<?php

/**
 * Scholiq Assessment Scoring Handler
 *
 * Lifecycle guard/handler for the AssessmentResult schema's `submit` transition.
 * On submit, auto-scores each response by comparing it against the parent Item's
 * correctResponse. Items with interactionType `extendedText` or a null
 * correctResponse are left with autoScore null (they require teacher manual scoring).
 *
 * This is a legitimate PHP exception per ADR-031 §"Calculation engine": auto-scoring
 * is a domain algorithm above what schema metadata can express. It runs as a `requires:`
 * guard on the `submit` transition. It returns true when the parent Assessment is
 * accessible and scoring is applied. It returns false (fail-closed) when the parent
 * Assessment cannot be resolved — blocking the transition to prevent client-controlled
 * autoScore values from persisting (wave-12 WF3).
 *
 * Referenced from the AssessmentResult schema's
 * x-openregister-lifecycle.transitions.submit.requires in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Runs auto-scoring on AssessmentResult submit transition.
 *
 * Evaluates each response against the matching Item's correctResponse:
 * - For choice/textEntry/hotspot/order/match/gapMatch/inlineChoice: compares the
 *   response value to correctResponse and awards maxScore (or 0) accordingly.
 * - For extendedText or null correctResponse: leaves autoScore null (needs teacher).
 *
 * Returns true when scoring succeeds or when the Assessment is not yet needed (no responses).
 * Returns false (fail-closed) when the parent Assessment cannot be resolved — this blocks
 * the submit transition to prevent client-controlled autoScore values from persisting.
 */
