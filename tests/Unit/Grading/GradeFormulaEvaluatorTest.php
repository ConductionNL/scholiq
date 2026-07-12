<?php

/**
 * Scholiq GradeFormulaEvaluator unit tests.
 *
 * Covers the exam-board-case-handling extension: a `sourceKind: exemption`
 * GradeEntry must not corrupt a `weighted-average` roll-up (the pre-existing
 * `(float) ($entry['value'] ?? 0)` bug this fix closes — an exemption's
 * `null` value would otherwise cast to `0.0` and drag the average down), must
 * still be reflected in `breakdown.components[componentId]` as `exempt:
 * true`, and must satisfy an `all-must-pass` component's `passRules`
 * threshold without a numeric comparison.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Grading
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-entry-does-not-corrupt-the-weighted-average
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-satisfies-an-all-must-pass-component-without-a-numeric-check
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Grading;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Grading\GradeFormulaEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradeFormulaEvaluator::evaluate() — exemption-aware roll-up.
 */
class GradeFormulaEvaluatorTest extends TestCase
{

    /**
     * Wrap a data array in an ObjectEntity-like mock, as
     * GradeFormulaEvaluator::fetchPlan()/fetchPassThreshold() expect from
     * ObjectService::find() (unlike findAll(), which may return raw arrays).
     *
     * @param array<string,mixed> $data The jsonSerialize() payload.
     *
     * @return ObjectEntity
     */
    private function makeObjectEntity(array $data): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($data);

