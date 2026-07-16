<?php

/**
 * Scholiq QTI Export Service
 *
 * Exports an `ItemBank` and its `Item`s as a QTI 3.0 package (a ZIP
 * containing `imsmanifest.xml` + one `assessmentItem` XML per Item),
 * completing the "Items use QTI 3.0 as canonical form" requirement's
 * import-only coverage into a round-trip.
 *
 * Every `Item.qtiBody` already holds verbatim, valid QTI 3.0 XML — written by
 * both `QtiImportService` on import and `ItemAuthorView` on manual authoring
 * — so this exporter wraps the stored `qtiBody` directly rather than
 * re-deriving it from `interactionType`/`correctResponse`. Export fidelity is
 * therefore unaffected by the pre-existing import-side interaction-type
 * parsing gap documented in `QtiImportService`'s own class docblock.
 *
 * Legitimate PHP per ADR-031 §"Document/ZIP generation": streaming a ZIP
 * package cannot be expressed declaratively. Mirrors
 * `AuditPackExportController`'s in-memory-ZIP pattern.
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
 * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;
use ZipArchive;

/**
 * Builds a QTI 3.0 export package for an `ItemBank`.
 */
class QtiExportService
{

    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OR object service for reading ItemBank/Item objects.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Export an ItemBank's Items as a QTI 3.0 package ZIP.
     *
     * @param string $itemBankId UUID of the ItemBank to export.
     *
     * @return string Raw ZIP bytes.
     *
     * @throws \RuntimeException When the ItemBank does not exist.
     *
     * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#scenario-exporting-an-itembank-produces-a-valid-qti-30-package
     */
    public function export(string $itemBankId): string
    {
        $bank = $this->objectService->find(id: $itemBankId, register: self::SCHOLIQ_REGISTER, schema: 'item-bank');
        if ($bank === null) {
            throw new RuntimeException("ItemBank '{$itemBankId}' not found.");
        }

        $bankData = $this->toArray(object: $bank);
        $itemIds  = (array) ($bankData['itemIds'] ?? []);

        $items = [];
        foreach ($itemIds as $itemId) {
            if (is_string($itemId) === false) {
                continue;
            }

            $item = $this->objectService->find(id: $itemId, register: self::SCHOLIQ_REGISTER, schema: 'item');
            if ($item === null) {
                continue;
            }

            $items[] = $this->toArray(object: $item);
        }

        return $this->buildPackage(bankData: $bankData, items: $items);
    }//end export()

    /**
     * Build the QTI 3.0 package ZIP: `imsmanifest.xml` + one item XML per Item,
     * each holding that Item's stored `qtiBody` verbatim.
     *
     * @param array<string, mixed>             $bankData The ItemBank's own data.
     * @param array<int, array<string, mixed>> $items    The bank's resolved Items.
     *
     * @return string Raw ZIP bytes.
     *
     * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#scenario-export-fidelity-is-not-limited-by-the-import-side-parsing-gap
     */
    private function buildPackage(array $bankData, array $items): string
    {
        $resourceEntries = '';
        $files           = [];

        foreach ($items as $idx => $item) {
            $itemFilename = 'item-'.($idx + 1).'.xml';
            $qtiBody      = (string) ($item['qtiBody'] ?? '');
            $files[$itemFilename] = $qtiBody;

            $identifier       = 'ITEM-'.($item['id'] ?? $item['uuid'] ?? ($idx + 1));
            $resourceEntries .= '<resource identifier="'.htmlspecialchars((string) $identifier, ENT_XML1 | ENT_QUOTES)
                .'" type="imsqti_item_xmlv3p0" href="'.$itemFilename.'">'
                .'<file href="'.$itemFilename.'"/></resource>';
        }

        $bankTitle = htmlspecialchars((string) ($bankData['name'] ?? 'Item bank'), ENT_XML1 | ENT_QUOTES);

        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" '
            .'xmlns:imsqti="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" identifier="MANIFEST-1">'
            .'<metadata><schema>QTIv3.0 Package</schema><title>'.$bankTitle.'</title></metadata>'
            .'<organizations/>'
            .'<resources>'.$resourceEntries.'</resources>'
            .'</manifest>';

        $files['imsmanifest.xml'] = $manifest;

        return $this->buildZip(files: $files);
    }//end buildPackage()

    /**
     * Build an in-memory ZIP archive from named string content entries.
     *
     * @param array<string,string> $files Map of filename => content.
     *
     * @return string Raw ZIP bytes.
     *
     * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package
     */
    private function buildZip(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_qti_export_');
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

    /**
     * Normalise an ObjectService result (array or ObjectEntity-like) to a plain array.
     *
     * @param mixed $object The result row.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package
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
}//end class
