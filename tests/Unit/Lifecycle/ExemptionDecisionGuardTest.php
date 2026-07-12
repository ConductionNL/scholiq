<?php

/**
 * Scholiq ExemptionDecisionGuard unit tests.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-exemptioncase-decisions-require-a-rationale-and-policy-reference
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ExemptionDecisionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the ExemptionDecisionGuard (in-assessment → granted|rejected).
 */
class ExemptionDecisionGuardTest extends TestCase
{

    /**
     * Build a guard with a stub logger.
     *
     * @return ExemptionDecisionGuard
     */
    private function makeGuard(): ExemptionDecisionGuard
    {
        return new ExemptionDecisionGuard($this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * Both decisionRationale and policyReference set → allowed.
     *
     * @return void
     */
    public function testBothFieldsSetAllowsTransition(): void
    {
        $context = [
            'object' => [
                'id'                 => 'case-1',
                'decisionRationale'  => 'Prior HBO diploma covers this component.',
                'policyReference'    => 'handreiking-2026 §3.2',
            ],
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testBothFieldsSetAllowsTransition()

    /**
     * Missing decisionRationale blocks the transition.
     *
     * @return void
     */
    public function testMissingDecisionRationaleBlocks(): void
    {
        $context = [
            'object' => [
                'id'              => 'case-1',
                'policyReference' => 'handreiking-2026 §3.2',
            ],
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingDecisionRationaleBlocks()

    /**
     * Missing policyReference blocks the transition.
     *
     * @return void
     */
    public function testMissingPolicyReferenceBlocks(): void
    {
        $context = [
            'object' => [
                'id'                => 'case-1',
                'decisionRationale' => 'Prior HBO diploma covers this component.',
            ],
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingPolicyReferenceBlocks()

    /**
     * Blank (whitespace-only) values are treated as missing.
     *
     * @return void
     */
    public function testBlankValuesBlock(): void
    {
        $context = [
            'object' => [
                'id'                => 'case-1',
                'decisionRationale' => '   ',
                'policyReference'   => '   ',
            ],
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testBlankValuesBlock()

    /**
     * Neither field set blocks the transition.
     *
     * @return void
     */
    public function testNeitherFieldSetBlocks(): void
    {
        $context = ['object' => ['id' => 'case-1']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testNeitherFieldSetBlocks()
}//end class
