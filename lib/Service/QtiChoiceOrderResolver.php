<?php

/**
 * Scholiq QTI Choice Order Resolver
 *
 * Stateless helper: parses a choice-type Item's QTI 3.0 `qtiBody` for its
 * `choiceInteraction`'s `simpleChoice` identifiers and, when asked, returns
 * those identifiers permuted — respecting the QTI 3.0 `fixed` attribute on
 * any individual `simpleChoice` that must not move (e.g. "None of the
 * above" pinned last). Extracted out of AssessmentDrawResolver purely to
 * keep that class's own complexity within this app's PHPMD budget; it
 * carries no dependencies of its own (constructor-injected via Nextcloud's
 * DI autowiring, mirroring the DI-injected-collaborator shape
 * BsaProgressFlagHandler already uses for BsaProgressEvaluator).
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata"
 * — parsing/permuting QTI XML is not expressible as a schema declaration.
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DOMDocument;
use DOMElement;
use DOMNodeList;

/**
 * Resolves a permuted QTI simpleChoice order, respecting `fixed` choices.
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
 */
class QtiChoiceOrderResolver
{
    /**
     * Resolve a permuted answer-option order for a choice-type Item.
     *
     * @param array<string,mixed> $item Item data (interactionType, qtiBody).
     *
     * @return array<int,string>|null Permuted identifier list, or null for
     *                                non-choice items or an unparseable/empty body.
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
     */
    public function resolveOrder(array $item): ?array
    {
        if (($item['interactionType'] ?? null) !== 'choice') {
            return null;
        }

        $qtiBody = $item['qtiBody'] ?? '';
        if (is_string($qtiBody) === false || trim($qtiBody) === '') {
            return null;
        }

        $doc = $this->parseQtiBody(qtiBody: $qtiBody);
        if ($doc === null) {
            return null;
        }

        $choiceNodes = $doc->getElementsByTagName('simpleChoice');
        if ($choiceNodes->length === 0) {
            return null;
        }

        $classified = $this->classifyChoices(choiceNodes: $choiceNodes);
        if (empty($classified['declaredOrder']) === true) {
            return null;
        }

        return $this->assembleOptionOrder(classified: $classified);

    }//end resolveOrder()

    /**
     * Parse a QTI 3.0 item body XML string, suppressing libxml warnings for
     * a malformed body (returns null instead).
     *
     * @param string $qtiBody Raw QTI 3.0 XML body.
     *
     * @return DOMDocument|null
     */
    private function parseQtiBody(string $qtiBody): ?DOMDocument
    {
        $previousSetting = libxml_use_internal_errors(true);
        $doc    = new DOMDocument();
        $loaded = $doc->loadXML($qtiBody);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($loaded === false) {
            return null;
        }

        return $doc;

    }//end parseQtiBody()

    /**
     * Classify each declared `simpleChoice` by its QTI `fixed` attribute.
     *
     * @param DOMNodeList<DOMElement> $choiceNodes The item's simpleChoice nodes, in declared order.
     *
     * @return array{declaredOrder: array<int,string>, fixedByIndex: array<int,string>, movableIds: array<int,string>}
     */
    private function classifyChoices(DOMNodeList $choiceNodes): array
    {
        $declaredOrder = [];
        $fixedByIndex  = [];
        $movableIds    = [];

        foreach ($choiceNodes as $index => $choiceNode) {
            $identifier = $choiceNode->getAttribute('identifier');
            if ($identifier === '') {
                continue;
            }

            $declaredOrder[$index] = $identifier;

            if (strtolower($choiceNode->getAttribute('fixed')) === 'true') {
                $fixedByIndex[$index] = $identifier;
                continue;
            }

            $movableIds[] = $identifier;
        }//end foreach

        return ['declaredOrder' => $declaredOrder, 'fixedByIndex' => $fixedByIndex, 'movableIds' => $movableIds];

    }//end classifyChoices()

    /**
     * Assemble the final option order: fixed choices keep their declared
     * index; the remaining (movable) identifiers are shuffled into the gaps.
     *
     * @param array<string,mixed> $classified classifyChoices()'s {declaredOrder, fixedByIndex, movableIds} result.
     *
     * @return array<int,string>
     */
    private function assembleOptionOrder(array $classified): array
    {
        $shuffledMovable = $this->secureShuffle(items: $classified['movableIds']);

        $result = [];
        $cursor = 0;
        foreach ($classified['declaredOrder'] as $index => $identifier) {
            if (isset($classified['fixedByIndex'][$index]) === true) {
                // A fixed choice's identifier is unchanged from its declared
                // position — declaredOrder[$index] already IS that identifier.
                $result[] = $identifier;
                continue;
            }

            $result[] = $shuffledMovable[$cursor];
            $cursor++;
        }

        return $result;

    }//end assembleOptionOrder()

    /**
     * Fisher-Yates shuffle using `random_int()` (cryptographically-strong,
     * unlike Mersenne-Twister-backed `shuffle()`/`array_rand()`) — no seed
     * is persisted, matching AssessmentDrawResolver's own shuffle posture.
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
}//end class
