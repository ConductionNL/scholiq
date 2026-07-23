<?php

/**
 * Scholiq MunicipalityFeedbackGuard unit tests.
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
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\MunicipalityFeedbackGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for MunicipalityFeedbackGuard::check() — the DataExchangeJob
 * recordMunicipalityFeedback (succeeded → succeeded) self-loop transition.
 */
class MunicipalityFeedbackGuardTest extends TestCase
{
    /**
     * Build a guard whose user/group managers report the given group
     * membership for a known 'actor-1' user.
     *
     * @param string[] $groups Group IDs 'actor-1' belongs to.
     *
     * @return MunicipalityFeedbackGuard
     */
    private function makeGuard(array $groups): MunicipalityFeedbackGuard
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

        return new MunicipalityFeedbackGuard($groupManager, $userManager, new NullLogger());

    }//end makeGuard()

    /**
     * A coordinator recording feedback on a leerplicht job is allowed, and
     * recordedBy/receivedAt are stamped server-side into the payload.
     *
     * @return void
     *
     * @spec openspec/changes/verzuim-report-composer/specs/data-exchange/spec.md#scenario-coordinator-records-the-municipalitys-route-decision
     */
    public function testCoordinatorOnLeerplichtJobIsAllowedAndStamped(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'actor'   => 'actor-1',
            'payload' => ['municipalityFeedback' => ['masRoute' => 'jeugdhulp', 'note' => 'Route toegewezen.']],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['municipalityFeedback']['recordedBy']);
        self::assertNotEmpty($context['payload']['municipalityFeedback']['receivedAt']);
        self::assertSame('jeugdhulp', $context['payload']['municipalityFeedback']['masRoute']);

    }//end testCoordinatorOnLeerplichtJobIsAllowedAndStamped()

    /**
     * An admin recording feedback is also allowed.
     *
     * @return void
     */
    public function testAdminOnLeerplichtJobIsAllowed(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertTrue($guard->check($context));

    }//end testAdminOnLeerplichtJobIsAllowed()

    /**
     * A caller-supplied recordedBy is overwritten with the actual actor —
     * never trust a caller-supplied identity for this compliance field.
     *
     * @return void
     */
    public function testCallerSuppliedRecordedByIsOverwritten(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'actor'   => 'actor-1',
            'payload' => ['municipalityFeedback' => ['recordedBy' => 'someone-else']],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('actor-1', $context['payload']['municipalityFeedback']['recordedBy']);

    }//end testCallerSuppliedRecordedByIsOverwritten()

    /**
     * A caller-supplied receivedAt is preserved (not overwritten) when present.
     *
     * @return void
     */
    public function testCallerSuppliedReceivedAtIsPreserved(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'actor'   => 'actor-1',
            'payload' => ['municipalityFeedback' => ['receivedAt' => '2026-01-01T00:00:00+00:00']],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('2026-01-01T00:00:00+00:00', $context['payload']['municipalityFeedback']['receivedAt']);

    }//end testCallerSuppliedReceivedAtIsPreserved()

    /**
     * A learner (no privileged group) is denied.
     *
     * @return void
     */
    public function testUnauthorisedActorIsDenied(): void
    {
        $guard   = $this->makeGuard([]);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testUnauthorisedActorIsDenied()

    /**
     * A non-leerplicht target (e.g. oso) is denied even for a coordinator —
     * municipalityFeedback only applies to leerplicht reports.
     *
     * @return void
     */
    public function testNonLeerplichtTargetIsDenied(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'oso'],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testNonLeerplichtTargetIsDenied()

    /**
     * No actor in the transition context is denied.
     *
     * @return void
     */
    public function testNoActorIsDenied(): void
    {
        $guard   = $this->makeGuard(['coordinator']);
        $context = [
            'object'  => ['id' => 'job-1', 'target' => 'leerplicht'],
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testNoActorIsDenied()
}//end class
