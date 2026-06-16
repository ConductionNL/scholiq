<?php

/**
 * Scholiq Cohort Membership Guard
 *
 * Lifecycle guard for the Cohort schema's `activate` transition. Enforces that a
 * Cohort has at least one learner assigned before it can be activated. Also verifies
 * that the backing Nextcloud group (ncGroupId) is set or can be created.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Cohort schema's x-openregister-lifecycle.transitions.activate.requires
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the Cohort `activate` transition.
 *
 * Returns true only when the Cohort has at least one learner in learnerIds,
 * ensuring that empty cohorts cannot be activated.
 *
 * Note: Full NC group synchronisation (ncGroupId provisioning) is deferred to a
 * separate event listener or manual admin action. The guard focuses on the
 * pre-condition check only, keeping it a single-method ADR-031 exception.
 */
class CohortMembershipGuard
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
     * Called by OpenRegister's lifecycle engine before executing the `activate`
     * transition on a Cohort object. Returns true only when the Cohort has at
     * least one learner assigned in learnerIds.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Cohort data array
     *                                               - 'transition' : 'activate'
     *                                               - 'from'       : 'planned'
     *                                               - 'to'         : 'active'
     *
     * @return bool True if the Cohort has at least one learner; false blocks the transition.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
     */
    public function check(array &$transitionContext): bool
    {
        $object     = $transitionContext['object'] ?? [];
        $learnerIds = $object['learnerIds'] ?? [];

        if (empty($learnerIds) === true) {
            $this->logger->info(
                '[CohortMembershipGuard] Cohort has no learners assigned; blocking activate transition.'
            );
            return false;
        }

        return true;
    }//end check()
}//end class