class AssessmentScoringHandler
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Interaction types that can be auto-scored.
     */
    private const AUTO_SCORABLE = ['choice', 'textEntry', 'hotspot', 'order', 'match', 'gapMatch', 'inlineChoice'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for Assessment and Item lookups.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point — always allows the transition, but scores responses first.
     *
     * Called by OpenRegister's lifecycle engine on the `submit` transition.
     * Mutates $transitionContext['object']['responses'] to populate `autoScore` for
     * each auto-scorable item. Items requiring manual scoring remain with autoScore null.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the AssessmentResult data array (mutated)
     *                                               - 'transition' : 'submit'
     *                                               - 'from'       : 'in-progress'
     *                                               - 'to'         : 'submitted'
     *
     * @return bool True when scoring succeeds or when there are no responses to score.
     *              False (fail-closed) when the parent Assessment cannot be resolved —
     *              this blocks the submit transition to prevent attacker-controlled autoScore.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
     */
    public function check(array &$transitionContext): bool
    {
        $result       = &$transitionContext['object'];
        $assessmentId = $result['assessmentId'] ?? null;
        $responses    = $result['responses'] ?? [];

        if ($assessmentId === null || empty($responses) === true) {
            return true;
        }

        $tenantId = $result['tenant_id'] ?? '';

        // H1: scope Assessment lookup to the same tenant.
        $assessmentFilters = ['uuid' => $assessmentId];
        if ($tenantId !== '') {
            $assessmentFilters['tenant_id'] = $tenantId;
        }

        // Fetch the parent Assessment for itemRefs and their point overrides.
        $assessments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'assessment',
                'filters'  => $assessmentFilters,
                'limit'    => 1,
            ]
        );

        if (empty($assessments) === true) {
            // Fail-CLOSED: if the parent Assessment is unreachable (different tenant,
            // deleted, or attacker-supplied bogus assessmentId), block the submit
            // transition rather than allowing client-controlled autoScore values through.
            // See wave-12 WF3.
            $this->logger->warning(
                '[AssessmentScoringHandler] Assessment {id} not found or out-of-tenant; blocking submit transition (fail-closed).',
                ['id' => $assessmentId]
            );
            return false;
        }

        $assessment = $assessments[0];
        $itemRefs   = $assessment['itemRefs'] ?? [];

        // Build itemId → points override map.
        $pointsByItemId = [];
        foreach ($itemRefs as $itemRef) {
            $itemId = $itemRef['itemId'] ?? null;
            if ($itemId !== null) {
                $pointsByItemId[$itemId] = $itemRef['points'] ?? null;
            }
        }

        // Score each response.
        foreach ($responses as &$response) {
            $itemId = $response['itemId'] ?? null;
            if ($itemId === null) {
                continue;
            }

            // H1: scope Item lookup to the same tenant.
            $itemFilters = ['uuid' => $itemId];
            if ($tenantId !== '') {
                $itemFilters['tenant_id'] = $tenantId;
            }

            $items = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'item',
                    'filters'  => $itemFilters,
                    'limit'    => 1,
                ]
            );

            if (empty($items) === true) {
                // Item unreachable — zero out autoScore rather than leaving client value intact.
                // Prevents out-of-tenant item references from carrying through attacker-supplied scores.
                $response['autoScore'] = 0.0;
                continue;
            }

            $item            = $items[0];
            $interactionType = $item['interactionType'] ?? '';
            $correctResponse = $item['correctResponse'] ?? null;
            $maxScore        = $pointsByItemId[$itemId] ?? $item['maxScore'] ?? 0;

            $needsManual = ($interactionType === 'extendedText') || ($correctResponse === null);

            if ($needsManual === true) {
                $response['autoScore'] = null;
                continue;
            }

            if (in_array($interactionType, self::AUTO_SCORABLE, true) === false) {
                $response['autoScore'] = null;
                continue;
            }

            $learnerResponse       = $response['response'] ?? null;
            $response['autoScore'] = $this->scoreResponse(
                interactionType: $interactionType,
                learnerResponse: $learnerResponse,
                correctResponse: $correctResponse,
                maxScore: (float) $maxScore
            );
        }//end foreach

        unset($response);
        $result['responses'] = $responses;

        $this->logger->info(
            '[AssessmentScoringHandler] Auto-scored {count} responses for AssessmentResult.',
            ['count' => count($responses)]
        );

        return true;
    }//end check()

    /**
     * Score a single response against the item's correctResponse.
     *
     * For choice, textEntry, inlineChoice: exact match wins full marks.
     * For order, match, gapMatch: partial scoring by matched count / total.
     * For hotspot: treats correctResponse as array of accepted identifiers.
     * Unknown interactions return 0.
     *
     * @param string $interactionType QTI 3.0 interaction type.
     * @param mixed  $learnerResponse Learner's response value.
     * @param mixed  $correctResponse Item's declared correct response.
     * @param float  $maxScore        Maximum points for this item (from itemRefs override or item).
     *
     * @return float Score in range [0, maxScore].
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
     */
    private function scoreResponse(
        string $interactionType,
        mixed $learnerResponse,
        mixed $correctResponse,
        float $maxScore,
    ): float {
        if ($learnerResponse === null || $correctResponse === null) {
            return 0.0;
        }

        switch ($interactionType) {
            case 'choice':
            case 'textEntry':
            case 'inlineChoice':
                // Exact or case-insensitive match.
                $lr = $learnerResponse;
                if (is_string($learnerResponse) === true) {
                    $lr = mb_strtolower(trim($learnerResponse));
                }

                $cr = $correctResponse;
                if (is_string($correctResponse) === true) {
                    $cr = mb_strtolower(trim($correctResponse));
                }

                if ($lr === $cr) {
                    return $maxScore;
                }
                return 0.0;

            case 'order':
            case 'match':
            case 'gapMatch':
                // Partial: award proportionally for each correct element.
                if (is_array($learnerResponse) === false || is_array($correctResponse) === false) {
                    return 0.0;
                }

                $totalExpected = count($correctResponse);
                if ($totalExpected === 0) {
                    return 0.0;
                }

                $correctCount = 0;
                foreach ($correctResponse as $idx => $expected) {
                    if (isset($learnerResponse[$idx]) === true && $learnerResponse[$idx] === $expected) {
                        $correctCount++;
                    }
                }
                return round(($correctCount / $totalExpected) * $maxScore, 2);

            case 'hotspot':
                // Treat correctResponse as an array of required identifiers.
                // #185: partial scoring — award marks proportionally for the fraction
                // of correct hotspots hit, rather than full marks for any single hit.
                if (is_array($correctResponse) === false) {
                    $correctResponse = [$correctResponse];
                }

                if (is_array($learnerResponse) === false) {
                    $learnerResponse = [$learnerResponse];
                }

                $totalRequired = count($correctResponse);
                if ($totalRequired === 0) {
                    return 0.0;
                }

                // Correct hits: learner clicked a required hotspot (no negatives for wrong hits).
                $hits = count(array_intersect($learnerResponse, $correctResponse));
                return round(($hits / $totalRequired) * $maxScore, 2);

            default:
                return 0.0;
        }//end switch
    }//end scoreResponse()
}//end class
