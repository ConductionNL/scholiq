<?php

/**
 * Scholiq Conference Schedule Generator
 *
 * IEventListener for ConferenceRound lifecycle → `scheduled` (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=conference-round,
 * to=scheduled — fired by both the `generate` transition, booking-closed →
 * scheduled, and the idempotent `regenerate` self-transition, scheduled →
 * scheduled). Runs the greedy, submission-order, earliest-fit conflict-free
 * slot-assignment algorithm described in
 * openspec/changes/parent-evening-planner/design.md over submitted/locked
 * TeacherAvailability and submitted/waitlisted ConferenceSignup rows for the
 * round, and writes ConferenceSlot objects via ObjectService::saveObject.
 *
 * Algorithm (design.md):
 *   1. sliceAvailability() cuts each teacher's declared free blocks into a
 *      chronologically ordered FIFO queue of slotDurationMinutes candidates,
 *      bufferMinutes apart. Pure function, no side effects.
 *   2. Candidate slots overlapping an already-`confirmed` ConferenceSlot for
 *      that teacher are excluded up front — confirmed appointments are
 *      pinned across a regenerate.
 *   3. Signups (submitted + waitlisted, so a regenerate re-attempts a
 *      previously waitlisted signup) are walked oldest-`createdAt`-first.
 *      For each requestedTeacherIds entry already satisfied by an existing
 *      non-cancelled ConferenceSlot, nothing is re-created (idempotency). For
 *      the rest, the teacher's queue is popped until a slot is found that
 *      does not overlap any interval already assigned to *this signup* in
 *      this pass (across any teacher) — a parent must never receive two
 *      simultaneous conversations. A slot rejected for overlap is discarded
 *      permanently (design.md "Complexity": each slot visited at most once
 *      per pass), not requeued for a later signup.
 *   4. A signup with every requestedTeacherIds entry satisfied (existing or
 *      newly created) is written `scheduled`; a signup with any unmet
 *      teacher-request is written `waitlisted`, with the unmet teacher ids
 *      appended to `notes`.
 *
 * ADR-031 legitimate exception: cross-object write bridge — schedule
 * generation is a genuine algorithm (allocation over two collections), not a
 * declarative schema expression. Matches ExcuseApprovalHandler's shape.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Generates a conflict-free ConferenceSlot schedule from submitted signups
 * and teacher availability when a ConferenceRound transitions to `scheduled`.
 *
 * @implements IEventListener<Event>
 */
class ConferenceScheduleGenerator implements IEventListener
{

    private const SCHOLIQ_REGISTER            = 'scholiq';
    private const CONFERENCE_ROUND_SCHEMA     = 'conference-round';
    private const TEACHER_AVAILABILITY_SCHEMA = 'teacher-availability';
    private const CONFERENCE_SIGNUP_SCHEMA    = 'conference-signup';
    private const CONFERENCE_SLOT_SCHEMA      = 'conference-slot';

    /**
     * Non-cancelled ConferenceSlot lifecycle states — a slot in one of these
     * states already occupies the teacher's/signup's time and must not be
     * duplicated or re-assigned.
     *
     * @var string[]
     */
    private const ACTIVE_SLOT_STATES = ['proposed', 'confirmed', 'completed'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::CONFERENCE_ROUND_SCHEMA || $event->getTo() !== 'scheduled') {
            return;
        }

        $this->generateForRound(round: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Run the greedy conflict-free generation pass for one ConferenceRound.
     *
     * @param array<string,mixed> $round The ConferenceRound property array.
     *
     * @return void
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
     */
    private function generateForRound(array $round): void
    {
        $roundId = $round['id'] ?? ($round['uuid'] ?? '');
        if ($roundId === '') {
            $this->logger->warning('[ConferenceScheduleGenerator] ConferenceRound has no id; aborting generation.');
            return;
        }

        $tenantId            = $round['tenant_id'] ?? '';
        $slotDurationMinutes = (int) ($round['slotDurationMinutes'] ?? 10);
        $bufferMinutes       = (int) ($round['bufferMinutes'] ?? 0);

        $availabilities = array_merge(
            $this->fetchByRoundAndLifecycle(schema: self::TEACHER_AVAILABILITY_SCHEMA, roundId: $roundId, lifecycle: 'submitted'),
            $this->fetchByRoundAndLifecycle(schema: self::TEACHER_AVAILABILITY_SCHEMA, roundId: $roundId, lifecycle: 'locked'),
        );

        $existingSlots = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::CONFERENCE_SLOT_SCHEMA,
                'filters'  => ['conferenceRoundId' => $roundId],
                'limit'    => 5000,
            ]
        );
        $existingSlots = array_map([$this, 'normalise'], $existingSlots);

