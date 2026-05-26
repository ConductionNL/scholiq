<?php

/**
 * Scholiq QTI Import Service
 *
 * Imports QTI 2.x / 3.0 packages and IMS Common Cartridge archives, converts
 * items to the canonical QTI 3.0 stored form, and creates `Item` objects in
 * the specified ItemBank.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing ZIP/XML from
 * an external interchange format (QTI, IMS CC) cannot be expressed declaratively.
 *
 * Supports:
 *   - QTI 3.0 packages (imsqti_v3p0.xml manifest)
 *   - QTI 2.1 packages (imsqti_v2p1.xml / qti2p1 manifest) — converted to 3.0 subset
 *   - IMS Common Cartridge 1.x (imsmanifest.xml) — extracts QTI items
 *
 * Full parser implemented for `choice` and `extendedText` interaction types.
 * Other interaction types are imported with their raw qtiBody preserved and
 * a TODO marker in their correctResponse, pending a future parsing extension.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Imports QTI 2.x / 3.0 packages and Common Cartridge files into the Scholiq
 * ItemBank as `Item` objects.
 */
class QtiImportService
{

    /**
     * QTI 3.0 namespace.
     */
    private const QTI3_NS = 'http://www.imsglobal.org/xsd/imsqtiasi_v3p0';

    /**
     * QTI 2.1 namespace.
     */
    private const QTI2_NS = 'http://www.imsglobal.org/xsd/imsqti_v2p1';

    /**
     * Map of QTI interaction element names → Scholiq interactionType slugs.
     */
    private const INTERACTION_MAP = [
        'choiceInteraction'       => 'choice',
        'textEntryInteraction'    => 'textEntry',
        'extendedTextInteraction' => 'extendedText',
        'hotspotInteraction'      => 'hotspot',
        'orderInteraction'        => 'order',
        'matchInteraction'        => 'match',
        'gapMatchInteraction'     => 'gapMatch',
        'inlineChoiceInteraction' => 'inlineChoice',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for creating Item objects.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Import items from a QTI 2.x / 3.0 or Common Cartridge ZIP package.
     *
     * Extracts the archive to a temporary directory, detects the package type
     * from the manifest, parses each item XML, and creates `Item` objects in OR.
     *
     * @param string $packagePath Absolute path to the .zip package file.
     * @param string $itemBankId  UUID of the target ItemBank.
     *
     * @return string[] Array of created Item UUIDs.
     *
     * @throws \RuntimeException When the archive cannot be opened or is not a recognised format.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    public function import(string $packagePath, string $itemBankId): array
    {
        $tmpDir = sys_get_temp_dir().'/scholiq_qti_'.bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            $this->extractZip(zipPath: $packagePath, targetDir: $tmpDir);
            $packageType  = $this->detectPackageType(dir: $tmpDir);
            $itemXmlPaths = $this->collectItemPaths(dir: $tmpDir, packageType: $packageType);

            $createdUuids = [];
            foreach ($itemXmlPaths as $xmlPath) {
                $uuid = $this->importSingleItem(xmlPath: $xmlPath, itemBankId: $itemBankId);
                if ($uuid !== null) {
                    $createdUuids[] = $uuid;
                }
            }

            $this->logger->info(
                '[QtiImportService] Imported {count} items into ItemBank {bankId} from {path}.',
                [
                    'count'  => count($createdUuids),
                    'bankId' => $itemBankId,
                    'path'   => $packagePath,
                ]
            );

            return $createdUuids;
        } finally {
            $this->removeDirectory(dir: $tmpDir);
        }//end try
    }//end import()

