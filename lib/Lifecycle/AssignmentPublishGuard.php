<?php

/**
 * Scholiq Assignment Publish Guard
 *
 * Lifecycle guard for the Assignment schema's `publish` transition. Enforces that an
 * Assignment has a courseId or a sessionId (or both) before it may be published.
 * Assignments without a context cannot be submitted to by learners.
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the Assignment `publish` transition.
 *
 * An Assignment may only be published when it has a non-null courseId OR a non-null
 * sessionId. This ensures every published assignment has a learner context and can
 * appear in the correct course or session view.
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
     * a non-null courseId or a non-null sessionId.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Assignment data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : current lifecycle state
     *                                               - 'to'         : 'published'
     *
     * @return bool True if the Assignment has courseId or sessionId; false blocks the transition.
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

        return true;
    }//end check()
}//end class
