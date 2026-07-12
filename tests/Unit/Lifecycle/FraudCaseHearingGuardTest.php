<?php

/**
 * Scholiq FraudCaseHearingGuard unit tests.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\FraudCaseHearingGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseHearingGuard (reported → hearing-scheduled).
 */
class FraudCaseHearingGuardTest extends TestCase
{

    /**
     * Build a guard with a stub logger.
     *
     * @return FraudCaseHearingGuard
     */
    private function makeGuard(): FraudCaseHearingGuard
    {
        return new FraudCaseHearingGuard($this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A hearingDate present allows the transition.
     *
     * @return void
     */
    public function testHearingDateSetAllowsTransition(): void
    {
        $context = ['object' => ['id' => 'case-1', 'hearingDate' => '2026-08-01T10:00:00Z']];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testHearingDateSetAllowsTransition()

    /**
     * A missing hearingDate blocks the transition.
     *
     * @return void
     */
    public function testMissingHearingDateBlocks(): void
    {
        $context = ['object' => ['id' => 'case-1']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingHearingDateBlocks()

    /**
     * A blank hearingDate blocks the transition.
     *
     * @return void
     */
    public function testBlankHearingDateBlocks(): void
    {
        $context = ['object' => ['id' => 'case-1', 'hearingDate' => '   ']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testBlankHearingDateBlocks()
}//end class
