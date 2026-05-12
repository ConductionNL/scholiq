<?php

/**
 * Scholiq Grade Formula Evaluator
 *
 * Stateless calculation engine that applies a CurriculumPlan's declared formula
 * over a learner's published GradeEntries to produce a final grade value, a
 * pass/fail verdict, and a per-period/per-component breakdown.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * The weighted-average / last-attempt / best-of-n / all-must-pass formulas
 * cannot be expressed in JSON-logic; they require iteration over aggregated
 * GradeEntry sets and conditional branching on CurriculumPlan.passRules.
 * Single responsibility: evaluate → return; no state, no audit writes.
 *
 * Consumed by:
 *   - GradeRollupHandler (via ObjectTransitionedEvent)
 *   - FinalGrade x-openregister-calculations engine (referenced by FQCN)
 *
 * @category Grading
 * @package  OCA\Scholiq\Grading
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Grading;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Evaluates CurriculumPlan formulas over a learner's published GradeEntries.
 */
class GradeFormulaEvaluator
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const GRADE_ENTRY_SCHEMA     = 'grade-entry';
    private const CURRICULUM_PLAN_SCHEMA = 'curriculum-plan';
    private const GRADE_SCALE_SCHEMA     = 'grade-scale';

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
     * Evaluate the CurriculumPlan formula for a learner.
     *
     * Fetches the CurriculumPlan + its associated GradeScale + all published
     * GradeEntries for this learner on this plan, then applies the declared
     * formula to produce a value, a pass/fail verdict, and a breakdown.
     *
     * @param string $curriculumPlanId UUID of the CurriculumPlan.
     * @param string $learnerId        Nextcloud user ID of the learner.
     *
     * @return array{value: float|null, passed: bool|null, breakdown: array, lastRecomputedAt: string}
     */
    public function evaluate(string $curriculumPlanId, string $learnerId): array
    {
        $plan = $this->fetchPlan(curriculumPlanId: $curriculumPlanId);
        if ($plan === null) {
            return $this->emptyResult();
        }

        $entries = $this->fetchPublishedEntries(
            curriculumPlanId: $curriculumPlanId,
            learnerId: $learnerId
        );

        if (empty($entries) === true) {
            return $this->emptyResult();
        }

        $components   = $this->indexComponents(plan: $plan);
        $formula      = $plan['formula'] ?? 'weighted-average';
        $passRules    = $plan['passRules'] ?? [];
        $gradeScaleId = $plan['gradeScaleId'] ?? null;

        $passThreshold = $this->fetchPassThreshold(gradeScaleId: $gradeScaleId);

        [$value, $breakdown] = $this->applyFormula(
            formula: $formula,
            entries: $entries,
            components: $components
        );

        $passed = $this->evaluatePassed(
            formula: $formula,
            value: $value,
            entries: $entries,
            components: $components,
            passRules: $passRules,
            passThreshold: $passThreshold
        );

        return [
            'value'            => $value,
            'passed'           => $passed,
            'breakdown'        => $breakdown,
            'lastRecomputedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

    }//end evaluate()

    /**
     * Fetch the CurriculumPlan object.
     *
     * @param string $curriculumPlanId UUID.
     *
     * @return array|null
     */
    private function fetchPlan(string $curriculumPlanId): ?array
    {
        $obj = $this->objectService->find(
            id: $curriculumPlanId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::CURRICULUM_PLAN_SCHEMA
        );

        if ($obj === null) {
            return null;
        }

        return $obj->jsonSerialize();

    }//end fetchPlan()

    /**
     * Fetch all published GradeEntries for this learner on this plan.
     *
     * @param string $curriculumPlanId UUID.
     * @param string $learnerId        NC user ID.
     *
     * @return array<int, array>
     */
    private function fetchPublishedEntries(string $curriculumPlanId, string $learnerId): array
    {
        $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::GRADE_ENTRY_SCHEMA,
                    'filters'  => [
                        'learnerId'        => $learnerId,
                        'curriculumPlanId' => $curriculumPlanId,
                        'lifecycle'        => 'published',
                    ],
                ]
                );

        if (empty($results) === true) {
            return [];
        }

        return array_map(
            static function ($obj) {
                if (is_array($obj) === true) {
                    return $obj;
                }

                return $obj->jsonSerialize();
            },
            $results
        );

    }//end fetchPublishedEntries()

    /**
     * Fetch the passThreshold from the GradeScale.
     *
     * @param string|null $gradeScaleId UUID or null.
     *
     * @return float|null
     */
    private function fetchPassThreshold(?string $gradeScaleId): ?float
    {
        if ($gradeScaleId === null) {
            return null;
        }

        $obj = $this->objectService->find(
            id: $gradeScaleId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::GRADE_SCALE_SCHEMA
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            $scale = $obj;
        } else {
            $scale = $obj->jsonSerialize();
        }

        $threshold = $scale['passThreshold'] ?? null;
        if ($threshold === null) {
            return null;
        }

        return (float) $threshold;

    }//end fetchPassThreshold()

    /**
     * Build a componentId → component map from the CurriculumPlan.
     *
     * @param array $plan CurriculumPlan data.
     *
     * @return array<string, array>
     */
    private function indexComponents(array $plan): array
    {
        $components = [];
        foreach (($plan['components'] ?? []) as $component) {
            if (isset($component['componentId']) === true) {
                $components[$component['componentId']] = $component;
            }
        }

        return $components;

    }//end indexComponents()

    /**
     * Resolve the effective weight for a single GradeEntry.
     *
     * The per-entry weight overrides the plan component weight when set.
     *
     * @param array                $entry      GradeEntry data.
     * @param array<string, array> $components Component index.
     *
     * @return float
     */
    private function effectiveWeight(array $entry, array $components): float
    {
        if (isset($entry['weight']) === true && $entry['weight'] !== null) {
            return (float) $entry['weight'];
        }

        $componentId = $entry['componentId'] ?? '';
        $planWeight  = $components[$componentId]['weight'] ?? 1;
        return (float) $planWeight;

    }//end effectiveWeight()

    /**
     * Apply the formula to the published entries.
     *
     * @param string               $formula    One of weighted-average|last-attempt|best-of-n|all-must-pass.
     * @param array<int, array>    $entries    Published GradeEntries.
     * @param array<string, array> $components Component index from the plan.
     *
     * @return array{0: float|null, 1: array}  [value, breakdown]
     */
    private function applyFormula(
        string $formula,
        array $entries,
        array $components,
    ): array {
        // Reduce entries per formula to the set we actually average.
        $effective = match ($formula) {
            'last-attempt'  => $this->lastAttemptEntries(entries: $entries),
            'best-of-n'     => $this->bestOfNEntries(entries: $entries),
            default         => $entries,
        };

        return $this->weightedAverage(entries: $effective, components: $components);

    }//end applyFormula()

    /**
     * Reduce to one entry per componentId (the most-recent by gradedAt).
     *
     * @param array<int, array> $entries All published entries.
     *
     * @return array<int, array>
     */
    private function lastAttemptEntries(array $entries): array
    {
        $byComponent = [];
        foreach ($entries as $entry) {
            $cid = $entry['componentId'] ?? '';
            if (isset($byComponent[$cid]) === false
                || $this->compareGradedAt(a: $entry, b: $byComponent[$cid]) > 0
            ) {
                $byComponent[$cid] = $entry;
            }
        }

        return array_values($byComponent);

    }//end lastAttemptEntries()

    /**
     * Reduce to one entry per componentId (the highest value).
     *
     * @param array<int, array> $entries All published entries.
     *
     * @return array<int, array>
     */
    private function bestOfNEntries(array $entries): array
    {
        $byComponent = [];
        foreach ($entries as $entry) {
            $cid = $entry['componentId'] ?? '';
            if (isset($byComponent[$cid]) === false
                || (float) ($entry['value'] ?? 0) > (float) ($byComponent[$cid]['value'] ?? 0)
            ) {
                $byComponent[$cid] = $entry;
            }
        }

        return array_values($byComponent);

    }//end bestOfNEntries()

    /**
     * Compare two entries by gradedAt timestamp.
     *
     * @param array $a First entry.
     * @param array $b Second entry.
     *
     * @return int Negative if a < b, positive if a > b, 0 if equal.
     */
    private function compareGradedAt(array $a, array $b): int
    {
        $rawA = strtotime($a['gradedAt'] ?? '1970-01-01');
        $rawB = strtotime($b['gradedAt'] ?? '1970-01-01');
        if ($rawA === false) {
            $timeA = 0;
        } else {
            $timeA = $rawA;
        }

        if ($rawB === false) {
            $timeB = 0;
        } else {
            $timeB = $rawB;
        }

        return $timeA <=> $timeB;

    }//end compareGradedAt()

    /**
     * Compute weighted average and breakdown from entries.
     *
     * @param array<int, array>    $entries    The entries to average.
     * @param array<string, array> $components Component index.
     *
     * @return array{0: float|null, 1: array}
     */
    private function weightedAverage(array $entries, array $components): array
    {
        $weightedSum        = 0.0;
        $totalWeight        = 0.0;
        $periodTotals       = [];
        $componentBreakdown = [];

        foreach ($entries as $entry) {
            $value  = (float) ($entry['value'] ?? 0);
            $weight = $this->effectiveWeight(entry: $entry, components: $components);
            $period = (string) ($entry['period'] ?? 'unknown');
            $cid    = $entry['componentId'] ?? '';

            $weightedSum += $value * $weight;
            $totalWeight += $weight;

            // Period accumulation for breakdown.
            if (isset($periodTotals[$period]) === false) {
                $periodTotals[$period] = ['sum' => 0.0, 'weight' => 0.0];
            }

            $periodTotals[$period]['sum']    += $value * $weight;
            $periodTotals[$period]['weight'] += $weight;

            $componentBreakdown[$cid] = [
                'value'        => $value,
                'weight'       => $weight,
                'contribution' => $value * $weight,
            ];
        }//end foreach

        if ($totalWeight === 0.0) {
            return [null, []];
        }

        $value = $weightedSum / $totalWeight;

        // Compute period averages.
        $periods = [];
        foreach ($periodTotals as $period => $totals) {
            if ($totals['weight'] > 0) {
                $periods[$period] = round($totals['sum'] / $totals['weight'], 4);
            } else {
                $periods[$period] = null;
            }
        }

        $breakdown = [
            'periods'    => $periods,
            'components' => $componentBreakdown,
        ];

        return [round($value, 4), $breakdown];

    }//end weightedAverage()

    /**
     * Determine whether the learner has passed.
     *
     * @param string               $formula       Formula name.
     * @param float|null           $value         Computed final value.
     * @param array<int, array>    $entries       Published entries.
     * @param array<string, array> $components    Component index.
     * @param array                $passRules     passRules from the CurriculumPlan.
     * @param float|null           $passThreshold Threshold from the GradeScale.
     *
     * @return bool|null Null if insufficient data.
     */
    private function evaluatePassed(
        string $formula,
        ?float $value,
        array $entries,
        array $components,
        array $passRules,
        ?float $passThreshold,
    ): ?bool {
        if ($value === null) {
            return null;
        }

        // Threshold check (applies to all formulas).
        if ($passThreshold !== null && $value < $passThreshold) {
            return false;
        }

        // All-must-pass: every component's best must be >= its passRules threshold.
        if ($formula === 'all-must-pass' && empty($passRules) === false) {
            $bestByComponent = $this->bestOfNEntries(entries: $entries);
            $bestMap         = [];
            foreach ($bestByComponent as $entry) {
                $bestMap[$entry['componentId'] ?? ''] = (float) ($entry['value'] ?? 0);
            }

            foreach ($passRules as $rule) {
                $ruleComponentId = $rule['componentId'] ?? '';
                $ruleThreshold   = (float) ($rule['passThreshold'] ?? 0);
                $bestValue       = $bestMap[$ruleComponentId] ?? null;
                if ($bestValue === null || $bestValue < $ruleThreshold) {
                    return false;
                }
            }
        }

        return true;

    }//end evaluatePassed()

    /**
     * Return an empty result (no entries yet).
     *
     * @return array{value: null, passed: null, breakdown: array, lastRecomputedAt: string}
     */
    private function emptyResult(): array
    {
        return [
            'value'            => null,
            'passed'           => null,
            'breakdown'        => [],
            'lastRecomputedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

    }//end emptyResult()
}//end class
