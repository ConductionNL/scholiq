<?php

/**
 * Scholiq MoodleBackupParser unit tests.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#33-add-ocascholiqservicemoodlebackupparser-spdx-walks-an-extracted-mbzs-moodle_backupxml--per-sectionmodule-xml-classifies-each-module-resourcepageurlquizassignforumwikiglossaryother-and-returns-the-same-resource-descriptor-shape-commoncartridgeparser-returns
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\MbzExtractor;
use OCA\Scholiq\Service\MoodleBackupParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for MoodleBackupParser.
 */
class MoodleBackupParserTest extends TestCase
{

    private const FIXTURE = __DIR__.'/../../fixtures/course-packages/minimal-moodle.mbz';

    private string $tmpDir;

    /**
     * Extract the fixture `.mbz` before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/scholiq_test_moodle_'.bin2hex(random_bytes(6));
        (new MbzExtractor())->extract(self::FIXTURE, $this->tmpDir);
    }//end setUp()

    /**
     * Remove the extracted fixture after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }//end tearDown()

    /**
     * The section/module structure is parsed against the fixture `.mbz`.
     *
     * @return void
     */
    public function testParseManifestParsesSectionsAndActivities(): void
    {
        $result = (new MoodleBackupParser())->parseManifest($this->tmpDir);

        self::assertCount(1, $result['sectionNodes']);
        self::assertSame('2', $result['sectionNodes'][0]['identifier']);
        self::assertSame('Week 1', $result['sectionNodes'][0]['title']);

        $byModuleId = [];
        foreach ($result['activities'] as $activity) {
            $byModuleId[$activity['identifier']] = $activity;
        }

        self::assertSame('resource', $byModuleId['5']['classification']);
        self::assertSame('quiz', $byModuleId['6']['classification']);
        self::assertSame('forum', $byModuleId['7']['classification']);
        self::assertSame('url', $byModuleId['8']['classification']);
        self::assertSame('assign', $byModuleId['9']['classification']);
    }//end testParseManifestParsesSectionsAndActivities()

    /**
     * Unsupported module types (forum/wiki/glossary) classify as their own
     * name, never silently skipped from the activity list.
     *
     * @return void
     */
    public function testUnsupportedModuleTypesAreClassifiedNotSkipped(): void
    {
        $result     = (new MoodleBackupParser())->parseManifest($this->tmpDir);
        $identifiers = array_column($result['activities'], 'identifier');

        self::assertContains('7', $identifiers, 'The forum activity must still appear in the activity list.');
    }//end testUnsupportedModuleTypesAreClassifiedNotSkipped()

    /**
     * A missing `moodle_backup.xml` throws, so the orchestrator can report `failed`.
     *
     * @return void
     */
    public function testMissingManifestThrows(): void
    {
        $emptyDir = sys_get_temp_dir().'/scholiq_test_moodle_empty_'.bin2hex(random_bytes(6));
        mkdir($emptyDir, 0700, true);

        $this->expectException(RuntimeException::class);

        try {
            (new MoodleBackupParser())->parseManifest($emptyDir);
        } finally {
            rmdir($emptyDir);
        }
    }//end testMissingManifestThrows()

    /**
     * Recursively remove a directory (test cleanup helper).
     *
     * @param string $dir Absolute path.
     *
     * @return void
     */
    private function rrmdir(string $dir): void
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
                $this->rrmdir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }//end rrmdir()
}//end class
