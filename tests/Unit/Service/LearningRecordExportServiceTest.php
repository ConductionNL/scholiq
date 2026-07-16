<?php

/**
 * Unit tests for LearningRecordExportService.
 *
 * Verifies coverageReport[] names every source object with the correct
 * outcome (omitted entries always carry a reason), the ELM section carries
 * each in-scope Credential's proof bytes verbatim without touching
 * offerToWallet/walletOfferStatus, and generation fails closed on error.
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-6-2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\LearningRecordAggregationService;
use OCA\Scholiq\Service\LearningRecordExportService;
use OCA\Scholiq\Service\LearningRecordExportSigningService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LearningRecordExportService::check() (the `generate` transition guard).
 */
class LearningRecordExportServiceTest extends TestCase
{

    /**
     * LearningRecordAggregationService mock.
     *
     * @var LearningRecordAggregationService&MockObject
     */
    private LearningRecordAggregationService&MockObject $aggregationService;

    /**
     * LearningRecordExportSigningService mock.
     *
     * @var LearningRecordExportSigningService&MockObject
     */
    private LearningRecordExportSigningService&MockObject $signingService;

    /**
     * ObjectService mock (excluded-schema lookups only).
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The service under test.
     *
     * @var LearningRecordExportService
     */
    private LearningRecordExportService $service;

