<?php

/**
 * Scholiq Session Change Notice Handler
 *
 * IEventListener for Session lifecycle -> `cancel` / `substitute-teacher` /
 * `substitute-teacher-in-progress` (the OR ObjectTransitionedEvent with
 * register=scholiq, schema=session, action in that set). Materialises
 * `affectedLearnerIds` / `affectedParentIds` / `changedAt` onto the Session so
 * the verified `x-openregister-notifications` dialect's `kind:field`
 * recipients can resolve them without a runtime join — mirrors
 * `ConferenceRound.invitedLearnerIds`'s documented shape exactly, the same
 * two-hop cross-schema-join rationale already accepted for
 * `BsaProgressEvaluator`/`GradeFormulaEvaluator`:
 *
 * 1. Resolve the Session's `Cohort.learnerIds` (single hop) into
 *    `affectedLearnerIds`.
 * 2. Resolve each of those learners' `LearnerProfile.parentIds` (a second
 *    hop) into `affectedParentIds` (deduplicated).
 * 3. Persist both arrays plus a server-stamped `changedAt` onto the Session
 *    via `ObjectService::saveObject` — a genuine cross-object write bridge,
 *    the same ADR-031 exception class as `ConferenceScheduleGenerator`. This
 *    is a plain field-update save, never another lifecycle transition, and it
 *    NEVER touches `lifecycle`, `roomId`, `startsAt`, or `endsAt`.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Materialises affectedLearnerIds/affectedParentIds/changedAt on a Session
 * cancelled or substituted, ahead of the declared transition notification.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */
class SessionChangeNoticeHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const SESSION_SCHEMA         = 'session';
    private const COHORT_SCHEMA          = 'cohort';
    private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

    /**
     * Transition action names this handler reacts to.
     *
     * @var string[]
     */
    private const WATCHED_ACTIONS = ['cancel', 'substitute-teacher', 'substitute-teacher-in-progress'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER || $event->getSchema() !== self::SESSION_SCHEMA) {
            return;
        }

        if (in_array($event->getAction(), self::WATCHED_ACTIONS, true) === false) {
            return;
        }

        $this->materialiseAffected(session: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Resolve and persist affectedLearnerIds/affectedParentIds/changedAt onto the Session.
     *
     * @param array<string,mixed> $session The Session data after the transition.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
     */
    private function materialiseAffected(array $session): void
    {
        $sessionId = (string) ($session['id'] ?? ($session['uuid'] ?? ''));
        if ($sessionId === '') {
            $this->logger->warning('[SessionChangeNoticeHandler] Session has no id; aborting.');
            return;
        }

        $cohortId = (string) ($session['cohortId'] ?? '');
        $tenantId = (string) ($session['tenant_id'] ?? '');

        $learnerIds = $this->resolveCohortLearnerIds(cohortId: $cohortId, tenantId: $tenantId);
        $parentIds  = $this->resolveParentIds(learnerIds: $learnerIds, tenantId: $tenantId);

        $session['affectedLearnerIds'] = $learnerIds;
        $session['affectedParentIds']  = $parentIds;
        $session['changedAt']          = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::SESSION_SCHEMA,
            object: $session
        );

        $this->logger->info(
            '[SessionChangeNoticeHandler] Session {id}: {l} affected learner(s), {p} affected parent(s).',
            ['id' => $sessionId, 'l' => count($learnerIds), 'p' => count($parentIds)]
        );

    }//end materialiseAffected()

    /**
     * Resolve a Cohort's learnerIds.
     *
     * @param string $cohortId Cohort UUID.
     * @param string $tenantId Tenant ID to enforce as a mandatory filter.
     *
     * @return array<int,string> The cohort's learner Nextcloud user ids.
     */
    private function resolveCohortLearnerIds(string $cohortId, string $tenantId): array
    {
        if ($cohortId === '') {
            return [];
        }

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
            return [];
        }

        $cohort = $results[0];
        if (is_array($cohort) === false) {
            $cohort = $cohort->jsonSerialize();
        }

        $learnerIds = $cohort['learnerIds'] ?? [];
        if (is_array($learnerIds) === false) {
            return [];
        }

        return array_values(array_unique(array_filter($learnerIds, static fn ($id) => is_string($id) === true && $id !== '')));

    }//end resolveCohortLearnerIds()

    /**
     * Resolve the deduplicated union of parentIds across every learner's LearnerProfile.
     *
     * @param array<int,string> $learnerIds Affected learner Nextcloud user ids.
     * @param string            $tenantId   Tenant ID to enforce as a mandatory filter.
     *
     * @return array<int,string> The deduplicated parent/guardian Nextcloud user ids.
     */
    private function resolveParentIds(array $learnerIds, string $tenantId): array
    {
        $parentIds = [];

        foreach ($learnerIds as $learnerId) {
            $filters = ['ncUserId' => $learnerId];
            if ($tenantId !== '') {
                $filters['tenant_id'] = $tenantId;
            }

            $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::LEARNER_PROFILE_SCHEMA,
                    'filters'  => $filters,
                    'limit'    => 1,
                ]
            );

            if (empty($results) === true) {
                continue;
            }

            $profile = $results[0];
            if (is_array($profile) === false) {
                $profile = $profile->jsonSerialize();
            }

            $profileParentIds = $profile['parentIds'] ?? [];
            if (is_array($profileParentIds) === false) {
                continue;
            }

            foreach ($profileParentIds as $parentId) {
                if (is_string($parentId) === true && $parentId !== '') {
                    $parentIds[$parentId] = true;
                }
            }
        }//end foreach

        return array_keys($parentIds);

    }//end resolveParentIds()
}//end class
