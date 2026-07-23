<?php

/**
 * Scholiq TimetableConflictDetector unit tests.
 *
 * Covers every declared TimetableConflict `kind` (teacher/room/cohort/
 * learner-double-booking, room-capacity-exceeded, exam-clash), the
 * idempotent-rescan case, and the invariant that the detector never writes
 * a Session field.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Timetabling
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

namespace OCA\Scholiq\Tests\Unit\Timetabling;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Timetabling\TimetableConflictDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for TimetableConflictDetector::scan().
 */
class TimetableConflictDetectorTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var array<int,array{register:string,schema:string,object:array<string,mixed>}> Every saveObject() call, in order.
     */
    private array $saves = [];

    /**
     * Fixture registry, keyed by schema then by a simple predicate-free list.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $fixtures = [
        'cohort'             => [],
        'room'               => [],
        'assessment'         => [],
        'timetable-conflict' => [],
    ];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->saves = [];
        $this->fixtures = ['cohort' => [], 'room' => [], 'assessment' => [], 'timetable-conflict' => []];

    }//end setUp()

    /**
     * Build the detector under test, wiring findAll/saveObject against the
     * current $this->fixtures state.
     *
     * @return TimetableConflictDetector
     */
    private function detector(): TimetableConflictDetector
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'session') {
                    // The day-bucket sibling query — no additional sessions beyond
                    // what the test explicitly passes to scan().
                    return [];
                }

                $rows = $this->fixtures[$schema] ?? [];

                if ($schema === 'cohort' || $schema === 'room') {
                    return array_values(array_filter($rows, static fn (array $r): bool => ($r['id'] ?? null) === ($filters['id'] ?? null)));
                }

                if ($schema === 'assessment') {
                    return array_values(array_filter($rows, static fn (array $r): bool => ($r['sessionId'] ?? null) === ($filters['sessionId'] ?? null)));
                }

                if ($schema === 'timetable-conflict') {
                    return $rows;
                }

                return [];
            }
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->saves[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new TimetableConflictDetector($this->objectService, new NullLogger());

    }//end detector()

    /**
     * Sessions overlap: s-a is 09:00-10:00, s-b is 09:30-10:30.
     *
     * @param array<string,mixed> $overrides Session A overrides.
     * @param array<string,mixed> $overridesB Session B overrides.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function overlappingPair(array $overrides = [], array $overridesB = []): array
    {
        $a = array_merge(
            [
                'id'        => 's-a',
                'cohortId'  => 'cohort-a',
                'startsAt'  => '2026-02-02T09:00:00+00:00',
                'endsAt'    => '2026-02-02T10:00:00+00:00',
                'lifecycle' => 'scheduled',
                'tenant_id' => 'tenant-1',
            ],
            $overrides
        );
        $b = array_merge(
            [
                'id'        => 's-b',
                'cohortId'  => 'cohort-b',
                'startsAt'  => '2026-02-02T09:30:00+00:00',
                'endsAt'    => '2026-02-02T10:30:00+00:00',
                'lifecycle' => 'scheduled',
                'tenant_id' => 'tenant-1',
            ],
            $overridesB
        );

        return [$a, $b];

    }//end overlappingPair()

    /**
     * Two overlapping Sessions in the same room are flagged room-double-booking.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
     */
    public function testRoomDoubleBooking(): void
    {
        [$a, $b] = $this->overlappingPair(['roomId' => 'room-1'], ['roomId' => 'room-1']);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => ['t-a'], 'learnerIds' => ['l-a']],
            ['id' => 'cohort-b', 'teacherIds' => ['t-b'], 'learnerIds' => ['l-b']],
        ];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('room-double-booking', $kinds);

    }//end testRoomDoubleBooking()

    /**
     * Two overlapping Sessions sharing a teacher are flagged teacher-double-booking.
     *
     * @return void
     */
    public function testTeacherDoubleBooking(): void
    {
        [$a, $b] = $this->overlappingPair();
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => ['shared-teacher'], 'learnerIds' => ['l-a']],
            ['id' => 'cohort-b', 'teacherIds' => ['shared-teacher'], 'learnerIds' => ['l-b']],
        ];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('teacher-double-booking', $kinds);

    }//end testTeacherDoubleBooking()

    /**
     * A substitute teacher is used in the intersection instead of Cohort.teacherIds.
     *
     * @return void
     */
    public function testSubstituteTeacherUsedForDoubleBookingCheck(): void
    {
        [$a, $b] = $this->overlappingPair(['substituteTeacherId' => 'sub-1'], []);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => ['t-a'], 'learnerIds' => ['l-a']],
            ['id' => 'cohort-b', 'teacherIds' => ['sub-1'], 'learnerIds' => ['l-b']],
        ];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('teacher-double-booking', $kinds);

    }//end testSubstituteTeacherUsedForDoubleBookingCheck()

    /**
     * Two overlapping Sessions with the same cohortId are flagged cohort-double-booking.
     *
     * @return void
     */
    public function testCohortDoubleBooking(): void
    {
        [$a, $b] = $this->overlappingPair(['cohortId' => 'cohort-x'], ['cohortId' => 'cohort-x']);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-x', 'teacherIds' => [], 'learnerIds' => []],
        ];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('cohort-double-booking', $kinds);

    }//end testCohortDoubleBooking()

    /**
     * Two overlapping Sessions in different cohorts sharing a learner are
     * flagged learner-double-booking.
     *
     * @return void
     */
    public function testLearnerDoubleBooking(): void
    {
        [$a, $b] = $this->overlappingPair();
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => ['t-a'], 'learnerIds' => ['shared-learner']],
            ['id' => 'cohort-b', 'teacherIds' => ['t-b'], 'learnerIds' => ['shared-learner']],
        ];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('learner-double-booking', $kinds);

    }//end testLearnerDoubleBooking()

    /**
     * An exam Session exceeding room capacity is flagged room-capacity-exceeded.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
     */
    public function testRoomCapacityExceeded(): void
    {
        $session = [
            'id'        => 's-exam',
            'cohortId'  => 'cohort-exam',
            'roomId'    => 'room-small',
            'startsAt'  => '2026-02-02T09:00:00+00:00',
            'endsAt'    => '2026-02-02T10:00:00+00:00',
            'lifecycle' => 'scheduled',
            'tenant_id' => 'tenant-1',
        ];
        // 34-learner cohort against a capacity:30 room.
        $this->fixtures['cohort']     = [['id' => 'cohort-exam', 'teacherIds' => [], 'learnerIds' => array_map(static fn (int $n): string => "l{$n}", range(1, 34))]];
        $this->fixtures['room']       = [['id' => 'room-small', 'capacity' => 30]];
        $this->fixtures['assessment'] = [['id' => 'assess-1', 'sessionId' => 's-exam']];

        $this->detector()->scan([$session]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('room-capacity-exceeded', $kinds);

    }//end testRoomCapacityExceeded()

    /**
     * A room-capacity-exceeded Session under capacity is not flagged.
     *
     * @return void
     */
    public function testRoomCapacityNotExceededIsNotFlagged(): void
    {
        $session = [
            'id'        => 's-exam',
            'cohortId'  => 'cohort-exam',
            'roomId'    => 'room-big',
            'startsAt'  => '2026-02-02T09:00:00+00:00',
            'endsAt'    => '2026-02-02T10:00:00+00:00',
            'lifecycle' => 'scheduled',
            'tenant_id' => 'tenant-1',
        ];
        $this->fixtures['cohort']     = [['id' => 'cohort-exam', 'teacherIds' => [], 'learnerIds' => ['l1', 'l2']]];
        $this->fixtures['room']       = [['id' => 'room-big', 'capacity' => 30]];
        $this->fixtures['assessment'] = [['id' => 'assess-1', 'sessionId' => 's-exam']];

        $this->detector()->scan([$session]);

        self::assertCount(0, $this->saves);

    }//end testRoomCapacityNotExceededIsNotFlagged()

    /**
     * A pairwise overlap where one Session has a linked Assessment also gets
     * an exam-clash row, alongside the underlying overlap kind.
     *
     * @return void
     */
    public function testExamClashAccompaniesUnderlyingOverlapKind(): void
    {
        [$a, $b] = $this->overlappingPair(['cohortId' => 'cohort-x'], ['cohortId' => 'cohort-x']);
        $this->fixtures['cohort']     = [['id' => 'cohort-x', 'teacherIds' => [], 'learnerIds' => []]];
        $this->fixtures['assessment'] = [['id' => 'assess-1', 'sessionId' => 's-a']];

        $this->detector()->scan([$a, $b]);

        $kinds = array_column(array_column($this->saves, 'object'), 'kind');
        self::assertContains('cohort-double-booking', $kinds);
        self::assertContains('exam-clash', $kinds);

    }//end testExamClashAccompaniesUnderlyingOverlapKind()

    /**
     * Non-overlapping Sessions produce no conflicts.
     *
     * @return void
     */
    public function testNonOverlappingSessionsProduceNoConflict(): void
    {
        $a = ['id' => 's-a', 'cohortId' => 'cohort-a', 'roomId' => 'room-1', 'startsAt' => '2026-02-02T09:00:00+00:00', 'endsAt' => '2026-02-02T10:00:00+00:00', 'lifecycle' => 'scheduled', 'tenant_id' => 'tenant-1'];
        $b = ['id' => 's-b', 'cohortId' => 'cohort-b', 'roomId' => 'room-1', 'startsAt' => '2026-02-02T10:00:00+00:00', 'endsAt' => '2026-02-02T11:00:00+00:00', 'lifecycle' => 'scheduled', 'tenant_id' => 'tenant-1'];
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => [], 'learnerIds' => []],
            ['id' => 'cohort-b', 'teacherIds' => [], 'learnerIds' => []],
        ];

        $this->detector()->scan([$a, $b]);

        self::assertCount(0, $this->saves);

    }//end testNonOverlappingSessionsProduceNoConflict()

    /**
     * A cancelled Session is excluded from the pairwise scan.
     *
     * @return void
     */
    public function testCancelledSessionExcludedFromScan(): void
    {
        [$a, $b] = $this->overlappingPair(['roomId' => 'room-1', 'lifecycle' => 'cancelled'], ['roomId' => 'room-1']);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => [], 'learnerIds' => []],
            ['id' => 'cohort-b', 'teacherIds' => [], 'learnerIds' => []],
        ];

        $this->detector()->scan([$a, $b]);

        self::assertCount(0, $this->saves);

    }//end testCancelledSessionExcludedFromScan()

    /**
     * Re-scanning an unchanged window with an existing `open` conflict for
     * the same (sessionIds, kind) does not create a duplicate.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
     */
    public function testIdempotentRescanDoesNotDuplicate(): void
    {
        [$a, $b] = $this->overlappingPair(['roomId' => 'room-1'], ['roomId' => 'room-1']);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => [], 'learnerIds' => []],
            ['id' => 'cohort-b', 'teacherIds' => [], 'learnerIds' => []],
        ];
        $this->fixtures['timetable-conflict'] = [
            ['kind' => 'room-double-booking', 'sessionIds' => ['s-b', 's-a'], 'lifecycle' => 'open'],
        ];

        $this->detector()->scan([$a, $b]);

        self::assertCount(0, $this->saves);

    }//end testIdempotentRescanDoesNotDuplicate()

    /**
     * The detector never writes a Session field — only TimetableConflict rows.
     *
     * @return void
     */
    public function testNeverWritesSessionField(): void
    {
        [$a, $b] = $this->overlappingPair(['roomId' => 'room-1'], ['roomId' => 'room-1']);
        $this->fixtures['cohort'] = [
            ['id' => 'cohort-a', 'teacherIds' => [], 'learnerIds' => []],
            ['id' => 'cohort-b', 'teacherIds' => [], 'learnerIds' => []],
        ];

        $this->detector()->scan([$a, $b]);

        self::assertNotEmpty($this->saves);
        foreach ($this->saves as $save) {
            self::assertSame('timetable-conflict', $save['schema']);
        }

    }//end testNeverWritesSessionField()

    /**
     * An empty session list is a no-op.
     *
     * @return void
     */
    public function testEmptyInputIsNoOp(): void
    {
        $this->detector()->scan([]);

        self::assertCount(0, $this->saves);

    }//end testEmptyInputIsNoOp()
}//end class