        // Step 4 (regenerate) — a cancelled signup frees its ConferenceSlots'
        // minutes back to the teacher's queue: cancel any still-active slot
        // belonging to a cancelled signup so its interval is not re-treated as
        // consumed below. A confirmed slot belonging to a *still-live* signup
        // is untouched (design.md "regenerate MUST NOT re-shuffle confirmed
        // ConferenceSlots").
        $cancelledSignupIds = array_map(
            function ($signup): string {
                $signup = $this->normalise(row: $signup);
                return (string) ($signup['id'] ?? ($signup['uuid'] ?? ''));
            },
            $this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'cancelled')
        );
        $cancelledSignupIds = array_flip(array_filter($cancelledSignupIds, static fn (string $id): bool => $id !== ''));

        // Confirmed slots are pinned — their minutes are excluded from re-slicing.
        $confirmedByTeacher = [];
        // Any non-cancelled slot for a (signupId, teacherId) pair means that
        // teacher-request is already satisfied — never duplicate it.
        $activeSlotKeys = [];
        // Every non-cancelled interval already assigned to a signup (any
        // teacher) — the overlap guard for newly assigned slots in this pass.
        $assignedIntervalsBySignup = [];

        foreach ($existingSlots as $slot) {
            $status    = $slot['lifecycle'] ?? '';
            $teacherId = $slot['teacherId'] ?? '';
            $signupId  = $slot['signupId'] ?? '';

            if (in_array($status, self::ACTIVE_SLOT_STATES, true) === false) {
                continue;
            }

            if ($signupId !== '' && isset($cancelledSignupIds[$signupId]) === true) {
                $freedSlot = $slot;
                $freedSlot['lifecycle'] = 'cancelled';
                $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::CONFERENCE_SLOT_SCHEMA, object: $freedSlot);
                continue;
            }

            $interval = ['startsAt' => $slot['startsAt'] ?? '', 'endsAt' => $slot['endsAt'] ?? ''];

            if ($status === 'confirmed') {
                $confirmedByTeacher[$teacherId][] = $interval;
            }

            if ($signupId !== '') {
                $activeSlotKeys[$signupId.'|'.$teacherId] = true;
                $assignedIntervalsBySignup[$signupId][]   = $interval;
            }
        }//end foreach

        // Step 1 — slice availability into a per-teacher slot queue, excluding
        // minutes already consumed by a confirmed slot for that teacher.
        $queues = [];
        foreach ($availabilities as $availability) {
            $availability = $this->normalise(row: $availability);
            $teacherId    = $availability['teacherId'] ?? '';
            $blocks       = $availability['blocks'] ?? [];

            $sliced = self::sliceAvailability(blocks: $blocks, slotDurationMinutes: $slotDurationMinutes, bufferMinutes: $bufferMinutes);

            $confirmed = $confirmedByTeacher[$teacherId] ?? [];
            $free      = array_values(
                array_filter(
                    $sliced,
                    fn (array $candidate): bool => $this->overlapsAny(candidate: $candidate, intervals: $confirmed) === false
                )
            );

            if (isset($queues[$teacherId]) === false) {
                $queues[$teacherId] = [];
            }

            $queues[$teacherId] = array_merge($queues[$teacherId], $free);
        }//end foreach

        foreach ($queues as $teacherId => $queue) {
            usort($queue, static fn (array $a, array $b): int => strcmp((string) $a['startsAt'], (string) $b['startsAt']));
            $queues[$teacherId] = $queue;
        }

        // Step 2 — walk submitted + waitlisted signups in submission order.
        $signups = array_merge(
            $this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'submitted'),
            $this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'waitlisted'),
        );
        $signups = array_map([$this, 'normalise'], $signups);
        usort($signups, static fn (array $a, array $b): int => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));

        $newSlots        = [];
        $signupSaves     = [];
        $scheduledCount  = 0;
        $waitlistedCount = 0;

        foreach ($signups as $signup) {
            $signupId            = $signup['id'] ?? ($signup['uuid'] ?? '');
            $requestedTeacherIds = $signup['requestedTeacherIds'] ?? [];
            $assignedIntervals   = $assignedIntervalsBySignup[$signupId] ?? [];
            $unmetTeacherIds     = [];

            foreach ($requestedTeacherIds as $teacherId) {
                if (isset($activeSlotKeys[$signupId.'|'.$teacherId]) === true) {
                    // Already satisfied by an existing non-cancelled slot — idempotent no-op.
                    continue;
                }

                $queue = $queues[$teacherId] ?? [];
                $slot  = $this->popNextNonOverlapping(queue: $queue, blocked: $assignedIntervals);
                $queues[$teacherId] = $queue;

                if ($slot === null) {
                    $unmetTeacherIds[] = $teacherId;
                    continue;
                }

                $newSlots[] = [
                    'conferenceRoundId' => $roundId,
                    'teacherId'         => $teacherId,
                    'learnerId'         => $signup['learnerId'] ?? '',
                    'learnerRef'        => $signup['learnerRef'] ?? null,
                    'signupId'          => $signupId,
                    'startsAt'          => $slot['startsAt'],
                    'endsAt'            => $slot['endsAt'],
                    'location'          => null,
                    'tenant_id'         => $tenantId,
                    'lifecycle'         => 'proposed',
                ];

                $assignedIntervals[] = $slot;
                $activeSlotKeys[$signupId.'|'.$teacherId] = true;
            }//end foreach

            $updatedSignup = $signup;
            if (count($unmetTeacherIds) === 0) {
                $updatedSignup['lifecycle'] = 'scheduled';
                $scheduledCount++;
            } else {
                $updatedSignup['lifecycle'] = 'waitlisted';
                $existingNotes = (string) ($signup['notes'] ?? '');
                $reason        = 'Unmet teacher-request(s): '.implode(', ', $unmetTeacherIds);
                if ($existingNotes === '') {
                    $updatedSignup['notes'] = $reason;
                } else {
                    $updatedSignup['notes'] = $existingNotes.' | '.$reason;
                }

                $waitlistedCount++;
            }

            $signupSaves[] = $updatedSignup;
        }//end foreach

        foreach ($newSlots as $slot) {
            $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::CONFERENCE_SLOT_SCHEMA, object: $slot);
        }

        foreach ($signupSaves as $signup) {
            $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::CONFERENCE_SIGNUP_SCHEMA, object: $signup);
        }

        $this->logger->info(
            '[ConferenceScheduleGenerator] Round {round}: {slots} slot(s) written, {scheduled} signup(s) scheduled, {waitlisted} waitlisted.',
            ['round' => $roundId, 'slots' => count($newSlots), 'scheduled' => $scheduledCount, 'waitlisted' => $waitlistedCount]
        );

    }//end generateForRound()

    /**
     * Step 1 — slice a teacher's declared free blocks into a chronologically
     * ordered list of candidate `{startsAt, endsAt}` slots, slotDurationMinutes
     * long with a bufferMinutes gap between consecutive slots. Pure function,
     * no side effects, deterministic for the same input (design.md "Step 1").
     *
     * @param array<int,array<string,mixed>> $blocks              Free blocks: [{startsAt, endsAt}, ...].
     * @param int                            $slotDurationMinutes Length of one slot in minutes.
     * @param int                            $bufferMinutes       Gap between consecutive slots in minutes.
     *
     * @return array<int,array{startsAt:string,endsAt:string}> Candidate slots, in chronological order.
     *
     * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-a-conference-round-declares-its-scope-slot-duration-and-buffer-time
     */
    public static function sliceAvailability(array $blocks, int $slotDurationMinutes, int $bufferMinutes): array
    {
        if ($slotDurationMinutes <= 0) {
            return [];
        }

        $slots = [];

        foreach ($blocks as $block) {
            $startRaw = $block['startsAt'] ?? null;
            $endRaw   = $block['endsAt'] ?? null;

            if ($startRaw === null || $endRaw === null) {
                continue;
            }

            try {
                $cursor    = new DateTimeImmutable((string) $startRaw, new DateTimeZone('UTC'));
                $blockEnds = new DateTimeImmutable((string) $endRaw, new DateTimeZone('UTC'));
            } catch (\Exception) {
                continue;
            }

            while (true) {
                $slotEnd = $cursor->modify('+'.$slotDurationMinutes.' minutes');
                if ($slotEnd > $blockEnds) {
                    break;
                }

                $slots[] = [
                    'startsAt' => $cursor->format(DATE_ATOM),
                    'endsAt'   => $slotEnd->format(DATE_ATOM),
                ];

                $cursor = $slotEnd->modify('+'.$bufferMinutes.' minutes');
            }
        }//end foreach

        return $slots;

    }//end sliceAvailability()

    /**
     * Pop candidate slots from the front of a teacher's queue until one is
     * found that does not overlap any interval in `$blocked`, or the queue is
     * exhausted. A rejected-for-overlap candidate is discarded permanently
     * (design.md "Complexity" — visited at most once per pass), not requeued.
     *
     * @param array<int,array{startsAt:string,endsAt:string}> $queue   The teacher's candidate queue (mutated: consumed from the front).
     * @param array<int,array{startsAt:string,endsAt:string}> $blocked Intervals already assigned to the current signup.
     *
     * @return array{startsAt:string,endsAt:string}|null The first non-overlapping slot, or null if the queue is exhausted.
     */
    private function popNextNonOverlapping(array &$queue, array $blocked): ?array
    {
        while (count($queue) > 0) {
            $candidate = array_shift($queue);
            if ($this->overlapsAny(candidate: $candidate, intervals: $blocked) === false) {
                return $candidate;
            }
        }

        return null;

    }//end popNextNonOverlapping()

    /**
     * Whether a candidate interval overlaps any interval in a set.
     *
     * Half-open interval overlap: [a.start, a.end) intersects [b.start, b.end)
     * iff a.start < b.end AND b.start < a.end.
     *
     * @param array{startsAt:string,endsAt:string}            $candidate The candidate interval.
     * @param array<int,array{startsAt:string,endsAt:string}> $intervals The intervals to check against.
     *
     * @return bool True if the candidate overlaps at least one interval.
     */
    private function overlapsAny(array $candidate, array $intervals): bool
    {
        $candidateStart = strtotime((string) $candidate['startsAt']);
        $candidateEnd   = strtotime((string) $candidate['endsAt']);

        foreach ($intervals as $interval) {
            $intervalStart = strtotime((string) $interval['startsAt']);
            $intervalEnd   = strtotime((string) $interval['endsAt']);

            if ($candidateStart === false || $candidateEnd === false || $intervalStart === false || $intervalEnd === false) {
                continue;
            }

            if ($candidateStart < $intervalEnd && $intervalStart < $candidateEnd) {
                return true;
            }
        }

        return false;

    }//end overlapsAny()

    /**
     * Fetch OR objects for a schema filtered to a ConferenceRound + a single
     * lifecycle state.
     *
     * @param string $schema    OR schema slug.
     * @param string $roundId   ConferenceRound UUID.
     * @param string $lifecycle Lifecycle state to filter on.
     *
     * @return array<int,mixed> Raw rows as returned by ObjectService::findAll().
     */
    private function fetchByRoundAndLifecycle(string $schema, string $roundId, string $lifecycle): array
    {
        return $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => [
                    'conferenceRoundId' => $roundId,
                    'lifecycle'         => $lifecycle,
                ],
                'limit'    => 2000,
            ]
        );

    }//end fetchByRoundAndLifecycle()

    /**
     * Normalise an ObjectService row to a plain array, whether it was
     * returned as an array already or as an object exposing jsonSerialize().
     *
     * @param mixed $row Raw row from ObjectService::findAll().
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        return $row->jsonSerialize();

    }//end normalise()
}//end class
