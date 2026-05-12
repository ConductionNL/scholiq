<?php

/**
 * Scholiq Attendance Flag Report Guard
 *
 * Lifecycle guard for the AttendanceFlag schema's `in-handling → reported`
 * transition. Once the data-exchange spec lands this guard will verify that
 * a DataExchangeJob for this flag has been queued and has succeeded before
 * allowing the `reported` state.
 *
 * For now the guard always returns true so the coordinator can move the flag
 * to `reported` once they have manually submitted the leerplicht report.
 * The data-exchange spec will tighten this to require a verified
 * DataExchangeJob result.
 *
 * ADR-031: guards referenced via schema `requires:` are resolved by OR's
 * lifecycle engine by class name — no `Application.php` registration needed.
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
 * Guards the AttendanceFlag `in-handling → reported` lifecycle transition.
 *
 * Currently a pass-through stub pending the data-exchange spec.
 * The check() method signature matches OR's lifecycle guard contract.
 */
class AttendanceFlagReportGuard
{
    /**
     * Allow the `in-handling → reported` transition.
     *
     * TODO(data-exchange spec): Once `DataExchangeJob` exists, tighten this to:
     * 1. Read `flag.dataExchangeJobId` from $transitionContext['object'].
     * 2. If null → return false (no job queued yet).
     * 3. Fetch the DataExchangeJob via ObjectService::findAll and check its
     *    lifecycle/status is 'succeeded'.
     * 4. Return false with a 422 if not yet succeeded; true on success.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the AttendanceFlag data array
     *                                               - 'transition' : 'report'
     *                                               - 'from'       : 'in-handling'
     *                                               - 'to'         : 'reported'
     *
     * @return bool Always true in this stub.
     */
    public function check(array &$transitionContext): bool
    {
        // Stub: allow transition unconditionally until the data-exchange spec lands.
        return true;

    }//end check()
}//end class
