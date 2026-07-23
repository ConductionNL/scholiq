<?php

/**
 * Scholiq Item Analysis Service
 *
 * Stateless classical-test-theory (CTT) calculation engine: p-value, corrected
 * item-total (item-rest) Pearson correlation, Kelley 27%-split distractor
 * analysis, and Cronbach's alpha, computed from graded `AssessmentResult`s.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata"
 * (mirrors GradeFormulaEvaluator's docblock). OpenRegister's declarative
 * aggregation engine (`x-openregister-aggregations`,
 * openregister/lib/Service/Aggregation/AggregationRunner.php:965-972,1256)
 * supports only count/sum/avg/min/max over a single flat/top-level field per
 * matching object row — every response lives nested inside
 * `AssessmentResult.responses[]`, one array per learner per attempt, and none
 * of these statistics reduce to a flat-field aggregate:
 *   - p-value needs a group-by over responses[] elements by itemId, which the
 *     aggregation engine has no primitive for at all.
 *   - item-total correlation needs a SECOND vector per respondent (total
 *     score EXCLUDING the item's own contribution — the standard "corrected"
 *     item-total correlation) and a Pearson correlation coefficient; there is
 *     no aggregation metric for "correlation".
 *   - distractor analysis needs a Kelley top/bottom-27%-by-total-score split
 *     and, per answer option, a count within each group separately —
 *     multi-dimensional group-then-count over nested array elements.
 *   - Cronbach's alpha is a multi-item, multi-pass variance-ratio formula
 *     over every item's score vector plus the total-score vector.
 * (design.md "Where the computation lives, precisely" carries the full table.)
 *
 * Single responsibility: compute -> return; no state, no audit writes, no
 * persistence (ItemAnalysisRecomputeHandler upserts ItemStatistics /
 * AssessmentReliability with the returned arrays).
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DateTimeImmutable;
use DOMDocument;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes item- and assessment-level CTT statistics from graded AssessmentResults.
 *
 * Deliberately a single class exposing both computeItemStatistics() and
 * computeReliability() (task 4.1's explicit shape — design.md's aggregation-
 * engine-insufficiency table treats both as one "statistics engine"
 * responsibility). Already broken into 16 small single-purpose private
 * helpers (each individually well under the per-method complexity
 * threshold); the class-level SUM naturally exceeds PHPMD's
 * ExcessiveClassComplexity budget purely because of how many small vector/
 * variance/correlation helpers a CTT engine needs, not because any one of
 * them is tangled — splitting further would fragment one cohesive,
 * intentionally-scoped statistics engine into a matching pair of classes
 * for no readability gain (mirrors XapiCompletionHandler::handle()'s
 * precedent for @SuppressWarnings(PHPMD.CyclomaticComplexity) on a
 * similarly irreducible domain algorithm).
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ItemAnalysisService
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const ASSESSMENT_SCHEMA        = 'assessment';
    private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
    private const ITEM_SCHEMA = 'item';

    /**
     * Schema defaults, used when Assessment.itemAnalysisConfig is unset —
     * MUST match lib/Settings/scholiq_register.json's `default` values on
     * Assessment.itemAnalysisConfig's properties (design.md "Minimum-N thresholds").
     */
    private const DEFAULT_MIN_SAMPLE_SIZE = 20;
    private const DEFAULT_RELIABILITY_MIN_SAMPLE_SIZE = 30;
    private const DEFAULT_TOO_DIFFICULT_BELOW         = 0.20;
    private const DEFAULT_TOO_EASY_ABOVE           = 0.95;
    private const DEFAULT_LOW_DISCRIMINATION_BELOW = 0.10;

    /**
     * Kelley's 27% split for distractor-analysis upper/lower scoring groups.
     */
    private const DISTRACTOR_SPLIT_FRACTION = 0.27;

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
     * Compute ItemStatistics for one (itemId, assessmentId) pair from that
     * assessment's `graded` AssessmentResults.
     *
     * @param string $itemId       UUID of the Item.
     * @param string $assessmentId UUID of the Assessment.
     *
     * @return array{sampleSize: int, pValue: float|null, itemTotalCorrelation: float|null,
     *               distractorAnalysis: array|null, insufficientData: bool, computedAt: string}
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
     */
    public function computeItemStatistics(string $itemId, string $assessmentId): array
    {
        $assessment = $this->fetchOne(schema: self::ASSESSMENT_SCHEMA, uuid: $assessmentId);
        $tenantId   = $assessment['tenant_id'] ?? '';
        $config     = $this->resolveConfig(assessment: $assessment);

        $item = $this->fetchOne(schema: self::ITEM_SCHEMA, uuid: $itemId, tenantId: $tenantId);

        $gradedResults = $this->fetchGradedResults(assessmentId: $assessmentId, tenantId: $tenantId);
        $vectors       = $this->collectItemVectors(gradedResults: $gradedResults, itemId: $itemId, item: $item);

        $itemScores         = $vectors['itemScores'];
        $maxPointsList      = $vectors['maxPointsList'];
        $itemExcludedTotals = $vectors['itemExcludedTotals'];
        $totalScores        = $vectors['totalScores'];
        $selections         = $vectors['selections'];

        $sampleSize = count($itemScores);

        $statistics = [
            'sampleSize'           => $sampleSize,
            'pValue'               => null,
            'itemTotalCorrelation' => null,
            'distractorAnalysis'   => null,
            'insufficientData'     => true,
            'computedAt'           => (new DateTimeImmutable())->format(\DATE_ATOM),
        ];

        if ($sampleSize < $config['minSampleSize']) {
            return $statistics;
        }

        $statistics['insufficientData'] = false;
        $statistics['pValue']           = $this->computePValue(itemScores: $itemScores, maxPointsList: $maxPointsList);
        $statistics['itemTotalCorrelation'] = $this->pearsonCorrelation(vectorX: $itemScores, vectorY: $itemExcludedTotals);

        $isChoiceWithKey = ($item !== null)
            && (($item['interactionType'] ?? null) === 'choice')
            && (($item['correctResponse'] ?? null) !== null);

        if ($isChoiceWithKey === true) {
            $statistics['distractorAnalysis'] = $this->computeDistractorAnalysis(
                item: $item,
                selections: $selections,
                totalScores: $totalScores
            );
        }

        return $statistics;

    }//end computeItemStatistics()

    /**
     * Compute AssessmentReliability (Cronbach's alpha) for an Assessment from
     * its `graded` AssessmentResults.
     *
     * List-wise: only respondents who have a scored response for EVERY item
     * referenced anywhere across the assessment's graded results are included
     * — Cronbach's alpha requires a common item set per respondent.
     *
     * @param string $assessmentId UUID of the Assessment.
     *
     * @return array{sampleSize: int, itemCount: int, cronbachAlpha: float|null,
     *               insufficientData: bool, computedAt: string}
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
     */
    public function computeReliability(string $assessmentId): array
    {
        $assessment = $this->fetchOne(schema: self::ASSESSMENT_SCHEMA, uuid: $assessmentId);
        $tenantId   = $assessment['tenant_id'] ?? '';
        $config     = $this->resolveConfig(assessment: $assessment);

        $gradedResults = $this->fetchGradedResults(assessmentId: $assessmentId, tenantId: $tenantId);

        $itemIds   = $this->resolveScoredItemIds(gradedResults: $gradedResults);
        $itemCount = count($itemIds);

        $completeResults = $this->filterCompleteResults(gradedResults: $gradedResults, itemIds: $itemIds);
        $sampleSize      = count($completeResults);

        $reliability = [
            'sampleSize'       => $sampleSize,
            'itemCount'        => $itemCount,
            'cronbachAlpha'    => null,
            'insufficientData' => true,
            'computedAt'       => (new DateTimeImmutable())->format(\DATE_ATOM),
        ];

        if ($sampleSize < $config['reliabilityMinSampleSize'] || $itemCount < 2) {
            return $reliability;
        }

        $itemVariances = $this->computeItemVariances(completeResults: $completeResults, itemIds: $itemIds);
        $totalScores   = $this->computeTotalScores(completeResults: $completeResults, itemIds: $itemIds);
        $totalVariance = $this->sampleVariance(values: $totalScores);

        $reliability['insufficientData'] = false;

        if ($totalVariance <= 0.0) {
            // Undefined (no variance in total scores) — leave alpha null but
            // do NOT report insufficientData (the sample size gate was met).
            $reliability['cronbachAlpha'] = null;
            return $reliability;
        }

        $k = $itemCount;
        $reliability['cronbachAlpha'] = (($k / ($k - 1)) * (1 - (array_sum($itemVariances) / $totalVariance)));

        return $reliability;

    }//end computeReliability()

    /**
     * Collect every distinct itemId with a scored response somewhere across
     * a set of graded AssessmentResults.
     *
     * @param array<int,array<string,mixed>> $gradedResults Graded AssessmentResult rows.
     *
     * @return array<int,string>
     */
    private function resolveScoredItemIds(array $gradedResults): array
    {
        $itemIds = [];
        foreach ($gradedResults as $result) {
            foreach (($result['responses'] ?? []) as $response) {
                $itemId = $response['itemId'] ?? null;
                if ($itemId !== null
                    && ($response['manualScore'] ?? $response['autoScore'] ?? null) !== null
                ) {
                    $itemIds[$itemId] = true;
                }
            }
        }

        return array_keys($itemIds);

    }//end resolveScoredItemIds()

    /**
     * List-wise filter: keep only the results that carry a scored response
     * for EVERY given itemId — Cronbach's alpha requires a common item set
     * per respondent.
     *
     * @param array<int,array<string,mixed>> $gradedResults Graded AssessmentResult rows.
     * @param array<int,string>              $itemIds       Item ids that must all be scored.
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterCompleteResults(array $gradedResults, array $itemIds): array
    {
        return array_values(
            array_filter(
                $gradedResults,
                function (array $result) use ($itemIds) {
                    foreach ($itemIds as $itemId) {
                        if ($this->hasScoredResponse(result: $result, itemId: $itemId) === false) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );

    }//end filterCompleteResults()

    /**
     * Compute each item's sample-variance score vector across the complete-case results.
     *
     * @param array<int,array<string,mixed>> $completeResults List-wise-complete AssessmentResult rows.
     * @param array<int,string>              $itemIds         Item ids.
     *
     * @return array<int,float>
     */
    private function computeItemVariances(array $completeResults, array $itemIds): array
    {
        $itemVariances = [];
        foreach ($itemIds as $itemId) {
            $scores = [];
            foreach ($completeResults as $result) {
                $scores[] = $this->scoreForItem(result: $result, itemId: $itemId);
            }

            $itemVariances[] = $this->sampleVariance(values: $scores);
        }

        return $itemVariances;

    }//end computeItemVariances()

    /**
     * Compute each complete-case respondent's total score across the given items.
     *
     * @param array<int,array<string,mixed>> $completeResults List-wise-complete AssessmentResult rows.
     * @param array<int,string>              $itemIds         Item ids.
     *
     * @return array<int,float>
     */
    private function computeTotalScores(array $completeResults, array $itemIds): array
    {
        $totalScores = [];
        foreach ($completeResults as $result) {
            $sum = 0.0;
            foreach ($itemIds as $itemId) {
                $sum += $this->scoreForItem(result: $result, itemId: $itemId);
            }

            $totalScores[] = $sum;
        }

        return $totalScores;

    }//end computeTotalScores()

    /**
     * Resolve ItemAnalysisService's configuration for an Assessment, falling
     * back to the schema defaults (which MUST match
     * Assessment.itemAnalysisConfig's declared JSON Schema `default` values)
     * when Assessment.itemAnalysisConfig is unset.
     *
     * @param array<string,mixed>|null $assessment Assessment data, or null when unresolvable.
     *
     * @return array{minSampleSize: int, reliabilityMinSampleSize: int, tooDifficultyBelow: float,
     *               tooEasyAbove: float, lowDiscriminationBelow: float}
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
     */
    public function resolveConfig(?array $assessment): array
    {
        $raw = null;
        if (is_array($assessment) === true) {
            $raw = ($assessment['itemAnalysisConfig'] ?? null);
        }

        if (is_array($raw) === false) {
            $raw = [];
        }

        return [
            'minSampleSize'            => (int) ($raw['minSampleSize'] ?? self::DEFAULT_MIN_SAMPLE_SIZE),
            'reliabilityMinSampleSize' => (int) ($raw['reliabilityMinSampleSize'] ?? self::DEFAULT_RELIABILITY_MIN_SAMPLE_SIZE),
            'tooDifficultyBelow'       => (float) ($raw['tooDifficultyBelow'] ?? self::DEFAULT_TOO_DIFFICULT_BELOW),
            'tooEasyAbove'             => (float) ($raw['tooEasyAbove'] ?? self::DEFAULT_TOO_EASY_ABOVE),
            'lowDiscriminationBelow'   => (float) ($raw['lowDiscriminationBelow'] ?? self::DEFAULT_LOW_DISCRIMINATION_BELOW),
        ];

    }//end resolveConfig()

    /**
     * Collect the per-respondent vectors computeItemStatistics() needs
     * (item score, max points, item-excluded total, full total, selected
     * option) from every graded result that actually presented this item.
     *
     * @param array<int,array<string,mixed>> $gradedResults Graded AssessmentResult rows.
     * @param string                         $itemId        UUID of the item.
     * @param array<string,mixed>|null       $item          Item data, or null when unresolvable.
     *
     * @return array{itemScores: array<int,float>, maxPointsList: array<int,float>,
     *               itemExcludedTotals: array<int,float>, totalScores: array<int,float>,
     *               selections: array<int,string|null>}
     */
    private function collectItemVectors(array $gradedResults, string $itemId, ?array $item): array
    {
        $vectors = [
            'itemScores'         => [],
            'maxPointsList'      => [],
            'itemExcludedTotals' => [],
            'totalScores'        => [],
            'selections'         => [],
        ];

        foreach ($gradedResults as $result) {
            $responses = $result['responses'] ?? [];
            $response  = $this->findResponseForItem(responses: $responses, itemId: $itemId);

            if ($response === null) {
                // This attempt never presented this item (e.g. a different
                // random-draw set), or the response is not yet scored (e.g.
                // essay awaiting manual grading — should not occur on a
                // `graded` result, but skip defensively either way).
                continue;
            }

            $itemScore  = (float) ($response['manualScore'] ?? $response['autoScore']);
            $totalScore = $this->sumResponseScores(responses: $responses);

            $vectors['itemScores'][]         = $itemScore;
            $vectors['itemExcludedTotals'][] = ($totalScore - $itemScore);
            $vectors['totalScores'][]        = $totalScore;
            $vectors['maxPointsList'][]      = (float) $this->resolveMaxPoints(result: $result, itemId: $itemId, item: $item);

            $selectedOption = null;
            $responseValue  = $response['response']['value'] ?? null;
            if (is_string($responseValue) === true) {
                $selectedOption = $responseValue;
            }

            $vectors['selections'][] = $selectedOption;
        }//end foreach

        return $vectors;

    }//end collectItemVectors()

    /**
     * Find the scored response for one item within one result's responses[],
     * or null when the item was not presented or is not yet scored.
     *
     * @param array<int,array<string,mixed>> $responses AssessmentResult.responses[].
     * @param string                         $itemId    UUID of the item.
     *
     * @return array<string,mixed>|null
     */
    private function findResponseForItem(array $responses, string $itemId): ?array
    {
        foreach ($responses as $candidate) {
            if (($candidate['itemId'] ?? null) !== $itemId) {
                continue;
            }

            if (($candidate['manualScore'] ?? $candidate['autoScore'] ?? null) === null) {
                return null;
            }

            return $candidate;
        }

        return null;

    }//end findResponseForItem()

    /**
     * Sum every scored response's value within one result's responses[].
     *
     * @param array<int,array<string,mixed>> $responses AssessmentResult.responses[].
     *
     * @return float
     */
    private function sumResponseScores(array $responses): float
    {
        $total = 0.0;
        foreach ($responses as $candidate) {
            $score = $candidate['manualScore'] ?? $candidate['autoScore'] ?? null;
            if ($score !== null) {
                $total += (float) $score;
            }
        }

        return $total;

    }//end sumResponseScores()

    /**
     * Resolve the max points an item was worth in one specific attempt:
     * prefers the attempt's own drawnItemRefs[].points snapshot (the frozen
     * ground truth, correct even if the Assessment/Item changed later), and
     * falls back to the Item's maxScore when no matching drawnItemRefs entry
     * exists (pre-existing results created before this change shipped).
     *
     * @param array<string,mixed>      $result AssessmentResult data.
     * @param string                   $itemId UUID of the item.
     * @param array<string,mixed>|null $item   Item data, or null when unresolvable.
     *
     * @return float
     */
    private function resolveMaxPoints(array $result, string $itemId, ?array $item): float
    {
        foreach (($result['drawnItemRefs'] ?? []) as $ref) {
            if (($ref['itemId'] ?? null) === $itemId) {
                return (float) ($ref['points'] ?? 0);
            }
        }

        return (float) ($item['maxScore'] ?? 0);

    }//end resolveMaxPoints()

    /**
     * Proportion of respondents who scored full marks on the item.
     *
     * @param array<int,float> $itemScores    Per-respondent item scores.
     * @param array<int,float> $maxPointsList Per-respondent max points for the item (same index order).
     *
     * @return float
     */
    private function computePValue(array $itemScores, array $maxPointsList): float
    {
        $n = count($itemScores);
        if ($n === 0) {
            return 0.0;
        }

        $fullMarksCount = 0;
        foreach ($itemScores as $index => $score) {
            $max = $maxPointsList[$index] ?? 0.0;
            if ($max > 0.0 && $score >= ($max - 1.0e-9)) {
                $fullMarksCount++;
            }
        }

        return ($fullMarksCount / $n);

    }//end computePValue()

    /**
     * Pearson product-moment correlation coefficient.
     *
     * @param array<int,float> $vectorX First vector.
     * @param array<int,float> $vectorY Second vector (same length/order as $vectorX).
     *
     * @return float 0.0 when either vector has zero variance (correlation is
     *               mathematically undefined in that case; 0.0 — "no
     *               detectable relationship" — is the least-misleading value
     *               to surface rather than NAN).
     */
    private function pearsonCorrelation(array $vectorX, array $vectorY): float
    {
        $n = count($vectorX);
        if ($n < 2 || $n !== count($vectorY)) {
            return 0.0;
        }

        $meanX = (array_sum($vectorX) / $n);
        $meanY = (array_sum($vectorY) / $n);

        $numerator = 0.0;
        $denomX    = 0.0;
        $denomY    = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $deviationX = ($vectorX[$i] - $meanX);
            $deviationY = ($vectorY[$i] - $meanY);
            $numerator += ($deviationX * $deviationY);
            $denomX    += ($deviationX * $deviationX);
            $denomY    += ($deviationY * $deviationY);
        }

        if ($denomX <= 0.0 || $denomY <= 0.0) {
            return 0.0;
        }

        return ($numerator / sqrt($denomX * $denomY));

    }//end pearsonCorrelation()

    /**
     * Kelley 27%-split distractor analysis: for every declared answer option,
     * count how many top-27%-by-total-score and bottom-27%-by-total-score
     * respondents selected it.
     *
     * @param array<string,mixed>|null $item        Item data (qtiBody).
     * @param array<int,string|null>   $selections  Per-respondent selected option identifier (or null).
     * @param array<int,float>         $totalScores Per-respondent total score (same index order as $selections).
     *
     * @return array<int,array{optionId:string,selectedByHighGroup:int,selectedByLowGroup:int}>|null
     */
    private function computeDistractorAnalysis(?array $item, array $selections, array $totalScores): ?array
    {
        $optionIds = $this->extractChoiceIdentifiers(qtiBody: (string) ($item['qtiBody'] ?? ''));
        if (empty($optionIds) === true) {
            return null;
        }

        $n = count($totalScores);
        if ($n === 0) {
            return null;
        }

        $indices = range(0, ($n - 1));
        usort($indices, static fn (int $a, int $b) => ($totalScores[$b] <=> $totalScores[$a]));

        $groupSize = (int) round($n * self::DISTRACTOR_SPLIT_FRACTION);
        $groupSize = max(1, $groupSize);
        $groupSize = min($groupSize, (int) floor($n / 2));
        if ($groupSize < 1) {
            $groupSize = 1;
        }

        $highIndices = array_slice($indices, 0, $groupSize);
        $lowIndices  = array_slice($indices, -$groupSize);

        $result = [];
        foreach ($optionIds as $optionId) {
            $highCount = 0;
            foreach ($highIndices as $index) {
                if (($selections[$index] ?? null) === $optionId) {
                    $highCount++;
                }
            }

            $lowCount = 0;
            foreach ($lowIndices as $index) {
                if (($selections[$index] ?? null) === $optionId) {
                    $lowCount++;
                }
            }

            $result[] = [
                'optionId'            => $optionId,
                'selectedByHighGroup' => $highCount,
                'selectedByLowGroup'  => $lowCount,
            ];
        }//end foreach

        return $result;

    }//end computeDistractorAnalysis()

    /**
     * Extract QTI simpleChoice `identifier` values, in declared order.
     *
     * @param string $qtiBody Raw QTI 3.0 XML body.
     *
     * @return array<int,string>
     */
    private function extractChoiceIdentifiers(string $qtiBody): array
    {
        if (trim($qtiBody) === '') {
            return [];
        }

        $previousSetting = libxml_use_internal_errors(true);
        $doc    = new DOMDocument();
        $loaded = $doc->loadXML($qtiBody);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($loaded === false) {
            return [];
        }

        $identifiers = [];
        foreach ($doc->getElementsByTagName('simpleChoice') as $node) {
            $identifier = $node->getAttribute('identifier');
            if ($identifier !== '') {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;

    }//end extractChoiceIdentifiers()

    /**
     * Sample variance (N-1 denominator) — the conventional Cronbach's-alpha convention.
     *
     * @param array<int,float> $values Values.
     *
     * @return float
     */
    private function sampleVariance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean  = (array_sum($values) / $n);
        $sumSq = 0.0;
        foreach ($values as $value) {
            $sumSq += (($value - $mean) ** 2);
        }

        return ($sumSq / ($n - 1));

    }//end sampleVariance()

    /**
     * Whether an AssessmentResult has a non-null (auto or manual) score for the given item.
     *
     * @param array<string,mixed> $result AssessmentResult data.
     * @param string              $itemId UUID of the item.
     *
     * @return bool
     */
    private function hasScoredResponse(array $result, string $itemId): bool
    {
        foreach (($result['responses'] ?? []) as $response) {
            if (($response['itemId'] ?? null) === $itemId) {
                return (($response['manualScore'] ?? $response['autoScore'] ?? null) !== null);
            }
        }

        return false;

    }//end hasScoredResponse()

    /**
     * The scored value (manualScore ?? autoScore ?? 0.0) an AssessmentResult
     * carries for one item.
     *
     * @param array<string,mixed> $result AssessmentResult data.
     * @param string              $itemId UUID of the item.
     *
     * @return float
     */
    private function scoreForItem(array $result, string $itemId): float
    {
        foreach (($result['responses'] ?? []) as $response) {
            if (($response['itemId'] ?? null) === $itemId) {
                return (float) ($response['manualScore'] ?? $response['autoScore'] ?? 0.0);
            }
        }

        return 0.0;

    }//end scoreForItem()

    /**
     * Fetch every `graded` AssessmentResult for an Assessment.
     *
     * @param string $assessmentId UUID of the Assessment.
     * @param string $tenantId     Tenant ID, or '' to skip tenant scoping.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchGradedResults(string $assessmentId, string $tenantId): array
    {
        $filters = ['assessmentId' => $assessmentId, 'lifecycle' => 'graded'];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ASSESSMENT_RESULT_SCHEMA,
                'filters'  => $filters,
            ]
        );

        return array_map(
            static function ($result) {
                if (is_array($result) === true) {
                    return $result;
                }

                return $result->jsonSerialize();
            },
            $results
        );

    }//end fetchGradedResults()

    /**
     * Fetch a single object by uuid, optionally scoped to a tenant.
     *
     * @param string $schema   OR schema slug.
     * @param string $uuid     Object UUID.
     * @param string $tenantId Tenant ID, or '' to skip tenant scoping.
     *
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $schema, string $uuid, string $tenantId=''): ?array
    {
        $filters = ['uuid' => $uuid];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $matches = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($matches) === true) {
            return null;
        }

        $match = $matches[0];
        if (is_array($match) === true) {
            return $match;
        }

        return $match->jsonSerialize();

    }//end fetchOne()
}//end class
