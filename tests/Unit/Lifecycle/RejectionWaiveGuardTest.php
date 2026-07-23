<?php

/**
 * Scholiq RejectionWaiveGuard unit tests.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\RejectionWaiveGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for RejectionWaiveGuard::check() — the ExchangeRejection
 * `open|corrected → waived` transition.
 */
class RejectionWaiveGuardTest extends TestCase
{

    /**
     * Build a guard whose user/group managers report the given group
     * membership for a known 'actor-1' user.
     *
     * @param string[] $groups Group IDs 'actor-1' belongs to.
     *
     * @return RejectionWaiveGuard
     */
    private function makeGuard(array $groups): RejectionWaiveGuard
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

        return new RejectionWaiveGuard($groupManager, $userManager, new NullLogger());

    }//end makeGuard()

    /**
     * A coordinator waiving with a non-empty reason is allowed, and
     * waivedBy/waivedAt are stamped server-side into the payload.
     *
     * @return void
     */
    public function testCoordinatorWithReasonIsAllowedAndStamped(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => 'DUO-fout is een bekend platformprobleem, geen actie nodig.'],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['waivedBy']);
        self::assertNotEmpty($context['payload']['waivedAt']);
        self::assertSame(
            'DUO-fout is een bekend platformprobleem, geen actie nodig.',
            $context['payload']['waiveReason']
        );

    }//end testCoordinatorWithReasonIsAllowedAndStamped()

    /**
     * An admin waiving with a reason is also allowed.
     *
     * @return void
     */
    public function testAdminWithReasonIsAllowed(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'corrected'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => 'Niet meer relevant.'],
        ];

        self::assertTrue($guard->check($context));

    }//end testAdminWithReasonIsAllowed()

    /**
     * A caller-supplied waivedBy is overwritten with the actual actor.
     *
     * @return void
     */
    public function testCallerSuppliedWaivedByIsOverwritten(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => 'Geldige reden.', 'waivedBy' => 'someone-else'],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['waivedBy']);

    }//end testCallerSuppliedWaivedByIsOverwritten()

    /**
     * Waiving with an empty waiveReason is refused.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-waiving-without-a-reason-is-refused
     */
    public function testEmptyReasonRefused(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => ''],
        ];

        self::assertFalse($guard->check($context));

    }//end testEmptyReasonRefused()

    /**
     * Waiving with a whitespace-only waiveReason is refused.
     *
     * @return void
     */
    public function testWhitespaceOnlyReasonRefused(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => '   '],
        ];

        self::assertFalse($guard->check($context));

    }//end testWhitespaceOnlyReasonRefused()

    /**
     * Waiving with no waiveReason key at all is refused.
     *
     * @return void
     */
    public function testMissingReasonRefused(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testMissingReasonRefused()

    /**
     * A learner (no privileged group) is denied even with a valid reason.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-non-authorised-user-cannot-resubmit-or-waive
     */
    public function testUnauthorisedActorIsDenied(): void
    {
        $guard   = $this->makeGuard([]);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'actor'   => 'actor-1',
            'payload' => ['waiveReason' => 'Valid reason.'],
        ];

        self::assertFalse($guard->check($context));

    }//end testUnauthorisedActorIsDenied()

    /**
     * No actor in the transition context is denied.
     *
     * @return void
     */
    public function testNoActorIsDenied(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'rej-1', 'status' => 'open'],
            'payload' => ['waiveReason' => 'Valid reason.'],
        ];

        self::assertFalse($guard->check($context));

    }//end testNoActorIsDenied()
}//end class
