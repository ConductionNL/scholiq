<?php

/**
 * Scholiq OrderTotalValidationGuard unit tests.
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\OrderTotalValidationGuard;
use OCA\Scholiq\Payments\OrderTotalEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OrderTotalValidationGuard (Order draft -> open / finalize).
 */
class OrderTotalValidationGuardTest extends TestCase
{
    /**
     * Build a guard whose OrderTotalEvaluator returns the given result.
     *
     * @param float $total     Computed total.
     * @param int   $lineCount Computed line count.
     *
     * @return OrderTotalValidationGuard
     */
    private function makeGuard(float $total, int $lineCount): OrderTotalValidationGuard
    {
        $evaluator = $this->createMock(OrderTotalEvaluator::class);
        $evaluator->method('evaluate')->willReturn(['total' => $total, 'lineCount' => $lineCount]);

        return new OrderTotalValidationGuard($evaluator, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A matching total succeeds.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
     */
    public function testMatchingTotalSucceeds(): void
    {
        $guard   = $this->makeGuard(total: 45.00, lineCount: 2);
        $context = ['object' => ['id' => 'order-1', 'totalAmount' => 45.00]];

        self::assertTrue($guard->check($context));

    }//end testMatchingTotalSucceeds()

    /**
     * A mismatched total is refused.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
     */
    public function testMismatchedTotalRefused(): void
    {
        $guard   = $this->makeGuard(total: 45.00, lineCount: 2);
        $context = ['object' => ['id' => 'order-1', 'totalAmount' => 40.00]];

        self::assertFalse($guard->check($context));

    }//end testMismatchedTotalRefused()

    /**
     * An Order with zero OrderLines is refused (nothing to finalize).
     *
     * @return void
     */
    public function testZeroOrderLinesRefused(): void
    {
        $guard   = $this->makeGuard(total: 0.0, lineCount: 0);
        $context = ['object' => ['id' => 'order-1', 'totalAmount' => 0.0]];

        self::assertFalse($guard->check($context));

    }//end testZeroOrderLinesRefused()

    /**
     * A total within floating-point epsilon of the computed sum succeeds.
     *
     * @return void
     */
    public function testWithinEpsilonSucceeds(): void
    {
        $guard   = $this->makeGuard(total: 45.001, lineCount: 2);
        $context = ['object' => ['id' => 'order-1', 'totalAmount' => 45.00]];

        self::assertTrue($guard->check($context));

    }//end testWithinEpsilonSucceeds()
}//end class
