<?php

/**
 * Scholiq Entitlement Order Paid Guard
 *
 * Lifecycle guard for the Entitlement schema's `grant` transition (pending ->
 * active). Resolves the Entitlement's orderLineId -> OrderLine -> orderId ->
 * Order chain and refuses the transition unless that Order has reached
 * `paid`. `partially-paid`, `open`, `draft`, `cancelled`, and `refunded` all
 * refuse — this change deliberately requires full payment (see design.md
 * "Rejected Alternatives"): partial-access-for-partial-payment is a real
 * pattern some platforms support, but it multiplies this guard's cases
 * without a concrete buyer requirement driving the threshold.
 *
 * Composed by {@see FeeItemVoluntaryEntitlementGuard}, which is the class
 * actually named in the Entitlement schema's `grant.requires` (OpenRegister's
 * lifecycle engine only accepts a single `requires` string — see that class's
 * own docblock for the composition rationale, mirroring
 * ReportPeriodLockGuard's composition of FraudCaseBlockGuard). This class is
 * independently unit-tested and may also be referenced directly if a future
 * schema needs only the payment check without the voluntary-fee guard.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-entitlement-grants-access-only-once-its-order-is-paid-and-only-for-non-voluntary-chargeables
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Entitlement `grant` (pending -> active) transition on payment status.
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
 */
class EntitlementOrderPaidGuard
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const ORDER_LINE_SCHEMA = 'order-line';
    private const ORDER_SCHEMA      = 'order';
    private const PAID_STATE        = 'paid';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Allow the `grant` transition only when the linked Order is `paid`.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Entitlement data array
     *                                               - 'transition' : 'grant'
     *
     * @return bool True if the linked Order is paid; false blocks the transition (HTTP 422).
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-activates-once-its-order-is-fully-paid
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-entitlement-cannot-activate-while-its-order-is-only-partially-paid
     */
    public function check(array &$transitionContext): bool
    {
        $entitlement   = $transitionContext['object'] ?? [];
        $entitlementId = $entitlement['id'] ?? ($entitlement['uuid'] ?? '');
        $orderLineId   = $entitlement['orderLineId'] ?? null;

        if (is_string($orderLineId) === false || $orderLineId === '') {
            $this->logger->warning(
                '[EntitlementOrderPaidGuard] Entitlement {id} has no orderLineId — denying grant (fail closed).',
                ['id' => $entitlementId]
            );
            return false;
        }

        $orderLine = $this->fetchObject(id: $orderLineId, schema: self::ORDER_LINE_SCHEMA);
        if ($orderLine === null) {
            $this->logger->warning(
                '[EntitlementOrderPaidGuard] Entitlement {id} links OrderLine {lineId} which was not found — denying grant (fail closed).',
                ['id' => $entitlementId, 'lineId' => $orderLineId]
            );
            return false;
        }

        $orderId = $orderLine['orderId'] ?? null;
        if (is_string($orderId) === false || $orderId === '') {
            $this->logger->warning(
                '[EntitlementOrderPaidGuard] OrderLine {lineId} has no orderId — denying grant (fail closed).',
                ['lineId' => $orderLineId]
            );
            return false;
        }

        $order = $this->fetchObject(id: $orderId, schema: self::ORDER_SCHEMA);
        if ($order === null) {
            $this->logger->warning(
                '[EntitlementOrderPaidGuard] OrderLine {lineId} links Order {orderId} which was not found — denying grant (fail closed).',
                ['lineId' => $orderLineId, 'orderId' => $orderId]
            );
            return false;
        }

        $lifecycle = $order['lifecycle'] ?? '';
        if ($lifecycle !== self::PAID_STATE) {
            $this->logger->info(
                '[EntitlementOrderPaidGuard] Entitlement {id} blocked — linked Order {orderId} is "{state}", not paid.',
                ['id' => $entitlementId, 'orderId' => $orderId, 'state' => $lifecycle]
            );
            return false;
        }

        return true;

    }//end check()

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
