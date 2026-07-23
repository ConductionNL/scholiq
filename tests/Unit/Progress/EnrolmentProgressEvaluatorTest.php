<?php

/**
 * Scholiq EnrolmentProgressEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Progress
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

namespace OCA\Scholiq\Tests\Unit\Progress;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Progress\EnrolmentProgressEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressEvaluator::evaluate().
 */
class EnrolmentProgressEvaluatorTest extends TestCase
{

    /**
     * Build an evaluator whose ObjectService returns the given completed and
     * published-lesson counts for the (learnerId, courseId) pair.
     *
     * @param int $completedCount Number of LessonCompletion rows to return.
     * @param int $publishedCount Number of published Lesson rows to return.
     *
     * @return EnrolmentProgressEvaluator
     */
    private function makeEvaluator(int $completedCount, int $publishedCount): EnrolmentProgressEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($completedCount, $publishedCount) {
                if ($config['schema'] === 'lesson-completion') {
                    return array_fill(0, $completedCount, ['id' => 'x']);
                }

                if ($config['schema'] === 'lesson') {
                    return array_fill(0, $publishedCount, ['id' => 'y']);
                }

                return [];
            }
        );

        return new EnrolmentProgressEvaluator($objectService);

    }//end makeEvaluator()

    /**
     * A normal ratio computes the expected percentage.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
     */
    public function testNormalRatio(): void
    {
        $evaluator = $this->makeEvaluator(completedCount: 4, publishedCount: 10);

        $result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

        self::assertSame(40, $result['progressPercent']);
        self::assertSame(4, $result['completedLessonCount']);
        self::assertSame(10, $result['totalPublishedLessonCount']);

    }//end testNormalRatio()

    /**
     * Zero completions yields 0%, not an error.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
     */
    public function testZeroCompletions(): void
    {
        $evaluator = $this->makeEvaluator(completedCount: 0, publishedCount: 10);

        $result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

        self::assertSame(0, $result['progressPercent']);

    }//end testZeroCompletions()

    /**
     * Zero published lessons yields 0%, never a divide-by-zero error.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
     */
    public function testZeroPublishedLessons(): void
    {
        $evaluator = $this->makeEvaluator(completedCount: 0, publishedCount: 0);

        $result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

        self::assertSame(0, $result['progressPercent']);
        self::assertSame(0, $result['totalPublishedLessonCount']);

    }//end testZeroPublishedLessons()

    /**
     * A ratio that does not divide evenly is rounded.
     *
     * @return void
     */
    public function testRatioRequiringRounding(): void
    {
        $evaluator = $this->makeEvaluator(completedCount: 1, publishedCount: 3);

        $result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

        // 1/3 * 100 = 33.33... -> rounds to 33.
        self::assertSame(33, $result['progressPercent']);

    }//end testRatioRequiringRounding()

    /**
     * Full completion yields exactly 100%.
     *
     * @return void
     */
    public function testFullCompletion(): void
    {
        $evaluator = $this->makeEvaluator(completedCount: 10, publishedCount: 10);

        $result = $evaluator->evaluate(learnerId: 'learner-1', courseId: 'course-1');

        self::assertSame(100, $result['progressPercent']);

    }//end testFullCompletion()
}//end class
