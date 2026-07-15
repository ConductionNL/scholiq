<?php

/**
 * Scholiq SubjectChoiceValidator unit tests.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\SubjectChoiceValidator;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SubjectChoiceValidator::handle() on SubjectChoice -> submitted.
 */
class SubjectChoiceValidatorTest extends TestCase
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
     * @param array<string, mixed>|null       $plan     CurriculumPlan data returned by find().
     * @param array<int, array<string,mixed>> $siblings Sibling SubjectChoice rows in an occupying state.
     *
     * @return SubjectChoiceValidator
     */
    private function makeHandler(?array $plan, array $siblings = []): SubjectChoiceValidator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($plan) {
                if ($schema === 'curriculum-plan') {
                    return $plan;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($siblings) {
                if (($config['schema'] ?? '') === 'subject-choice') {
                    return $siblings;
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

        return new SubjectChoiceValidator($objectService, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a SubjectChoice -> submitted transition.
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
        $event->method('getTo')->willReturn('submitted');
        $event->method('getFrom')->willReturn('draft');

        return $event;

    }//end makeEvent()

    /**
     * A choice satisfying every rule validates.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-satisfying-every-rule-validates
     */
    public function testValidChoiceMovesToValidated(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'electiveRules' => [
                'minElectives' => 2,
                'maxElectives' => 2,
            ],
        ];
        $handler = $this->makeHandler(plan: $plan);

        $choice = [
            'id'                        => 'choice-1',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-a', 'course-b'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(1, $this->savedObjects);
        self::assertSame('validated', $this->savedObjects[0]['object']['lifecycle']);
        self::assertSame([], $this->savedObjects[0]['object']['validationErrors']);

    }//end testValidChoiceMovesToValidated()

    /**
     * A choice violating a mandatory combination is sent back for revision.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-violating-a-mandatory-combination-is-sent-back-for-revision
     */
    public function testMandatoryCombinationViolationMovesToNeedsRevision(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'electiveRules' => [
                'mandatoryCombinations' => [['course-x', 'course-y']],
            ],
        ];
        $handler = $this->makeHandler(plan: $plan);

        $choice = [
            'id'                        => 'choice-2',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-x'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertSame('needs-revision', $this->savedObjects[0]['object']['lifecycle']);
        self::assertNotEmpty($this->savedObjects[0]['object']['validationErrors']);
        self::assertStringContainsString('course-y', $this->savedObjects[0]['object']['validationErrors'][0]);

    }//end testMandatoryCombinationViolationMovesToNeedsRevision()

    /**
     * A choice exceeding a course's capacity is sent back for revision.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-exceeding-a-course-s-capacity-is-sent-back-for-revision
     */
    public function testCapacityExceededMovesToNeedsRevision(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'electiveRules' => [
                'capacityByCourseId' => ['course-full' => 1],
            ],
        ];
        $siblings = [['id' => 'choice-other', 'selectedElectiveCourseIds' => ['course-full']]];
        $handler  = $this->makeHandler(plan: $plan, siblings: $siblings);

        $choice = [
            'id'                        => 'choice-3',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-full'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertSame('needs-revision', $this->savedObjects[0]['object']['lifecycle']);
        self::assertNotEmpty($this->savedObjects[0]['object']['validationErrors']);
        self::assertStringContainsString('course-full', $this->savedObjects[0]['object']['validationErrors'][0]);

    }//end testCapacityExceededMovesToNeedsRevision()

    /**
     * Selecting more than one course from a mutuallyExclusive set fails validation.
     *
     * @return void
     */
    public function testMutuallyExclusiveViolationMovesToNeedsRevision(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'electiveRules' => [
                'mutuallyExclusive' => [['course-x', 'course-y']],
            ],
        ];
        $handler = $this->makeHandler(plan: $plan);

        $choice = [
            'id'                        => 'choice-4',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-x', 'course-y'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertSame('needs-revision', $this->savedObjects[0]['object']['lifecycle']);

    }//end testMutuallyExclusiveViolationMovesToNeedsRevision()

    /**
     * Selecting fewer than minElectives fails validation.
     *
     * @return void
     */
    public function testBelowMinElectivesMovesToNeedsRevision(): void
    {
        $plan = [
            'id'            => 'plan-1',
            'electiveRules' => ['minElectives' => 2],
        ];
        $handler = $this->makeHandler(plan: $plan);

        $choice = [
            'id'                        => 'choice-5',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-a'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertSame('needs-revision', $this->savedObjects[0]['object']['lifecycle']);

    }//end testBelowMinElectivesMovesToNeedsRevision()

    /**
     * A plan with no electiveRules set validates unconditionally.
     *
     * @return void
     */
    public function testNoElectiveRulesAlwaysValidates(): void
    {
        $plan    = ['id' => 'plan-1'];
        $handler = $this->makeHandler(plan: $plan);

        $choice = [
            'id'                        => 'choice-6',
            'curriculumPlanId'          => 'plan-1',
            'selectedElectiveCourseIds' => ['course-a', 'course-b', 'course-c'],
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($choice));

        self::assertSame('validated', $this->savedObjects[0]['object']['lifecycle']);

    }//end testNoElectiveRulesAlwaysValidates()

    /**
     * A missing curriculumPlanId is skipped — no save.
     *
     * @return void
     */
    public function testMissingCurriculumPlanIdSkips(): void
    {
        $handler = $this->makeHandler(plan: null);

        $choice = ['id' => 'choice-7', 'selectedElectiveCourseIds' => [], 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($choice));

        self::assertCount(0, $this->savedObjects);

    }//end testMissingCurriculumPlanIdSkips()

    /**
     * A wrong schema is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(plan: null);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('application');
        $event->method('getTo')->willReturn('submitted');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testWrongSchemaIgnored()

    /**
     * A target state other than submitted is ignored.
     *
     * @return void
     */
    public function testWrongTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(plan: null);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('subject-choice');
        $event->method('getTo')->willReturn('locked');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testWrongTargetStateIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler(plan: null);

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);

    }//end testNonMatchingEventTypeIgnored()
}//end class
