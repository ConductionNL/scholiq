<?php

/**
 * Scholiq PaymentTransactionStatusHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-refunded-order-revokes-its-entitlement
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\PaymentTransactionStatusHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PaymentTransactionStatusHandler::handle().
 */
class PaymentTransactionStatusHandlerTest extends TestCase
{
    /**
     * Build a transition-event mock for a PaymentTransaction reaching the given state.
     *
     * @param array<string,mixed> $transactionData The PaymentTransaction data.
     * @param string              $to              Target lifecycle state.
     *
     * @return ObjectTransitionedEvent&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeEvent(array $transactionData, string $to)
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($transactionData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($entity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('payment-transaction');
        $event->method('getTo')->willReturn($to);

        return $event;

    }//end makeEvent()

    /**
     * A full single payment rolls the Order up to `paid`.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    public function testFullPaymentRollsOrderToPaid(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'open', 'totalAmount' => 50.00]);
        $objectService->method('findAll')->willReturn(
            [['id' => 'txn-1', 'orderId' => 'order-1', 'lifecycle' => 'succeeded', 'amount' => 50.00]]
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->once())->method('transition')->with('order-1', 'markPaid');

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($this->makeEvent(['id' => 'txn-1', 'orderId' => 'order-1'], 'succeeded'));

    }//end testFullPaymentRollsOrderToPaid()

    /**
     * A payment less than totalAmount rolls the Order to `partially-paid`.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    public function testPartialPaymentRollsOrderToPartiallyPaid(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'open', 'totalAmount' => 50.00]);
        $objectService->method('findAll')->willReturn(
            [['id' => 'txn-1', 'orderId' => 'order-1', 'lifecycle' => 'succeeded', 'amount' => 20.00]]
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->once())->method('transition')->with('order-1', 'markPartiallyPaid');

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($this->makeEvent(['id' => 'txn-1', 'orderId' => 'order-1'], 'succeeded'));

    }//end testPartialPaymentRollsOrderToPartiallyPaid()

    /**
     * An Order already `paid` is left alone — an extra succeeded payment does not retrigger.
     *
     * @return void
     */
    public function testAlreadyPaidOrderIsNotRetriggered(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'paid', 'totalAmount' => 50.00]);

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($this->makeEvent(['id' => 'txn-1', 'orderId' => 'order-1'], 'succeeded'));

    }//end testAlreadyPaidOrderIsNotRetriggered()

    /**
     * A refund on a paid Order with an active Entitlement revokes both the
     * Order (paid -> refunded) and the Entitlement (active -> revoked).
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-refunded-order-revokes-its-entitlement
     */
    public function testRefundRevokesOrderAndActiveEntitlements(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'paid']);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if ($config['schema'] === 'order-line') {
                    return [['id' => 'line-1', 'orderId' => 'order-1']];
                }

                if ($config['schema'] === 'entitlement') {
                    return [['id' => 'ent-1', 'orderLineId' => 'line-1', 'lifecycle' => 'active']];
                }

                return [];
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $calls            = [];
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $id, string $action) use (&$calls) {
                $calls[] = [$id, $action];
            }
        );

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($this->makeEvent(['id' => 'txn-1', 'orderId' => 'order-1'], 'refunded'));

        self::assertContains(['order-1', 'refund'], $calls);
        self::assertContains(['ent-1', 'revoke'], $calls);

    }//end testRefundRevokesOrderAndActiveEntitlements()

    /**
     * An event for a different schema is ignored.
     *
     * @return void
     */
    public function testIgnoresOtherSchemas(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('find');

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $entity = $this->createMock(ObjectEntity::class);
        $event  = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($entity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('order');
        $event->method('getTo')->willReturn('paid');

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($event);

    }//end testIgnoresOtherSchemas()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEventType(): void
    {
        $objectService    = $this->createMock(ObjectService::class);
        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $handler = new PaymentTransactionStatusHandler($objectService, $transitionEngine, $this->createMock(LoggerInterface::class));
        $handler->handle($this->createMock(Event::class));

    }//end testIgnoresUnrelatedEventType()
}//end class
