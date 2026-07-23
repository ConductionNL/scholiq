<?php

/**
 * Scholiq EnrolmentProgressRollupHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\EnrolmentProgressRollupHandler;
use OCA\Scholiq\Progress\EnrolmentProgressEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressRollupHandler::handle() on ObjectCreatedEvent<LessonCompletion>.
 */
class EnrolmentProgressRollupHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler with mocked collaborators.
     *
     * @param array<int, array<string, mixed>> $enrolments        Enrolment rows returned for the active-enrolment lookup.
     * @param array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int} $evaluated Result the mocked evaluator returns.
     *
     * @return EnrolmentProgressRollupHandler
     */
    private function makeHandler(array $enrolments, array $evaluated): EnrolmentProgressRollupHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($enrolments) {
                if ($config['schema'] === 'enrolment') {
                    return $enrolments;
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

        $evaluator = $this->createMock(EnrolmentProgressEvaluator::class);
        $evaluator->method('evaluate')->willReturn($evaluated);

        return new EnrolmentProgressRollupHandler($objectService, $evaluator);

    }//end makeHandler()

    /**
     * Build a mocked ObjectCreatedEvent<LessonCompletion>.
     *
     * @param array<string, mixed> $data The LessonCompletion jsonSerialize() payload.
     *
     * @return ObjectCreatedEvent
     */
    private function makeEvent(array $data): ObjectCreatedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('lesson-completion');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        return $event;

    }//end makeEvent()

    /**
     * A new LessonCompletion for a learner with an active Enrolment triggers
     * a recompute and saves progressPercent onto that Enrolment.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
     */
    public function testNewCompletionTriggersRecompute(): void
    {
        $enrolment = ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active'];

        $handler = $this->makeHandler(
            enrolments: [$enrolment],
            evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
        );

        $handler->handle(
            $this->makeEvent(
                ['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
            )
        );

        self::assertCount(1, $this->savedObjects);
        self::assertSame('enrolment', $this->savedObjects[0]['schema']);
        self::assertSame('enrolment-1', $this->savedObjects[0]['object']['id']);
        self::assertSame(40, $this->savedObjects[0]['object']['progressPercent']);

    }//end testNewCompletionTriggersRecompute()

    /**
     * A learner with no active Enrolment for the completion's course is
     * skipped without error.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
     */
    public function testNoActiveEnrolmentIsSkipped(): void
    {
        $handler = $this->makeHandler(
            enrolments: [],
            evaluated: ['progressPercent' => 0, 'completedLessonCount' => 0, 'totalPublishedLessonCount' => 0]
        );

        $handler->handle(
            $this->makeEvent(
                ['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
            )
        );

        self::assertCount(0, $this->savedObjects);

    }//end testNoActiveEnrolmentIsSkipped()

    /**
     * An event on a different schema is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedSchemaIsIgnored(): void
    {
        $handler = $this->makeHandler(
            enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']],
            evaluated: ['progressPercent' => 50, 'completedLessonCount' => 5, 'totalPublishedLessonCount' => 10]
        );

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'x']);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('xapi-statement');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testUnrelatedSchemaIsIgnored()
}//end class
