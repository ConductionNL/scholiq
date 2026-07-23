<?php

/**
 * Scholiq LearnerEngagementRollupHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Engagement\PointEngagementEvaluator;
use OCA\Scholiq\Listener\LearnerEngagementRollupHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearnerEngagementRollupHandler::handle().
 */
class LearnerEngagementRollupHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Existing LearnerEngagement row to return from findAll(), or null.
     *
     * @var array<string,mixed>|null
     */
    private ?array $existingEngagement = null;

    /**
     * Active streak-milestone PointRule rows to return from findAll().
     *
     * @var array<int, array>
     */
    private array $streakRules = [];

    /**
     * Evaluator result to return.
     *
     * @var array<string,mixed>
     */
    private array $evaluatorResult = [
        'totalPoints'       => 0.0,
        'levelId'           => null,
        'currentStreakDays' => 0,
        'longestStreakDays' => 0,
        'lastActivityDate'  => null,
    ];

    /**
     * Reset the capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects       = [];
        $this->existingEngagement = null;
        $this->streakRules        = [];
        $this->evaluatorResult    = [
            'totalPoints'       => 0.0,
            'levelId'           => null,
            'currentStreakDays' => 0,
            'longestStreakDays' => 0,
            'lastActivityDate'  => null,
        ];

    }//end setUp()

    /**
     * Build a handler with mocked collaborators.
     *
     * @param DateTime $now The "now" the injected ITimeFactory reports.
     *
     * @return LearnerEngagementRollupHandler
     */
    private function makeHandler(DateTime $now): LearnerEngagementRollupHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if ($config['schema'] === 'learner-engagement') {
                    return $this->existingEngagement === null ? [] : [$this->existingEngagement];
                }

                if ($config['schema'] === 'point-rule') {
                    return $this->streakRules;
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        $evaluator = $this->createMock(PointEngagementEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(fn () => $this->evaluatorResult);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new LearnerEngagementRollupHandler($objectService, $evaluator, $timeFactory);

    }//end makeHandler()

    /**
     * Build a mocked ObjectCreatedEvent for a PointAward.
     *
     * @param array<string, mixed> $data The PointAward's jsonSerialize() payload.
     *
     * @return ObjectCreatedEvent
     */
    private function makeEvent(array $data): ObjectCreatedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('point-award');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        return $event;

    }//end makeEvent()

    /**
     * Filter savedObjects to those matching a schema.
     *
     * @param string $schema Schema slug.
     *
     * @return array<int, array>
     */
    private function savesFor(string $schema): array
    {
        return array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === $schema));

    }//end savesFor()

    /**
     * A new PointAward recomputes and saves LearnerEngagement totals/level/streak.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
     */
    public function testNewPointAwardRecomputesLearnerEngagement(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->evaluatorResult = [
            'totalPoints'       => 25.0,
            'levelId'           => 'level-silver',
            'currentStreakDays' => 2,
            'longestStreakDays' => 2,
            'lastActivityDate'  => '2026-07-15',
        ];

        $handler = $this->makeHandler(now: $now);

        $award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'enrolment'];
        $handler->handle($this->makeEvent($award));

        $saves = $this->savesFor('learner-engagement');
        self::assertCount(1, $saves);
        self::assertSame(25.0, $saves[0]['object']['totalPoints']);
        self::assertSame('level-silver', $saves[0]['object']['levelId']);
        self::assertSame(2, $saves[0]['object']['currentStreakDays']);

    }//end testNewPointAwardRecomputesLearnerEngagement()

    /**
     * A streak crossing from 6 to 7 awards exactly one bonus PointAward for
     * an active streak-milestone rule with milestoneDays:7.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
     */
    public function testStreakCrossingAwardsBonusExactlyOnce(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 6];
        $this->evaluatorResult    = [
            'totalPoints'       => 30.0,
            'levelId'           => null,
            'currentStreakDays' => 7,
            'longestStreakDays' => 7,
            'lastActivityDate'  => '2026-07-15',
        ];
        $this->streakRules = [
            ['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
        ];

        $handler = $this->makeHandler(now: $now);

        $award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'submission'];
        $handler->handle($this->makeEvent($award));

        $bonusSaves = $this->savesFor('point-award');
        self::assertCount(1, $bonusSaves);
        self::assertSame('streak-milestone', $bonusSaves[0]['object']['sourceKind']);
        self::assertNull($bonusSaves[0]['object']['sourceObjectId']);
        self::assertSame('rule-streak-7', $bonusSaves[0]['object']['pointRuleId']);
        self::assertSame(20.0, $bonusSaves[0]['object']['points']);

    }//end testStreakCrossingAwardsBonusExactlyOnce()

    /**
     * The bonus award's own rollup (sourceKind: streak-milestone) does not
     * re-trigger a further streak-milestone check -- the recursion guard.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
     */
    public function testBonusAwardRollupDoesNotReTriggerMilestoneCheck(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 7];
        $this->evaluatorResult    = [
            'totalPoints'       => 50.0,
            'levelId'           => null,
            'currentStreakDays' => 7,
            'longestStreakDays' => 7,
            'lastActivityDate'  => '2026-07-15',
        ];
        $this->streakRules = [
            ['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
        ];

        $handler = $this->makeHandler(now: $now);

        // This event's OWN sourceKind is streak-milestone -- the recursion guard.
        $award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'streak-milestone'];
        $handler->handle($this->makeEvent($award));

        self::assertCount(0, $this->savesFor('point-award'));
        self::assertCount(1, $this->savesFor('learner-engagement'));

    }//end testBonusAwardRollupDoesNotReTriggerMilestoneCheck()

    /**
     * No forward streak progress (equal or lower) never awards a bonus.
     *
     * @return void
     */
    public function testNoForwardStreakProgressAwardsNoBonus(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 7];
        $this->evaluatorResult    = [
            'totalPoints'       => 10.0,
            'levelId'           => null,
            'currentStreakDays' => 7,
            'longestStreakDays' => 7,
            'lastActivityDate'  => '2026-07-15',
        ];
        $this->streakRules = [
            ['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
        ];

        $handler = $this->makeHandler(now: $now);

        $award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'grade-entry'];
        $handler->handle($this->makeEvent($award));

        self::assertCount(0, $this->savesFor('point-award'));

    }//end testNoForwardStreakProgressAwardsNoBonus()

    /**
     * An ObjectCreatedEvent on a different schema is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedSchemaIsIgnored(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'x']);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('enrolment');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $handler = $this->makeHandler(now: $now);
        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testUnrelatedSchemaIsIgnored()
}//end class