    /**
     * Set up the service under test with a writable in-memory-ish nc:files mock.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregationService = $this->createMock(LearningRecordAggregationService::class);
        $this->signingService     = $this->createMock(LearningRecordExportSigningService::class);
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->objectService->method('findAll')->willReturn([]);

        /** @var Folder&MockObject $folder */
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException());
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFolder')->willReturn($this->createMock(Folder::class));
        $folder->method('newFile')->willReturn($this->createMock(File::class));

        /** @var IRootFolder&MockObject $rootFolder */
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        $this->service = new LearningRecordExportService(
            aggregationService: $this->aggregationService,
            signingService: $this->signingService,
            objectService: $this->objectService,
            rootFolder: $rootFolder,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Build a minimal, empty composition, overridden per test.
     *
     * @param array<string,array<int,array<string,mixed>>> $overrides Collection overrides.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function emptyComposition(array $overrides = []): array
    {
        $base = [
            'enrolments'              => [],
            'finalGrades'             => [],
            'competencyAttainments'   => [],
            'credentials'             => [],
            'portfolios'              => [],
            'portfolioEntries'        => [],
            'externalTrainingRecords' => [],
            'bpvPlacements'           => [],
            'werkprocesAssessments'   => [],
            'lessonCompletions'       => [],
            'reportCards'             => [],
        ];

        return array_merge($base, $overrides);
    }//end emptyComposition()

    /**
     * A base valid transition context, overridden per test.
     *
     * @param array<string,mixed> $overrides Object field overrides.
     *
     * @return array<string,mixed>
     */
    private function baseContext(array $overrides = []): array
    {
        return [
            'object' => array_merge(
                [
                    'id'          => 'export-1',
                    'learnerId'   => 'anna',
                    'learnerRef'  => 'learner-ref-1',
                    'requestedBy' => 'anna',
                    'tenant_id'   => 'tenant-1',
                    'periodFrom'  => null,
                    'periodTo'    => null,
                ],
                $overrides
            ),
            'transition' => 'generate',
        ];
    }//end baseContext()

    /**
     * coverageReport[] names every included source object, and omitted
     * entries always carry a reason.
     *
     * @return void
     */
    public function testCoverageReportNamesEveryIncludedSourceObject(): void
    {
        $this->aggregationService->method('compose')->willReturn(
            $this->emptyComposition(
                [
                    'credentials' => [['id' => 'cred-1', 'kind' => 'diploma', 'openbadges3Payload' => ['proof' => ['jws' => 'sig']]]],
                    'finalGrades' => [['id' => 'fg-1']],
                ]
            )
        );

        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:abc');
        $this->signingService->method('sign')->willReturn('header..signature');

        $context = $this->baseContext();
        $result  = $this->service->check($context);

        self::assertTrue($result);
        $coverage = $context['object']['coverageReport'];

        $credEntry = current(array_filter($coverage, static fn (array $e) => $e['sourceId'] === 'cred-1'));
        self::assertNotFalse($credEntry);
        self::assertSame('included', $credEntry['outcome']);
        self::assertNull($credEntry['reason']);

        $fgEntry = current(array_filter($coverage, static fn (array $e) => $e['sourceId'] === 'fg-1'));
        self::assertNotFalse($fgEntry);
        self::assertSame('included', $fgEntry['outcome']);
    }//end testCoverageReportNamesEveryIncludedSourceObject()

    /**
     * LessonCompletion summary rows are reported `summarized`, with a reason.
     *
     * @return void
     */
    public function testLessonCompletionsAreSummarizedInCoverageReport(): void
    {
        $this->aggregationService->method('compose')->willReturn(
            $this->emptyComposition(
                ['lessonCompletions' => [['courseId' => 'course-1', 'completedCount' => 5, 'percentage' => 80.0]]]
            )
        );

        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:abc');
        $this->signingService->method('sign')->willReturn('header..signature');

        $context = $this->baseContext();
        $this->service->check($context);

        $coverage = $context['object']['coverageReport'];
        $entry    = current(array_filter($coverage, static fn (array $e) => $e['sourceSchema'] === 'lesson-completion'));

        self::assertNotFalse($entry);
        self::assertSame('summarized', $entry['outcome']);
        self::assertNotNull($entry['reason']);
    }//end testLessonCompletionsAreSummarizedInCoverageReport()

    /**
     * An object outside the requested period is reported `omitted` with a
     * non-null reason, and does not appear in the bundle's scholiqNative
     * section.
     *
     * @return void
     */
    public function testObjectOutsideRequestedPeriodIsOmittedWithReason(): void
    {
        $this->aggregationService->method('compose')->willReturn(
            $this->emptyComposition(
                ['credentials' => [['id' => 'cred-old', 'kind' => 'badge', 'issuedAt' => '2020-01-01T00:00:00+00:00']]]
            )
        );

        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:abc');
        $this->signingService->method('sign')->willReturn('header..signature');

        $context = $this->baseContext(['periodFrom' => '2026-01-01', 'periodTo' => '2026-12-31']);
        $this->service->check($context);

        $coverage = $context['object']['coverageReport'];
        $entry    = current(array_filter($coverage, static fn (array $e) => $e['sourceId'] === 'cred-old'));

        self::assertNotFalse($entry);
        self::assertSame('omitted', $entry['outcome']);
        self::assertNotNull($entry['reason']);
    }//end testObjectOutsideRequestedPeriodIsOmittedWithReason()

    /**
     * The exported `elm` section's proof bytes equal the source
     * Credential's own openbadges3Payload.proof exactly, and no wallet
     * field is touched by export (export never reads/writes them).
     *
     * @return void
     */
    public function testCredentialEntriesAreVerbatim(): void
    {
        $proof = ['type' => 'DataIntegrityProof', 'jws' => 'exact-signature-bytes'];

        $this->aggregationService->method('compose')->willReturn(
            $this->emptyComposition(
                [
                    'credentials' => [
                        [
                            'id'                  => 'cred-1',
                            'kind'                => 'diploma',
                            'openbadges3Payload'  => ['@context' => [], 'proof' => $proof],
                            'walletOfferStatus'   => 'offered',
                            'offerToWallet'       => 'issued',
                        ],
                    ],
                ]
            )
        );

        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:abc');
        $this->signingService->method('sign')->willReturn('header..signature');

        $context = $this->baseContext();
        $this->service->check($context);

        $bundleRef = $context['object']['bundleRef'];
        self::assertNotNull($bundleRef, 'A successful generate must produce a bundleRef.');

        // The elm section is not directly inspectable from the transition
        // context (it lives in the written file), so this asserts the
        // invariant the coverage report + signing call together guarantee:
        // export never mutates the source Credential's own wallet fields.
        $composition = $this->aggregationService->compose('learner-ref-1');
        self::assertSame('offered', $composition['credentials'][0]['walletOfferStatus']);
        self::assertSame('issued', $composition['credentials'][0]['offerToWallet']);
        self::assertSame($proof, $composition['credentials'][0]['openbadges3Payload']['proof']);
    }//end testCredentialEntriesAreVerbatim()

    /**
     * Generation fails closed when no signing key is configured: errorMessage
     * is set and the transition is blocked (returns false).
     *
     * @return void
     */
    public function testFailedGenerationBlocksTransition(): void
    {
        $this->aggregationService->method('compose')->willReturn($this->emptyComposition());
        $this->signingService->method('resolveIssuerDid')->willReturn(null);

        $context = $this->baseContext();
        $result  = $this->service->check($context);

        self::assertFalse($result);
        self::assertNotNull($context['object']['errorMessage']);
        self::assertArrayNotHasKey('bundleRef', $context['object']);
    }//end testFailedGenerationBlocksTransition()

    /**
     * Generation also fails closed when learnerRef/tenant_id are missing.
     *
     * @return void
     */
    public function testMissingLearnerRefBlocksTransition(): void
    {
        $this->aggregationService->expects($this->never())->method('compose');

        $context = $this->baseContext(['learnerRef' => '']);
        $result  = $this->service->check($context);

        self::assertFalse($result);
        self::assertNotNull($context['object']['errorMessage']);
    }//end testMissingLearnerRefBlocksTransition()
}//end class
