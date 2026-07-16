<?php

/**
 * Scholiq SessionConflictListener unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Scholiq\Listener\SessionConflictListener;
use OCA\Scholiq\Timetabling\TimetableConflictDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionConflictListener::handle().
 */
class SessionConflictListenerTest extends TestCase
{

    /**
     * @var TimetableConflictDetector&MockObject
     */
    private TimetableConflictDetector&MockObject $detector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = $this->createMock(TimetableConflictDetector::class);

    }//end setUp()

    /**
     * A created Session invokes the detector with its data.
     *
     * @return void
     */
    public function testCreatedSessionInvokesDetector(): void
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('session');
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'session-1']);

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $this->detector->expects(self::once())->method('scan')->with([['id' => 'session-1']]);

        (new SessionConflictListener($this->detector))->handle($event);

    }//end testCreatedSessionInvokesDetector()

    /**
     * An updated Session invokes the detector with its data.
     *
     * @return void
     */
    public function testUpdatedSessionInvokesDetector(): void
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('session');
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'session-2']);

        $event = $this->createMock(ObjectUpdatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $this->detector->expects(self::once())->method('scan')->with([['id' => 'session-2']]);

        (new SessionConflictListener($this->detector))->handle($event);

    }//end testUpdatedSessionInvokesDetector()

    /**
     * A different schema is ignored.
     *
     * @return void
     */
    public function testDifferentSchemaIsIgnored(): void
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn('cohort');

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);

        $this->detector->expects(self::never())->method('scan');

        (new SessionConflictListener($this->detector))->handle($event);

    }//end testDifferentSchemaIsIgnored()
}//end class
