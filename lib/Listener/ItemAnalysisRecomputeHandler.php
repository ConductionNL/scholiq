<?php

/**
 * Scholiq Item Analysis Recompute Handler
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration — mirrors GradeRollupHandler's role for
 * FinalGrade and BsaProgressFlagHandler's combined evaluate-then-flag shape.
 * The statistics arithmetic itself (p-value, corrected item-total
 * correlation, distractor analysis, Cronbach's alpha) lives entirely in
 * ItemAnalysisService — this class is only the event-driven bridge: load,
 * call the service, upsert, and evaluate quality thresholds.
 *
 * Listens for the SAME ObjectTransitionedEvent<AssessmentResult, graded>
 * GradeRollupHandler already reacts to (a sibling listener, not an edit to
 * that class — mirrors LessonProgressHandler/EngagementSignalHandler's
 * "independently reacts to the same event" convention).
 *
 * On fire:
 *   1. Loads every `graded` AssessmentResult for the transitioned result's
 *      assessmentId.
 *   2. For each itemId referenced across those results' responses[], calls
 *      ItemAnalysisService::computeItemStatistics() and upserts the
 *      (itemId, assessmentId) ItemStatistics row — no lifecycle, fully
 *      derived, "do not set manually" (the FinalGrade precedent).
 *   3. Calls ItemAnalysisService::computeReliability() and upserts the
 *      assessment's AssessmentReliability row.
 *   4. For each ItemStatistics row with insufficientData false that crosses
 *      a configured quality threshold, creates a deduplicated (per itemId +
 *      reason, while still open/acknowledged) ItemRevisionFlag — a review
 *      signal ONLY. This handler NEVER mutates the flagged Item.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\ItemAnalysisService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes ItemStatistics/AssessmentReliability and opens ItemRevisionFlags
 * whenever an AssessmentResult reaches `graded`.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board
 */
class ItemAnalysisRecomputeHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const ASSESSMENT_SCHEMA        = 'assessment';
    private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
    private const ITEM_STATISTICS_SCHEMA   = 'item-statistics';
    private const ASSESSMENT_RELIABILITY_SCHEMA = 'assessment-reliability';
    private const ITEM_REVISION_FLAG_SCHEMA     = 'item-revision-flag';

    /**
     * ItemRevisionFlag lifecycle states that count as "still open" for
     * (itemId, reason) idempotency purposes.
     *
     * @var string[]
     */
    private const OPEN_FLAG_STATES = ['open', 'acknowledged'];

    /**
     * Constructor.
     *
     * @param ObjectService       $objectService       OR object access.
     * @param ItemAnalysisService $itemAnalysisService CTT statistics calculation engine.
     * @param ITimeFactory        $timeFactory         NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ItemAnalysisService $itemAnalysisService,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::ASSESSMENT_RESULT_SCHEMA || $event->getTo() !== 'graded') {
            return;
        }

        $result       = $event->getObject()->jsonSerialize();
        $assessmentId = $result['assessmentId'] ?? null;

        if ($assessmentId === null) {
            return;
        }

        $tenantId = $result['tenant_id'] ?? '';

        $assessment = $this->fetchOne(schema: self::ASSESSMENT_SCHEMA, uuid: $assessmentId, tenantId: $tenantId);
        $config     = $this->itemAnalysisService->resolveConfig(assessment: $assessment);

        $gradedResults = $this->fetchGradedResults(assessmentId: $assessmentId, tenantId: $tenantId);
        $itemIds       = $this->resolveReferencedItemIds(gradedResults: $gradedResults);

        $this->recomputeItemStatistics(itemIds: $itemIds, assessmentId: $assessmentId, tenantId: $tenantId, config: $config);

        $reliability = $this->itemAnalysisService->computeReliability(assessmentId: $assessmentId);
        $this->upsertAssessmentReliability(assessmentId: $assessmentId, tenantId: $tenantId, reliability: $reliability);

    }//end handle()

    /**
     * Collect every distinct itemId referenced across a set of
     * AssessmentResults' responses[].
     *
     * @param array<int,array<string,mixed>> $gradedResults Graded AssessmentResult rows.
     *
     * @return array<int,string>
     */
    private function resolveReferencedItemIds(array $gradedResults): array
    {
        $itemIds = [];
        foreach ($gradedResults as $gradedResult) {
            foreach (($gradedResult['responses'] ?? []) as $response) {
                $itemId = $response['itemId'] ?? null;
                if ($itemId !== null) {
                    $itemIds[$itemId] = true;
                }
            }
        }

        return array_keys($itemIds);

    }//end resolveReferencedItemIds()

    /**
     * Recompute, upsert, and threshold-evaluate ItemStatistics for every
     * given itemId.
     *
     * @param array<int,string>       $itemIds      Item UUIDs referenced by this assessment's graded results.
     * @param string                  $assessmentId UUID of the Assessment.
     * @param string                  $tenantId     Tenant ID.
     * @param array<string,int|float> $config       ItemAnalysisService::resolveConfig() result.
     *
     * @return void
     */
    private function recomputeItemStatistics(array $itemIds, string $assessmentId, string $tenantId, array $config): void
    {
        foreach ($itemIds as $itemId) {
            $statistics = $this->itemAnalysisService->computeItemStatistics(itemId: $itemId, assessmentId: $assessmentId);
            $savedId    = $this->upsertItemStatistics(
                itemId: $itemId,
                assessmentId: $assessmentId,
                tenantId: $tenantId,
                statistics: $statistics
            );

            if ($statistics['insufficientData'] === false) {
                $this->evaluateThresholds(
                    itemId: $itemId,
                    itemStatisticsId: $savedId,
                    tenantId: $tenantId,
                    statistics: $statistics,
                    config: $config
                );
            }
        }

    }//end recomputeItemStatistics()

    /**
     * Upsert the (itemId, assessmentId) ItemStatistics row.
     *
     * @param string              $itemId       UUID of the Item.
     * @param string              $assessmentId UUID of the Assessment.
     * @param string              $tenantId     Tenant ID.
     * @param array<string,mixed> $statistics   ItemAnalysisService::computeItemStatistics() result.
     *
     * @return string|null The saved row's id/uuid, or null when the save did not return one.
     */
    private function upsertItemStatistics(string $itemId, string $assessmentId, string $tenantId, array $statistics): ?string
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ITEM_STATISTICS_SCHEMA,
                'filters'  => ['itemId' => $itemId, 'assessmentId' => $assessmentId],
                'limit'    => 1,
            ]
        );

        $existingData = [];
        if (empty($existing) === false) {
            $existingData = $this->toArrayData(object: $existing[0]);
        }

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ITEM_STATISTICS_SCHEMA,
            object: array_merge(
                $existingData,
                [
                    'itemId'               => $itemId,
                    'assessmentId'         => $assessmentId,
                    'sampleSize'           => $statistics['sampleSize'],
                    'pValue'               => $statistics['pValue'],
                    'itemTotalCorrelation' => $statistics['itemTotalCorrelation'],
                    'distractorAnalysis'   => $statistics['distractorAnalysis'],
                    'insufficientData'     => $statistics['insufficientData'],
                    'computedAt'           => $statistics['computedAt'],
                    'tenant_id'            => $tenantId,
                ]
            )
        );

        if ($saved === null) {
            return null;
        }

        $savedData = $this->toArrayData(object: $saved);

        return ($savedData['id'] ?? ($savedData['uuid'] ?? null));

    }//end upsertItemStatistics()

    /**
     * Upsert the AssessmentReliability row for an Assessment.
     *
     * @param string              $assessmentId UUID of the Assessment.
     * @param string              $tenantId     Tenant ID.
     * @param array<string,mixed> $reliability  ItemAnalysisService::computeReliability() result.
     *
     * @return void
     */
    private function upsertAssessmentReliability(string $assessmentId, string $tenantId, array $reliability): void
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ASSESSMENT_RELIABILITY_SCHEMA,
                'filters'  => ['assessmentId' => $assessmentId],
                'limit'    => 1,
            ]
        );

        $existingData = [];
        if (empty($existing) === false) {
            $existingData = $this->toArrayData(object: $existing[0]);
        }

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ASSESSMENT_RELIABILITY_SCHEMA,
            object: array_merge(
                $existingData,
                [
                    'assessmentId'     => $assessmentId,
                    'sampleSize'       => $reliability['sampleSize'],
                    'itemCount'        => $reliability['itemCount'],
                    'cronbachAlpha'    => $reliability['cronbachAlpha'],
                    'insufficientData' => $reliability['insufficientData'],
                    'computedAt'       => $reliability['computedAt'],
                    'tenant_id'        => $tenantId,
                ]
            )
        );

    }//end upsertAssessmentReliability()

    /**
     * Evaluate an ItemStatistics row against the configured quality
     * thresholds and open a deduplicated ItemRevisionFlag per crossed
     * threshold. NEVER mutates the flagged Item.
     *
     * @param string                  $itemId           UUID of the Item.
     * @param string|null             $itemStatisticsId UUID of the triggering ItemStatistics row.
     * @param string                  $tenantId         Tenant ID.
     * @param array<string,mixed>     $statistics       ItemAnalysisService::computeItemStatistics() result.
     * @param array<string,int|float> $config           ItemAnalysisService::resolveConfig() result.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board
     */
    private function evaluateThresholds(
        string $itemId,
        ?string $itemStatisticsId,
        string $tenantId,
        array $statistics,
        array $config,
    ): void {
        $pValue = $statistics['pValue'];
        $itemTotalCorrelation = $statistics['itemTotalCorrelation'];
        $reasons = $this->determineFlagReasons(
            pValue: $pValue,
            itemTotalCorrelation: $itemTotalCorrelation,
            config: $config
        );

        foreach ($reasons as $reason) {
            $this->createFlagIfNotOpen(
                itemId: $itemId,
                itemStatisticsId: $itemStatisticsId,
                tenantId: $tenantId,
                reason: $reason,
                pValue: $pValue,
                itemTotalCorrelation: $itemTotalCorrelation
            );
        }

    }//end evaluateThresholds()

    /**
     * Determine which quality-threshold reasons (if any) an ItemStatistics
     * computation crosses.
     *
     * @param float|null              $pValue               ItemStatistics.pValue.
     * @param float|null              $itemTotalCorrelation ItemStatistics.itemTotalCorrelation.
     * @param array<string,int|float> $config               ItemAnalysisService::resolveConfig() result.
     *
     * @return array<int,string>
     */
    private function determineFlagReasons(?float $pValue, ?float $itemTotalCorrelation, array $config): array
    {
        $reasons = [];

        if ($pValue !== null && $pValue < $config['tooDifficultyBelow']) {
            $reasons[] = 'too-difficult';
        } else if ($pValue !== null && $pValue > $config['tooEasyAbove']) {
            $reasons[] = 'too-easy';
        }

        if ($itemTotalCorrelation !== null && $itemTotalCorrelation < 0) {
            $reasons[] = 'negative-discrimination';
        } else if ($itemTotalCorrelation !== null && $itemTotalCorrelation < $config['lowDiscriminationBelow']) {
            $reasons[] = 'low-discrimination';
        }

        return $reasons;

    }//end determineFlagReasons()

    /**
     * Create an ItemRevisionFlag for one (itemId, reason), unless a
     * still-open one already exists.
     *
     * @param string      $itemId               UUID of the Item.
     * @param string|null $itemStatisticsId     UUID of the triggering ItemStatistics row.
     * @param string      $tenantId             Tenant ID.
     * @param string      $reason               Threshold reason.
     * @param float|null  $pValue               Snapshot of ItemStatistics.pValue.
     * @param float|null  $itemTotalCorrelation Snapshot of ItemStatistics.itemTotalCorrelation.
     *
     * @return void
     */
    private function createFlagIfNotOpen(
        string $itemId,
        ?string $itemStatisticsId,
        string $tenantId,
        string $reason,
        ?float $pValue,
        ?float $itemTotalCorrelation,
    ): void {
        if ($this->hasOpenFlag(itemId: $itemId, reason: $reason) === true) {
            return;
        }

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ITEM_REVISION_FLAG_SCHEMA,
            object: [
                'itemId'                     => $itemId,
                'itemStatisticsId'           => $itemStatisticsId,
                'reason'                     => $reason,
                'pValueAtFlag'               => $pValue,
                'itemTotalCorrelationAtFlag' => $itemTotalCorrelation,
                'flaggedAt'                  => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
                'lifecycle'                  => 'open',
                'tenant_id'                  => $tenantId,
            ]
        );

    }//end createFlagIfNotOpen()

    /**
     * Whether a still-open ItemRevisionFlag already exists for (itemId, reason).
     *
     * @param string $itemId UUID of the Item.
     * @param string $reason Threshold reason.
     *
     * @return bool
     */
    private function hasOpenFlag(string $itemId, string $reason): bool
    {
        foreach (self::OPEN_FLAG_STATES as $state) {
            $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::ITEM_REVISION_FLAG_SCHEMA,
                    'filters'  => ['itemId' => $itemId, 'reason' => $reason, 'lifecycle' => $state],
                    'limit'    => 1,
                ]
            );

            if (empty($existing) === false) {
                return true;
            }
        }

        return false;

    }//end hasOpenFlag()

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
            fn ($result) => $this->toArrayData(object: $result),
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

        return $this->toArrayData(object: $matches[0]);

    }//end fetchOne()

    /**
     * Normalize an OR findAll()/saveObject() result entry (a raw array or an
     * ObjectEntity-like object) to a plain array.
     *
     * @param mixed $object Raw array or an object exposing jsonSerialize().
     *
     * @return array<string,mixed>
     */
    private function toArrayData(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end toArrayData()
}//end class
