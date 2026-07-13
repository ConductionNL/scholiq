<?php

/**
 * Scholiq Enrolment Progress Evaluator
 *
 * Stateless calculation engine computing an Enrolment's progressPercent from
 * its declared completedLessonCount/totalPublishedLessonCount aggregate-refs.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * No division operator exists in this register's JSON-logic calculation DSL
 * (verified by a full scan of every x-openregister-calculations expression
 * in lib/Settings/scholiq_register.json — see proposal.md) — a small PHP
 * class is genuinely needed, the same shape of gap FinalGrade.value /
 * GradeFormulaEvaluator already solved. Single responsibility: evaluate ->
 * return; no state, no audit writes.
 *
 * Consumed by:
 *   - EnrolmentProgressRollupHandler (via ObjectCreatedEvent<LessonCompletion>)
 *
 * @category Progress
 * @package  OCA\Scholiq\Progress
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

namespace OCA\Scholiq\Progress;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes progressPercent = round(completedLessonCount / totalPublishedLessonCount * 100).
 */
class EnrolmentProgressEvaluator
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const LESSON_SCHEMA            = 'lesson';
    private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object access.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Evaluate progressPercent for a learner + course.
     *
     * Counts the learner's LessonCompletion rows for the course and the
     * course's published Lessons, then computes a null-safe percentage —
     * 0 (never a divide-by-zero error) when either count is 0.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     *
     * @return array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int}
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
     */
    public function evaluate(string $learnerId, string $courseId): array
    {
        $completedLessonCount      = $this->countCompletedLessons(learnerId: $learnerId, courseId: $courseId);
        $totalPublishedLessonCount = $this->countPublishedLessons(courseId: $courseId);

        if ($completedLessonCount === 0 || $totalPublishedLessonCount === 0) {
            return [
                'progressPercent'           => 0,
                'completedLessonCount'      => $completedLessonCount,
                'totalPublishedLessonCount' => $totalPublishedLessonCount,
            ];
        }

        $progressPercent = (int) round(($completedLessonCount / $totalPublishedLessonCount) * 100);

        return [
            'progressPercent'           => $progressPercent,
            'completedLessonCount'      => $completedLessonCount,
            'totalPublishedLessonCount' => $totalPublishedLessonCount,
        ];

    }//end evaluate()

    /**
     * Count the learner's LessonCompletion rows for a course.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     *
     * @return int
     */
    private function countCompletedLessons(string $learnerId, string $courseId): int
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LESSON_COMPLETION_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                ],
            ]
        );

        return count($results);

    }//end countCompletedLessons()

    /**
     * Count a course's published Lessons.
     *
     * @param string $courseId UUID of the Course.
     *
     * @return int
     */
    private function countPublishedLessons(string $courseId): int
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LESSON_SCHEMA,
                'filters'  => [
                    'courseId'  => $courseId,
                    'lifecycle' => 'published',
                ],
            ]
        );

        return count($results);

    }//end countPublishedLessons()
}//end class
