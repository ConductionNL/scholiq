<?php

/**
 * Scholiq PointEngagementEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Engagement
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

namespace OCA\Scholiq\Tests\Unit\Engagement;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Engagement\PointEngagementEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PointEngagementEvaluator::evaluate().
 */
class PointEngagementEvaluatorTest extends TestCase
{

    /**
     * Build an evaluator with an ObjectService stub driven by the given
     * PointAward + EngagementLevel fixtures, and a fixed "now".
     *
     * @param array<int, array> $awards The learner's PointAward rows.
     * @param array<int, array> $levels The tenant's EngagementLevel rows.
     * @param DateTime           $now    The "now" the injected ITimeFactory reports.
     *
     * @return PointEngagementEvaluator
     */
    private function makeEvaluator(array $awards, array $levels, DateTime $now): PointEngagementEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($awards, $levels) {
                if ($config['schema'] === 'point-award') {
                    return $awards;
                }

                if ($config['schema'] === 'engagement-level') {
                    return $levels;
                }

                return [];
            }
        );

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new PointEngagementEvaluator($objectService, $timeFactory);

    }//end makeEvaluator()

    /**
     * Points across multiple awards sum correctly.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
     */
    public function testTotalsSumCorrectly(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 10, 'awardedAt' => '2026-07-13T10:00:00+00:00'],
            ['points' => 5, 'awardedAt' => '2026-07-14T10:00:00+00:00'],
            ['points' => 7.5, 'awardedAt' => '2026-07-15T09:00:00+00:00'],
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: [], now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(22.5, $result['totalPoints']);

    }//end testTotalsSumCorrectly()

    /**
     * levelId resolves to the highest EngagementLevel whose minPoints is at
     * most totalPoints, including exact-threshold equality.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
     */
    public function testLevelResolutionAtExactThreshold(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 20, 'awardedAt' => '2026-07-15T09:00:00+00:00'],
        ];

        $levels = [
            ['id' => 'level-bronze', 'name' => 'Bronze', 'minPoints' => 0],
            ['id' => 'level-silver', 'name' => 'Silver', 'minPoints' => 20],
            ['id' => 'level-gold', 'name' => 'Gold', 'minPoints' => 50],
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: $levels, now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(20.0, $result['totalPoints']);
        self::assertSame('level-silver', $result['levelId']);

    }//end testLevelResolutionAtExactThreshold()

    /**
     * A learner below every level's minPoints resolves levelId to null.
     *
     * @return void
     */
    public function testLevelResolutionBelowLowestThresholdIsNull(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 5, 'awardedAt' => '2026-07-15T09:00:00+00:00'],
        ];

        $levels = [
            ['id' => 'level-bronze', 'name' => 'Bronze', 'minPoints' => 10],
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: $levels, now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertNull($result['levelId']);

    }//end testLevelResolutionBelowLowestThresholdIsNull()

    /**
     * A streak of consecutive days ending TODAY is counted in full.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
     */
    public function testStreakEndingTodayIsCountedInFull(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 1, 'awardedAt' => '2026-07-13T09:00:00+00:00'],
            ['points' => 1, 'awardedAt' => '2026-07-14T09:00:00+00:00'],
            ['points' => 1, 'awardedAt' => '2026-07-15T09:00:00+00:00'],
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: [], now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(3, $result['currentStreakDays']);
        self::assertSame(3, $result['longestStreakDays']);
        self::assertSame('2026-07-15', $result['lastActivityDate']);

    }//end testStreakEndingTodayIsCountedInFull()

    /**
     * A streak whose last activity was YESTERDAY (nothing yet today) stays
     * alive through the current day rather than resetting.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
     */
    public function testStreakContinuesAcrossYesterdayBoundary(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 1, 'awardedAt' => '2026-07-13T09:00:00+00:00'],
            ['points' => 1, 'awardedAt' => '2026-07-14T09:00:00+00:00'],
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: [], now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(2, $result['currentStreakDays']);

    }//end testStreakContinuesAcrossYesterdayBoundary()

    /**
     * A gap of more than one day resets currentStreakDays to 0, while
     * longestStreakDays still reflects the historical run.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
     */
    public function testStreakResetsAcrossAGap(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $awards = [
            ['points' => 1, 'awardedAt' => '2026-07-01T09:00:00+00:00'],
            ['points' => 1, 'awardedAt' => '2026-07-02T09:00:00+00:00'],
            ['points' => 1, 'awardedAt' => '2026-07-03T09:00:00+00:00'],
            // Gap: no activity 07-04..07-14.
        ];

        $evaluator = $this->makeEvaluator(awards: $awards, levels: [], now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(0, $result['currentStreakDays']);
        self::assertSame(3, $result['longestStreakDays']);

    }//end testStreakResetsAcrossAGap()

    /**
     * Empty learnerId short-circuits to an empty result without querying.
     *
     * @return void
     */
    public function testEmptyLearnerIdReturnsEmptyResultWithoutQuerying(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $timeFactory = $this->createMock(ITimeFactory::class);

        $evaluator = new PointEngagementEvaluator($objectService, $timeFactory);

        $result = $evaluator->evaluate(learnerId: '', tenantId: 'tenant-a');

        self::assertSame(0.0, $result['totalPoints']);
        self::assertNull($result['levelId']);
        self::assertSame(0, $result['currentStreakDays']);
        self::assertSame(0, $result['longestStreakDays']);
        self::assertNull($result['lastActivityDate']);

    }//end testEmptyLearnerIdReturnsEmptyResultWithoutQuerying()

    /**
     * A learner with zero PointAwards still resolves a level whose minPoints
     * is 0 -- levelId resolution is not short-circuited to null just because
     * no award exists yet.
     *
     * @return void
     */
    public function testZeroAwardsStillResolvesAZeroThresholdLevel(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $levels = [
            ['id' => 'level-starter', 'name' => 'Starter', 'minPoints' => 0],
        ];

        $evaluator = $this->makeEvaluator(awards: [], levels: $levels, now: $now);

        $result = $evaluator->evaluate(learnerId: 'learner-1', tenantId: 'tenant-a');

        self::assertSame(0.0, $result['totalPoints']);
        self::assertSame('level-starter', $result['levelId']);

    }//end testZeroAwardsStillResolvesAZeroThresholdLevel()
}//end class
