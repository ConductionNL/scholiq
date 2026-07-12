<?php

/**
 * Scholiq FraudCaseBlockGuard unit tests.
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-permanently-fraud-proven-link-blocks-publish-even-after-decision
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\FraudCaseBlockGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FraudCaseBlockGuard (GradeEntry publish/republish).
 */
class FraudCaseBlockGuardTest extends TestCase
{

    /**
     * Build a guard whose ObjectService::find() returns the given FraudCase (or null).
     *
     * @param array<string,mixed>|null $fraudCase FraudCase data, or null (not found).
     *
     * @return FraudCaseBlockGuard
     */
    private function makeGuard(?array $fraudCase): FraudCaseBlockGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($fraudCase);

        return new FraudCaseBlockGuard($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * No fraudCaseId set → allowed unconditionally.
     *
     * @return void
     */
    public function testNoFraudCaseIdAllowsUnconditionally(): void
    {
        $guard   = $this->makeGuard(fraudCase: null);
        $context = ['object' => ['id' => 'entry-1']];

        self::assertTrue($guard->check($context));

    }//end testNoFraudCaseIdAllowsUnconditionally()

    /**
     * A linked FraudCase in an open state (reported/hearing-scheduled/heard) blocks publish.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
     */
    public function testOpenFraudCaseBlocksPublish(): void
    {
        foreach (['reported', 'hearing-scheduled', 'heard'] as $state) {
            $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => $state]);
            $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

            self::assertFalse($guard->check($context), "state '{$state}' should block publish");
        }

    }//end testOpenFraudCaseBlocksPublish()

    /**
     * A FraudCase decided fraud-proven blocks publish permanently.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-permanently-fraud-proven-link-blocks-publish-even-after-decision
     */
    public function testDecidedFraudProvenBlocksPublishPermanently(): void
    {
        $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'fraud-proven']);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

        self::assertFalse($guard->check($context));

    }//end testDecidedFraudProvenBlocksPublishPermanently()

    /**
     * A FraudCase decided unfounded allows publish.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
     */
    public function testDecidedUnfoundedAllowsPublish(): void
    {
        $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'decided', 'verdict' => 'unfounded']);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

        self::assertTrue($guard->check($context));

    }//end testDecidedUnfoundedAllowsPublish()

    /**
     * A dismissed FraudCase allows publish.
     *
     * @return void
     */
    public function testDismissedFraudCaseAllowsPublish(): void
    {
        $guard   = $this->makeGuard(fraudCase: ['id' => 'case-1', 'lifecycle' => 'dismissed']);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1']];

        self::assertTrue($guard->check($context));

    }//end testDismissedFraudCaseAllowsPublish()

    /**
     * A fraudCaseId that resolves to no FraudCase (deleted/bad link) fails closed.
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
