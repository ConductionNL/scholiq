<?php

/**
 * Scholiq CoursePackageImportService unit tests.
 *
 * Integration-style: exercises the real `QtiImportService`, `MbzExtractor`,
 * `CommonCartridgeParser`, `MoodleBackupParser`, and `MoodleQuizQuestionMapper`
 * against the fixture archives, with only `ObjectService` (persistence) and
 * `IRootFolder` (NC file writes) mocked — the same shape task 3.5's
 * acceptance criteria describes.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#35-add-ocascholiqservicecoursepackageimportservice-spdx-the-orchestrator-detects-cc-vs-moodle-from-the-archive-delegates-to-mbzextractor-or-the-existing-extractzip-equivalent-runs-the-matching-parser-and-for-each-resource-descriptor-creates-courselesson-organization-nodes-material-web-contentweblinkresourcepageurl-delegates-to-qtiimportserviceimportfromdirectory-qticc-assessment-items-or-moodlequizquestionmapper-moodle-quiz-items-creates-ltitoolplacement-basiclti-resources-creates-assignment-moodle-assign-modules-existing-schema-per-assignments-capability-unmodified-or-records-a-dropped-entry-forumwikiglossarycmi5-scorm-embeddedunrecognised-assembles-and-persists-the-coursepackageimportreport-throughout-resolving-its-final-lifecycle-per-the-report-requirements-rule
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\CommonCartridgeParser;
use OCA\Scholiq\Service\CoursePackageImportService;
use OCA\Scholiq\Service\MbzExtractor;
use OCA\Scholiq\Service\MoodleBackupParser;
use OCA\Scholiq\Service\MoodleQuizQuestionMapper;
use OCA\Scholiq\Service\QtiImportService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CoursePackageImportService.
 */
class CoursePackageImportServiceTest extends TestCase
{

    private const CC_FIXTURE          = __DIR__.'/../../fixtures/course-packages/minimal-cc.imscc';
    private const MOODLE_FIXTURE      = __DIR__.'/../../fixtures/course-packages/minimal-moodle.mbz';
    private const CORRUPT_FIXTURE     = __DIR__.'/../../fixtures/course-packages/corrupt.bin';
    private const NO_MANIFEST_FIXTURE = __DIR__.'/../../fixtures/course-packages/no-manifest.imscc';

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $savedByschema = [];

