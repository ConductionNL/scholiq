<?php

/**
 * Unit tests for LtiToolPlacementController.
 *
 * Covers the launch-delegation contract: a valid placement's launch call
 * forwards the correct openconnectorDeploymentId and returns the mocked
 * OpenConnector response unmodified (task 2.4); OpenConnector
 * unreachable/non-2xx returns a clear error response, not a silent empty
 * body (task 2.5); and the bearer-token header reuses the same
 * scholiq.openconnector_api_token config key DataExchangeRunHandler already
 * uses.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.4
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\LtiToolPlacementController;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LtiToolPlacementController::launch().
 */
class LtiToolPlacementControllerTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * User-session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

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
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return LtiToolPlacementController
     */
    private function controller(): LtiToolPlacementController
    {
        return new LtiToolPlacementController(
            request: $this->createMock(IRequest::class),
            userSession: $this->userSession,
            objectService: $this->objectService,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );
    }//end controller()

    /**
     * Sign the caller in as the given uid.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signInAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signInAs()

    /**
     * A valid placement's launch call forwards the correct
     * openconnectorDeploymentId and returns the mocked response unmodified.
     *
     * @return void
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.4
     */
    public function testLaunchForwardsDeploymentIdAndReturnsResponseUnmodified(): void
    {
        $this->signInAs('learner-1');

        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema): ?array {
                if ($register === 'scholiq' && $schema === 'lti-tool-placement' && $id === 'placement-1') {
                    return [
                        'id'                        => 'placement-1',
                        'openconnectorDeploymentId' => 'deployment-uuid-1',
                        'launchMode'                => 'resource-link',
                    ];
                }

                return null;
            }
        );

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );

        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $capturedUrl     = null;
        $capturedOptions = null;

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['formActionUrl' => 'https://tool.example/launch', 'idToken' => 'jwt-value']));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->willReturnCallback(
                function (string $url, array $options) use (&$capturedUrl, &$capturedOptions, $response): IResponse {
                    $capturedUrl     = $url;
                    $capturedOptions = $options;
                    return $response;
                }
            );

        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller()->launch(placementId: 'placement-1');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame(
            ['formActionUrl' => 'https://tool.example/launch', 'idToken' => 'jwt-value', 'launchMode' => 'resource-link'],
            $result->getData()
        );

        // Forwarded the deployment UUID, not the placement UUID, in the URL.
        self::assertStringContainsString('deployment-uuid-1', (string) $capturedUrl);
        self::assertStringNotContainsString('placement-1', (string) $capturedUrl);

        // Reused the same bearer-token header shape DataExchangeRunHandler uses.
        self::assertSame('Bearer token-abc', $capturedOptions['headers']['Authorization']);
        self::assertSame('learner-1', $capturedOptions['json']['subject']);
    }//end testLaunchForwardsDeploymentIdAndReturnsResponseUnmodified()

    /**
     * OpenConnector unreachable / non-2xx: launch() returns a clear error
     * response, not a silent empty body.
     *
     * @return void
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.5
     */
    public function testLaunchReturnsClearErrorWhenOpenConnectorUnreachable(): void
    {
        $this->signInAs('learner-1');

        $this->objectService->method('find')->willReturn(
            [
                'id'                        => 'placement-1',
                'openconnectorDeploymentId' => 'deployment-uuid-1',
                'launchMode'                => 'resource-link',
            ]
        );

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller()->launch(placementId: 'placement-1');

        self::assertSame(Http::STATUS_BAD_GATEWAY, $result->getStatus());
        self::assertArrayHasKey('error', $result->getData());
        self::assertNotSame('', $result->getData()['error']);
    }//end testLaunchReturnsClearErrorWhenOpenConnectorUnreachable()

    /**
     * A placement that does not exist returns 404, never a silent empty body.
     *
     * @return void
     */
    public function testLaunchReturnsNotFoundForUnknownPlacement(): void
    {
        $this->signInAs('learner-1');
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller()->launch(placementId: 'nope');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }//end testLaunchReturnsNotFoundForUnknownPlacement()

    /**
     * An unauthenticated caller receives 401, never proceeds to launch.
     *
     * @return void
     */
    public function testLaunchRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->objectService->expects($this->never())->method('find');

        $result = $this->controller()->launch(placementId: 'placement-1');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
    }//end testLaunchRequiresAuthentication()
}//end class
