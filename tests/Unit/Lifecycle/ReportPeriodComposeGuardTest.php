<?php

/**
 * Scholiq ReportPeriodComposeGuard unit tests.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ReportPeriodComposeGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportPeriodComposeGuard (ReportPeriod open -> composed).
 */
class ReportPeriodComposeGuardTest extends TestCase
{

    /**
     * Build a guard with a mocked logger.
     *
     * @return ReportPeriodComposeGuard
     */
    private function makeGuard(): ReportPeriodComposeGuard
    {
        return new ReportPeriodComposeGuard($this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A materialised `isLocked: true` allows compose.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
     */
    public function testMaterialisedIsLockedTrueAllowsCompose(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'period-1', 'isLocked' => true, 'lockDate' => '2020-01-01T00:00:00+00:00']];

        self::assertTrue($guard->check($context));

    }//end testMaterialisedIsLockedTrueAllowsCompose()

    /**
     * A materialised `isLocked: false` blocks compose.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-compose-is-blocked-before-the-lock-date
     */
    public function testMaterialisedIsLockedFalseBlocksCompose(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'period-1', 'isLocked' => false, 'lockDate' => '2099-01-01T00:00:00+00:00']];

        self::assertFalse($guard->check($context));

    }//end testMaterialisedIsLockedFalseBlocksCompose()

    /**
     * When the materialised value is absent, the guard falls back to computing
     * isLocked from lockDate directly — a past lockDate allows compose.
     *
     * @return void
     */
    public function testMissingMaterialisedValueFallsBackToPastLockDate(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'period-1', 'lockDate' => '2020-01-01T00:00:00+00:00']];

        self::assertTrue($guard->check($context));

    }//end testMissingMaterialisedValueFallsBackToPastLockDate()

    /**
     * When the materialised value is absent and lockDate is in the future,
     * the fallback blocks compose.
     *
     * @return void
     */
    public function testMissingMaterialisedValueFallsBackToFutureLockDate(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'period-1', 'lockDate' => '2099-01-01T00:00:00+00:00']];

        self::assertFalse($guard->check($context));

    }//end testMissingMaterialisedValueFallsBackToFutureLockDate()

    /**
     * A null lockDate never locks — compose is blocked.
     *
     * @return void
     */
    public function testNullLockDateBlocksCompose(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'period-1', 'lockDate' => null]];

        self::assertFalse($guard->check($context));

    }//end testNullLockDateBlocksCompose()
}//end class
