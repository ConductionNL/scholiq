<?php

/**
 * Scholiq ConferenceScheduleGenerator unit tests.
 *
 * Covers the conflict-freeness property (no two ConferenceSlots for the same
 * teacher overlap, no two ConferenceSlots for the same signup/parent
 * overlap), waitlisting with a named unmet teacher-request, and the
 * idempotent regenerate behaviour (a cancellation frees exactly that
 * signup's minutes while confirmed slots stay pinned; newly added
 * availability schedules a previously waitlisted signup).
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
 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ConferenceScheduleGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConferenceScheduleGenerator::handle() on ConferenceRound → scheduled.
 */
class ConferenceScheduleGeneratorTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Fixture rows served by ObjectService::findAll(), keyed by schema.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $fixtures = [];

    /**
     * Reset per-test state.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];
        $this->fixtures     = [];

    }//end setUp()

    /**
     * Build a generator whose ObjectService is wired to $this->fixtures /
     * $this->savedObjects.
     *
     * @return ConferenceScheduleGenerator
     */
    private function makeGenerator(): ConferenceScheduleGenerator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $rows    = $this->fixtures[$config['schema']] ?? [];
                $filters = $config['filters'] ?? [];

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($row[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new ConferenceScheduleGenerator($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGenerator()

    /**
     * Build a mocked ObjectTransitionedEvent for a ConferenceRound → scheduled transition.
     *
     * @param array<string, mixed> $roundData The ConferenceRound's jsonSerialize() payload.
     * @param string                $from      The `from` lifecycle state (generate vs regenerate).
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $roundData, string $from = 'booking-closed'): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($roundData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('conference-round');
        $event->method('getTo')->willReturn('scheduled');
        $event->method('getFrom')->willReturn($from);

        return $event;

    }//end makeEvent()

    /**
     * Assert that no two saved ConferenceSlot writes sharing the same teacherId
     * overlap, and no two sharing the same signupId overlap — the conflict-freeness
     * property the generator MUST guarantee.
     *
     * @param array<int, array<string, mixed>> $slots Saved ConferenceSlot objects.
     *
     * @return void
     */
    private function assertConflictFree(array $slots): void
    {
        foreach (['teacherId', 'signupId'] as $groupKey) {
            $byKey = [];
            foreach ($slots as $slot) {
                $byKey[$slot[$groupKey]][] = $slot;
            }

            foreach ($byKey as $key => $group) {
                for ($i = 0; $i < count($group); $i++) {
                    for ($j = ($i + 1); $j < count($group); $j++) {
                        $a = $group[$i];
                        $b = $group[$j];
                        $overlap = strtotime($a['startsAt']) < strtotime($b['endsAt'])
                            && strtotime($b['startsAt']) < strtotime($a['endsAt']);
                        self::assertFalse(
                            $overlap,
                            sprintf('Slots for %s=%s overlap: %s–%s vs %s–%s', $groupKey, $key, $a['startsAt'], $a['endsAt'], $b['startsAt'], $b['endsAt'])
                        );
                    }
                }
            }
        }//end foreach

    }//end assertConflictFree()

    /**
     * Extract every saved ConferenceSlot object.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedSlots(): array
    {
        return array_values(
            array_map(
                static fn (array $s): array => $s['object'],
                array_filter($this->savedObjects, static fn (array $s): bool => $s['schema'] === 'conference-slot')
            )
        );

    }//end savedSlots()

    /**
     * Extract every saved ConferenceSignup object.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedSignups(): array
    {
        return array_values(
            array_map(
                static fn (array $s): array => $s['object'],
                array_filter($this->savedObjects, static fn (array $s): bool => $s['schema'] === 'conference-signup')
            )
        );

    }//end savedSignups()

    /**
     * Conflict-free generation across two teachers and four signups: every
     * produced ConferenceSlot is conflict-free per teacher and per signup;
     * fully-satisfied signups are scheduled; a signup with an unmet
     * teacher-request is waitlisted and names the unmet teacher.
     *
     * @return void
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
     */
    public function testConflictFreeGenerationAcrossTeachersAndSignups(): void
    {
        $this->fixtures['teacher-availability'] = [
            [
                'id'                => 'ta-a',
                'conferenceRoundId' => 'round-1',
                'lifecycle'         => 'submitted',
                'teacherId'         => 'teacher-a',
                'blocks'            => [['startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:30:00+00:00']],
            ],
            [
                'id'                => 'ta-b',
                'conferenceRoundId' => 'round-1',
                'lifecycle'         => 'submitted',
                'teacherId'         => 'teacher-b',
                'blocks'            => [['startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:20:00+00:00']],
            ],
        ];

        $this->fixtures['conference-signup'] = [
            ['id' => 'signup-1', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'learnerId' => 'learner-1', 'requestedTeacherIds' => ['teacher-a', 'teacher-b'], 'createdAt' => '2026-08-01T08:00:00+00:00'],
            ['id' => 'signup-2', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'learnerId' => 'learner-2', 'requestedTeacherIds' => ['teacher-a'], 'createdAt' => '2026-08-01T08:01:00+00:00'],
            ['id' => 'signup-3', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'learnerId' => 'learner-3', 'requestedTeacherIds' => ['teacher-a', 'teacher-b'], 'createdAt' => '2026-08-01T08:02:00+00:00'],
            ['id' => 'signup-4', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'learnerId' => 'learner-4', 'requestedTeacherIds' => ['teacher-b'], 'createdAt' => '2026-08-01T08:03:00+00:00'],
        ];

        $this->fixtures['conference-slot'] = [];

        $round = ['id' => 'round-1', 'tenant_id' => 'tenant-a', 'slotDurationMinutes' => 10, 'bufferMinutes' => 0];

        $this->makeGenerator()->handle($this->makeEvent($round));

        $slots = $this->savedSlots();
        self::assertCount(4, $slots);
        $this->assertConflictFree($slots);

        $signups        = $this->savedSignups();
        $signupsById     = [];
        foreach ($signups as $s) {
            $signupsById[$s['id']] = $s;
        }

        self::assertCount(4, $signups);
        self::assertSame('scheduled', $signupsById['signup-1']['lifecycle']);
        self::assertSame('scheduled', $signupsById['signup-2']['lifecycle']);
        self::assertSame('waitlisted', $signupsById['signup-3']['lifecycle']);
        self::assertStringContainsString('teacher-b', $signupsById['signup-3']['notes']);
        self::assertSame('waitlisted', $signupsById['signup-4']['lifecycle']);
        self::assertStringContainsString('teacher-b', $signupsById['signup-4']['notes']);

        // signup-1 got both requested teachers, and its two slots do not overlap
        // each other (a parent cannot attend two conversations at once).
        $signup1Slots = array_values(array_filter($slots, static fn (array $s): bool => $s['signupId'] === 'signup-1'));
        self::assertCount(2, $signup1Slots);

    }//end testConflictFreeGenerationAcrossTeachersAndSignups()

    /**
     * `regenerate` after a signup cancellation frees exactly that signup's
     * minutes (the freed interval is reused by a still-waitlisted signup
     * requesting the same teacher) and leaves `confirmed` slots for other
     * signups untouched.
     *
     * @return void
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
     */
    public function testRegenerateAfterCancellationFreesExactlyThatSignupsMinutes(): void
    {
        $this->fixtures['teacher-availability'] = [
            [
                'id'                => 'ta-x',
                'conferenceRoundId' => 'round-2',
                'lifecycle'         => 'locked',
                'teacherId'         => 'teacher-x',
                'blocks'            => [['startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:20:00+00:00']],
            ],
        ];

        $this->fixtures['conference-signup'] = [
            ['id' => 'signup-A', 'conferenceRoundId' => 'round-2', 'lifecycle' => 'scheduled', 'learnerId' => 'learner-a', 'requestedTeacherIds' => ['teacher-x'], 'createdAt' => '2026-08-01T08:00:00+00:00'],
            ['id' => 'signup-B', 'conferenceRoundId' => 'round-2', 'lifecycle' => 'cancelled', 'learnerId' => 'learner-b', 'requestedTeacherIds' => ['teacher-x'], 'createdAt' => '2026-08-01T08:01:00+00:00'],
            ['id' => 'signup-C', 'conferenceRoundId' => 'round-2', 'lifecycle' => 'waitlisted', 'learnerId' => 'learner-c', 'requestedTeacherIds' => ['teacher-x'], 'createdAt' => '2026-08-01T08:02:00+00:00', 'notes' => 'Unmet teacher-request(s): teacher-x'],
        ];

        $this->fixtures['conference-slot'] = [
            ['id' => 'slot-A', 'conferenceRoundId' => 'round-2', 'teacherId' => 'teacher-x', 'signupId' => 'signup-A', 'startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:10:00+00:00', 'lifecycle' => 'confirmed'],
            ['id' => 'slot-B', 'conferenceRoundId' => 'round-2', 'teacherId' => 'teacher-x', 'signupId' => 'signup-B', 'startsAt' => '2026-09-01T09:10:00+00:00', 'endsAt' => '2026-09-01T09:20:00+00:00', 'lifecycle' => 'proposed'],
        ];

        $round = ['id' => 'round-2', 'tenant_id' => 'tenant-a', 'slotDurationMinutes' => 10, 'bufferMinutes' => 0];

        $this->makeGenerator()->handle($this->makeEvent($round, from: 'scheduled'));

        $slotSaves = array_values(array_filter($this->savedObjects, static fn (array $s): bool => $s['schema'] === 'conference-slot'));

        // slot-A (confirmed, belongs to a still-live signup) is never re-saved/touched.
        $touchedIds = array_map(static fn (array $s): string => $s['object']['id'] ?? '', $slotSaves);
        self::assertNotContains('slot-A', $touchedIds);

        // slot-B is cancelled (its minutes freed) because its signup (signup-B) is cancelled.
        $slotBSave = array_values(array_filter($slotSaves, static fn (array $s): bool => ($s['object']['id'] ?? '') === 'slot-B'));
        self::assertCount(1, $slotBSave);
        self::assertSame('cancelled', $slotBSave[0]['object']['lifecycle']);

        // The freed 09:10–09:20 interval is reused for the still-waitlisted signup-C.
        $newSlot = array_values(array_filter($slotSaves, static fn (array $s): bool => ($s['object']['signupId'] ?? '') === 'signup-C'));
        self::assertCount(1, $newSlot);
        self::assertSame('2026-09-01T09:10:00+00:00', $newSlot[0]['object']['startsAt']);
        self::assertSame('2026-09-01T09:20:00+00:00', $newSlot[0]['object']['endsAt']);

        $signupsById = [];
        foreach ($this->savedSignups() as $s) {
            $signupsById[$s['id']] = $s;
        }
        self::assertSame('scheduled', $signupsById['signup-C']['lifecycle']);

    }//end testRegenerateAfterCancellationFreesExactlyThatSignupsMinutes()

    /**
     * `regenerate` after a coordinator adds new TeacherAvailability schedules a
     * previously waitlisted signup, without disturbing the existing confirmed
     * slot for a different signup/teacher pairing.
     *
     * @return void
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
     */
    public function testRegenerateAfterAddingAvailabilitySchedulesWaitlistedSignup(): void
    {
        $this->fixtures['teacher-availability'] = [
            [
                'id'                => 'ta-y-original',
                'conferenceRoundId' => 'round-3',
                'lifecycle'         => 'locked',
                'teacherId'         => 'teacher-y',
                'blocks'            => [['startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:10:00+00:00']],
            ],
            // Coordinator added more availability ahead of the regenerate.
            [
                'id'                => 'ta-y-added',
                'conferenceRoundId' => 'round-3',
                'lifecycle'         => 'submitted',
                'teacherId'         => 'teacher-y',
                'blocks'            => [['startsAt' => '2026-09-01T09:10:00+00:00', 'endsAt' => '2026-09-01T09:20:00+00:00']],
            ],
        ];

        $this->fixtures['conference-signup'] = [
            ['id' => 'signup-D', 'conferenceRoundId' => 'round-3', 'lifecycle' => 'scheduled', 'learnerId' => 'learner-d', 'requestedTeacherIds' => ['teacher-y'], 'createdAt' => '2026-08-01T08:00:00+00:00'],
            ['id' => 'signup-E', 'conferenceRoundId' => 'round-3', 'lifecycle' => 'waitlisted', 'learnerId' => 'learner-e', 'requestedTeacherIds' => ['teacher-y'], 'createdAt' => '2026-08-01T08:01:00+00:00', 'notes' => 'Unmet teacher-request(s): teacher-y'],
        ];

        $this->fixtures['conference-slot'] = [
            ['id' => 'slot-D', 'conferenceRoundId' => 'round-3', 'teacherId' => 'teacher-y', 'signupId' => 'signup-D', 'startsAt' => '2026-09-01T09:00:00+00:00', 'endsAt' => '2026-09-01T09:10:00+00:00', 'lifecycle' => 'confirmed'],
        ];

        $round = ['id' => 'round-3', 'tenant_id' => 'tenant-a', 'slotDurationMinutes' => 10, 'bufferMinutes' => 0];

        $this->makeGenerator()->handle($this->makeEvent($round, from: 'scheduled'));

        $slots = $this->savedSlots();
        self::assertCount(1, $slots);
        self::assertSame('signup-E', $slots[0]['signupId']);
        self::assertSame('2026-09-01T09:10:00+00:00', $slots[0]['startsAt']);
        self::assertSame('2026-09-01T09:20:00+00:00', $slots[0]['endsAt']);

        $signupsById = [];
        foreach ($this->savedSignups() as $s) {
            $signupsById[$s['id']] = $s;
        }
        self::assertSame('scheduled', $signupsById['signup-E']['lifecycle']);

    }//end testRegenerateAfterAddingAvailabilitySchedulesWaitlistedSignup()

    /**
     * A non-ConferenceRound / non-`scheduled` transition is ignored (no queries, no writes).
     *
     * @return void
     */
    public function testIgnoresUnrelatedTransitions(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects(self::never())->method('findAll');
        $objectService->expects(self::never())->method('saveObject');

        $generator = new ConferenceScheduleGenerator($objectService, $this->createMock(LoggerInterface::class));

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'round-x']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('conference-round');
        $event->method('getTo')->willReturn('booking-closed');
        $event->method('getFrom')->willReturn('booking-open');

        $generator->handle($event);

    }//end testIgnoresUnrelatedTransitions()
}//end class
