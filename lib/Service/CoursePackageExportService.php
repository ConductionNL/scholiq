<?php

/**
 * Scholiq Course Package Export Service
 *
 * Exports a `Course` (and its `Lesson`/`Material`/`Assessment`/`Rubric`/
 * `LtiToolPlacement` descendants) as (a) an IMS Common Cartridge 1.3 package
 * for interoperability with other LMS platforms and (b) a scholiq-native
 * JSON tree for lossless round-trip back into Scholiq.
 *
 * `Material.fileRef` bytes are resolved through Nextcloud's file API (the
 * same "app does not store file bytes, OR does" contract `Material`'s own
 * schema description states, and the same resolution direction
 * `CoursePackageImportService::resolveFileRef()` writes). Embedded assessment
 * items are exported in QTI 3.0 form via `QtiExportService` (the `assessment`
 * capability's own item-export capability) — this service never re-derives
 * `qtiBody` itself.
 *
 * Every object is read through OpenRegister's own `ObjectService::find()`/
 * `findAll()` (which already applies `x-property-rbac` for the calling
 * session), never a raw database query — so an export can never leak a field
 * the exporting user could not already see in the UI (design.md "Security /
 * Privacy Posture").
 *
 * Legitimate PHP per ADR-031 §"Document/ZIP generation": streaming a ZIP /
 * building an interop package cannot be expressed declaratively.
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds Common Cartridge and scholiq-native JSON exports of a `Course`.
 */
