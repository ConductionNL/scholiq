<?php

/**
 * Scholiq Point Engagement Evaluator
 *
 * Stateless calculation engine that sums a learner's PointAward.points,
 * resolves the highest EngagementLevel the learner has reached, and computes
 * their current/longest activity streak from distinct PointAward.awardedAt
 * calendar dates.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * A grep of every "metric": value across lib/Settings/scholiq_register.json
 * returns only count and count_distinct -- never sum, anywhere in this
 * register. FinalGrade.value (GradeFormulaEvaluator) and BsaTrajectory's
 * ectsEarned (BsaProgressEvaluator) both hit this exact limitation and both
 * resolved it the same way: a constructor-injected PHP evaluator. This class
 * follows the identical, precedented path for LearnerEngagement.totalPoints.
 *
 * Unlike GradeFormulaEvaluator/BsaProgressEvaluator, this evaluator also
 * needs an injectable "now" to determine whether a streak is still alive
 * (ending today or yesterday) -- ITimeFactory is constructor-injected for
 * the same deterministic-testing reason BsaProgressFlagHandler injects it.
 * longestStreakDays is computed by scanning the learner's FULL PointAward
 * date history for the longest run of consecutive calendar days anywhere in
 * it, not by comparing against a previously-stored value -- because
 * PointAward is appendOnly (rows are never deleted or edited), this is
 * equivalent to max(existing longestStreakDays, currentStreakDays) while
 * keeping the evaluator a pure function of its inputs (no state, no reads of
 * the LearnerEngagement row it is about to overwrite).
 *
 * Consumed by:
 *   - LearnerEngagementRollupHandler (via ObjectCreatedEvent on PointAward)
 *
 * @category Engagement
 * @package  OCA\Scholiq\Engagement
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Scholiq\Engagement;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Sums a learner's PointAward.points, resolves their EngagementLevel, and
 * computes their activity streak.
 *
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */
class PointEngagementEvaluator
{

    private const SCHOLIQ_REGISTER        = 'scholiq';
    private const POINT_AWARD_SCHEMA      = 'point-award';
    private const ENGAGEMENT_LEVEL_SCHEMA = 'engagement-level';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object access.
     * @param ITimeFactory  $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Compute a learner's totalPoints, levelId, and streak days.
     *
     * @param string $learnerId Nextcloud user ID of the learner.
     * @param string $tenantId  Tenant identifier the EngagementLevel rows are scoped to.
     *
     * @return array{totalPoints: float, levelId: ?string, currentStreakDays: int, longestStreakDays: int, lastActivityDate: ?string}
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
     */
    public function evaluate(string $learnerId, string $tenantId): array
    {
        if ($learnerId === '') {
            return $this->emptyResult();
        }

        $awards = $this->fetchAwards(learnerId: $learnerId);

        if (empty($awards) === true) {
            $result            = $this->emptyResult();
            $result['levelId'] = $this->resolveLevelId(totalPoints: 0.0, tenantId: $tenantId);
            return $result;
        }

        $totalPoints = 0.0;
        $dates       = [];
        foreach ($awards as $award) {
            $totalPoints += (float) ($award['points'] ?? 0);

            $awardedAt = $award['awardedAt'] ?? null;
            if (is_string($awardedAt) === true && $awardedAt !== '') {
                try {
                    $date         = (new DateTimeImmutable($awardedAt))->format('Y-m-d');
                    $dates[$date] = true;
                } catch (\Exception) {
                    continue;
                }
            }
        }

        $distinctDates = array_keys($dates);
        sort($distinctDates);

        $levelId           = $this->resolveLevelId(totalPoints: $totalPoints, tenantId: $tenantId);
        $currentStreakDays = $this->computeCurrentStreak(distinctDates: $distinctDates);
        $longestStreakDays = $this->computeLongestStreak(distinctDates: $distinctDates);
        $lastActivityDate  = null;
        if (empty($distinctDates) === false) {
            $lastActivityDate = $distinctDates[count($distinctDates) - 1];
        }

        return [
            'totalPoints'       => $totalPoints,
            'levelId'           => $levelId,
            'currentStreakDays' => $currentStreakDays,
            'longestStreakDays' => $longestStreakDays,
            'lastActivityDate'  => $lastActivityDate,
        ];

    }//end evaluate()

