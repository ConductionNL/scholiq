<?php

/**
 * Scholiq Report Period Lock Guard
 *
 * Lifecycle guard for the GradeEntry schema's `publish` and `republish`
 * transitions (grading spec, report-card-composer delta). Blocks ordinary
 * teacher grade-publishing once a matching `ReportPeriod` is locked
 * (`isLocked` true), unless the acting user holds admin/mentor/principal —
 * an explicit override, e.g. a genuine post-lock correction agreed at the
 * rapportvergadering.
 *
 * DEVIATION FROM THE ORIGINAL DESIGN — this class REPLACES
 * {@see FraudCaseBlockGuard} as the `requires` value on `publish`/`republish`
 * rather than being added "alongside" it as a second `requires` entry. The
 * design doc's own precedent for a second entry (`certification`'s
 * `Credential.revoke` transition) does not exist as an array shape at HEAD:
 * `Credential.revoke.requires` is a single string
 * (`OCA\Scholiq\Service\WalletRevocationPropagationService`), and
 * OpenRegister's own `LifecycleAnnotationValidator::validate()` explicitly
 * rejects a non-string `requires` value (`is_string($spec['requires']) ===
 * false || $spec['requires'] === ''` => `lifecycle-requires-malformed`);
 * `TransitionEngine::listAvailableActions()` likewise casts `requires` to a
 * single `(string)`. There is no "stack two guards" shape to use. This class
 * instead COMPOSES the original guard: it constructor-injects
 * {@see FraudCaseBlockGuard} and calls its `check()` first (unchanged
 * fraud-case behaviour, byte-for-byte), then applies the report-period lock
 * check on top — mirroring how `MunicipalityFeedbackGuard`'s own docblock
 * already documents "no `x-openregister-*` extension expresses [this], so a
 * guard composes the missing capability" as a legitimate ADR-031 seam.
 *
 * Match key deviation: the report-card design.md text describes matching a
 * governing `ReportPeriod` by "periodCode + curriculumPlanIds containment +
 * academicYear". `GradeEntry` carries no `academicYear` property (verified:
 * only `Cohort`/`BsaTrajectory` do) — `tenant_id`, present on both schemas,
 * is used as the equivalent multi-tenant scoping safeguard instead.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the GradeEntry schema's
 * x-openregister-lifecycle.transitions.publish/republish.requires in
 * scholiq_register.json.
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
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-a-mentor-override-publishes-a-grade-for-a-locked-report-period
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the GradeEntry `publish`/`republish` lifecycle transitions.
 *
 * Delegates to the pre-existing {@see FraudCaseBlockGuard} first (unchanged
 * behaviour); when that passes, resolves whether the entry's `period` +
 * `curriculumPlanId` match any `report-card` `ReportPeriod` (by `periodCode`
 * + `curriculumPlanIds` containment + `tenant_id`) that is `isLocked`. If no
 * such `ReportPeriod` exists, allows unconditionally (fail-open — a school
 * not using report cards, or a GradeEntry outside any declared
 * ReportPeriod's scope, is completely unaffected). If a matching, locked
 * ReportPeriod exists, blocks unless the acting user holds
 * admin/mentor/principal.
 *
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class ReportPeriodLockGuard
{

    private const SCHOLIQ_REGISTER     = 'scholiq';
    private const REPORT_PERIOD_SCHEMA = 'report-period';

    /**
     * Roles whose members may override a locked report period and publish
     * anyway (an explicit, logged correction).
     *
     * @var string[]
     */
    private const OVERRIDE_GROUPS = ['admin', 'mentor', 'principal'];

    /**
     * Constructor.
     *
     * @param FraudCaseBlockGuard $fraudCaseBlockGuard The original guard this class composes (unchanged behaviour, called first).
     * @param ObjectService       $objectService       OR object access service.
     * @param IGroupManager       $groupManager        OR/NC group manager to resolve the acting user's role groups.
     * @param IUserManager        $userManager         User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface     $logger              PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly FraudCaseBlockGuard $fraudCaseBlockGuard,
        private readonly ObjectService $objectService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `publish`/`republish` transition on a GradeEntry object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the GradeEntry data array
     *                                               - 'transition' : 'publish' or 'republish'
     *                                               - 'actor'      : NC user ID of the requester (when available)
     *
     * @return bool True if the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-a-mentor-override-publishes-a-grade-for-a-locked-report-period
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
     */
    public function check(array &$transitionContext): bool
    {
        // 1. Preserve the original fraud-case check byte-for-byte.
        if ($this->fraudCaseBlockGuard->check($transitionContext) === false) {
            return false;
        }

        $entry = $transitionContext['object'] ?? [];

        $period           = (string) ($entry['period'] ?? '');
        $curriculumPlanId = (string) ($entry['curriculumPlanId'] ?? '');
        $tenantId         = (string) ($entry['tenant_id'] ?? '');
        $entryId          = $entry['id'] ?? ($entry['uuid'] ?? '');

        if ($period === '' || $curriculumPlanId === '') {
            // Nothing to match a ReportPeriod against.
            return true;
        }

        $reportPeriod = $this->findGoverningReportPeriod(
            period: $period,
            curriculumPlanId: $curriculumPlanId,
            tenantId: $tenantId
        );

        if ($reportPeriod === null) {
            // No ReportPeriod governs this entry — fail open, mirroring
            // AttendanceFlagReportGuard's "no linked job -> allow
            // unconditionally" posture.
            return true;
        }

        $isLocked = $reportPeriod['isLocked'] ?? false;

        if ($isLocked !== true) {
            return true;
        }

        $actor = (string) ($transitionContext['actor'] ?? '');

        if ($this->actorMayOverride(actor: $actor) === true) {
            $this->logger->info(
                '[ReportPeriodLockGuard] GradeEntry {id} publish allowed — actor {actor} overrides locked ReportPeriod {period}.',
                ['id' => $entryId, 'actor' => $actor, 'period' => $reportPeriod['id'] ?? ($reportPeriod['uuid'] ?? '')]
            );
            return true;
        }

        $this->logger->info(
            '[ReportPeriodLockGuard] GradeEntry {id} blocked — governing ReportPeriod {period} is locked and actor {actor} holds no override role.',
            ['id' => $entryId, 'period' => $reportPeriod['id'] ?? ($reportPeriod['uuid'] ?? ''), 'actor' => $actor]
        );

        return false;

    }//end check()

    /**
     * Resolve the ReportPeriod (if any) governing this GradeEntry's period +
     * curriculumPlanId, scoped to the same tenant.
     *
     * @param string $period           GradeEntry.period value.
     * @param string $curriculumPlanId GradeEntry.curriculumPlanId value.
     * @param string $tenantId         GradeEntry.tenant_id value.
     *
     * @return array<string,mixed>|null The governing ReportPeriod data array, or null when none matches.
     */
    private function findGoverningReportPeriod(string $period, string $curriculumPlanId, string $tenantId): ?array
    {
        $filters = ['periodCode' => $period];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $candidates = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::REPORT_PERIOD_SCHEMA,
                'filters'  => $filters,
                'limit'    => 500,
            ]
        );

        foreach ($candidates as $candidate) {
            $candidateData = $candidate;
            if (is_array($candidate) === false) {
                $candidateData = $candidate->jsonSerialize();
            }

            $curriculumPlanIds = $candidateData['curriculumPlanIds'] ?? [];
            if (is_array($curriculumPlanIds) === false) {
                continue;
            }

            if (in_array($curriculumPlanId, $curriculumPlanIds, true) === true) {
                return $candidateData;
            }
        }//end foreach

        return null;

    }//end findGoverningReportPeriod()

    /**
     * Whether the acting user holds an override role (admin/mentor/principal).
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in one of the override groups.
     */
    private function actorMayOverride(string $actor): bool
    {
        if ($actor === '') {
            return false;
        }

        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::OVERRIDE_GROUPS)) > 0;

    }//end actorMayOverride()
}//end class
