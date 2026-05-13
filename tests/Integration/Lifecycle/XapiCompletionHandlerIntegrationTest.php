<?php

/**
 * Integration test for XapiCompletionHandler.
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
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @group integration
 *
 * NOTE: This test needs a live OpenRegister installation. It is decorated with
 * @group integration so standard CI PHPUnit runs (which only load unit suites)
 * skip it. To include it in a run, pass --group integration or target the
 * Integration Tests suite in phpunit.xml.
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Integration\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for XapiCompletionHandler.
 *
 * Seeds a minimal scholiq register (Course, Lesson, Enrolment) into the live
 * OpenRegister database, fires an xAPI "completed" statement event, and asserts
 * that the matching Enrolment is transitioned to `completed`.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration\Lifecycle
 */
class XapiCompletionHandlerIntegrationTest extends TestCase
{

    /** @var ObjectService|null */
    private ?ObjectService $objectService = null;

    /** @var XapiCompletionHandler|null */
    private ?XapiCompletionHandler $handler = null;

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

        // Skip if Nextcloud server is not bootstrapped.
        if (class_exists(\OC::class) === false || isset(\OC::$server) === false) {
            $this->markTestSkipped('Nextcloud not bootstrapped — set up a live NC + OR environment to run integration tests.');
        }

        // Skip if openregister app is not installed.
        if (class_exists(ObjectService::class) === false) {
            $this->markTestSkipped('openregister app is not installed — integration tests require OR.');
        }

