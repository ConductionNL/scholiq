<?php

/**
 * Scholiq Assessment Scoring Service
 *
 * Public API for triggering auto-scoring on an AssessmentResult. This service
 * wraps the AssessmentScoringHandler logic for programmatic use (e.g. CLI tools,
 * tests, or admin repair scripts) without going through the lifecycle transition.
 *
 * Legitimate PHP per ADR-031 §"Calculation engine — business rule above schema metadata."
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\AssessmentScoringHandler;
use Psr\Log\LoggerInterface;

/**
 * Provides a single public entry-point for auto-scoring an AssessmentResult.
 *
 * Delegates all scoring logic to AssessmentScoringHandler (the lifecycle guard/handler
 * that also runs on the submit transition). After scoring, persists the updated
 * responses via ObjectService::saveObject().
 */
class AssessmentScoringService
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService  OR object service for fetching and saving objects.
     * @param AssessmentScoringHandler $scoringHandler The scoring logic implementation.
     * @param LoggerInterface          $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AssessmentScoringHandler $scoringHandler,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Auto-score an AssessmentResult by UUID.
     *
     * Fetches the result, invokes the scoring handler, and persists the updated responses.
     * Items with interactionType `extendedText` or null correctResponse are left with
     * autoScore null and require teacher manual scoring before the result can be graded.
     *
     * @param string $assessmentResultId UUID of the AssessmentResult to score.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the AssessmentResult cannot be found.
     */
    public function autoScore(string $assessmentResultId): void
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'AssessmentResult',
                'filters'  => ['uuid' => $assessmentResultId],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            throw new \InvalidArgumentException(
                "AssessmentResult with UUID '{$assessmentResultId}' not found."
            );
        }

        $resultObject = $results[0];

        // Wrap in a transition context matching the handler contract.
        $transitionContext = [
            'object'     => $resultObject,
            'transition' => 'score',
            'from'       => $resultObject['lifecycle'] ?? 'submitted',
            'to'         => $resultObject['lifecycle'] ?? 'submitted',
        ];

        $this->scoringHandler->check($transitionContext);

        // Persist the updated responses.
        $this->objectService->saveObject(
            $transitionContext['object'],
        );

        $this->logger->info(
            '[AssessmentScoringService] Auto-scoring complete for AssessmentResult {id}.',
            ['id' => $assessmentResultId]
        );
    }//end autoScore()
}//end class
