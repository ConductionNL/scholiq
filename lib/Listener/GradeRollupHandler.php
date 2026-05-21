<?php

/**
 * Scholiq Grade Rollup Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and handles two bridges:
 *
 * 1. GradeEntry → published: finds or creates the FinalGrade for the learner +
 *    curriculumPlan pair and recomputes it via GradeFormulaEvaluator. Also fans
 *    out the gradePublished notification to parents listed on the learner's
 *    LearnerProfile (the declarative x-openregister-notifications covers the
 *    learner directly; parents require a PHP bridge because OR's declarative
 *    notification system addresses a single field, not a related array).
 *
 * 2. AssessmentResult → graded: creates a concept GradeEntry bridging the
 *    auto-scored / teacher-scored result into the grading pipeline. Sets
 *    AssessmentResult.gradeEntryId. The teacher then reviews and publishes the
 *    concept entry via the GradebookView.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write bridge
 * that cannot be expressed as a schema declaration." Single responsibility:
 * translate ObjectTransitionedEvents into GradeEntry / FinalGrade writes.
 * No audit writes (OR's lifecycle engine handles those).
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Grading\GradeFormulaEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges GradeEntry.published → FinalGrade recompute and AssessmentResult.graded → GradeEntry creation.
 *
 * @implements IEventListener<Event>
 */
class GradeRollupHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const GRADE_ENTRY_SCHEMA       = 'grade-entry';
    private const FINAL_GRADE_SCHEMA       = 'final-grade';
    private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
    private const LEARNER_PROFILE_SCHEMA   = 'learner-profile';

    /**
     * Constructor.
     *
     * @param ObjectService         $objectService OpenRegister object access.
     * @param GradeFormulaEvaluator $evaluator     Formula evaluation engine.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly GradeFormulaEvaluator $evaluator,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() === self::GRADE_ENTRY_SCHEMA
            && $event->getTo() === 'published'
        ) {
            $this->handleGradeEntryPublished(event: $event);
            return;
        }

        if ($event->getSchema() === self::ASSESSMENT_RESULT_SCHEMA
            && $event->getTo() === 'graded'
        ) {
            $this->handleAssessmentResultGraded(event: $event);
        }

    }//end handle()

    /**
     * Recompute FinalGrade and fan out parent notifications when a GradeEntry publishes.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     */
    private function handleGradeEntryPublished(ObjectTransitionedEvent $event): void
    {
        $entry            = $event->getObject()->jsonSerialize();
        $learnerId        = $entry['learnerId'] ?? '';
        $curriculumPlanId = $entry['curriculumPlanId'] ?? '';
        $tenantId         = $entry['tenant_id'] ?? '';

        if ($learnerId === '' || $curriculumPlanId === '') {
            return;
        }

        $this->recomputeFinalGrade(
            learnerId: $learnerId,
            curriculumPlanId: $curriculumPlanId,
            tenantId: $tenantId,
            entry: $entry
        );

        $this->fanOutParentNotifications(learnerId: $learnerId, gradeEntry: $entry);

    }//end handleGradeEntryPublished()

    /**
     * Find or create the FinalGrade, evaluate, and persist.
     *
     * @param string $learnerId        NC user ID.
     * @param string $curriculumPlanId Plan UUID.
     * @param string $tenantId         Tenant ID.
     * @param array  $entry            The published GradeEntry.
     *
     * @return void
     */
    private function recomputeFinalGrade(
        string $learnerId,
        string $curriculumPlanId,
        string $tenantId,
        array $entry,
    ): void {
        $result = $this->evaluator->evaluate(
            curriculumPlanId: $curriculumPlanId,
            learnerId: $learnerId
        );

        // Find existing FinalGrade for this pair.
        $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::FINAL_GRADE_SCHEMA,
                    'filters'  => [
                        'learnerId'        => $learnerId,
                        'curriculumPlanId' => $curriculumPlanId,
                    ],
                    'limit'    => 1,
                ]
                );

        $existingObj = null;
        if (empty($existing) === false && is_array($existing[0]) === true) {
            $existingObj = $existing[0];
        }

        if (empty($existing) === false && is_array($existing[0]) === false) {
            $existingObj = $existing[0]->jsonSerialize();
        }

        $data = array_merge(
            $existingObj ?? [],
            [
                'learnerId'        => $learnerId,
                'curriculumPlanId' => $curriculumPlanId,
                'courseId'         => $entry['courseId'] ?? ($existingObj['courseId'] ?? null),
                'cohortId'         => $entry['cohortId'] ?? ($existingObj['cohortId'] ?? null),
                'gradeScaleId'     => $entry['gradeScaleId'] ?? ($existingObj['gradeScaleId'] ?? null),
                'tenant_id'        => $tenantId,
                'value'            => $result['value'],
                'passed'           => $result['passed'],
                'breakdown'        => $result['breakdown'],
                'lastRecomputedAt' => $result['lastRecomputedAt'],
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::FINAL_GRADE_SCHEMA,
            object: $data
        );

    }//end recomputeFinalGrade()

    /**
     * Resolve LearnerProfile.parentIds and fire the gradePublished notification for each parent.
     *
     * The declarative x-openregister-notifications on GradeEntry targets the learnerId only.
     * Parents require an explicit PHP bridge per the design doc (§3.2).
     * Notification is best-effort — a failure here does not roll back the FinalGrade recompute.
     *
     * @param string $learnerId  NC user ID.
     * @param array  $gradeEntry Published GradeEntry data.
     *
     * @return void
     */
    private function fanOutParentNotifications(string $learnerId, array $gradeEntry): void
    {
        $profiles = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::LEARNER_PROFILE_SCHEMA,
                    'filters'  => ['learnerId' => $learnerId],
                    'limit'    => 1,
                ]
                );

        if (empty($profiles) === true) {
            return;
        }

        $profile = $profiles[0];
        if (is_array($profiles[0]) === false) {
            $profile = $profiles[0]->jsonSerialize();
        }

        $parentIds = $profile['parentIds'] ?? [];

        if (empty($parentIds) === true) {
            return;
        }

        // Each parent receives a gradePublished notification.
        // OR's notification subsystem respects the parent's own notification preference
        // (instant vs daily-digest) stored via UserService::getNotificationPreferences().
        // We build a synthetic notification object per parent — OR dispatches or queues it
        // based on the recipient's stored preference mode.
        foreach ($parentIds as $parentId) {
            if (empty($parentId) === true) {
                continue;
            }

            // Persist a lightweight notification record via OR so BatchNotificationJob
            // can pick it up for digest recipients.
            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: 'grade-entry',
                object: array_merge(
                        $gradeEntry,
                        [
                            '_notification' => [
                                'event'          => 'gradePublished',
                                'recipient'      => $parentId,
                                'sourceId'       => $gradeEntry['id'] ?? '',
                                'idempotencyKey' => ($gradeEntry['id'] ?? '').'-parent-'.$parentId,
                            ],
                        ]
                        )
            );
        }//end foreach

    }//end fanOutParentNotifications()

    /**
     * Create a concept GradeEntry when an AssessmentResult reaches `graded`.
     *
     * Bridges the auto-scored / teacher-scored result into the grading pipeline
     * so the teacher can review and publish it via the GradebookView.
     * Sets AssessmentResult.gradeEntryId after creation.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     */
    private function handleAssessmentResultGraded(ObjectTransitionedEvent $event): void
    {
        $result = $event->getObject()->jsonSerialize();

        $learnerId   = $result['learnerId'] ?? '';
        $totalScore  = $result['totalScore'] ?? null;
        $tenantId    = $result['tenant_id'] ?? '';
        $componentId = $result['gradeEntryComponentId'] ?? null;
        $planId      = $result['curriculumPlanId'] ?? null;
        $scaleId     = $result['gradeScaleId'] ?? null;
        $existingId  = $result['gradeEntryId'] ?? null;

        if ($learnerId === '' || $totalScore === null || $componentId === null || $planId === null) {
            // Insufficient data to create a meaningful GradeEntry — skip.
            return;
        }

        // Do not create a duplicate if gradeEntryId is already set.
        if ($existingId !== null) {
            return;
        }

        $gradeEntry = [
            'learnerId'          => $learnerId,
            'curriculumPlanId'   => $planId,
            'componentId'        => $componentId,
            'sourceKind'         => 'assessment-result',
            'assessmentResultId' => $result['id'] ?? null,
            'value'              => (float) $totalScore,
            'gradeScaleId'       => $scaleId ?? '',
            'grader'             => 'auto',
            'gradedAt'           => (new DateTimeImmutable())->format(\DATE_ATOM),
            'tenant_id'          => $tenantId,
            'lifecycle'          => 'concept',
        ];

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::GRADE_ENTRY_SCHEMA,
            object: $gradeEntry
        );

        if ($saved === null) {
            return;
        }

        $savedData = $saved;
        if (is_array($saved) === false) {
            $savedData = $saved->jsonSerialize();
        }

        $gradeEntryId = $savedData['id'] ?? null;

        if ($gradeEntryId === null) {
            return;
        }

        // Back-link the GradeEntry to the AssessmentResult.
        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ASSESSMENT_RESULT_SCHEMA,
            object: array_merge($result, ['gradeEntryId' => $gradeEntryId])
        );

    }//end handleAssessmentResultGraded()
}//end class
