<?php

/**
 * Scholiq Assessment Draw Resolver
 *
 * ADR-031 legitimate exception: server-side randomness/shuffle resolution
 * cannot be client-supplied — the exact trust boundary AssessmentScoringHandler
 * already defends for autoScore (lib/Lifecycle/AssessmentScoringHandler.php).
 * `TakeAssessmentView.vue`'s `createResult()` POSTs an AssessmentResult
 * straight to OR's generic object-create endpoint (ADR-022: no pass-through
 * controller for a capability OR's object API already serves) — the only
 * server-side seam available is an OR object lifecycle event, exactly like the
 * six other ObjectCreatedEvent/ObjectTransitionedEvent listeners registered in
 * lib/AppInfo/Application.php.
 *
 * Listens for OR's ObjectCreatedEvent, filtered to schema `assessment-result`.
 * On fire, resolves and persists `AssessmentResult.drawnItemRefs` — the
 * concrete, frozen set/order/answer-option-order this attempt presents:
 *   - itemSelectionMode `fixed`: starts from Assessment.itemRefs, unchanged.
 *   - itemSelectionMode `random-draw`: draws itemPoolConfig.drawCount
 *     `published` Items from the referenced ItemBank, filtered by
 *     subjectTags/difficulty, with at most one Item per variantGroupId.
 *   - shuffleItemOrder / shuffleAnswerOptions (independent of selection mode)
 *     permute the resolved list / each item's QTI simpleChoice identifiers
 *     (respecting the QTI `fixed` attribute on individual choices).
 * Populated for EVERY attempt, not only random-draw ones, so an appeal/
 * exam-board review can always reconstruct exactly what the learner saw
 * (design.md "Why drawnItemRefs is populated for every attempt"). Written
 * once; never recomputed by any later process. Fails closed (logs, leaves
 * drawnItemRefs at its default `[]`) when the pool cannot supply drawCount
 * distinct variant groups — should not happen given the extended
 * AssessmentPublishGuard, but a bank can shrink after publish.
 *
 * Randomness uses `random_int()` (cryptographically-strong PHP RNG, not
 * Mersenne-Twister `shuffle()`/`array_rand()`) for both the draw and the
 * Fisher-Yates permutations — see "Determinism" in design.md: no seed is
 * persisted, `drawnItemRefs` itself is the ground truth.
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\ItemPoolFilter;
use OCA\Scholiq\Service\QtiChoiceOrderResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Resolves and persists AssessmentResult.drawnItemRefs on creation.
 *
 * QTI simpleChoice parsing/permutation is delegated to QtiChoiceOrderResolver
 * and pool filter/variant-grouping to ItemPoolFilter (both dependency-free
 * collaborators, DI-autowired) purely to keep this class's own complexity
 * within this app's PHPMD budget.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
 */
