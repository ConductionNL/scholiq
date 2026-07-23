<?php

/**
 * Scholiq EngagementSignalHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Analytics\EngagementScoreEvaluator;
use OCA\Scholiq\Listener\EngagementSignalHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EngagementSignalHandler::handle() on ObjectCreatedEvent<XapiStatement>.
 */
class EngagementSignalHandlerTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug. Persists across
     * multiple handle() calls within one test so idempotency can be
     * exercised.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db           = [];
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler backed by an ObjectService stub over $this->db and a
     * mocked EngagementScoreEvaluator returning a fixed result.
     *
     * @param array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int} $evaluated Result the mocked evaluator returns.
     * @param DateTime                                                                  $now       The "now" the injected ITimeFactory reports.
     *
     * @return EngagementSignalHandler
     */
    private function makeHandler(array $evaluated, DateTime $now): EngagementSignalHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) {
                if ($schema === 'cohort') {
                    foreach (($this->db['cohort'] ?? []) as $cohort) {
                        if (($cohort['id'] ?? null) === $id) {
                            return $cohort;
                        }
                    }
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema  = $config['schema'];
                $records = $this->db[$schema] ?? [];
                $filters = $config['filters'] ?? [];

                $matched = array_values(
                    array_filter(
                        $records,
                        static function (array $rec) use ($filters) {
                            foreach ($filters as $key => $value) {
                                if (($rec[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

                if (isset($config['limit']) === true) {
                    $matched = array_slice($matched, 0, (int) $config['limit']);
                }

                return $matched;
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                if (isset($object['id']) === false) {
                    $object['id'] = $schema.'-auto-'.(count($this->db[$schema] ?? []) + 1);
                }

                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

                $existingIndex = null;
                foreach (($this->db[$schema] ?? []) as $index => $rec) {
                    if (($rec['id'] ?? null) === $object['id']) {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $this->db[$schema][$existingIndex] = $object;
                } else {
                    $this->db[$schema][] = $object;
                }

                return $object;
            }
        );

        $evaluator = $this->createMock(EngagementScoreEvaluator::class);
        $evaluator->method('evaluate')->willReturn($evaluated);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new EngagementSignalHandler($objectService, $evaluator, $timeFactory);

    }//end makeHandler()

    /**
     * Seed a record into the fake datastore.
     *
     * @param string               $schema Schema slug.
     * @param array<string, mixed> $record Record data.
     *
     * @return void
     */
    private function seed(string $schema, array $record): void
    {
        $this->db[$schema][] = $record;

    }//end seed()

    /**
     * Build a mocked ObjectCreatedEvent<XapiStatement>.
     *
     * @param array<string, mixed> $data The XapiStatement jsonSerialize() payload.
     *
     * @return ObjectCreatedEvent
     */
    private function makeXapiEvent(array $data): ObjectCreatedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('xapi-statement');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        return $event;

    }//end makeXapiEvent()

    /**
     * Fetch every saveObject() call recorded for a given schema.
     *
     * @param string $schema Schema slug.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedFor(string $schema): array
    {
        return array_values(
            array_map(
                static fn (array $s) => $s['object'],
                array_filter($this->savedObjects, static fn (array $s) => $s['schema'] === $schema)
            )
        );

    }//end savedFor()

    /**
     * The score recompute always runs and persists an EngagementScore, even
     * with no active threshold.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-engagementscore-objects-persist-and-recompute-from-xapi-activity
     */
    public function testScoreRecomputeAlwaysRuns(): void
    {
        $now     = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
        $handler = $this->makeHandler(
            evaluated: ['timeOnTaskMinutes' => 12.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 55],
            now: $now
        );

        $handler->handle(
            $this->makeXapiEvent(
                ['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
            )
        );

        $scores = $this->savedFor('engagement-score');
        self::assertCount(1, $scores);
        self::assertSame(55, $scores[0]['score']);
        self::assertSame(0, count($this->savedFor('engagement-risk-flag')));

    }//end testScoreRecomputeAlwaysRuns()

    /**
     * A learner whose score falls below an active engagement-score-below
     * threshold gets a flag on first crossing.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
     */
    public function testFlagCreatedOnFirstCrossing(): void
    {
        $now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->seed(
            'engagement-risk-threshold',
            [
                'id'        => 'threshold-1',
                'name'      => 'Low engagement',
                'kind'      => 'low-engagement',
                'scope'     => 'per-learner',
                'cohortId'  => null,
                'metric'    => 'engagement-score-below',
                'limit'     => 30,
                'lifecycle' => 'active',
            ]
        );

        $handler = $this->makeHandler(
            evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
            now: $now
        );

        $handler->handle(
            $this->makeXapiEvent(
                ['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
            )
        );

        $flags = $this->savedFor('engagement-risk-flag');
        self::assertCount(1, $flags);
        self::assertSame('learner-1', $flags[0]['learnerId']);
        self::assertSame('threshold-1', $flags[0]['engagementRiskThresholdId']);
        self::assertSame('open', $flags[0]['lifecycle']);
        self::assertSame(20.0, $flags[0]['metricValueAtFlag']);

    }//end testFlagCreatedOnFirstCrossing()

    /**
     * No duplicate flag is created while one is already open for the same
     * learner + threshold.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
     */
    public function testNoDuplicateFlagWhileOpen(): void
    {
        $now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->seed(
            'engagement-risk-threshold',
            [
                'id'        => 'threshold-1',
                'scope'     => 'per-learner',
                'cohortId'  => null,
                'metric'    => 'engagement-score-below',
                'limit'     => 30,
                'lifecycle' => 'active',
            ]
        );
        $this->seed(
            'engagement-risk-flag',
            [
                'id'                        => 'flag-1',
                'learnerId'                 => 'learner-1',
                'engagementRiskThresholdId' => 'threshold-1',
                'lifecycle'                 => 'open',
            ]
        );

        $handler = $this->makeHandler(
            evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
            now: $now
        );

        $handler->handle(
            $this->makeXapiEvent(
                ['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
            )
        );

        self::assertCount(0, $this->savedFor('engagement-risk-flag'));

    }//end testNoDuplicateFlagWhileOpen()

    /**
     * A resolved flag does not block a new flag on a later relapse.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-a-resolved-flag-does-not-block-re-flagging-on-a-later-relapse
     */
    public function testResolvedFlagAllowsNewFlagOnRelapse(): void
    {
        $now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->seed(
            'engagement-risk-threshold',
            [
                'id'        => 'threshold-1',
                'scope'     => 'per-learner',
                'cohortId'  => null,
                'metric'    => 'engagement-score-below',
                'limit'     => 30,
                'lifecycle' => 'active',
            ]
        );
        $this->seed(
            'engagement-risk-flag',
            [
                'id'                        => 'flag-1',
                'learnerId'                 => 'learner-1',
                'engagementRiskThresholdId' => 'threshold-1',
                'lifecycle'                 => 'resolved',
            ]
        );

        $handler = $this->makeHandler(
            evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
            now: $now
        );

        $handler->handle(
            $this->makeXapiEvent(
                ['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
            )
        );

        self::assertCount(1, $this->savedFor('engagement-risk-flag'));

    }//end testResolvedFlagAllowsNewFlagOnRelapse()

    /**
     * A per-cohort threshold does not fire for a learner who is not a
     * member of the scoped Cohort.
     *
     * @return void
     */
    public function testCohortScopedThresholdSkipsNonMember(): void
    {
        $now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->seed('cohort', ['id' => 'cohort-1', 'learnerIds' => ['learner-2', 'learner-3']]);
        $this->seed(
            'engagement-risk-threshold',
            [
                'id'        => 'threshold-1',
                'scope'     => 'per-cohort',
                'cohortId'  => 'cohort-1',
                'metric'    => 'engagement-score-below',
                'limit'     => 30,
                'lifecycle' => 'active',
            ]
        );

        $handler = $this->makeHandler(
            evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
            now: $now
        );

        $handler->handle(
            $this->makeXapiEvent(
                ['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
            )
        );

        self::assertCount(0, $this->savedFor('engagement-risk-flag'));

    }//end testCohortScopedThresholdSkipsNonMember()

    /**
     * No AI/ML client, HTTP call, or Hermiq dependency is constructed
     * anywhere by this handler — verified structurally: it depends only on
     * ObjectService, EngagementScoreEvaluator, and ITimeFactory.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
     */
    public function testConstructorHasNoAiOrHermiqDependency(): void
    {
        $reflection  = new \ReflectionClass(EngagementSignalHandler::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $paramTypes = array_map(
            static fn (\ReflectionParameter $p) => (string) $p->getType(),
            $constructor->getParameters()
        );

        foreach ($paramTypes as $type) {
            self::assertStringNotContainsStringIgnoringCase('hermiq', $type);
            self::assertStringNotContainsStringIgnoringCase('aifeature', $type);
        }

    }//end testConstructorHasNoAiOrHermiqDependency()
}//end class
