<?php

/**
 * Scholiq BsaProgressEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\StudyProgress
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\StudyProgress;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\StudyProgress\BsaProgressEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BsaProgressEvaluator::evaluate().
 */
class BsaProgressEvaluatorTest extends TestCase
{

    /**
     * Build an evaluator with an ObjectService stub driven by the given
     * course + final-grade fixtures.
     *
     * @param array<int, array> $courses     Course rows returned for the course query.
     * @param array<int, array> $finalGrades FinalGrade rows returned for the passed-grades query.
     *
     * @return BsaProgressEvaluator
     */
    private function makeEvaluator(array $courses, array $finalGrades): BsaProgressEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($courses, $finalGrades) {
                if ($config['schema'] === 'course') {
                    return $courses;
                }

                if ($config['schema'] === 'final-grade') {
                    return $finalGrades;
                }

                return [];
            }
        );

        return new BsaProgressEvaluator($objectService);

    }//end makeEvaluator()

    /**
     * Multiple passed courses sum correctly.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-falling-behind-pace-ahead-of-the-interim-check-raises-a-flag
     */
    public function testMultiplePassedCoursesSumCorrectly(): void
    {
        $courses = [
            ['id' => 'course-1', 'ectsCredits' => 6],
            ['id' => 'course-2', 'ectsCredits' => 4.5],
            ['id' => 'course-3', 'ectsCredits' => 3],
        ];

        $finalGrades = [
            ['learnerId' => 'learner-1', 'courseId' => 'course-1', 'passed' => true],
            ['learnerId' => 'learner-1', 'courseId' => 'course-2', 'passed' => true],
        ];

        $evaluator = $this->makeEvaluator(courses: $courses, finalGrades: $finalGrades);

        $result = $evaluator->evaluate(programmeId: 'programme-1', learnerId: 'learner-1');

        self::assertSame(10.5, $result['ectsEarned']);

    }//end testMultiplePassedCoursesSumCorrectly()

    /**
     * A course with a null ectsCredits contributes 0, not an error.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-a-course-with-no-declared-credit-value-contributes-zero-not-an-error
     */
    public function testNullEctsCreditsContributesZero(): void
    {
        $courses = [
            ['id' => 'course-1', 'ectsCredits' => 6],
            ['id' => 'course-2', 'ectsCredits' => null],
        ];

        $finalGrades = [
            ['learnerId' => 'learner-1', 'courseId' => 'course-1', 'passed' => true],
            ['learnerId' => 'learner-1', 'courseId' => 'course-2', 'passed' => true],
        ];

        $evaluator = $this->makeEvaluator(courses: $courses, finalGrades: $finalGrades);

        $result = $evaluator->evaluate(programmeId: 'programme-1', learnerId: 'learner-1');

        self::assertSame(6.0, $result['ectsEarned']);

    }//end testNullEctsCreditsContributesZero()

    /**
     * A learner with zero passed courses returns 0, not an error.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    public function testZeroPassedCoursesReturnsZero(): void
    {
        $courses = [
            ['id' => 'course-1', 'ectsCredits' => 6],
        ];

        $evaluator = $this->makeEvaluator(courses: $courses, finalGrades: []);

        $result = $evaluator->evaluate(programmeId: 'programme-1', learnerId: 'learner-1');

        self::assertSame(0.0, $result['ectsEarned']);

    }//end testZeroPassedCoursesReturnsZero()

    /**
     * A FinalGrade referencing a course outside the Programme's course list
     * does not contribute.
     *
     * @return void
     */
    public function testFinalGradeOutsideProgrammeScopeIsIgnored(): void
    {
        $courses = [
            ['id' => 'course-1', 'ectsCredits' => 6],
        ];

        $finalGrades = [
            ['learnerId' => 'learner-1', 'courseId' => 'course-1', 'passed' => true],
            ['learnerId' => 'learner-1', 'courseId' => 'course-other-programme', 'passed' => true],
        ];

        $evaluator = $this->makeEvaluator(courses: $courses, finalGrades: $finalGrades);

        $result = $evaluator->evaluate(programmeId: 'programme-1', learnerId: 'learner-1');

        self::assertSame(6.0, $result['ectsEarned']);

    }//end testFinalGradeOutsideProgrammeScopeIsIgnored()

    /**
     * Empty programmeId or learnerId short-circuits to 0 without querying.
     *
     * @return void
     */
    public function testEmptyIdentifiersReturnZeroWithoutQuerying(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $evaluator = new BsaProgressEvaluator($objectService);

        $result = $evaluator->evaluate(programmeId: '', learnerId: 'learner-1');

        self::assertSame(0.0, $result['ectsEarned']);

    }//end testEmptyIdentifiersReturnZeroWithoutQuerying()
}//end class
