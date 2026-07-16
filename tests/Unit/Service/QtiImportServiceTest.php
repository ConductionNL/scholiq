<?php

/**
 * Scholiq QtiImportService unit tests.
 *
 * Regression coverage for the `import()`/`importFromDirectory()` split
 * (course-package-import-export task 2.1): `import()` must keep behaving
 * exactly as before the refactor, and the newly-extracted
 * `importFromDirectory()` must be independently callable against an
 * already-extracted directory — the exact shape `CoursePackageImportService`
 * relies on.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#21-refactor-qtiimportserviceimport-extract-the-existing-collectitempathsimportsingleitem-loop-into-a-new-public-function-importfromdirectorystring-dir-string-itembankid-string-tenantid---array-import-becomes-extractzip-then-importfromdirectory-no-behavior-change-for-the-existing-qtiimportcontroller-caller
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\QtiImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Tests for QtiImportService.
 */
class QtiImportServiceTest extends TestCase
{

    private const FIXTURE = __DIR__.'/../../fixtures/course-packages/minimal-cc.imscc';

    /**
     * Track saveObject payloads so assertions can inspect what was persisted.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $savedObjects = [];

    /**
     * Build a QtiImportService whose ObjectService::saveObject records the
     * payload and returns an incrementing uuid.
     *
     * @return QtiImportService
     */
    private function service(): QtiImportService
    {
        $this->savedObjects = [];
        $objectService       = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object): array {
                $object['uuid']       = 'item-'.(count($this->savedObjects) + 1);
                $this->savedObjects[] = $object;
                return $object;
            }
        );

        return new QtiImportService($objectService, new NullLogger());
    }//end service()

    /**
     * `importFromDirectory()` parses the single QTI item in the fixture package
     * and creates one Item with the parsed choice interaction / correctResponse.
     *
     * @return void
     */
    public function testImportFromDirectoryCreatesItemsFromAnAlreadyExtractedDirectory(): void
    {
        $tmpDir = sys_get_temp_dir().'/scholiq_test_qti_'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        $zip = new ZipArchive();
        $zip->open(self::FIXTURE);
        $zip->extractTo($tmpDir);
        $zip->close();

        $svc   = $this->service();
        $uuids = $svc->importFromDirectory($tmpDir, 'bank-1', 'tenant-1');

        self::assertCount(1, $uuids);
        self::assertCount(1, $this->savedObjects);
        self::assertSame('choice', $this->savedObjects[0]['interactionType']);
        self::assertSame('ChoiceA', $this->savedObjects[0]['correctResponse']);
        self::assertSame('bank-1', $this->savedObjects[0]['itemBankId']);
        self::assertSame('tenant-1', $this->savedObjects[0]['tenant_id']);

        $this->rrmdir($tmpDir);
    }//end testImportFromDirectoryCreatesItemsFromAnAlreadyExtractedDirectory()

    /**
     * Regression: `import()` (the pre-existing public entry point) must still
     * extract the ZIP itself and produce the same result as before the
     * `importFromDirectory()` extraction.
     *
     * @return void
     */
    public function testImportStillExtractsAndImportsAsBeforeTheRefactor(): void
    {
        $svc   = $this->service();
        $uuids = $svc->import(self::FIXTURE, 'bank-1', 'tenant-1');

        self::assertCount(1, $uuids);
        self::assertSame('choice', $this->savedObjects[0]['interactionType']);
    }//end testImportStillExtractsAndImportsAsBeforeTheRefactor()

    /**
     * `extractZip()` is now public so `CoursePackageImportService` can reuse it
     * directly for the CC-package-wide extraction (design.md "zero duplicated
     * security logic").
     *
     * @return void
     */
    public function testExtractZipIsPubliclyCallable(): void
    {
        $svc    = $this->service();
        $tmpDir = sys_get_temp_dir().'/scholiq_test_qti_extract_'.bin2hex(random_bytes(6));

        $svc->extractZip(self::FIXTURE, $tmpDir);

        self::assertFileExists($tmpDir.'/imsmanifest.xml');
        self::assertFileExists($tmpDir.'/item1.xml');

        $this->rrmdir($tmpDir);
    }//end testExtractZipIsPubliclyCallable()

    /**
     * Recursively remove a directory (test cleanup helper).
     *
     * @param string $dir Absolute path.
     *
     * @return void
     */
    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) === true) {
                $this->rrmdir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }//end rrmdir()
}//end class
