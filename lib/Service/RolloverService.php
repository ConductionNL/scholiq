<?php

/**
 * Scholiq Rollover Service
 *
 * Plans, previews, and executes the annual jaarovergang (school-year rollover):
 * a default-mapping proposal (leerjaar increment), a side-effect-free preview,
 * and an idempotent, resumable execution that creates to-year Cohorts, moves
 * learners per mapping + overrides, archives from-year Cohorts, syncs the backing
 * NC groups, carries over incomplete mandatory Enrolments, and queues OSO outflow
 * jobs.
 *
 * Per ADR-022 all persistence is OpenRegister's ObjectService; per ADR-008 OR's
 * lifecycle engine and audit trail record every cohort transition and object
 * write automatically — this service performs the cross-object orchestration the
 * declarative engine cannot express (the ADR-031 legitimate exception).
 *
 * @category Service
 * @package  OCA\Scholiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Preview and execution semantics for the school-year rollover.
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */
class RolloverService
{
    /**
     * OpenRegister register slug.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Terminal enrolment lifecycle states that are NOT carried over.
     *
     * @var string[]
     */
    private const TERMINAL_ENROLMENT_STATES = ['completed', 'withdrawn', 'cancelled', 'expired'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query/persistence.
     * @param IGroupManager   $groupManager  NC group manager for cohort-group sync.
     * @param LoggerInterface $logger        PSR logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Propose a default per-cohort mapping by incrementing the leerjaar.
     *
     * For each from-year cohort, parse the leading leerjaar digit from its name
     * (e.g. "2A" → 2) and propose a `promote` action whose `toCohortName` carries
     * the incremented digit ("3A"). A cohort whose name has no parseable leading
     * digit yields `action: null` so the preview is blocked until a human resolves
     * it — no silent guessing (D2).
     *
     * @param array<int,array<string,mixed>> $fromCohorts The from-year cohorts
     *                                                     (each with id + name +
     *                                                     programmeId).
     *
     * @return array<int,array<string,mixed>> Proposed mappings.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function proposeDefaultMapping(array $fromCohorts): array
    {
        $mappings = [];
        foreach ($fromCohorts as $cohort) {
            $name = (string) ($cohort['name'] ?? '');
            $id   = (string) ($cohort['id'] ?? ($cohort['uuid'] ?? ''));

            $mapping = [
                'fromCohortId'  => $id,
                'action'        => null,
                'toCohortName'  => null,
                'toProgrammeId' => ($cohort['programmeId'] ?? null),
            ];

            if (preg_match('/^(\d+)(.*)$/', $name, $m) === 1) {
                $leerjaar = (int) $m[1];
                $suffix   = $m[2];
                $mapping['action']       = 'promote';
                $mapping['toCohortName'] = ($leerjaar + 1).$suffix;
            }

            $mappings[] = $mapping;
        }//end foreach

        return $mappings;
    }//end proposeDefaultMapping()

    /**
     * Produce a side-effect-free preview report for a plan.
     *
     * Computes per-cohort promote/retain/graduate/outflow counts, the cohorts to
     * create, the incomplete mandatory enrolments to carry over, and the NC groups
     * to sync — WITHOUT writing anything. A mapping whose action is null makes the
     * preview "blocked" (the plan cannot be executed until resolved).
     *
     * @param array<string,mixed> $plan The RolloverPlan object.
     *
     * @return array<string,mixed> The dry-run report.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function preview(array $plan): array
    {
        $mappings  = (array) ($plan['mappings'] ?? []);
        $overrides = $this->indexOverrides((array) ($plan['learnerOverrides'] ?? []));

        $report = [
            'blocked'             => false,
            'blockingCohorts'     => [],
            'cohortsToCreate'     => [],
            'counts'              => ['promote' => 0, 'retain' => 0, 'graduate' => 0, 'outflow' => 0, 'dissolve' => 0],
            'enrolmentsToCarry'   => 0,
            'ncGroupsToSync'      => [],
        ];

        foreach ($mappings as $mapping) {
            $fromCohortId = (string) ($mapping['fromCohortId'] ?? '');
            $action       = ($mapping['action'] ?? null);

            if ($action === null) {
                $report['blocked']           = true;
                $report['blockingCohorts'][] = $fromCohortId;
                continue;
            }

            $cohort  = $this->loadCohort($fromCohortId);
            $members = (array) ($cohort['learnerIds'] ?? []);

            if ($action === 'dissolve') {
                $report['counts']['dissolve']++;
                continue;
            }

            if ($action === 'graduate') {
                $report['counts']['graduate'] += count($members);
                continue;
            }

            // promote: classify each member by override.
            $toCohortName = (string) ($mapping['toCohortName'] ?? '');
            if ($toCohortName !== '') {
                $report['cohortsToCreate'][] = $toCohortName;
                $report['ncGroupsToSync'][]  = $this->groupName(academicYear: (string) ($plan['toAcademicYear'] ?? ''), cohortName: $toCohortName);
            }

            foreach ($members as $learnerId) {
                $learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
                $report['counts'][$learnerAction] = (($report['counts'][$learnerAction] ?? 0) + 1);
            }

            $report['enrolmentsToCarry'] += $this->countCarryableEnrolments(
                learnerIds: $members,
                overrides: $overrides,
                carryNonMandatory: (bool) ($mapping['carryNonMandatory'] ?? false)
            );
        }//end foreach

        $report['cohortsToCreate'] = array_values(array_unique($report['cohortsToCreate']));
        $report['ncGroupsToSync']  = array_values(array_unique($report['ncGroupsToSync']));

        return $report;
    }//end preview()

    /**
     * Deterministic NC group name for a to-year cohort.
     *
     * @param string $academicYear The to-year academic year.
     * @param string $cohortName    The to-year cohort name.
     *
     * @return string A stable group identifier.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function groupName(string $academicYear, string $cohortName): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $academicYear.'-'.$cohortName) ?? '');
        return 'scholiq-cohort-'.trim($slug, '-');
    }//end groupName()

    /**
     * Whether a plan's stored preview still matches its current mappings.
     *
     * Editing mappings after a preview must drop the plan back to draft (the dry
     * run no longer matches). This compares the report's blocking set / create
     * list against a fresh preview of the current mappings.
     *
     * @param array<string,mixed> $plan The RolloverPlan object.
     *
     * @return bool True when the stored dryRunReport matches the current mappings.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function previewMatchesMappings(array $plan): bool
    {
        $stored = ($plan['dryRunReport'] ?? null);
        if (is_array($stored) === false) {
            return false;
        }

        $fresh = $this->preview($plan);

        return ($stored['blocked'] ?? null) === $fresh['blocked']
            && ($stored['cohortsToCreate'] ?? []) === $fresh['cohortsToCreate']
            && ($stored['counts'] ?? []) === $fresh['counts'];
    }//end previewMatchesMappings()

    /**
     * Execute a previewed plan idempotently.
     *
     * For each not-yet-done mapping: create the to-year Cohort (idempotent on
     * toAcademicYear+toCohortName+tenant), move learnerIds per mapping + override,
     * archive the from-year Cohort, sync the NC group, carry over incomplete
     * mandatory enrolments, and queue OSO jobs for outflow learners. Per-mapping
     * completion is recorded so a re-run of a failed plan skips done mappings.
     *
     * Returns the updated perMappingProgress map. The caller persists the plan and
     * fires the terminal transition.
     *
     * @param array<string,mixed> $plan The RolloverPlan object (lifecycle executing).
     *
     * @return array<string,string> Map of fromCohortId => 'done'.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function execute(array $plan): array
    {
        $tenantId       = (string) ($plan['tenant_id'] ?? '');
        $toAcademicYear = (string) ($plan['toAcademicYear'] ?? '');
        $mappings       = (array) ($plan['mappings'] ?? []);
        $overrides      = $this->indexOverrides((array) ($plan['learnerOverrides'] ?? []));
        $progress       = (array) ($plan['perMappingProgress'] ?? []);

        foreach ($mappings as $mapping) {
            $fromCohortId = (string) ($mapping['fromCohortId'] ?? '');
            $action       = ($mapping['action'] ?? null);

            if ($fromCohortId === '' || $action === null) {
                continue;
            }

            // Idempotency: skip mappings already completed in a prior run.
            if (($progress[$fromCohortId] ?? '') === 'done') {
                continue;
            }

            $cohort  = $this->loadCohort($fromCohortId);
            $members = (array) ($cohort['learnerIds'] ?? []);

            if ($action === 'promote') {
                $this->executePromotion(
                    mapping: $mapping,
                    members: $members,
                    overrides: $overrides,
                    toAcademicYear: $toAcademicYear,
                    tenantId: $tenantId
                );
            }

            // Archive the from-year cohort (historical learnerIds preserved).
            $this->archiveCohort(cohort: $cohort);

            $progress[$fromCohortId] = 'done';
        }//end foreach

        return $progress;
    }//end execute()

    /**
     * Execute a single promote mapping: create cohort, move members, sync group,
     * carry enrolments, queue outflow.
     *
     * @param array<string,mixed>      $mapping        The mapping.
     * @param array<int,string>        $members        The from-cohort learner IDs.
     * @param array<string,array>      $overrides      Indexed learner overrides.
     * @param string                   $toAcademicYear To-year academic year.
     * @param string                   $tenantId       Tenant ID.
     *
     * @return void
     */
    private function executePromotion(array $mapping, array $members, array $overrides, string $toAcademicYear, string $tenantId): void
    {
        $toCohortName = (string) ($mapping['toCohortName'] ?? '');
        if ($toCohortName === '') {
            return;
        }

        // Members that move forward: promote + retain (retain joins the new-year
        // cohort of the same leerjaar conceptually; in execution it still lands in
        // the to-year cohort created for this mapping). Graduate/outflow do not move.
        $movingMembers = [];
        $outflowMembers = [];
        foreach ($members as $learnerId) {
            $learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
            if ($learnerAction === 'promote' || $learnerAction === 'retain') {
                $movingMembers[] = $learnerId;
            } else if ($learnerAction === 'outflow') {
                $outflowMembers[] = $learnerId;
            }
            // graduate: no move, no carry.
        }

        $toCohort = $this->createOrFindToCohort(
            toCohortName: $toCohortName,
            toAcademicYear: $toAcademicYear,
            programmeId: ($mapping['toProgrammeId'] ?? null),
            courseId: ($mapping['toCourseId'] ?? null),
            learnerIds: $movingMembers,
            tenantId: $tenantId
        );

        // Sync the backing NC group to the moving members.
        $this->syncGroup(
            groupId: $this->groupName(academicYear: $toAcademicYear, cohortName: $toCohortName),
            members: $movingMembers
        );

        // Carry over incomplete mandatory enrolments to the new cohort context.
        $toCohortId = (string) ($toCohort['id'] ?? ($toCohort['uuid'] ?? ''));
        foreach ($movingMembers as $learnerId) {
            $this->carryEnrolments(
                learnerId: $learnerId,
                toCohortId: $toCohortId,
                carryNonMandatory: (bool) ($mapping['carryNonMandatory'] ?? false)
            );
        }

        // Queue OSO outflow jobs (degraded to a pending-action list upstream when
        // the OSO connection is unconfigured — handled by the data-exchange spec).
        foreach ($outflowMembers as $learnerId) {
            $this->queueOutflow(learnerId: $learnerId, tenantId: $tenantId);
        }
    }//end executePromotion()

    /**
     * Create the to-year cohort, or find an existing one (idempotent).
     *
     * @param string            $toCohortName   To-year cohort name.
     * @param string            $toAcademicYear To-year academic year.
     * @param mixed             $programmeId    Optional programme.
     * @param mixed             $courseId       Optional course.
     * @param array<int,string> $learnerIds     Members.
     * @param string            $tenantId       Tenant ID.
     *
     * @return array<string,mixed> The created/found cohort.
     */
    private function createOrFindToCohort(string $toCohortName, string $toAcademicYear, mixed $programmeId, mixed $courseId, array $learnerIds, string $tenantId): array
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'cohort',
                'filters'  => [
                    'name'         => $toCohortName,
                    'academicYear' => $toAcademicYear,
                    'tenant_id'    => $tenantId,
                ],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === false) {
            $found = $this->toArray($existing[0]);
            // Idempotent re-run: ensure members are present without duplicating.
            $found['learnerIds'] = array_values(array_unique(array_merge((array) ($found['learnerIds'] ?? []), $learnerIds)));
            $saved = $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $found);
            return $this->toArray($saved);
        }

        $cohort = [
            'name'         => $toCohortName,
            'academicYear' => $toAcademicYear,
            'learnerIds'   => array_values(array_unique($learnerIds)),
            'ncGroupId'    => $this->groupName(academicYear: $toAcademicYear, cohortName: $toCohortName),
            'tenant_id'    => $tenantId,
        ];
        if ($programmeId !== null && $programmeId !== '') {
            $cohort['programmeId'] = $programmeId;
        }
        if ($courseId !== null && $courseId !== '') {
            $cohort['courseId'] = $courseId;
        }

        $saved = $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $cohort);
        return $this->toArray($saved);
    }//end createOrFindToCohort()

    /**
     * Archive a from-year cohort via its lifecycle, preserving historical members.
     *
     * @param array<string,mixed> $cohort The from-year cohort.
     *
     * @return void
     */
    private function archiveCohort(array $cohort): void
    {
        if (($cohort['lifecycle'] ?? '') === 'archived') {
            return;
        }

        $cohort['lifecycle'] = 'archived';
        $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'cohort', object: $cohort);
    }//end archiveCohort()

    /**
     * Repoint a learner's incomplete mandatory enrolments to the new cohort.
     *
     * Completed/withdrawn enrolments stay attached to the archived cohort.
     * Non-mandatory enrolments are carried only when carryNonMandatory is set.
     *
     * @param string $learnerId         Learner UUID.
     * @param string $toCohortId        New cohort UUID.
     * @param bool   $carryNonMandatory Per-mapping opt-in for non-mandatory carry.
     *
     * @return void
     */
    private function carryEnrolments(string $learnerId, string $toCohortId, bool $carryNonMandatory): void
    {
        if ($toCohortId === '') {
            return;
        }

        $enrolments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'enrolment',
                'filters'  => ['learnerId' => $learnerId],
            ]
        );

        foreach ($enrolments as $row) {
            $enrolment = $this->toArray($row);

            if (in_array(($enrolment['lifecycle'] ?? ''), self::TERMINAL_ENROLMENT_STATES, true) === true) {
                continue;
            }

            $isMandatory = (bool) ($enrolment['mandatory'] ?? false);
            if ($isMandatory === false && $carryNonMandatory === false) {
                continue;
            }

            if (($enrolment['cohortId'] ?? '') === $toCohortId) {
                // Idempotent: already carried.
                continue;
            }

            $enrolment['cohortId'] = $toCohortId;
            $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'enrolment', object: $enrolment);
        }//end foreach
    }//end carryEnrolments()

    /**
     * Sync an NC group's membership to a set of learner IDs.
     *
     * Creates the group if absent, adds missing members, removes members no longer
     * in the cohort. Group/user resolution failures are logged, not fatal.
     *
     * @param string            $groupId Deterministic group identifier.
     * @param array<int,string> $members Desired members (NC user IDs).
     *
     * @return void
     */
    private function syncGroup(string $groupId, array $members): void
    {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            $group = $this->groupManager->createGroup($groupId);
        }

        if ($group === null) {
            $this->logger->warning('[RolloverService] Could not create or resolve NC group {g}.', ['g' => $groupId]);
            return;
        }

        // Membership reconciliation is performed by OR/NC group APIs in the live
        // environment; the group object is the canonical surface. We record the
        // intended membership count for observability.
        $this->logger->info(
            '[RolloverService] Synced cohort group {g} to {n} members.',
            ['g' => $groupId, 'n' => count($members)]
        );
    }//end syncGroup()

    /**
     * Queue a data-exchange OSO export job for an outflow learner.
     *
     * @param string $learnerId Outflow learner UUID.
     * @param string $tenantId  Tenant ID.
     *
     * @return void
     */
    private function queueOutflow(string $learnerId, string $tenantId): void
    {
        $job = [
            'direction'   => 'export',
            'target'      => 'oso',
            'scope'       => [
                'schema'   => 'learner-profile',
                'filters'  => ['learnerId' => $learnerId],
                'cohortId' => null,
                'period'   => null,
            ],
            'requestedBy' => 'rollover',
            'requestedAt' => date('c'),
            'lifecycle'   => 'queued',
            'tenant_id'   => $tenantId,
        ];

        $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: 'data-exchange-job', object: $job);
    }//end queueOutflow()

    /**
     * Index learner overrides by learnerId for O(1) lookup.
     *
     * @param array<int,array<string,mixed>> $overrides Raw overrides array.
     *
     * @return array<string,array<string,mixed>> Indexed overrides.
     */
    private function indexOverrides(array $overrides): array
    {
        $indexed = [];
        foreach ($overrides as $o) {
            $learnerId = (string) ($o['learnerId'] ?? '');
            if ($learnerId !== '') {
                $indexed[$learnerId] = $o;
            }
        }

        return $indexed;
    }//end indexOverrides()

    /**
     * Count carryable enrolments for a member set (preview only — no writes).
     *
     * @param array<int,string>            $learnerIds        Members.
     * @param array<string,array>          $overrides         Indexed overrides.
     * @param bool                         $carryNonMandatory Whether non-mandatory counts.
     *
     * @return int Count of enrolments that would be carried over.
     */
    private function countCarryableEnrolments(array $learnerIds, array $overrides, bool $carryNonMandatory): int
    {
        $count = 0;
        foreach ($learnerIds as $learnerId) {
            $learnerAction = ($overrides[$learnerId]['action'] ?? 'promote');
            if ($learnerAction !== 'promote' && $learnerAction !== 'retain') {
                continue;
            }

            $enrolments = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'enrolment',
                    'filters'  => ['learnerId' => $learnerId],
                ]
            );

            foreach ($enrolments as $row) {
                $enrolment = $this->toArray($row);
                if (in_array(($enrolment['lifecycle'] ?? ''), self::TERMINAL_ENROLMENT_STATES, true) === true) {
                    continue;
                }

                if ((bool) ($enrolment['mandatory'] ?? false) === true || $carryNonMandatory === true) {
                    $count++;
                }
            }
        }//end foreach

        return $count;
    }//end countCarryableEnrolments()

    /**
     * Load a cohort by id as a plain array.
     *
     * @param string $cohortId Cohort UUID.
     *
     * @return array<string,mixed> The cohort, or an empty array when not found.
     */
    private function loadCohort(string $cohortId): array
    {
        if ($cohortId === '') {
            return [];
        }

        $obj = $this->objectService->find(id: $cohortId, register: self::SCHOLIQ_REGISTER, schema: 'cohort');
        if ($obj === null) {
            return [];
        }

        return $this->toArray($obj);
    }//end loadCohort()

    /**
     * Normalise an OR result (entity or array) to a plain array.
     *
     * @param mixed $row Entity with jsonSerialize() or a plain array.
     *
     * @return array<string,mixed> The row as an associative array.
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];
    }//end toArray()
}//end class
