<?php

/**
 * Scholiq Enrolment Progress Rollup Handler
 *
 * Listens for OR's ObjectCreatedEvent on LessonCompletion objects and
 * recomputes the matching Enrolment's progressPercent via
 * EnrolmentProgressEvaluator. Mirrors GradeRollupHandler's role for
 * FinalGrade — a cross-schema roll-up write-bridge, not a TimedJob
 * (ADR-022).
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration — no division operator exists in this
 * register's calculation DSL (see EnrolmentProgressEvaluator).
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Progress\EnrolmentProgressEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes Enrolment.progressPercent whenever a LessonCompletion is created.
 *
 * @implements IEventListener<Event>
 */
class EnrolmentProgressRollupHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';
    private const ENROLMENT_SCHEMA         = 'enrolment';

    /**
     * Constructor.
     *
     * @param ObjectService              $objectService OR object access.
     * @param EnrolmentProgressEvaluator $evaluator     progressPercent calculation engine.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly EnrolmentProgressEvaluator $evaluator,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectCreatedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        $objectEntity = $event->getObject();

        if ($objectEntity->getRegister() !== self::SCHOLIQ_REGISTER
            || $objectEntity->getSchema() !== self::LESSON_COMPLETION_SCHEMA
        ) {
            return;
        }

        $completion = $objectEntity->jsonSerialize();
        $learnerId  = $completion['learnerId'] ?? '';
        // LessonCompletion already denormalizes courseId (mirrors
        // XapiStatement.courseId/.lessonId) — no extra Lesson lookup needed
        // to resolve the course scope.
        $courseId = $completion['courseId'] ?? '';

        if ($learnerId === '' || $courseId === '') {
            return;
        }

        $enrolment = $this->findActiveEnrolment(learnerId: $learnerId, courseId: $courseId);
        if ($enrolment === null) {
            // No active Enrolment for this learner+course — nothing to
            // recompute onto. Skipped without error.
            return;
        }

        $result = $this->evaluator->evaluate(learnerId: $learnerId, courseId: $courseId);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ENROLMENT_SCHEMA,
            object: array_merge($enrolment, ['progressPercent' => $result['progressPercent']])
        );

    }//end handle()

    /**
     * Find the learner's active Enrolment for a course.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     *
     * @return array<string, mixed>|null
     */
    private function findActiveEnrolment(string $learnerId, string $courseId): ?array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENROLMENT_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                    'lifecycle' => 'active',
                ],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $enrolment = $results[0];
        if (is_array($enrolment) === false) {
            $enrolment = $enrolment->jsonSerialize();
        }

        return $enrolment;

    }//end findActiveEnrolment()
}//end class
