<?php

/**
 * Scholiq ReportCardComposer unit tests.
 *
 * Covers subject-row population from FinalGrade.breakdown.periods[periodCode]
 * plus contributing published GradeEntry ids, the "no matching period
 * component -> no row, not an error" rule, attendance aggregation, and the
 * `recompose` self-loop's overwrite behaviour.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-subject-with-no-matching-period-component-contributes-no-row-not-an-error
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ReportCardComposer;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ReportCardComposer::handle().
 */
class ReportCardComposerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a composer with a fully-stubbed ObjectService fixture.
     *
     * @param array<string,array<string,mixed>> $cohorts        cohortId => Cohort data.
     * @param array<string,array<string,mixed>> $plans          curriculumPlanId => CurriculumPlan data.
     * @param array<string,array<string,mixed>> $finalGrades    "{learnerId}|{planId}" => FinalGrade data.
     * @param array<string,array<int,array<string,mixed>>> $gradeEntries "{learnerId}|{planId}|{period}" => published GradeEntry rows.
     * @param array<int,array<string,mixed>>    $sessions       Session rows (any cohort).
     * @param array<int,array<string,mixed>>    $attendance     AttendanceRecord rows (any learner).
     * @param array<string,array<string,mixed>> $learnerProfiles learnerId => LearnerProfile data.
     *
     * @return ReportCardComposer
     */
    private function makeComposer(
        array $cohorts=[],
        array $plans=[],
        array $finalGrades=[],
        array $gradeEntries=[],
        array $sessions=[],
        array $attendance=[],
        array $learnerProfiles=[]
    ): ReportCardComposer {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($cohorts, $plans) {
                if ($schema === 'cohort') {
                    return $cohorts[$id] ?? null;
                }

                if ($schema === 'curriculum-plan') {
                    return $plans[$id] ?? null;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($finalGrades, $gradeEntries, $sessions, $attendance, $learnerProfiles) {
                if ($config['schema'] === 'final-grade') {
                    $key = ($config['filters']['learnerId'] ?? '').'|'.($config['filters']['curriculumPlanId'] ?? '');
                    $fg  = $finalGrades[$key] ?? null;
                    return $fg === null ? [] : [$fg];
                }

                if ($config['schema'] === 'grade-entry') {
                    $key = ($config['filters']['learnerId'] ?? '').'|'.($config['filters']['curriculumPlanId'] ?? '').'|'.($config['filters']['period'] ?? '');
                    return $gradeEntries[$key] ?? [];
                }

                if ($config['schema'] === 'session') {
                    $cohortId = $config['filters']['cohortId'] ?? '';
                    return array_values(array_filter($sessions, static fn ($s) => ($s['cohortId'] ?? '') === $cohortId));
                }

                if ($config['schema'] === 'attendance-record') {
                    $learnerId = $config['filters']['learnerId'] ?? '';
                    return array_values(array_filter($attendance, static fn ($a) => ($a['learnerId'] ?? '') === $learnerId));
                }

                if ($config['schema'] === 'learner-profile') {
                    $learnerId = $config['filters']['learnerId'] ?? '';
                    $profile   = $learnerProfiles[$learnerId] ?? null;
                    return $profile === null ? [] : [$profile];
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

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn(new DateTime('2026-07-13T09:00:00+00:00'));

        return new ReportCardComposer($objectService, $timeFactory, new NullLogger());

    }//end makeComposer()

    /**
     * Build a mocked ObjectTransitionedEvent.
     *
     * @param array<string,mixed> $objectData The transitioned object's payload.
     * @param string              $schema     OR schema slug.
     * @param string              $action     Transition action name.
     * @param string              $to         Lifecycle value after the transition.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $objectData, string $schema, string $action, string $to): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($objectData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn($schema);
        $event->method('getAction')->willReturn($action);
        $event->method('getTo')->willReturn($to);

        return $event;

    }//end makeEvent()

    /**
     * Composing a period with 2 cohorts totalling 3 learners and one
     * qualifying + one non-qualifying subject creates exactly 3 ReportCards,
     * each with exactly one subjectGrades row (the qualifying subject).
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-composing-a-period-creates-one-reportcard-per-cohort-learner
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-subject-with-no-matching-period-component-contributes-no-row-not-an-error
     */
    public function testComposeCreatesOneReportCardPerCohortLearnerWithQualifyingSubjectsOnly(): void
    {
        $composer = $this->makeComposer(
            cohorts: [
                'cohort-a' => ['id' => 'cohort-a', 'learnerIds' => ['learner-1', 'learner-2']],
                'cohort-b' => ['id' => 'cohort-b', 'learnerIds' => ['learner-3']],
            ],
            plans: [
                'plan-bio' => ['id' => 'plan-bio', 'components' => [['componentId' => 'c1', 'period' => '1', 'weight' => 1, 'kind' => 'assessment']]],
                // No component matching period '1' — this subject contributes no row.
                'plan-mus' => ['id' => 'plan-mus', 'components' => [['componentId' => 'c2', 'period' => '2', 'weight' => 1, 'kind' => 'assessment']]],
            ],
            finalGrades: [
                'learner-1|plan-bio' => ['learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-bio', 'courseId' => 'course-1', 'passed' => true, 'breakdown' => ['periods' => ['1' => 7.5]]],
                'learner-2|plan-bio' => ['learnerId' => 'learner-2', 'curriculumPlanId' => 'plan-bio', 'courseId' => 'course-1', 'passed' => false, 'breakdown' => ['periods' => ['1' => 4.5]]],
                'learner-3|plan-bio' => ['learnerId' => 'learner-3', 'curriculumPlanId' => 'plan-bio', 'courseId' => 'course-1', 'passed' => true, 'breakdown' => ['periods' => ['1' => 8.0]]],
            ],
            gradeEntries: [
                'learner-1|plan-bio|1' => [['id' => 'entry-1']],
                'learner-2|plan-bio|1' => [['id' => 'entry-2']],
                'learner-3|plan-bio|1' => [['id' => 'entry-3']],
            ],
        );

        $period = [
            'id'                  => 'period-1',
            'periodCode'          => '1',
            'curriculumPlanIds'   => ['plan-bio', 'plan-mus'],
            'cohortIds'           => ['cohort-a', 'cohort-b'],
            'attendanceIncluded'  => false,
            'tenant_id'           => 'tenant-a',
        ];

        $composer->handle($this->makeEvent($period, 'report-period', 'compose', 'composed'));

        $cardSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card'));
        self::assertCount(3, $cardSaves);

        $byLearner = [];
        foreach ($cardSaves as $save) {
            $byLearner[$save['object']['learnerId']] = $save['object'];
        }

        $byLearnerKeys = array_keys($byLearner);
        sort($byLearnerKeys);
        self::assertSame(['learner-1', 'learner-2', 'learner-3'], $byLearnerKeys);

        foreach (['learner-1', 'learner-2', 'learner-3'] as $learnerId) {
            self::assertArrayHasKey($learnerId, $byLearner, "expected a ReportCard for {$learnerId}");
            $card = $byLearner[$learnerId];
            self::assertSame('draft', $card['lifecycle']);
            self::assertSame('period-1', $card['reportPeriodId']);
            // Only plan-bio qualifies (matching period component) — plan-mus contributes no row.
            self::assertCount(1, $card['subjectGrades']);
            self::assertSame('plan-bio', $card['subjectGrades'][0]['curriculumPlanId']);
            self::assertNull($card['attendanceSummary']);
        }

        self::assertSame(7.5, $byLearner['learner-1']['subjectGrades'][0]['periodAverage']);
        self::assertTrue($byLearner['learner-1']['subjectGrades'][0]['passed']);
        self::assertSame(['entry-1'], $byLearner['learner-1']['subjectGrades'][0]['sourceGradeEntryIds']);

        self::assertFalse($byLearner['learner-2']['subjectGrades'][0]['passed']);

    }//end testComposeCreatesOneReportCardPerCohortLearnerWithQualifyingSubjectsOnly()

    /**
     * A subject whose CurriculumPlan has no component matching the period
     * contributes no subjectGrades row and does not error the composition
     * for the learner's other subjects.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-subject-with-no-matching-period-component-contributes-no-row-not-an-error
     */
    public function testNoQualifyingSubjectsYieldsEmptySubjectGrades(): void
    {
        $composer = $this->makeComposer(
            cohorts: ['cohort-a' => ['id' => 'cohort-a', 'learnerIds' => ['learner-1']]],
            plans: ['plan-x' => ['id' => 'plan-x', 'components' => [['componentId' => 'c1', 'period' => '2', 'weight' => 1, 'kind' => 'assessment']]]],
        );

        $period = [
            'id'                 => 'period-1',
            'periodCode'         => '1',
            'curriculumPlanIds'  => ['plan-x'],
            'cohortIds'          => ['cohort-a'],
            'attendanceIncluded' => false,
            'tenant_id'          => 'tenant-a',
        ];

        $composer->handle($this->makeEvent($period, 'report-period', 'compose', 'composed'));

        $cardSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card'));
        self::assertCount(1, $cardSaves);
        self::assertSame([], $cardSaves[0]['object']['subjectGrades']);

    }//end testNoQualifyingSubjectsYieldsEmptySubjectGrades()

    /**
     * When attendanceIncluded is true, sessions within [startDate, endDate]
     * are aggregated per learner into presentCount/absentUnexcusedCount/etc
     * and a percent, ignoring records outside the window.
     *
     * @return void
     */
    public function testAttendanceIncludedAggregatesSessionsWithinWindow(): void
    {
        $composer = $this->makeComposer(
            cohorts: ['cohort-a' => ['id' => 'cohort-a', 'learnerIds' => ['learner-1']]],
            plans: [],
            sessions: [
                ['id' => 'session-1', 'cohortId' => 'cohort-a', 'startsAt' => '2026-01-10T09:00:00+00:00', 'endsAt' => '2026-01-10T10:00:00+00:00'],
                ['id' => 'session-2', 'cohortId' => 'cohort-a', 'startsAt' => '2026-01-15T09:00:00+00:00', 'endsAt' => '2026-01-15T10:00:00+00:00'],
                // Outside the window — must not be counted.
                ['id' => 'session-3', 'cohortId' => 'cohort-a', 'startsAt' => '2026-03-01T09:00:00+00:00', 'endsAt' => '2026-03-01T10:00:00+00:00'],
            ],
            attendance: [
                ['learnerId' => 'learner-1', 'sessionId' => 'session-1', 'status' => 'present'],
                ['learnerId' => 'learner-1', 'sessionId' => 'session-2', 'status' => 'absent-unexcused'],
                // Outside-window session — must not be counted even though the record exists.
                ['learnerId' => 'learner-1', 'sessionId' => 'session-3', 'status' => 'present'],
            ],
        );

        $period = [
            'id'                 => 'period-1',
            'periodCode'         => '1',
            'curriculumPlanIds'  => [],
            'cohortIds'          => ['cohort-a'],
            'startDate'          => '2026-01-01',
            'endDate'            => '2026-01-31',
            'attendanceIncluded' => true,
            'tenant_id'          => 'tenant-a',
        ];

        $composer->handle($this->makeEvent($period, 'report-period', 'compose', 'composed'));

        $cardSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card'));
        self::assertCount(1, $cardSaves);

        $summary = $cardSaves[0]['object']['attendanceSummary'];
        self::assertSame(1, $summary['presentCount']);
        self::assertSame(1, $summary['absentUnexcusedCount']);
        self::assertSame(0, $summary['lateCount']);
        self::assertSame(50.0, $summary['attendancePercent']);

    }//end testAttendanceIncludedAggregatesSessionsWithinWindow()

    /**
     * The `recompose` self-loop overwrites an existing draft ReportCard's
     * subjectGrades/attendanceSummary in place (same id), re-resolving the
     * governing ReportPeriod via find().
     *
     * @return void
     */
    public function testRecomposeOverwritesExistingCardInPlace(): void
    {
        // find(report-period) isn't wired via makeComposer()'s callback (that
        // callback only handles cohort/curriculum-plan) — build a composer
        // directly whose find() also resolves the governing ReportPeriod.
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) {
                if ($schema === 'curriculum-plan') {
                    return ['id' => 'plan-bio', 'components' => [['componentId' => 'c1', 'period' => '1', 'weight' => 1, 'kind' => 'assessment']]];
                }

                if ($schema === 'report-period') {
                    return [
                        'id'                 => 'period-1',
                        'periodCode'         => '1',
                        'curriculumPlanIds'  => ['plan-bio'],
                        'cohortIds'          => [],
                        'attendanceIncluded' => false,
                    ];
                }

                return null;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if ($config['schema'] === 'final-grade') {
                    return [['learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-bio', 'passed' => true, 'breakdown' => ['periods' => ['1' => 9.0]]]];
                }

                if ($config['schema'] === 'grade-entry') {
                    return [['id' => 'entry-9']];
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

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn(new DateTime('2026-07-13T09:00:00+00:00'));

        $composer = new ReportCardComposer($objectService, $timeFactory, new NullLogger());

        $card = [
            'id'               => 'card-existing-1',
            'learnerId'        => 'learner-1',
            'reportPeriodId'   => 'period-1',
            'lifecycle'        => 'draft',
            'subjectGrades'    => [],
            'attendanceSummary' => null,
        ];

        $composer->handle($this->makeEvent($card, 'report-card', 'recompose', 'draft'));

        $cardSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card'));
        self::assertCount(1, $cardSaves);
        // Same id — an in-place update, not a new object.
        self::assertSame('card-existing-1', $cardSaves[0]['object']['id']);
        self::assertCount(1, $cardSaves[0]['object']['subjectGrades']);
        self::assertSame(9.0, $cardSaves[0]['object']['subjectGrades'][0]['periodAverage']);
        self::assertNotEmpty($cardSaves[0]['object']['composedAt']);

    }//end testRecomposeOverwritesExistingCardInPlace()
}//end class
