<?php

/**
 * Scholiq ItemAnalysisService unit tests.
 *
 * p-value, corrected item-total correlation, and Cronbach's alpha against
 * hand-computed reference fixtures (exact fractions chosen so the reference
 * values are exact, not merely "close"), Kelley 27%-split distractor
 * analysis with a fully traced high/low group composition, and the
 * minimum-N gate (insufficientData true/false at and around the boundary).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\ItemAnalysisService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ItemAnalysisService::computeItemStatistics()/computeReliability().
 */
class ItemAnalysisServiceTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = [];

    }//end setUp()

    /**
     * Build a service backed by an ObjectService stub over $this->db.
     *
     * @return ItemAnalysisService
     */
    private function makeService(): ItemAnalysisService
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

        return new ItemAnalysisService($objectService);

    }//end makeService()

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
     * Seed the parent Assessment (no itemAnalysisConfig override — schema defaults apply).
     *
     * @return void
     */
    private function seedAssessment(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'uuid' => 'assessment-1', 'tenant_id' => 'tenant-a']);

    }//end seedAssessment()

    /**
     * An item's statistics remain null below the minimum sample (n=12 < 20).
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-an-items-statistics-remain-null-below-the-minimum-sample
     */
    public function testItemStatisticsInsufficientDataBelowMinimumSample(): void
    {
        $this->seedAssessment();
        $this->seed('item', ['id' => 'item-x', 'uuid' => 'item-x', 'tenant_id' => 'tenant-a', 'maxScore' => 1]);

        for ($i = 0; $i < 12; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [['itemId' => 'item-x', 'points' => 1, 'optionOrder' => null]],
                    'responses'     => [
                        ['itemId' => 'item-x', 'response' => ['value' => 'A'], 'autoScore' => 1, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service    = $this->makeService();
        $statistics = $service->computeItemStatistics('item-x', 'assessment-1');

        self::assertSame(12, $statistics['sampleSize']);
        self::assertTrue($statistics['insufficientData']);
        self::assertNull($statistics['pValue']);
        self::assertNull($statistics['itemTotalCorrelation']);
        self::assertNull($statistics['distractorAnalysis']);

    }//end testItemStatisticsInsufficientDataBelowMinimumSample()

    /**
     * At exactly n=20 (the minimum-N boundary), pValue and the corrected
     * item-total correlation are computed against a hand-computed fixture:
     * itemX and itemY carry IDENTICAL 0/1 score vectors (12 full-marks, 8
     * zero), so item-excluded total (= itemY, in a 2-item test) equals
     * itemX exactly — Pearson correlation of a vector against itself is
     * exactly 1.0, and pValue = 12/20 = 0.6.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-an-items-p-value-and-discrimination-are-computed-once-the-minimum-sample-is-met
     */
    public function testItemStatisticsPValueAndCorrelationAtMinimumSampleBoundary(): void
    {
        $this->seedAssessment();
        $this->seed('item', ['id' => 'item-x', 'uuid' => 'item-x', 'tenant_id' => 'tenant-a', 'maxScore' => 1, 'interactionType' => 'textEntry']);

        for ($i = 0; $i < 20; $i++) {
            $score = ($i < 12) ? 1 : 0;
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [
                        ['itemId' => 'item-x', 'points' => 1, 'optionOrder' => null],
                        ['itemId' => 'item-y', 'points' => 1, 'optionOrder' => null],
                    ],
                    'responses'     => [
                        ['itemId' => 'item-x', 'response' => ['value' => (string) $score], 'autoScore' => $score, 'manualScore' => null],
                        // itemY is IDENTICAL to itemX by construction (see docblock).
                        ['itemId' => 'item-y', 'response' => ['value' => (string) $score], 'autoScore' => $score, 'manualScore' => null],
                    ],
                ]
            );
        }//end for

        $service    = $this->makeService();
        $statistics = $service->computeItemStatistics('item-x', 'assessment-1');

        self::assertSame(20, $statistics['sampleSize']);
        self::assertFalse($statistics['insufficientData']);
        self::assertEqualsWithDelta(0.6, $statistics['pValue'], 0.0001);
        self::assertEqualsWithDelta(1.0, $statistics['itemTotalCorrelation'], 0.0001);

    }//end testItemStatisticsPValueAndCorrelationAtMinimumSampleBoundary()

    /**
     * Kelley 27%-split distractor analysis over a hand-traced 20-respondent
     * fixture: the top-5-by-total-score group is exactly the 5 first
     * full-marks respondents (all selected "A"), the bottom-5 group is
     * exactly the last 5 zero-scoring respondents (1 selected "B", 4
     * selected "C") — see docblock derivation in the test body.
     *
     * @return void
     */
    public function testDistractorAnalysisKelleySplit(): void
    {
        $this->seedAssessment();
        $qtiBody = '<?xml version="1.0"?>'
            .'<assessmentItem><itemBody><choiceInteraction responseIdentifier="RESPONSE">'
            .'<simpleChoice identifier="A">Alpha</simpleChoice>'
            .'<simpleChoice identifier="B">Beta</simpleChoice>'
            .'<simpleChoice identifier="C">Gamma</simpleChoice>'
            .'</choiceInteraction></itemBody></assessmentItem>';

        $this->seed(
            'item',
            [
                'id'              => 'item-d',
                'uuid'            => 'item-d',
                'tenant_id'       => 'tenant-a',
                'maxScore'        => 1,
                'interactionType' => 'choice',
                'correctResponse' => 'A',
                'qtiBody'         => $qtiBody,
            ]
        );

        // Indices 0-9: full marks, all selected A (PHP 8 sort() is stable, so
        // these sort to the front on a descending-by-totalScore sort and the
        // top-5 group is exactly indices 0-4).
        for ($i = 0; $i < 10; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [['itemId' => 'item-d', 'points' => 1, 'optionOrder' => null]],
                    'responses'     => [
                        ['itemId' => 'item-d', 'response' => ['value' => 'A'], 'autoScore' => 1, 'manualScore' => null],
                    ],
                ]
            );
        }

        // Indices 10-15: zero score, selected B (6 respondents).
        for ($i = 10; $i < 16; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [['itemId' => 'item-d', 'points' => 1, 'optionOrder' => null]],
                    'responses'     => [
                        ['itemId' => 'item-d', 'response' => ['value' => 'B'], 'autoScore' => 0, 'manualScore' => null],
                    ],
                ]
            );
        }

        // Indices 16-19: zero score, selected C (4 respondents) — these are
        // the LAST 4 rows, so they land in the bottom-5 group alongside
        // exactly one of the B-selectors (index 15).
        for ($i = 16; $i < 20; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [['itemId' => 'item-d', 'points' => 1, 'optionOrder' => null]],
                    'responses'     => [
                        ['itemId' => 'item-d', 'response' => ['value' => 'C'], 'autoScore' => 0, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service    = $this->makeService();
        $statistics = $service->computeItemStatistics('item-d', 'assessment-1');

        self::assertFalse($statistics['insufficientData']);
        self::assertIsArray($statistics['distractorAnalysis']);

        $byOption = [];
        foreach ($statistics['distractorAnalysis'] as $row) {
            $byOption[$row['optionId']] = $row;
        }

        self::assertSame(['selectedByHighGroup' => 5, 'selectedByLowGroup' => 0], [
            'selectedByHighGroup' => $byOption['A']['selectedByHighGroup'],
            'selectedByLowGroup'  => $byOption['A']['selectedByLowGroup'],
        ]);
        self::assertSame(['selectedByHighGroup' => 0, 'selectedByLowGroup' => 1], [
            'selectedByHighGroup' => $byOption['B']['selectedByHighGroup'],
            'selectedByLowGroup'  => $byOption['B']['selectedByLowGroup'],
        ]);
        self::assertSame(['selectedByHighGroup' => 0, 'selectedByLowGroup' => 4], [
            'selectedByHighGroup' => $byOption['C']['selectedByHighGroup'],
            'selectedByLowGroup'  => $byOption['C']['selectedByLowGroup'],
        ]);

    }//end testDistractorAnalysisKelleySplit()

    /**
     * Reliability is null until 30 graded attempts exist — the spec's own
     * n=22 scenario.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-reliability-is-null-until-30-graded-attempts-exist
     */
    public function testReliabilityInsufficientDataBelowMinimumSample(): void
    {
        $this->seedAssessment();

        for ($i = 0; $i < 22; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'           => "result-$i",
                    'assessmentId' => 'assessment-1',
                    'tenant_id'    => 'tenant-a',
                    'lifecycle'    => 'graded',
                    'responses'    => [
                        ['itemId' => 'item-1', 'response' => ['value' => 'x'], 'autoScore' => 1, 'manualScore' => null],
                        ['itemId' => 'item-2', 'response' => ['value' => 'x'], 'autoScore' => 1, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service     = $this->makeService();
        $reliability = $service->computeReliability('assessment-1');

        self::assertSame(22, $reliability['sampleSize']);
        self::assertTrue($reliability['insufficientData']);
        self::assertNull($reliability['cronbachAlpha']);

    }//end testReliabilityInsufficientDataBelowMinimumSample()

    /**
     * Cronbach's alpha against a hand-computed reference fixture: itemA and
     * itemB carry IDENTICAL alternating 0/1 score vectors across 30
     * respondents (15 zeros, 15 ones each) — sum of item variances is
     * exactly half the total-score variance by construction (see docblock
     * derivation), giving alpha = 2 * (1 - 0.5) = 1.0 exactly.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
     */
    public function testReliabilityCronbachAlphaHandComputedFixture(): void
    {
        $this->seedAssessment();

        for ($i = 0; $i < 30; $i++) {
            $score = ($i % 2 === 0) ? 0 : 1;
            $this->seed(
                'assessment-result',
                [
                    'id'           => "result-$i",
                    'assessmentId' => 'assessment-1',
                    'tenant_id'    => 'tenant-a',
                    'lifecycle'    => 'graded',
                    'responses'    => [
                        ['itemId' => 'item-a', 'response' => ['value' => (string) $score], 'autoScore' => $score, 'manualScore' => null],
                        ['itemId' => 'item-b', 'response' => ['value' => (string) $score], 'autoScore' => $score, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service     = $this->makeService();
        $reliability = $service->computeReliability('assessment-1');

        self::assertSame(30, $reliability['sampleSize']);
        self::assertSame(2, $reliability['itemCount']);
        self::assertFalse($reliability['insufficientData']);
        self::assertEqualsWithDelta(1.0, $reliability['cronbachAlpha'], 0.0001);

    }//end testReliabilityCronbachAlphaHandComputedFixture()

    /**
     * Reliability requires itemCount >= 2 — a single-item assessment stays
     * insufficientData even with n >= 30, since alpha is undefined for k=1.
     *
     * @return void
     */
    public function testReliabilityInsufficientDataWithFewerThanTwoItems(): void
    {
        $this->seedAssessment();

        for ($i = 0; $i < 35; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'           => "result-$i",
                    'assessmentId' => 'assessment-1',
                    'tenant_id'    => 'tenant-a',
                    'lifecycle'    => 'graded',
                    'responses'    => [
                        ['itemId' => 'item-only', 'response' => ['value' => 'x'], 'autoScore' => 1, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service     = $this->makeService();
        $reliability = $service->computeReliability('assessment-1');

        self::assertSame(1, $reliability['itemCount']);
        self::assertTrue($reliability['insufficientData']);
        self::assertNull($reliability['cronbachAlpha']);

    }//end testReliabilityInsufficientDataWithFewerThanTwoItems()

    /**
     * Assessment.itemAnalysisConfig, when set, overrides the schema
     * defaults — a lower minSampleSize makes a 12-sample computation
     * sufficient.
     *
     * @return void
     */
    public function testItemAnalysisConfigOverridesMinimumSampleSize(): void
    {
        $this->seed(
            'assessment',
            [
                'id'                 => 'assessment-1',
                'uuid'               => 'assessment-1',
                'tenant_id'          => 'tenant-a',
                'itemAnalysisConfig' => ['minSampleSize' => 10],
            ]
        );
        $this->seed('item', ['id' => 'item-x', 'uuid' => 'item-x', 'tenant_id' => 'tenant-a', 'maxScore' => 1]);

        for ($i = 0; $i < 12; $i++) {
            $this->seed(
                'assessment-result',
                [
                    'id'            => "result-$i",
                    'assessmentId'  => 'assessment-1',
                    'tenant_id'     => 'tenant-a',
                    'lifecycle'     => 'graded',
                    'drawnItemRefs' => [['itemId' => 'item-x', 'points' => 1, 'optionOrder' => null]],
                    'responses'     => [
                        ['itemId' => 'item-x', 'response' => ['value' => 'A'], 'autoScore' => 1, 'manualScore' => null],
                    ],
                ]
            );
        }

        $service    = $this->makeService();
        $statistics = $service->computeItemStatistics('item-x', 'assessment-1');

        self::assertFalse($statistics['insufficientData'], 'a per-Assessment minSampleSize override of 10 must make n=12 sufficient');
        self::assertSame(1.0, $statistics['pValue']);

    }//end testItemAnalysisConfigOverridesMinimumSampleSize()
}//end class
