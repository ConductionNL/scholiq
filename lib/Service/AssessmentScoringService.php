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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\AssessmentScoringHandler;
use OCP\IGroupManager;
use OCP\IUserSession;
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
     * @param IGroupManager            $groupManager   NC group manager for admin-role check.
     * @param IUserSession             $userSession    NC user session for caller identity.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AssessmentScoringHandler $scoringHandler,
        private readonly LoggerInterface $logger,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Auto-score an AssessmentResult by UUID.
     *
     * Fetches the result, invokes the scoring handler, and persists the updated responses.
     * Items with interactionType `extendedText` or null correctResponse are left with
     * autoScore null and require teacher manual scoring before the result can be graded.
     *
     * Requires the caller to be a Nextcloud admin (group `admin`). This prevents any
     * future controller or MCP tool from calling autoScore without proper authorization
     * and overwriting scores outside the normal lifecycle. (#195)
     *
     * @param string $assessmentResultId UUID of the AssessmentResult to score.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the AssessmentResult cannot be found.
     * @throws \RuntimeException         When the caller is not an admin.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
     */
    public function autoScore(string $assessmentResultId): void
    {
        // #195: Enforce admin-only access so no unauthorized code path can call
        // autoScore and overwrite grades outside the normal lifecycle transition.
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isInGroup($user->getUID(), 'admin') === false) {
            throw new \RuntimeException(
                'autoScore may only be called by an admin user.'
            );
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'AssessmentResult',
                'filters'  => ['uuid' => $assessmentResultId],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            throw new InvalidArgumentException(
                "AssessmentResult with UUID '{$assessmentResultId}' not found."
            );
        }

        $raw = $results[0];
        if (is_array($raw) === true) {
            $resultObject = $raw;
        } else {
            $resultObject = $raw->jsonSerialize();
        }

        // Wrap in a transition context matching the handler contract.
        $transitionContext = [
            'object'     => $resultObject,
            'transition' => 'score',
            'from'       => $resultObject['lifecycle'] ?? 'submitted',
            'to'         => $resultObject['lifecycle'] ?? 'submitted',
        ];

        $this->scoringHandler->check($transitionContext);

        // #194/#223: use named args so saveObject picks the correct register/schema
        // from the stored state rather than relying on positional stale-state fallback.
        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: 'assessment-result',
            object: $transitionContext['object']
        );

        $this->logger->info(
            '[AssessmentScoringService] Auto-scoring complete for AssessmentResult {id}.',
            ['id' => $assessmentResultId]
        );
    }//end autoScore()
}//end class
