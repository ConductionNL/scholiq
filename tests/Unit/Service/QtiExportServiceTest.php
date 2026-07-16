<?php

/**
 * Scholiq QtiExportService unit tests.
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
 * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#scenario-exporting-an-itembank-produces-a-valid-qti-30-package
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\QtiExportService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Tests for QtiExportService.
 */
class QtiExportServiceTest extends TestCase
{

    /**
     * The exported package byte-matches the stored `qtiBody` for every item,
     * including one whose `interactionType` was imported with the pre-existing
     * degraded parsing (raw `qtiBody` preserved, `correctResponse` unresolved)
     * — export fidelity is unaffected by that import-side limitation.
     *
     * @return void
     */
    public function testExportProducesAValidPackageWithVerbatimQtiBodies(): void
    {
        $fullyParsedBody = '<?xml version="1.0"?><assessmentItem identifier="i1"><itemBody>Q1</itemBody></assessmentItem>';
        $degradedBody    = '<?xml version="1.0"?><assessmentItem identifier="i2"><itemBody>Q2 (hotspot, raw only)</itemBody></assessmentItem>';

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($fullyParsedBody, $degradedBody) {
                if ($schema === 'item-bank') {
                    return ['id' => 'bank-1', 'name' => 'Physics 101', 'itemIds' => ['item-1', 'item-2']];
                }

                return match ($id) {
                    'item-1' => ['id' => 'item-1', 'qtiBody' => $fullyParsedBody, 'interactionType' => 'choice'],
                    'item-2' => ['id' => 'item-2', 'qtiBody' => $degradedBody, 'interactionType' => 'hotspot'],
                    default => null,
                };
            }
        );

        $zipBytes = (new QtiExportService($objectService))->export('bank-1');

        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_qti_export_test_');
        file_put_contents($tmpFile, $zipBytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmpFile) === true);

        $manifest = $zip->getFromName('imsmanifest.xml');
        self::assertIsString($manifest);
        self::assertStringContainsString('Physics 101', $manifest);

        self::assertSame($fullyParsedBody, $zip->getFromName('item-1.xml'));
        self::assertSame($degradedBody, $zip->getFromName('item-2.xml'), 'The degraded-parsing item still exports its raw qtiBody verbatim.');

        $zip->close();
        unlink($tmpFile);
    }//end testExportProducesAValidPackageWithVerbatimQtiBodies()

    /**
     * Exporting an unknown ItemBank throws so the controller can return a clean 404/422.
     *
     * @return void
     */
    public function testExportThrowsForUnknownItemBank(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        (new QtiExportService($objectService))->export('missing-bank');
    }//end testExportThrowsForUnknownItemBank()
}//end class
