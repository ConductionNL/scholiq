<?php

/**
 * Scholiq GradeRollupHandler unit tests — scheduled visibility window.
 *
 * Covers the `grade-visibility-scheduling` wiring: GradeVisibilityResolver is
 * invoked once per publish, the resolved `visibleFrom` is persisted onto the
 * GradeEntry and stamped onto every fanned-out GradeNotification, an explicit
 * teacher override propagates unchanged, and the FinalGrade recompute is
 * unaffected by (does not wait on) visibleFrom resolution.
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
 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Grading\GradeFormulaEvaluator;
use OCA\Scholiq\Grading\GradeVisibilityResolver;
use OCA\Scholiq\Listener\GradeRollupHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradeRollupHandler::handle() on GradeEntry → published.
 */
class GradeRollupHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
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
     * Build a handler with a real GradeVisibilityResolver and stubbed collaborators.
     *
     * @param array<string, mixed>|null $curriculumPlan Curriculum plan data returned by find().
     * @param array<int, string>        $parentIds      Parent user IDs returned for the learner profile.
     * @param DateTime                  $now            The "now" the injected ITimeFactory reports.
     *
     * @return GradeRollupHandler
     */
    private function makeHandler(?array $curriculumPlan, array $parentIds, DateTime $now): GradeRollupHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($curriculumPlan) {
                if ($schema === 'curriculum-plan') {
                    return $curriculumPlan;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($parentIds) {
                if ($config['schema'] === 'final-grade') {
                    return [];
                }

                if ($config['schema'] === 'learner-profile') {
                    return [['parentIds' => $parentIds]];
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

        $evaluator = $this->createMock(GradeFormulaEvaluator::class);
        $evaluator->method('evaluate')->willReturn(
            [
                'value'            => 8.0,
                'passed'           => true,
                'breakdown'        => ['periods' => [], 'components' => []],
                'lastRecomputedAt' => '2026-07-13T12:00:00+02:00',
            ]
        );

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new GradeRollupHandler(
            $objectService,
            $evaluator,
            new GradeVisibilityResolver(),
            $timeFactory
        );

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a GradeEntry → published transition.
     *
     * @param array<string, mixed> $entryData The GradeEntry's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $entryData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($entryData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('grade-entry');
        $event->method('getTo')->willReturn('published');
        $event->method('getFrom')->willReturn('concept');

        return $event;

    }//end makeEvent()

    /**
     * A night publish under a `nextSchoolDay` policy resolves `visibleFrom` to the next school
     * day and stamps the identical value onto the GradeEntry and every fanned-out GradeNotification.
     *
     * @return void
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-night-publish-defers-notification-to-the-resolved-visiblefrom
     */
    public function testNightPublishUnderNextSchoolDayPolicyResolvesAndStampsVisibleFrom(): void
    {
        // Monday 2026-07-13, 23:40 Europe/Amsterdam — after the 10:00 cutoff.
        $now  = new DateTime('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $plan = [
            'id'                    => 'plan-1',
            'gradeVisibilityPolicy' => [
                'mode'     => 'nextSchoolDay',
                'time'     => '10:00',
                'timezone' => 'Europe/Amsterdam',
            ],
        ];

        $handler = $this->makeHandler(curriculumPlan: $plan, parentIds: ['parent-1', 'parent-2'], now: $now);

        $entry = [
            'id'               => 'entry-1',
            'learnerId'        => 'learner-1',
            'curriculumPlanId' => 'plan-1',
            'tenant_id'        => 'tenant-a',
            'courseId'         => 'course-1',
            'gradeScaleId'     => 'scale-1',
            'lifecycle'        => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $expectedVisibleFrom = '2026-07-14T10:00:00+02:00';

        $gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        self::assertCount(1, $gradeEntrySaves);
        self::assertSame($expectedVisibleFrom, $gradeEntrySaves[0]['object']['visibleFrom']);
        // lifecycle is untouched by this write — still 'published', no re-transition.
        self::assertSame('published', $gradeEntrySaves[0]['object']['lifecycle']);

        $notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-notification'));
        self::assertCount(2, $notificationSaves);
        foreach ($notificationSaves as $save) {
            self::assertSame($expectedVisibleFrom, $save['object']['visibleFrom']);
        }

    }//end testNightPublishUnderNextSchoolDayPolicyResolvesAndStampsVisibleFrom()

    /**
     * An explicit teacher override on the GradeEntry propagates unchanged to the persisted
     * GradeEntry and every fanned-out GradeNotification, regardless of the CurriculumPlan policy.
     *
     * @return void
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-teacher-overrides-the-default-visibility-window
     */
    public function testExplicitOverridePropagatesToGradeEntryAndNotifications(): void
    {
        $now  = new DateTime('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $plan = [
            'id'                    => 'plan-1',
            'gradeVisibilityPolicy' => [
                'mode'     => 'nextSchoolDay',
                'time'     => '10:00',
                'timezone' => 'Europe/Amsterdam',
            ],
        ];

        $handler = $this->makeHandler(curriculumPlan: $plan, parentIds: ['parent-1'], now: $now);

        $override = '2026-07-13T23:41:00+02:00';
        $entry    = [
            'id'               => 'entry-1',
            'learnerId'        => 'learner-1',
            'curriculumPlanId' => 'plan-1',
            'tenant_id'        => 'tenant-a',
            'visibleFrom'      => $override,
            'lifecycle'        => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        self::assertSame($override, $gradeEntrySaves[0]['object']['visibleFrom']);

        $notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-notification'));
        self::assertCount(1, $notificationSaves);
        self::assertSame($override, $notificationSaves[0]['object']['visibleFrom']);

    }//end testExplicitOverridePropagatesToGradeEntryAndNotifications()

    /**
     * FinalGrade recompute happens unconditionally and is unaffected by visibleFrom resolution —
     * it recomputes at publish, not at visibleFrom, and carries no visibleFrom field of its own.
     *
     * @return void
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-roll-up-re-fires-on-publish-without-a-timedjob
     */
    public function testFinalGradeRecomputeIsUnaffectedByVisibleFromResolution(): void
    {
        // A far-future nextSchoolDay resolution (policy defers visibility significantly).
        $now  = new DateTime('2026-07-10 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $plan = [
            'id'                    => 'plan-1',
            'gradeVisibilityPolicy' => [
                'mode'     => 'nextSchoolDay',
                'time'     => '10:00',
                'timezone' => 'Europe/Amsterdam',
            ],
        ];

        $handler = $this->makeHandler(curriculumPlan: $plan, parentIds: [], now: $now);

        $entry = [
            'id'               => 'entry-1',
            'learnerId'        => 'learner-1',
            'curriculumPlanId' => 'plan-1',
            'tenant_id'        => 'tenant-a',
            'courseId'         => 'course-1',
            'gradeScaleId'     => 'scale-1',
            'lifecycle'        => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $finalGradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'final-grade'));
        self::assertCount(1, $finalGradeSaves);
        self::assertSame(8.0, $finalGradeSaves[0]['object']['value']);
        self::assertTrue($finalGradeSaves[0]['object']['passed']);
        self::assertArrayNotHasKey('visibleFrom', $finalGradeSaves[0]['object']);

    }//end testFinalGradeRecomputeIsUnaffectedByVisibleFromResolution()

    /**
     * A null `gradeVisibilityPolicy` (or a missing plan) resolves visibleFrom to the publish
     * moment itself — today's behaviour is unaffected until a school opts in.
     *
     * @return void
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-gradeentry-schema-carries-a-scheduled-visibility-window
     */
    public function testNullPolicyResolvesVisibleFromToPublishMoment(): void
    {
        $now  = new DateTime('2026-07-13 14:00:00', new DateTimeZone('Europe/Amsterdam'));
        $plan = ['id' => 'plan-1', 'gradeVisibilityPolicy' => null];

        $handler = $this->makeHandler(curriculumPlan: $plan, parentIds: [], now: $now);

        $entry = [
            'id'               => 'entry-1',
            'learnerId'        => 'learner-1',
            'curriculumPlanId' => 'plan-1',
            'tenant_id'        => 'tenant-a',
            'lifecycle'        => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        self::assertSame('2026-07-13T14:00:00+02:00', $gradeEntrySaves[0]['object']['visibleFrom']);

    }//end testNullPolicyResolvesVisibleFromToPublishMoment()
}//end class
