<?php

/**
 * Unit tests for LtiAgsScorePollJob.
 *
 * Covers the AGS-to-GradeEntry bridge: a pulled AGS score message for a
 * configured placement creates exactly one concept GradeEntry with the
 * correct componentId/curriculumPlanId/ltiAgsResultId (task 4.7); a
 * redelivered message (same ltiToolPlacementId + ltiAgsResultId already on
 * an existing GradeEntry) does not create a duplicate (task 4.8); and a
 * message whose deploymentUuid matches no LtiToolPlacement is skipped
 * without throwing (task 4.9).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Cron
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
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.7
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.8
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.9
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Cron;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Cron\LtiAgsScorePollJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LtiAgsScorePollJob::run().
 */
class LtiAgsScorePollJobTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * HTTP client-service mock.
     *
     * @var IClientService&MockObject
     */
    private IClientService&MockObject $clientService;

    /**
     * URL generator mock.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator&MockObject $urlGenerator;

    /**
     * App-config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * @var array<string,string>
     */
    private array $configValues = [];

    /**
     * The placement fixture returned for the 'lti-tool-placement' schema.
     *
     * @var array<string,mixed>|null
     */
    private ?array $placementFixture = null;

    /**
     * The GradeEntry fixtures ObjectService::findAll('grade-entry') should
     * report as already existing (idempotency fixtures).
     *
     * @var array<int,array<string,mixed>>
     */
    private array $existingGradeEntries = [];

    /**
     * Objects saved via ObjectService::saveObject during the test.
     *
     * @var array<int,array{register:string,schema:string,object:array<string,mixed>}>
     */
    private array $savedObjects = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);

        $this->configValues = [
            'lti_ags_subscription_id' => 'sub-1',
            'lti_ags_pull_cursor'     => '',
            'openconnector_api_user'  => 'lti-service-user',
            'openconnector_api_token' => 'token-abc',
        ];

        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default=''): string {
                return $this->configValues[$key] ?? $default;
            }
        );
        $this->appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->configValues[$key] = $value;
                return true;
            }
        );

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );

        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'lti-tool-placement') {
                    if ($this->placementFixture === null) {
                        return [];
                    }

                    if (($filters['openconnectorDeploymentId'] ?? null) !== $this->placementFixture['openconnectorDeploymentId']) {
                        return [];
                    }

                    return [$this->placementFixture];
                }

                if ($schema === 'grade-entry') {
                    $placementId = $filters['ltiToolPlacementId'] ?? null;
                    $resultId    = $filters['ltiAgsResultId'] ?? null;
                    return array_values(
                        array_filter(
                            $this->existingGradeEntries,
                            static fn (array $e): bool => ($e['ltiToolPlacementId'] ?? null) === $placementId
                                && ($e['ltiAgsResultId'] ?? null) === $resultId
                        )
                    );
                }

                return [];
            }
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object): array {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                $object['id']         = 'grade-entry-new';
                return $object;
            }
        );
    }//end setUp()

    /**
     * Build the job under test, wired to return the given pulled messages.
     *
     * @param array<int,array<string,mixed>> $messages The messages the pull() HTTP call should return.
     * @param string|null                    $cursor   The cursor value the pull() call should return.
     *
     * @return LtiAgsScorePollJob
     */
    private function job(array $messages, ?string $cursor='cursor-1'): LtiAgsScorePollJob
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['messages' => $messages, 'cursor' => $cursor]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);

        $this->clientService->method('newClient')->willReturn($client);

        return new LtiAgsScorePollJob(
            time: $this->createMock(ITimeFactory::class),
            objectService: $this->objectService,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );
    }//end job()

    /**
     * A pulled AGS message for a configured placement creates exactly one
     * concept GradeEntry with the correct componentId/curriculumPlanId/ltiAgsResultId.
     *
     * @return void
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.7
     */
    public function testCreatesConceptGradeEntryForConfiguredPlacement(): void
    {
        $this->placementFixture = [
            'id'                        => 'placement-1',
            'openconnectorDeploymentId' => 'deployment-1',
            'curriculumPlanId'          => 'plan-1',
            'gradeEntryComponentId'     => 'component-1',
            'gradeScaleId'              => '',
            'tenant_id'                 => 'tenant-1',
        ];

        $message = [
            'id'      => 'msg-1',
            'payload' => [
                'deploymentUuid' => 'deployment-1',
                'score'          => [
                    'userId'       => 'learner-1',
                    'scoreGiven'   => 8.5,
                    'scoreMaximum' => 10,
                ],
            ],
        ];

        $job = $this->job(messages: [$message]);
        $job->run(null);

        self::assertCount(1, $this->savedObjects);
        $saved = $this->savedObjects[0];
        self::assertSame('scholiq', $saved['register']);
        self::assertSame('grade-entry', $saved['schema']);
        self::assertSame('lti-ags', $saved['object']['sourceKind']);
        self::assertSame('component-1', $saved['object']['componentId']);
        self::assertSame('plan-1', $saved['object']['curriculumPlanId']);
        self::assertSame('placement-1', $saved['object']['ltiToolPlacementId']);
        self::assertSame('msg-1', $saved['object']['ltiAgsResultId']);
        self::assertSame('learner-1', $saved['object']['learnerId']);
        self::assertSame('concept', $saved['object']['lifecycle']);
        self::assertSame(8.5, $saved['object']['value']);

        // Cursor advanced.
        self::assertSame('cursor-1', $this->configValues['lti_ags_pull_cursor']);
    }//end testCreatesConceptGradeEntryForConfiguredPlacement()

    /**
     * Pulling the same message twice (simulating a redelivery) creates
     * exactly one GradeEntry, not two.
     *
     * @return void
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.8
     */
    public function testRedeliveredMessageDoesNotCreateDuplicate(): void
    {
        $this->placementFixture = [
            'id'                        => 'placement-1',
            'openconnectorDeploymentId' => 'deployment-1',
            'curriculumPlanId'          => 'plan-1',
            'gradeEntryComponentId'     => 'component-1',
            'gradeScaleId'              => '',
            'tenant_id'                 => 'tenant-1',
        ];

        // Simulate a GradeEntry already created for this exact pair.
        $this->existingGradeEntries = [
            ['ltiToolPlacementId' => 'placement-1', 'ltiAgsResultId' => 'msg-1'],
        ];

        $message = [
            'id'      => 'msg-1',
            'payload' => [
                'deploymentUuid' => 'deployment-1',
                'score'          => ['userId' => 'learner-1', 'scoreGiven' => 8.5, 'scoreMaximum' => 10],
            ],
        ];

        $job = $this->job(messages: [$message]);
        $job->run(null);

        self::assertCount(0, $this->savedObjects);
    }//end testRedeliveredMessageDoesNotCreateDuplicate()

    /**
     * A message whose deploymentUuid matches no LtiToolPlacement is logged
     * and skipped without throwing.
     *
     * @return void
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.9
     */
    public function testOrphanMessageIsSkippedWithoutThrowing(): void
    {
        $this->placementFixture = null;

        $message = [
            'id'      => 'msg-orphan',
            'payload' => [
                'deploymentUuid' => 'deployment-unknown',
                'score'          => ['userId' => 'learner-1', 'scoreGiven' => 5, 'scoreMaximum' => 10],
            ],
        ];

        $job = $this->job(messages: [$message]);

        // Must not throw.
        $job->run(null);

        self::assertCount(0, $this->savedObjects);
    }//end testOrphanMessageIsSkippedWithoutThrowing()

    /**
     * A job with no configured subscription id no-ops without calling the
     * HTTP client at all.
     *
     * @return void
     */
    public function testNoOpsWhenSubscriptionNotConfigured(): void
    {
        $this->configValues['lti_ags_subscription_id'] = '';

        $this->clientService->expects($this->never())->method('newClient');

        $job = new LtiAgsScorePollJob(
            time: $this->createMock(ITimeFactory::class),
            objectService: $this->objectService,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );

        $job->run(null);

        self::assertCount(0, $this->savedObjects);
    }//end testNoOpsWhenSubscriptionNotConfigured()
}//end class
