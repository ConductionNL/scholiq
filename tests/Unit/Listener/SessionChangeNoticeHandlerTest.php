<?php

/**
 * Scholiq SessionChangeNoticeHandler unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\SessionChangeNoticeHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionChangeNoticeHandler::handle().
 */
class SessionChangeNoticeHandlerTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var array<int,array<string,mixed>> Objects passed to saveObject(), in call order.
     */
    private array $saved = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->saved = [];

    }//end setUp()

    /**
     * Build the handler under test.
     *
     * @return SessionChangeNoticeHandler
     */
    private function handler(): SessionChangeNoticeHandler
    {
        return new SessionChangeNoticeHandler($this->objectService, new NullLogger());

    }//end handler()

    /**
     * Wire ObjectService::findAll for cohort/learner-profile lookups, and
     * capture saveObject() calls into $this->saved.
     *
     * @param array<int,array<string,mixed>> $cohorts  Cohort fixtures.
     * @param array<int,array<string,mixed>> $profiles LearnerProfile fixtures.
     *
     * @return void
     */
    private function wire(array $cohorts, array $profiles): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($cohorts, $profiles): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'cohort') {
                    return array_values(array_filter($cohorts, static fn (array $c): bool => ($c['id'] ?? null) === ($filters['id'] ?? null)));
                }

                if ($schema === 'learner-profile') {
                    return array_values(array_filter($profiles, static fn (array $p): bool => ($p['ncUserId'] ?? null) === ($filters['ncUserId'] ?? null)));
                }

                return [];
            }
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->saved[] = $object;
                return $object;
            }
        );

    }//end wire()

    /**
     * Build a mocked ObjectTransitionedEvent for a Session transition.
     *
     * @param string              $action      Transition action.
     * @param array<string,mixed> $sessionData The Session's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(string $action, array $sessionData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($sessionData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('session');
        $event->method('getAction')->willReturn($action);

        return $event;

    }//end makeEvent()

    /**
     * Cancelling a Session materialises affectedLearnerIds/affectedParentIds
     * and stamps changedAt.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
     */
    public function testCancelMaterialisesAffectedLearnersAndParents(): void
    {
        $this->wire(
            [['id' => 'cohort-1', 'learnerIds' => ['alice', 'bob']]],
            [
                ['ncUserId' => 'alice', 'parentIds' => ['parent-alice']],
                ['ncUserId' => 'bob', 'parentIds' => ['parent-bob', 'parent-alice']],
            ]
        );

        $this->handler()->handle(
            $this->makeEvent('cancel', ['id' => 'session-1', 'cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a'])
        );

        self::assertCount(1, $this->saved);
        $saved = $this->saved[0];
        self::assertSame(['alice', 'bob'], $saved['affectedLearnerIds']);
        self::assertEqualsCanonicalizing(['parent-alice', 'parent-bob'], $saved['affectedParentIds']);
        self::assertNotEmpty($saved['changedAt']);

    }//end testCancelMaterialisesAffectedLearnersAndParents()

    /**
     * substitute-teacher also triggers materialisation.
     *
     * @return void
     */
    public function testSubstituteTeacherAlsoMaterialises(): void
    {
        $this->wire([['id' => 'cohort-1', 'learnerIds' => ['alice']]], [['ncUserId' => 'alice', 'parentIds' => []]]);

        $this->handler()->handle(
            $this->makeEvent('substitute-teacher', ['id' => 'session-1', 'cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a'])
        );

        self::assertCount(1, $this->saved);
        self::assertSame(['alice'], $this->saved[0]['affectedLearnerIds']);
        self::assertSame([], $this->saved[0]['affectedParentIds']);

    }//end testSubstituteTeacherAlsoMaterialises()

    /**
     * A learner with no linked parents yields an empty affectedParentIds.
     *
     * @return void
     */
    public function testLearnerWithNoParentsYieldsEmptyParentIds(): void
    {
        $this->wire([['id' => 'cohort-1', 'learnerIds' => ['alice']]], [['ncUserId' => 'alice', 'parentIds' => []]]);

        $this->handler()->handle(
            $this->makeEvent('cancel', ['id' => 'session-1', 'cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a'])
        );

        self::assertSame([], $this->saved[0]['affectedParentIds']);

    }//end testLearnerWithNoParentsYieldsEmptyParentIds()

    /**
     * A transition on a different schema is ignored.
     *
     * @return void
     */
    public function testDifferentSchemaIsIgnored(): void
    {
        $this->wire([], []);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('cohort');
        $event->method('getAction')->willReturn('activate');
        $event->expects(self::never())->method('getObject');

        $this->handler()->handle($event);

        self::assertCount(0, $this->saved);

    }//end testDifferentSchemaIsIgnored()

    /**
     * An unwatched action (e.g. 'start') is ignored.
     *
     * @return void
     */
    public function testUnwatchedActionIsIgnored(): void
    {
        $this->wire([], []);

        $this->handler()->handle(
            $this->makeEvent('start', ['id' => 'session-1', 'cohortId' => 'cohort-1'])
        );

        self::assertCount(0, $this->saved);

    }//end testUnwatchedActionIsIgnored()
}//end class
