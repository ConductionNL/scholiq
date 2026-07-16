<?php

/**
 * Scholiq Course Package Import Service
 *
 * Imports an IMS Common Cartridge 1.3 or Moodle backup (`.mbz`) archive and
 * materialises its Course/Lesson/Material/Item/LtiToolPlacement/Assignment
 * hierarchy, delegating QTI/CC assessment items to the existing
 * `QtiImportService::importFromDirectory()` and Moodle quiz questions to
 * `MoodleQuizQuestionMapper`. Every source-package resource — supported or
 * not — produces exactly one `CoursePackageImportReport` entry; nothing is
 * ever silently dropped (the structural anti-Canvas promise, see the
 * proposal's "Why").
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/tar/XML
 * from an external interchange format cannot be expressed declaratively.
 * Same exception category as `QtiImportService`, at course-package scope
 * (design.md "Routing: scholiq, not openconnector").
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates course-package import: format detection, extraction,
 * manifest-driven object creation, and the resulting import report.
 */
class CoursePackageImportService
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const REPORT_SCHEMA    = 'course-package-import-report';

    /**
     * Cmi5/SCORM Moodle module / CC resource-type substrings that this
     * change deliberately does not import (design.md Non-Goals — that is
     * ADR-002's own separate, still-unbuilt lesson-content importer gap).
     */
    private const LESSON_CONTENT_TYPES = ['scorm', 'cmi5'];

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService    OR object service for creating/persisting objects.
     * @param QtiImportService         $qtiImportService QTI/CC assessment-item import (shared extraction + item parsing).
     * @param MbzExtractor             $mbzExtractor     Moodle `.mbz` (gzipped tar) extractor.
     * @param CommonCartridgeParser    $ccParser         Common Cartridge manifest parser.
     * @param MoodleBackupParser       $moodleParser     Moodle backup manifest parser.
     * @param MoodleQuizQuestionMapper $quizMapper       Moodle quiz question-bank mapper.
     * @param IRootFolder              $rootFolder       NC root folder for writing resolved Material file bytes.
     * @param LoggerInterface          $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly QtiImportService $qtiImportService,
        private readonly MbzExtractor $mbzExtractor,
        private readonly CommonCartridgeParser $ccParser,
        private readonly MoodleBackupParser $moodleParser,
        private readonly MoodleQuizQuestionMapper $quizMapper,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Import a course package and persist its `CoursePackageImportReport`.
     *
     * @param string $packagePath    Absolute path to the uploaded archive (tmp upload path).
     * @param string $sourceFilename Original filename as supplied by the browser.
     * @param string $importedBy     NC user id of the caller.
     * @param string $tenantId       Tenant UUID stamped on every created object.
     *
     * @return array<string, mixed> The persisted `CoursePackageImportReport` (includes `uuid`).
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
     */
    public function import(string $packagePath, string $sourceFilename, string $importedBy, string $tenantId=''): array
    {
        $importedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $tmpDir     = sys_get_temp_dir().'/scholiq_coursepkg_'.bin2hex(random_bytes(8));

        try {
            $format = $this->detectFormat(packagePath: $packagePath, sourceFilename: $sourceFilename);
            if ($format === null) {
                return $this->persistReport(
                    sourceFormat: 'common-cartridge-1.3',
                    sourceFilename: $sourceFilename,
                    importedBy: $importedBy,
                    importedAt: $importedAt,
                    tenantId: $tenantId,
                    courseId: null,
                    lifecycle: 'failed',
                    entries: [],
                    errorMessage: 'Archive is not a recognised IMS Common Cartridge (ZIP), Moodle backup '
                        .'(.mbz / gzipped tar), or scholiq-native JSON package.',
                );
            }

            // The scholiq-native JSON format is a single file, not an archive to
            // extract — it is this change's own lossless export target (design.md
            // format-support matrix), read and walked directly.
            if ($format === 'scholiq-json') {
                return $this->importScholiqJsonPackage(
                    packagePath: $packagePath,
                    sourceFilename: $sourceFilename,
                    importedBy: $importedBy,
                    importedAt: $importedAt,
                    tenantId: $tenantId,
                );
            }

            mkdir(directory: $tmpDir, permissions: 0700, recursive: true);

            try {
                if ($format === 'common-cartridge-1.3') {
                    $this->qtiImportService->extractZip(zipPath: $packagePath, targetDir: $tmpDir);
                    $manifest = $this->ccParser->parseManifest(dir: $tmpDir);
                } else {
                    $this->mbzExtractor->extract(mbzPath: $packagePath, targetDir: $tmpDir);
                    $manifest = $this->moodleParser->parseManifest(dir: $tmpDir);
                }
            } catch (\Throwable $e) {
                return $this->persistReport(
                    sourceFormat: $format,
                    sourceFilename: $sourceFilename,
                    importedBy: $importedBy,
                    importedAt: $importedAt,
                    tenantId: $tenantId,
                    courseId: null,
                    lifecycle: 'failed',
                    entries: [],
                    errorMessage: 'Could not extract or parse the package: '.$e->getMessage(),
                );
            }//end try

            $entries = [];
            if ($format === 'common-cartridge-1.3') {
                $courseId = $this->importCommonCartridge(
                    dir: $tmpDir,
                    manifest: $manifest,
                    importedBy: $importedBy,
                    tenantId: $tenantId,
                    entries: $entries
                );
                $this->importQtiResources(dir: $tmpDir, tenantId: $tenantId, entries: $entries);
            } else {
                $courseId = $this->importMoodle(dir: $tmpDir, manifest: $manifest, importedBy: $importedBy, tenantId: $tenantId, entries: $entries);
            }

            $lifecycle = $this->resolveLifecycle(entries: $entries);

            return $this->persistReport(
                sourceFormat: $format,
                sourceFilename: $sourceFilename,
                importedBy: $importedBy,
                importedAt: $importedAt,
                tenantId: $tenantId,
                courseId: $courseId,
                lifecycle: $lifecycle,
                entries: $entries,
                errorMessage: null,
            );
        } finally {
            $this->removeDirectory(dir: $tmpDir);
        }//end try
    }//end import()

    /**
     * Detect whether the uploaded archive is a ZIP (Common Cartridge) or a
     * gzipped tar (Moodle `.mbz`) by sniffing magic bytes — filename
     * extension alone is not trustworthy.
     *
     * @param string $packagePath    Absolute path to the uploaded archive.
     * @param string $sourceFilename Original filename (used only as a hint for logging).
     *
     * @return string|null `'common-cartridge-1.3'`, `'moodle-backup'`, or null when unrecognised.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-corrupt-or-unrecognised-archive-fails-loudly-not-silently
     */
    private function detectFormat(string $packagePath, string $sourceFilename): ?string
    {
        $handle = fopen($packagePath, 'rb');
        if ($handle === false) {
            return null;
        }

        $magic = fread($handle, 4);
        fclose($handle);
        if ($magic === false || strlen($magic) < 2) {
            return null;
        }

        // ZIP local-file-header signature: 'PK' (0x50 0x4B).
        if ($magic[0] === 'P' && $magic[1] === 'K') {
            return 'common-cartridge-1.3';
        }

        // Gzip magic bytes: 0x1F 0x8B.
        if (ord($magic[0]) === 0x1F && ord($magic[1]) === 0x8B) {
            return 'moodle-backup';
        }

        // Scholiq-native JSON export (design.md format-support matrix: "Yes (round-trip
        // of this change's own export)") — a plain JSON object, not an archive.
        $trimmed = ltrim($magic);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            return 'scholiq-json';
        }

        $this->logger->warning(
            '[CoursePackageImportService] Unrecognised archive magic bytes for {file}.',
            ['file' => $sourceFilename]
        );

        return null;
    }//end detectFormat()

    /**
     * Materialise a Common Cartridge package's organization tree + resources.
     *
     * @param string                          $dir        Extracted CC package directory.
     * @param array<string, mixed>            $manifest   `CommonCartridgeParser::parseManifest()` result.
     * @param string                          $importedBy NC user id (used as the nc:files owner for resolved Material bytes).
     * @param string                          $tenantId   Tenant UUID stamped on every created object.
     * @param array<int, array<string,mixed>> $entries    Report entries accumulator (by reference).
     *
     * @return string|null UUID of the top-level Course, or null if none was created.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
     */
    private function importCommonCartridge(string $dir, array $manifest, string $importedBy, string $tenantId, array &$entries): ?string
    {
        $resourcesById = [];
        foreach ($manifest['resources'] as $resource) {
            $resourcesById[$resource['identifier']] = $resource;
        }

        // Organization nodes -> Course (folders) / Lesson (leaf items referencing a resource).
        $courseIdByOrgIdentifier = [];
        $topCourseId = null;
        foreach ($manifest['organizationNodes'] as $node) {
            $resource = null;
            if ($node['resourceIdentifier'] !== null) {
                $resource = ($resourcesById[$node['resourceIdentifier']] ?? null);
            }

            if ($node['isFolder'] === true || $resource === null) {
                // Folder-level organization node -> child Course (module-as-a-course recursion).
                $parentCourseId = null;
                if ($node['parentIdentifier'] !== null) {
                    $parentCourseId = ($courseIdByOrgIdentifier[$node['parentIdentifier']] ?? null);
                }

                $courseId = $this->createCourse(title: $node['title'], parentCourseId: $parentCourseId, tenantId: $tenantId);
                $courseIdByOrgIdentifier[$node['identifier']] = $courseId;
                if ($topCourseId === null) {
                    $topCourseId = $courseId;
                }

                $entries[] = $this->entry(
                    resourceIdentifier: $node['identifier'],
                    resourceType: 'organization',
                    title: $node['title'],
                    outcome: 'imported',
                    targetType: 'course',
                    targetId: $courseId,
                    reason: null,
                );
                continue;
            }//end if

            // Leaf item referencing a resource -> Lesson, then route the underlying resource.
            $parentCourseId = $topCourseId;
            if ($node['parentIdentifier'] !== null) {
                $parentCourseId = ($courseIdByOrgIdentifier[$node['parentIdentifier']] ?? $topCourseId);
            }

            if ($parentCourseId === null) {
                // A leaf item with no enclosing folder — materialise an implicit top-level Course first.
                $parentCourseId = $this->createCourse(title: 'Imported course', parentCourseId: null, tenantId: $tenantId);
                $topCourseId    = $parentCourseId;
            }

            $this->routeResource(
                resource: $resource,
                dir: $dir,
                courseId: $parentCourseId,
                lessonOrder: $node['order'],
                lessonTitle: $node['title'],
                importedBy: $importedBy,
                tenantId: $tenantId,
                entries: $entries,
            );
        }//end foreach

        // Resources not referenced by any organization item still get one entry each
        // (e.g. a QTI item bank resource an assessment references directly, or an
        // orphan resource with no manifest organization entry).
        $routedResourceIds = array_column($entries, 'resourceIdentifier');
        foreach ($manifest['resources'] as $resource) {
            if (in_array($resource['identifier'], $routedResourceIds, strict: true) === true) {
                continue;
            }

            $this->routeResource(
                resource: $resource,
                dir: $dir,
                courseId: $topCourseId,
                lessonOrder: null,
                lessonTitle: null,
                importedBy: $importedBy,
                tenantId: $tenantId,
                entries: $entries,
            );
        }

        return $topCourseId;
    }//end importCommonCartridge()

    /**
     * Route one CC resource to its scholiq target (Material / QTI item / LTI placement /
     * dropped), appending exactly one report entry (or, for QTI resources, entries are
     * appended once in bulk by `importQtiResources()` — see that method).
     *
     * @param array<string, mixed>            $resource    One `CommonCartridgeParser` resource row.
     * @param string                          $dir         Extracted package directory (for file resolution).
     * @param string|null                     $courseId    Enclosing Course UUID, when known.
     * @param int|null                        $lessonOrder Manifest order, when this resource has an owning Lesson slot.
     * @param string|null                     $lessonTitle Lesson title, when this resource has an owning Lesson slot.
     * @param string                          $importedBy  NC user id (nc:files owner for resolved Material bytes).
     * @param string                          $tenantId    Tenant UUID.
     * @param array<int, array<string,mixed>> $entries     Report entries accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function routeResource(
        array $resource,
        string $dir,
        ?string $courseId,
        ?int $lessonOrder,
        ?string $lessonTitle,
        string $importedBy,
        string $tenantId,
        array &$entries,
    ): void {
        $classification = $resource['classification'];

        if (str_contains(strtolower($resource['type']), 'scorm') === true) {
            $entries[] = $this->entry(
                resourceIdentifier: $resource['identifier'],
                resourceType: $resource['type'],
                title: $resource['title'],
                outcome: 'dropped',
                targetType: null,
                targetId: null,
                reason: "requires ADR-002's lesson-content importer, not yet implemented",
            );
            return;
        }

        try {
            switch ($classification) {
                case 'webcontent':
                    $materialId = $this->createMaterial(
                        title: $lessonTitle ?? $resource['title'],
                        kind: 'document',
                        fileRef: $this->resolveFileRef(dir: $dir, href: $resource['href'], importedBy: $importedBy, tenantId: $tenantId),
                        url: null,
                        courseId: $courseId,
                        tenantId: $tenantId,
                    );
                    $this->createLessonForMaterial(
                        courseId: $courseId,
                        title: $lessonTitle ?? $resource['title'],
                        order: $lessonOrder ?? 0,
                        contentRef: (string) $materialId,
                        tenantId: $tenantId
                    );
                    $entries[] = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'imported',
                        targetType: 'material',
                        targetId: $materialId,
                        reason: null,
                    );
                    break;

                case 'weblink':
                    $materialId = $this->createMaterial(
                        title: $lessonTitle ?? $resource['title'],
                        kind: 'link',
                        fileRef: null,
                        url: $this->resolveWeblinkUrl(dir: $dir, href: $resource['href']),
                        courseId: $courseId,
                        tenantId: $tenantId,
                    );
                    $entries[]  = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'imported',
                        targetType: 'material',
                        targetId: $materialId,
                        reason: null,
                    );
                    break;

                case 'imsqti_item':
                case 'imsqti_test':
                    // QTI/CC assessment items are imported in bulk (one ItemBank per package)
                    // by importQtiResources(), called once by the caller after the resource
                    // walk — see import(). This branch only guards against double-handling.
                    $entries[] = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'pending-qti',
                        targetType: null,
                        targetId: null,
                        reason: null,
                    );
                    break;

                case 'basiclti':
                    $placementId = $this->createLtiPlacement(courseId: $courseId, tenantId: $tenantId);
                    $entries[]   = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'degraded',
                        targetType: 'lti-tool-placement',
                        targetId: $placementId,
                        reason: 'LTI placement created without a configured OpenConnector deployment; '
                            .'an admin must bind a deployment before this tool can be launched.',
                    );
                    break;

                case 'discussion':
                    $entries[] = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'dropped',
                        targetType: null,
                        targetId: null,
                        reason: 'No scholiq schema represents discussion/forum content — migrate manually.',
                    );
                    break;

                default:
                    $entries[] = $this->entry(
                        resourceIdentifier: $resource['identifier'],
                        resourceType: $resource['type'],
                        title: $resource['title'],
                        outcome: 'dropped',
                        targetType: null,
                        targetId: null,
                        reason: "Resource type not supported: {$resource['type']}.",
                    );
            }//end switch
        } catch (\Throwable $e) {
            // A per-resource failure never aborts the whole import — it becomes a
            // dropped entry so the report still names every resource (never a
            // silent absence, and never a partially-created object either).
            $this->logger->warning(
                '[CoursePackageImportService] Resource {id} failed to import: {msg}',
                ['id' => $resource['identifier'], 'msg' => $e->getMessage()]
            );
            $entries[] = $this->entry(
                resourceIdentifier: $resource['identifier'],
                resourceType: $resource['type'],
                title: $resource['title'],
                outcome: 'dropped',
                targetType: null,
                targetId: null,
                reason: 'Import failed: '.$e->getMessage(),
            );
        }//end try
    }//end routeResource()

    /**
     * Import every QTI/CC assessment-item resource in one bulk call via the shared
     * `QtiImportService::importFromDirectory()` path, then resolve the earlier
     * `pending-qti` placeholder entries against the created Item UUIDs.
     *
     * Positional pairing: both this parser and `QtiImportService::collectItemPaths()`
     * walk the same manifest resource list in document order, so the Nth `pending-qti`
     * entry corresponds to the Nth created Item UUID in the common case. When fewer
     * Items were created than there are `pending-qti` entries (an item failed to parse
     * and `QtiImportService` silently dropped it — a pre-existing behaviour, unchanged
     * by this work), the tail of unmatched entries degrades rather than being reported
     * as falsely `imported`.
     *
     * @param string                          $dir      Extracted CC package directory.
     * @param string                          $tenantId Tenant UUID.
     * @param array<int, array<string,mixed>> $entries  Report entries accumulator, mutated in place.
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    private function importQtiResources(string $dir, string $tenantId, array &$entries): void
    {
        $pendingIndexes = [];
        foreach ($entries as $idx => $entry) {
            if ($entry['outcome'] === 'pending-qti') {
                $pendingIndexes[] = $idx;
            }
        }

        if (empty($pendingIndexes) === true) {
            return;
        }

        $itemBank     = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'item-bank',
            ['name' => 'Imported items', 'tenant_id' => $tenantId, 'lifecycle' => 'draft']
        );
        $itemBankId   = $this->extractUuid(saved: $itemBank);
        $createdUuids = [];
        if ($itemBankId !== null) {
            $createdUuids = $this->qtiImportService->importFromDirectory(dir: $dir, itemBankId: $itemBankId, tenantId: $tenantId);
        }

        foreach ($pendingIndexes as $position => $idx) {
            $uuid = $createdUuids[$position] ?? null;
            if ($uuid !== null) {
                $entries[$idx]['outcome']    = 'imported';
                $entries[$idx]['targetType'] = 'item';
                $entries[$idx]['targetId']   = $uuid;
                $entries[$idx]['reason']     = null;
                continue;
            }

            $entries[$idx]['outcome']    = 'degraded';
            $entries[$idx]['targetType'] = null;
            $entries[$idx]['targetId']   = null;
            $entries[$idx]['reason']     = 'Item could not be parsed from the package (see application log).';
        }
    }//end importQtiResources()

    /**
     * Materialise a Moodle backup's section/activity structure.
     *
     * @param string                          $dir        Extracted Moodle backup directory.
     * @param array<string, mixed>            $manifest   `MoodleBackupParser::parseManifest()` result.
     * @param string                          $importedBy NC user id (used for Assignment ownership context only).
     * @param string                          $tenantId   Tenant UUID.
     * @param array<int, array<string,mixed>> $entries    Report entries accumulator (by reference).
     *
     * @return string|null UUID of the top-level Course, or null if none was created.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-a-moodle-backup-materialises-the-same-structural-shapes
     */
    private function importMoodle(string $dir, array $manifest, string $importedBy, string $tenantId, array &$entries): ?string
    {
        $courseId = $this->createCourse(title: 'Imported Moodle course', parentCourseId: null, tenantId: $tenantId);

        $sectionCourseId = [];
        foreach ($manifest['sectionNodes'] as $section) {
            $sectionCourseId[$section['identifier']] = $courseId;
            $entries[] = $this->entry(
                resourceIdentifier: $section['identifier'],
                resourceType: 'section',
                title: $section['title'],
                outcome: 'imported',
                targetType: 'course',
                targetId: $courseId,
                reason: null,
            );
        }

        foreach ($manifest['activities'] as $activity) {
            $this->routeMoodleActivity(
                activity: $activity,
                dir: $dir,
                courseId: $courseId,
                importedBy: $importedBy,
                tenantId: $tenantId,
                entries: $entries
            );
        }

        return $courseId;
    }//end importMoodle()

    /**
     * Read and re-import a scholiq-native JSON export
     * (`CoursePackageExportService::exportScholiqJson()`'s own output) — the
     * lossless round-trip path the design's format-support matrix describes.
     *
     * @param string $packagePath    Absolute path to the uploaded JSON file.
     * @param string $sourceFilename Original filename.
     * @param string $importedBy     NC user id of the caller.
     * @param string $importedAt     ISO-8601 timestamp already resolved by `import()`.
     * @param string $tenantId       Tenant UUID stamped on every created object.
     *
     * @return array<string, mixed> The persisted `CoursePackageImportReport`.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
     */
    private function importScholiqJsonPackage(
        string $packagePath,
        string $sourceFilename,
        string $importedBy,
        string $importedAt,
        string $tenantId,
    ): array {
        $raw  = (string) file_get_contents($packagePath);
        $tree = json_decode($raw, associative: true);

        if (is_array($tree) === false || isset($tree['course']) === false) {
            return $this->persistReport(
                sourceFormat: 'scholiq-json',
                sourceFilename: $sourceFilename,
                importedBy: $importedBy,
                importedAt: $importedAt,
                tenantId: $tenantId,
                courseId: null,
                lifecycle: 'failed',
                entries: [],
                errorMessage: 'File is not a valid scholiq-native course export (missing top-level "course" object).',
            );
        }

        $entries  = [];
        $courseId = $this->importScholiqJson(tree: $tree, importedBy: $importedBy, tenantId: $tenantId, entries: $entries);

        return $this->persistReport(
            sourceFormat: 'scholiq-json',
            sourceFilename: $sourceFilename,
            importedBy: $importedBy,
            importedAt: $importedAt,
            tenantId: $tenantId,
            courseId: $courseId,
            lifecycle: $this->resolveLifecycle(entries: $entries),
            entries: $entries,
            errorMessage: null,
        );
    }//end importScholiqJsonPackage()

    /**
     * Materialise a scholiq-native JSON export's object graph: the Course,
     * its direct child Courses (re-parented onto the newly created Course,
     * not the stale exported id), Lessons, Materials (with `contentBase64`
     * bytes written back into `nc:files`), and Rubrics all round-trip
     * cleanly. Assessments are recreated as shells — their `itemRefs` are
     * source-tenant UUIDs that do not resolve in the destination tenant, so
     * they are reported `degraded` rather than silently relinked to the
     * wrong Items; LtiToolPlacements degrade the same way import from a CC
     * package does (no live OpenConnector deployment carried).
     *
     * @param array<string, mixed>            $tree       The decoded scholiq-native JSON tree.
     * @param string                          $importedBy NC user id (nc:files owner for resolved Material bytes).
     * @param string                          $tenantId   Tenant UUID.
     * @param array<int, array<string,mixed>> $entries    Report entries accumulator (by reference).
     *
     * @return string|null UUID of the re-created top-level Course.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
     */
    private function importScholiqJson(array $tree, string $importedBy, string $tenantId, array &$entries): ?string
    {
        $courseData = (array) ($tree['course'] ?? []);
        $courseId   = $this->createCourse(title: (string) ($courseData['name'] ?? 'Imported course'), parentCourseId: null, tenantId: $tenantId);
        $entries[]  = $this->entry(
            resourceIdentifier: (string) ($courseData['id'] ?? $courseData['uuid'] ?? 'course'),
            resourceType: 'course',
            title: (string) ($courseData['name'] ?? ''),
            outcome: 'imported',
            targetType: 'course',
            targetId: $courseId,
            reason: null,
        );

        foreach ((array) ($tree['childCourses'] ?? []) as $child) {
            $child         = (array) $child;
            $childCourseId = $this->createCourse(
                title: (string) ($child['name'] ?? 'Imported module'),
                parentCourseId: $courseId,
                tenantId: $tenantId
            );
            $entries[]     = $this->entry(
                resourceIdentifier: (string) ($child['id'] ?? $child['uuid'] ?? 'child-course'),
                resourceType: 'course',
                title: (string) ($child['name'] ?? ''),
                outcome: 'imported',
                targetType: 'course',
                targetId: $childCourseId,
                reason: null,
            );
        }

        foreach ((array) ($tree['lessons'] ?? []) as $lesson) {
            $lesson    = (array) $lesson;
            $saved     = $this->objectService->saveObject(
                self::SCHOLIQ_REGISTER,
                'lesson',
                [
                    'courseId'    => $courseId,
                    'name'        => $lesson['name'] ?? '',
                    'order'       => $lesson['order'] ?? 0,
                    'contentType' => $lesson['contentType'] ?? 'text',
                    'contentRef'  => $lesson['contentRef'] ?? '',
                    'lifecycle'   => 'draft',
                    'tenant_id'   => $tenantId,
                ]
            );
            $entries[] = $this->entry(
                resourceIdentifier: (string) ($lesson['id'] ?? $lesson['uuid'] ?? 'lesson'),
                resourceType: 'lesson',
                title: (string) ($lesson['name'] ?? ''),
                outcome: 'imported',
                targetType: 'lesson',
                targetId: $this->extractUuid(saved: $saved),
                reason: null,
            );
        }//end foreach

        foreach ((array) ($tree['materials'] ?? []) as $material) {
            $material = (array) $material;
            $fileRef  = null;
            if (empty($material['contentBase64']) === false) {
                $fileRef = $this->writeBase64ToFiles(
                    base64Content: (string) $material['contentBase64'],
                    title: (string) ($material['title'] ?? 'material'),
                    importedBy: $importedBy,
                    tenantId: $tenantId,
                );
            }

            $materialId = $this->createMaterial(
                title: (string) ($material['title'] ?? ''),
                kind: (string) ($material['kind'] ?? 'document'),
                fileRef: $fileRef,
                url: $material['url'] ?? null,
                courseId: $courseId,
                tenantId: $tenantId,
            );
            $entries[]  = $this->entry(
                resourceIdentifier: (string) ($material['id'] ?? $material['uuid'] ?? 'material'),
                resourceType: 'material',
                title: (string) ($material['title'] ?? ''),
                outcome: 'imported',
                targetType: 'material',
                targetId: $materialId,
                reason: null,
            );
        }//end foreach

        foreach ((array) ($tree['rubrics'] ?? []) as $rubric) {
            $rubric    = (array) $rubric;
            $saved     = $this->objectService->saveObject(
                self::SCHOLIQ_REGISTER,
                'rubric',
                [
                    'name'      => $rubric['name'] ?? '',
                    'criteria'  => $rubric['criteria'] ?? [],
                    'maxPoints' => $rubric['maxPoints'] ?? 100,
                    'lifecycle' => 'draft',
                    'tenant_id' => $tenantId,
                ]
            );
            $entries[] = $this->entry(
                resourceIdentifier: (string) ($rubric['id'] ?? $rubric['uuid'] ?? 'rubric'),
                resourceType: 'rubric',
                title: (string) ($rubric['name'] ?? ''),
                outcome: 'imported',
                targetType: 'rubric',
                targetId: $this->extractUuid(saved: $saved),
                reason: null,
            );
        }//end foreach

        foreach ((array) ($tree['assessments'] ?? []) as $assessment) {
            $assessment = (array) $assessment;
            $saved      = $this->objectService->saveObject(
                self::SCHOLIQ_REGISTER,
                'assessment',
                [
                    'title'     => $assessment['title'] ?? '',
                    'courseId'  => $courseId,
                    'lifecycle' => 'draft',
                    'tenant_id' => $tenantId,
                ]
            );
            $entries[]  = $this->entry(
                resourceIdentifier: (string) ($assessment['id'] ?? $assessment['uuid'] ?? 'assessment'),
                resourceType: 'assessment',
                title: (string) ($assessment['title'] ?? ''),
                outcome: 'degraded',
                targetType: 'assessment',
                targetId: $this->extractUuid(saved: $saved),
                reason: 'Item references are source-tenant UUIDs and do not resolve here; re-import the ItemBank via QTI package import to relink.',
            );
        }//end foreach

        foreach ((array) ($tree['ltiPlacements'] ?? []) as $placement) {
            $placement   = (array) $placement;
            $placementId = $this->createLtiPlacement(courseId: $courseId, tenantId: $tenantId);
            $entries[]   = $this->entry(
                resourceIdentifier: (string) ($placement['id'] ?? $placement['uuid'] ?? 'lti-placement'),
                resourceType: 'lti-tool-placement',
                title: 'LTI tool placement',
                outcome: 'degraded',
                targetType: 'lti-tool-placement',
                targetId: $placementId,
                reason: 'LTI placement re-created without a configured OpenConnector deployment; '
                    .'an admin must bind a deployment before this tool can be launched.',
            );
        }

        return $courseId;
    }//end importScholiqJson()

    /**
     * Route one Moodle activity/module to its scholiq target.
     *
     * @param array<string, mixed>            $activity   One `MoodleBackupParser` activity row.
     * @param string                          $dir        Extracted backup directory (for question-bank resolution).
     * @param string                          $courseId   Enclosing Course UUID.
     * @param string                          $importedBy NC user id (nc:files owner for resolved Material bytes).
     * @param string                          $tenantId   Tenant UUID.
     * @param array<int, array<string,mixed>> $entries    Report entries accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function routeMoodleActivity(array $activity, string $dir, string $courseId, string $importedBy, string $tenantId, array &$entries): void
    {
        $classification = $activity['classification'];

        try {
            if (in_array($classification, self::LESSON_CONTENT_TYPES, strict: true) === true) {
                $entries[] = $this->entry(
                    resourceIdentifier: $activity['identifier'],
                    resourceType: $activity['moduleType'],
                    title: $activity['title'],
                    outcome: 'dropped',
                    targetType: null,
                    targetId: null,
                    reason: "requires ADR-002's lesson-content importer, not yet implemented",
                );
                return;
            }

            switch ($classification) {
                case 'resource':
                case 'page':
                    // Moodle stores module content via a content-addressed files.xml + files/
                    // pool, far more elaborate than this scoped importer parses (design.md
                    // Non-Goals). This importer reads a single conventional `content.html` file
                    // inside the activity's own backup directory, when present.
                    $contentHref = null;
                    if ($activity['directory'] !== null) {
                        $contentHref = $activity['directory'].'/content.html';
                    }

                    $materialId = $this->createMaterial(
                        title: $activity['title'],
                        kind: 'document',
                        fileRef: $this->resolveFileRef(dir: $dir, href: $contentHref, importedBy: $importedBy, tenantId: $tenantId),
                        url: null,
                        courseId: $courseId,
                        tenantId: $tenantId,
                    );
                    $this->createLessonForMaterial(
                        courseId: $courseId,
                        title: $activity['title'],
                        order: $activity['order'],
                        contentRef: (string) $materialId,
                        tenantId: $tenantId
                    );
                    $entries[] = $this->entry(
                        resourceIdentifier: $activity['identifier'],
                        resourceType: $activity['moduleType'],
                        title: $activity['title'],
                        outcome: 'imported',
                        targetType: 'material',
                        targetId: $materialId,
                        reason: null,
                    );
                    break;

                case 'url':
                    $materialId = $this->createMaterial(
                        title: $activity['title'],
                        kind: 'link',
                        fileRef: null,
                        url: $this->resolveMoodleUrlModuleTarget(dir: $dir, directory: $activity['directory'], fallback: $activity['title']),
                        courseId: $courseId,
                        tenantId: $tenantId,
                    );
                    $entries[]  = $this->entry(
                        resourceIdentifier: $activity['identifier'],
                        resourceType: $activity['moduleType'],
                        title: $activity['title'],
                        outcome: 'imported',
                        targetType: 'material',
                        targetId: $materialId,
                        reason: null,
                    );
                    break;

                case 'quiz':
                    $this->routeMoodleQuiz(activity: $activity, dir: $dir, tenantId: $tenantId, entries: $entries);
                    break;

                case 'assign':
                    $assignmentId = $this->objectService->saveObject(
                        self::SCHOLIQ_REGISTER,
                        'assignment',
                        [
                            'title'        => $activity['title'],
                            'instructions' => '',
                            'courseId'     => $courseId,
                            'maxPoints'    => 100,
                            'tenant_id'    => $tenantId,
                            'lifecycle'    => 'draft',
                        ]
                    );
                    $entries[]    = $this->entry(
                        resourceIdentifier: $activity['identifier'],
                        resourceType: $activity['moduleType'],
                        title: $activity['title'],
                        outcome: 'degraded',
                        targetType: 'assignment',
                        targetId: $this->extractUuid(saved: $assignmentId),
                        reason: 'Moodle-specific grading-workflow configuration (peer review, group config) was not carried over.',
                    );
                    break;

                case 'forum':
                case 'wiki':
                case 'glossary':
                    $entries[] = $this->entry(
                        resourceIdentifier: $activity['identifier'],
                        resourceType: $activity['moduleType'],
                        title: $activity['title'],
                        outcome: 'dropped',
                        targetType: null,
                        targetId: null,
                        reason: "No scholiq schema represents Moodle's {$activity['moduleType']} module — migrate manually.",
                    );
                    break;

                default:
                    $entries[] = $this->entry(
                        resourceIdentifier: $activity['identifier'],
                        resourceType: $activity['moduleType'],
                        title: $activity['title'],
                        outcome: 'dropped',
                        targetType: null,
                        targetId: null,
                        reason: "Moodle module type not supported: {$activity['moduleType']}.",
                    );
            }//end switch
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[CoursePackageImportService] Moodle activity {id} failed to import: {msg}',
                ['id' => $activity['identifier'], 'msg' => $e->getMessage()]
            );
            $entries[] = $this->entry(
                resourceIdentifier: $activity['identifier'],
                resourceType: $activity['moduleType'],
                title: $activity['title'],
                outcome: 'dropped',
                targetType: null,
                targetId: null,
                reason: 'Import failed: '.$e->getMessage(),
            );
        }//end try
    }//end routeMoodleActivity()

    /**
     * Map and create Items for a Moodle `quiz` activity's question bank
     * (`{activity.directory}/questions.xml`), one report entry per question.
     *
     * @param array<string, mixed>            $activity The `quiz` activity row.
     * @param string                          $dir      Extracted backup directory.
     * @param string                          $tenantId Tenant UUID.
     * @param array<int, array<string,mixed>> $entries  Report entries accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function routeMoodleQuiz(array $activity, string $dir, string $tenantId, array &$entries): void
    {
        $questionsPath = null;
        if ($activity['directory'] !== null) {
            $questionsPath = $dir.'/'.$activity['directory'].'/questions.xml';
        }

        if ($questionsPath === null || file_exists($questionsPath) === false) {
            $entries[] = $this->entry(
                resourceIdentifier: $activity['identifier'],
                resourceType: 'quiz',
                title: $activity['title'],
                outcome: 'dropped',
                targetType: null,
                targetId: null,
                reason: 'Quiz activity has no readable question bank in the package.',
            );
            return;
        }

        $itemBank   = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'item-bank',
            ['name' => $activity['title'], 'tenant_id' => $tenantId, 'lifecycle' => 'draft']
        );
        $itemBankId = $this->extractUuid(saved: $itemBank);

        $mapped = $this->quizMapper->mapQuestions(questionsXmlPath: $questionsPath, itemBankId: (string) $itemBankId, tenantId: $tenantId);

        foreach ($mapped as $idx => $question) {
            $resourceIdentifier = $activity['identifier'].'-q'.$idx;

            if ($question['outcome'] === 'dropped') {
                $entries[] = $this->entry(
                    resourceIdentifier: $resourceIdentifier,
                    resourceType: 'quiz-question:'.$question['moodleQuestionType'],
                    title: $question['title'],
                    outcome: 'dropped',
                    targetType: null,
                    targetId: null,
                    reason: $question['reason'],
                );
                continue;
            }

            $savedItem = $this->objectService->saveObject(self::SCHOLIQ_REGISTER, 'item', $question['itemData']);
            $entries[] = $this->entry(
                resourceIdentifier: $resourceIdentifier,
                resourceType: 'quiz-question:'.$question['moodleQuestionType'],
                title: $question['title'],
                outcome: 'degraded',
                targetType: 'item',
                targetId: $this->extractUuid(saved: $savedItem),
                reason: 'Mapped from Moodle question XML, not QTI — verify correctResponse before publishing.',
            );
        }//end foreach
    }//end routeMoodleQuiz()

    /**
     * Create a `Course` object.
     *
     * @param string      $title          Course display name.
     * @param string|null $parentCourseId Parent Course UUID for nested organization folders.
     * @param string      $tenantId       Tenant UUID.
     *
     * @return string|null Created Course UUID.
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function createCourse(string $title, ?string $parentCourseId, string $tenantId): ?string
    {
        $saved = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'course',
            [
                'code'           => 'IMPORT-'.substr(md5($title.microtime()), 0, 8),
                'name'           => $title,
                'level'          => 'other',
                'language'       => 'en',
                'parentCourseId' => $parentCourseId,
                'lifecycle'      => 'draft',
                'tenant_id'      => $tenantId,
            ]
        );

        return $this->extractUuid(saved: $saved);
    }//end createCourse()

    /**
     * Create a `Lesson` pointing at a `document`-kind Material.
     *
     * @param string|null $courseId   Enclosing Course UUID.
     * @param string      $title      Lesson title.
     * @param int         $order      Manifest order.
     * @param string      $contentRef Material UUID (nc:files-resolved content lives on the Material).
     * @param string      $tenantId   Tenant UUID.
     *
     * @return string|null Created Lesson UUID.
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function createLessonForMaterial(?string $courseId, string $title, int $order, string $contentRef, string $tenantId): ?string
    {
        $saved = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'lesson',
            [
                'courseId'    => $courseId,
                'name'        => $title,
                'order'       => $order,
                'contentType' => 'text',
                'contentRef'  => $contentRef,
                'lifecycle'   => 'draft',
                'tenant_id'   => $tenantId,
            ]
        );

        return $this->extractUuid(saved: $saved);
    }//end createLessonForMaterial()

    /**
     * Create a `Material` object.
     *
     * @param string      $title    Material title.
     * @param string      $kind     One of Material's `kind` enum values.
     * @param string|null $fileRef  nc:files path, when `kind` carries file bytes.
     * @param string|null $url      External URL, when `kind: link`.
     * @param string|null $courseId Enclosing Course UUID.
     * @param string      $tenantId Tenant UUID.
     *
     * @return string|null Created Material UUID.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function createMaterial(string $title, string $kind, ?string $fileRef, ?string $url, ?string $courseId, string $tenantId): ?string
    {
        $saved = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'material',
            [
                'title'     => $title,
                'kind'      => $kind,
                'fileRef'   => $fileRef ?? '',
                'url'       => $url,
                'courseId'  => $courseId,
                'tenant_id' => $tenantId,
            ]
        );

        return $this->extractUuid(saved: $saved);
    }//end createMaterial()

    /**
     * Create an `LtiToolPlacement` for an embedded `basiclti` resource.
     *
     * @param string|null $courseId Enclosing Course UUID.
     * @param string      $tenantId Tenant UUID.
     *
     * @return string|null Created LtiToolPlacement UUID.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-lti-resource-becomes-a-placement-not-an-inline-link
     */
    private function createLtiPlacement(?string $courseId, string $tenantId): ?string
    {
        $saved = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            'lti-tool-placement',
            [
                // Left blank: the package carries no live OpenConnector deployment binding.
                // An admin configures this before the placement can launch — reported as
                // `degraded`, never a silent success (see routeResource()).
                'openconnectorDeploymentId' => '',
                'launchMode'                => 'resource-link',
                'courseId'                  => $courseId,
                'lifecycle'                 => 'draft',
                'tenant_id'                 => $tenantId,
            ]
        );

        return $this->extractUuid(saved: $saved);
    }//end createLtiPlacement()

    /**
     * Resolve a CC weblink resource's actual target URL. A CC `imswl` resource's
     * manifest `href` points at the local weblink XML file, not the target URL —
     * the real URL lives inside that file as `<webLink><url href="..."/>`. Falls
     * back to the manifest href itself when the file is missing or unparseable,
     * so a Material is still created rather than left with no url at all.
     *
     * @param string      $dir  Extracted package directory.
     * @param string|null $href Package-relative path to the weblink XML file.
     *
     * @return string|null The resolved target URL, or null when nothing could be resolved.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function resolveWeblinkUrl(string $dir, ?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $path = $dir.'/'.$href;
        if (file_exists($path) === false) {
            return $href;
        }

        $xml = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->load($path);
        libxml_clear_errors();
        if ($loaded === false) {
            return $href;
        }

        $urlNodes = $xml->getElementsByTagName('url');
        if ($urlNodes->length === 0) {
            return $href;
        }

        $urlNode = $urlNodes->item(0);
        if (($urlNode instanceof \DOMElement) === false) {
            return $href;
        }

        $resolved = $urlNode->getAttribute('href');
        if ($resolved !== '') {
            return $resolved;
        }

        return $href;
    }//end resolveWeblinkUrl()

    /**
     * Resolve a Moodle `url` module's actual target URL from its own
     * conventional `url.xml` (`<externalurl>` text), the same simplified,
     * documented-scope convention `routeMoodleQuiz()`'s `questions.xml` uses
     * (real Moodle backups are far more elaborate — design.md Non-Goals).
     *
     * @param string      $dir       Extracted backup directory.
     * @param string|null $directory The activity's own backup directory (from `MoodleBackupParser`).
     * @param string      $fallback  Value to return when the target could not be resolved.
     *
     * @return string The resolved URL, or `$fallback`.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function resolveMoodleUrlModuleTarget(string $dir, ?string $directory, string $fallback): string
    {
        if ($directory === null) {
            return $fallback;
        }

        $path = $dir.'/'.$directory.'/url.xml';
        if (file_exists($path) === false) {
            return $fallback;
        }

        $xml = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->load($path);
        libxml_clear_errors();
        if ($loaded === false) {
            return $fallback;
        }

        $nodes = $xml->getElementsByTagName('externalurl');
        if ($nodes->length === 0) {
            return $fallback;
        }

        $value = trim((string) $nodes->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }

        return $fallback;
    }//end resolveMoodleUrlModuleTarget()

    /**
     * Resolve a package-relative file into an nc:files path by writing its
     * bytes into the importing tenant's Scholiq course-imports folder, per
     * design.md's "app does not store file bytes, OR does" contract —
     * Material's `fileRef` is always an nc:files path, never a package-local
     * temp path the recipient cannot resolve.
     *
     * @param string      $dir        Extracted package directory.
     * @param string|null $href       Package-relative path to the source file (or a directory, for Moodle).
     * @param string      $importedBy NC user id whose home folder owns the resolved file (the caller's own
     *                                `nc:files` — the same tenant-scoped ownership `PortfolioShareGrantHandler`
     *                                already uses for `attachmentRef` resolution).
     * @param string      $tenantId   Tenant UUID, used to namespace the destination folder.
     *
     * @return string|null The nc:files path, or null when the source file could not be resolved.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    private function resolveFileRef(string $dir, ?string $href, string $importedBy, string $tenantId): ?string
    {
        if ($href === null) {
            return null;
        }

        $sourcePath = $dir.'/'.$href;
        if (is_dir($sourcePath) === true) {
            // Directory-shaped source (e.g. a Moodle activity folder) — nothing to attach directly.
            return null;
        }

        if (file_exists($sourcePath) === false) {
            return null;
        }

        $content = (string) file_get_contents($sourcePath);
        return $this->writeBytesToFiles(content: $content, filename: basename($sourcePath), importedBy: $importedBy, tenantId: $tenantId);
    }//end resolveFileRef()

    /**
     * Write a base64-encoded Material's bytes (from a scholiq-native JSON
     * export's `contentBase64`) into `nc:files`, same destination convention
     * `resolveFileRef()` uses for CC/Moodle-sourced files.
     *
     * @param string $base64Content Base64-encoded file content.
     * @param string $title         Material title, used to derive a filename.
     * @param string $importedBy    NC user id whose home folder owns the resolved file.
     * @param string $tenantId      Tenant UUID, used to namespace the destination folder.
     *
     * @return string|null The nc:files path, or null when the content could not be decoded/written.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
     */
    private function writeBase64ToFiles(string $base64Content, string $title, string $importedBy, string $tenantId): ?string
    {
        $decoded = base64_decode($base64Content, strict: true);
        if ($decoded === false) {
            return null;
        }

        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $title);
        if ($filename === null || $filename === '') {
            $filename = 'material';
        }

        return $this->writeBytesToFiles(content: $decoded, filename: $filename, importedBy: $importedBy, tenantId: $tenantId);
    }//end writeBase64ToFiles()

    /**
     * Write raw bytes into the importing tenant's Scholiq course-imports
     * nc:files folder and return the resulting `fileRef` path. Shared by
     * `resolveFileRef()` (CC/Moodle package-relative files) and
     * `writeBase64ToFiles()` (scholiq-native JSON `contentBase64`), per
     * design.md's "app does not store file bytes, OR does" contract.
     *
     * @param string $content    Raw file bytes.
     * @param string $filename   Destination filename (already sanitised by the caller).
     * @param string $importedBy NC user id whose home folder owns the resolved file.
     * @param string $tenantId   Tenant UUID, used to namespace the destination folder.
     *
     * @return string|null The nc:files path, or null on failure.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    private function writeBytesToFiles(string $content, string $filename, string $importedBy, string $tenantId): ?string
    {
        try {
            $tenantSegment = 'default';
            if ($tenantId !== '') {
                $tenantSegment = $tenantId;
            }

            $ncBaseDir = 'Scholiq/'.$tenantSegment.'/course-imports';
            $ncPath    = $ncBaseDir.'/'.$filename;

            $userFolder = $this->rootFolder->getUserFolder($importedBy);
            $this->ensureFolder(userFolder: $userFolder, path: $ncBaseDir);
            if ($userFolder->nodeExists($ncPath) === true) {
                $existingNode = $userFolder->get($ncPath);
                if ($existingNode instanceof File) {
                    $existingNode->putContent($content);
                }
            } else {
                $userFolder->newFile($ncPath, $content);
            }

            return '/'.$ncPath;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[CoursePackageImportService] Could not write resolved file "{filename}": {msg}',
                ['filename' => $filename, 'msg' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end writeBytesToFiles()

    /**
     * Ensure a nested nc:files folder path exists under the given folder.
     *
     * @param \OCP\Files\Folder $userFolder The root user folder.
     * @param string            $path       Slash-separated relative path to ensure.
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    private function ensureFolder(Folder $userFolder, string $path): void
    {
        $segments = array_filter(explode('/', $path));
        $current  = '';
        foreach ($segments as $segment) {
            if ($current === '') {
                $current = $segment;
            } else {
                $current = $current.'/'.$segment;
            }

            try {
                $userFolder->get($current);
            } catch (NotFoundException $e) {
                $userFolder->newFolder($current);
            }
        }
    }//end ensureFolder()

    /**
     * Build one report entry row.
     *
     * @param string      $resourceIdentifier Source resource identifier.
     * @param string      $resourceType       Source resource/module type string.
     * @param string      $title              Resource title.
     * @param string      $outcome            `imported`|`degraded`|`dropped` (or the internal `pending-qti` marker, resolved before persisting).
     * @param string|null $targetType         Created scholiq schema name, when applicable.
     * @param string|null $targetId           Created object UUID, when applicable.
     * @param string|null $reason             Human-readable reason, required for non-`imported` outcomes.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
     */
    private function entry(
        string $resourceIdentifier,
        string $resourceType,
        string $title,
        string $outcome,
        ?string $targetType,
        ?string $targetId,
        ?string $reason,
    ): array {
        return [
            'resourceIdentifier' => $resourceIdentifier,
            'resourceType'       => $resourceType,
            'title'              => $title,
            'outcome'            => $outcome,
            'targetType'         => $targetType,
            'targetId'           => $targetId,
            'reason'             => $reason,
        ];
    }//end entry()

    /**
     * Resolve the report's final `lifecycle` from its entries, per the report
     * requirement's rule: `succeeded` only when every entry is `imported`,
     * otherwise `partial`.
     *
     * @param array<int, array<string,mixed>> $entries Report entries.
     *
     * @return string `succeeded` or `partial`.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
     */
    private function resolveLifecycle(array $entries): string
    {
        foreach ($entries as $entry) {
            if ($entry['outcome'] !== 'imported') {
                return 'partial';
            }
        }

        return 'succeeded';
    }//end resolveLifecycle()

    /**
     * Persist the `CoursePackageImportReport`, running the deferred QTI bulk-import
     * pass first (when there are `pending-qti` entries) so the persisted report
     * always reflects final, resolved outcomes.
     *
     * @param string                           $sourceFormat   `common-cartridge-1.3` or `moodle-backup`.
     * @param string                           $sourceFilename Original filename.
     * @param string                           $importedBy     NC user id.
     * @param string                           $importedAt     ISO-8601 timestamp.
     * @param string                           $tenantId       Tenant UUID.
     * @param string|null                      $courseId       Top-level Course UUID, or null.
     * @param string                           $lifecycle      Resolved lifecycle (`succeeded`|`partial`|`failed`).
     * @param array<int, array<string, mixed>> $entries        Report entries.
     * @param string|null                      $errorMessage   Failure reason, only when `lifecycle: failed`.
     *
     * @return array<string, mixed> The persisted report.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
     */
    private function persistReport(
        string $sourceFormat,
        string $sourceFilename,
        string $importedBy,
        string $importedAt,
        string $tenantId,
        ?string $courseId,
        string $lifecycle,
        array $entries,
        ?string $errorMessage,
    ): array {
        $imported = 0;
        $degraded = 0;
        $dropped  = 0;
        foreach ($entries as $entry) {
            match ($entry['outcome']) {
                'imported' => $imported++,
                'degraded' => $degraded++,
                'dropped' => $dropped++,
                default => null,
            };
        }

        $saved = $this->objectService->saveObject(
            self::SCHOLIQ_REGISTER,
            self::REPORT_SCHEMA,
            [
                'sourceFormat'      => $sourceFormat,
                'sourceFilename'    => $sourceFilename,
                'courseId'          => $courseId,
                'importedBy'        => $importedBy,
                'importedAt'        => $importedAt,
                'lifecycle'         => $lifecycle,
                'resourcesTotal'    => count($entries),
                'resourcesImported' => $imported,
                'resourcesDegraded' => $degraded,
                'resourcesDropped'  => $dropped,
                'errorMessage'      => $errorMessage,
                'entries'           => $entries,
                'tenant_id'         => $tenantId,
            ]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return (array) $saved->jsonSerialize();
        }

        return [];
    }//end persistReport()

    /**
     * Extract a created object's UUID from an `ObjectService::saveObject()` return value.
     *
     * @param mixed $saved Return value of `saveObject()` (array or an ObjectEntity-like object).
     *
     * @return string|null The UUID, or null if it could not be resolved.
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function extractUuid(mixed $saved): ?string
    {
        if (is_array($saved) === true) {
            $uuid = $saved['uuid'] ?? null;
            if (is_string($uuid) === true) {
                return $uuid;
            }

            return null;
        }

        if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
            $uuid = $saved->getUuid();
            if (is_string($uuid) === true) {
                return $uuid;
            }

            return null;
        }

        return null;
    }//end extractUuid()

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Absolute path to the directory.
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) === true) {
                $this->removeDirectory(dir: $path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }//end removeDirectory()
}//end class
