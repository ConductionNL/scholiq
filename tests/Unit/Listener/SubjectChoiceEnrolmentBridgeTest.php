<?php

/**
 * Scholiq SubjectChoiceEnrolmentBridge unit tests.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\SubjectChoiceEnrolmentBridge;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SubjectChoiceEnrolmentBridge::handle() on SubjectChoice approved -> locked.
 */
class SubjectChoiceEnrolmentBridgeTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler with stubbed collaborators.
     *
     * @param array<int, array<string,mixed>> $existingEnrolments Existing Enrolment rows for the learner.
     *
     * @return SubjectChoiceEnrolmentBridge
     */
    private function makeHandler(array $existingEnrolments = []): SubjectChoiceEnrolmentBridge
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($existingEnrolments) {
                if (($config['schema'] ?? '') === 'enrolment') {
                    return $existingEnrolments;
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new SubjectChoiceEnrolmentBridge($objectService, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a SubjectChoice approved -> locked transition.
     *
     * @param array<string, mixed> $choiceData The SubjectChoice's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $choiceData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($choiceData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('subject-choice');
        $event->method('getFrom')->willReturn('approved');
        $event->method('getTo')->willReturn('locked');

        return $event;

    }//end makeEvent()

    /**
     * Locking a subject choice enrols the learner in the chosen electives.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-locking-a-subject-choice-enrols-the-learner-in-the-chosen-electives
     */
    public function testLockCreatesEnrolments(): void
    {
        $handler = $this->makeHandler(existingEnrolments: []);

        $choice = [
            'id'                        => 'choice-1',
            'learnerId'                 => 'learner-1',
            'selectedElectiveCourseIds' => ['course-a', 'course-b'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(2, $this->savedObjects);
        foreach ($this->savedObjects as $save) {
            self::assertSame('enrolment', $save['schema']);
            self::assertSame('subject-choice', $save['object']['source']);
            self::assertSame('learner-1', $save['object']['learnerId']);
        }

        $courseIds = array_column(array_column($this->savedObjects, 'object'), 'courseId');
        sort($courseIds);
        self::assertSame(['course-a', 'course-b'], $courseIds);

    }//end testLockCreatesEnrolments()

    /**
     * No duplicate Enrolment is created for a course the learner is already enrolled in.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment
     */
    public function testNoDuplicateEnrolmentForAlreadyEnrolledCourse(): void
    {
        $handler = $this->makeHandler(existingEnrolments: [['courseId' => 'course-a']]);

        $choice = [
            'id'                        => 'choice-2',
            'learnerId'                 => 'learner-1',
            'selectedElectiveCourseIds' => ['course-a', 'course-b'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(1, $this->savedObjects);
        self::assertSame('course-b', $this->savedObjects[0]['object']['courseId']);

    }//end testNoDuplicateEnrolmentForAlreadyEnrolledCourse()

    /**
     * Empty selectedElectiveCourseIds is a no-op.
     *
     * @return void
     */
    public function testEmptySelectionIsNoop(): void
    {
        $handler = $this->makeHandler();

        $choice = ['id' => 'choice-3', 'learnerId' => 'learner-1', 'selectedElectiveCourseIds' => [], 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(0, $this->savedObjects);

    }//end testEmptySelectionIsNoop()

    /**
     * A missing learnerId is a no-op.
     *
     * @return void
     */
    public function testMissingLearnerIdIsNoop(): void
    {
        $handler = $this->makeHandler();

        $choice = ['id' => 'choice-4', 'selectedElectiveCourseIds' => ['course-a'], 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(0, $this->savedObjects);

    }//end testMissingLearnerIdIsNoop()

    /**
     * A transition NOT from `approved` is ignored.
     *
     * @return void
     */
    public function testNotFromApprovedIgnored(): void
    {
        $handler = $this->makeHandler();

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('subject-choice');
        $event->method('getFrom')->willReturn('validated');
        $event->method('getTo')->willReturn('locked');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testNotFromApprovedIgnored()

    /**
     * A wrong schema is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler();

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getFrom')->willReturn('approved');
        $event->method('getTo')->willReturn('locked');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testWrongSchemaIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler();

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);

    }//end testNonMatchingEventTypeIgnored()
}//end class
