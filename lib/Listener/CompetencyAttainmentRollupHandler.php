<?php

/**
 * Scholiq Competency Attainment Rollup Handler
 *
 * IEventListener registered against BOTH OR's ObjectCreatedEvent and
 * ObjectTransitionedEvent (Application.php registers this one class for both
 * event classes — handle() branches on instanceof, matching the shape design.md's
 * File Structure section declares: exactly one new PHP listener file). Handles
 * two responsibilities:
 *
 * 1. WerkprocesAssessment creation → server-side competencyId resolution.
 *    Matches the newly-created assessment's werkprocesCode against a
 *    Competency.code under an sbb-kwalificatiedossier CompetencyFramework and,
 *    on a match, writes competencyId back onto the assessment. Never accepts
 *    competencyId as client input — including from the praktijkopleider
 *    portal action, whose field whitelist deliberately excludes it (design.md
 *    "WerkprocesAssessment generalization mechanics"). A miss leaves
 *    competencyId null and blocks nothing.
 *
 * 2. Competency-aligned evidence → CompetencyAttainment roll-up.
 *    Mirrors GradeRollupHandler/WerkprocesGradeEmitHandler's cross-schema
 *    write-bridge shape, but rolls up into CompetencyAttainment instead of
 *    FinalGrade/GradeEntry:
 *      - GradeEntry -> published (sourceKind: assignment-submission): resolves
 *        submissionId -> Submission.assignmentId -> Assignment.competencyIds.
 *      - GradeEntry -> published (sourceKind: assessment-result): resolves
 *        assessmentResultId -> AssessmentResult.assessmentId ->
 *        Assessment.competencyIds.
 *      - WerkprocesAssessment -> confirmed: uses the assessment's own
 *        generalized competencyId directly (no join needed).
 *    For each aligned competency, upserts one CompetencyAttainment row per
 *    (learnerId, competencyId), appending evidence ids idempotently and
 *    recomputing proficiencyLevelId (percentage-threshold mapping for
 *    GradeEntry evidence, direct beoordeling label mapping for
 *    WerkprocesAssessment evidence — design.md "Mastery roll-up mechanics").
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (join through Submission/AssessmentResult/Assignment/Assessment/Competency/
 * CompetencyFramework lookups) that cannot be expressed as a schema
 * declaration alone. Never a TimedJob (ADR-022).
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
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Resolves WerkprocesAssessment.competencyId at creation and rolls up
 * competency-aligned evidence into CompetencyAttainment on transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 */
class CompetencyAttainmentRollupHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const GRADE_ENTRY_SCHEMA       = 'grade-entry';
    private const WERKPROCES_SCHEMA        = 'werkproces-assessment';
    private const BPV_PLACEMENT_SCHEMA     = 'bpv-placement';
    private const SUBMISSION_SCHEMA        = 'submission';
    private const ASSIGNMENT_SCHEMA        = 'assignment';
    private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
    private const ASSESSMENT_SCHEMA        = 'assessment';
    private const COMPETENCY_SCHEMA        = 'competency';
    private const FRAMEWORK_SCHEMA         = 'competency-framework';
    private const ATTAINMENT_SCHEMA        = 'competency-attainment';

    /**
     * SBB kwalificatiedossier source authority used to scope werkprocesCode resolution.
     */
    private const SBB_SOURCE_AUTHORITY = 'sbb-kwalificatiedossier';

    /**
     * Beoordeling values recognised for direct label-to-level mapping.
     */
    private const BEOORDELING_VALUES = ['competent', 'nog-niet-competent'];

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
     * Handle an incoming OR event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === true) {
            $this->handleObjectCreated(event: $event);
            return;
        }

        if ($event instanceof ObjectTransitionedEvent === true) {
            $this->handleObjectTransitioned(event: $event);
        }

    }//end handle()

    /**
     * Handle an ObjectCreatedEvent — resolves WerkprocesAssessment.competencyId.
     *
     * @param ObjectCreatedEvent $event The created-object event.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function handleObjectCreated(ObjectCreatedEvent $event): void
    {
        $entity = $event->getObject();

        if ($entity->getRegister() !== self::SCHOLIQ_REGISTER
            || $entity->getSchema() !== self::WERKPROCES_SCHEMA
        ) {
            return;
        }

        $this->resolveWerkprocesCompetencyId(data: $entity->jsonSerialize());

    }//end handleObjectCreated()

    /**
     * Handle an ObjectTransitionedEvent — GradeEntry.published or WerkprocesAssessment.confirmed.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function handleObjectTransitioned(ObjectTransitionedEvent $event): void
    {
        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() === self::GRADE_ENTRY_SCHEMA && $event->getTo() === 'published') {
            $this->handleGradeEntryPublished(entry: $event->getObject()->jsonSerialize());
            return;
        }

        if ($event->getSchema() === self::WERKPROCES_SCHEMA && $event->getTo() === 'confirmed') {
            $this->handleWerkprocesConfirmed(assessment: $event->getObject()->jsonSerialize());
        }

    }//end handleObjectTransitioned()

    /**
     * Resolve and persist WerkprocesAssessment.competencyId at creation time.
     *
     * Matches werkprocesCode against Competency.code scoped to an
     * sbb-kwalificatiedossier CompetencyFramework. A miss leaves competencyId
     * null and never blocks creation or the existing confirm/GradeEntry flow.
     *
     * @param array<string,mixed> $data The newly created WerkprocesAssessment data.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function resolveWerkprocesCompetencyId(array $data): void
    {
        // Defensive no-op: competencyId is never client-settable (not in the
        // portal whitelist), but if it is already set (e.g. a re-fired event
        // on an already-resolved row) there is nothing to do.
        if (empty($data['competencyId']) === false) {
            return;
        }

        $werkprocesCode = $data['werkprocesCode'] ?? '';
        if ($werkprocesCode === '') {
            return;
        }

        $tenantId = $data['tenant_id'] ?? '';

        $competency = $this->findCompetencyByCode(code: $werkprocesCode, tenantId: $tenantId);
        if ($competency === null) {
            $this->logger->info(
                '[CompetencyAttainmentRollupHandler] WerkprocesAssessment {id}: werkprocesCode "{code}" has no '
                .'matching Competency under an sbb-kwalificatiedossier framework — competencyId stays null.',
                ['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'code' => $werkprocesCode]
            );
            return;
        }

        $competencyId = $competency['id'] ?? ($competency['uuid'] ?? null);
        if ($competencyId === null) {
            return;
        }

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::WERKPROCES_SCHEMA,
            object: array_merge($data, ['competencyId' => $competencyId])
        );

        $this->logger->info(
            '[CompetencyAttainmentRollupHandler] WerkprocesAssessment {id}: resolved competencyId {cid} from '
            .'werkprocesCode "{code}".',
            ['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'cid' => $competencyId, 'code' => $werkprocesCode]
        );

    }//end resolveWerkprocesCompetencyId()

    /**
     * Find a Competency whose code matches, scoped to an sbb-kwalificatiedossier framework.
     *
     * @param string $code     The werkprocesCode to match.
     * @param string $tenantId Tenant UUID scope filter.
     *
     * @return array<string,mixed>|null The matching Competency data, or null when none found.
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function findCompetencyByCode(string $code, string $tenantId): ?array
    {
        $frameworkFilters = ['sourceAuthority' => self::SBB_SOURCE_AUTHORITY];
        if ($tenantId !== '') {
            $frameworkFilters['tenant_id'] = $tenantId;
        }

        $frameworks = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::FRAMEWORK_SCHEMA,
                'filters'  => $frameworkFilters,
            ]
        );

        foreach ($frameworks as $framework) {
            $frameworkData = $this->toArray(object: $framework);
            $frameworkId   = $frameworkData['id'] ?? ($frameworkData['uuid'] ?? null);
            if ($frameworkId === null) {
                continue;
            }

            $competencyFilters = ['frameworkId' => $frameworkId, 'code' => $code];
            if ($tenantId !== '') {
                $competencyFilters['tenant_id'] = $tenantId;
            }

            $competencies = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::COMPETENCY_SCHEMA,
                    'filters'  => $competencyFilters,
                    'limit'    => 1,
                ]
            );

            if (empty($competencies) === false) {
                return $this->toArray(object: $competencies[0]);
            }
        }//end foreach

        return null;

    }//end findCompetencyByCode()

    /**
     * Roll up a published GradeEntry's evidence into CompetencyAttainment, if aligned.
     *
     * @param array<string,mixed> $entry The published GradeEntry data.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function handleGradeEntryPublished(array $entry): void
    {
        $sourceKind = $entry['sourceKind'] ?? '';

        if ($sourceKind === 'assignment-submission') {
            $this->rollupFromAssignmentSubmission(entry: $entry);
            return;
        }

        if ($sourceKind === 'assessment-result') {
            $this->rollupFromAssessmentResult(entry: $entry);
        }

    }//end handleGradeEntryPublished()

    /**
     * Roll up an assignment-submission-sourced GradeEntry.
     *
     * Resolves submissionId -> Submission.assignmentId -> Assignment.competencyIds.
     *
     * @param array<string,mixed> $entry The published GradeEntry data.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/assignments/spec.md#requirement-assignment-declares-which-competencies-it-assesses
     */
    private function rollupFromAssignmentSubmission(array $entry): void
    {
        $submissionId = $entry['submissionId'] ?? '';
        if ($submissionId === '') {
            return;
        }

        $submission = $this->loadObject(schema: self::SUBMISSION_SCHEMA, id: $submissionId);
        if ($submission === null) {
            return;
        }

        $assignmentId = $submission['assignmentId'] ?? '';
        if ($assignmentId === '') {
            return;
        }

        $assignment = $this->loadObject(schema: self::ASSIGNMENT_SCHEMA, id: $assignmentId);
        if ($assignment === null) {
            return;
        }

        $competencyIds = $assignment['competencyIds'] ?? [];
        if (empty($competencyIds) === true) {
            return;
        }

        $percent = $this->percentageFor(value: $entry['value'] ?? null, maxPoints: $assignment['maxPoints'] ?? null);

        $entryId = $entry['id'] ?? ($entry['uuid'] ?? '');
        foreach ($competencyIds as $competencyId) {
            $this->upsertAttainment(
                learnerId: $entry['learnerId'] ?? '',
                competencyId: $competencyId,
                tenantId: $entry['tenant_id'] ?? '',
                evidenceAppend: [
                    'gradeEntryIds' => $entryId,
                    'submissionIds' => $submissionId,
                ],
                percent: $percent
            );
        }

    }//end rollupFromAssignmentSubmission()

    /**
     * Roll up an assessment-result-sourced GradeEntry.
     *
     * Resolves assessmentResultId -> AssessmentResult.assessmentId -> Assessment.competencyIds.
     *
     * @param array<string,mixed> $entry The published GradeEntry data.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/assessment/spec.md#requirement-assessment-declares-which-competencies-it-assesses-and-item-carries-competency-tags-for-authoring
     */
    private function rollupFromAssessmentResult(array $entry): void
    {
        $assessmentResultId = $entry['assessmentResultId'] ?? '';
        if ($assessmentResultId === '') {
            return;
        }

        $assessmentResult = $this->loadObject(schema: self::ASSESSMENT_RESULT_SCHEMA, id: $assessmentResultId);
        if ($assessmentResult === null) {
            return;
        }

        $assessmentId = $assessmentResult['assessmentId'] ?? '';
        if ($assessmentId === '') {
            return;
        }

        $assessment = $this->loadObject(schema: self::ASSESSMENT_SCHEMA, id: $assessmentId);
        if ($assessment === null) {
            return;
        }

        $competencyIds = $assessment['competencyIds'] ?? [];
        if (empty($competencyIds) === true) {
            return;
        }

        $percent = $this->percentageFor(
            value: $entry['value'] ?? null,
            maxPoints: $this->assessmentMaxPoints(assessment: $assessment)
        );

        $entryId = $entry['id'] ?? ($entry['uuid'] ?? '');
        foreach ($competencyIds as $competencyId) {
            $this->upsertAttainment(
                learnerId: $entry['learnerId'] ?? '',
                competencyId: $competencyId,
                tenantId: $entry['tenant_id'] ?? '',
                evidenceAppend: [
                    'gradeEntryIds'       => $entryId,
                    'assessmentResultIds' => $assessmentResultId,
                ],
                percent: $percent
            );
        }

    }//end rollupFromAssessmentResult()

    /**
     * Roll up a confirmed WerkprocesAssessment with a resolved competencyId.
     *
     * Uses the assessment's own generalized competencyId directly — no join
     * needed. A null competencyId (unresolved kwalificatiedossier code) is a
     * no-op: confirmation is never blocked by this handler.
     *
     * @param array<string,mixed> $assessment The confirmed WerkprocesAssessment data.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function handleWerkprocesConfirmed(array $assessment): void
    {
        $competencyId = $assessment['competencyId'] ?? null;
        if (empty($competencyId) === true) {
            return;
        }

        $bpvPlacementId = $assessment['bpvPlacementId'] ?? '';
        $placement      = $this->loadObject(schema: self::BPV_PLACEMENT_SCHEMA, id: $bpvPlacementId);
        if ($placement === null) {
            return;
        }

        $learnerId = $placement['learnerId'] ?? '';
        if ($learnerId === '') {
            return;
        }

        $tenantId = $placement['tenant_id'] ?? ($assessment['tenant_id'] ?? '');

        $beoordeling = $assessment['beoordeling'] ?? '';
        $levelId     = $this->resolveLevelByLabel(competencyId: $competencyId, beoordeling: $beoordeling);

        $assessmentId = $assessment['id'] ?? ($assessment['uuid'] ?? '');
        $this->upsertAttainment(
            learnerId: $learnerId,
            competencyId: $competencyId,
            tenantId: $tenantId,
            evidenceAppend: ['werkprocesAssessmentIds' => $assessmentId],
            percent: null,
            levelId: $levelId
        );

    }//end handleWerkprocesConfirmed()

    /**
     * Find-or-create a CompetencyAttainment row and idempotently append evidence.
     *
     * @param string                    $learnerId      NC user id of the learner.
     * @param string                    $competencyId   UUID of the aligned Competency.
     * @param string                    $tenantId       Tenant UUID scope.
     * @param array<string,string|null> $evidenceAppend Map of evidence-array field name to the id to append.
     * @param float|null                $percent        Evidence percentage for threshold-based level resolution,
     *                                                  or null when not applicable (e.g. the WerkprocesAssessment path).
     * @param string|null               $levelId        An already-resolved levelId (WerkprocesAssessment path);
     *                                                  when null, percent-based resolution is attempted instead.
     *
     * @return void
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function upsertAttainment(
        string $learnerId,
        string $competencyId,
        string $tenantId,
        array $evidenceAppend,
        ?float $percent=null,
        ?string $levelId=null,
    ): void {
        if ($learnerId === '' || $competencyId === '') {
            return;
        }

        $competency = $this->loadObject(schema: self::COMPETENCY_SCHEMA, id: $competencyId);
        if ($competency === null) {
            return;
        }

        $frameworkId = $competency['frameworkId'] ?? '';

        $existing = $this->findExistingAttainment(learnerId: $learnerId, competencyId: $competencyId, tenantId: $tenantId);

        $data = $existing ?? [
            'learnerId'               => $learnerId,
            'competencyId'            => $competencyId,
            'frameworkId'             => $frameworkId,
            'tenant_id'               => $tenantId,
            'gradeEntryIds'           => [],
            'assessmentResultIds'     => [],
            'werkprocesAssessmentIds' => [],
            'submissionIds'           => [],
            'proficiencyLevelId'      => null,
        ];

        foreach ($evidenceAppend as $field => $id) {
            if ($id === '' || $id === null) {
                continue;
            }

            $arr = $data[$field] ?? [];
            if (is_array($arr) === false) {
                $arr = [];
            }

            if (in_array($id, $arr, true) === false) {
                $arr[] = $id;
            }

            $data[$field] = $arr;
        }

        $resolvedLevelId = $levelId;
        if ($resolvedLevelId === null && $percent !== null) {
            $resolvedLevelId = $this->resolveLevelByPercent(frameworkId: $frameworkId, percent: $percent);
        }

        if ($resolvedLevelId !== null) {
            $data['proficiencyLevelId'] = $resolvedLevelId;
        }

        $data['learnerId']    = $learnerId;
        $data['competencyId'] = $competencyId;

        $data['frameworkId'] = $data['frameworkId'] ?? '';
        if ($frameworkId !== '') {
            $data['frameworkId'] = $frameworkId;
        }

        $data['tenant_id'] = $data['tenant_id'] ?? '';
        if ($tenantId !== '') {
            $data['tenant_id'] = $tenantId;
        }

        $data['lastRecomputedAt'] = (new DateTimeImmutable())->format(\DATE_ATOM);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ATTAINMENT_SCHEMA,
            object: $data
        );

        $kind = 'created';
        if ($existing !== null) {
            $kind = 'updated';
        }

        $this->logger->info(
            '[CompetencyAttainmentRollupHandler] CompetencyAttainment {kind} for learner {learner}, competency {cid}.',
            ['kind' => $kind, 'learner' => $learnerId, 'cid' => $competencyId]
        );

    }//end upsertAttainment()

    /**
     * Find an existing CompetencyAttainment row for a (learnerId, competencyId) pair.
     *
     * @param string $learnerId    NC user id.
     * @param string $competencyId Competency UUID.
     * @param string $tenantId     Tenant UUID scope filter.
     *
     * @return array<string,mixed>|null The existing row data, or null when none exists.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function findExistingAttainment(string $learnerId, string $competencyId, string $tenantId): ?array
    {
        $filters = [
            'learnerId'    => $learnerId,
            'competencyId' => $competencyId,
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ATTAINMENT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        return $this->toArray(object: $results[0]);

    }//end findExistingAttainment()

    /**
     * Resolve a proficiencyLevelId from an evidence percentage against a framework's declared thresholds.
     *
     * Takes the highest-order level whose minPercent is met. A framework whose
     * levels omit minPercent entirely never resolves via this path.
     *
     * @param string $frameworkId UUID of the CompetencyFramework.
     * @param float  $percent     Evidence percentage (0-100).
     *
     * @return string|null The resolved levelId, or null when no threshold is met.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function resolveLevelByPercent(string $frameworkId, float $percent): ?string
    {
        $framework = $this->loadObject(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId);
        if ($framework === null) {
            return null;
        }

        $levels = $framework['proficiencyLevels'] ?? [];
        if (is_array($levels) === false || empty($levels) === true) {
            return null;
        }

        $bestLevelId = null;
        $bestOrder   = null;
        foreach ($levels as $level) {
            $minPercent = $level['minPercent'] ?? null;
            if ($minPercent === null) {
                continue;
            }

            if ($percent < (float) $minPercent) {
                continue;
            }

            $order = (int) ($level['order'] ?? 0);
            if ($bestOrder === null || $order > $bestOrder) {
                $bestOrder   = $order;
                $bestLevelId = $level['levelId'] ?? null;
            }
        }

        return $bestLevelId;

    }//end resolveLevelByPercent()

    /**
     * Resolve a proficiencyLevelId directly from a WerkprocesAssessment beoordeling label.
     *
     * `competent` maps to the framework's highest-order level, `nog-niet-competent`
     * to the lowest — the same binary-scale precedent WerkprocesGradeEmitHandler
     * already uses when mapping beoordeling onto GradeEntry.value.
     *
     * @param string $competencyId UUID of the Competency (used to resolve its framework).
     * @param string $beoordeling  The werkproces assessment outcome.
     *
     * @return string|null The resolved levelId, or null when unresolvable.
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function resolveLevelByLabel(string $competencyId, string $beoordeling): ?string
    {
        if (in_array($beoordeling, self::BEOORDELING_VALUES, true) === false) {
            return null;
        }

        $competency = $this->loadObject(schema: self::COMPETENCY_SCHEMA, id: $competencyId);
        if ($competency === null) {
            return null;
        }

        $frameworkId = $competency['frameworkId'] ?? '';
        if ($frameworkId === '') {
            return null;
        }

        $framework = $this->loadObject(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId);
        if ($framework === null) {
            return null;
        }

        $levels = $framework['proficiencyLevels'] ?? [];
        if (is_array($levels) === false || empty($levels) === true) {
            return null;
        }

        $lowest  = null;
        $highest = null;
        foreach ($levels as $level) {
            $order = (int) ($level['order'] ?? 0);
            if ($lowest === null || $order < (int) ($lowest['order'] ?? 0)) {
                $lowest = $level;
            }

            if ($highest === null || $order > (int) ($highest['order'] ?? 0)) {
                $highest = $level;
            }
        }

        $target = $lowest;
        if ($beoordeling === 'competent') {
            $target = $highest;
        }

        return $target['levelId'] ?? null;

    }//end resolveLevelByLabel()

    /**
     * Compute an evidence percentage from a raw value and its maximum.
     *
     * @param mixed $value     Raw GradeEntry value.
     * @param mixed $maxPoints Maximum achievable points for the source.
     *
     * @return float|null The percentage (0-100), or null when not computable.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function percentageFor(mixed $value, mixed $maxPoints): ?float
    {
        if ($value === null || $maxPoints === null) {
            return null;
        }

        $max = (float) $maxPoints;
        if ($max <= 0.0) {
            return null;
        }

        return ((float) $value / $max) * 100.0;

    }//end percentageFor()

    /**
     * Sum an Assessment's itemRefs[].points to derive its total achievable points.
     *
     * @param array<string,mixed> $assessment The Assessment data.
     *
     * @return float|null The summed max points, or null when itemRefs is empty/unset.
     *
     * @spec openspec/changes/competency-framework/specs/assessment/spec.md#requirement-assessment-declares-which-competencies-it-assesses-and-item-carries-competency-tags-for-authoring
     */
    private function assessmentMaxPoints(array $assessment): ?float
    {
        $itemRefs = $assessment['itemRefs'] ?? [];
        if (is_array($itemRefs) === false || empty($itemRefs) === true) {
            return null;
        }

        $sum = 0.0;
        foreach ($itemRefs as $ref) {
            $sum += (float) ($ref['points'] ?? 0);
        }

        if ($sum <= 0.0) {
            return null;
        }

        return $sum;

    }//end assessmentMaxPoints()

    /**
     * Load a single OpenRegister object by id.
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @return array<string,mixed>|null The object data, or null when not found.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function loadObject(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => ['id' => $id],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        return $this->toArray(object: $results[0]);

    }//end loadObject()

    /**
     * Normalise an OR object result (array or ObjectEntity-like) to a plain array.
     *
     * @param mixed $object The raw findAll()/saveObject() result element.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end toArray()
}//end class
