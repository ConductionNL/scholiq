<?php

/**
 * Scholiq SessionChangeGuard unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\SessionChangeGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionChangeGuard::check() — the Session cancel /
 * substitute-teacher / substitute-teacher-in-progress transitions.
 */
class SessionChangeGuardTest extends TestCase
{

    /**
     * Build a guard whose group/user managers report the given group
     * membership for a known 'actor-1' user, and whose ObjectService returns
     * the given Cohort row for any cohort query.
     *
     * @param string[]                  $groups Group IDs 'actor-1' belongs to.
     * @param array<string,mixed>|null $cohort Cohort row to return, or null for "not found".
     *
     * @return SessionChangeGuard
     */
    private function makeGuard(array $groups, ?array $cohort): SessionChangeGuard
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

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use ($cohort): array {
                if (($config['schema'] ?? '') === 'cohort') {
                    return $cohort === null ? [] : [$cohort];
                }
                return [];
            }
        );

        return new SessionChangeGuard($objectService, $groupManager, $userManager, new NullLogger());

    }//end makeGuard()

    /**
     * A cohort teacher cancels a Session with a reason — allowed.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
     */
    public function testCohortTeacherCancelsWithReasonIsAllowed(): void
    {
        $guard   = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['actor-1']]);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'cancel',
            'actor'      => 'actor-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testCohortTeacherCancelsWithReasonIsAllowed()

    /**
     * Cancelling without a reason is refused.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
     */
    public function testCancelWithoutReasonIsRefused(): void
    {
        $guard   = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['actor-1']]);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a'],
            'transition' => 'cancel',
            'actor'      => 'actor-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testCancelWithoutReasonIsRefused()

    /**
     * A teacher outside the cohort (and not admin/coordinator) cannot cancel.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-teacher-outside-the-cohort-cannot-substitute-or-cancel
     */
    public function testOutsideTeacherCannotCancel(): void
    {
        $guard   = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['someone-else']]);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'cancel',
            'actor'      => 'actor-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testOutsideTeacherCannotCancel()

    /**
     * An admin may cancel a Session even without being a cohort teacher.
     *
     * @return void
     */
    public function testAdminMayCancelWithoutCohortMembership(): void
    {
        $guard   = $this->makeGuard(['admin'], ['id' => 'cohort-1', 'teacherIds' => ['someone-else']]);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'timetable-change'],
            'transition' => 'cancel',
            'actor'      => 'actor-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testAdminMayCancelWithoutCohortMembership()

    /**
     * A coordinator may assign a substitute teacher.
     *
     * @return void
     */
    public function testCoordinatorMaySubstitute(): void
    {
        $guard   = $this->makeGuard(['coordinator'], ['id' => 'cohort-1', 'teacherIds' => []]);
        $context = [
            'object'     => [
                'cohortId'            => 'cohort-1',
                'tenant_id'           => 'tenant-a',
                'changeReasonKind'    => 'teacher-absence',
                'substituteTeacherId' => 'sub-1',
            ],
            'transition' => 'substitute-teacher',
            'actor'      => 'actor-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testCoordinatorMaySubstitute()

    /**
     * substitute-teacher without substituteTeacherId set is refused.
     *
     * @return void
     */
    public function testSubstituteWithoutTeacherIdIsRefused(): void
    {
        $guard   = $this->makeGuard(['admin'], null);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'substitute-teacher',
            'actor'      => 'actor-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testSubstituteWithoutTeacherIdIsRefused()

    /**
     * substitute-teacher-in-progress is gated the same way as substitute-teacher.
     *
     * @return void
     */
    public function testSubstituteInProgressRequiresTeacherId(): void
    {
        $guard   = $this->makeGuard(['admin'], null);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'substitute-teacher-in-progress',
            'actor'      => 'actor-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testSubstituteInProgressRequiresTeacherId()

    /**
     * No actor in the transition context is refused.
     *
     * @return void
     */
    public function testNoActorIsRefused(): void
    {
        $guard   = $this->makeGuard(['admin'], null);
        $context = [
            'object'     => ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'cancel',
        ];

        self::assertFalse($guard->check($context));

    }//end testNoActorIsRefused()

    /**
     * A missing Cohort fails closed even for a changeReasonKind-complete request.
     *
     * @return void
     */
    public function testMissingCohortFailsClosed(): void
    {
        $guard   = $this->makeGuard([], null);
        $context = [
            'object'     => ['cohortId' => 'cohort-missing', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence'],
            'transition' => 'cancel',
            'actor'      => 'actor-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testMissingCohortFailsClosed()
}//end class
