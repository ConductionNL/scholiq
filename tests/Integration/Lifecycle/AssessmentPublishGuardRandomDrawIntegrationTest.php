<?php

/**
 * Integration test for AssessmentPublishGuard's random-draw item-source check.
 *
 * Requires a live OpenRegister database (installed Nextcloud + scholiq + openregister).
 * Run with:
 *   ./vendor/bin/phpunit --testsuite "Integration Tests"
 *
 * In CI environments without a running Nextcloud the test is skipped automatically.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @group integration
 *
 * NOTE: This test needs a live OpenRegister installation. It is decorated with
 * @group integration so standard CI PHPUnit runs (which only load unit suites)
 * skip it. To include it in a run, pass --group integration or target the
 * Integration Tests suite in phpunit.xml. Mirrors
 * XapiCompletionHandlerIntegrationTest.php's shape.
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-publishing-an-assessment-requires-a-resolvable-item-source
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Integration\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for AssessmentPublishGuard's extended item-source rule.
 *
 * Seeds an ItemBank + Items into the live OpenRegister database and asserts
 * that a random-draw Assessment's `publish` transition is blocked when the
 * bank has fewer distinct variant groups than drawCount, and succeeds once
 * enough matching published Items exist.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration\Lifecycle
 */
class AssessmentPublishGuardRandomDrawIntegrationTest extends TestCase
{

    /** @var ObjectService|null */
    private ?ObjectService $objectService = null;

    /** @var TransitionEngine|null */
    private ?TransitionEngine $transitionEngine = null;

    /** Cleanup: UUIDs of objects created by this test run. */
    private array $createdUuids = [];


    /**
     * Set up the test: verify OR is available, resolve services.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(\OC::class) === false || isset(\OC::$server) === false) {
            $this->markTestSkipped('Nextcloud not bootstrapped — set up a live NC + OR environment to run integration tests.');
        }

        if (class_exists(ObjectService::class) === false) {
            $this->markTestSkipped('openregister app is not installed — integration tests require OR.');
        }

        try {
            $this->objectService   = \OC::$server->get(ObjectService::class);
            $this->transitionEngine = \OC::$server->get(TransitionEngine::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Could not resolve OR services from DI container: '.$e->getMessage());
        }

    }//end setUp()


    /**
     * Tear down: remove objects created during the test to leave the DB clean.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->objectService !== null && empty($this->createdUuids) === false) {
            foreach (array_reverse($this->createdUuids) as ['schema' => $schema, 'uuid' => $uuid]) {
                try {
                    $this->objectService->deleteObject($uuid);
                } catch (\Throwable) {
                    // Best-effort cleanup; ignore failures.
                }
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Create an object via OR's ObjectService and record its UUID for cleanup.
     *
     * @param string $schema Schema name (e.g. 'Assessment').
     * @param array  $data   Object payload.
     *
     * @return array The created object as an associative array.
     */
    private function createObject(string $schema, array $data): array
    {
        try {
            $obj = $this->objectService->saveObject(register: 'scholiq', schema: $schema, object: $data);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $this->markTestSkipped('Scholiq register/schema not seeded: '.$e->getMessage());
        }

        $this->createdUuids[] = ['schema' => $schema, 'uuid' => $obj['uuid']];

        return $obj;

    }//end createObject()


    /**
     * A random-draw Assessment whose ItemBank has fewer distinct variant
     * groups than drawCount cannot publish.
     *
     * @return void
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-random-draw-assessment-with-an-insufficient-pool-cannot-publish
     */
    public function testInsufficientPoolBlocksPublish(): void
    {
        $bank = $this->createObject('ItemBank', ['name' => 'Integration Test Bank '.uniqid()]);

        // Only 6 published items — fewer than drawCount 10.
        for ($i = 0; $i < 6; $i++) {
            $this->createObject(
                'Item',
                [
                    'itemBankId'      => $bank['uuid'],
                    'title'           => 'Item '.$i,
                    'interactionType' => 'textEntry',
                    'qtiBody'         => '<assessmentItem/>',
                    'maxScore'        => 1,
                    'lifecycle'       => 'published',
                ]
            );
        }

        $assessment = $this->createObject(
            'Assessment',
            [
                'title'             => 'Integration Test Assessment '.uniqid(),
                'itemSelectionMode' => 'random-draw',
                'itemPoolConfig'    => ['itemBankId' => $bank['uuid'], 'drawCount' => 10],
            ]
        );

        $result = $this->transitionEngine->transition($assessment['uuid'], 'publish');

        self::assertFalse(
            ($result === true || (is_array($result) === true && ($result['success'] ?? false) === true)),
            'publish MUST be blocked when the pool has fewer than drawCount distinct variant groups'
        );

    }//end testInsufficientPoolBlocksPublish()


    /**
     * A random-draw Assessment whose ItemBank has at least drawCount
     * distinct published Items can publish.
     *
     * @return void
     */
    public function testSufficientPoolAllowsPublish(): void
    {
        $bank = $this->createObject('ItemBank', ['name' => 'Integration Test Bank '.uniqid()]);

        for ($i = 0; $i < 10; $i++) {
            $this->createObject(
                'Item',
                [
                    'itemBankId'      => $bank['uuid'],
                    'title'           => 'Item '.$i,
                    'interactionType' => 'textEntry',
                    'qtiBody'         => '<assessmentItem/>',
                    'maxScore'        => 1,
                    'lifecycle'       => 'published',
                ]
            );
        }

        $assessment = $this->createObject(
            'Assessment',
            [
                'title'             => 'Integration Test Assessment '.uniqid(),
                'itemSelectionMode' => 'random-draw',
                'itemPoolConfig'    => ['itemBankId' => $bank['uuid'], 'drawCount' => 5],
            ]
        );

        $result = $this->transitionEngine->transition($assessment['uuid'], 'publish');

        self::assertTrue(
            ($result === true || (is_array($result) === true && ($result['success'] ?? false) === true)),
            'publish MUST succeed when the pool has at least drawCount distinct published Items'
        );

    }//end testSufficientPoolAllowsPublish()
}//end class
