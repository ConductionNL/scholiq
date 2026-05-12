<?php

/**
 * Scholiq Assessment Grade Guard
 *
 * Lifecycle guard for the AssessmentResult schema's `grade` transition. Enforces that
 * every item that requires manual scoring has a non-null manualScore in the result's
 * responses before the AssessmentResult may move from `submitted` to `graded`.
 *
 * Auto-scored-only attempts (no extendedText or null correctResponse items) may be
 * graded immediately because AssessmentScoringHandler sets all autoScores on submit.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (AssessmentResult → Assessment → Item) to determine which
 * items need manual scoring.
 * Referenced from the AssessmentResult schema's x-openregister-lifecycle.transitions.grade.requires
 * in scholiq_register.json.
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AssessmentResult `grade` transition.
 *
 * Only allows `submitted → graded` once every item flagged `needsManualScoring`
 * has a non-null `manualScore` in the result's responses. Attempts consisting
 * entirely of auto-scored items pass immediately (AssessmentScoringHandler already
 * set all autoScores on the submit transition).
 */
class AssessmentGradeGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

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
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `grade`
     * transition on an AssessmentResult object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the AssessmentResult data array
     *                                               - 'transition' : 'grade'
     *                                               - 'from'       : 'submitted'
     *                                               - 'to'         : 'graded'
     *
     * @return bool True if all manual-scoring items have scores; false blocks the transition.
     */
    public function check(array &$transitionContext): bool
    {
        $result       = $transitionContext['object'] ?? [];
        $assessmentId = $result['assessmentId'] ?? null;
        $responses    = $result['responses'] ?? [];

        if ($assessmentId === null) {
            $this->logger->info(
                '[AssessmentGradeGuard] AssessmentResult has no assessmentId; blocking grade.'
            );
            return false;
        }

        // Fetch the parent Assessment to get itemRefs.
        $assessments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'Assessment',
                'filters'  => ['uuid' => $assessmentId],
                'limit'    => 1,
            ]
        );

        if (empty($assessments) === true) {
            $this->logger->info(
                '[AssessmentGradeGuard] Assessment {id} not found; blocking grade.',
                ['id' => $assessmentId]
            );
            return false;
        }

        $assessment = $assessments[0];
        $itemRefs   = $assessment['itemRefs'] ?? [];

        // Build a map of itemId → response for O(1) lookup.
        $responseByItemId = [];
        foreach ($responses as $response) {
            $itemId = $response['itemId'] ?? null;
            if ($itemId !== null) {
                $responseByItemId[$itemId] = $response;
            }
        }

        // For each referenced item, check if it needs manual scoring.
        foreach ($itemRefs as $itemRef) {
            $itemId = $itemRef['itemId'] ?? null;
            if ($itemId === null) {
                continue;
            }

            // Fetch the Item to determine needsManualScoring.
            $items = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'Item',
                    'filters'  => ['uuid' => $itemId],
                    'limit'    => 1,
                ]
            );

            if (empty($items) === true) {
                continue;
            }

            $item            = $items[0];
            $interactionType = $item['interactionType'] ?? '';
            $correctResponse = $item['correctResponse'] ?? null;
            $needsManualScoring = ($interactionType === 'extendedText') || ($correctResponse === null);

            if ($needsManualScoring === false) {
                continue;
            }

            $response    = $responseByItemId[$itemId] ?? null;
            $manualScore = $response['manualScore'] ?? null;

            if ($manualScore === null) {
                $this->logger->info(
                    '[AssessmentGradeGuard] Item {itemId} needs manual scoring but manualScore is null; blocking grade.',
                    ['itemId' => $itemId]
                );
                return false;
            }
        }//end foreach

        return true;
    }//end check()
}//end class
