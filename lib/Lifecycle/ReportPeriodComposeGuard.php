<?php

/**
 * Scholiq Report Period Compose Guard
 *
 * Lifecycle guard for the ReportPeriod schema's `compose` transition
 * (open -> composed). Blocks composition until the period is `isLocked`
 * (lockDate is set AND has passed @now) — a coordinator/mentor must not
 * compose report cards while ordinary grade-entry publishing for the
 * period is still allowed, per report-card's "Lock date is enforced by a
 * materialised calculation and guards, not an automatic transition"
 * requirement.
 *
 * `isLocked` is a `materialise: true` x-openregister-calculations field —
 * this guard reads it directly off the fetched object the same way every
 * other cross-schema guard in this app reads a sibling field, falling back
 * to computing it manually from `lockDate` only when the materialised value
 * is absent (defensive — materialisation should always have run, but a
 * guard must not silently allow composition of a not-yet-locked period on a
 * missing/stale calculation).
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the ReportPeriod schema's
 * x-openregister-lifecycle.transitions.compose.requires in
 * scholiq_register.json.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the ReportPeriod `compose` (open -> composed) lifecycle transition.
 *
 * Allows the transition only when the period's `isLocked` calculation is
 * `true`. Blocks otherwise.
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-lock-date-is-enforced-by-a-materialised-calculation-and-guards-not-an-automatic-transition
 */
class ReportPeriodComposeGuard
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
     * Called by OpenRegister's lifecycle engine before executing the
     * `compose` transition on a ReportPeriod object. Returns true only when
     * `isLocked` (the materialised lockDate-passed calculation) is `true`.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the ReportPeriod data array
     *                                               - 'transition' : 'compose'
     *                                               - 'from'       : 'open'
     *                                               - 'to'         : 'composed'
     *
     * @return bool True when the period is locked; false blocks the transition.
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $periodId = $object['id'] ?? ($object['uuid'] ?? '');

        $isLocked = $object['isLocked'] ?? null;

        if (is_bool($isLocked) === false) {
            // Materialised value absent — defensive fallback, computed the same
            // way as the declared x-openregister-calculations expression.
            $isLocked = $this->computeIsLocked(object: $object);
        }

        if ($isLocked === false) {
            $this->logger->info(
                '[ReportPeriodComposeGuard] ReportPeriod {id} is not yet locked — denying compose transition.',
                ['id' => $periodId]
            );
            return false;
        }

        return true;

    }//end check()

    /**
     * Defensive fallback: compute whether `lockDate` has passed `@now`,
     * mirroring the declared `isLocked` x-openregister-calculations
     * expression exactly (`lockDate` set AND `lockDate < now`).
     *
     * @param array<string,mixed> $object The ReportPeriod data array.
     *
     * @return bool True when lockDate is set and in the past.
     */
    private function computeIsLocked(array $object): bool
    {
        $lockDate = $object['lockDate'] ?? null;

        if ($lockDate === null || $lockDate === '') {
            return false;
        }

        $lockTimestamp = strtotime((string) $lockDate);

        if ($lockTimestamp === false) {
            return false;
        }

        return $lockTimestamp < time();

    }//end computeIsLocked()
}//end class
