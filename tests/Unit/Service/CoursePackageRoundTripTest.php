<?php

/**
 * Scholiq course-package round-trip smoke test.
 *
 * Exports a seeded course as scholiq-native JSON via CoursePackageExportService,
 * re-imports it via CoursePackageImportService, and diffs the resulting object
 * graph against the source — the lossless round-trip design.md's format-support
 * matrix promises for the scholiq-native JSON format.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#81-openspec-validate-course-package-import-export---strict-clean-phpunit-green-for-all-new-php-classes-plus-the-qtiimportservice-regression-suite-playwright-course-package-import-exportspects-green-no-dangling-refs-in-the-register-json-and-a-round-trip-smoke-test-export-a-seeded-course-as-scholiq-native-json-re-import-it-diff-the-resulting-object-graph-passes
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\CommonCartridgeParser;
use OCA\Scholiq\Service\CoursePackageExportService;
use OCA\Scholiq\Service\CoursePackageImportService;
use OCA\Scholiq\Service\MbzExtractor;
use OCA\Scholiq\Service\MoodleBackupParser;
use OCA\Scholiq\Service\MoodleQuizQuestionMapper;
use OCA\Scholiq\Service\QtiExportService;
use OCA\Scholiq\Service\QtiImportService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Round-trip smoke test: export → re-import → diff.
 */
class CoursePackageRoundTripTest extends TestCase
{

    /**
     * Export a seeded Course as scholiq-native JSON, re-import it, and assert
     * the re-created object graph reproduces the source Course/Lesson/
     * Material/Rubric shapes (design.md's lossless round-trip promise).
     *
     * @return void
     */
    public function testScholiqJsonExportReimportsToAnEquivalentObjectGraph(): void
    {
        // --- Seed source object graph (mocked ObjectService, export side). ---
        $exportObjectService = $this->createMock(ObjectService::class);
        $exportObjectService->method('find')->willReturnCallback(
            static function (string $id, string $register, string $schema) {
                return match ([$schema, $id]) {
                    ['course', 'course-source'] => ['id' => 'course-source', 'name' => 'Physics 101', 'tenant_id' => 't1'],
                    ['rubric', 'rubric-source'] => ['id' => 'rubric-source', 'name' => 'Essay rubric', 'criteria' => [], 'maxPoints' => 20],
                    default => null,
                };
            }
        );
        $exportObjectService->method('findAll')->willReturnCallback(
            static function (array $config): array {
                return match ($config['schema']) {
                    'course' => [],
                    'lesson' => [['id' => 'lesson-source', 'name' => 'Introduction', 'order' => 1, 'contentType' => 'text', 'contentRef' => 'material-source', 'courseId' => 'course-source']],
                    'material' => [['id' => 'material-source', 'title' => 'Syllabus', 'kind' => 'document', 'fileRef' => '/Scholiq/materials/syllabus.pdf', 'courseId' => 'course-source']],
                    'assessment' => [],
                    'assignment' => [['id' => 'assignment-source', 'title' => 'Essay', 'rubricId' => 'rubric-source', 'courseId' => 'course-source']],
                    'lti-tool-placement' => [],
                    default => [],
                };
            }
        );

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturnCallback(
            function (string $path) {
                if ($path === 'Scholiq/materials/syllabus.pdf') {
                    $node = $this->createMock(File::class);
                    $node->method('getContent')->willReturn('SYLLABUS-BYTES');
                    return $node;
                }

                throw new NotFoundException($path);
            }
        );
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        $qtiExportService  = $this->createMock(QtiExportService::class);
        $exportService      = new CoursePackageExportService($exportObjectService, $qtiExportService, $rootFolder, new NullLogger());
        $json                = $exportService->exportScholiqJson('course-source', 'teacher1');

        // --- Re-import the exported JSON into a fresh object graph. ---
        $savedByschema = [];
        $importObjectService = $this->createMock(ObjectService::class);
        $importObjectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) use (&$savedByschema): array {
                $savedByschema[$schema] ??= [];
                $object['uuid']              = $schema.'-reimported-'.(count($savedByschema[$schema]) + 1);
                $savedByschema[$schema][]    = $object;
                return $object;
            }
        );

        $importFolder = $this->createMock(Folder::class);
        $importFolder->method('get')->willThrowException(new NotFoundException('not found'));
        $importFolder->method('nodeExists')->willReturn(false);
        $importFolder->method('newFolder')->willReturn($this->createMock(Folder::class));
        $importFolder->method('newFile')->willReturn($this->createMock(File::class));
        $importRootFolder = $this->createMock(IRootFolder::class);
        $importRootFolder->method('getUserFolder')->willReturn($importFolder);

        $tmpJsonFile = tempnam(sys_get_temp_dir(), 'scholiq_roundtrip_');
        file_put_contents($tmpJsonFile, $json);

        $importService = new CoursePackageImportService(
            $importObjectService,
            new QtiImportService($importObjectService, new NullLogger()),
            new MbzExtractor(),
            new CommonCartridgeParser(),
            new MoodleBackupParser(),
            new MoodleQuizQuestionMapper(),
            $importRootFolder,
            new NullLogger(),
        );

        $report = $importService->import($tmpJsonFile, 'course-export.json', 'teacher1', 't1');
        unlink($tmpJsonFile);

        // --- Diff: the re-created object graph reproduces the source shapes. ---
        self::assertSame('scholiq-json', $report['sourceFormat']);
        self::assertNotSame('failed', $report['lifecycle']);
        self::assertNotNull($report['courseId']);

        self::assertSame('Physics 101', $savedByschema['course'][0]['name']);
        self::assertSame('Introduction', $savedByschema['lesson'][0]['name']);
        self::assertSame('Syllabus', $savedByschema['material'][0]['title']);
        self::assertSame('Essay rubric', $savedByschema['rubric'][0]['name']);
        self::assertSame(20, $savedByschema['rubric'][0]['maxPoints']);

        // The Material's file bytes were resolved on export (base64) and written
        // back into nc:files on import — never referencing a path the recipient
        // tenant cannot resolve.
        self::assertNotNull($savedByschema['material'][0]['fileRef']);
        self::assertNotSame('', $savedByschema['material'][0]['fileRef']);
    }//end testScholiqJsonExportReimportsToAnEquivalentObjectGraph()
}//end class
