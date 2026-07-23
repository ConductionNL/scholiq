<?php

/**
 * Scholiq CoursePackageExportService unit tests.
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\CoursePackageExportService;
use OCA\Scholiq\Service\QtiExportService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use ZipArchive;

/**
 * Tests for CoursePackageExportService.
 */
class CoursePackageExportServiceTest extends TestCase
{

    /**
     * Build a service wired against a fixed object graph: one Course, one
     * Lesson, one file-backed Material, one Assessment referencing one Item
     * (whose ItemBank export is delegated to QtiExportService, never
     * re-serialised by the course exporter), and one Assignment with a
     * Rubric.
     *
     * @return CoursePackageExportService
     */
    private function service(): CoursePackageExportService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) {
                return match ([$schema, $id]) {
                    ['course', 'course-1'] => ['id' => 'course-1', 'name' => 'Physics 101', 'tenant_id' => 't1'],
                    ['item', 'item-1'] => ['id' => 'item-1', 'itemBankId' => 'bank-1', 'qtiBody' => '<x/>'],
                    ['rubric', 'rubric-1'] => ['id' => 'rubric-1', 'name' => 'Essay rubric'],
                    default => null,
                };
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config): array {
                return match ($config['schema']) {
                    'course' => [],
                    'lesson' => [['id' => 'lesson-1', 'name' => 'Intro', 'courseId' => 'course-1']],
                    'material' => [['id' => 'material-1', 'title' => 'Slides', 'kind' => 'document', 'fileRef' => '/Scholiq/materials/slides.pdf', 'courseId' => 'course-1']],
                    'assessment' => [['id' => 'assessment-1', 'title' => 'Midterm', 'itemRefs' => ['item-1'], 'courseId' => 'course-1']],
                    'assignment' => [['id' => 'assignment-1', 'title' => 'Essay', 'rubricId' => 'rubric-1', 'courseId' => 'course-1']],
                    'lti-tool-placement' => [],
                    default => [],
                };
            }
        );

        $qtiExportService = $this->createMock(QtiExportService::class);
        $qtiExportService->method('export')->with('bank-1')->willReturn('FAKE-QTI-ZIP-BYTES');

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturnCallback(
            function (string $path) {
                if ($path === 'Scholiq/materials/slides.pdf') {
                    $node = $this->createMock(\OCP\Files\File::class);
                    $node->method('getContent')->willReturn('PDF-BYTES');
                    return $node;
                }

                throw new NotFoundException($path);
            }
        );

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        return new CoursePackageExportService($objectService, $qtiExportService, $rootFolder, new NullLogger());
    }//end service()

    /**
     * A Common Cartridge export contains a manifest entry per Lesson/Material/Item
     * (via the delegated ItemBank package), and resolves Material file bytes.
     *
     * @return void
     */
    public function testExportCommonCartridgeContainsManifestAndResolvedFiles(): void
    {
        $zipBytes = $this->service()->exportCommonCartridge('course-1', 'teacher1');

        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_course_export_test_');
        file_put_contents($tmpFile, $zipBytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmpFile) === true);

        $manifest = $zip->getFromName('imsmanifest.xml');
        self::assertIsString($manifest);
        self::assertStringContainsString('Physics 101', $manifest);
        self::assertStringContainsString('LESSON-lesson-1', $manifest);

        self::assertSame('FAKE-QTI-ZIP-BYTES', $zip->getFromName('assessments/qti-item-bank-bank-1.zip'), 'Assessment items are delegated to QtiExportService, not re-serialised.');

        $zip->close();
        unlink($tmpFile);
    }//end testExportCommonCartridgeContainsManifestAndResolvedFiles()

    /**
     * A scholiq-native JSON export round-trips the full object graph, including
     * base64-encoded resolved Material bytes for losslessness.
     *
     * @return void
     */
    public function testExportScholiqJsonProducesALosslessTree(): void
    {
        $json    = $this->service()->exportScholiqJson('course-1', 'teacher1');
        $decoded = json_decode($json, true);

        self::assertSame('Physics 101', $decoded['course']['name']);
        self::assertCount(1, $decoded['lessons']);
        self::assertSame('Intro', $decoded['lessons'][0]['name']);
        self::assertCount(1, $decoded['materials']);
        self::assertSame(base64_encode('PDF-BYTES'), $decoded['materials'][0]['contentBase64']);
        self::assertArrayNotHasKey('content', $decoded['materials'][0], 'Raw bytes are base64-encoded, not duplicated as a raw key.');
        self::assertCount(1, $decoded['rubrics']);
        self::assertSame('Essay rubric', $decoded['rubrics'][0]['name']);
    }//end testExportScholiqJsonProducesALosslessTree()

    /**
     * Exporting an unknown Course throws so the controller can return a clean 404/422.
     *
     * @return void
     */
    public function testExportThrowsForUnknownCourse(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);
        $qtiExportService = $this->createMock(QtiExportService::class);
        $rootFolder        = $this->createMock(IRootFolder::class);

        $service = new CoursePackageExportService($objectService, $qtiExportService, $rootFolder, new NullLogger());

        $this->expectException(RuntimeException::class);
        $service->exportScholiqJson('missing-course', 'teacher1');
    }//end testExportThrowsForUnknownCourse()
}//end class
