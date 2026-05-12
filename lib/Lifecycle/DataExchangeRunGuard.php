<?php

/**
 * Scholiq Data Exchange Run Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `queued → running`
 * transition (the `run` action). Enforces the OSO parent-review gate:
 * an OSO job must pass through `pending-parent-review` (approved by a
 * parent via `approveDossier`) before it may enter `running`. Direct
 * `queued → running` is blocked for OSO targets.
 *
 * For all other targets the guard returns true unconditionally — the job
 * may proceed directly from `queued` to `running`.
 *
 * Referenced from DataExchangeJob.x-openregister-lifecycle.transitions.run.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031: single-responsibility guard — solely decides whether the `run`
 * transition is permitted based on the target field.
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

/**
 * Guards the DataExchangeJob `queued → running` lifecycle transition.
 *
 * Blocks OSO jobs from jumping directly to `running`; they must first
 * pass through `pending-parent-review` and be approved via `approveDossier`.
 */
class DataExchangeRunGuard
{

    /**
     * The OpenConnector target name that requires parent review before running.
     */
    private const OSO_TARGET = 'oso';

    /**
     * Allow the `queued → running` transition.
     *
     * For OSO targets: returns false when the job is still in `queued` state,
     * because OSO jobs must enter `pending-parent-review` first and be
     * approved via the `approveDossier` transition (which also leads to
     * `running`, bypassing this guard via its own path).
     *
     * For all other targets: returns true unconditionally.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the DataExchangeJob data array
     *                                               - 'transition' : 'run'
     *                                               - 'from'       : current state (expected: 'queued')
     *                                               - 'to'         : 'running'
     *
     * @return bool False for OSO jobs in queued state; true otherwise.
     */
    public function check(array &$transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $target = $object['target'] ?? '';
        $from   = $transitionContext['from'] ?? '';

        // OSO jobs must NOT move directly from queued to running.
        // They must first enter pending-parent-review via the pendingParentReview
        // transition, and then proceed via approveDossier → running.
        if ($target === self::OSO_TARGET && $from === 'queued') {
            return false;
        }

        return true;

    }//end check()
}//end class
