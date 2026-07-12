<?php

/**
 * Scholiq FraudCaseDecisionGuard unit tests.
 *
 * Covers the verdict+rationale precondition, the additional capped-sanction
 * precondition when verdict=fraud-proven, and the decidedAt/appealDeadline
 * (decidedAt + 42 days) stamp on success.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OCA\Scholiq\Lifecycle\FraudCaseDecisionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseDecisionGuard (heard → decided).
 */
class FraudCaseDecisionGuardTest extends TestCase
{

    /**
     * Build a guard with a stub logger.
     *
     * @return FraudCaseDecisionGuard
     */
    private function makeGuard(): FraudCaseDecisionGuard
    {
        return new FraudCaseDecisionGuard($this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * Missing verdict and/or decisionRationale blocks decide.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-decide-blocked-without-a-verdict-and-rationale
     */
    public function testMissingVerdictOrRationaleBlocks(): void
    {
        $context = ['object' => ['id' => 'case-1']];
        self::assertFalse($this->makeGuard()->check($context));

        $context = ['object' => ['id' => 'case-1', 'verdict' => 'unfounded']];
        self::assertFalse($this->makeGuard()->check($context));

        $context = ['object' => ['id' => 'case-1', 'decisionRationale' => 'No evidence found.']];
        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingVerdictOrRationaleBlocks()

    /**
     * verdict=unfounded with a rationale succeeds and stamps decidedAt/appealDeadline —
     * no sanction fields required.
     *
     * @return void
     */
    public function testUnfoundedVerdictWithRationaleSucceedsAndStamps(): void
    {
        $context = [
            'object'  => [
                'id'                => 'case-1',
                'verdict'           => 'unfounded',
                'decisionRationale' => 'No evidence found.',
            ],
            'payload' => [],
        ];

        self::assertTrue($this->makeGuard()->check($context));
        self::assertArrayHasKey('decidedAt', $context['payload']);
        self::assertArrayHasKey('appealDeadline', $context['payload']);

    }//end testUnfoundedVerdictWithRationaleSucceedsAndStamps()

    /**
     * verdict=fraud-proven without sanction fields blocks decide.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-a-fraud-proven-verdict-requires-a-capped-sanction
     */
    public function testFraudProvenWithoutSanctionBlocks(): void
    {
        $context = [
            'object' => [
                'id'                => 'case-1',
                'verdict'           => 'fraud-proven',
                'decisionRationale' => 'Plagiarism confirmed via Turnitin report.',
            ],
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testFraudProvenWithoutSanctionBlocks()

    /**
     * verdict=fraud-proven with a sanctionDurationMonths above the 12-month cap blocks decide.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-a-fraud-proven-verdict-requires-a-capped-sanction
     */
    public function testFraudProvenWithSanctionDurationOverCapBlocks(): void
    {
        $context = [
            'object' => [
                'id'                      => 'case-1',
                'verdict'                 => 'fraud-proven',
                'decisionRationale'       => 'Plagiarism confirmed.',
                'sanctionType'            => 'suspension',
                'sanctionDurationMonths'  => 13,
                'sanctionScope'           => 'course',
            ],
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testFraudProvenWithSanctionDurationOverCapBlocks()

    /**
     * verdict=fraud-proven with a complete, valid sanction succeeds and stamps
     * decidedAt/appealDeadline = decidedAt + 42 days.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-deciding-a-case-stamps-the-appeal-deadline
     */
    public function testFraudProvenWithValidSanctionSucceedsAndStampsAppealDeadline(): void
    {
        $context = [
            'object'  => [
                'id'                     => 'case-1',
                'verdict'                => 'fraud-proven',
                'decisionRationale'      => 'Plagiarism confirmed via Turnitin report.',
                'sanctionType'           => 'suspension',
                'sanctionDurationMonths' => 6,
                'sanctionScope'          => 'course',
            ],
            'payload' => [],
        ];

        self::assertTrue($this->makeGuard()->check($context));

        self::assertArrayHasKey('decidedAt', $context['payload']);
        self::assertArrayHasKey('appealDeadline', $context['payload']);

        // Both values must parse as valid ISO-8601 dates (not asserting against
        // the test process' own wall clock — the guard and the test process may
        // run in different containers with independent clocks).
        $decidedAt      = new DateTimeImmutable($context['payload']['decidedAt']);
        $appealDeadline = new DateTimeImmutable($context['payload']['appealDeadline']);

        // The stamped deadline is exactly decidedAt + 42 days — the CBE appeal window.
        $expectedDeadline = $decidedAt->modify('+42 days');
        self::assertSame($expectedDeadline->format(\DATE_ATOM), $appealDeadline->format(\DATE_ATOM));

    }//end testFraudProvenWithValidSanctionSucceedsAndStampsAppealDeadline()

    /**
     * A sanctionDurationMonths of exactly 12 (the cap boundary) is allowed.
     *
     * @return void
     */
    public function testSanctionDurationAtCapBoundaryAllowed(): void
    {
        $context = [
            'object'  => [
                'id'                     => 'case-1',
                'verdict'                => 'fraud-proven',
                'decisionRationale'      => 'Repeated, severe plagiarism.',
                'sanctionType'           => 'exclusion',
                'sanctionDurationMonths' => 12,
                'sanctionScope'          => 'programme',
            ],
            'payload' => [],
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testSanctionDurationAtCapBoundaryAllowed()
}//end class
