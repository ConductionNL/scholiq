<?php

/**
 * Scholiq CourseQualityScoreEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\CourseEvaluation
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-course-teacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\CourseEvaluation;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\CourseEvaluation\CourseQualityScoreEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CourseQualityScoreEvaluator::evaluate().
 */
class CourseQualityScoreEvaluatorTest extends TestCase
{

    /**
     * Build an evaluator with stubbed response/invitation sets for course-1/2025-2026/Q1.
     *
     * @param array<int, array<string, mixed>> $responses   CourseEvaluationResponse rows.
     * @param array<int, array<string, mixed>> $invitations EvaluationInvitation rows.
     *
     * @return CourseQualityScoreEvaluator
     */
    private function makeEvaluator(array $responses, array $invitations): CourseQualityScoreEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($responses, $invitations) {
                if ($config['schema'] === 'course-evaluation-response') {
                    return $responses;
                }

                if ($config['schema'] === 'evaluation-invitation') {
                    return $invitations;
                }

                return [];
            }
        );

        return new CourseQualityScoreEvaluator($objectService);

    }//end makeEvaluator()

    /**
     * Two prior responses (overallScore 4, 5) averaging 4.5 plus a third
     * (overallScore 3) recomputes to average 4 and responseCount 3.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    public function testAverageRecomputesCorrectlyAcrossMultipleResponses(): void
    {
        $responses = [
            ['overallScore' => 4],
            ['overallScore' => 5],
            ['overallScore' => 3],
        ];

        $evaluator = $this->makeEvaluator(responses: $responses, invitations: []);

        $result = $evaluator->evaluate(courseId: 'course-1', teacherId: null, academicYear: '2025-2026', period: 'Q1');

        self::assertSame(3, $result['responseCount']);
        self::assertSame(4.0, $result['averageOverallScore']);

    }//end testAverageRecomputesCorrectlyAcrossMultipleResponses()

    /**
     * A response with a null overallScore is excluded from both the sum and
     * the divisor — it does not drag the average toward zero.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    public function testNullOverallScoreDoesNotSkewAverage(): void
    {
        $responses = [
            ['overallScore' => 5],
            ['overallScore' => null],
            ['overallScore' => 5],
        ];

        $evaluator = $this->makeEvaluator(responses: $responses, invitations: []);

        $result = $evaluator->evaluate(courseId: 'course-1', teacherId: null, academicYear: '2025-2026', period: 'Q1');

        self::assertSame(3, $result['responseCount'], 'responseCount counts every submitted response, including a null-score one');
        self::assertSame(5.0, $result['averageOverallScore'], 'averageOverallScore ignores the null entry entirely, not treating it as 0');

    }//end testNullOverallScoreDoesNotSkewAverage()

    /**
     * responseRate divides responseCount by invitationCount: 5 responses
     * over 20 invitations is 0.25.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-response-rate-reflects-invitations-not-just-responses
     */
    public function testResponseRateDividesByInvitationCount(): void
    {
        $responses   = array_fill(0, 5, ['overallScore' => 4]);
        $invitations = array_fill(0, 20, ['hasResponded' => false]);

        $evaluator = $this->makeEvaluator(responses: $responses, invitations: $invitations);

        $result = $evaluator->evaluate(courseId: 'course-1', teacherId: null, academicYear: '2025-2026', period: 'Q1');

        self::assertSame(5, $result['responseCount']);
        self::assertSame(20, $result['invitationCount']);
        self::assertSame(0.25, $result['responseRate']);

    }//end testResponseRateDividesByInvitationCount()

    /**
     * Zero invitations returns responseRate:0, never a divide-by-zero error.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-course-teacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob
     */
    public function testZeroInvitationsReturnsZeroResponseRate(): void
    {
        $evaluator = $this->makeEvaluator(responses: [], invitations: []);

        $result = $evaluator->evaluate(courseId: 'course-1', teacherId: null, academicYear: '2025-2026', period: 'Q1');

        self::assertSame(0, $result['invitationCount']);
        self::assertSame(0.0, $result['responseRate']);
        self::assertNull($result['averageOverallScore'], 'No responses at all -> null average, not 0');

    }//end testZeroInvitationsReturnsZeroResponseRate()
}//end class