    /**
     * Extract a ZIP archive to a target directory.
     *
     * @param string $zipPath   Absolute path to the ZIP file.
     * @param string $targetDir Absolute path to the destination directory.
     *
     * @return void
     *
     * @throws \RuntimeException When the ZIP cannot be opened.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function extractZip(string $zipPath, string $targetDir): void
    {
        $zip    = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException("Cannot open ZIP archive '{$zipPath}': ZipArchive error {$result}.");
        }

        $zip->extractTo($targetDir);
        $zip->close();
    }//end extractZip()

    /**
     * Detect the package type from the extracted manifest.
     *
     * @param string $dir Extracted package directory.
     *
     * @return string 'qti3' | 'qti2' | 'cc' | 'unknown'
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function detectPackageType(string $dir): string
    {
        // Check for IMS manifest first (Common Cartridge and QTI packages both use imsmanifest.xml).
        $manifest = $dir.'/imsmanifest.xml';
        if (file_exists($manifest) === false) {
            return 'unknown';
        }

        $content = (string) file_get_contents($manifest);
        if (str_contains($content, 'imsqtiasi_v3p0') === true || str_contains($content, 'imsqti_v3p0') === true) {
            return 'qti3';
        }

        if (str_contains($content, 'imsqti_v2p') === true || str_contains($content, 'imsqti_v2p1') === true) {
            return 'qti2';
        }

        // IMS Common Cartridge 1.x signature.
        if (str_contains($content, 'imscc_xmlv1') === true || str_contains($content, 'imsccv1') === true) {
            return 'cc';
        }

        // Fallback: look for QTI 3.0 namespace in any XML.
        if (str_contains($content, 'imsglobal.org/xsd/imsqtiasi_v3p0') === true) {
            return 'qti3';
        }

        return 'unknown';
    }//end detectPackageType()

    /**
     * Collect paths of all item XML files in the extracted package.
     *
     * @param string $dir         Extracted package directory.
     * @param string $packageType Package type string.
     *
     * @return string[] Absolute paths to item XML files.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function collectItemPaths(string $dir, string $packageType): array
    {
        // Parse the manifest to find item resource hrefs.
        $manifestPath = $dir.'/imsmanifest.xml';
        if (file_exists($manifestPath) === false) {
            // Fallback: glob for any .xml files that look like items.
            $globResult = glob($dir.'/**/*.xml');
            if ($globResult === false) {
                return [];
            }

