<?php

/**
 * Unit tests for DashboardRoleService.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\DashboardRoleService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Scholiq\Service\DashboardRoleService
 */
class DashboardRoleServiceTest extends TestCase
{
    /**
     * Build a service whose group manager answers a fixed membership map.
     *
     * @param bool                $isAdmin Whether the user is in the admin group.
     * @param array<string, bool> $groups  Map of group id => membership.
     *
     * @return DashboardRoleService
     */
    private function serviceWith(bool $isAdmin, array $groups): DashboardRoleService
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid) use ($groups): bool {
                return ($groups[$gid] ?? false);
            }
        );

        return new DashboardRoleService(groupManager: $groupManager);
    }//end serviceWith()

    /**
     * Build a stub user with a fixed UID.
     *
     * @return IUser
     */
    private function user(): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return $user;
    }//end user()

    /**
     * An admin-group member always resolves to the admin role + admin view.
     *
     * @return void
     */
    public function testAdminGroupResolvesToAdmin(): void
    {
        $service = $this->serviceWith(isAdmin: true, groups: []);
        $user    = $this->user();

        $this->assertSame('admin', $service->resolvePrimaryRole(user: $user));
        $this->assertSame('admin', $service->resolveDefaultView(user: $user));
        $this->assertSame(['admin', 'teacher', 'student'], $service->resolveViews(user: $user));
    }//end testAdminGroupResolvesToAdmin()

    /**
     * An instructor (group-backed) resolves to the teacher view by default and
     * can also see the student view.
     *
     * @return void
     */
    public function testInstructorResolvesToTeacher(): void
    {
        $service = $this->serviceWith(isAdmin: false, groups: ['scholiq-instructor' => true]);
        $user    = $this->user();

        $this->assertSame('instructor', $service->resolvePrimaryRole(user: $user));
        $this->assertSame('teacher', $service->resolveDefaultView(user: $user));
        $this->assertSame(['teacher', 'student'], $service->resolveViews(user: $user));
    }//end testInstructorResolvesToTeacher()

    /**
     * A user with no Scholiq group is a learner and only sees the student view.
     *
     * @return void
     */
    public function testNoGroupResolvesToLearnerStudent(): void
    {
        $service = $this->serviceWith(isAdmin: false, groups: []);
        $user    = $this->user();

        $this->assertSame('learner', $service->resolvePrimaryRole(user: $user));
        $this->assertSame('student', $service->resolveDefaultView(user: $user));
        $this->assertSame(['student'], $service->resolveViews(user: $user));
    }//end testNoGroupResolvesToLearnerStudent()

    /**
     * Higher-priority group membership wins over lower-priority membership.
     *
     * @return void
     */
    public function testHighestPriorityGroupWins(): void
    {
        $service = $this->serviceWith(
            isAdmin: false,
            groups: ['scholiq-instructor' => true, 'scholiq-hr' => true]
        );
        $user = $this->user();

        // hr outranks instructor, and hr maps to the admin view.
        $this->assertSame('hr', $service->resolvePrimaryRole(user: $user));
        $this->assertSame('admin', $service->resolveDefaultView(user: $user));
    }//end testHighestPriorityGroupWins()
}//end class
