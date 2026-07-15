<?php

/**
 * Scholiq EntitlementOrderPaidGuard unit tests.
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\EntitlementOrderPaidGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the EntitlementOrderPaidGuard (Entitlement pending -> active / grant).
 */
class EntitlementOrderPaidGuardTest extends TestCase
{
    /**
     * Build a guard whose ObjectService::find() resolves the given OrderLine/Order fixtures.
     *
     * @param array<string,mixed>|null $orderLine OrderLine data, or null (not found).
     * @param array<string,mixed>|null $order     Order data, or null (not found).
     *
     * @return EntitlementOrderPaidGuard
     */
    private function makeGuard(?array $orderLine, ?array $order): EntitlementOrderPaidGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($orderLine, $order) {
                if ($schema === 'order-line') {
                    return $orderLine;
                }

                if ($schema === 'order') {
                    return $order;
                }

                return null;
            }
        );

        return new EntitlementOrderPaidGuard($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A paid Order allows the grant transition.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
     */
    public function testPaidOrderAllowsGrant(): void
    {
        $guard   = $this->makeGuard(
            orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
            order: ['id' => 'order-1', 'lifecycle' => 'paid']
        );
        $context = ['object' => ['id' => 'ent-1', 'orderLineId' => 'line-1']];

        self::assertTrue($guard->check($context));

    }//end testPaidOrderAllowsGrant()

    /**
     * A partially-paid/open/draft/cancelled/refunded Order refuses the grant transition.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
     */
    public function testNonPaidOrderRefusesGrant(): void
    {
        foreach (['partially-paid', 'open', 'draft', 'cancelled', 'refunded'] as $state) {
            $guard   = $this->makeGuard(
                orderLine: ['id' => 'line-1', 'orderId' => 'order-1'],
                order: ['id' => 'order-1', 'lifecycle' => $state]
            );
            $context = ['object' => ['id' => 'ent-1', 'orderLineId' => 'line-1']];

            self::assertFalse($guard->check($context), "state '{$state}' should refuse grant");
        }

    }//end testNonPaidOrderRefusesGrant()

    /**
     * A missing orderLineId fails closed.
     *
     * @return void
     */
    public function testMissingOrderLineIdFailsClosed(): void
    {
        $guard   = $this->makeGuard(orderLine: null, order: null);
        $context = ['object' => ['id' => 'ent-1']];

        self::assertFalse($guard->check($context));

    }//end testMissingOrderLineIdFailsClosed()

    /**
     * An unresolvable OrderLine fails closed.
     *
     * @return void
     */
    public function testUnresolvableOrderLineFailsClosed(): void
    {
        $guard   = $this->makeGuard(orderLine: null, order: null);
        $context = ['object' => ['id' => 'ent-1', 'orderLineId' => 'missing-line']];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableOrderLineFailsClosed()

    /**
     * An unresolvable Order fails closed.
     *
     * @return void
     */
    public function testUnresolvableOrderFailsClosed(): void
    {
        $guard   = $this->makeGuard(
            orderLine: ['id' => 'line-1', 'orderId' => 'missing-order'],
            order: null
        );
        $context = ['object' => ['id' => 'ent-1', 'orderLineId' => 'line-1']];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableOrderFailsClosed()
}//end class
