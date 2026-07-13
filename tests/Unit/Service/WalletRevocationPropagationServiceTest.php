<?php

/**
 * Unit tests for WalletRevocationPropagationService.
 *
 * Covers the `revoke` guard's fail-soft contract per
 * `specs/certification/spec.md`: no-ops when there is no outstanding wallet
 * offer; propagates and sets `walletOfferStatus=revoked` on a confirmed
 * openconnector success; catches every failure (non-2xx body, thrown
 * exception) and still returns true, leaving `walletOfferStatus` unchanged
 * on failure.
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\WalletRevocationPropagationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WalletRevocationPropagationService::check().
 */
class WalletRevocationPropagationServiceTest extends TestCase
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
     * @return WalletRevocationPropagationService
     */
    private function service(): WalletRevocationPropagationService
    {
        return new WalletRevocationPropagationService(
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );
    }//end service()

    /**
     * No outstanding wallet offer (`walletOfferStatus` null) is a no-op —
     * no HTTP call is made, and the transition is always allowed.
     *
     * @return void
     */
    public function testNoOpWhenWalletOfferStatusIsNull(): void
    {
        $this->clientService->expects($this->never())->method('newClient');

        $context = [
            'object'     => [
                'id'                => 'credential-1',
                'walletOfferStatus' => null,
            ],
            'transition' => 'revoke',
            'from'       => 'issued',
            'to'         => 'revoked',
        ];

        self::assertTrue($this->service()->check($context));
        self::assertNull($context['object']['walletOfferStatus']);
    }//end testNoOpWhenWalletOfferStatusIsNull()

    /**
     * An outstanding offer propagates successfully and sets
     * `walletOfferStatus=revoked`.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-revoking-a-credential-with-an-outstanding-wallet-offer-propagates-the-revocation
     */
    public function testPropagatesAndSetsRevokedOnSuccess(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'revoked', 'alreadyRevoked' => false]));

        $capturedUrl = null;
        $client      = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->willReturnCallback(
                function (string $url) use (&$capturedUrl, $response): IResponse {
                    $capturedUrl = $url;
                    return $response;
                }
            );
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                   => 'credential-2',
                'walletOfferStatus'    => 'offered',
                'walletAttestationRef' => 'offer-uuid-1',
            ],
            'transition' => 'revoke',
            'from'       => 'issued',
            'to'         => 'revoked',
        ];

        self::assertTrue($this->service()->check($context));
        self::assertSame('revoked', $context['object']['walletOfferStatus']);
        self::assertStringContainsString('offer-uuid-1', (string) $capturedUrl);
        self::assertStringContainsString('/revoke', (string) $capturedUrl);
    }//end testPropagatesAndSetsRevokedOnSuccess()

    /**
     * A claimed offer (not just offered) also propagates.
     *
     * @return void
     */
    public function testClaimedOfferAlsoPropagates(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'revoked']));

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                   => 'credential-3',
                'walletOfferStatus'    => 'claimed',
                'walletAttestationRef' => 'offer-uuid-2',
            ],
            'transition' => 'revoke',
            'from'       => 'issued',
            'to'         => 'revoked',
        ];

        self::assertTrue($this->service()->check($context));
        self::assertSame('revoked', $context['object']['walletOfferStatus']);
    }//end testClaimedOfferAlsoPropagates()

    /**
     * A thrown exception is caught and swallowed — the transition always
     * proceeds (fail-soft), and `walletOfferStatus` is left unchanged since
     * propagation did not succeed.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-revoking-a-credential-proceeds-even-when-the-wallet-rail-is-unavailable
     */
    public function testThrowableIsCaughtAndTransitionStillProceeds(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => [
                'id'                   => 'credential-4',
                'walletOfferStatus'    => 'claimed',
                'walletAttestationRef' => 'offer-uuid-3',
            ],
            'transition' => 'revoke',
            'from'       => 'issued',
            'to'         => 'revoked',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('claimed', $context['object']['walletOfferStatus']);
        self::assertNotEmpty($context['object']['walletOfferError']);
    }//end testThrowableIsCaughtAndTransitionStillProceeds()

    /**
     * OpenConnector unavailable (no token configured): the call is skipped,
     * `walletOfferStatus` is left unchanged, and the transition still
     * proceeds.
     *
     * @return void
     */
    public function testMissingTokenStillProceedsFailSoft(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->clientService->method('newClient')->willReturn($this->createMock(IClient::class));

        $context = [
            'object'     => [
                'id'                   => 'credential-5',
                'walletOfferStatus'    => 'offered',
                'walletAttestationRef' => 'offer-uuid-4',
            ],
            'transition' => 'revoke',
            'from'       => 'issued',
            'to'         => 'revoked',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('offered', $context['object']['walletOfferStatus']);
    }//end testMissingTokenStillProceedsFailSoft()
}//end class