    /**
     * Build a CoursePackageImportService with real parsers/extractors and
     * mocked persistence (ObjectService) / file-writing (IRootFolder).
     *
     * @return CoursePackageImportService
     */
    private function service(): CoursePackageImportService
    {
        $this->savedByschema = [];

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object): array {
                $this->savedByschema[$schema] ??= [];
                $object['uuid']                  = $schema.'-'.(count($this->savedByschema[$schema]) + 1);
                $this->savedByschema[$schema][] = $object;
                return $object;
            }
        );

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException('not found'));
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFolder')->willReturn($this->createMock(Folder::class));
        $folder->method('newFile')->willReturn($this->createMock(File::class));

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($folder);

        $qtiImportService = new QtiImportService($objectService, new NullLogger());

        return new CoursePackageImportService(
            $objectService,
            $qtiImportService,
            new MbzExtractor(),
            new CommonCartridgeParser(),
            new MoodleBackupParser(),
            new MoodleQuizQuestionMapper(),
            $rootFolder,
            new NullLogger(),
        );
    }//end service()

    /**
     * A Common Cartridge fixture import creates the expected object counts and
     * report entries: the CC organization tree materialises Course/Lesson-ish
     * structure, the embedded QTI item imports via the shared
     * `QtiImportService::importFromDirectory()` path, the basiclti resource
     * degrades to an LtiToolPlacement, and the discussion resource drops —
     * every one of the 6 fixture resources produces exactly one entry.
     *
     * @return void
     */
    public function testImportCommonCartridgeFixtureProducesExpectedReport(): void
    {
        $report = $this->service()->import(self::CC_FIXTURE, 'minimal-cc.imscc', 'admin', 'tenant-1');

        self::assertSame('common-cartridge-1.3', $report['sourceFormat']);
        self::assertSame(6, $report['resourcesTotal']);
        self::assertSame(4, $report['resourcesImported'], 'I1 (webcontent), I2 (folder), I3 (qti item), I5 (weblink) all import cleanly.');
        self::assertSame(1, $report['resourcesDegraded'], 'I4 (basiclti) degrades — no live OpenConnector deployment.');
        self::assertSame(1, $report['resourcesDropped'], 'I6 (discussion) has no scholiq schema representation.');
        self::assertSame('partial', $report['lifecycle']);
        self::assertNotNull($report['courseId']);

        // Leaf organization items are reported under the underlying CC *resource*
        // identifier (RES_*), not the organization item id — one row per source
        // package resource, matching the fidelity table. The one exception is the
        // "Module A" folder (I2), which has no underlying resource of its own.
        $outcomesByResourceId = [];
        foreach ($report['entries'] as $entry) {
            $outcomesByResourceId[$entry['resourceIdentifier']] = $entry['outcome'];
        }

        self::assertSame('imported', $outcomesByResourceId['RES_WEB']);
        self::assertSame('imported', $outcomesByResourceId['I2']);
        self::assertSame('imported', $outcomesByResourceId['RES_QTI']);
        self::assertSame('degraded', $outcomesByResourceId['RES_LTI']);
        self::assertSame('imported', $outcomesByResourceId['RES_WL']);
        self::assertSame('dropped', $outcomesByResourceId['RES_FORUM']);

        self::assertNotEmpty($this->savedByschema['item'] ?? [], 'The embedded QTI item was created via QtiImportService::importFromDirectory().');
        self::assertSame('choice', $this->savedByschema['item'][0]['interactionType']);
    }//end testImportCommonCartridgeFixtureProducesExpectedReport()

    /**
     * A Moodle backup fixture import creates the equivalent Course/Lesson/
     * Material/Item objects from the section/module structure and the
     * supported quiz-question subset, reporting every resource including the
     * dropped forum module and the degraded assign/quiz-question rows.
     *
     * @return void
     */
    public function testImportMoodleFixtureProducesExpectedReport(): void
    {
        $report = $this->service()->import(self::MOODLE_FIXTURE, 'minimal-moodle.mbz', 'admin', 'tenant-1');

        self::assertSame('moodle-backup', $report['sourceFormat']);
        self::assertSame('partial', $report['lifecycle']);
        self::assertNotNull($report['courseId']);

        $byResourceId = [];
        foreach ($report['entries'] as $entry) {
            $byResourceId[$entry['resourceIdentifier']] = $entry;
        }

        // Section entry + resource/url modules import cleanly.
        self::assertSame('imported', $byResourceId['2']['outcome']);
        self::assertSame('imported', $byResourceId['5']['outcome']);
        self::assertSame('imported', $byResourceId['8']['outcome']);

        // forum has no scholiq schema representation.
        self::assertSame('dropped', $byResourceId['7']['outcome']);

        // assign degrades (Moodle-specific grading workflow not carried).
        self::assertSame('degraded', $byResourceId['9']['outcome']);

        // quiz questions: 4 supported subtypes degrade (Moodle-derived, not QTI),
        // the unsupported ddwtos subtype drops.
        $quizQuestionEntries = array_filter(
            $report['entries'],
            static fn (array $e): bool => str_starts_with($e['resourceIdentifier'], '6-q')
        );
        self::assertCount(5, $quizQuestionEntries);
        $outcomes = array_column($quizQuestionEntries, 'outcome');
        self::assertSame(4, count(array_filter($outcomes, static fn ($o) => $o === 'degraded')));
        self::assertSame(1, count(array_filter($outcomes, static fn ($o) => $o === 'dropped')));
    }//end testImportMoodleFixtureProducesExpectedReport()

    /**
     * An archive that is not a valid ZIP/gzipped-tar at all resolves to
     * `lifecycle: failed` with a non-empty `errorMessage`, `courseId` stays
     * null, and no partial objects are created.
     *
     * @return void
     */
    public function testCorruptArchiveFailsLoudlyNotSilently(): void
    {
        $service = $this->service();
        $report  = $service->import(self::CORRUPT_FIXTURE, 'corrupt.bin', 'admin', 'tenant-1');

        self::assertSame('failed', $report['lifecycle']);
        self::assertNotEmpty($report['errorMessage']);
        self::assertNull($report['courseId']);
        self::assertSame([], $report['entries']);
        self::assertArrayNotHasKey('course', $this->savedByschema, 'No partial Course objects are left behind on a failed import.');
    }//end testCorruptArchiveFailsLoudlyNotSilently()

    /**
     * A valid ZIP that has no recognisable `imsmanifest.xml` also fails loudly.
     *
     * @return void
     */
    public function testZipWithoutManifestFailsLoudly(): void
    {
        $report = $this->service()->import(self::NO_MANIFEST_FIXTURE, 'no-manifest.imscc', 'admin', 'tenant-1');

        self::assertSame('failed', $report['lifecycle']);
        self::assertNotEmpty($report['errorMessage']);
        self::assertNull($report['courseId']);
    }//end testZipWithoutManifestFailsLoudly()
}//end class
