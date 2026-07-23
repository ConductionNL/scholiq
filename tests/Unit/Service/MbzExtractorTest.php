<?php

/**
 * Scholiq MbzExtractor unit tests.
 *
 * Covers the fixture-package extraction happy path and the two security
 * guards ported from `QtiImportService::extractZip()` (fixes for #207):
 * tar-slip path-traversal rejection and the oversized-entry cap.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#31-add-ocascholiqservicembzextractor-spdx-adr-031-external-format-import-exception-extracts-a-gzipped-tar-mbz-archive-via-phardata-porting-qtiimportserviceextractzips-zip-slip--decompression-bomb--per-file-size-guards-to-the-tar-extraction-path-fixes-for-207-ported-not-re-invented
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\MbzExtractor;
use PHPUnit\Framework\TestCase;
use PharData;
use RuntimeException;

/**
 * Tests for MbzExtractor.
 */
class MbzExtractorTest extends TestCase
{

    private const FIXTURE           = __DIR__.'/../../fixtures/course-packages/minimal-moodle.mbz';
    private const TRAVERSAL_FIXTURE = __DIR__.'/../../fixtures/course-packages/malicious-path-traversal.tar.gz';

    /**
     * A valid `.mbz` extracts its full directory tree.
     *
     * @return void
     */
    public function testExtractValidMbzArchive(): void
    {
        $tmpDir = sys_get_temp_dir().'/scholiq_test_mbz_'.bin2hex(random_bytes(6));

        (new MbzExtractor())->extract(self::FIXTURE, $tmpDir);

        self::assertFileExists($tmpDir.'/moodle_backup.xml');
        self::assertFileExists($tmpDir.'/activities/quiz_6/questions.xml');
        self::assertFileExists($tmpDir.'/activities/resource_5/content.html');

        $this->rrmdir($tmpDir);
    }//end testExtractValidMbzArchive()

    /**
     * A tar entry crafted with a `../../` path-traversal name never results in a
     * file written outside the target directory. `PharData` itself already
     * excludes such entries from iteration entirely (verified empirically — it
     * never surfaces them, unlike `ZipArchive`, which does and requires the
     * app to defend itself); `MbzExtractor`'s own realpath-prefix check
     * (ported from `QtiImportService::extractZip()`'s zip-slip guard) is the
     * second, independent layer of defence for any entry that did make it
     * through. Either mechanism firing is an acceptable outcome — what must
     * never happen is a file landing outside `$tmpDir`.
     *
     * @return void
     */
    public function testExtractNeverWritesOutsideTargetDirectoryForATraversalEntry(): void
    {
        $tmpDir = sys_get_temp_dir().'/scholiq_test_mbz_slip_'.bin2hex(random_bytes(6));

        try {
            (new MbzExtractor())->extract(self::TRAVERSAL_FIXTURE, $tmpDir);
        } catch (RuntimeException $e) {
            // Also an acceptable outcome — MbzExtractor's own guard caught it.
            self::assertStringContainsString('tar-slip', $e->getMessage());
        }

        self::assertFileDoesNotExist('/etc/evil-payload.txt');
        $this->rrmdir($tmpDir);
    }//end testExtractNeverWritesOutsideTargetDirectoryForATraversalEntry()

    /**
     * An entry larger than the per-file cap (100 MB) is rejected before being written.
     *
     * @return void
     */
    public function testExtractRejectsOversizedEntry(): void
    {
        $oversizedTarGz = $this->buildOversizedTarGz();
        $tmpDir         = sys_get_temp_dir().'/scholiq_test_mbz_oversize_'.bin2hex(random_bytes(6));

        $this->expectException(RuntimeException::class);

        try {
            (new MbzExtractor())->extract($oversizedTarGz, $tmpDir);
        } finally {
            @unlink($oversizedTarGz);
            $this->rrmdir($tmpDir);
        }
    }//end testExtractRejectsOversizedEntry()

    /**
     * Build a gzipped tar containing one entry larger than the 100 MB per-file cap.
     *
     * @return string Absolute path to the built `.tar.gz` file.
     */
    private function buildOversizedTarGz(): string
    {
        // Phar::compress() reads the full entry into memory to gzip it; a 101 MB
        // fixture entry needs headroom beyond the default 128 M test memory_limit
        // purely to *build* the fixture (MbzExtractor's own extraction never loads
        // the oversized entry into memory — it rejects it by size before reading
        // any content, per the pre-flight pass in `extract()`).
        ini_set('memory_limit', '512M');

        $workDir = sys_get_temp_dir().'/scholiq_test_mbz_oversize_build_'.bin2hex(random_bytes(6));
        mkdir($workDir, 0700, true);

        // Sparse file: ftruncate reserves the size without writing real bytes,
        // so the fixture builds in milliseconds instead of allocating 101 MB.
        $bigFile = $workDir.'/big.bin';
        $handle  = fopen($bigFile, 'wb');
        ftruncate($handle, (101 * 1024 * 1024));
        fclose($handle);

        $tarPath = $workDir.'/oversized.tar';
        $phar    = new PharData($tarPath);
        $phar->addFile($bigFile, 'big.bin');
        $phar->compress(\Phar::GZ);
        unset($phar);

        $tarGzPath = $tarPath.'.gz';
        rename($tarGzPath, $workDir.'/oversized.tar.gz');

        return $workDir.'/oversized.tar.gz';
    }//end buildOversizedTarGz()

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
