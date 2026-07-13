<?php

/**
 * Scholiq Item Pool Filter
 *
 * Stateless helper: filters a candidate Item list by an
 * `Assessment.itemPoolConfig`'s `subjectTags`/`difficulty` filters and
 * groups the survivors by `variantGroupId` (an Item with no `variantGroupId`
 * is its own singleton group) — the exclusivity grouping
 * `AssessmentDrawResolver` draws from and `AssessmentPublishGuard` counts
 * against `drawCount` at publish time. Extracted out of AssessmentDrawResolver
 * purely to keep that class's own complexity within this app's PHPMD budget;
 * it carries no dependencies of its own (constructor-injected via Nextcloud's
 * DI autowiring).
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata"
 * — filtering/grouping by a declarative but cross-cutting rule (variant-group
 * exclusivity across an arbitrary candidate set) is not expressible as a
 * schema declaration.
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

/**
 * Filters and variant-groups a candidate Item pool.
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
 */
class ItemPoolFilter
{
    /**
     * Filter the ItemBank's `published` Items by itemPoolConfig's
     * subjectTags/difficulty filters and group the survivors by
     * variantGroupId (an Item with no variantGroupId is its own singleton
     * group).
     *
     * @param array<int,mixed>    $items      Raw findAll() rows (array or ObjectEntity-like).
     * @param array<string,mixed> $poolConfig itemPoolConfig (subjectTags, difficultyMin/Max).
     *
     * @return array<string,array<int,string>> variantGroupId (or synthetic singleton key) => item UUIDs.
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
     */
    public function filterAndGroupByVariant(array $items, array $poolConfig): array
    {
        $subjectTags   = $poolConfig['subjectTags'] ?? [];
        $difficultyMin = $poolConfig['difficultyMin'] ?? null;
        $difficultyMax = $poolConfig['difficultyMax'] ?? null;

        $groups = [];
        foreach ($items as $item) {
            $itemData = $item;
            if (is_array($item) === false) {
                $itemData = $item->jsonSerialize();
            }

            if ($this->matchesFilters(
                item: $itemData,
                subjectTags: $subjectTags,
                difficultyMin: $difficultyMin,
                difficultyMax: $difficultyMax
            ) === false
            ) {
                continue;
            }

            $itemId = $itemData['id'] ?? ($itemData['uuid'] ?? null);
            if ($itemId === null) {
                continue;
            }

            $groupKey            = $itemData['variantGroupId'] ?? ('__singleton__'.$itemId);
            $groups[$groupKey][] = $itemId;
        }//end foreach

        return $groups;

    }//end filterAndGroupByVariant()

    /**
     * Whether an Item matches the itemPoolConfig's subjectTags/difficulty filters.
     *
     * @param array<string,mixed> $item          Item data.
     * @param array<int,string>   $subjectTags   Required tags (OR match — empty = no filter).
     * @param float|null          $difficultyMin Inclusive lower bound, or null.
     * @param float|null          $difficultyMax Inclusive upper bound, or null.
     *
     * @return bool
     */
    private function matchesFilters(array $item, array $subjectTags, ?float $difficultyMin, ?float $difficultyMax): bool
    {
        if ($this->matchesSubjectTags(item: $item, subjectTags: $subjectTags) === false) {
            return false;
        }

        $difficulty = $item['difficulty'] ?? null;

        if ($difficultyMin !== null && ($difficulty === null || $difficulty < $difficultyMin)) {
            return false;
        }

        if ($difficultyMax !== null && ($difficulty === null || $difficulty > $difficultyMax)) {
            return false;
        }

        return true;

    }//end matchesFilters()

    /**
     * Whether an Item's subjectTags intersect the pool's required tags (OR
     * match — empty required list means no filter, always matches).
     *
     * @param array<string,mixed> $item        Item data.
     * @param array<int,string>   $subjectTags Required tags.
     *
     * @return bool
     */
    private function matchesSubjectTags(array $item, array $subjectTags): bool
    {
        if (empty($subjectTags) === true) {
            return true;
        }

        $itemTags = $item['subjectTags'] ?? [];
        foreach ($subjectTags as $tag) {
            if (in_array($tag, $itemTags, true) === true) {
                return true;
            }
        }

        return false;

    }//end matchesSubjectTags()
}//end class
