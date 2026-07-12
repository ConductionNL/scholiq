<?php

/**
 * Scholiq FraudCaseInvalidationGuard unit tests.
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\FraudCaseInvalidationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseInvalidationGuard (GradeEntry concept → invalidated).
 */
class FraudCaseInvalidationGuardTest extends TestCase
{

    /**
     * Build a guard whose ObjectService::find() returns the given FraudCase (or null).
     *
     * @param array<string,mixed>|null $fraudCase FraudCase data, or null (not found).
     *
     * @return FraudCaseInvalidationGuard
     */
    private function makeGuard(?array $fraudCase): FraudCaseInvalidationGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($fraudCase);

        return new FraudCaseInvalidationGuard($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * No fraudCaseId set → blocked (fail closed).
     *
     * @return void
     */
    public function testNoFraudCaseIdBlocks(): void
    {
        $guard   = $this->makeGuard(fraudCase: null);
        $context = ['object' => ['id' => 'entry-1']];

        self::assertFalse($guard->check($context));

    }//end testNoFraudCaseIdBlocks()

    /**
     * A linked FraudCase that is decided fraud-proven allows invalidate.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-succeeds-once-the-linked-case-is-decided-fraud-proven
     */
    public function testDecidedFraudProvenAllowsInvalidate(): void
    {
        $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'fraud-proven']);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

        self::assertTrue($guard->check($context));

    }//end testDecidedFraudProvenAllowsInvalidate()

    /**
     * A FraudCase not yet decided blocks invalidate.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-is-blocked-without-a-fraud-proven-decision
     */
    public function testNotYetDecidedBlocks(): void
    {
        foreach (['reported', 'hearing-scheduled', 'heard'] as $state) {
            $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => $state]);
            $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

            self::assertFalse($guard->check($context), "state '{$state}' should block invalidate");
        }

    }//end testNotYetDecidedBlocks()

    /**
     * A FraudCase decided unfounded blocks invalidate.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-invalidate-is-blocked-without-a-fraud-proven-decision
     */
    public function testDecidedUnfoundedBlocks(): void
    {
        $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'unfounded']);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

        self::assertFalse($guard->check($context));

    }//end testDecidedUnfoundedBlocks()

    /**
     * An unresolvable fraudCaseId fails closed.
     *
     * @return void
     */
    public function testUnresolvableFraudCaseFailsClosed(): void
    {
        $guard   = $this->makeGuard(fraudCase: null);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-missing']];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableFraudCaseFailsClosed()
}//end class