class CoursePackageExportService
{

    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object service (RBAC-applying object reads).
     * @param QtiExportService $qtiExportService QTI 3.0 item-bank export (the `assessment` capability's own exporter).
     * @param IRootFolder      $rootFolder       NC root folder for resolving `Material.fileRef` bytes.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly QtiExportService $qtiExportService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Export a Course as an IMS Common Cartridge 1.3 package ZIP.
     *
     * @param string $courseId      UUID of the Course to export.
     * @param string $exportingUser NC user id whose home folder resolves `Material.fileRef` bytes.
     *
     * @return string Raw ZIP bytes.
     *
     * @throws \RuntimeException When the Course does not exist.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-portable-common-cartridge-package
     */
    public function exportCommonCartridge(string $courseId, string $exportingUser): string
    {
        $tree = $this->gatherCourseTree(courseId: $courseId, exportingUser: $exportingUser);

        $files           = [];
        $resourceEntries = '';
        $organizationXml = '';

        foreach ($tree['lessons'] as $lesson) {
            $organizationXml .= '<item identifier="LESSON-'.htmlspecialchars((string) ($lesson['id'] ?? ''), ENT_XML1 | ENT_QUOTES).'">'
                .'<title>'.htmlspecialchars((string) ($lesson['name'] ?? ''), ENT_XML1 | ENT_QUOTES).'</title></item>';
        }

        foreach ($tree['materials'] as $material) {
            $filename = 'materials/'.($material['id'] ?? uniqid('material_', true)).'_'.basename((string) ($material['title'] ?? 'material'));
            if ($material['content'] !== null) {
                $files[$filename] = $material['content'];
                $resourceEntries .= '<resource identifier="MATERIAL-'.htmlspecialchars((string) $material['id'], ENT_XML1 | ENT_QUOTES)
                    .'" type="webcontent" href="'.$filename.'"><file href="'.$filename.'"/></resource>';
            }
        }

        foreach ($tree['itemBankPackages'] as $bankId => $packageBytes) {
            $filename         = 'assessments/qti-item-bank-'.$bankId.'.zip';
            $files[$filename] = $packageBytes;
        }

        $courseTitle = htmlspecialchars((string) ($tree['course']['name'] ?? 'Exported course'), ENT_XML1 | ENT_QUOTES);
        $manifest    = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" identifier="MANIFEST-COURSE-EXPORT" '
            .'xmlns:imsccv1p3="http://www.imsglobal.org/xsd/imsccv1p3/imscp_v1p1">'
            .'<metadata><schema>IMS Common Cartridge</schema><schemaversion>1.3.0</schemaversion><title>'.$courseTitle.'</title></metadata>'
            .'<organizations><organization identifier="ORG-1" structure="rooted-hierarchy">'.$organizationXml.'</organization></organizations>'
            .'<resources>'.$resourceEntries.'</resources>'
            .'</manifest>';

        $files['imsmanifest.xml'] = $manifest;

        return $this->buildZip(files: $files);
    }//end exportCommonCartridge()

    /**
     * Export a Course as a lossless scholiq-native JSON tree.
     *
     * @param string $courseId      UUID of the Course to export.
     * @param string $exportingUser NC user id whose home folder resolves `Material.fileRef` bytes.
     *
     * @return string JSON document.
     *
     * @throws \RuntimeException When the Course does not exist.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
     */
    public function exportScholiqJson(string $courseId, string $exportingUser): string
    {
        $tree = $this->gatherCourseTree(courseId: $courseId, exportingUser: $exportingUser);

        // Base64 the resolved bytes so the JSON tree is a single self-contained,
        // lossless artefact — the round-trip target `CoursePackageImportService`
        // can re-import without a second file-fetch pass.
        $materials = array_map(
            static function (array $material): array {
                if ($material['content'] !== null) {
                    $material['contentBase64'] = base64_encode($material['content']);
                }

                unset($material['content']);
                return $material;
            },
            $tree['materials']
        );

        $payload = [
            'schemaVersion' => '1.0',
            'exportedAt'    => gmdate('c'),
            'course'        => $tree['course'],
            'childCourses'  => $tree['childCourses'],
            'lessons'       => $tree['lessons'],
            'materials'     => $materials,
            'assessments'   => $tree['assessments'],
            'rubrics'       => $tree['rubrics'],
            'ltiPlacements' => $tree['ltiPlacements'],
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }//end exportScholiqJson()

    /**
     * Gather a Course's exportable object graph: the Course itself, child
     * Courses (organization folders), Lessons, Materials (with resolved file
     * bytes), Assessments (with their referenced ItemBanks exported via
     * `QtiExportService`), Rubrics (via Assignment.rubricId), and
     * LtiToolPlacements — all read through `ObjectService` so `x-property-rbac`
     * is enforced by construction.
     *
     * @param string $courseId      UUID of the Course.
     * @param string $exportingUser NC user id whose home folder resolves `Material.fileRef` bytes.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the Course does not exist.
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function gatherCourseTree(string $courseId, string $exportingUser): array
    {
        $course = $this->objectService->find(id: $courseId, register: self::SCHOLIQ_REGISTER, schema: 'course');
        if ($course === null) {
            throw new RuntimeException("Course '{$courseId}' not found.");
        }

        $courseData = $this->toArray(object: $course);

        $childCourses  = $this->findAllArrays(schema: 'course', filters: ['parentCourseId' => $courseId]);
        $lessons       = $this->findAllArrays(schema: 'lesson', filters: ['courseId' => $courseId]);
        $rawMaterials  = $this->findAllArrays(schema: 'material', filters: ['courseId' => $courseId]);
        $assessments   = $this->findAllArrays(schema: 'assessment', filters: ['courseId' => $courseId]);
        $assignments   = $this->findAllArrays(schema: 'assignment', filters: ['courseId' => $courseId]);
        $ltiPlacements = $this->findAllArrays(schema: 'lti-tool-placement', filters: ['courseId' => $courseId]);

        $materials = [];
        foreach ($rawMaterials as $material) {
            $material['content'] = $this->resolveFileBytes(fileRef: (string) ($material['fileRef'] ?? ''), exportingUser: $exportingUser);
            $materials[]         = $material;
        }

        $rubrics = [];
        foreach ($assignments as $assignment) {
            $rubricId = $assignment['rubricId'] ?? null;
            if (is_string($rubricId) === false || $rubricId === '') {
                continue;
            }

            $rubric = $this->objectService->find(id: $rubricId, register: self::SCHOLIQ_REGISTER, schema: 'rubric');
            if ($rubric !== null) {
                $rubrics[] = $this->toArray(object: $rubric);
            }
        }

        // Resolve each Assessment's referenced Items to their owning ItemBanks
        // and export each unique bank once via QtiExportService — never
        // re-serialised by this exporter itself.
        $itemBankIds = [];
        foreach ($assessments as $assessment) {
            foreach ((array) ($assessment['itemRefs'] ?? []) as $itemId) {
                if (is_string($itemId) === false) {
                    continue;
                }

                $item = $this->objectService->find(id: $itemId, register: self::SCHOLIQ_REGISTER, schema: 'item');
                if ($item === null) {
                    continue;
                }

                $itemData   = $this->toArray(object: $item);
                $itemBankId = $itemData['itemBankId'] ?? null;
                if (is_string($itemBankId) === true && $itemBankId !== '') {
                    $itemBankIds[$itemBankId] = true;
                }
            }
        }

        $itemBankPackages = [];
        foreach (array_keys($itemBankIds) as $bankId) {
            try {
                $itemBankPackages[$bankId] = $this->qtiExportService->export(itemBankId: $bankId);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[CoursePackageExportService] Could not export ItemBank {id}: {msg}',
                    ['id' => $bankId, 'msg' => $e->getMessage()]
                );
            }
        }

        return [
            'course'           => $courseData,
            'childCourses'     => $childCourses,
            'lessons'          => $lessons,
            'materials'        => $materials,
            'assessments'      => $assessments,
            'rubrics'          => $rubrics,
            'ltiPlacements'    => $ltiPlacements,
            'itemBankPackages' => $itemBankPackages,
        ];
    }//end gatherCourseTree()

    /**
     * Resolve a `Material.fileRef` nc:files path to its raw bytes, mirroring
     * `CoursePackageImportService::resolveFileRef()`'s write-side resolution.
     * Best-effort: an unresolvable reference (already-deleted file, empty
     * fileRef for a `link`-kind Material) returns null rather than failing
     * the whole export.
     *
     * @param string $fileRef       The nc:files path stored on the Material.
     * @param string $exportingUser NC user id whose home folder the path is resolved under.
     *
     * @return string|null The raw file bytes, or null when unresolvable.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    private function resolveFileBytes(string $fileRef, string $exportingUser): ?string
    {
        if ($fileRef === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($exportingUser);
            $node       = $userFolder->get(ltrim($fileRef, '/'));
            if (($node instanceof File) === false) {
                return null;
            }

            return $node->getContent();
        } catch (NotFoundException $e) {
            $this->logger->warning(
                '[CoursePackageExportService] Could not resolve Material fileRef "{ref}" for export — skipping bytes.',
                ['ref' => $fileRef]
            );
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveFileBytes()

    /**
     * Query a schema via `ObjectService::findAll()` and normalise every row to a plain array.
     *
     * @param string               $schema  Schema slug.
     * @param array<string, mixed> $filters Equality filters.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function findAllArrays(string $schema, array $filters): array
    {
        $rows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => $filters,
            ]
        );

        return array_map(fn ($row): array => $this->toArray(object: $row), $rows);
    }//end findAllArrays()

    /**
     * Normalise an ObjectService result (array or ObjectEntity-like) to a plain array.
     *
     * @param mixed $object The result row.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/course-package-import-export/design.md#data-model
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            return (array) $object->jsonSerialize();
        }

        return [];
    }//end toArray()

    /**
     * Build an in-memory ZIP archive from named string content entries.
     *
     * @param array<string,string> $files Map of filename => content.
     *
     * @return string Raw ZIP bytes.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    private function buildZip(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_course_export_');
        if ($tmpFile === false) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            return '';
        }

        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }

        $zip->close();

        $zipContent = (string) file_get_contents($tmpFile);
        unlink($tmpFile);

        return $zipContent;
    }//end buildZip()
}//end class
