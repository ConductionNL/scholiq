<?php

/**
 * Scholiq OSO Dossier Review Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `approveDossier`
 * transition (`pending-parent-review → running`). Verifies that the actor
 * approving the dossier is listed as a parent/guardian of the learner whose
 * data is being transferred.
 *
 * The check reads the `scope.filters.learnerId` (or `scope.cohortId` for
 * cohort-wide OSO exports) from the DataExchangeJob, fetches the learner's
 * `LearnerProfile.parentIds`, and returns true only when the approving actor
 * is among them.
 *
 * Referenced from DataExchangeJob.x-openregister-lifecycle.transitions.approveDossier.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031: single-responsibility guard — solely verifies parent identity for
 * the OSO dossier approval. No protocol or serialisation logic.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-17
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the DataExchangeJob `pending-parent-review → running` transition.
 *
 * Only a parent listed in the learner's LearnerProfile.parentIds may approve
 * an OSO dossier for transfer.
 */
class OsoDossierReviewGuard
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
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
     * Allow the `pending-parent-review → running` transition.
     *
     * Returns true only when the actor in the transition context is listed in
     * the learner's LearnerProfile.parentIds. The learnerId is read from the
     * job's `scope.filters.learnerId` field. When no learnerId is resolvable
     * (e.g. a cohort-wide export), this guard returns false and the transition
     * must be triggered via administrative override outside this guard.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the DataExchangeJob data array
     *                                               - 'transition' : 'approveDossier'
     *                                               - 'from'       : 'pending-parent-review'
     *                                               - 'to'         : 'running'
     *                                               - 'actor'      : NC user ID of the requester
     *
     * @return bool True if the actor is a parent of the learner; false otherwise.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-17
     */
    public function check(array &$transitionContext): bool
    {
        $actor  = $transitionContext['actor'] ?? '';
        $object = $transitionContext['object'] ?? [];

        if ($actor === '') {
            $this->logger->warning('[OsoDossierReviewGuard] No actor in transitionContext — denying approveDossier.');
            return false;
        }

        // Resolve learnerId from scope.filters or scope directly.
        $scope     = $object['scope'] ?? [];
        $filters   = $scope['filters'] ?? [];
        $learnerId = $filters['learnerId'] ?? ($filters['ncUserId'] ?? '');

        if ($learnerId === '') {
            $this->logger->warning(
                '[OsoDossierReviewGuard] Job {id}: no learnerId in scope — cannot verify parent.',
                ['id' => $object['id'] ?? '?']
            );
            return false;
        }

        // Fetch the learner's LearnerProfile to read parentIds.
        $profiles = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_PROFILE_SCHEMA,
                'filters'  => ['ncUserId' => $learnerId],
                'limit'    => 1,
            ]
        );

        if (empty($profiles) === true) {
            $this->logger->warning(
                '[OsoDossierReviewGuard] No LearnerProfile found for learnerId {l} — denying.',
                ['l' => $learnerId]
            );
            return false;
        }

        $profile = $profiles[0];
        if (is_array($profiles[0]) === false) {
            $profile = $profiles[0]->jsonSerialize();
        }

        $parentIds = $profile['parentIds'] ?? [];

        if (in_array($actor, $parentIds, true) === false) {
            $this->logger->info(
                '[OsoDossierReviewGuard] Actor {a} is not in parentIds for learner {l} — denying.',
                ['a' => $actor, 'l' => $learnerId]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
