<?php

/**
 * Scholiq Assignment Publish Guard
 *
 * Lifecycle guard for the Assignment schema's `publish` transition. Enforces that an
 * Assignment has a courseId or a sessionId (or both) before it may be published, and
 * (peer-and-self-assessment) that a rubricId is set whenever peerReviewEnabled or
 * selfAssessmentEnabled is true — without a Rubric there is nothing for a reviewer
 * or the learner to score against.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Assignment schema's x-openregister-lifecycle.transitions.publish.requires
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the Assignment `publish` transition.
 *
 * An Assignment may only be published when it has a non-null courseId OR a non-null
 * sessionId. This ensures every published assignment has a learner context and can
 * appear in the correct course or session view. Additionally, when peerReviewEnabled
 * or selfAssessmentEnabled is true, rubricId MUST be set.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
 */
class AssignmentPublishGuard
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `publish`
     * transition on an Assignment object. Returns true only when the Assignment has
     * a non-null courseId or a non-null sessionId, AND (peer-and-self-assessment)
     * when peerReviewEnabled or selfAssessmentEnabled is true, rubricId is set.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Assignment data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : current lifecycle state
     *                                               - 'to'         : 'published'
     *
     * @return bool True if the Assignment has courseId or sessionId (and, when peer/self
     *              assessment is enabled, a rubricId); false blocks the transition.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
     */
    public function check(array &$transitionContext): bool
    {
        $object    = $transitionContext['object'] ?? [];
        $courseId  = $object['courseId'] ?? null;
        $sessionId = $object['sessionId'] ?? null;

        if ($courseId === null && $sessionId === null) {
            $this->logger->info(
                '[AssignmentPublishGuard] Assignment has no courseId or sessionId; blocking publish.'
            );
            return false;
        }

        $peerReviewOn = ($object['peerReviewEnabled'] ?? false) === true;
        $selfAssessOn = ($object['selfAssessmentEnabled'] ?? false) === true;
        $rubricId     = $object['rubricId'] ?? null;

        if (($peerReviewOn === true || $selfAssessOn === true) && $rubricId === null) {
            $this->logger->info(
                '[AssignmentPublishGuard] peerReviewEnabled or selfAssessmentEnabled is true but rubricId is unset; blocking publish.'
            );
            return false;
        }

        return true;
    }//end check()
}//end class
