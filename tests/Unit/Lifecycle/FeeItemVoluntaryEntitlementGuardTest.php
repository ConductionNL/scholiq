<?php

/**
 * Scholiq FeeItemVoluntaryEntitlementGuard unit tests.
 *
 * Covers the structural Wet vrijwillige ouderbijdrage guarantee: a voluntary
 * FeeItem's Entitlement must never be able to reach `active`, regardless of
 * the linked Order's payment status — including a paid Order (a guardian who
 * does pay is recorded as having paid, but that fact must never gate
 * anything).
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\EntitlementOrderPaidGuard;
use OCA\Scholiq\Lifecycle\FeeItemVoluntaryEntitlementGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FeeItemVoluntaryEntitlementGuard (Entitlement pending -> active / grant).
 */
class FeeItemVoluntaryEntitlementGuardTest extends TestCase
{
    /**
     * Build a guard whose ObjectService::find() resolves the given FeeItem
     * fixture, composing a real EntitlementOrderPaidGuard whose own
     * ObjectService resolves the given OrderLine/Order fixtures.
     *
     * @param array<string,mixed>|null $feeItem   FeeItem data, or null (not found).
     * @param array<string,mixed>|null $orderLine OrderLine data, or null.
     * @param array<string,mixed>|null $order     Order data, or null.
     *
     * @return FeeItemVoluntaryEntitlementGuard
     */
    private function makeGuard(?array $feeItem, ?array $orderLine=null, ?array $order=null): FeeItemVoluntaryEntitlementGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($feeItem, $orderLine, $order) {
                if ($schema === 'fee-item') {
                    return $feeItem;
                }

                if ($schema === 'order-line') {
                    return $orderLine;
                }

                if ($schema === 'order') {
                    return $order;
                }

                return null;
            }
        );

        $orderPaidGuard = new EntitlementOrderPaidGuard($objectService, $this->createMock(LoggerInterface::class));

        return new FeeItemVoluntaryEntitlementGuard($objectService, $orderPaidGuard, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A voluntary FeeItem's Entitlement can never activate — even when the
     * linked Order has reached `paid`.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
     */
    public function testVoluntaryFeeItemBlocksGrantRegardlessOfOrderStatus(): void
    {
        foreach (['draft', 'open', 'partially-paid', 'paid', 'cancelled', 'refunded'] as $orderState) {
            $guard   = $this->makeGuard(
                feeItem: ['id' => 'fee-1', 'voluntary' => true],
                orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
                order: ['id' => 'order-1', 'lifecycle' => $orderState]
            );
            $context = ['object' => ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'orderLineId' => 'line-1']];

            self::assertFalse(
                $guard->check($context),
                "voluntary FeeItem must block grant even when Order is '{$orderState}'"
            );
        }

    }//end testVoluntaryFeeItemBlocksGrantRegardlessOfOrderStatus()

    /**
     * A non-voluntary FeeItem is unaffected by this guard — the composed
     * EntitlementOrderPaidGuard's own check still applies (paid Order allows).
     *
     * @return void
     */
    public function testNonVoluntaryFeeItemAllowsGrantWhenOrderPaid(): void
    {
        $guard   = $this->makeGuard(
            feeItem: ['id' => 'fee-1', 'voluntary' => false],
            orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
            order: ['id' => 'order-1', 'lifecycle' => 'paid']
        );
        $context = ['object' => ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'orderLineId' => 'line-1']];

        self::assertTrue($guard->check($context));

    }//end testNonVoluntaryFeeItemAllowsGrantWhenOrderPaid()

    /**
     * A non-voluntary FeeItem still refuses when the composed
     * EntitlementOrderPaidGuard's own check fails (Order not paid).
     *
     * @return void
     */
    public function testNonVoluntaryFeeItemRefusesGrantWhenOrderNotPaid(): void
    {
        $guard   = $this->makeGuard(
            feeItem: ['id' => 'fee-1', 'voluntary' => false],
            orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
            order: ['id' => 'order-1', 'lifecycle' => 'partially-paid']
        );
        $context = ['object' => ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'orderLineId' => 'line-1']];

        self::assertFalse($guard->check($context));

    }//end testNonVoluntaryFeeItemRefusesGrantWhenOrderNotPaid()

    /**
     * A missing feeItemId fails closed.
     *
     * @return void
     */
    public function testMissingFeeItemIdFailsClosed(): void
    {
        $guard   = $this->makeGuard(feeItem: null);
        $context = ['object' => ['id' => 'ent-1']];

        self::assertFalse($guard->check($context));

    }//end testMissingFeeItemIdFailsClosed()

    /**
     * An unresolvable FeeItem fails closed.
     *
     * @return void
     */
    public function testUnresolvableFeeItemFailsClosed(): void
    {
        $guard   = $this->makeGuard(feeItem: null);
        $context = ['object' => ['id' => 'ent-1', 'feeItemId' => 'missing-fee']];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableFeeItemFailsClosed()
}//end class