    /**
     * Fetch every PointAward for the learner.
     *
     * @param string $learnerId NC user ID.
     *
     * @return array<int, array>
     */
    private function fetchAwards(string $learnerId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::POINT_AWARD_SCHEMA,
                'filters'  => ['learnerId' => $learnerId],
            ]
        );

        return array_map(
            static function ($award) {
                if (is_array($award) === true) {
                    return $award;
                }

                return $award->jsonSerialize();
            },
            $results
        );

    }//end fetchAwards()

    /**
     * Resolve the highest EngagementLevel whose minPoints is at most totalPoints.
     *
     * @param float  $totalPoints The learner's summed points.
     * @param string $tenantId    Tenant identifier.
     *
     * @return string|null
     */
    private function resolveLevelId(float $totalPoints, string $tenantId): ?string
    {
        $levels = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENGAGEMENT_LEVEL_SCHEMA,
                'filters'  => ['tenant_id' => $tenantId],
            ]
        );

        $bestLevelId   = null;
        $bestMinPoints = null;
        foreach ($levels as $level) {
            if (is_array($level) === false) {
                $level = $level->jsonSerialize();
            }

            $minPoints = $level['minPoints'] ?? null;
            if ($minPoints === null || (float) $minPoints > $totalPoints) {
                continue;
            }

            if ($bestMinPoints === null || (float) $minPoints > $bestMinPoints) {
                $bestMinPoints = (float) $minPoints;
                $bestLevelId   = $level['id'] ?? ($level['uuid'] ?? null);
            }
        }

        return $bestLevelId;

    }//end resolveLevelId()

    /**
     * Count consecutive calendar days, ending today or yesterday, with at
     * least one PointAward.
     *
     * A learner who was active yesterday but has not yet acted today keeps
     * their streak alive through the day (the streak only breaks once a full
     * day passes with no activity).
     *
     * @param array<int, string> $distinctDates Sorted ascending 'Y-m-d' dates with >=1 award.
     *
     * @return int
     */
    private function computeCurrentStreak(array $distinctDates): int
    {
        if (empty($distinctDates) === true) {
            return 0;
        }

        $today     = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format('Y-m-d');
        $yesterday = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->modify('-1 day')->format('Y-m-d');

        $dateSet = array_flip($distinctDates);

        $anchor = null;
        if (isset($dateSet[$today]) === true) {
            $anchor = $today;
        } else if (isset($dateSet[$yesterday]) === true) {
            $anchor = $yesterday;
        }

        if ($anchor === null) {
            // No activity today or yesterday -- the streak has broken.
            return 0;
        }

        $count  = 0;
        $cursor = new DateTimeImmutable($anchor);
        while (isset($dateSet[$cursor->format('Y-m-d')]) === true) {
            $count++;
            $cursor = $cursor->modify('-1 day');
        }

        return $count;

    }//end computeCurrentStreak()

    /**
     * Find the longest run of consecutive calendar days anywhere in the
     * learner's full award history.
     *
     * @param array<int, string> $distinctDates Sorted ascending 'Y-m-d' dates with >=1 award.
     *
     * @return int
     */
    private function computeLongestStreak(array $distinctDates): int
    {
        if (empty($distinctDates) === true) {
            return 0;
        }

        $longest   = 1;
        $running   = 1;
        $previous  = new DateTimeImmutable($distinctDates[0]);
        $dateCount = count($distinctDates);

        for ($i = 1; $i < $dateCount; $i++) {
            $current      = new DateTimeImmutable($distinctDates[$i]);
            $expectedNext = $previous->modify('+1 day')->format('Y-m-d');

            $running = match ($current->format('Y-m-d') === $expectedNext) {
                true    => $running + 1,
                default => 1,
            };

            $longest  = max($longest, $running);
            $previous = $current;
        }

        return $longest;

    }//end computeLongestStreak()

    /**
     * Return an empty result (no PointAwards yet).
     *
     * @return array{totalPoints: float, levelId: null, currentStreakDays: int, longestStreakDays: int, lastActivityDate: null}
     */
    private function emptyResult(): array
    {
        return [
            'totalPoints'       => 0.0,
            'levelId'           => null,
            'currentStreakDays' => 0,
            'longestStreakDays' => 0,
            'lastActivityDate'  => null,
        ];

    }//end emptyResult()
}//end class
