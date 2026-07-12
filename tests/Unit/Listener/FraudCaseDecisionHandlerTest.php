<?php

/**
 * Scholiq FraudCaseDecisionHandler unit tests.
 *
 * Covers: FraudCase → decided with verdict=fraud-proven drives a still-concept
 * contestedGradeEntryId through invalidate; a published/revised/invalidated
 * contested entry is left untouched (defensive — logs and skips); verdict
 * unfounded is a no-op; no contestedGradeEntryId is a no-op; unrelated
 * events/transitions are ignored.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-fraud-proven-decision-invalidates-a-still-concept-contested-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\FraudCaseDecisionHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for FraudCaseDecisionHandler::handle() on FraudCase → decided.
 */
class FraudCaseDecisionHandlerTest extends TestCase
{

    /**
     * Recorded transition() calls.
     *
     * @var array<int, array{objectId: string, action: string}>
     */
    private array $transitions = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->transitions = [];

    }//end setUp()

    /**
     * Build a handler whose ObjectService::find() returns the given contested GradeEntry.
     *
     * @param array<string,mixed>|null $gradeEntry GradeEntry data returned by find(), or null.
     *
     * @return FraudCaseDecisionHandler
     */
    private function makeHandler(?array $gradeEntry): FraudCaseDecisionHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($gradeEntry);

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) {
                $this->transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new FraudCaseDecisionHandler($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a FraudCase → decided transition.
     *
     * @param array<string, mixed> $caseData The FraudCase's jsonSerialize() payload.
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
        $event->method('getSchema')->willReturn('fraud-case');
        $event->method('getTo')->willReturn('decided');
        $event->method('getFrom')->willReturn('heard');

        return $event;

    }//end makeEvent()

    /**
     * verdict=fraud-proven with a still-concept contested entry drives invalidate.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-a-fraud-proven-decision-invalidates-the-blocked-still-concept-entry
     */
    public function testFraudProvenInvalidatesConceptContestedEntry(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'concept']);

        $case = [
            'id'                     => 'case-1',
            'verdict'                => 'fraud-proven',
            'contestedGradeEntryId'  => 'entry-1',
        ];

        $handler->handle($this->makeEvent($case));

        self::assertCount(1, $this->transitions);
        self::assertSame('entry-1', $this->transitions[0]['objectId']);
        self::assertSame('invalidate', $this->transitions[0]['action']);

    }//end testFraudProvenInvalidatesConceptContestedEntry()

    /**
     * verdict=unfounded never triggers invalidate, even with a contestedGradeEntryId set.
     *
     * @return void
     */
    public function testUnfoundedVerdictDoesNotInvalidate(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'concept']);

        $case = [
            'id'                    => 'case-1',
            'verdict'               => 'unfounded',
            'contestedGradeEntryId' => 'entry-1',
        ];

        $handler->handle($this->makeEvent($case));

        self::assertCount(0, $this->transitions);

    }//end testUnfoundedVerdictDoesNotInvalidate()

    /**
     * No contestedGradeEntryId is a no-op even with verdict=fraud-proven.
     *
     * @return void
     */
    public function testNoContestedGradeEntryIdIsNoOp(): void
    {
        $handler = $this->makeHandler(gradeEntry: null);

        $case = ['id' => 'case-1', 'verdict' => 'fraud-proven'];

        $handler->handle($this->makeEvent($case));

        self::assertCount(0, $this->transitions);

    }//end testNoContestedGradeEntryIdIsNoOp()

    /**
     * A contested GradeEntry already published is left untouched — defensive skip,
     * never mutates a published, notified grade out from under a learner.
     *
     * @return void
     */
    public function testAlreadyPublishedContestedEntryIsLeftUntouched(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'published']);

        $case = [
            'id'                    => 'case-1',
            'verdict'               => 'fraud-proven',
            'contestedGradeEntryId' => 'entry-1',
        ];

        $handler->handle($this->makeEvent($case));

        self::assertCount(0, $this->transitions);

    }//end testAlreadyPublishedContestedEntryIsLeftUntouched()

    /**
     * A contestedGradeEntryId that resolves to no GradeEntry is skipped.
     *
     * @return void
     */
    public function testUnresolvableContestedGradeEntrySkipped(): void
    {
        $handler = $this->makeHandler(gradeEntry: null);

        $case = [
            'id'                    => 'case-1',
            'verdict'               => 'fraud-proven',
            'contestedGradeEntryId' => 'entry-missing',
        ];

        $handler->handle($this->makeEvent($case));

        self::assertCount(0, $this->transitions);

    }//end testUnresolvableContestedGradeEntrySkipped()

    /**
     * A non-FraudCase event (wrong schema) is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'concept']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('exemption-case');
        $event->method('getTo')->willReturn('decided');

        $handler->handle($event);

        self::assertCount(0, $this->transitions);

    }//end testWrongSchemaIgnored()

    /**
     * A transition to a state other than `decided` is ignored.
     *
     * @return void
     */
    public function testWrongTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'concept']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('fraud-case');
        $event->method('getTo')->willReturn('dismissed');

        $handler->handle($event);

        self::assertCount(0, $this->transitions);

    }//end testWrongTargetStateIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler(gradeEntry: ['id' => 'entry-1', 'lifecycle' => 'concept']);

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->transitions);

    }//end testNonMatchingEventTypeIgnored()
}//end class
