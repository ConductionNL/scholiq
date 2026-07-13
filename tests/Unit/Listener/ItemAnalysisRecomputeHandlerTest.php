<?php

/**
 * Scholiq ItemAnalysisRecomputeHandler unit tests.
 *
 * Covers: the handler fires on AssessmentResult -> graded, upserts (not
 * duplicates) ItemStatistics/AssessmentReliability rows, creates a
 * deduplicated ItemRevisionFlag when a quality threshold is crossed, and
 * NEVER mutates the flagged Item. ItemAnalysisService is mocked here (its
 * own statistics arithmetic is covered by ItemAnalysisServiceTest) so this
 * suite isolates the event-to-object-write bridge behaviour, mirroring
 * GradeRollupHandlerTest's convention of mocking GradeFormulaEvaluator.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ItemAnalysisRecomputeHandler;
use OCA\Scholiq\Service\ItemAnalysisService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ItemAnalysisRecomputeHandler::handle() on AssessmentResult -> graded.
 */
class ItemAnalysisRecomputeHandlerTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug.
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
     * Build a handler backed by an ObjectService stub over $this->db and the
     * given (mocked) ItemAnalysisService.
     *
     * @param ItemAnalysisService $itemAnalysisService Stubbed statistics engine.
     * @param DateTime             $now                  The "now" the injected ITimeFactory reports.
     *
     * @return ItemAnalysisRecomputeHandler
     */
    private function makeHandler(ItemAnalysisService $itemAnalysisService, DateTime $now): ItemAnalysisRecomputeHandler
    {
        $objectService = $this->createMock(ObjectService::class);

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

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new ItemAnalysisRecomputeHandler($objectService, $itemAnalysisService, $timeFactory);

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
     * Seed an assessment-result in `graded` state referencing the given item ids.
     *
     * @param string        $id      Result id.
     * @param array<string> $itemIds Item ids referenced in responses.
     *
     * @return void
     */
    private function seedGradedResult(string $id, array $itemIds): void
    {
        $responses = [];
        foreach ($itemIds as $itemId) {
            $responses[] = ['itemId' => $itemId, 'response' => ['value' => 'x'], 'autoScore' => 1, 'manualScore' => null];
        }

        $this->seed(
            'assessment-result',
            [
                'id'           => $id,
                'assessmentId' => 'assessment-1',
                'tenant_id'    => 'tenant-a',
                'lifecycle'    => 'graded',
                'responses'    => $responses,
            ]
        );

    }//end seedGradedResult()

    /**
     * Build a mocked ObjectTransitionedEvent<AssessmentResult, graded>.
     *
     * @param array<string, mixed> $data The AssessmentResult jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeGradedEvent(array $data): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('assessment-result');
        $event->method('getTo')->willReturn('graded');

        return $event;

    }//end makeGradedEvent()

    /**
     * Build a stubbed ItemAnalysisService returning fixed statistics/config.
     *
     * @param array<string,array<string,mixed>> $statisticsByItemId computeItemStatistics() results, keyed by itemId.
     * @param array<string,mixed>                $reliability         computeReliability() result.
     * @param array<string,mixed>                $config              resolveConfig() result.
     *
     * @return ItemAnalysisService
     */
    private function stubService(array $statisticsByItemId, array $reliability, array $config): ItemAnalysisService
    {
        $service = $this->createMock(ItemAnalysisService::class);

        $service->method('computeItemStatistics')->willReturnCallback(
            fn (string $itemId, string $assessmentId) => $statisticsByItemId[$itemId]
        );

        $service->method('computeReliability')->willReturn($reliability);
        $service->method('resolveConfig')->willReturn($config);

        return $service;

    }//end stubService()

    /**
     * Default threshold config: tooDifficultyBelow 0.20, tooEasyAbove 0.95, lowDiscriminationBelow 0.10.
     *
     * @return array<string,float|int>
     */
    private function defaultConfig(): array
    {
        return [
            'minSampleSize'            => 20,
            'reliabilityMinSampleSize' => 30,
            'tooDifficultyBelow'       => 0.20,
            'tooEasyAbove'             => 0.95,
            'lowDiscriminationBelow'   => 0.10,
        ];

    }//end defaultConfig()

    /**
     * Recompute fires on the `graded` transition, upserting an ItemStatistics
     * row per referenced item and one AssessmentReliability row.
     *
     * @return void
     */
    public function testRecomputeFiresOnGradedTransitionAndUpsertsRows(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $this->seedGradedResult('result-1', ['item-1', 'item-2']);

        $statistics = [
            'item-1' => ['sampleSize' => 25, 'pValue' => 0.6, 'itemTotalCorrelation' => 0.5, 'distractorAnalysis' => null, 'insufficientData' => false, 'computedAt' => '2026-07-13T10:00:00+02:00'],
            'item-2' => ['sampleSize' => 5, 'pValue' => null, 'itemTotalCorrelation' => null, 'distractorAnalysis' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00'],
        ];
        $reliability = ['sampleSize' => 25, 'itemCount' => 2, 'cronbachAlpha' => 0.7, 'insufficientData' => false, 'computedAt' => '2026-07-13T10:00:00+02:00'];

        $handler = $this->makeHandler(
            $this->stubService($statistics, $reliability, $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $handler->handle($this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']));

        self::assertCount(2, $this->db['item-statistics'] ?? []);
        self::assertCount(1, $this->db['assessment-reliability'] ?? []);

        $byItemId = [];
        foreach ($this->db['item-statistics'] as $row) {
            $byItemId[$row['itemId']] = $row;
        }

        self::assertSame(0.6, $byItemId['item-1']['pValue']);
        self::assertFalse($byItemId['item-1']['insufficientData']);
        self::assertTrue($byItemId['item-2']['insufficientData']);

        self::assertSame(0.7, $this->db['assessment-reliability'][0]['cronbachAlpha']);

    }//end testRecomputeFiresOnGradedTransitionAndUpsertsRows()

    /**
     * A second `graded` event for the same (itemId, assessmentId) updates
     * the existing ItemStatistics row rather than duplicating it.
     *
     * @return void
     */
    public function testUpsertUpdatesExistingRowNotDuplicates(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $this->seed(
            'item-statistics',
            ['id' => 'stat-1', 'itemId' => 'item-1', 'assessmentId' => 'assessment-1', 'sampleSize' => 10, 'insufficientData' => true, 'tenant_id' => 'tenant-a']
        );
        $this->seedGradedResult('result-1', ['item-1']);

        $statistics  = ['item-1' => ['sampleSize' => 25, 'pValue' => 0.6, 'itemTotalCorrelation' => 0.5, 'distractorAnalysis' => null, 'insufficientData' => false, 'computedAt' => '2026-07-13T10:00:00+02:00']];
        $reliability = ['sampleSize' => 25, 'itemCount' => 1, 'cronbachAlpha' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00'];

        $handler = $this->makeHandler(
            $this->stubService($statistics, $reliability, $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $handler->handle($this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']));

        self::assertCount(1, $this->db['item-statistics']);
        self::assertSame('stat-1', $this->db['item-statistics'][0]['id']);
        self::assertSame(25, $this->db['item-statistics'][0]['sampleSize']);
        self::assertFalse($this->db['item-statistics'][0]['insufficientData']);

    }//end testUpsertUpdatesExistingRowNotDuplicates()

    /**
     * A too-difficult pValue (below the configured tooDifficultyBelow)
     * opens a deduplicated ItemRevisionFlag routed to examboard/admin
     * (recipients are declared on the schema, asserted separately), and
     * NEVER mutates the flagged Item — no saveObject call targets the
     * `item` schema.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-low-discrimination-item-opens-a-flag-for-the-exam-board-without-altering-the-item
     */
    public function testTooDifficultyOpensDedupedFlagWithoutMutatingItem(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $this->seed('item', ['id' => 'item-1', 'uuid' => 'item-1', 'tenant_id' => 'tenant-a', 'title' => 'Original title']);
        $this->seedGradedResult('result-1', ['item-1']);

        $statistics  = ['item-1' => ['sampleSize' => 40, 'pValue' => 0.10, 'itemTotalCorrelation' => 0.4, 'distractorAnalysis' => null, 'insufficientData' => false, 'computedAt' => '2026-07-13T10:00:00+02:00']];
        $reliability = ['sampleSize' => 40, 'itemCount' => 1, 'cronbachAlpha' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00'];

        $handler = $this->makeHandler(
            $this->stubService($statistics, $reliability, $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $event = $this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $handler->handle($event);

        self::assertCount(1, $this->db['item-revision-flag'] ?? []);
        $flag = $this->db['item-revision-flag'][0];
        self::assertSame('item-1', $flag['itemId']);
        self::assertSame('too-difficult', $flag['reason']);
        self::assertSame('open', $flag['lifecycle']);
        self::assertNotNull($flag['itemStatisticsId']);

        // The flagged Item is never touched.
        foreach ($this->savedObjects as $saved) {
            self::assertNotSame('item', $saved['schema'], 'ItemAnalysisRecomputeHandler MUST NEVER save the `item` schema');
        }

        self::assertSame('Original title', $this->db['item'][0]['title']);

        // A second graded event with the SAME crossed threshold does not duplicate the flag.
        $handler->handle($this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']));
        self::assertCount(1, $this->db['item-revision-flag']);

    }//end testTooDifficultyOpensDedupedFlagWithoutMutatingItem()

    /**
     * A negative itemTotalCorrelation opens a `negative-discrimination`
     * flag (distinct from `low-discrimination`, which only applies to a
     * non-negative-but-below-threshold correlation).
     *
     * @return void
     */
    public function testNegativeCorrelationOpensNegativeDiscriminationFlag(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $this->seed('item', ['id' => 'item-1', 'uuid' => 'item-1', 'tenant_id' => 'tenant-a']);
        $this->seedGradedResult('result-1', ['item-1']);

        $statistics  = ['item-1' => ['sampleSize' => 40, 'pValue' => 0.5, 'itemTotalCorrelation' => -0.15, 'distractorAnalysis' => null, 'insufficientData' => false, 'computedAt' => '2026-07-13T10:00:00+02:00']];
        $reliability = ['sampleSize' => 40, 'itemCount' => 1, 'cronbachAlpha' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00'];

        $handler = $this->makeHandler(
            $this->stubService($statistics, $reliability, $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $handler->handle($this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']));

        self::assertCount(1, $this->db['item-revision-flag']);
        self::assertSame('negative-discrimination', $this->db['item-revision-flag'][0]['reason']);

    }//end testNegativeCorrelationOpensNegativeDiscriminationFlag()

    /**
     * insufficientData items are never evaluated against quality thresholds
     * — no flag is created.
     *
     * @return void
     */
    public function testInsufficientDataNeverOpensAFlag(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);
        $this->seed('item', ['id' => 'item-1', 'uuid' => 'item-1', 'tenant_id' => 'tenant-a']);
        $this->seedGradedResult('result-1', ['item-1']);

        $statistics  = ['item-1' => ['sampleSize' => 5, 'pValue' => null, 'itemTotalCorrelation' => null, 'distractorAnalysis' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00']];
        $reliability = ['sampleSize' => 5, 'itemCount' => 1, 'cronbachAlpha' => null, 'insufficientData' => true, 'computedAt' => '2026-07-13T10:00:00+02:00'];

        $handler = $this->makeHandler(
            $this->stubService($statistics, $reliability, $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $handler->handle($this->makeGradedEvent(['assessmentId' => 'assessment-1', 'tenant_id' => 'tenant-a']));

        self::assertSame([], $this->db['item-revision-flag'] ?? []);

    }//end testInsufficientDataNeverOpensAFlag()

    /**
     * An event on a different schema/toState is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedEventIsIgnored(): void
    {
        $handler = $this->makeHandler(
            $this->stubService([], ['sampleSize' => 0, 'itemCount' => 0, 'cronbachAlpha' => null, 'insufficientData' => true, 'computedAt' => ''], $this->defaultConfig()),
            new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
        );

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['assessmentId' => 'assessment-1']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('assessment-result');
        $event->method('getTo')->willReturn('submitted');

        $handler->handle($event);

        self::assertSame([], $this->savedObjects);

    }//end testUnrelatedEventIsIgnored()
}//end class
