<?php

/**
 * Scholiq Order Total Evaluator
 *
 * Stateless calculation engine that sums an Order's OrderLine.lineTotal rows.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * Summing OrderLine.lineTotal for a given orderId is a cross-schema
 * aggregation the pure JSON-logic engine cannot express — a full-file grep of
 * lib/Settings/scholiq_register.json confirms only count/count_distinct are
 * used as x-openregister-aggregations metrics anywhere in this register, no
 * sum metric has a working precedent (documented in the register's own
 * info.description and on LearnerEngagement/CourseQualityScore). Same
 * rationale already accepted for GradeFormulaEvaluator (FinalGrade.value) and
 * BsaProgressEvaluator (ectsEarned). Single responsibility: sum and return;
 * no state, no audit writes.
 *
 * Consumed by:
 *   - OCA\Scholiq\Lifecycle\OrderTotalValidationGuard (Order draft -> open)
 *
 * @category Payments
 * @package  OCA\Scholiq\Payments
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

namespace OCA\Scholiq\Payments;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Sums an Order's OrderLine.lineTotal rows.
 *
 * @spec openspec/changes/school-payments/tasks.md#task-3.3
 */
class OrderTotalEvaluator
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const ORDER_LINE_SCHEMA = 'order-line';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object access.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Sum every OrderLine.lineTotal that belongs to the given Order.
     *
     * @param string $orderId UUID of the parent Order.
     *
     * @return array{total: float, lineCount: int}
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total
     */
    public function evaluate(string $orderId): array
    {
        if ($orderId === '') {
            return [
                'total'     => 0.0,
                'lineCount' => 0,
            ];
        }

        $lines = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ORDER_LINE_SCHEMA,
                'filters'  => ['orderId' => $orderId],
            ]
        );

        $total = 0.0;
        $count = 0;
        foreach ($lines as $line) {
            if (is_array($line) === false) {
                $line = $line->jsonSerialize();
            }

            $lineTotal = $line['lineTotal'] ?? null;
            if ($lineTotal === null) {
                continue;
            }

            $total += (float) $lineTotal;
            $count++;
        }

        return [
            'total'     => $total,
            'lineCount' => $count,
        ];

    }//end evaluate()
}//end class
