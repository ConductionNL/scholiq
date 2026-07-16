<?php

/**
 * Scholiq Moodle Quiz Question Mapper
 *
 * Maps a Moodle quiz activity's question-bank XML to the same `Item` data
 * shape `QtiImportService::importSingleItem()` builds, for the four most
 * common Moodle question subtypes (single-answer / multi-answer multiple
 * choice, short-answer, essay). Moodle's own question-bank format is not QTI
 * — this is a deliberately narrower, separate mapper, not an extension of
 * `QtiImportService` (design.md Non-Goals: full Moodle quiz-question-type
 * parity is out of scope for this change). Every other Moodle question
 * subtype (drag-and-drop, calculated, cloze, random, …) returns a `dropped`
 * descriptor naming the unsupported type — never a partially-correct `Item`.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing an external
 * interchange XML format cannot be expressed declaratively.
 *
 * This mapper does not persist anything itself — it returns plain data the
 * orchestrator (`CoursePackageImportService`) saves via OpenRegister's
 * `ObjectService`, exactly as `QtiImportService` does for QTI items.
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
 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DOMDocument;
use DOMElement;

/**
 * Maps Moodle quiz question-bank XML entries to scholiq `Item` data or a
 * `dropped` descriptor.
 */
class MoodleQuizQuestionMapper
{

    /**
     * Moodle question `type` attribute values this mapper fully supports.
     */
    private const SUPPORTED_TYPES = ['multichoice', 'shortanswer', 'essay'];

