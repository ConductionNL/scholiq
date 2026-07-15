<?php

/**
 * Scholiq Course Quality Score Evaluator
 *
 * Stateless calculation engine that computes averageOverallScore and
 * responseRate for a (courseId, teacherId, academicYear, period) scope from
 * the matching submitted CourseEvaluationResponses and provisioned
 * EvaluationInvitations.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * Averaging (mean of overallScore, ignoring null) and a division
 * (responseCount / invitationCount) are beyond this register's proven
 * declarative aggregation metrics — a full-file grep of scholiq_register.json
 * for `"metric":` shows only `count`/`count_distinct` in use anywhere in
 * this register. Mirrors GradeFormulaEvaluator's shape: evaluate → return;
 * no state, no audit writes.
 *
 * Consumed by:
 *   - CourseQualityScoreRollupHandler (via ObjectTransitionedEvent)
 *
 * @category CourseEvaluation
 * @package  OCA\Scholiq\CourseEvaluation
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

namespace OCA\Scholiq\CourseEvaluation;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Evaluates averageOverallScore/responseRate for a course/teacher/period scope.
 */
class CourseQualityScoreEvaluator
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const COURSE_EVALUATION_RESPONSE_SCHEMA = 'course-evaluation-response';
    private const EVALUATION_INVITATION_SCHEMA      = 'evaluation-invitation';

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
     * Evaluate the quality score for a (courseId, teacherId, academicYear, period) scope.
     *
     * @param string      $courseId     Course UUID.
     * @param string|null $teacherId    Teacher NC user id, or null for a course-level-only scope.
     * @param string      $academicYear Academic year, e.g. '2025-2026'.
     * @param string      $period       Period label, e.g. 'Q1'.
     *
     * @return array{responseCount: int, invitationCount: int, averageOverallScore: float|null,
     *               responseRate: float, lastRecomputedAt: string}
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-response-rate-reflects-invitations-not-just-responses
     */
    public function evaluate(string $courseId, ?string $teacherId, string $academicYear, string $period): array
    {
        $responses   = $this->fetchSubmittedResponses(
            courseId: $courseId,
            teacherId: $teacherId,
            academicYear: $academicYear,
            period: $period
        );
        $invitations = $this->fetchInvitations(courseId: $courseId, academicYear: $academicYear, period: $period);

        $responseCount   = count($responses);
        $invitationCount = count($invitations);

        $averageOverallScore = $this->averageOverallScore(responses: $responses);
        $responseRate        = $this->responseRate(responseCount: $responseCount, invitationCount: $invitationCount);

        return [
            'responseCount'       => $responseCount,
            'invitationCount'     => $invitationCount,
            'averageOverallScore' => $averageOverallScore,
            'responseRate'        => $responseRate,
            'lastRecomputedAt'    => (new DateTimeImmutable())->format(\DATE_ATOM),
        ];

    }//end evaluate()

    /**
     * Fetch every submitted CourseEvaluationResponse in scope.
     *
     * @param string      $courseId     Course UUID.
     * @param string|null $teacherId    Teacher NC user id, or null.
     * @param string      $academicYear Academic year.
     * @param string      $period       Period label.
     *
     * @return array<int, array>
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    private function fetchSubmittedResponses(
        string $courseId,
        ?string $teacherId,
        string $academicYear,
        string $period,
    ): array {
        $filters = [
            'courseId'     => $courseId,
            'academicYear' => $academicYear,
            'period'       => $period,
            'lifecycle'    => 'submitted',
        ];

        if ($teacherId !== null && $teacherId !== '') {
            $filters['teacherId'] = $teacherId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COURSE_EVALUATION_RESPONSE_SCHEMA,
                'filters'  => $filters,
            ]
        );

        return $this->normaliseResults(results: $results);

    }//end fetchSubmittedResponses()

    /**
     * Fetch every EvaluationInvitation provisioned for this course/period scope.
     *
     * Not filtered by teacherId — EvaluationInvitation is provisioned per
     * learner+course, not per learner+teacher, so a teacher-scoped
     * CourseQualityScore's responseRate denominator is the same course-wide
     * invitation count as the course-level score (documented simplification;
     * a teacher-specific denominator would need EvaluationInvitation to also
     * carry a teacherId, which the campaign does not scope invitations by).
     *
     * @param string $courseId     Course UUID.
     * @param string $academicYear Academic year.
     * @param string $period       Period label.
     *
     * @return array<int, array>
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-response-rate-reflects-invitations-not-just-responses
     */
    private function fetchInvitations(string $courseId, string $academicYear, string $period): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::EVALUATION_INVITATION_SCHEMA,
                'filters'  => [
                    'courseId'     => $courseId,
                    'academicYear' => $academicYear,
                    'period'       => $period,
                ],
            ]
        );

        return $this->normaliseResults(results: $results);

    }//end fetchInvitations()

    /**
     * Normalise a findAll() result set to plain arrays.
     *
     * @param array $results Raw findAll() results (objects or arrays).
     *
     * @return array<int, array>
     */
    private function normaliseResults(array $results): array
    {
        if (empty($results) === true) {
            return [];
        }

        return array_map(
            static function ($item) {
                if (is_array($item) === true) {
                    return $item;
                }

                return $item->jsonSerialize();
            },
            $results
        );

    }//end normaliseResults()

    /**
     * Mean of overallScore across the response set, ignoring null values —
     * a response with no overallScore does not count toward the denominator
     * and does not skew the average toward zero.
     *
     * @param array<int, array> $responses Submitted CourseEvaluationResponses.
     *
     * @return float|null Null when no response carries a non-null overallScore.
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    private function averageOverallScore(array $responses): ?float
    {
        $sum   = 0.0;
        $count = 0;

        foreach ($responses as $response) {
            $score = $response['overallScore'] ?? null;
            if ($score === null) {
                continue;
            }

            $sum += (float) $score;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return round($sum / $count, 4);

    }//end averageOverallScore()

    /**
     * ResponseCount / invitationCount, 0 when invitationCount is 0 (never a
     * divide-by-zero error).
     *
     * @param int $responseCount   Count of submitted responses.
     * @param int $invitationCount Count of provisioned invitations.
     *
     * @return float
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-response-rate-reflects-invitations-not-just-responses
     */
    private function responseRate(int $responseCount, int $invitationCount): float
    {
        if ($invitationCount === 0) {
            return 0.0;
        }

        return round($responseCount / $invitationCount, 4);

    }//end responseRate()
}//end class
