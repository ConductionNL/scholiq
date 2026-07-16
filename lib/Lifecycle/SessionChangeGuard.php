<?php

/**
 * Scholiq Session Change Guard
 *
 * Lifecycle guard for the Session schema's `cancel`, `substitute-teacher`,
 * and `substitute-teacher-in-progress` transitions (timetabling-and-
 * substitution). Refuses the transition unless BOTH hold:
 *   1. Authorization: the caller (resolved server-side via the transition
 *      context's actor — never a client-supplied claim) is listed in the
 *      Session's Cohort.teacherIds, OR is a member of the admin/coordinator
 *      NC group — mirrors MunicipalityFeedbackGuard/ReportPeriodLockGuard's
 *      IGroupManager-based role check.
 *   2. Required fields: `changeReasonKind` MUST be set for both transitions
 *      ("no cancellation without a reason" — the same class of structural
 *      invariant as BsaDecisionGuard); `substituteTeacherId` MUST
 *      additionally be set for substitute-teacher/substitute-teacher-in-
 *      progress.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from Session.x-openregister-lifecycle.
 * transitions.{cancel,substitute-teacher,substitute-teacher-in-progress}.
 * requires in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the Session `cancel` / `substitute-teacher` / `substitute-teacher-in-progress` transitions.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */
class SessionChangeGuard
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const COHORT_SCHEMA    = 'cohort';

    /**
     * Transition action names that additionally require `substituteTeacherId`
     * to be set (beyond the `changeReasonKind` requirement shared by every
     * gated transition).
     *
     * @var string[]
     */
    private const SUBSTITUTE_ACTIONS = ['substitute-teacher', 'substitute-teacher-in-progress'];

    /**
     * NC groups whose members may cancel/substitute any Session, independent
     * of Cohort.teacherIds membership.
     *
     * @var string[]
     */
    private const OVERRIDE_GROUPS = ['admin', 'coordinator'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param IGroupManager   $groupManager  OR/NC group manager to resolve the acting user's role groups.
     * @param IUserManager    $userManager   User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Session data array (post-merge with the requested payload)
     *                                               - 'transition' : 'cancel'|'substitute-teacher'|'substitute-teacher-in-progress'
     *                                               - 'actor'      : NC user ID of the requester
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-teacher-outside-the-cohort-cannot-substitute-or-cancel
     */
    public function check(array &$transitionContext): bool
    {
        $object     = $transitionContext['object'] ?? [];
        $action     = (string) ($transitionContext['transition'] ?? '');
        $actor      = (string) ($transitionContext['actor'] ?? '');
        $cohortId   = (string) ($object['cohortId'] ?? '');
        $tenantId   = (string) ($object['tenant_id'] ?? '');
        $reasonKind = $object['changeReasonKind'] ?? null;

        if ($actor === '') {
            $this->logger->info('[SessionChangeGuard] No actor in transitionContext — blocking {a}.', ['a' => $action]);
            return false;
        }

        if (is_string($reasonKind) === false || $reasonKind === '') {
            $this->logger->info(
                '[SessionChangeGuard] {a} refused — changeReasonKind is not set.',
                ['a' => $action]
            );
            return false;
        }

        if (in_array($action, self::SUBSTITUTE_ACTIONS, true) === true) {
            $substituteTeacherId = $object['substituteTeacherId'] ?? null;
            if (is_string($substituteTeacherId) === false || $substituteTeacherId === '') {
                $this->logger->info(
                    '[SessionChangeGuard] {a} refused — substituteTeacherId is not set.',
                    ['a' => $action]
                );
                return false;
            }
        }

        if ($this->actorIsOverrideRole(actor: $actor) === true) {
            return true;
        }

        if ($cohortId === '') {
            $this->logger->warning('[SessionChangeGuard] Session has no cohortId; blocking {a}.', ['a' => $action]);
            return false;
        }

        $cohort = $this->loadCohort(cohortId: $cohortId, tenantId: $tenantId);
        if ($cohort === null) {
            $this->logger->warning(
                '[SessionChangeGuard] Cohort {c} not found for Session; blocking {a} (fail closed).',
                ['c' => $cohortId, 'a' => $action]
            );
            return false;
        }

        $teacherIds = $cohort['teacherIds'] ?? [];
        if (is_array($teacherIds) === true && in_array($actor, $teacherIds, true) === true) {
            return true;
        }

        $this->logger->info(
            '[SessionChangeGuard] Actor {u} is neither a Cohort.teacherIds member nor admin/coordinator; blocking {a}.',
            ['u' => $actor, 'a' => $action]
        );

        return false;

    }//end check()

    /**
     * Whether the actor is a member of one of OVERRIDE_GROUPS.
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in admin/coordinator.
     */
    private function actorIsOverrideRole(string $actor): bool
    {
        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::OVERRIDE_GROUPS)) > 0;

    }//end actorIsOverrideRole()

    /**
     * Load a Cohort by UUID, tenant-scoped.
     *
     * @param string $cohortId UUID of the Cohort.
     * @param string $tenantId Tenant ID to enforce as a mandatory filter.
     *
     * @return array<string,mixed>|null The cohort data, or null when not found.
     */
    private function loadCohort(string $cohortId, string $tenantId): ?array
    {
        $filters = ['id' => $cohortId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COHORT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $cohort = $results[0];
        if (is_array($cohort) === false) {
            $cohort = $cohort->jsonSerialize();
        }

        return $cohort;

    }//end loadCohort()
}//end class
