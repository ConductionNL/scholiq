<?php

/**
 * Scholiq Data Exchange Run Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `queued → running`
 * transition (the `run` action). Enforces the OSO-format-dossier
 * parent-review gate: a job whose composed dossier is OSO-format must pass
 * through `pending-parent-review` (approved by a parent via `approveDossier`)
 * before it may enter `running`. Direct `queued → running` is blocked for
 * those targets.
 *
 * GATED_TARGETS is a literal, explicit allowlist of target strings — NOT an
 * inference by dossier "richness" or composition shape (see
 * DataExchangeRunGuardTest's guarding comment). `oso` (PO→VO overstapdossier)
 * and `swv` (zorgvraag/TLV-chain dossier, openspec/changes/
 * zorgvraag-swv-tlv-chain) are both OSO-format dossiers per the data-exchange
 * spec's "OSO-format dossier parent-review gate covers the SWV zorgvraag
 * target too" requirement — the SAME gate mechanism, not a parallel one.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

/**
 * Guards the DataExchangeJob `queued → running` lifecycle transition.
 *
 * Blocks OSO-format-dossier jobs (oso, swv) from jumping directly to
 * `running`; they must first pass through `pending-parent-review` and be
 * approved via `approveDossier`.
 */
class DataExchangeRunGuard
{

    /**
     * Literal, explicit allowlist of target strings whose composed dossier is
     * OSO-format and therefore requires parent review before running.
     * Adding a target here must be a deliberate, named decision — never
     * inferred from dossier composition shape (DataExchangeRunGuardTest).
     *
     * @var string[]
     */
    private const GATED_TARGETS = ['oso', 'swv'];

    /**
     * Allow the `queued → running` transition.
     *
     * For gated targets (see GATED_TARGETS): returns false when the job is
     * still in `queued` state, because these jobs must enter
     * `pending-parent-review` first and be approved via the `approveDossier`
     * transition (which also leads to `running`, bypassing this guard via its
     * own path).
     *
     * For all other targets: returns true unconditionally.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the DataExchangeJob data array
     *                                               - 'transition' : 'run'
     *                                               - 'from'       : current state (expected: 'queued')
     *                                               - 'to'         : 'running'
     *
     * @return bool False for gated-target jobs in queued state; true otherwise.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.4
     */
    public function check(array &$transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $target = $object['target'] ?? '';
        $from   = $transitionContext['from'] ?? '';

        // Gated-target jobs must NOT move directly from queued to running.
        // They must first enter pending-parent-review via the pendingParentReview
        // transition, and then proceed via approveDossier → running.
        if (in_array($target, self::GATED_TARGETS, true) === true && $from === 'queued') {
            return false;
        }

        return true;

    }//end check()
}//end class
