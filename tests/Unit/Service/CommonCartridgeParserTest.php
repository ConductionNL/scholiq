<?php

/**
 * Scholiq CommonCartridgeParser unit tests.
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
 * @spec openspec/changes/course-package-import-export/tasks.md#32-add-ocascholiqservicecommoncartridgeparser-spdx-walks-an-extracted-cc-13-imsmanifestxml-classifies-each-resource-type--organizationwebcontentweblinkimsqti_itemimsqti_testbasiclticourseother-and-returns-a-resource-descriptor-list-the-orchestrator-consumes
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\CommonCartridgeParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Tests for CommonCartridgeParser.
 */
class CommonCartridgeParserTest extends TestCase
{

    private const FIXTURE = __DIR__.'/../../fixtures/course-packages/minimal-cc.imscc';

    private string $tmpDir;

    /**
     * Extract the fixture package before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/scholiq_test_cc_'.bin2hex(random_bytes(6));
        $zip          = new ZipArchive();
        $zip->open(self::FIXTURE);
        $zip->extractTo($this->tmpDir);
        $zip->close();
    }//end setUp()

    /**
     * Remove the extracted fixture after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir.'/*') as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }//end tearDown()

    /**
     * The organization tree is walked in manifest order, folders and leaf
     * items are distinguished, and parent/child relationships are preserved.
     *
     * @return void
     */
    public function testParseManifestPreservesOrganizationTreeOrder(): void
    {
        $result = (new CommonCartridgeParser())->parseManifest($this->tmpDir);
        $nodes  = $result['organizationNodes'];

        self::assertSame(['I1', 'I2', 'I3', 'I4', 'I5', 'I6'], array_column($nodes, 'identifier'));

        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node['identifier']] = $node;
        }

        self::assertSame('Welcome page', $byId['I1']['title']);
        self::assertFalse($byId['I1']['isFolder']);
        self::assertNull($byId['I1']['parentIdentifier']);
        self::assertSame('RES_WEB', $byId['I1']['resourceIdentifier']);

        self::assertTrue($byId['I2']['isFolder']);
        self::assertSame('Module A', $byId['I2']['title']);

        self::assertSame('I2', $byId['I3']['parentIdentifier']);
        self::assertSame('RES_QTI', $byId['I3']['resourceIdentifier']);
    }//end testParseManifestPreservesOrganizationTreeOrder()

    /**
     * Every fidelity-table resource type is classified correctly against the
     * fixture manifest.
     *
     * @return void
     */
    public function testParseManifestClassifiesEachResourceType(): void
    {
        $result    = (new CommonCartridgeParser())->parseManifest($this->tmpDir);
        $resources = [];
        foreach ($result['resources'] as $resource) {
            $resources[$resource['identifier']] = $resource;
        }

        self::assertSame('webcontent', $resources['RES_WEB']['classification']);
        self::assertSame('page1.html', $resources['RES_WEB']['href']);

        self::assertSame('imsqti_item', $resources['RES_QTI']['classification']);
        self::assertSame('basiclti', $resources['RES_LTI']['classification']);
        self::assertSame('weblink', $resources['RES_WL']['classification']);
        self::assertSame('discussion', $resources['RES_FORUM']['classification']);
    }//end testParseManifestClassifiesEachResourceType()

    /**
     * An unrecognised resource type classifies as `other`, never silently omitted.
     *
     * @return void
     */
    public function testUnrecognisedResourceTypeClassifiesAsOther(): void
    {
        $parser   = new CommonCartridgeParser();
        $reflected = new \ReflectionClass($parser);
        $method    = $reflected->getMethod('classify');
        $method->setAccessible(true);

        self::assertSame('other', $method->invoke($parser, 'some_vendor_specific_type'));
    }//end testUnrecognisedResourceTypeClassifiesAsOther()

    /**
     * A missing `imsmanifest.xml` throws, so the orchestrator can report `failed`.
     *
     * @return void
     */
    public function testMissingManifestThrows(): void
    {
        $emptyDir = sys_get_temp_dir().'/scholiq_test_cc_empty_'.bin2hex(random_bytes(6));
        mkdir($emptyDir, 0700, true);

        $this->expectException(RuntimeException::class);

        try {
            (new CommonCartridgeParser())->parseManifest($emptyDir);
        } finally {
            rmdir($emptyDir);
        }
    }//end testMissingManifestThrows()
}//end class
