<?php

/**
 * Scholiq MoodleQuizQuestionMapper unit tests.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#34-add-ocascholiqservicemoodlequizquestionmapper-spdx-maps-moodles-quizquizxml-single-answer-multi-answer-short-answer-and-essay-question-types-to-item-objects-matching-qtiimportservices-item-shape-every-other-moodle-question-subtype-returns-a-dropped-marked-descriptor-rather-than-a-partially-correct-item
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\MoodleQuizQuestionMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MoodleQuizQuestionMapper.
 */
class MoodleQuizQuestionMapperTest extends TestCase
{

    private const QUESTIONS_XML = __DIR__.'/../../fixtures/course-packages/minimal-moodle-extracted-questions.xml';

    /**
     * Copy the fixture's question-bank XML out to a standalone path (the
     * mapper reads a plain file path, independent of the `.mbz` archive).
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $mbzExtractPath = sys_get_temp_dir().'/scholiq_test_quizmap_'.bin2hex(random_bytes(6));
        (new \OCA\Scholiq\Service\MbzExtractor())->extract(
            __DIR__.'/../../fixtures/course-packages/minimal-moodle.mbz',
            $mbzExtractPath
        );
        copy($mbzExtractPath.'/activities/quiz_6/questions.xml', self::QUESTIONS_XML);
        self::rrmdirStatic($mbzExtractPath);
    }//end setUpBeforeClass()

    /**
     * Recursively remove a directory (static test cleanup helper).
     *
     * @param string $dir Absolute path.
     *
     * @return void
     */
    private static function rrmdirStatic(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) === true) {
                self::rrmdirStatic($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }//end rrmdirStatic()

    /**
     * Remove the standalone fixture copy after all tests.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        @unlink(self::QUESTIONS_XML);
    }//end tearDownAfterClass()

    /**
     * Each of the four supported Moodle question subtypes produces a correct
     * `Item`-shaped data array.
     *
     * @return void
     */
    public function testSupportedSubtypesProduceCorrectItems(): void
    {
        $mapper = new MoodleQuizQuestionMapper();
        $rows   = $mapper->mapQuestions(self::QUESTIONS_XML, 'bank-1', 'tenant-1');

        self::assertCount(5, $rows, 'Fixture has 5 questions: 4 supported + 1 unsupported.');

        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[$row['title']] = $row;
        }

        // Single-answer multichoice.
        $single = $byTitle['Single answer question'];
        self::assertSame('imported', $single['outcome']);
        self::assertSame('choice', $single['itemData']['interactionType']);
        self::assertSame('4', $single['itemData']['correctResponse']);
        self::assertSame(1.0, $single['itemData']['maxScore']);
        self::assertSame('bank-1', $single['itemData']['itemBankId']);
        self::assertSame('tenant-1', $single['itemData']['tenant_id']);

        // Multi-answer multichoice.
        $multi = $byTitle['Multi answer question'];
        self::assertSame('imported', $multi['outcome']);
        self::assertSame('choice', $multi['itemData']['interactionType']);
        self::assertSame(['2', '3'], $multi['itemData']['correctResponse']);

        // Short answer.
        $short = $byTitle['Short answer question'];
        self::assertSame('imported', $short['outcome']);
        self::assertSame('textEntry', $short['itemData']['interactionType']);
        self::assertSame('Paris', $short['itemData']['correctResponse']);

        // Essay.
        $essay = $byTitle['Essay question'];
        self::assertSame('imported', $essay['outcome']);
        self::assertSame('extendedText', $essay['itemData']['interactionType']);
        self::assertNull($essay['itemData']['correctResponse']);
    }//end testSupportedSubtypesProduceCorrectItems()

    /**
     * An unsupported subtype (drag-and-drop) produces a `dropped` descriptor,
     * never a partially-correct `Item`.
     *
     * @return void
     */
    public function testUnsupportedSubtypeProducesDroppedDescriptorNotAMalformedItem(): void
    {
        $mapper = new MoodleQuizQuestionMapper();
        $rows   = $mapper->mapQuestions(self::QUESTIONS_XML, 'bank-1', 'tenant-1');

        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[$row['title']] = $row;
        }

        $dragDrop = $byTitle['Drag and drop question'];
        self::assertSame('dropped', $dragDrop['outcome']);
        self::assertNull($dragDrop['itemData']);
        self::assertSame('ddwtos', $dragDrop['moodleQuestionType']);
        self::assertStringContainsString('ddwtos', $dragDrop['reason']);
    }//end testUnsupportedSubtypeProducesDroppedDescriptorNotAMalformedItem()
}//end class
