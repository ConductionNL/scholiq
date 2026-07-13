<?php

/**
 * Scholiq ReportCardReopenGuard unit tests.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ReportCardReopenGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportCardReopenGuard (finalised -> rapportvergadering-review).
 */
class ReportCardReopenGuardTest extends TestCase
{

    /**
     * Build a guard whose group manager reports the given groups for the actor.
     *
     * @param array<string> $actorGroups Group IDs the actor belongs to.
     * @param bool          $actorExists Whether the user manager resolves the actor.
     *
     * @return ReportCardReopenGuard
     */
    private function makeGuard(array $actorGroups, bool $actorExists=true): ReportCardReopenGuard
    {
        $user = $this->createMock(IUser::class);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($actorExists === true ? $user : null);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn($actorGroups);

        return new ReportCardReopenGuard($groupManager, $userManager, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * An admin/mentor/principal may reopen a finalised report card.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
     */
    public function testOverrideRolesAllowReopen(): void
    {
        foreach (['admin', 'mentor', 'principal'] as $role) {
            $guard   = $this->makeGuard([$role]);
            $context = ['object' => ['id' => 'card-1'], 'actor' => 'staff-1'];

            self::assertTrue($guard->check($context), "role '{$role}' should be allowed to reopen");
        }

    }//end testOverrideRolesAllowReopen()

    /**
     * A subject teacher (no override role) cannot reopen.
     *
     * @return void
     */
    public function testNonOverrideRoleDeniesReopen(): void
    {
        $guard   = $this->makeGuard(['teacher']);
        $context = ['object' => ['id' => 'card-1'], 'actor' => 'teacher-1'];

        self::assertFalse($guard->check($context));

    }//end testNonOverrideRoleDeniesReopen()

    /**
     * No actor in the context denies reopen.
     *
     * @return void
     */
    public function testMissingActorDeniesReopen(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = ['object' => ['id' => 'card-1'], 'actor' => ''];

        self::assertFalse($guard->check($context));

    }//end testMissingActorDeniesReopen()

    /**
     * An unresolvable actor (not a valid NC user) denies reopen.
     *
     * @return void
     */
    public function testUnresolvableActorDeniesReopen(): void
    {
        $guard   = $this->makeGuard(['admin'], actorExists: false);
        $context = ['object' => ['id' => 'card-1'], 'actor' => 'ghost-1'];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableActorDeniesReopen()
}//end class
