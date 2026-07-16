<?php

/**
 * Scholiq ExamAccommodationApprovalGuard unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-exam-accommodations-are-recorded-as-approved-evidence-backed-entitlements
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ExamAccommodationApprovalGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ExamAccommodationApprovalGuard::check() — the `approve` transition.
 */
class ExamAccommodationApprovalGuardTest extends TestCase
{

    /**
     * Build a guard whose group/user managers report the given group
     * membership for a known 'actor-1' user.
     *
     * @param string[] $groups Group IDs 'actor-1' belongs to.
     *
     * @return ExamAccommodationApprovalGuard
     */
    private function makeGuard(array $groups): ExamAccommodationApprovalGuard
    {
        $user = $this->createMock(IUser::class);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(
            static function (string $uid) use ($user): ?IUser {
                return $uid === 'actor-1' ? $user : null;
            }
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn($groups);

        return new ExamAccommodationApprovalGuard($groupManager, $userManager, new NullLogger());

    }//end makeGuard()

    /**
     * A mentor may approve, and approvedBy is stamped server-side.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-requests-an-accommodation-and-a-mentor-approves-it
     */
    public function testMentorApprovalIsAllowedAndStamped(): void
    {
        $guard   = $this->makeGuard(['mentor']);
        $context = ['actor' => 'actor-1', 'payload' => []];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['approvedBy']);

    }//end testMentorApprovalIsAllowedAndStamped()

    /**
     * An admin may approve.
     *
     * @return void
     */
    public function testAdminApprovalIsAllowed(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = ['actor' => 'actor-1', 'payload' => []];

        self::assertTrue($guard->check($context));

    }//end testAdminApprovalIsAllowed()

    /**
     * A compliance-officer may approve.
     *
     * @return void
     */
    public function testComplianceOfficerApprovalIsAllowed(): void
    {
        $guard   = $this->makeGuard(['compliance-officer']);
        $context = ['actor' => 'actor-1', 'payload' => []];

        self::assertTrue($guard->check($context));

    }//end testComplianceOfficerApprovalIsAllowed()

    /**
     * A learner (no privileged group) cannot self-approve.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-cannot-self-approve-their-own-accommodation
     */
    public function testLearnerCannotSelfApprove(): void
    {
        $guard   = $this->makeGuard([]);
        $context = ['actor' => 'actor-1', 'payload' => []];

        self::assertFalse($guard->check($context));
        self::assertArrayNotHasKey('approvedBy', $context['payload']);

    }//end testLearnerCannotSelfApprove()

    /**
     * A caller-supplied approvedBy is overwritten with the actual actor.
     *
     * @return void
     */
    public function testCallerSuppliedApprovedByIsOverwritten(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = ['actor' => 'actor-1', 'payload' => ['approvedBy' => 'someone-else']];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['approvedBy']);

    }//end testCallerSuppliedApprovedByIsOverwritten()

    /**
     * No actor in the transition context is denied.
     *
     * @return void
     */
    public function testNoActorIsDenied(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = ['payload' => []];

        self::assertFalse($guard->check($context));

    }//end testNoActorIsDenied()
}//end class
