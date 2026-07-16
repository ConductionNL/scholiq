<?php

/**
 * Unit tests for LearningRecordImportService.
 *
 * Verifies a recognised own-format bundle parses with verified/unverifiable
 * per key presence, a recognised bare ELM set parses with `sourceSchema:
 * null` entries, and an unparseable file sets `errorMessage` and blocks the
 * transition. `LearningRecordImportService` takes no `ObjectService`
 * dependency at all — it is structurally incapable of writing to any other
 * schema (a stronger guarantee than a mock-and-assert-zero-calls test).
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\LearningRecordExportSigningService;
use OCA\Scholiq\Service\LearningRecordImportService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LearningRecordImportService::check() (the `parse` transition guard).
 */
class LearningRecordImportServiceTest extends TestCase
{

    /**
     * LearningRecordExportSigningService mock.
     *
     * @var LearningRecordExportSigningService&MockObject
     */
    private LearningRecordExportSigningService&MockObject $signingService;

    /**
     * Build a service under test whose IRootFolder mock serves the given raw bytes.
     *
     * @param string $rawContent Raw bytes `readSourceBytes()` should return.
     *
     * @return LearningRecordImportService
     */
    private function makeService(string $rawContent): LearningRecordImportService
    {
        $this->signingService = $this->createMock(LearningRecordExportSigningService::class);

        /** @var File&MockObject $file */
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn($rawContent);

        /** @var Folder&MockObject $folder */
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturn($file);

        /** @var IRootFolder&MockObject $rootFolder */
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        return new LearningRecordImportService(
            signingService: $this->signingService,
            rootFolder: $rootFolder,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end makeService()

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
                    'sourceRef'    => '/Scholiq/tenant-1/learning-record-imports/abc.json',
                    'uploadedBy'   => 'coordinator-1',
                    'sourceFormat' => 'scholiq-learning-record',
                    'tenant_id'    => 'tenant-1',
                ],
                $overrides
            ),
            'transition' => 'parse',
        ];
    }//end baseContext()

    /**
     * A recognised own-format bundle whose issuerDid matches the importing
     * tenant's own key AND whose signature verifies parses `verified`.
     *
     * @return void
     */
    public function testRecognisedOwnFormatBundleParsesVerifiedWhenKeyMatches(): void
    {
        $bundle = [
            'bundleType'  => 'scholiq-learning-record',
            'issuerDid'   => 'did:web:scholiq:tenant-1:fingerprint',
            'elm'         => [['kind' => 'diploma']],
            'scholiqNative' => [
                'credentials' => [['id' => 'cred-1', 'kind' => 'diploma']],
                'finalGrades' => [['id' => 'fg-1']],
            ],
            'proof' => ['jws' => 'header..signature'],
        ];

        $service = $this->makeService(rawContent: (string) json_encode($bundle));
        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:fingerprint');
        $this->signingService->method('verify')->willReturn(true);

        $context = $this->baseContext();
        $result  = $service->check($context);

        self::assertTrue($result);
        self::assertSame('verified', $context['object']['verificationStatus']);
        self::assertNull($context['object']['errorMessage']);

        $entries = $context['object']['entries'];
        self::assertNotEmpty($entries);

        $credEntry = current(array_filter($entries, static fn (array $e) => $e['sourceSchema'] === 'credential'));
        self::assertNotFalse($credEntry);
        self::assertSame('recognized', $credEntry['outcome']);
    }//end testRecognisedOwnFormatBundleParsesVerifiedWhenKeyMatches()

    /**
     * A recognised own-format bundle from a DIFFERENT (or unresolvable)
     * issuer parses `unverifiable` — the expected, non-error case for a
     * genuinely foreign system.
     *
     * @return void
     */
    public function testRecognisedOwnFormatBundleParsesUnverifiableForForeignIssuer(): void
    {
        $bundle = [
            'bundleType'    => 'scholiq-learning-record',
            'issuerDid'     => 'did:web:scholiq:some-other-tenant:xyz',
            'elm'           => [],
            'scholiqNative' => ['credentials' => []],
            'proof'         => ['jws' => 'header..signature'],
        ];

        $service = $this->makeService(rawContent: (string) json_encode($bundle));
        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:fingerprint');
        $this->signingService->expects($this->never())->method('verify');

        $context = $this->baseContext();
        $service->check($context);

        self::assertSame('unverifiable', $context['object']['verificationStatus']);
    }//end testRecognisedOwnFormatBundleParsesUnverifiableForForeignIssuer()

    /**
     * A bundle claiming to be from THIS tenant but whose signature fails
     * verification parses `invalid` (tamper flag).
     *
     * @return void
     */
    public function testTamperedOwnFormatBundleParsesInvalid(): void
    {
        $bundle = [
            'bundleType'    => 'scholiq-learning-record',
            'issuerDid'     => 'did:web:scholiq:tenant-1:fingerprint',
            'elm'           => [],
            'scholiqNative' => ['credentials' => []],
            'proof'         => ['jws' => 'header..tampered-signature'],
        ];

        $service = $this->makeService(rawContent: (string) json_encode($bundle));
        $this->signingService->method('resolveIssuerDid')->willReturn('did:web:scholiq:tenant-1:fingerprint');
        $this->signingService->method('verify')->willReturn(false);

        $context = $this->baseContext();
        $service->check($context);

        self::assertSame('invalid', $context['object']['verificationStatus']);
    }//end testTamperedOwnFormatBundleParsesInvalid()

    /**
     * A recognised bare ELM/Europass credential set parses with
     * `sourceSchema: null` entries and `unverifiable` (no generic
     * third-party ELM verifier is built).
     *
     * @return void
     */
    public function testRecognisedBareElmSetParsesWithNullSourceSchema(): void
    {
        $elmSet = [
            'credentials' => [
                ['credentialSubject' => ['achievement' => ['name' => 'Foreign Diploma']]],
                ['name' => 'Another Credential'],
            ],
        ];

        $service = $this->makeService(rawContent: (string) json_encode($elmSet));

        $context = $this->baseContext(['sourceFormat' => 'elm-europass']);
        $result  = $service->check($context);

        self::assertTrue($result);
        self::assertSame('unverifiable', $context['object']['verificationStatus']);

        $entries = $context['object']['entries'];
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertNull($entry['sourceSchema']);
            self::assertSame('recognized', $entry['outcome']);
        }

        self::assertSame('Foreign Diploma', $entries[0]['sourceTitle']);
    }//end testRecognisedBareElmSetParsesWithNullSourceSchema()

    /**
     * Unparseable JSON sets errorMessage, blocks the transition, and
     * produces no entries.
     *
     * @return void
     */
    public function testUnparseableFileSetsErrorMessageAndBlocks(): void
    {
        $service = $this->makeService(rawContent: '{not valid json');

        $context = $this->baseContext();
        $result  = $service->check($context);

        self::assertFalse($result);
        self::assertNotNull($context['object']['errorMessage']);
        self::assertSame([], $context['object']['entries']);
    }//end testUnparseableFileSetsErrorMessageAndBlocks()

    /**
     * An unrecognised sourceFormat sets errorMessage and blocks.
     *
     * @return void
     */
    public function testUnrecognisedSourceFormatBlocks(): void
    {
        $service = $this->makeService(rawContent: (string) json_encode(['a' => 1]));

        $context = $this->baseContext(['sourceFormat' => 'some-other-format']);
        $result  = $service->check($context);

        self::assertFalse($result);
        self::assertNotNull($context['object']['errorMessage']);
    }//end testUnrecognisedSourceFormatBlocks()

}//end class