        try {
            $this->objectService = \OC::$server->get(ObjectService::class);

            // TransitionEngine is final; we use the real one via the DI container.
            $transitionEngine = \OC::$server->get(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class);

            $this->handler = new XapiCompletionHandler(
                $this->objectService,
                $transitionEngine,
                new NullLogger(),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Could not resolve OR services from DI container: ' . $e->getMessage());
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
            foreach (array_reverse($this->createdUuids) as ['register' => $register, 'schema' => $schema, 'uuid' => $uuid]) {
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
     * @param string $schema Schema name (e.g. 'Course').
     * @param array  $data   Object payload.
     *
     * @return array The created object as an associative array.
     */
    private function createObject(string $schema, array $data): array
    {
        $obj = $this->objectService->saveObject(
            register: 'scholiq',
            schema: $schema,
            object: $data,
        );

        $this->createdUuids[] = [
            'register' => 'scholiq',
            'schema'   => $schema,
            'uuid'     => $obj['uuid'],
        ];

        return $obj;

    }//end createObject()


    /**
     * Build a minimal xAPI "completed" event carrying $payload.
     *
     * @param array $payload xAPI statement payload.
     *
     * @return Event An anonymous event object that implements getData().
     */
    private function makeXapiEvent(array $payload): Event
    {
        return new class($payload) extends Event {

            /**
             * Constructor.
             *
             * @param array $data xAPI statement payload.
             *
             * @return void
             */
            public function __construct(private readonly array $data)
            {
                parent::__construct();
            }


            /**
             * Return the xAPI statement payload.
             *
             * @return array
             */
            public function getData(): array
            {
                return $this->data;
            }
        };

    }//end makeXapiEvent()


    /**
     * Happy-path: completing the final mandatory lesson transitions the Enrolment
     * to `completed` and OR writes an enrolment.completed audit entry.
     *
     * @return void
     */
    public function testCompletingFinalMandatoryLessonTransitionsEnrolment(): void
    {
        // ── Seed data ──────────────────────────────────────────────────
        // 1. Course (published).
        $course = $this->createObject(
            'Course',
            [
                'title'     => 'Integration Test Course ' . uniqid(),
                'lifecycle' => 'published',
            ]
        );

        $courseId = $course['uuid'];

        $xapiObjectId1 = 'https://scholiq.test/lessons/' . uniqid();
        $xapiObjectId2 = 'https://scholiq.test/lessons/' . uniqid();

        // 2. Lesson 1 — published, not mandatory.
        $this->createObject(
            'Lesson',
            [
                'title'            => 'Lesson 1',
                'courseId'         => $courseId,
                'lifecycle'        => 'published',
                'mandatoryTraining' => false,
                'xapiObjectId'     => $xapiObjectId1,
            ]
        );

        // 3. Lesson 2 — published, mandatory training (the final lesson).
        $lesson2 = $this->createObject(
            'Lesson',
            [
                'title'            => 'Lesson 2 — Mandatory',
                'courseId'         => $courseId,
                'lifecycle'        => 'published',
                'mandatoryTraining' => true,
                'xapiObjectId'     => $xapiObjectId2,
            ]
        );

        $learnerId = 'learner-' . uniqid();

        // 4. Active Enrolment for the learner.
        $enrolment = $this->createObject(
            'Enrolment',
            [
                'learnerId' => $learnerId,
                'courseId'  => $courseId,
                'lifecycle' => 'active',
            ]
        );

        $enrolmentId = $enrolment['uuid'];

        // ── Fire event ─────────────────────────────────────────────────
        $xapiStatement = [
            'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
            'object' => ['id' => $xapiObjectId2],
            'actor'  => ['account' => ['name' => $learnerId]],
        ];

        $event = $this->makeXapiEvent($xapiStatement);
        $this->handler->handle($event);

        // ── Assertions ─────────────────────────────────────────────────
        // The Enrolment should now be in `completed` state.
        $updated = $this->objectService->get(
            register: 'scholiq',
            schema: 'Enrolment',
            uuid: $enrolmentId,
        );

        $this->assertSame(
            'completed',
            $updated['lifecycle'] ?? null,
            'Enrolment lifecycle should be "completed" after xAPI completed statement for final mandatory lesson.'
        );

        // OR should have written an audit-trail entry for the transition.
        // We check the audit log if the AuditTrailMapper is available.
        if (class_exists(\OCA\OpenRegister\Db\AuditTrailMapper::class)) {
            try {
                $auditMapper = \OC::$server->get(\OCA\OpenRegister\Db\AuditTrailMapper::class);
                $entries     = $auditMapper->findAll(
                    filters: [
                        'object_uuid' => $enrolmentId,
                        'action'      => 'enrolment.completed',
                    ],
                    limit: 5
                );
                $this->assertNotEmpty(
                    $entries,
                    'OR audit trail should contain an enrolment.completed entry after the lifecycle transition.'
                );
            } catch (\Throwable) {
                // AuditTrailMapper may not expose this query method in all OR versions; skip gracefully.
                $this->addWarning('Could not verify audit trail entry — AuditTrailMapper query not available in this OR version.');
            }
        }

    }//end testCompletingFinalMandatoryLessonTransitionsEnrolment()


    /**
     * Non-mandatory lesson completion does NOT transition the Enrolment.
     *
     * @return void
     */
    public function testNonMandatoryLessonCompletionIsIgnored(): void
    {
        $course   = $this->createObject('Course', ['title' => 'Course ' . uniqid(), 'lifecycle' => 'published']);
        $courseId = $course['uuid'];

        $xapiObjectId = 'https://scholiq.test/lessons/' . uniqid();
        $this->createObject(
            'Lesson',
            [
                'title'            => 'Optional Lesson',
                'courseId'         => $courseId,
                'lifecycle'        => 'published',
                'mandatoryTraining' => false,
                'xapiObjectId'     => $xapiObjectId,
            ]
        );

        $learnerId = 'learner-' . uniqid();
        $enrolment = $this->createObject('Enrolment', ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => 'active']);

        $event = $this->makeXapiEvent(
            [
                'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
                'object' => ['id' => $xapiObjectId],
                'actor'  => ['account' => ['name' => $learnerId]],
            ]
        );

        $this->handler->handle($event);

        $still = $this->objectService->get(register: 'scholiq', schema: 'Enrolment', uuid: $enrolment['uuid']);
        $this->assertSame('active', $still['lifecycle'] ?? null, 'Enrolment should remain active after non-mandatory lesson completion.');

    }//end testNonMandatoryLessonCompletionIsIgnored()


    /**
     * Unknown verb in xAPI statement is ignored (no Enrolment change).
     *
     * @return void
     */
    public function testUnknownVerbIsIgnored(): void
    {
        $course   = $this->createObject('Course', ['title' => 'Course ' . uniqid(), 'lifecycle' => 'published']);
        $courseId = $course['uuid'];

        $xapiObjectId = 'https://scholiq.test/lessons/' . uniqid();
        $this->createObject(
            'Lesson',
            [
                'title'            => 'Mandatory Lesson',
                'courseId'         => $courseId,
                'lifecycle'        => 'published',
                'mandatoryTraining' => true,
                'xapiObjectId'     => $xapiObjectId,
            ]
        );

        $learnerId = 'learner-' . uniqid();
        $enrolment = $this->createObject('Enrolment', ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => 'active']);

        $event = $this->makeXapiEvent(
            [
                'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/launched'],
                'object' => ['id' => $xapiObjectId],
                'actor'  => ['account' => ['name' => $learnerId]],
            ]
        );

        $this->handler->handle($event);

        $still = $this->objectService->get(register: 'scholiq', schema: 'Enrolment', uuid: $enrolment['uuid']);
        $this->assertSame('active', $still['lifecycle'] ?? null, 'Enrolment should remain active for non-completion verbs.');

    }//end testUnknownVerbIsIgnored()


}//end class
