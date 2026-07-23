<?php

/**
 * Scholiq Moodle Backup Manifest Parser
 *
 * Walks an extracted Moodle backup (`.mbz`)'s `moodle_backup.xml`: the
 * section structure and the flat activity (module) list, classifying each
 * module (`resource`/`page`/`url`/`quiz`/`assign`/`forum`/`wiki`/`glossary`/
 * other) the way `CommonCartridgeParser` classifies CC resources — same
 * resource-descriptor shape, so `CoursePackageImportService` routes both
 * formats through one orchestration loop. Does not create any OpenRegister
 * object itself — this is a pure parser.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing XML from an
 * external interchange format cannot be expressed declaratively.
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
use RuntimeException;

/**
 * Parses an extracted Moodle backup package's `moodle_backup.xml`.
 */
class MoodleBackupParser
{

    /**
     * Moodle module names this importer materialises as scholiq objects (not `other`/dropped).
     * `quiz` and `assign` are handled specially by the orchestrator (question mapping / Assignment
     * creation respectively); `resource`/`page`/`url` become Material.
     */
    private const SUPPORTED_MODULE_TYPES = ['resource', 'page', 'url', 'quiz', 'assign'];

    /**
     * Parse the manifest at `$dir/moodle_backup.xml`.
     *
     * @param string $dir Absolute path to the extracted Moodle backup directory.
     *
     * @return array{sectionNodes: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>}
     *         `sectionNodes`: one row per section, in manifest order, each `{identifier, title, order}`.
     *         `activities`: one row per activity/module, each
     *         `{identifier, sectionIdentifier, moduleType, classification, title, directory, order}`.
     *
     * @throws \RuntimeException When `moodle_backup.xml` is missing or not parseable XML.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    public function parseManifest(string $dir): array
    {
        $manifestPath = $dir.'/moodle_backup.xml';
        if (file_exists($manifestPath) === false) {
            throw new RuntimeException("No moodle_backup.xml found in '{$dir}' — not a recognisable Moodle backup archive.");
        }

        $xml = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->load($manifestPath);
        libxml_clear_errors();
        if ($loaded === false) {
            throw new RuntimeException("Could not parse moodle_backup.xml in '{$dir}' as XML.");
        }

        $sectionNodes = [];
        $order        = 0;
        foreach ($xml->getElementsByTagName('section') as $sectionEl) {
            if (($sectionEl instanceof DOMElement) === false) {
                continue;
            }

            // Only <section> elements directly under <sections> carry a <sectionid>; skip any
            // unrelated same-named elements elsewhere in the document.
            $sectionId = $this->childText(element: $sectionEl, tagName: 'sectionid');
            if ($sectionId === null) {
                continue;
            }

            $sectionNodes[] = [
                'identifier' => $sectionId,
                'title'      => $this->childText(element: $sectionEl, tagName: 'title') ?? $sectionId,
                'order'      => $order,
            ];
            $order++;
        }

        $activities = [];
        $order      = 0;
        foreach ($xml->getElementsByTagName('activity') as $activityEl) {
            if (($activityEl instanceof DOMElement) === false) {
                continue;
            }

            $moduleId = $this->childText(element: $activityEl, tagName: 'moduleid');
            if ($moduleId === null) {
                continue;
            }

            $moduleType = $this->childText(element: $activityEl, tagName: 'modulename') ?? 'other';

            $activities[] = [
                'identifier'        => $moduleId,
                'sectionIdentifier' => $this->childText(element: $activityEl, tagName: 'sectionid'),
                'moduleType'        => $moduleType,
                'classification'    => $this->classify(moduleType: $moduleType),
                'title'             => $this->childText(element: $activityEl, tagName: 'title') ?? $moduleType,
                'directory'         => $this->childText(element: $activityEl, tagName: 'directory'),
                'order'             => $order,
            ];
            $order++;
        }//end foreach

        return [
            'sectionNodes' => $sectionNodes,
            'activities'   => $activities,
        ];
    }//end parseManifest()

    /**
     * Classify a Moodle module name into a fidelity-table category.
     *
     * @param string $moduleType The raw `<modulename>` value.
     *
     * @return string One of `resource`, `page`, `url`, `quiz`, `assign`, `forum`, `wiki`, `glossary`, `other`.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function classify(string $moduleType): string
    {
        $lower = strtolower($moduleType);

        if (in_array($lower, self::SUPPORTED_MODULE_TYPES, strict: true) === true) {
            return $lower;
        }

        if (in_array($lower, ['forum', 'wiki', 'glossary'], strict: true) === true) {
            return $lower;
        }

        // Cmi5/SCORM embedded in a Moodle backup is reported dropped by the orchestrator
        // with the ADR-002 reason, same as an embedded cmi5/SCORM resource in a CC package.
        if (in_array($lower, ['scorm', 'lti', 'lesson'], strict: true) === true) {
            return $lower;
        }

        return 'other';
    }//end classify()

    /**
     * Read the trimmed text content of the first direct child with the given tag name.
     *
     * @param \DOMElement $element The parent element.
     * @param string      $tagName The child tag's name.
     *
     * @return string|null Trimmed text content, or null when no such child exists / it is empty.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    private function childText(DOMElement $element, string $tagName): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $tagName) {
                $text = trim((string) $child->textContent);
                if ($text !== '') {
                    return $text;
                }

                return null;
            }
        }

        return null;
    }//end childText()
}//end class
