<?php

/**
 * Scholiq EnrolmentPrerequisiteListener unit tests.
 *
 * Covers the enrolment prerequisite gate matrix: an unmet prerequisite blocks
 * enrolment naming the failing course; a met prerequisite (a completed
 * Enrolment already exists) allows; no prerequisiteCourseIds allows
 * unaffected; multiple prerequisites all must be met; a non-enrolment schema
 * is ignored; and a simulated ObjectService failure during lookup allows
 * (fail-open on infrastructure faults) and logs a warning.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\EnrolmentPrerequisiteListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for EnrolmentPrerequisiteListener::handle() on ObjectCreatingEvent<Enrolment>.
 */
class EnrolmentPrerequisiteListenerTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug then id.
     *
     * @var array<string, array<string, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = [];

    }//end setUp()

    /**
     * Seed a record into the fake datastore.
     *
     * @param string               $schema Schema slug.
     * @param string               $id     Object id.
     * @param array<string, mixed> $record Record data.
     *
     * @return void
     */
    private function seed(string $schema, string $id, array $record): void
    {
        $record['id']            = $id;
        $this->db[$schema][$id] = $record;

    }//end seed()

    /**
     * Build a listener over an in-memory ObjectService double.
     *
     * @param LoggerInterface|null $logger Optional logger override (to assert warnings).
     *
     * @return EnrolmentPrerequisiteListener
     */
    private function makeListener(?LoggerInterface $logger=null): EnrolmentPrerequisiteListener
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, $register=null, $schema=null) {
                return $this->db[$schema][$id] ?? null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema  = $config['schema'];
                $filters = ($config['filters'] ?? []);
                $records = array_values($this->db[$schema] ?? []);

                $matched = array_values(
                    array_filter(
                        $records,
                        static function (array $rec) use ($filters) {
                            foreach ($filters as $key => $value) {
                                if (($rec[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );

                if (isset($config['limit']) === true) {
                    $matched = array_slice($matched, 0, (int) $config['limit']);
                }

                return $matched;
            }
        );

        return new EnrolmentPrerequisiteListener($objectService, $logger ?? new NullLogger());

    }//end makeListener()

    /**
     * Build a real ObjectCreatingEvent wrapping a mocked `enrolment` ObjectEntity.
     *
     * @param array<string, mixed> $payload The Enrolment jsonSerialize() payload being created.
     *
     * @return ObjectCreatingEvent
     */
    private function eventFor(array $payload): ObjectCreatingEvent
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($payload);
        $entity->method('getRegister')->willReturn('scholiq');
        $entity->method('getSchema')->willReturn('enrolment');

        return new ObjectCreatingEvent($entity);

    }//end eventFor()

    /**
     * Enrolment is BLOCKED when the target course's prerequisite is unmet.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#scenario-block-enrolment-when-prerequisites-are-unmet
     */
    public function testEnrolmentBlockedWhenPrerequisiteUnmet(): void
    {
        $this->seed(
            'course',
            'course-advanced',
            ['name' => 'Advanced NIS2', 'prerequisiteCourseIds' => ['course-basic']]
        );
        $this->seed('course', 'course-basic', ['name' => 'NIS2 Basics']);

        $listener = $this->makeListener();
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-advanced',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertStringContainsString('NIS2 Basics', (string) $event->getErrors()['message']);
        self::assertSame('course-basic', $event->getErrors()['prerequisiteCourseId']);

    }//end testEnrolmentBlockedWhenPrerequisiteUnmet()

    /**
     * Enrolment is ALLOWED once the learner holds a completed Enrolment for
     * the prerequisite course.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#scenario-enrolment-succeeds-once-the-prerequisite-course-is-completed
     */
    public function testEnrolmentAllowedWhenPrerequisiteMet(): void
    {
        $this->seed(
            'course',
            'course-advanced',
            ['name' => 'Advanced NIS2', 'prerequisiteCourseIds' => ['course-basic']]
        );
        $this->seed(
            'enrolment',
            'enrolment-existing',
            ['learnerId' => 'learner-1', 'courseId' => 'course-basic', 'lifecycle' => 'completed', 'tenant_id' => 'tenant-a']
        );

        $listener = $this->makeListener();
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-advanced',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testEnrolmentAllowedWhenPrerequisiteMet()

    /**
     * No prerequisiteCourseIds (absent) allows enrolment unaffected.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#scenario-enrolment-proceeds-unaffected-when-a-course-has-no-prerequisites
     */
    public function testNoPrerequisitesAllowsUnaffected(): void
    {
        $this->seed('course', 'course-open', ['name' => 'Open Course']);

        $listener = $this->makeListener();
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-open',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testNoPrerequisitesAllowsUnaffected()

    /**
     * An empty prerequisiteCourseIds array allows enrolment unaffected.
     *
     * @return void
     */
    public function testEmptyPrerequisitesAllowsUnaffected(): void
    {
        $this->seed('course', 'course-open', ['name' => 'Open Course', 'prerequisiteCourseIds' => []]);

        $listener = $this->makeListener();
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-open',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testEmptyPrerequisitesAllowsUnaffected()

    /**
     * Multiple prerequisites: ALL must be met, blocking on the first unmet one.
     *
     * @return void
     */
    public function testMultiplePrerequisitesAllMustBeMet(): void
    {
        $this->seed(
            'course',
            'course-advanced',
            ['name' => 'Advanced NIS2', 'prerequisiteCourseIds' => ['course-basic', 'course-intermediate']]
        );
        $this->seed(
            'enrolment',
            'enrolment-existing',
            ['learnerId' => 'learner-1', 'courseId' => 'course-basic', 'lifecycle' => 'completed', 'tenant_id' => 'tenant-a']
        );
        $this->seed('course', 'course-intermediate', ['name' => 'Intermediate NIS2']);

        $listener = $this->makeListener();
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-advanced',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertStringContainsString('Intermediate NIS2', (string) $event->getErrors()['message']);

    }//end testMultiplePrerequisitesAllMustBeMet()

    /**
     * A non-enrolment schema is ignored entirely.
     *
     * @return void
     */
    public function testOtherSchemaIgnored(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['id' => 'x']);
        $entity->method('getRegister')->willReturn('scholiq');
        $entity->method('getSchema')->willReturn('course');

        $event = new ObjectCreatingEvent($entity);

        $listener = $this->makeListener();
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testOtherSchemaIgnored()

    /**
     * A non-ObjectCreatingEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventIgnored(): void
    {
        $listener = $this->makeListener();
        $event    = new Event();

        // Should not throw.
        $listener->handle($event);

        self::assertTrue(true);

    }//end testNonMatchingEventIgnored()

    /**
     * An infrastructure error during the prerequisite lookup fails soft
     * (allowed, not thrown) and logs a warning.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#scenario-an-infrastructure-error-during-the-prerequisite-lookup-does-not-block-enrolment
     */
    public function testInfrastructureFailureFailsSoftAndLogsWarning(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willThrowException(new \RuntimeException('OR unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $listener = new EnrolmentPrerequisiteListener($objectService, $logger);
        $event    = $this->eventFor(
            [
                'learnerId' => 'learner-1',
                'courseId'  => 'course-advanced',
                'tenant_id' => 'tenant-a',
            ]
        );

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testInfrastructureFailureFailsSoftAndLogsWarning()

    /**
     * A creating Enrolment with no courseId is skipped (OR's own `required`
     * validation is responsible for a genuinely missing courseId).
     *
     * @return void
     */
    public function testMissingCourseIdIsSkipped(): void
    {
        $listener = $this->makeListener();
        $event    = $this->eventFor(['learnerId' => 'learner-1']);

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testMissingCourseIdIsSkipped()
}//end class
