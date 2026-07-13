<?php

/**
 * Unit tests for ReportCardPdfDelegationService.
 *
 * Covers the fail-soft contract: `check()` ALWAYS returns true regardless of
 * outcome (missing token, unreachable docudesk, malformed response, thrown
 * exception, or success), mirroring WalletRevocationPropagationService's
 * fail-soft shape. Records the request-body shape against the proposed
 * docudesk contract.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\ReportCardPdfDelegationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ReportCardPdfDelegationService::check().
 */
class ReportCardPdfDelegationServiceTest extends TestCase
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
     * @return ReportCardPdfDelegationService
     */
    private function service(): ReportCardPdfDelegationService
    {
        return new ReportCardPdfDelegationService(
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );

    }//end service()

    /**
     * A reachable docudesk endpoint returning a 2xx with a documentRef records
     * `rendered`/docudeskDocumentRef and clears any prior error.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
     */
    public function testSuccessfulRenderRecordsDocumentReference(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-1']));

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
            'object' => [
                'id'                  => 'card-1',
                'subjectGrades'       => [['curriculumPlanId' => 'plan-1']],
                'mentorComment'       => 'Goed gedaan.',
                'attendanceSummary'   => ['presentCount' => 10],
                'docudeskRenderError' => 'previous failure',
            ],
            'transition' => 'renderToPdf',
            'from'       => 'finalised',
            'to'         => 'finalised',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('rendered', $context['object']['docudeskRenderStatus']);
        self::assertSame('doc-uuid-1', $context['object']['docudeskDocumentRef']);
        self::assertNull($context['object']['docudeskRenderError']);
        self::assertNotEmpty($context['object']['docudeskRequestedAt']);

        self::assertStringContainsString('/apps/docudesk/api/v1/documents/render', (string) $capturedUrl);
        self::assertSame('Bearer token-abc', $capturedOptions['headers']['Authorization']);
        self::assertSame('card-1', $capturedOptions['json']['reportCardId']);
        self::assertSame([['curriculumPlanId' => 'plan-1']], $capturedOptions['json']['subjectGrades']);
        self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

    }//end testSuccessfulRenderRecordsDocumentReference()

    /**
     * No configured docudesk API token still returns true (fail-soft) but
     * records the failure.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
     */
    public function testMissingTokenIsFailSoft(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->clientService->expects($this->never())->method('newClient');

        $context = [
            'object'     => ['id' => 'card-2', 'subjectGrades' => [], 'mentorComment' => null],
            'transition' => 'renderToPdf',
            'from'       => 'finalised',
            'to'         => 'finalised',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('failed', $context['object']['docudeskRenderStatus']);
        self::assertNotEmpty($context['object']['docudeskRenderError']);

    }//end testMissingTokenIsFailSoft()

    /**
     * docudesk unreachable (HTTP client throws) is fail-soft — still returns
     * true, never blocks the transition.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
     */
    public function testUnreachableDocudeskIsFailSoft(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => ['id' => 'card-3', 'subjectGrades' => [], 'mentorComment' => null, 'lifecycle' => 'finalised'],
            'transition' => 'renderToPdf',
            'from'       => 'finalised',
            'to'         => 'finalised',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('failed', $context['object']['docudeskRenderStatus']);
        self::assertStringContainsString('Connection refused', $context['object']['docudeskRenderError']);
        // Fail-soft never touches lifecycle — the transition context still applies normally.
        self::assertSame('finalised', $context['object']['lifecycle']);

    }//end testUnreachableDocudeskIsFailSoft()

    /**
     * A malformed/no-documentRef response is fail-soft, recorded as `failed`.
     *
     * @return void
     */
    public function testMalformedResponseIsFailSoft(): void
    {
        $this->appConfig->method('getValueString')->willReturn('token-abc');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'accepted']));

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $context = [
            'object'     => ['id' => 'card-4', 'subjectGrades' => [], 'mentorComment' => null],
            'transition' => 'rerenderToPdf',
            'from'       => 'published-to-parents',
            'to'         => 'published-to-parents',
        ];

        $result = $this->service()->check($context);

        self::assertTrue($result);
        self::assertSame('failed', $context['object']['docudeskRenderStatus']);
        self::assertNotEmpty($context['object']['docudeskRenderError']);

    }//end testMalformedResponseIsFailSoft()
}//end class
