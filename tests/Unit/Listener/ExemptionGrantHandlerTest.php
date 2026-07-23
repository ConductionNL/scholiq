<?php

/**
 * Scholiq ExemptionGrantHandler unit tests.
 *
 * Covers: ExemptionCase → granted creates a GradeEntry (sourceKind: exemption,
 * value: null, exemptionCaseId set), drives it through the *existing* publish
 * transition (via TransitionEngine, not a raw field write), back-links
 * resultingGradeEntryId, and ignores unrelated events/transitions.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-granted-exemption-feeds-grading-through-the-existing-publish-path
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ExemptionGrantHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ExemptionGrantHandler::handle() on ExemptionCase → granted.
 */
class ExemptionGrantHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Recorded transition() calls.
     *
     * @var array<int, array{objectId: string, action: string}>
     */
    private array $transitions = [];

    /**
     * Reset capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];
        $this->transitions  = [];

    }//end setUp()

    /**
     * Build a handler with stubbed collaborators.
     *
     * @param mixed $savedGradeEntry What ObjectService::saveObject() returns for the grade-entry save
     *                               (an array, an ObjectEntity, or null).
     *
     * @return ExemptionGrantHandler
     */
    private function makeHandler(mixed $savedGradeEntry): ExemptionGrantHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) use ($savedGradeEntry) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                if ($schema === 'grade-entry') {
                    return $savedGradeEntry;
                }

                return $object;
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) {
                $this->transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new ExemptionGrantHandler($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for an ExemptionCase → granted transition.
     *
     * @param array<string, mixed> $caseData The ExemptionCase's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $caseData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($caseData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('exemption-case');
        $event->method('getTo')->willReturn('granted');
        $event->method('getFrom')->willReturn('in-assessment');

        return $event;

    }//end makeEvent()

    /**
     * A granted ExemptionCase creates a GradeEntry (sourceKind: exemption, value: null,
     * exemptionCaseId set) and drives it through the existing publish transition, then
     * back-links resultingGradeEntryId onto the case.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-granting-an-exemption-creates-and-publishes-a-gradeentry
     */
    public function testGrantedCaseCreatesAndPublishesGradeEntry(): void
    {
        $handler = $this->makeHandler(savedGradeEntry: ['id' => 'entry-1', 'sourceKind' => 'exemption']);

        $case = [
            'id'                => 'case-1',
            'learnerId'         => 'learner-1',
            'curriculumPlanId'  => 'plan-1',
            'componentId'       => 'comp-a',
            'tenant_id'         => 'tenant-a',
            'decidedBy'         => 'board-member-1',
            'lifecycle'         => 'granted',
        ];

        $handler->handle($this->makeEvent($case));

        $gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        self::assertCount(1, $gradeEntrySaves);
        self::assertSame('exemption', $gradeEntrySaves[0]['object']['sourceKind']);
        self::assertNull($gradeEntrySaves[0]['object']['value']);
        self::assertSame('case-1', $gradeEntrySaves[0]['object']['exemptionCaseId']);
        self::assertSame('learner-1', $gradeEntrySaves[0]['object']['learnerId']);
        self::assertSame('plan-1', $gradeEntrySaves[0]['object']['curriculumPlanId']);
        self::assertSame('comp-a', $gradeEntrySaves[0]['object']['componentId']);
        // Newly-created concept entry — the *existing* publish transition drives it forward.
        self::assertSame('concept', $gradeEntrySaves[0]['object']['lifecycle']);

        self::assertCount(1, $this->transitions);
        self::assertSame('entry-1', $this->transitions[0]['objectId']);
        self::assertSame('publish', $this->transitions[0]['action']);

        $caseSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exemption-case'));
        self::assertCount(1, $caseSaves);
        self::assertSame('entry-1', $caseSaves[0]['object']['resultingGradeEntryId']);

    }//end testGrantedCaseCreatesAndPublishesGradeEntry()

    /**
     * A GradeEntry save that returns an ObjectEntity (not a plain array) is handled identically.
     *
     * @return void
     */
    public function testHandlesObjectEntityReturnFromSaveObject(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['id' => 'entry-2']);

        $handler = $this->makeHandler(savedGradeEntry: $entity);

        $case = [
            'id'               => 'case-2',
            'learnerId'        => 'learner-2',
            'curriculumPlanId' => 'plan-2',
            'componentId'      => 'comp-b',
            'tenant_id'        => 'tenant-a',
            'lifecycle'        => 'granted',
        ];

        $handler->handle($this->makeEvent($case));

        self::assertCount(1, $this->transitions);
        self::assertSame('entry-2', $this->transitions[0]['objectId']);
        self::assertSame('publish', $this->transitions[0]['action']);

    }//end testHandlesObjectEntityReturnFromSaveObject()

    /**
     * Missing learnerId/curriculumPlanId/componentId is skipped — no GradeEntry created.
     *
     * @return void
     */
    public function testMissingRequiredFieldsSkips(): void
    {
        $handler = $this->makeHandler(savedGradeEntry: ['id' => 'entry-3']);

        $case = ['id' => 'case-3', 'lifecycle' => 'granted'];

        $handler->handle($this->makeEvent($case));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testMissingRequiredFieldsSkips()

    /**
     * A non-ExemptionCase event (wrong schema) is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(savedGradeEntry: ['id' => 'entry-4']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('fraud-case');
        $event->method('getTo')->willReturn('granted');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testWrongSchemaIgnored()

    /**
     * A transition to a state other than `granted` is ignored.
     *
     * @return void
     */
    public function testWrongTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(savedGradeEntry: ['id' => 'entry-5']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('exemption-case');
        $event->method('getTo')->willReturn('rejected');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testWrongTargetStateIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler(savedGradeEntry: ['id' => 'entry-6']);

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testNonMatchingEventTypeIgnored()
}//end class