        return $entity;

    }//end makeObjectEntity()

    /**
     * Build an evaluator whose ObjectService returns the given plan, entries, and grade scale.
     *
     * @param array<string,mixed>             $plan    CurriculumPlan data returned by find().
     * @param array<int,array<string,mixed>>  $entries Published GradeEntries returned by findAll().
     * @param array<string,mixed>|null        $scale   GradeScale data returned by find(), or null.
     *
     * @return GradeFormulaEvaluator
     */
    private function makeEvaluator(array $plan, array $entries, ?array $scale=null): GradeFormulaEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);

        $planEntity  = $this->makeObjectEntity(data: $plan);
        $scaleEntity = $scale === null ? null : $this->makeObjectEntity(data: $scale);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($planEntity, $scaleEntity) {
                if ($schema === 'curriculum-plan') {
                    return $planEntity;
                }

                if ($schema === 'grade-scale') {
                    return $scaleEntity;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($entries) {
                if ($config['schema'] === 'grade-entry') {
                    return $entries;
                }

                return [];
            }
        );

        return new GradeFormulaEvaluator($objectService);

    }//end makeEvaluator()

    /**
     * A weighted-average roll-up with one numeric entry (7.0, weight 2) and one
     * exemption entry (null value, weight 3) excludes the exemption from the sum
     * and denominator — the resulting value equals the numeric entry's own value,
     * not a value dragged down by treating the null exemption value as zero.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-entry-does-not-corrupt-the-weighted-average
     */
    public function testExemptionEntryDoesNotCorruptWeightedAverage(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'formula'       => 'weighted-average',
            'gradeScaleId'  => 'scale-1',
            'components'    => [
                ['componentId' => 'comp-a', 'weight' => 2],
                ['componentId' => 'comp-b', 'weight' => 3],
            ],
        ];

        $entries = [
            [
                'componentId' => 'comp-a',
                'sourceKind'  => 'assessment-result',
                'value'       => 7.0,
                'weight'      => 2,
                'period'      => '1',
                'gradedAt'    => '2026-01-10T00:00:00Z',
            ],
            [
                'componentId' => 'comp-b',
                'sourceKind'  => 'exemption',
                'value'       => null,
                'weight'      => 3,
                'period'      => '1',
                'gradedAt'    => '2026-01-11T00:00:00Z',
            ],
        ];

        $evaluator = $this->makeEvaluator(plan: $plan, entries: $entries);

        $result = $evaluator->evaluate(curriculumPlanId: 'plan-1', learnerId: 'learner-1');

        self::assertSame(7.0, $result['value'], 'exemption must not drag the weighted average toward zero');
        self::assertTrue($result['breakdown']['components']['comp-b']['exempt']);
        self::assertArrayNotHasKey('value', $result['breakdown']['components']['comp-b']);
        self::assertSame(7.0, $result['breakdown']['components']['comp-a']['value']);

    }//end testExemptionEntryDoesNotCorruptWeightedAverage()

    /**
     * The pre-existing bug this change fixes: before the fix,
     * `weightedAverage()` did `(float) ($entry['value'] ?? 0)` unconditionally,
     * so a null-valued exemption entry cast to 0.0 and was summed with full
     * weight. This test pins the corrected behaviour with a case where the bug
     * would have produced a materially different (lower) result: two numeric
     * entries plus one heavily-weighted exemption entry.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/design.md#6-gradeformulaevaluator-extension--verified-against-the-current-implementation
     */
    public function testExemptionEntryWithHeavyWeightDoesNotDragDownAverage(): void
    {
        $plan = [
            'id'         => 'plan-1',
            'formula'    => 'weighted-average',
            'components' => [
                ['componentId' => 'comp-a', 'weight' => 1],
                ['componentId' => 'comp-b', 'weight' => 1],
                ['componentId' => 'comp-c', 'weight' => 10],
            ],
        ];

        $entries = [
            ['componentId' => 'comp-a', 'sourceKind' => 'manual', 'value' => 8.0, 'weight' => 1, 'gradedAt' => '2026-01-01T00:00:00Z'],
            ['componentId' => 'comp-b', 'sourceKind' => 'manual', 'value' => 6.0, 'weight' => 1, 'gradedAt' => '2026-01-02T00:00:00Z'],
            ['componentId' => 'comp-c', 'sourceKind' => 'exemption', 'value' => null, 'weight' => 10, 'gradedAt' => '2026-01-03T00:00:00Z'],
        ];

        $evaluator = $this->makeEvaluator(plan: $plan, entries: $entries);

        $result = $evaluator->evaluate(curriculumPlanId: 'plan-1', learnerId: 'learner-1');

        // Without the fix: (8*1 + 6*1 + 0*10) / (1+1+10) = 14/12 ≈ 1.17.
        // With the fix: exemption excluded entirely → (8*1 + 6*1) / (1+1) = 7.0.
        self::assertSame(7.0, $result['value']);
        self::assertTrue($result['breakdown']['components']['comp-c']['exempt']);

    }//end testExemptionEntryWithHeavyWeightDoesNotDragDownAverage()

    /**
     * An all-must-pass component satisfied only by an exemption entry passes its
     * passRules threshold without a numeric comparison, and does not force the
     * overall `passed` verdict to false.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-satisfies-an-all-must-pass-component-without-a-numeric-check
     */
    public function testExemptionSatisfiesAllMustPassWithoutNumericCheck(): void
    {
        $plan = [
            'id'         => 'plan-1',
            'formula'    => 'all-must-pass',
            'components' => [
                ['componentId' => 'comp-a', 'weight' => 1],
                ['componentId' => 'comp-b', 'weight' => 1],
            ],
            'passRules'  => [
                ['componentId' => 'comp-a', 'passThreshold' => 5.5],
                ['componentId' => 'comp-b', 'passThreshold' => 5.5],
            ],
        ];

        $entries = [
            ['componentId' => 'comp-a', 'sourceKind' => 'manual', 'value' => 8.0, 'weight' => 1, 'gradedAt' => '2026-01-01T00:00:00Z'],
            ['componentId' => 'comp-b', 'sourceKind' => 'exemption', 'value' => null, 'weight' => 1, 'gradedAt' => '2026-01-02T00:00:00Z'],
        ];

        $evaluator = $this->makeEvaluator(plan: $plan, entries: $entries);

        $result = $evaluator->evaluate(curriculumPlanId: 'plan-1', learnerId: 'learner-1');

        self::assertTrue($result['passed']);

    }//end testExemptionSatisfiesAllMustPassWithoutNumericCheck()

    /**
     * A non-exempt component that fails its all-must-pass threshold still fails
     * the overall verdict even when a sibling component is exempt — the
     * exemption only satisfies its own component's rule.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-satisfies-an-all-must-pass-component-without-a-numeric-check
     */
    public function testNonExemptComponentStillEnforcesThresholdAlongsideAnExemption(): void
    {
        $plan = [
            'id'         => 'plan-1',
            'formula'    => 'all-must-pass',
            'components' => [
                ['componentId' => 'comp-a', 'weight' => 1],
                ['componentId' => 'comp-b', 'weight' => 1],
            ],
            'passRules'  => [
                ['componentId' => 'comp-a', 'passThreshold' => 5.5],
                ['componentId' => 'comp-b', 'passThreshold' => 5.5],
            ],
        ];

        $entries = [
            ['componentId' => 'comp-a', 'sourceKind' => 'manual', 'value' => 3.0, 'weight' => 1, 'gradedAt' => '2026-01-01T00:00:00Z'],
            ['componentId' => 'comp-b', 'sourceKind' => 'exemption', 'value' => null, 'weight' => 1, 'gradedAt' => '2026-01-02T00:00:00Z'],
        ];

        $evaluator = $this->makeEvaluator(plan: $plan, entries: $entries);

        $result = $evaluator->evaluate(curriculumPlanId: 'plan-1', learnerId: 'learner-1');

        self::assertFalse($result['passed']);

    }//end testNonExemptComponentStillEnforcesThresholdAlongsideAnExemption()

    /**
     * No published entries yet → an empty result with a null value/passed, unchanged
     * by the exemption extension.
     *
     * @return void
     */
    public function testNoPublishedEntriesReturnsEmptyResult(): void
    {
        $plan = ['id' => 'plan-1', 'formula' => 'weighted-average', 'components' => []];

        $evaluator = $this->makeEvaluator(plan: $plan, entries: []);

        $result = $evaluator->evaluate(curriculumPlanId: 'plan-1', learnerId: 'learner-1');

        self::assertNull($result['value']);
        self::assertNull($result['passed']);
        self::assertSame([], $result['breakdown']);

    }//end testNoPublishedEntriesReturnsEmptyResult()
}//end class
