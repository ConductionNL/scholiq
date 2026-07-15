<?php

/**
 * Scholiq Payment Transaction Status Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when a
 * PaymentTransaction transitions:
 *
 * 1. -> succeeded: sums every `succeeded` PaymentTransaction.amount for the
 *    parent Order and rolls the Order up to `paid` (sum >= totalAmount) or
 *    `partially-paid` (otherwise), via TransitionEngine — never a raw field
 *    write, so the Order's own audit trail and dueSoon/overdue notification
 *    rules stay consistent with a real lifecycle transition. An Order already
 *    `paid` is left alone (an extra succeeded payment does not retrigger).
 * 2. -> refunded: rolls the parent Order `paid -> refunded` via
 *    TransitionEngine, then revokes every `active` Entitlement reachable
 *    through that Order's OrderLines (also via TransitionEngine's `revoke`
 *    transition — Entitlement.revoke has no guard, per the payments spec).
 *
 * ADR-031 legitimate exception: cross-object roll-up bridge — "sum a related
 * schema's field and drive a transition on a third schema" cannot be
 * expressed as schema metadata. Event-driven (ObjectTransitionedEvent), NOT a
 * TimedJob (ADR-022), mirroring GradeRollupHandler/ExemptionGrantHandler's
 * shape.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges PaymentTransaction.succeeded -> Order paid/partially-paid roll-up,
 * and PaymentTransaction.refunded -> Order refund + Entitlement revoke cascade.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
 */
class PaymentTransactionStatusHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER           = 'scholiq';
    private const PAYMENT_TRANSACTION_SCHEMA = 'payment-transaction';
    private const ORDER_SCHEMA       = 'order';
    private const ORDER_LINE_SCHEMA  = 'order-line';
    private const ENTITLEMENT_SCHEMA = 'entitlement';

    private const ORDER_STATE_OPEN           = 'open';
    private const ORDER_STATE_PARTIALLY_PAID = 'partially-paid';
    private const ORDER_STATE_PAID           = 'paid';

    private const TRANSACTION_STATE_SUCCEEDED = 'succeeded';
    private const TRANSACTION_STATE_REFUNDED  = 'refunded';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object access.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to drive
     *                                           the Order roll-up and Entitlement
     *                                           revoke transitions.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::PAYMENT_TRANSACTION_SCHEMA) {
            return;
        }

        if ($event->getTo() === self::TRANSACTION_STATE_SUCCEEDED) {
            $this->handlePaymentSucceeded(event: $event);
            return;
        }

        if ($event->getTo() === self::TRANSACTION_STATE_REFUNDED) {
            $this->handlePaymentRefunded(event: $event);
        }

    }//end handle()

    /**
     * Roll the parent Order up to `paid` or `partially-paid` once a
     * PaymentTransaction succeeds.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    private function handlePaymentSucceeded(ObjectTransitionedEvent $event): void
    {
        $transaction = $event->getObject()->jsonSerialize();
        $orderId     = $transaction['orderId'] ?? null;

        if (is_string($orderId) === false || $orderId === '') {
            $this->logger->warning(
                '[PaymentTransactionStatusHandler] Succeeded PaymentTransaction {id} has no orderId — cannot roll up.',
                ['id' => $transaction['id'] ?? null]
            );
            return;
        }

        $order = $this->fetchObject(id: $orderId, schema: self::ORDER_SCHEMA);
        if ($order === null) {
            $this->logger->warning(
                '[PaymentTransactionStatusHandler] PaymentTransaction {id} links Order {orderId} which was not found.',
                ['id' => $transaction['id'] ?? null, 'orderId' => $orderId]
            );
            return;
        }

        $orderLifecycle = $order['lifecycle'] ?? '';
        if ($orderLifecycle === self::ORDER_STATE_PAID) {
            // Already fully settled — an extra succeeded payment does not retrigger.
            return;
        }

        $totalAmount  = (float) ($order['totalAmount'] ?? 0);
        $succeededSum = $this->sumSucceededTransactions(orderId: $orderId);

        if ($succeededSum >= $totalAmount) {
            if ($orderLifecycle === self::ORDER_STATE_OPEN || $orderLifecycle === self::ORDER_STATE_PARTIALLY_PAID) {
                $this->transitionEngine->transition($orderId, 'markPaid');
            }

            return;
        }

        if ($orderLifecycle === self::ORDER_STATE_OPEN) {
            $this->transitionEngine->transition($orderId, 'markPartiallyPaid');
        }

    }//end handlePaymentSucceeded()

    /**
     * Roll the parent Order to `refunded` and revoke every active Entitlement
     * reachable through its OrderLines once a PaymentTransaction is refunded.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-refunded-order-revokes-its-entitlement
     */
    private function handlePaymentRefunded(ObjectTransitionedEvent $event): void
    {
        $transaction = $event->getObject()->jsonSerialize();
        $orderId     = $transaction['orderId'] ?? null;

        if (is_string($orderId) === false || $orderId === '') {
            $this->logger->warning(
                '[PaymentTransactionStatusHandler] Refunded PaymentTransaction {id} has no orderId — cannot cascade.',
                ['id' => $transaction['id'] ?? null]
            );
            return;
        }

        $order = $this->fetchObject(id: $orderId, schema: self::ORDER_SCHEMA);
        if ($order === null) {
            $this->logger->warning(
                '[PaymentTransactionStatusHandler] PaymentTransaction {id} links Order {orderId} which was not found.',
                ['id' => $transaction['id'] ?? null, 'orderId' => $orderId]
            );
            return;
        }

        if (($order['lifecycle'] ?? '') === self::ORDER_STATE_PAID) {
            $this->transitionEngine->transition($orderId, 'refund');
        }

        $this->revokeActiveEntitlementsForOrder(orderId: $orderId);

    }//end handlePaymentRefunded()

    /**
     * Sum every `succeeded` PaymentTransaction.amount for the given Order.
     *
     * @param string $orderId UUID of the Order.
     *
     * @return float The sum of succeeded amounts.
     */
    private function sumSucceededTransactions(string $orderId): float
    {
        $transactions = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::PAYMENT_TRANSACTION_SCHEMA,
                'filters'  => [
                    'orderId'   => $orderId,
                    'lifecycle' => self::TRANSACTION_STATE_SUCCEEDED,
                ],
            ]
        );

        $sum = 0.0;
        foreach ($transactions as $transaction) {
            if (is_array($transaction) === false) {
                $transaction = $transaction->jsonSerialize();
            }

            $sum += (float) ($transaction['amount'] ?? 0);
        }

        return $sum;

    }//end sumSucceededTransactions()

    /**
     * Revoke every `active` Entitlement whose orderLineId belongs to the
     * given Order.
     *
     * @param string $orderId UUID of the Order.
     *
     * @return void
     */
    private function revokeActiveEntitlementsForOrder(string $orderId): void
    {
        $orderLines = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ORDER_LINE_SCHEMA,
                'filters'  => ['orderId' => $orderId],
            ]
        );

        foreach ($orderLines as $orderLine) {
            if (is_array($orderLine) === false) {
                $orderLine = $orderLine->jsonSerialize();
            }

            $orderLineId = $orderLine['id'] ?? ($orderLine['uuid'] ?? null);
            if (is_string($orderLineId) === false || $orderLineId === '') {
                continue;
            }

            $entitlements = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::ENTITLEMENT_SCHEMA,
                    'filters'  => [
                        'orderLineId' => $orderLineId,
                        'lifecycle'   => 'active',
                    ],
                ]
            );

            foreach ($entitlements as $entitlement) {
                if (is_array($entitlement) === false) {
                    $entitlement = $entitlement->jsonSerialize();
                }

                $entitlementId = $entitlement['id'] ?? ($entitlement['uuid'] ?? null);
                if (is_string($entitlementId) === false || $entitlementId === '') {
                    continue;
                }

                $this->transitionEngine->transition($entitlementId, 'revoke');
            }
        }//end foreach

    }//end revokeActiveEntitlementsForOrder()

    /**
     * Fetch an object by id + schema, normalising both array and ObjectEntity
     * return shapes.
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null The object data array, or null if not found.
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        $obj = $this->objectService->find(
            id: $id,
            register: self::SCHOLIQ_REGISTER,
            schema: $schema
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return $obj->jsonSerialize();

    }//end fetchObject()
}//end class
