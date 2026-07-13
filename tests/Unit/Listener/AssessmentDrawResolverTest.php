<?php

/**
 * Scholiq AssessmentDrawResolver unit tests.
 *
 * Covers: fixed draw count from a filtered pool, subjectTags/difficulty
 * filtering, variant-group exclusivity, Fisher-Yates item-order shuffle, the
 * QTI `fixed`-choice-respecting answer-option shuffle, fail-closed behaviour
 * on an insufficient pool, and that a client-supplied drawnItemRefs value is
 * always overwritten by the server-side resolution.
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\AssessmentDrawResolver;
use OCA\Scholiq\Service\ItemPoolFilter;
use OCA\Scholiq\Service\QtiChoiceOrderResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AssessmentDrawResolver::handle() on ObjectCreatedEvent<AssessmentResult>.
 */
class AssessmentDrawResolverTest extends TestCase
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
     * Build a resolver backed by an ObjectService stub over $this->db.
     *
     * @return AssessmentDrawResolver
     */
    private function makeResolver(): AssessmentDrawResolver
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
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new AssessmentDrawResolver(
            $objectService,
            new QtiChoiceOrderResolver(),
            new ItemPoolFilter(),
            $this->createMock(LoggerInterface::class)
        );

    }//end makeResolver()

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
     * Build a mocked ObjectCreatedEvent<AssessmentResult>.
     *
     * @param array<string, mixed> $data The AssessmentResult jsonSerialize() payload.
     *
     * @return ObjectCreatedEvent
     */
    private function makeResultEvent(array $data): ObjectCreatedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('assessment-result');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        return $event;

    }//end makeResultEvent()

    /**
     * The single drawnItemRefs write recorded for assessment-result, or null.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function savedDrawnItemRefs(): ?array
    {
        foreach (array_reverse($this->savedObjects) as $saved) {
            if ($saved['schema'] === 'assessment-result') {
                return $saved['object']['drawnItemRefs'] ?? null;
            }
        }

        return null;

    }//end savedDrawnItemRefs()

    /**
     * A random-draw assessment draws exactly drawCount items, each published
     * and matching the configured subjectTags filter.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-random-draw-assessment-draws-the-configured-number-of-items-from-the-filtered-pool
     */
    public function testRandomDrawDrawsConfiguredCountFromFilteredPool(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                   => 'assessment-1',
                'uuid'                 => 'assessment-1',
                'tenant_id'            => 'tenant-a',
                'itemSelectionMode'    => 'random-draw',
                'itemPoolConfig'       => [
                    'itemBankId'  => 'bank-1',
                    'drawCount'   => 3,
                    'subjectTags' => ['algebra'],
                ],
                'shuffleItemOrder'     => false,
                'shuffleAnswerOptions' => false,
            ]
        );

        for ($i = 1; $i <= 10; $i++) {
            $this->seed(
                'item',
                [
                    'id'          => "item-$i",
                    'uuid'        => "item-$i",
                    'tenant_id'   => 'tenant-a',
                    'itemBankId'  => 'bank-1',
                    'lifecycle'   => 'published',
                    'subjectTags' => ['algebra'],
                    'maxScore'    => 1,
                ]
            );
        }

        // A decoy item outside the bank and one non-matching-subject item —
        // neither should ever be drawn.
        $this->seed(
            'item',
            [
                'id'          => 'item-other-bank',
                'uuid'        => 'item-other-bank',
                'tenant_id'   => 'tenant-a',
                'itemBankId'  => 'bank-2',
                'lifecycle'   => 'published',
                'subjectTags' => ['algebra'],
                'maxScore'    => 1,
            ]
        );
        $this->seed(
            'item',
            [
                'id'          => 'item-wrong-subject',
                'uuid'        => 'item-wrong-subject',
                'tenant_id'   => 'tenant-a',
                'itemBankId'  => 'bank-1',
                'lifecycle'   => 'published',
                'subjectTags' => ['geometry'],
                'maxScore'    => 1,
            ]
        );

        $resolver = $this->makeResolver();
        $event    = $this->makeResultEvent(
            [
                'id'            => 'result-1',
                'assessmentId'  => 'assessment-1',
                'learnerId'     => 'learner-1',
                'tenant_id'     => 'tenant-a',
                'drawnItemRefs' => [['itemId' => 'attacker-item', 'points' => 999, 'optionOrder' => null]],
            ]
        );

        $resolver->handle($event);

        $drawn = $this->savedDrawnItemRefs();
        self::assertIsArray($drawn);
        self::assertCount(3, $drawn);

        foreach ($drawn as $ref) {
            self::assertStringStartsWith('item-', $ref['itemId']);
            self::assertNotSame('item-other-bank', $ref['itemId']);
            self::assertNotSame('item-wrong-subject', $ref['itemId']);
        }

        // No duplicates in a single drawn set.
        $itemIds = array_column($drawn, 'itemId');
        self::assertSame($itemIds, array_unique($itemIds));

    }//end testRandomDrawDrawsConfiguredCountFromFilteredPool()

    /**
     * A drawn set never includes two items from the same variant group —
     * repeated draws always contain at most one of A/B.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-drawn-set-never-includes-two-items-from-the-same-variant-group
     */
    public function testDrawnSetNeverIncludesTwoItemsFromTheSameVariantGroup(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                => 'assessment-1',
                'uuid'              => 'assessment-1',
                'tenant_id'         => 'tenant-a',
                'itemSelectionMode' => 'random-draw',
                'itemPoolConfig'    => ['itemBankId' => 'bank-1', 'drawCount' => 2],
            ]
        );

        $this->seed('item', ['id' => 'item-a', 'uuid' => 'item-a', 'tenant_id' => 'tenant-a', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'variantGroupId' => 'v1', 'maxScore' => 1]);
        $this->seed('item', ['id' => 'item-b', 'uuid' => 'item-b', 'tenant_id' => 'tenant-a', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'variantGroupId' => 'v1', 'maxScore' => 1]);
        $this->seed('item', ['id' => 'item-c', 'uuid' => 'item-c', 'tenant_id' => 'tenant-a', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'variantGroupId' => null, 'maxScore' => 1]);

        for ($trial = 0; $trial < 25; $trial++) {
            $this->savedObjects = [];
            $resolver           = $this->makeResolver();
            $event              = $this->makeResultEvent(
                [
                    'id'           => "result-$trial",
                    'assessmentId' => 'assessment-1',
                    'learnerId'    => 'learner-1',
                    'tenant_id'    => 'tenant-a',
                ]
            );

            $resolver->handle($event);

            $drawn    = $this->savedDrawnItemRefs();
            $itemIds  = array_column($drawn, 'itemId');
            $hasA     = in_array('item-a', $itemIds, true);
            $hasB     = in_array('item-b', $itemIds, true);

            self::assertFalse(
                ($hasA === true && $hasB === true),
                'A drawn set must never contain both variant-group members item-a and item-b'
            );
            self::assertCount(2, $itemIds);
        }//end for

    }//end testDrawnSetNeverIncludesTwoItemsFromTheSameVariantGroup()

    /**
     * A fixed-list assessment with shuffleItemOrder enabled produces
     * different presentation orders across independent attempts — not
     * guaranteed identical (Fisher-Yates shuffle).
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-learner-taking-a-fixed-list-assessment-with-shuffle-enabled-sees-a-permuted-item-order
     */
    public function testShuffleItemOrderProducesVaryingPresentationOrder(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                => 'assessment-1',
                'uuid'              => 'assessment-1',
                'tenant_id'         => 'tenant-a',
                'itemSelectionMode' => 'fixed',
                'itemRefs'          => [
                    ['itemId' => 'item-1', 'points' => 1],
                    ['itemId' => 'item-2', 'points' => 1],
                    ['itemId' => 'item-3', 'points' => 1],
                    ['itemId' => 'item-4', 'points' => 1],
                    ['itemId' => 'item-5', 'points' => 1],
                ],
                'shuffleItemOrder'  => true,
            ]
        );

        foreach (range(1, 5) as $n) {
            $this->seed('item', ['id' => "item-$n", 'uuid' => "item-$n", 'tenant_id' => 'tenant-a', 'maxScore' => 1]);
        }

        $orders = [];
        for ($trial = 0; $trial < 20; $trial++) {
            $this->savedObjects = [];
            $resolver           = $this->makeResolver();
            $event              = $this->makeResultEvent(
                [
                    'id'           => "result-$trial",
                    'assessmentId' => 'assessment-1',
                    'learnerId'    => 'learner-1',
                    'tenant_id'    => 'tenant-a',
                ]
            );

            $resolver->handle($event);

            $drawn    = $this->savedDrawnItemRefs();
            $orders[] = implode(',', array_column($drawn, 'itemId'));
        }

        self::assertGreaterThan(
            1,
            count(array_unique($orders)),
            'shuffleItemOrder=true over 20 independent attempts of a 5-item list must not always '
            .'produce the identical order (Fisher-Yates permutation, not a no-op)'
        );

        // Every order remains a permutation of the same 5 items — the SET never changes.
        foreach ($orders as $order) {
            self::assertSame(['item-1', 'item-2', 'item-3', 'item-4', 'item-5'], $this->sorted($order));
        }

    }//end testShuffleItemOrderProducesVaryingPresentationOrder()

    /**
     * Sort a comma-joined item-id order string for set-equality comparison.
     *
     * @param string $order Comma-joined item ids.
     *
     * @return array<int,string>
     */
    private function sorted(string $order): array
    {
        $parts = explode(',', $order);
        sort($parts);
        return $parts;

    }//end sorted()

    /**
     * A `fixed="true"` simpleChoice never moves when shuffleAnswerOptions is
     * true, while the other choices may be permuted.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-pinned-distractor-never-moves-when-answer-options-shuffle
     */
    public function testFixedSimpleChoiceNeverMovesWhenAnswerOptionsShuffle(): void
    {
        $qtiBody = '<?xml version="1.0"?>'
            .'<assessmentItem><itemBody><choiceInteraction responseIdentifier="RESPONSE" shuffle="true">'
            .'<simpleChoice identifier="A">Alpha</simpleChoice>'
            .'<simpleChoice identifier="NONE" fixed="true">None of the above</simpleChoice>'
            .'<simpleChoice identifier="B">Beta</simpleChoice>'
            .'</choiceInteraction></itemBody></assessmentItem>';

        $this->seed(
            'assessment',
            [
                'id'                   => 'assessment-1',
                'uuid'                 => 'assessment-1',
                'tenant_id'            => 'tenant-a',
                'itemSelectionMode'    => 'fixed',
                'itemRefs'             => [['itemId' => 'item-1', 'points' => 1]],
                'shuffleAnswerOptions' => true,
            ]
        );
        $this->seed(
            'item',
            [
                'id'              => 'item-1',
                'uuid'            => 'item-1',
                'tenant_id'       => 'tenant-a',
                'maxScore'        => 1,
                'interactionType' => 'choice',
                'qtiBody'         => $qtiBody,
            ]
        );

        $seenNonePosition = [];
        for ($trial = 0; $trial < 15; $trial++) {
            $this->savedObjects = [];
            $resolver            = $this->makeResolver();
            $event                = $this->makeResultEvent(
                [
                    'id'           => "result-$trial",
                    'assessmentId' => 'assessment-1',
                    'learnerId'    => 'learner-1',
                    'tenant_id'    => 'tenant-a',
                ]
            );

            $resolver->handle($event);

            $drawn       = $this->savedDrawnItemRefs();
            $optionOrder = $drawn[0]['optionOrder'];

            self::assertSame(1, array_search('NONE', $optionOrder, true), 'fixed choice must stay at declared index 1');
            self::assertEqualsCanonicalizing(['A', 'NONE', 'B'], $optionOrder);

            $seenNonePosition[] = implode(',', $optionOrder);
        }//end for

        self::assertGreaterThan(
            1,
            count(array_unique($seenNonePosition)),
            'the two movable choices (A, B) must sometimes swap around the fixed NONE choice'
        );

    }//end testFixedSimpleChoiceNeverMovesWhenAnswerOptionsShuffle()

    /**
     * When the pool cannot supply drawCount distinct variant groups, the
     * listener fails closed: drawnItemRefs is never written (stays at its
     * schema default []).
     *
     * @return void
     */
    public function testFailsClosedWhenPoolCannotSupplyDrawCount(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                => 'assessment-1',
                'uuid'              => 'assessment-1',
                'tenant_id'         => 'tenant-a',
                'itemSelectionMode' => 'random-draw',
                'itemPoolConfig'    => ['itemBankId' => 'bank-1', 'drawCount' => 5],
            ]
        );

        // Only 2 published items available — fewer than drawCount 5.
        $this->seed('item', ['id' => 'item-1', 'uuid' => 'item-1', 'tenant_id' => 'tenant-a', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'maxScore' => 1]);
        $this->seed('item', ['id' => 'item-2', 'uuid' => 'item-2', 'tenant_id' => 'tenant-a', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'maxScore' => 1]);

        $resolver = $this->makeResolver();
        $event    = $this->makeResultEvent(
            [
                'id'           => 'result-1',
                'assessmentId' => 'assessment-1',
                'learnerId'    => 'learner-1',
                'tenant_id'    => 'tenant-a',
            ]
        );

        $resolver->handle($event);

        self::assertNull($this->savedDrawnItemRefs(), 'a fail-closed resolution must never write a short drawnItemRefs set');

    }//end testFailsClosedWhenPoolCannotSupplyDrawCount()

    /**
     * A client-supplied drawnItemRefs value on the create payload is always
     * overwritten by the server-side resolution — the resolver never trusts
     * or preserves it.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-client-supplied-drawnitemrefs-value-is-overwritten-by-the-server-resolved-draw
     */
    public function testClientSuppliedDrawnItemRefsIsOverwritten(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                => 'assessment-1',
                'uuid'              => 'assessment-1',
                'tenant_id'         => 'tenant-a',
                'itemSelectionMode' => 'fixed',
                'itemRefs'          => [['itemId' => 'item-1', 'points' => 7]],
            ]
        );
        $this->seed('item', ['id' => 'item-1', 'uuid' => 'item-1', 'tenant_id' => 'tenant-a', 'maxScore' => 1]);

        $resolver = $this->makeResolver();
        $event    = $this->makeResultEvent(
            [
                'id'            => 'result-1',
                'assessmentId'  => 'assessment-1',
                'learnerId'     => 'learner-1',
                'tenant_id'     => 'tenant-a',
                'drawnItemRefs' => [
                    ['itemId' => 'attacker-chosen-item', 'points' => 1000, 'optionOrder' => null],
                ],
            ]
        );

        $resolver->handle($event);

        $drawn = $this->savedDrawnItemRefs();
        self::assertCount(1, $drawn);
        self::assertSame('item-1', $drawn[0]['itemId']);
        self::assertSame(7, $drawn[0]['points']);

    }//end testClientSuppliedDrawnItemRefsIsOverwritten()

    /**
     * An event on a different schema is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedSchemaIsIgnored(): void
    {
        $resolver = $this->makeResolver();

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'x']);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('grade-entry');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $resolver->handle($event);

        self::assertSame([], $this->savedObjects);

    }//end testUnrelatedSchemaIsIgnored()
}//end class
