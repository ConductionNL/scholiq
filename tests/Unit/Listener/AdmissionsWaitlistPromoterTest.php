<?php

/**
 * Scholiq AdmissionsWaitlistPromoter unit tests.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\AdmissionsWaitlistPromoter;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AdmissionsWaitlistPromoter::handle() on Application withdrawn/rejected FROM placed.
 */
class AdmissionsWaitlistPromoterTest extends TestCase
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
     * Build a handler with stubbed collaborators.
     *
     * @param array<int, array<string, mixed>> $waitlisted Rows returned for a waitlisted-application query.
     *
     * @return AdmissionsWaitlistPromoter
     */
    private function makeHandler(array $waitlisted): AdmissionsWaitlistPromoter
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($waitlisted) {
                if (($config['schema'] ?? '') === 'application' && ($config['filters']['lifecycle'] ?? '') === 'waitlisted') {
                    return $waitlisted;
                }

                return [];
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) {
                $this->transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new AdmissionsWaitlistPromoter($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for an Application `placed -> $to` transition.
     *
     * @param array<string, mixed> $freedData The freed Application's jsonSerialize() payload.
     * @param string                $to        Target lifecycle state (withdrawn|rejected).
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $freedData, string $to): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($freedData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getFrom')->willReturn('placed');
        $event->method('getTo')->willReturn($to);

        return $event;

    }//end makeEvent()

    /**
     * A withdrawal promotes the oldest waitlisted applicant; a later one is untouched.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-withdrawal-promotes-the-oldest-waitlisted-applicant
     */
    public function testOldestWaitlistedApplicationPromotedOnWithdrawal(): void
    {
        $waitlisted = [
            ['id' => 'app-b', 'submittedAt' => '2026-02-01T09:00:00+01:00'],
            ['id' => 'app-a', 'submittedAt' => '2026-01-01T09:00:00+01:00'],
        ];

        $handler = $this->makeHandler(waitlisted: $waitlisted);

        $freed = ['id' => 'app-placed', 'admissionsRoundId' => 'round-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($freed, 'withdrawn'));

        self::assertCount(1, $this->transitions);
        self::assertSame('app-a', $this->transitions[0]['objectId']);
        self::assertSame('promote', $this->transitions[0]['action']);

    }//end testOldestWaitlistedApplicationPromotedOnWithdrawal()

    /**
     * A rejection FROM placed also promotes the oldest waitlisted applicant.
     *
     * @return void
     */
    public function testOldestWaitlistedApplicationPromotedOnRejection(): void
    {
        $waitlisted = [['id' => 'app-a', 'submittedAt' => '2026-01-01T09:00:00+01:00']];

        $handler = $this->makeHandler(waitlisted: $waitlisted);

        $freed = ['id' => 'app-placed', 'admissionsRoundId' => 'round-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($freed, 'rejected'));

        self::assertCount(1, $this->transitions);
        self::assertSame('app-a', $this->transitions[0]['objectId']);
        self::assertSame('promote', $this->transitions[0]['action']);

    }//end testOldestWaitlistedApplicationPromotedOnRejection()

    /**
     * No waitlisted applications for the round is a no-op.
     *
     * @return void
     */
    public function testNoWaitlistedApplicationsIsNoop(): void
    {
        $handler = $this->makeHandler(waitlisted: []);

        $freed = ['id' => 'app-placed', 'admissionsRoundId' => 'round-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($freed, 'withdrawn'));

        self::assertCount(0, $this->transitions);

    }//end testNoWaitlistedApplicationsIsNoop()

    /**
     * A transition NOT from `placed` is ignored.
     *
     * @return void
     */
    public function testNotFromPlacedIgnored(): void
    {
        $handler = $this->makeHandler(waitlisted: [['id' => 'app-a', 'submittedAt' => '2026-01-01T09:00:00+01:00']]);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getFrom')->willReturn('intake-completed');
        $event->method('getTo')->willReturn('rejected');

        $handler->handle($event);

        self::assertCount(0, $this->transitions);

    }//end testNotFromPlacedIgnored()

    /**
     * A target state other than withdrawn/rejected is ignored.
     *
     * @return void
     */
    public function testUnrelatedTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(waitlisted: [['id' => 'app-a', 'submittedAt' => '2026-01-01T09:00:00+01:00']]);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getFrom')->willReturn('placed');
        $event->method('getTo')->willReturn('converted');

        $handler->handle($event);

        self::assertCount(0, $this->transitions);

    }//end testUnrelatedTargetStateIgnored()

    /**
     * A wrong schema is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(waitlisted: [['id' => 'app-a', 'submittedAt' => '2026-01-01T09:00:00+01:00']]);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('subject-choice');
        $event->method('getFrom')->willReturn('placed');
        $event->method('getTo')->willReturn('withdrawn');

        $handler->handle($event);

        self::assertCount(0, $this->transitions);

    }//end testWrongSchemaIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler(waitlisted: []);

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->transitions);

    }//end testNonMatchingEventTypeIgnored()
}//end class