    /**
     * Map every `<question>` in a Moodle question-bank XML file to either
     * an `Item`-shaped data array (ready for `ObjectService::saveObject()`)
     * or a `dropped` descriptor.
     *
     * @param string $questionsXmlPath Absolute path to the quiz activity's question-bank XML.
     * @param string $itemBankId       UUID of the target ItemBank.
     * @param string $tenantId         Tenant UUID to stamp on created Items.
     *
     * @return array<int, array{outcome: string, title: string, moodleQuestionType: string, itemData: array<string, mixed>|null, reason: string|null}>
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    public function mapQuestions(string $questionsXmlPath, string $itemBankId, string $tenantId=''): array
    {
        if (file_exists($questionsXmlPath) === false) {
            return [];
        }

        $xml = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->load($questionsXmlPath);
        libxml_clear_errors();
        if ($loaded === false) {
            return [];
        }

        $results = [];
        foreach ($xml->getElementsByTagName('question') as $questionEl) {
            if (($questionEl instanceof DOMElement) === false) {
                continue;
            }

            $results[] = $this->mapOne(questionEl: $questionEl, itemBankId: $itemBankId, tenantId: $tenantId);
        }

        return $results;
    }//end mapQuestions()

    /**
     * Map a single `<question>` element.
     *
     * @param \DOMElement $questionEl The `<question type="...">` element.
     * @param string      $itemBankId UUID of the target ItemBank.
     * @param string      $tenantId   Tenant UUID to stamp on the created Item.
     *
     * @return array{outcome: string, title: string, moodleQuestionType: string, itemData: array<string, mixed>|null, reason: string|null}
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function mapOne(DOMElement $questionEl, string $itemBankId, string $tenantId): array
    {
        $type  = $questionEl->getAttribute('type');
        $title = $this->childText(element: $questionEl, tagName: 'name') ?? 'Untitled question';

        if (in_array($type, self::SUPPORTED_TYPES, strict: true) === false) {
            return [
                'outcome'            => 'dropped',
                'title'              => $title,
                'moodleQuestionType' => $type,
                'itemData'           => null,
                'reason'             => "Moodle question type not supported: {$type}.",
            ];
        }

        $questionText = $this->childText(element: $questionEl, tagName: 'questiontext') ?? '';
        $maxScore     = (float) ($this->childText(element: $questionEl, tagName: 'defaultgrade') ?? '1.0');

        $answers = [];
        foreach ($questionEl->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'answer') {
                $fraction  = (float) $child->getAttribute('fraction');
                $text      = trim((string) $this->childText(element: $child, tagName: 'text'));
                $answers[] = ['fraction' => $fraction, 'text' => $text];
            }
        }

        [$interactionType, $correctResponse] = $this->deriveInteraction(type: $type, questionEl: $questionEl, answers: $answers);

        $qtiBody = $this->buildQtiLikeBody(
            title: $title,
            questionText: $questionText,
            interactionType: $interactionType,
            answers: $answers,
        );

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
            'tenant_id'       => $tenantId,
        ];

        return [
            'outcome'            => 'imported',
            'title'              => $title,
            'moodleQuestionType' => $type,
            'itemData'           => $itemData,
            'reason'             => null,
        ];
    }//end mapOne()

    /**
     * Derive the scholiq `interactionType` + `correctResponse` for a supported Moodle question type.
     *
     * @param string                                           $type       Moodle question `type` attribute.
     * @param \DOMElement                                      $questionEl The `<question>` element (for `<single>`).
     * @param array<int, array{fraction: float, text: string}> $answers    Parsed `<answer>` rows.
     *
     * @return array{0: string, 1: mixed} [interactionType, correctResponse].
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function deriveInteraction(string $type, DOMElement $questionEl, array $answers): array
    {
        if ($type === 'essay') {
            return ['extendedText', null];
        }

        if ($type === 'shortanswer') {
            $correct = null;
            foreach ($answers as $answer) {
                if ($answer['fraction'] >= 100.0) {
                    $correct = $answer['text'];
                    break;
                }
            }

            return ['textEntry', $correct];
        }

        // Multichoice: <single>true</single> => single-answer choice, false => multi-answer choice.
        $single       = ($this->childText(element: $questionEl, tagName: 'single') ?? 'true') !== 'false';
        $correctTexts = array_values(
            array_map(
                static fn (array $a): string => $a['text'],
                array_filter($answers, static fn (array $a): bool => $a['fraction'] > 0.0)
            )
        );

        if ($single === true) {
            return ['choice', $correctTexts[0] ?? null];
        }

        return ['choice', $correctTexts];
    }//end deriveInteraction()

    /**
     * Build a minimal QTI-shaped XML body for a Moodle-derived Item, so `qtiBody`
     * (required on every `Item`) is always populated even though the source was
     * not QTI. Marked `degraded` at the report level by the orchestrator, not here.
     *
     * @param string                                           $title           Question title.
     * @param string                                           $questionText    Question stem text.
     * @param string                                           $interactionType Scholiq interactionType slug.
     * @param array<int, array{fraction: float, text: string}> $answers         Parsed answers, for `choice` rendering.
     *
     * @return string A QTI-3.0-namespaced XML string wrapping the Moodle question content.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
     */
    private function buildQtiLikeBody(string $title, string $questionText, string $interactionType, array $answers): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES);
        $escapedText  = htmlspecialchars(strip_tags($questionText), ENT_XML1 | ENT_QUOTES);

        $choiceXml = '';
        if ($interactionType === 'choice') {
            foreach ($answers as $idx => $answer) {
                $identifier    = 'A'.$idx;
                $escapedAnswer = htmlspecialchars($answer['text'], ENT_XML1 | ENT_QUOTES);
                $choiceXml    .= '<simpleChoice identifier="'.$identifier.'">'.$escapedAnswer.'</simpleChoice>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" '
            .'identifier="moodle-'.md5($title).'" title="'.$escapedTitle.'" adaptive="false" timeDependent="false">'
            .'<itemBody><p>'.$escapedText.'</p>'.$choiceXml.'</itemBody>'
            .'</assessmentItem>';
    }//end buildQtiLikeBody()

    /**
     * Read the trimmed text content of the first direct child with the given tag name.
     *
     * @param \DOMElement $element The parent element.
     * @param string      $tagName The child tag's name.
     *
     * @return string|null Trimmed text content, or null when no such child exists / it is empty.
     *
     * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
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
