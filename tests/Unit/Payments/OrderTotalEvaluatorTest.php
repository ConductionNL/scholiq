<?php

/**
 * Scholiq OrderTotalEvaluator unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Payments
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

namespace OCA\Scholiq\Tests\Unit\Payments;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Payments\OrderTotalEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OrderTotalEvaluator::evaluate().
 */
class OrderTotalEvaluatorTest extends TestCase
{
    /**
     * Sums lineTotal across every OrderLine for the given orderId.
     *
     * @return void
     */
    public function testSumsLineTotalsForTheOrder(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn(
            [
                ['id' => 'line-1', 'orderId' => 'order-1', 'lineTotal' => 15.00],
                ['id' => 'line-2', 'orderId' => 'order-1', 'lineTotal' => 30.00],
            ]
        );

        $evaluator = new OrderTotalEvaluator($objectService);
        $result    = $evaluator->evaluate(orderId: 'order-1');

        self::assertSame(45.0, $result['total']);
        self::assertSame(2, $result['lineCount']);

    }//end testSumsLineTotalsForTheOrder()

    /**
     * No OrderLines returns a zero total and zero line count.
     *
     * @return void
     */
    public function testNoOrderLinesReturnsZero(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $evaluator = new OrderTotalEvaluator($objectService);
        $result    = $evaluator->evaluate(orderId: 'order-1');

        self::assertSame(0.0, $result['total']);
        self::assertSame(0, $result['lineCount']);

    }//end testNoOrderLinesReturnsZero()

    /**
     * An empty orderId short-circuits without calling ObjectService.
     *
     * @return void
     */
    public function testEmptyOrderIdShortCircuits(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $evaluator = new OrderTotalEvaluator($objectService);
        $result    = $evaluator->evaluate(orderId: '');

        self::assertSame(0.0, $result['total']);
        self::assertSame(0, $result['lineCount']);

    }//end testEmptyOrderIdShortCircuits()
}//end class
