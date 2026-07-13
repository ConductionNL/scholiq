<?php

/**
 * Scholiq Engagement Score Evaluator
 *
 * Stateless calculation engine computing a learner's per-Course engagement
 * signals from their XapiStatement activity: timeOnTaskMinutes (sum of every
 * matching statement's result.duration), lastActivityAt (max statement
 * timestamp), and a bounded 0-100 score combining completion-ratio-of-course
 * time-on-task with a recency-gap decay factor.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * Summing nested XapiStatement.result duration data and resolving the
 * Course's total Lesson.durationMinutes is a cross-schema, iterative
 * computation the pure JSON-logic DSL cannot express — the same rationale
 * already accepted for GradeFormulaEvaluator/EnrolmentProgressEvaluator.
 * Single responsibility: evaluate -> return; no state, no audit writes.
 *
 * Score formula (plain weighted arithmetic, NOT a model / no AI-Act surface):
 *   completionRatio = min(1, timeOnTaskMinutes / totalPublishedLessonDurationMinutes)
 *                      (0 when the course declares no Lesson.durationMinutes at all)
 *   recencyFactor    = 1 - min(1, gapDays / RECENCY_DECAY_WINDOW_DAYS)
 *                      where gapDays is the number of days between the
 *                      learner's PREVIOUS lastActivityAt (if any) and the
 *                      newly observed max statement timestamp — a large gap
 *                      between sessions lowers the recency component even
 *                      though the statement itself is fresh; a first-ever
 *                      statement has no gap to measure and defaults to 1.
 *   score            = round(completionRatio * 70 + recencyFactor * 30),
 *                      clamped to [0, 100]
 *
 * Consumed by:
 *   - EngagementSignalHandler (via ObjectCreatedEvent<XapiStatement>)
 *
 * @category Analytics
 * @package  OCA\Scholiq\Analytics
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Analytics;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes timeOnTaskMinutes, lastActivityAt, and a bounded 0-100 score for
 * a learner + course from their XapiStatement activity.
 */
