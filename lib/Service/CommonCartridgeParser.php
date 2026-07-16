<?php

/**
 * Scholiq Common Cartridge Manifest Parser
 *
 * Walks an extracted IMS Common Cartridge 1.3 `imsmanifest.xml`: the
 * organization tree (folders + leaf items, order preserved) and the flat
 * resource list, classifying each `<resource type="...">` into the fidelity-
 * table categories `CoursePackageImportService` routes on. Does not create
 * any OpenRegister object itself — this is a pure parser, the orchestrator
 * owns object creation.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing XML from an
 * external interchange format cannot be expressed declaratively. Same
 * exception category as `QtiImportService`, at course-package scope.
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

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parses an extracted Common Cartridge 1.3 package's `imsmanifest.xml`.
 */
class CommonCartridgeParser
{

    /**
     * IMS Content Packaging namespace used by the manifest root/organizations/resources elements.
     */
    private const IMSCP_NS = 'http://www.imsglobal.org/xsd/imscp_v1p1';

    /**
     * Parse the manifest at `$dir/imsmanifest.xml`.
     *
     * @param string $dir Absolute path to the extracted CC package directory.
     *
     * @return array{organizationNodes: array<int, array<string, mixed>>, resources: array<int, array<string, mixed>>}
     *         `organizationNodes`: one row per organization folder/item, in manifest order, each
     *         `{identifier, title, order, parentIdentifier, isFolder, resourceIdentifier}`.
     *         `resources`: one row per `<resource>`, each
     *         `{identifier, type, classification, href, title}`.
     *
     * @throws \RuntimeException When `imsmanifest.xml` is missing or not parseable XML.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    public function parseManifest(string $dir): array
    {
        $manifestPath = $dir.'/imsmanifest.xml';
        if (file_exists($manifestPath) === false) {
            throw new RuntimeException("No imsmanifest.xml found in '{$dir}' — not a recognisable Common Cartridge package.");
        }

        $xml = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->load($manifestPath);
        libxml_clear_errors();
        if ($loaded === false) {
            throw new RuntimeException("Could not parse imsmanifest.xml in '{$dir}' as XML.");
        }

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('imscp', self::IMSCP_NS);

        $resources = $this->parseResources(xpath: $xpath);

        $organizationNodes = [];
        $orgNodes          = $xpath->query('//imscp:organizations/imscp:organization');
        if ($orgNodes === false || $orgNodes->length === 0) {
            $orgNodes = $xml->getElementsByTagName('organization');
        }

        $order = 0;
        foreach ($orgNodes as $orgNode) {
            if (($orgNode instanceof DOMElement) === false) {
                continue;
            }

            $this->walkItems(
                parent: $orgNode,
                parentIdentifier: null,
                order: $order,
                out: $organizationNodes,
            );
        }

        return [
            'organizationNodes' => $organizationNodes,
            'resources'         => $resources,
        ];
    }//end parseManifest()

    /**
     * Recursively walk `<item>` elements under an `<organization>` or parent `<item>`,
     * preserving manifest order and parent/child relationships.
     *
     * @param \DOMElement                      $parent           The `<organization>` or `<item>` element whose direct `<item>` children are walked.
     * @param string|null                      $parentIdentifier Identifier of the organization node this level nests under (null at the root).
     * @param int                              $order            Running order counter, incremented per sibling.
     * @param array<int, array<string, mixed>> $out              Accumulator the walked nodes are appended to.
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    private function walkItems(DOMElement $parent, ?string $parentIdentifier, int &$order, array &$out): void
    {
        foreach ($parent->childNodes as $child) {
            if (($child instanceof DOMElement) === false || $child->localName !== 'item') {
                continue;
            }

            $identifier    = $child->getAttribute('identifier');
            $identifierRef = $child->getAttribute('identifierref');
            $title         = $this->firstChildText(element: $child, tagName: 'title') ?? $identifier;

            $hasChildItems = false;
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof DOMElement && $grandChild->localName === 'item') {
                    $hasChildItems = true;
                    break;
                }
            }

            $resourceIdentifier = null;
            if ($identifierRef !== '') {
                $resourceIdentifier = $identifierRef;
            }

            $out[] = [
                'identifier'         => $identifier,
                'title'              => $title,
                'order'              => $order,
                'parentIdentifier'   => $parentIdentifier,
                'isFolder'           => $hasChildItems,
                'resourceIdentifier' => $resourceIdentifier,
            ];
            $order++;

            if ($hasChildItems === true) {
                $this->walkItems(parent: $child, parentIdentifier: $identifier, order: $order, out: $out);
            }
        }//end foreach
    }//end walkItems()

    /**
     * Parse the manifest's `<resources><resource>` list.
     *
     * @param \DOMXPath $xpath Manifest XPath, with the `imscp` namespace registered.
     *
     * @return array<int, array<string, mixed>> One row per resource: `{identifier, type, classification, href, title}`.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    private function parseResources(DOMXPath $xpath): array
    {
        $resourceNodes = $xpath->query('//imscp:resources/imscp:resource');
        if ($resourceNodes === false || $resourceNodes->length === 0) {
            $resourceNodes = $xpath->query('//resource');
        }

        $resources = [];
        if ($resourceNodes === false) {
            return $resources;
        }

        foreach ($resourceNodes as $node) {
            if (($node instanceof DOMElement) === false) {
                continue;
            }

            $identifier = $node->getAttribute('identifier');
            $type       = $node->getAttribute('type');
            $href       = $node->getAttribute('href');
            if ($href === '') {
                $fileNodes = $node->getElementsByTagName('file');
                $firstFile = $fileNodes->item(0);
                if ($firstFile instanceof DOMElement) {
                    $href = $firstFile->getAttribute('href');
                }
            }

            $title      = $identifier;
            $hrefOrNull = null;
            if ($href !== '') {
                $title      = basename($href);
                $hrefOrNull = $href;
            }

            $resources[] = [
                'identifier'     => $identifier,
                'type'           => $type,
                'classification' => $this->classify(type: $type),
                'href'           => $hrefOrNull,
                'title'          => $title,
            ];
        }//end foreach

        return $resources;
    }//end parseResources()

    /**
     * Classify a CC `<resource type="...">` string into a fidelity-table category.
     *
     * @param string $type The raw `type` attribute value.
     *
     * @return string One of `imsqti_item`, `imsqti_test`, `basiclti`, `weblink`, `webcontent`, `discussion`, `other`.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function classify(string $type): string
    {
        $lower = strtolower($type);

        if (str_contains($lower, 'imsqti_test') === true) {
            return 'imsqti_test';
        }

        if (str_contains($lower, 'imsqti') === true) {
            return 'imsqti_item';
        }

        if (str_contains($lower, 'basiclti') === true) {
            return 'basiclti';
        }

        if (str_contains($lower, 'imswl') === true) {
            return 'weblink';
        }

        if (str_contains($lower, 'imsdt') === true) {
            return 'discussion';
        }

        if (str_contains($lower, 'webcontent') === true || str_contains($lower, 'associatedcontent') === true) {
            return 'webcontent';
        }

        return 'other';
    }//end classify()

    /**
     * Read the trimmed text content of the first direct child with the given local tag name.
     *
     * @param \DOMElement $element The parent element.
     * @param string      $tagName The child tag's local name (namespace-agnostic).
     *
     * @return string|null Trimmed text content, or null when no such child exists.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    private function firstChildText(DOMElement $element, string $tagName): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tagName) {
                $text = trim((string) $child->textContent);
                if ($text !== '') {
                    return $text;
                }

                return null;
            }
        }

        return null;
    }//end firstChildText()
}//end class
