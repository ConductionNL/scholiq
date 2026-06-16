<?php

/**
 * Scholiq RoleSelector unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\RoleSelector;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the RoleSelector calculation helper used by the LearnerProfile schema.
 */
class RoleSelectorTest extends TestCase
{
    /**
     * Build a RoleSelector with a group manager that reports the given admin state.
     *
     * @param bool $isAdmin Whether groupManager::isAdmin() should return true.
     *
     * @return RoleSelector
     */
    private function makeSelector(bool $isAdmin=false): RoleSelector
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new RoleSelector($groupManager, $this->createMock(LoggerInterface::class));
    }//end makeSelector()

    /**
     * An NC admin-group member always resolves to 'admin'.
     *
     * @return void
     */
    public function testNcAdminOverridesDeclaredRoles(): void
    {
        $selector = $this->makeSelector(isAdmin: true);
        $user     = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $result = $selector->calculate(
            [
                'object' => ['roles' => ['learner']],
                'user'   => $user,
            ]
        );

        $this->assertSame('admin', $result);
    }//end testNcAdminOverridesDeclaredRoles()

    /**
     * The highest-priority declared role wins (compliance-officer beats learner).
     *
     * @return void
     */
    public function testHighestPriorityRoleWins(): void
    {
        $selector = $this->makeSelector();

        $result = $selector->calculate(
            ['object' => ['roles' => ['learner', 'compliance-officer', 'instructor']]]
        );

        $this->assertSame('compliance-officer', $result);
    }//end testHighestPriorityRoleWins()

    /**
     * No declared roles falls back to 'learner'.
     *
     * @return void
     */
    public function testNoRolesFallsBackToLearner(): void
    {
        $selector = $this->makeSelector();

        $this->assertSame('learner', $selector->calculate(['object' => []]));
        $this->assertSame('learner', $selector->calculate(['object' => ['roles' => []]]));
    }//end testNoRolesFallsBackToLearner()

    /**
     * A non-array roles value falls back to 'learner'.
     *
     * @return void
     */
    public function testNonArrayRolesFallsBackToLearner(): void
    {
        $selector = $this->makeSelector();

        $this->assertSame('learner', $selector->calculate(['object' => ['roles' => 'compliance-officer']]));
    }//end testNonArrayRolesFallsBackToLearner()

    /**
     * Unknown role strings are ignored; the known role still wins.
     *
     * @return void
     */
    public function testUnknownRolesAreIgnored(): void
    {
        $selector = $this->makeSelector();

        $result = $selector->calculate(['object' => ['roles' => ['gardener', 'hr', 'pilot']]]);

        $this->assertSame('hr', $result);
    }//end testUnknownRolesAreIgnored()
}//end class
