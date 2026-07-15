<?php

/**
 * Scholiq ApplicationConversionHandler unit tests.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ApplicationConversionHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ApplicationConversionHandler::handle() on Application -> placed.
 */
class ApplicationConversionHandlerTest extends TestCase
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
     * @param array<string, mixed>|null $programme Programme data returned by find(), or null.
     *
     * @return ApplicationConversionHandler
     */
    private function makeHandler(?array $programme): ApplicationConversionHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($programme) {
                if ($schema === 'programme') {
                    return $programme;
                }

                return null;
            }
        );

        $counter = ['learner-profile' => 0, 'enrolment' => 0];
        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) use (&$counter) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

                if ($schema === 'learner-profile') {
                    $counter['learner-profile']++;
                    return array_merge($object, ['id' => 'profile-'.$counter['learner-profile']]);
                }

                if ($schema === 'enrolment') {
                    $counter['enrolment']++;
                    return array_merge($object, ['id' => 'enrolment-'.$counter['enrolment']]);
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

        return new ApplicationConversionHandler($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for an Application -> placed transition.
     *
     * @param array<string, mixed> $applicationData The Application's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $applicationData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($applicationData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getTo')->willReturn('placed');
        $event->method('getFrom')->willReturn('intake-completed');

        return $event;

    }//end makeEvent()

    /**
     * Placement creates a LearnerProfile (guardianRefs stamped) and one Enrolment per
     * Programme course, stamps both reference fields, and drives the Application to converted.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-placement-creates-a-learnerprofile-and-enrolments
     */
    public function testPlacementCreatesLearnerProfileAndEnrolments(): void
    {
        $programme = ['id' => 'programme-1', 'courseIds' => ['course-a', 'course-b', 'course-c']];
        $handler   = $this->makeHandler(programme: $programme);

        $application = [
            'id'                  => 'app-1',
            'programmeId'         => 'programme-1',
            'guardianRef'         => 'guardian-ref-1',
            'applicantGivenName'  => 'Kim',
            'applicantFamilyName' => 'Jansen',
            'tenant_id'           => 'tenant-a',
            'lifecycle'           => 'placed',
        ];

        $handler->handle($this->makeEvent($application));

        $profileSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'learner-profile'));
        self::assertCount(1, $profileSaves);
        self::assertSame(['guardian-ref-1'], $profileSaves[0]['object']['guardianRefs']);
        self::assertSame(['learner'], $profileSaves[0]['object']['roles']);
        self::assertSame('Kim', $profileSaves[0]['object']['givenName']);

        $enrolmentSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'enrolment'));
        self::assertCount(3, $enrolmentSaves);
        foreach ($enrolmentSaves as $save) {
            self::assertSame('admission', $save['object']['source']);
        }

        $applicationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'application'));
        self::assertCount(1, $applicationSaves);
        self::assertSame('profile-1', $applicationSaves[0]['object']['convertedLearnerProfileId']);
        self::assertCount(3, $applicationSaves[0]['object']['convertedEnrolmentIds']);

        self::assertCount(1, $this->transitions);
        self::assertSame('app-1', $this->transitions[0]['objectId']);
        self::assertSame('convert', $this->transitions[0]['action']);

    }//end testPlacementCreatesLearnerProfileAndEnrolments()

    /**
     * No NC user account or LMS provisioning side effect — only OpenRegister writes happen
     * (no unexpected schema is saved).
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments
     */
    public function testNoUnexpectedSideEffectSchemasWritten(): void
    {
        $programme = ['id' => 'programme-1', 'courseIds' => ['course-a']];
        $handler   = $this->makeHandler(programme: $programme);

        $application = ['id' => 'app-2', 'programmeId' => 'programme-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'placed'];

        $handler->handle($this->makeEvent($application));

        $writtenSchemas = array_unique(array_column($this->savedObjects, 'schema'));
        sort($writtenSchemas);
        self::assertSame(['application', 'enrolment', 'learner-profile'], $writtenSchemas);

    }//end testNoUnexpectedSideEffectSchemasWritten()

    /**
     * A missing guardianRef leaves guardianRefs empty rather than containing a null entry.
     *
     * @return void
     */
    public function testMissingGuardianRefLeavesGuardianRefsEmpty(): void
    {
        $programme = ['id' => 'programme-1', 'courseIds' => []];
        $handler   = $this->makeHandler(programme: $programme);

        $application = ['id' => 'app-3', 'programmeId' => 'programme-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'placed'];

        $handler->handle($this->makeEvent($application));

        $profileSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'learner-profile'));
        self::assertSame([], $profileSaves[0]['object']['guardianRefs']);

    }//end testMissingGuardianRefLeavesGuardianRefsEmpty()

    /**
     * A missing Application id aborts conversion — nothing is saved or transitioned.
     *
     * @return void
     */
    public function testMissingApplicationIdAborts(): void
    {
        $handler = $this->makeHandler(programme: ['id' => 'programme-1', 'courseIds' => ['course-a']]);

        $handler->handle($this->makeEvent(['programmeId' => 'programme-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'placed']));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testMissingApplicationIdAborts()

    /**
     * A wrong schema is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(programme: null);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('subject-choice');
        $event->method('getTo')->willReturn('placed');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testWrongSchemaIgnored()

    /**
     * A target state other than `placed` is ignored.
     *
     * @return void
     */
    public function testWrongTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(programme: null);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
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
        $handler = $this->makeHandler(programme: null);

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testNonMatchingEventTypeIgnored()
}//end class