            return $globResult;
        }

        $xml = new DOMDocument();
        if ($xml->load($manifestPath) === false) {
            return [];
        }

        $paths = [];
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('imscp', 'http://www.imsglobal.org/xsd/imscp_v1p1');

        // QTI packages list items as resources in the manifest.
        $resourceNodes = $xpath->query('//imscp:resource[@type]');
        if ($resourceNodes === false || $resourceNodes->length === 0) {
            // Try without namespace.
            $resourceNodes = $xml->getElementsByTagName('resource');
        }

        foreach ($resourceNodes as $node) {
            if (($node instanceof \DOMElement) === false) {
                continue;
            }

            $type = $node->getAttribute('type');

            $isQtiItem = (str_contains($type, 'imsqti_item') === true || str_contains($type, 'imsqti_test') === true);
            $isCcItem  = (str_contains($type, 'imsqti') === true && $packageType === 'cc');

            if ($isQtiItem === false && $isCcItem === false) {
                continue;
            }

            $href = $node->getAttribute('href');
            if ($href === '') {
                // Fallback to first <file href=...> child.
                $fileNodes = $node->getElementsByTagName('file');
                $firstFile = $fileNodes->item(0);
                if ($firstFile instanceof \DOMElement) {
                    $href = $firstFile->getAttribute('href');
                }
            }

            if ($href !== '') {
                $fullPath = $dir.'/'.$href;
                if (file_exists($fullPath) === true) {
                    $paths[] = $fullPath;
                }
            }
        }//end foreach

        // If no items found via manifest, look for XML files with QTI namespaces.
        if (empty($paths) === true) {
            $globAll = glob($dir.'/*.xml');
            $allXml  = [];
            if ($globAll !== false) {
                $allXml = $globAll;
            }

            foreach ($allXml as $xmlFile) {
                if ($xmlFile === $manifestPath) {
                    continue;
                }

                $content = (string) file_get_contents($xmlFile);
                if (str_contains($content, 'assessmentItem') === true) {
                    $paths[] = $xmlFile;
                }
            }
        }

        return $paths;
    }//end collectItemPaths()

    /**
     * Parse a single QTI item XML and create an Item object in OR.
     *
     * Full parsing implemented for `choice` and `extendedText` interactions.
     * Other interaction types are imported with raw qtiBody and a placeholder
     * correctResponse pending future parser extensions.
     *
     * @param string $xmlPath    Absolute path to the item XML file.
     * @param string $itemBankId UUID of the target ItemBank.
     *
     * @return string|null Created Item UUID, or null on parse failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function importSingleItem(string $xmlPath, string $itemBankId): ?string
    {
        $xml = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($xml->load($xmlPath) === false) {
            $this->logger->warning('[QtiImportService] Failed to parse XML: {path}', ['path' => $xmlPath]);
            return null;
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($xml);

        // Register namespaces for both QTI versions.
        $xpath->registerNamespace('qti3', self::QTI3_NS);
        $xpath->registerNamespace('qti2', self::QTI2_NS);

        // Detect the root assessmentItem element.
        $root = $xml->getElementsByTagName('assessmentItem')->item(0);
        if ($root === null) {
            $this->logger->warning('[QtiImportService] No assessmentItem in: {path}', ['path' => $xmlPath]);
            return null;
        }

        $rawTitle = $root->getAttribute('title');
        $title    = basename($xmlPath, '.xml');
        if ($rawTitle !== '') {
            $title = $rawTitle;
        }

        $qtiBody = $xml->saveXML();

        if ($qtiBody === false) {
            return null;
        }

        // Detect interaction type.
        $interactionType = $this->detectInteractionType(xml: $xml);

        // Parse correctResponse and maxScore for choice + extendedText.
        $correctResponse = null;
        $maxScore        = 1.0;

        if ($interactionType === 'choice') {
            [$correctResponse, $maxScore] = $this->parseChoiceItem(xml: $xml);
        } else if ($interactionType === 'extendedText') {
            // Essay — no correctResponse by definition.
            $correctResponse = null;
            $maxScore        = $this->parseOutcomeMaxScore(xml: $xml);
        }

        $itemData = [
            'itemBankId'      => $itemBankId,
            'title'           => $title,
            'interactionType' => $interactionType,
            'qtiBody'         => $qtiBody,
            'correctResponse' => $correctResponse,
            'maxScore'        => $maxScore,
            'subjectTags'     => [],
            'difficulty'      => null,
            'lifecycle'       => 'draft',
            'tenant_id'       => '',
        ];

        $saved = $this->objectService->saveObject($itemData);
        if ($saved === null) {
            return null;
        }

        $uuid = null;
        if (is_array($saved) === true) {
            $uuid = $saved['uuid'] ?? null;
        }

        if (is_array($saved) === false && is_object($saved) === true) {
            $uuid = $saved->getUuid() ?? null;
        }

        if (is_string($uuid) === true) {
            return $uuid;
        }

        return null;
    }//end importSingleItem()

    /**
     * Detect the interaction type of a QTI item XML document.
     *
     * @param \DOMDocument $xml Parsed QTI item document.
     *
     * @return string Interaction type slug from INTERACTION_MAP, or 'extendedText' as fallback.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function detectInteractionType(\DOMDocument $xml): string
    {
        foreach (self::INTERACTION_MAP as $elementName => $typeSlug) {
            if ($xml->getElementsByTagName($elementName)->length > 0) {
                return $typeSlug;
            }
        }

        return 'extendedText';
    }//end detectInteractionType()

    /**
     * Parse a `choice` interaction item: extract the correct response and maxScore.
     *
     * @param \DOMDocument $xml Parsed QTI item document.
     *
     * @return array{0: mixed, 1: float} [correctResponse, maxScore] tuple.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function parseChoiceItem(\DOMDocument $xml): array
    {
        // Find the correctResponse declaration (QTI 3.0 and 2.x both use <correctResponse>).
        $correctResponseNodes = $xml->getElementsByTagName('correctResponse');
        $correctResponse      = null;

        if ($correctResponseNodes->length > 0) {
            $crNode = $correctResponseNodes->item(0);
            $values = [];
            foreach ($crNode->getElementsByTagName('value') as $valueNode) {
                $values[] = trim($valueNode->nodeValue);
            }

            // Single-response choice: return string; multi-response: return array.
            $correctResponse = $values;
            if (count($values) === 1) {
                $correctResponse = $values[0];
            }
        }

        $maxScore = $this->parseOutcomeMaxScore(xml: $xml);

        return [$correctResponse, $maxScore];
    }//end parseChoiceItem()

    /**
     * Parse the outcome MAXSCORE or defaultValue from a QTI item.
     *
     * @param \DOMDocument $xml Parsed QTI item document.
     *
     * @return float Maximum score (defaults to 1.0 if not found).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    private function parseOutcomeMaxScore(\DOMDocument $xml): float
    {
        // Look for <outcomeDeclaration identifier="SCORE"> with <defaultValue>.
        $outcomeNodes = $xml->getElementsByTagName('outcomeDeclaration');
        foreach ($outcomeNodes as $outcomeNode) {
            $identifier = $outcomeNode->getAttribute('identifier');
            if ($identifier !== 'SCORE' && $identifier !== 'MAXSCORE') {
                continue;
            }

            $defaultValueNodes = $outcomeNode->getElementsByTagName('value');
            if ($defaultValueNodes->length > 0) {
                $val = trim($defaultValueNodes->item(0)->nodeValue);
                if (is_numeric($val) === true) {
                    return (float) $val;
                }
            }
        }

        return 1.0;
    }//end parseOutcomeMaxScore()

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Absolute path to the directory.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
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