class EngagementScoreEvaluator
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const XAPI_SCHEMA      = 'xapi-statement';
    private const LESSON_SCHEMA    = 'lesson';

    /**
     * Recency-gap decay window, in days. A gap of 0 days yields a full 1.0
     * recency factor; a gap at or beyond this window yields 0.
     */
    private const RECENCY_DECAY_WINDOW_DAYS = 14;

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
     * Evaluate a learner's engagement signals for a course.
     *
     * @param string      $learnerId              NC user ID of the learner.
     * @param string      $courseId               UUID of the Course.
     * @param string|null $previousLastActivityAt The EngagementScore's prior
     *                                            lastActivityAt (ISO-8601),
     *                                            if any — used only for
     *                                            the recency-gap decay
     *                                            factor.
     *
     * @return array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int}
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
     */
    public function evaluate(string $learnerId, string $courseId, ?string $previousLastActivityAt=null): array
    {
        $statements = $this->fetchStatements(learnerId: $learnerId, courseId: $courseId);

        $timeOnTaskMinutes = 0.0;
        $lastActivityAt    = null;

        foreach ($statements as $statement) {
            $timeOnTaskMinutes += $this->parseDurationMinutes(iso8601Duration: $statement['result']['duration'] ?? null);

            $timestamp = $statement['timestamp'] ?? null;
            if (is_string($timestamp) === true && $timestamp !== ''
                && ($lastActivityAt === null || $timestamp > $lastActivityAt)
            ) {
                $lastActivityAt = $timestamp;
            }
        }

        $totalPublishedLessonDurationMinutes = $this->sumPublishedLessonDuration(courseId: $courseId);

        $completionRatio = 0.0;
        if ($totalPublishedLessonDurationMinutes > 0.0) {
            $completionRatio = min(1.0, $timeOnTaskMinutes / $totalPublishedLessonDurationMinutes);
        }

        $recencyFactor = $this->recencyFactor(
            previousLastActivityAt: $previousLastActivityAt,
            newLastActivityAt: $lastActivityAt
        );

        $score = (int) round(($completionRatio * 70) + ($recencyFactor * 30));
        $score = max(0, min(100, $score));

        return [
            'timeOnTaskMinutes' => round($timeOnTaskMinutes, 2),
            'lastActivityAt'    => $lastActivityAt,
            'score'             => $score,
        ];

    }//end evaluate()

    /**
     * Fetch every XapiStatement for this learner + course.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchStatements(string $learnerId, string $courseId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::XAPI_SCHEMA,
                'filters'  => [
                    'verified_actor_id' => $learnerId,
                    'courseId'          => $courseId,
                ],
            ]
        );

        return array_map(
            static function ($statement) {
                if (is_array($statement) === true) {
                    return $statement;
                }

                return $statement->jsonSerialize();
            },
            $results
        );

    }//end fetchStatements()

    /**
     * Parse an xAPI ISO-8601 duration string (result.duration) into minutes.
     * A missing or unparsable duration contributes 0, never an error.
     *
     * @param string|null $iso8601Duration e.g. "PT1H30M".
     *
     * @return float
     */
    private function parseDurationMinutes(?string $iso8601Duration): float
    {
        if (is_string($iso8601Duration) === false || $iso8601Duration === '') {
            return 0.0;
        }

        try {
            $interval = new \DateInterval($iso8601Duration);
        } catch (\Exception) {
            return 0.0;
        }

        $days    = ((float) $interval->y * 365) + ((float) $interval->m * 30) + (float) $interval->d;
        $minutes = ($days * 24 * 60) + ((float) $interval->h * 60) + (float) $interval->i + ((float) $interval->s / 60);

        return $minutes;

    }//end parseDurationMinutes()

    /**
     * Sum durationMinutes across a course's published Lessons. A Lesson with
     * no durationMinutes contributes 0.
     *
     * @param string $courseId UUID of the Course.
     *
     * @return float
     */
    private function sumPublishedLessonDuration(string $courseId): float
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

        $total = 0.0;
        foreach ($results as $lesson) {
            if (is_array($lesson) === false) {
                $lesson = $lesson->jsonSerialize();
            }

            $total += (float) ($lesson['durationMinutes'] ?? 0);
        }

        return $total;

    }//end sumPublishedLessonDuration()

    /**
     * Compute the recency-gap decay factor between the prior lastActivityAt
     * and the newly observed one. No activity at all yields 0 (nothing to
     * be "recent" about). No PRIOR activity but a new statement just arrived
     * (first-ever statement for this learner+course) yields a full 1.0
     * factor — there is no gap to measure yet, and the learner is engaging
     * right now.
     *
     * @param string|null $previousLastActivityAt Prior lastActivityAt, ISO-8601.
     * @param string|null $newLastActivityAt      Newly observed max timestamp, ISO-8601.
     *
     * @return float Between 0.0 and 1.0.
     */
    private function recencyFactor(?string $previousLastActivityAt, ?string $newLastActivityAt): float
    {
        if ($newLastActivityAt === null) {
            // No activity has ever been observed — nothing to be recent about.
            return 0.0;
        }

        if ($previousLastActivityAt === null) {
            // First-ever statement for this learner+course — no gap to measure.
            return 1.0;
        }

        try {
            $previous = new DateTimeImmutable($previousLastActivityAt);
            $new      = new DateTimeImmutable($newLastActivityAt);
        } catch (\Exception) {
            return 1.0;
        }

        $gapDays = ($new->getTimestamp() - $previous->getTimestamp()) / 86400;
        if ($gapDays <= 0) {
            return 1.0;
        }

        return max(0.0, 1.0 - min(1.0, $gapDays / self::RECENCY_DECAY_WINDOW_DAYS));

    }//end recencyFactor()
}//end class
