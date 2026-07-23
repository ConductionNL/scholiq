<?php

/**
 * Scholiq DataExchangeRunGuard unit tests — leerplicht regression coverage.
 *
 * Regression test per verzuim-report-composer tasks.md#task-3.2: confirms the
 * `leerplicht` target reaches `running` via the `run` transition (queued →
 * running) unconditionally, and is NEVER blocked the way `oso` is — the
 * richer verzuimloket dossier composition this change adds does NOT gate on
 * pending-parent-review. Guards this class's target-matching condition
 * against ever being broadened to match by dossier shape instead of literal,
 * explicitly-named target strings.
 *
 * Also covers zorgvraag-swv-tlv-chain tasks.md#task-4.4: `swv` is a second,
 * explicitly-named OSO-format-dossier target that gates identically to `oso`
 * (data-exchange spec "OSO-format dossier parent-review gate covers the SWV
 * zorgvraag target too") — added to GATED_TARGETS, not inferred.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\DataExchangeRunGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DataExchangeRunGuard::check() — the queued → running transition.
 */
class DataExchangeRunGuardTest extends TestCase
{
    /**
     * A leerplicht-target job in `queued` is allowed to run directly —
     * NOT blocked the way an oso-target job in `queued` is.
     *
     * @return void
     *
     * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.2
     */
    public function testLeerplichtTargetInQueuedIsAllowedToRun(): void
    {
        $context = [
            'object' => ['id' => 'job-1', 'target' => 'leerplicht'],
            'from'   => 'queued',
        ];

        self::assertTrue((new DataExchangeRunGuard())->check($context));

    }//end testLeerplichtTargetInQueuedIsAllowedToRun()

    /**
     * An oso-target job in `queued` is blocked — it must go via
     * pending-parent-review → approveDossier instead (unchanged behaviour,
     * asserted here so a future broadening of this guard's condition to
     * match by dossier richness rather than the literal target string is
     * caught).
     *
     * @return void
     */
    public function testOsoTargetInQueuedIsBlocked(): void
    {
        $context = [
            'object' => ['id' => 'job-2', 'target' => 'oso'],
            'from'   => 'queued',
        ];

        self::assertFalse((new DataExchangeRunGuard())->check($context));

    }//end testOsoTargetInQueuedIsBlocked()

    /**
     * A bron-rod-target job in `queued` is allowed to run directly, same as
     * leerplicht — only oso gates on parent review.
     *
     * @return void
     */
    public function testBronRodTargetInQueuedIsAllowedToRun(): void
    {
        $context = [
            'object' => ['id' => 'job-3', 'target' => 'bron-rod'],
            'from'   => 'queued',
        ];

        self::assertTrue((new DataExchangeRunGuard())->check($context));

    }//end testBronRodTargetInQueuedIsAllowedToRun()

    /**
     * A swv-target job in `queued` is blocked — same gate as oso, per
     * zorgvraag-swv-tlv-chain tasks.md#task-4.4 and the data-exchange spec's
     * "OSO-format dossier parent-review gate covers the SWV zorgvraag target
     * too" requirement.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.4
     */
    public function testSwvTargetInQueuedIsBlocked(): void
    {
        $context = [
            'object' => ['id' => 'job-4', 'target' => 'swv'],
            'from'   => 'queued',
        ];

        self::assertFalse((new DataExchangeRunGuard())->check($context));

    }//end testSwvTargetInQueuedIsBlocked()
}//end class
