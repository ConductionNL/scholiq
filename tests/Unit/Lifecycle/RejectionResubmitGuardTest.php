<?php

/**
 * Scholiq RejectionResubmitGuard unit tests.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\RejectionResubmitGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for RejectionResubmitGuard::check() — the ExchangeRejection
 * `corrected → resubmitted` transition.
 */
class RejectionResubmitGuardTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a guard whose user/group managers report the given group
     * membership for a known 'actor-1' user, and whose ObjectService resolves
     * the originating job + records saveObject() calls.
     *
     * @param string[]                  $groups      Group IDs 'actor-1' belongs to.
     * @param array<string,mixed>|null  $originalJob The originating DataExchangeJob row findAll() returns, or null.
     * @param string|null               $newJobId    UUID to return for the new DataExchangeJob save, or null to
     *                                               simulate a save failure.
     *
     * @return RejectionResubmitGuard
     */
    private function makeGuard(array $groups, ?array $originalJob, ?string $newJobId='new-job-1'): RejectionResubmitGuard
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
            function (array $config) use ($originalJob): array {
                if (($config['schema'] ?? '') !== 'data-exchange-job') {
                    return [];
                }
                return $originalJob === null ? [] : [$originalJob];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) use ($newJobId) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

                if ($newJobId === null) {
                    return null;
                }

                return array_merge($object, ['id' => $newJobId]);
            }
        );

        return new RejectionResubmitGuard($objectService, $groupManager, $userManager, new NullLogger());

    }//end makeGuard()

    /**
     * A coordinator resubmitting a corrected rejection is allowed: exactly one
     * new DataExchangeJob is created with the original job's target/
     * mappingProfileId, scope.filters.id = sourceObjectId, and
     * resubmittedJobId is stamped into the transition payload.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
     */
    public function testCoordinatorResubmitCreatesScopedJobAndStampsLink(): void
    {
        $guard = $this->makeGuard(
            ['coordinator'],
            ['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']
        );

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'learner-profile',
                'learnerProfileId'  => 'lp-1',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertTrue($guard->check($context));

        self::assertCount(1, $this->savedObjects);
        $newJob = $this->savedObjects[0]['object'];
        self::assertSame('bron-rod', $newJob['target']);
        self::assertSame('profile-1', $newJob['mappingProfileId']);
        self::assertSame('learner-profile', $newJob['scope']['schema']);
        self::assertSame('lp-1', $newJob['scope']['filters']['id']);
        self::assertSame('queued', $newJob['lifecycle']);
        self::assertSame('actor-1', $newJob['requestedBy']);
        self::assertSame('tenant-a', $newJob['tenant_id']);

        self::assertSame('new-job-1', $context['payload']['resubmittedJobId']);

    }//end testCoordinatorResubmitCreatesScopedJobAndStampsLink()

    /**
     * An admin resubmitting is also allowed.
     *
     * @return void
     */
    public function testAdminResubmitIsAllowed(): void
    {
        $guard = $this->makeGuard(
            ['admin'],
            ['id' => 'job-orig', 'target' => 'leerplicht', 'mappingProfileId' => null]
        );

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'attendance-flag',
                'attendanceFlagId'  => 'flag-1',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertTrue($guard->check($context));

    }//end testAdminResubmitIsAllowed()

    /**
     * A caller-supplied resubmittedJobId is overwritten with the actual new
     * job id — never trust a caller-supplied value for this link.
     *
     * @return void
     */
    public function testCallerSuppliedResubmittedJobIdIsOverwritten(): void
    {
        $guard = $this->makeGuard(
            ['coordinator'],
            ['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']
        );

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'learner-profile',
                'learnerProfileId'  => 'lp-1',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => ['resubmittedJobId' => 'attacker-supplied-id'],
        ];

        self::assertTrue($guard->check($context));
        self::assertSame('new-job-1', $context['payload']['resubmittedJobId']);

    }//end testCallerSuppliedResubmittedJobIdIsOverwritten()

    /**
     * A learner (no privileged group) is denied — no job created.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-non-authorised-user-cannot-resubmit-or-waive
     */
    public function testUnauthorisedActorIsDenied(): void
    {
        $guard = $this->makeGuard(
            [],
            ['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']
        );

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'learner-profile',
                'learnerProfileId'  => 'lp-1',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));
        self::assertCount(0, $this->savedObjects);

    }//end testUnauthorisedActorIsDenied()

    /**
     * No actor in the transition context is denied.
     *
     * @return void
     */
    public function testNoActorIsDenied(): void
    {
        $guard = $this->makeGuard(['coordinator'], ['id' => 'job-orig', 'target' => 'bron-rod']);

        $context = [
            'object'  => ['id' => 'rej-1', 'sourceKind' => 'learner-profile', 'learnerProfileId' => 'lp-1', 'dataExchangeJobId' => 'job-orig'],
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testNoActorIsDenied()

    /**
     * An unresolvable originating DataExchangeJob denies the transition —
     * no partial job is created.
     *
     * @return void
     */
    public function testUnresolvableOriginalJobIsDenied(): void
    {
        $guard = $this->makeGuard(['coordinator'], null);

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'learner-profile',
                'learnerProfileId'  => 'lp-1',
                'dataExchangeJobId' => 'job-missing',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));
        self::assertCount(0, $this->savedObjects);

    }//end testUnresolvableOriginalJobIsDenied()

    /**
     * A job save failure denies the transition.
     *
     * @return void
     */
    public function testJobSaveFailureIsDenied(): void
    {
        $guard = $this->makeGuard(
            ['coordinator'],
            ['id' => 'job-orig', 'target' => 'bron-rod'],
            newJobId: null
        );

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'learner-profile',
                'learnerProfileId'  => 'lp-1',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));

    }//end testJobSaveFailureIsDenied()

    /**
     * An unsupported sourceKind on the rejection denies the transition.
     *
     * @return void
     */
    public function testUnsupportedSourceKindIsDenied(): void
    {
        $guard = $this->makeGuard(['coordinator'], ['id' => 'job-orig', 'target' => 'bron-rod']);

        $context = [
            'object' => [
                'id'                => 'rej-1',
                'sourceKind'        => 'cohort',
                'dataExchangeJobId' => 'job-orig',
                'tenant_id'         => 'tenant-a',
            ],
            'actor'   => 'actor-1',
            'payload' => [],
        ];

        self::assertFalse($guard->check($context));
        self::assertCount(0, $this->savedObjects);

    }//end testUnsupportedSourceKindIsDenied()
}//end class
