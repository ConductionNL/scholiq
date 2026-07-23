<?php

/**
 * Scholiq Order Total Validation Guard
 *
 * Lifecycle guard for the Order schema's `finalize` transition (draft ->
 * open). Order.totalAmount is written by the frontend line-editor and MUST
 * NOT be trusted as-is: this guard recomputes the sum of the Order's
 * OrderLine.lineTotal rows via OrderTotalEvaluator and refuses the transition
 * if the stored totalAmount does not match. An Order with zero OrderLines is
 * refused (nothing to finalize).
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards"
 * plus the same cross-schema-sum exception GradeFormulaEvaluator/
 * BsaProgressEvaluator already establish (see OrderTotalEvaluator's own
 * docblock) — applied here as a validation guard at the finalize transition
 * rather than a continuously materialised field, since Order.totalAmount only
 * needs to be correct at the moment a payer can no longer edit the lines.
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\Scholiq\Payments\OrderTotalEvaluator;
use Psr\Log\LoggerInterface;

/**
 * Guards the Order `finalize` (draft -> open) lifecycle transition.
 *
 * Refuses the transition unless the stored totalAmount exactly equals the
 * sum of the Order's OrderLine.lineTotal rows, and refuses an Order with no
 * OrderLines at all (nothing to finalize).
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
 */
class OrderTotalValidationGuard
{

    /**
     * Floating-point comparison tolerance for currency amounts (half a cent).
     *
     * @var float
     */
    private const AMOUNT_EPSILON = 0.005;

    /**
     * Constructor.
     *
     * @param OrderTotalEvaluator $evaluator Sums an Order's OrderLine.lineTotal rows.
     * @param LoggerInterface     $logger    PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly OrderTotalEvaluator $evaluator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Allow the `finalize` transition only when totalAmount matches the sum
     * of the Order's OrderLines.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Order data array
     *                                               - 'transition' : 'finalize'
     *
     * @return bool True if the transition is allowed; false blocks it (HTTP 422).
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
     */
    public function check(array &$transitionContext): bool
    {
        $order       = $transitionContext['object'] ?? [];
        $orderId     = $order['id'] ?? ($order['uuid'] ?? '');
        $storedTotal = (float) ($order['totalAmount'] ?? 0);

        $result = $this->evaluator->evaluate(orderId: (string) $orderId);

        if ($result['lineCount'] === 0) {
            $this->logger->info(
                '[OrderTotalValidationGuard] Order {id} has no OrderLines — refusing finalize (nothing to finalize).',
                ['id' => $orderId]
            );
            return false;
        }

        if (abs($result['total'] - $storedTotal) > self::AMOUNT_EPSILON) {
            $this->logger->info(
                '[OrderTotalValidationGuard] Order {id} totalAmount ({stored}) does not match'
                .' the sum of its OrderLines ({computed}) — refusing finalize.',
                [
                    'id'       => $orderId,
                    'stored'   => $storedTotal,
                    'computed' => $result['total'],
                ]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