class AssessmentDrawResolver implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';
    private const ASSESSMENT_SCHEMA        = 'assessment';
    private const ITEM_SCHEMA = 'item';

    /**
     * Constructor.
     *
     * @param ObjectService          $objectService  OR object service for Assessment/Item lookups and the follow-up save.
     * @param QtiChoiceOrderResolver $choiceResolver QTI simpleChoice parsing/permutation collaborator.
     * @param ItemPoolFilter         $poolFilter     Pool filter/variant-grouping collaborator.
     * @param LoggerInterface        $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly QtiChoiceOrderResolver $choiceResolver,
        private readonly ItemPoolFilter $poolFilter,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an incoming ObjectCreatedEvent.
     *
     * @param Event $event The dispatched event from OR.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false) {
            return;
        }

        $objectEntity = $event->getObject();

        if ($objectEntity->getRegister() !== self::SCHOLIQ_REGISTER
            || $objectEntity->getSchema() !== self::ASSESSMENT_RESULT_SCHEMA
        ) {
            return;
        }

        $result       = $objectEntity->jsonSerialize();
        $assessmentId = $result['assessmentId'] ?? null;
        $tenantId     = $result['tenant_id'] ?? '';

        if ($assessmentId === null) {
            return;
        }

        $assessment = $this->fetchOne(schema: self::ASSESSMENT_SCHEMA, uuid: $assessmentId, tenantId: $tenantId);
        if ($assessment === null) {
            // Fail-CLOSED: parent Assessment unreachable — leave drawnItemRefs at its
            // default [] rather than trusting anything client-supplied.
            $this->logger->warning(
                '[AssessmentDrawResolver] Assessment {id} not found or out-of-tenant; leaving drawnItemRefs empty (fail-closed).',
                ['id' => $assessmentId]
            );
            return;
        }

        $itemSequence = $this->resolveItemSequence(assessment: $assessment, tenantId: $tenantId);
        if ($itemSequence === null) {
            $this->logger->error(
                '[AssessmentDrawResolver] Item pool for Assessment {id} cannot supply the configured '
                .'drawCount across distinct variant groups; leaving drawnItemRefs empty (fail-closed).',
                ['id' => $assessmentId]
            );
            return;
        }

        $drawnItemRefs = $this->buildDrawnItemRefs(
            itemSequence: $itemSequence,
            shuffleAnswerOptions: (($assessment['shuffleAnswerOptions'] ?? false) === true),
            tenantId: $tenantId
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ASSESSMENT_RESULT_SCHEMA,
            object: array_merge($result, ['drawnItemRefs' => $drawnItemRefs])
        );

        $this->logger->info(
            '[AssessmentDrawResolver] Resolved {count} drawnItemRefs for AssessmentResult on Assessment {id}.',
            ['count' => count($drawnItemRefs), 'id' => $assessmentId]
        );

    }//end handle()

    /**
     * Resolve the ordered item-id sequence and any per-item points overrides
     * for this attempt: `random-draw` delegates to resolveRandomDraw();
     * `fixed` reads Assessment.itemRefs in declared order. shuffleItemOrder
     * (independent of selection mode) permutes the resulting sequence.
     *
     * @param array<string,mixed> $assessment Assessment data.
     * @param string              $tenantId   Tenant ID to scope the random-draw Item lookup.
     *
     * @return array{itemIds: array<int,string>, pointsOverride: array<string,mixed>}|null
     *               Null when random-draw fails closed (pool cannot supply drawCount).
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
     */
    private function resolveItemSequence(array $assessment, string $tenantId): ?array
    {
        $shuffleItemOrder = (($assessment['shuffleItemOrder'] ?? false) === true);

        if (($assessment['itemSelectionMode'] ?? 'fixed') === 'random-draw') {
            $itemIds = $this->resolveRandomDraw(assessment: $assessment, tenantId: $tenantId);
            if ($itemIds === null) {
                return null;
            }

            if ($shuffleItemOrder === true) {
                $itemIds = $this->secureShuffle(items: $itemIds);
            }

            return ['itemIds' => $itemIds, 'pointsOverride' => []];
        }

        $itemIds        = [];
        $pointsOverride = [];
        foreach (($assessment['itemRefs'] ?? []) as $itemRef) {
            $itemId = $itemRef['itemId'] ?? null;
            if ($itemId === null) {
                continue;
            }

            $itemIds[] = $itemId;
            $pointsOverride[$itemId] = $itemRef['points'] ?? null;
        }

        if ($shuffleItemOrder === true) {
            $itemIds = $this->secureShuffle(items: $itemIds);
        }

        return ['itemIds' => $itemIds, 'pointsOverride' => $pointsOverride];

    }//end resolveItemSequence()

    /**
     * Resolve each item id to a concrete drawnItemRefs entry (points +
     * optional shuffled optionOrder), skipping any item that has become
     * unreachable since resolution (out-of-tenant / deleted between publish
     * and attempt).
     *
     * @param array<string,mixed> $itemSequence         resolveItemSequence()'s {itemIds, pointsOverride} result.
     * @param bool                $shuffleAnswerOptions Whether to resolve a permuted optionOrder per item.
     * @param string              $tenantId             Tenant ID to scope the Item lookup.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildDrawnItemRefs(array $itemSequence, bool $shuffleAnswerOptions, string $tenantId): array
    {
        $drawnItemRefs = [];

        foreach ($itemSequence['itemIds'] as $itemId) {
            $item = $this->fetchOne(schema: self::ITEM_SCHEMA, uuid: $itemId, tenantId: $tenantId);
            if ($item === null) {
                continue;
            }

            $optionOrder = null;
            if ($shuffleAnswerOptions === true) {
                $optionOrder = $this->choiceResolver->resolveOrder(item: $item);
            }

            $drawnItemRefs[] = [
                'itemId'      => $itemId,
                'points'      => ($itemSequence['pointsOverride'][$itemId] ?? ($item['maxScore'] ?? 0)),
                'optionOrder' => $optionOrder,
            ];
        }

        return $drawnItemRefs;

    }//end buildDrawnItemRefs()

    /**
     * Resolve a random draw: filter published Items in the configured ItemBank
     * by subjectTags/difficulty, group by variantGroupId (an Item with no
     * variantGroupId is its own singleton group), draw drawCount distinct
     * groups via a cryptographically-strong RNG, then pick one Item per drawn
     * group.
     *
     * @param array<string,mixed> $assessment Assessment data (itemPoolConfig).
     * @param string              $tenantId   Tenant ID to scope the Item lookup.
     *
     * @return array<int,string>|null Ordered list of drawn Item UUIDs, or null
     *                                when the pool cannot supply drawCount
     *                                distinct variant groups (fail-closed).
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
     */
    private function resolveRandomDraw(array $assessment, string $tenantId): ?array
    {
        $poolConfig = $assessment['itemPoolConfig'] ?? null;
        if (is_array($poolConfig) === false) {
            return null;
        }

        $itemBankId = $poolConfig['itemBankId'] ?? null;
        $drawCount  = (int) ($poolConfig['drawCount'] ?? 0);
        if ($itemBankId === null || $drawCount < 1) {
            return null;
        }

        $filters = ['itemBankId' => $itemBankId, 'lifecycle' => 'published'];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $items = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ITEM_SCHEMA,
                'filters'  => $filters,
            ]
        );

        $groups = $this->poolFilter->filterAndGroupByVariant(items: $items, poolConfig: $poolConfig);

        if (count($groups) < $drawCount) {
            // Not enough DISTINCT variant groups to satisfy drawCount — fail closed.
            return null;
        }

        $drawnGroupKeys = array_slice($this->secureShuffle(items: array_keys($groups)), 0, $drawCount);

        $drawn = [];
        foreach ($drawnGroupKeys as $groupKey) {
            $candidates = $groups[$groupKey];
            $pickIndex  = 0;
            if (count($candidates) > 1) {
                $pickIndex = random_int(0, count($candidates) - 1);
            }

            $drawn[] = $candidates[$pickIndex];
        }

        return $drawn;

    }//end resolveRandomDraw()

    /**
     * Fisher-Yates shuffle using `random_int()` (cryptographically-strong,
     * unlike Mersenne-Twister-backed `shuffle()`/`array_rand()`).
     *
     * @param array<int,mixed> $items Items to permute.
     *
     * @return array<int,mixed> A new, permuted, re-indexed array.
     */
    private function secureShuffle(array $items): array
    {
        $items = array_values($items);
        $count = count($items);

        for ($i = ($count - 1); $i > 0; $i--) {
            $swapIndex = random_int(0, $i);
            [$items[$i], $items[$swapIndex]] = [$items[$swapIndex], $items[$i]];
        }

        return $items;

    }//end secureShuffle()

    /**
     * Fetch a single object by uuid, scoped to the given tenant when one is set.
     *
     * @param string $schema   OR schema slug.
     * @param string $uuid     Object UUID.
     * @param string $tenantId Tenant ID, or '' to skip tenant scoping.
     *
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $schema, string $uuid, string $tenantId): ?array
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
