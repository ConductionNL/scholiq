<?php

/**
 * Unit tests for WalletOfferDelegationService.
 *
 * Covers the `offerToWallet` guard contract per
 * `specs/certification/spec.md`: a handled openconnector response records
 * the offer fields and clears any prior error; an unreachable/misconfigured
 * openconnector fails closed with `walletOfferError` set and the transition
 * blocked; a Credential with no signed payload is rejected before any HTTP
 * call is made.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\WalletOfferDelegationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WalletOfferDelegationService::check().
 */
class WalletOfferDelegationServiceTest extends TestCase
{

    /**
     * HTTP client-service mock.
     *
     * @var IClientService&\PHPUnit\Framework\MockObject\MockObject
     */
    private IClientService $clientService;

    /**
     * URL generator mock.
     *
     * @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject
     */
    private IURLGenerator $urlGenerator;

    /**
     * App-config mock.
     *
     * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clientService = $this->createMock(IClientService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return WalletOfferDelegationService
     */
    private function service(): WalletOfferDelegationService
    {
        return new WalletOfferDelegationService(
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );
    }//end service()

    /**
     * A handled openconnector response records the offer fields and clears
     * any prior error.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-pushing-an-issued-credential-to-the-wallet-records-the-offer
     */
    public function testHandledResponseRecordsOfferFields(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(
            json_encode(
                [
                    'offerUrl'           => 'openid-credential-offer://?credential_offer_uri=...',
                    'credentialOfferUri' => 'https://openconnector.example/index.php/apps/openconnector/api/eudi/credential-offers/offer-uuid-1',
                    'qrPayload'          => 'openid-credential-offer://?credential_offer_uri=...',
                ]
            )
        );

        $capturedUrl     = null;
        $capturedOptions = null;

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

        $context = [
            'object'     => [
                'id'                  => 'credential-1',
                'kind'                => 'diploma',
                'learnerId'           => 'learner-1',
                'edciPayload'         => null,
                'openbadges3Payload'  => ['credentialSubject' => ['id' => 'urn:scholiq:learner:learner-1']],
                'walletOfferStatus'   => null,
                'walletOfferError'    => 'previous failure',
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('offered', $context['object']['walletOfferStatus']);
        self::assertSame('offer-uuid-1', $context['object']['walletAttestationRef']);
        self::assertNotEmpty($context['object']['walletOfferedAt']);
        self::assertNull($context['object']['walletOfferError']);

        self::assertStringContainsString('/apps/openconnector/api/eudi/credential-offers', (string) $capturedUrl);
        self::assertSame('Bearer token-abc', $capturedOptions['headers']['Authorization']);
        self::assertSame('jwt_vc_json', $capturedOptions['json']['format']);
        self::assertSame('edci-diploma', $capturedOptions['json']['credentialConfigurationId']);
    }//end testHandledResponseRecordsOfferFields()

    /**
     * A badge Credential is offered under the open-badges-3 configuration id.
     *
     * @return void
     */
    public function testBadgeUsesOpenBadges3ConfigurationId(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(
            json_encode(['credentialOfferUri' => 'https://openconnector.example/.../credential-offers/offer-uuid-2'])
        );

        $capturedOptions = null;
        $client          = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
                $capturedOptions = $options;
                return $response;
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                 => 'credential-2',
                'kind'               => 'badge',
                'learnerId'          => 'learner-2',
                'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:scholiq:learner:learner-2']],
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        self::assertTrue($this->service()->check($context));
        self::assertSame('open-badges-3', $capturedOptions['json']['credentialConfigurationId']);
    }//end testBadgeUsesOpenBadges3ConfigurationId()

    /**
     * A Credential with no signed payload is rejected before any HTTP call.
     *
     * @return void
     */
    public function testNoPayloadFailsClosedWithoutCallingOpenConnector(): void
    {
        $this->clientService->expects($this->never())->method('newClient');

        $context = [
            'object'     => [
                'id'                 => 'credential-3',
                'kind'               => 'diploma',
                'learnerId'          => 'learner-3',
                'edciPayload'        => null,
                'openbadges3Payload' => null,
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $result = $this->service()->check($context);

        self::assertFalse($result);
        self::assertNotEmpty($context['object']['walletOfferError']);
    }//end testNoPayloadFailsClosedWithoutCallingOpenConnector()

    /**
     * No configured API token fails closed without ever reaching the client.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-openconnector-unreachable-blocks-the-offer-and-records-the-error
     */
    public function testMissingTokenFailsClosed(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->clientService->expects($this->never())->method('newClient');

        $context = [
            'object'     => [
                'id'                 => 'credential-4',
                'kind'               => 'diploma',
                'learnerId'          => 'learner-4',
                'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:scholiq:learner:learner-4']],
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $result = $this->service()->check($context);

        self::assertFalse($result);
        self::assertNotEmpty($context['object']['walletOfferError']);
        self::assertArrayNotHasKey('walletAttestationRef', $context['object']);
    }//end testMissingTokenFailsClosed()

    /**
     * OpenConnector unreachable (HTTP client throws) blocks the transition
     * and records a clear error, never leaving partial wallet-offer state.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-openconnector-unreachable-blocks-the-offer-and-records-the-error
     */
    public function testOpenConnectorUnreachableFailsClosed(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                 => 'credential-5',
                'kind'               => 'diploma',
                'learnerId'          => 'learner-5',
                'walletOfferStatus'  => null,
                'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:scholiq:learner:learner-5']],
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $result = $this->service()->check($context);

        self::assertFalse($result);
        self::assertNull($context['object']['walletOfferStatus']);
        self::assertNotEmpty($context['object']['walletOfferError']);
    }//end testOpenConnectorUnreachableFailsClosed()

    /**
     * A response with no usable credentialOfferUri fails closed.
     *
     * @return void
     */
    public function testUnresolvableResponseFailsClosed(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['offerUrl' => 'openid-credential-offer://...']));

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                 => 'credential-6',
                'kind'               => 'diploma',
                'learnerId'          => 'learner-6',
                'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:scholiq:learner:learner-6']],
            ],
            'transition' => 'offerToWallet',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $result = $this->service()->check($context);

        self::assertFalse($result);
        self::assertNotEmpty($context['object']['walletOfferError']);
    }//end testUnresolvableResponseFailsClosed()
}//end class
