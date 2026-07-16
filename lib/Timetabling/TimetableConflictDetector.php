<?php

/**
 * Scholiq Timetable Conflict Detector
 *
 * Detection, not optimisation (design.md "Conflict-detection algorithm").
 * Given a set of Session ids that just changed (created/updated, or
 * upserted by a `timetable-import` DataExchangeJob), scans the affected
 * date window — never a full-register scan — for:
 *   - teacher-double-booking : overlapping Sessions whose assigned teacher
 *     (substituteTeacherId when set, else Cohort.teacherIds) intersect.
 *   - room-double-booking    : overlapping Sessions sharing the same non-null roomId.
 *   - cohort-double-booking  : overlapping Sessions sharing the same cohortId.
 *   - learner-double-booking : overlapping Sessions across different cohorts
 *     whose Cohort.learnerIds intersect.
 *   - room-capacity-exceeded : a single Session with roomId + a linked
 *     Assessment whose cohort's learnerIds count exceeds Room.capacity.
 *   - exam-clash             : any overlap kind above where at least one of
 *     the two Sessions has a linked Assessment.
 *
 * Each finding is persisted as a `TimetableConflict` row, idempotent by
 * (sessionIds, kind) against any existing `open` row — a re-scan of an
 * unchanged window never spams duplicates. This class NEVER edits, cancels,
 * or reassigns a Session; it only ever creates `TimetableConflict` objects.
 *
 * ADR-031 legitimate exception: cross-object write bridge — a conflict is a
 * relationship BETWEEN two or more Session rows, not a property
 * materialisable on one row via a declared x-openregister-calculations
 * expression. The same exception class as ConferenceScheduleGenerator.
 *
 * Invoked by SessionConflictListener (OR-event-driven, on Session
 * create/update) and by TimetableImportHandler (batch, once a
 * timetable-import DataExchangeJob succeeds).
 *
 * @category Service
 * @package  OCA\Scholiq\Timetabling
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

namespace OCA\Scholiq\Timetabling;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Pairwise overlap scan over Session objects scoped to an affected date
 * window, writing idempotent TimetableConflict rows.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class TimetableConflictDetector
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const SESSION_SCHEMA    = 'session';
    private const COHORT_SCHEMA     = 'cohort';
    private const ROOM_SCHEMA       = 'room';
    private const ASSESSMENT_SCHEMA = 'assessment';
    private const TIMETABLE_CONFLICT_SCHEMA = 'timetable-conflict';

    /**
     * Lifecycle states excluded from the pairwise scan — a cancelled Session
     * no longer occupies its slot.
     *
     * @var string[]
     */
    private const EXCLUDED_LIFECYCLES = ['cancelled'];

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
     * Scan the affected window around the given Session ids for conflicts.
     *
     * @param array<int,array<string,mixed>> $sessions The freshly changed/imported Session objects (already-fetched data arrays).
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-scanning-an-unchanged-window-does-not-create-duplicate-conflicts
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-an-exam-session-exceeding-room-capacity-is-flagged-as-room-capacity-exceeded
     */
    public function scan(array $sessions): void
    {
        if (empty($sessions) === true) {
            return;
        }

        $tenantId = (string) ($sessions[0]['tenant_id'] ?? '');
        $buckets  = $this->collectDayBuckets(sessions: $sessions);

        $window = $this->loadWindow(sessions: $sessions, buckets: $buckets, tenantId: $tenantId);
        if (count($window) < 1) {
            return;
        }

        $cohortCache     = [];
        $roomCache       = [];
        $assessmentCache = [];

        $existingOpen = $this->loadExistingOpenKeys(tenantId: $tenantId);
        $toCreate     = [];

        $ids   = array_keys($window);
        $count = count($ids);

        for ($i = 0; $i < $count; $i++) {
            for ($j = ($i + 1); $j < $count; $j++) {
                $a = $window[$ids[$i]];
                $b = $window[$ids[$j]];

                if ($this->overlaps(a: $a, b: $b) === false) {
                    continue;
                }

                $this->evaluatePair(
                    a: $a,
                    b: $b,
                    tenantId: $tenantId,
                    cohortCache: $cohortCache,
                    assessmentCache: $assessmentCache,
                    existingOpen: $existingOpen,
                    toCreate: $toCreate
                );
            }
        }//end for

        foreach ($window as $session) {
            $this->evaluateCapacity(
                session: $session,
                tenantId: $tenantId,
                cohortCache: $cohortCache,
                roomCache: $roomCache,
                assessmentCache: $assessmentCache,
                existingOpen: $existingOpen,
                toCreate: $toCreate
            );
        }

        foreach ($toCreate as $conflict) {
            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::TIMETABLE_CONFLICT_SCHEMA,
                object: $conflict
            );
        }

        if (count($toCreate) > 0) {
            $this->logger->info(
                '[TimetableConflictDetector] {n} conflict(s) created for a window of {w} session(s).',
                ['n' => count($toCreate), 'w' => count($window)]
            );
        }

    }//end scan()

    /**
     * Evaluate the pairwise overlap kinds for one Session pair, appending any
     * finding to `$toCreate` (idempotent against `$existingOpen`).
     *
     * @param array<string,mixed>               $a               Session A.
     * @param array<string,mixed>               $b               Session B.
     * @param string                            $tenantId        Tenant scope.
     * @param array<string,array<string,mixed>> $cohortCache     Cohort cache, keyed by cohortId (mutated: entries added).
     * @param array<string,string|null>         $assessmentCache Session -> linked Assessment id cache (mutated: entries added).
     * @param array<string,true>                $existingOpen    Existing open (sessionIds,kind) keys.
     * @param array<int,array<string,mixed>>    $toCreate        Accumulator of TimetableConflict rows to persist (mutated: rows appended).
     *
     * @return void
     */
    private function evaluatePair(
        array $a,
        array $b,
        string $tenantId,
        array &$cohortCache,
        array &$assessmentCache,
        array $existingOpen,
        array &$toCreate
    ): void {
        $idA = (string) ($a['id'] ?? ($a['uuid'] ?? ''));
        $idB = (string) ($b['id'] ?? ($b['uuid'] ?? ''));
        if ($idA === '' || $idB === '' || $idA === $idB) {
            return;
        }

        $cohortA = $this->loadCohort(cohortId: (string) ($a['cohortId'] ?? ''), tenantId: $tenantId, cache: $cohortCache);
        $cohortB = $this->loadCohort(cohortId: (string) ($b['cohortId'] ?? ''), tenantId: $tenantId, cache: $cohortCache);

        $kinds = [];

        // Teacher-double-booking.
        $teachersA      = $this->assignedTeacherIds(session: $a, cohort: $cohortA);
        $teachersB      = $this->assignedTeacherIds(session: $b, cohort: $cohortB);
        $sharedTeachers = array_values(array_intersect($teachersA, $teachersB));
        if (count($sharedTeachers) > 0) {
            $kinds['teacher-double-booking'] = $sharedTeachers[0];
        }

        // Room-double-booking.
        $roomA = (string) ($a['roomId'] ?? '');
        $roomB = (string) ($b['roomId'] ?? '');
        if ($roomA !== '' && $roomA === $roomB) {
            $kinds['room-double-booking'] = $roomA;
        }

        // Cohort-double-booking.
        $cohortIdA = (string) ($a['cohortId'] ?? '');
        $cohortIdB = (string) ($b['cohortId'] ?? '');
        if ($cohortIdA !== '' && $cohortIdA === $cohortIdB) {
            $kinds['cohort-double-booking'] = $cohortIdA;
        }

        // Learner-double-booking (different cohorts, overlapping learnerIds).
        if ($cohortIdA !== '' && $cohortIdB !== '' && $cohortIdA !== $cohortIdB) {
            $learnersA      = $this->stringList(value: $cohortA['learnerIds'] ?? []);
            $learnersB      = $this->stringList(value: $cohortB['learnerIds'] ?? []);
            $sharedLearners = array_values(array_intersect($learnersA, $learnersB));
            if (count($sharedLearners) > 0) {
                $kinds['learner-double-booking'] = $sharedLearners[0];
            }
        }

        if (empty($kinds) === true) {
            return;
        }

        // Exam-clash: any overlap kind above, when at least one Session has a linked Assessment.
        $hasAssessment = $this->hasLinkedAssessment(sessionId: $idA, tenantId: $tenantId, cache: $assessmentCache)
            || $this->hasLinkedAssessment(sessionId: $idB, tenantId: $tenantId, cache: $assessmentCache);
        if ($hasAssessment === true) {
            $kinds['exam-clash'] = null;
        }

        foreach ($kinds as $kind => $scopeRef) {
            $this->queueConflict(
                sessionIds: [$idA, $idB],
                kind: (string) $kind,
                scopeRef: $scopeRef,
                tenantId: $tenantId,
                existingOpen: $existingOpen,
                toCreate: $toCreate
            );
        }

    }//end evaluatePair()

    /**
     * Evaluate the single-Session room-capacity-exceeded kind.
     *
     * @param array<string,mixed>               $session         The Session.
     * @param string                            $tenantId        Tenant scope.
     * @param array<string,array<string,mixed>> $cohortCache     Cohort cache (mutated: entries added).
     * @param array<string,array<string,mixed>> $roomCache       Room cache (mutated: entries added).
     * @param array<string,string|null>         $assessmentCache Assessment-link cache (mutated: entries added).
     * @param array<string,true>                $existingOpen    Existing open (sessionIds,kind) keys.
     * @param array<int,array<string,mixed>>    $toCreate        Accumulator of rows to persist (mutated: rows appended).
     *
     * @return void
     */
    private function evaluateCapacity(
        array $session,
        string $tenantId,
        array &$cohortCache,
        array &$roomCache,
        array &$assessmentCache,
        array $existingOpen,
        array &$toCreate
    ): void {
        $sessionId = (string) ($session['id'] ?? ($session['uuid'] ?? ''));
        $roomId    = (string) ($session['roomId'] ?? '');
        if ($sessionId === '' || $roomId === '') {
            return;
        }

        if ($this->hasLinkedAssessment(sessionId: $sessionId, tenantId: $tenantId, cache: $assessmentCache) === false) {
            return;
        }

        $room = $this->loadRoom(roomId: $roomId, tenantId: $tenantId, cache: $roomCache);
        if ($room === null) {
            return;
        }

        $cohort         = $this->loadCohort(cohortId: (string) ($session['cohortId'] ?? ''), tenantId: $tenantId, cache: $cohortCache);
        $candidateCount = count($this->stringList(value: $cohort['learnerIds'] ?? []));
        $capacity       = (int) ($room['capacity'] ?? 0);

        if ($capacity <= 0 || $candidateCount <= $capacity) {
            return;
        }

        $this->queueConflict(
            sessionIds: [$sessionId],
            kind: 'room-capacity-exceeded',
            scopeRef: $roomId,
            tenantId: $tenantId,
            existingOpen: $existingOpen,
            toCreate: $toCreate
        );

    }//end evaluateCapacity()

    /**
     * Queue a TimetableConflict row for creation unless an `open` row already
     * exists for the same (sessionIds, kind) pair — idempotent by design.
     *
     * @param array<int,string>              $sessionIds   Session UUIDs involved.
     * @param string                         $kind         Conflict kind.
     * @param string|null                    $scopeRef     Shared identity in conflict, or null.
     * @param string                         $tenantId     Tenant scope.
     * @param array<string,true>             $existingOpen Existing open (sessionIds,kind) keys.
     * @param array<int,array<string,mixed>> $toCreate     Accumulator of rows to persist (mutated: rows appended).
     *
     * @return void
     */
    private function queueConflict(
        array $sessionIds,
        string $kind,
        ?string $scopeRef,
        string $tenantId,
        array $existingOpen,
        array &$toCreate
    ): void {
        $key = $this->conflictKey(sessionIds: $sessionIds, kind: $kind);
        if (isset($existingOpen[$key]) === true) {
            return;
        }

        // Also skip a duplicate within the same scan pass.
        foreach ($toCreate as $queued) {
            if ($this->conflictKey(sessionIds: $queued['sessionIds'], kind: $queued['kind']) === $key) {
                return;
            }
        }

        $toCreate[] = [
            'kind'           => $kind,
            'sessionIds'     => $sessionIds,
            'scopeRef'       => $scopeRef,
            'severity'       => 'error',
            'detectedAt'     => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'resolutionNote' => null,
            'tenant_id'      => $tenantId,
            'lifecycle'      => 'open',
        ];

    }//end queueConflict()

    /**
     * Build the idempotency key for a (sessionIds, kind) pair — order-independent.
     *
     * @param array<int,string> $sessionIds Session UUIDs.
     * @param string            $kind       Conflict kind.
     *
     * @return string The composite key.
     */
    private function conflictKey(array $sessionIds, string $kind): string
    {
        $sorted = $sessionIds;
        sort($sorted);
        return $kind.'|'.implode(',', $sorted);

    }//end conflictKey()

    /**
     * Load every `open` TimetableConflict's (sessionIds, kind) key for the tenant.
     *
     * @param string $tenantId Tenant scope.
     *
     * @return array<string,true> Map of composite key -> true.
     */
    private function loadExistingOpenKeys(string $tenantId): array
    {
        $filters = ['lifecycle' => 'open'];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::TIMETABLE_CONFLICT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 5000,
            ]
        );

        $keys = [];
        foreach ($results as $row) {
            $data       = $this->normalise(row: $row);
            $sessionIds = $data['sessionIds'] ?? [];
            if (is_array($sessionIds) === false) {
                continue;
            }

            $keys[$this->conflictKey(sessionIds: $this->stringList(value: $sessionIds), kind: (string) ($data['kind'] ?? ''))] = true;
        }

        return $keys;

    }//end loadExistingOpenKeys()

    /**
     * Whether a Session has at least one linked Assessment (Assessment.sessionId === this Session).
     *
     * @param string                    $sessionId Session UUID.
     * @param string                    $tenantId  Tenant scope.
     * @param array<string,string|null> $cache     Per-scan cache, keyed by sessionId (mutated: entries added).
     *
     * @return bool True when a linked Assessment exists.
     */
    private function hasLinkedAssessment(string $sessionId, string $tenantId, array &$cache): bool
    {
        if ($sessionId === '') {
            return false;
        }

        if (array_key_exists($sessionId, $cache) === true) {
            return $cache[$sessionId] !== null;
        }

        $filters = ['sessionId' => $sessionId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ASSESSMENT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            $cache[$sessionId] = null;
            return false;
        }

        $assessment        = $this->normalise(row: $results[0]);
        $cache[$sessionId] = (string) ($assessment['id'] ?? ($assessment['uuid'] ?? 'unknown'));

        return true;

    }//end hasLinkedAssessment()

    /**
     * Resolve the "assigned teacher" identity set for a Session: the
     * substitute teacher once assigned, else the Cohort's teacherIds.
     *
     * @param array<string,mixed>      $session Session data.
     * @param array<string,mixed>|null $cohort  The Session's Cohort data, or null.
     *
     * @return array<int,string> The assigned teacher Nextcloud user ids.
     */
    private function assignedTeacherIds(array $session, ?array $cohort): array
    {
        $substituteId = (string) ($session['substituteTeacherId'] ?? '');
        if ($substituteId !== '') {
            return [$substituteId];
        }

        if ($cohort === null) {
            return [];
        }

        return $this->stringList(value: $cohort['teacherIds'] ?? []);

    }//end assignedTeacherIds()

    /**
     * Whether two Sessions' [startsAt, endsAt) intervals overlap.
     *
     * @param array<string,mixed> $a Session A.
     * @param array<string,mixed> $b Session B.
     *
     * @return bool True when the intervals overlap.
     */
    private function overlaps(array $a, array $b): bool
    {
        $aStart = strtotime((string) ($a['startsAt'] ?? ''));
        $aEnd   = strtotime((string) ($a['endsAt'] ?? ''));
        $bStart = strtotime((string) ($b['startsAt'] ?? ''));
        $bEnd   = strtotime((string) ($b['endsAt'] ?? ''));

        if ($aStart === false || $aEnd === false || $bStart === false || $bEnd === false) {
            return false;
        }

        return ($aStart < $bEnd && $bStart < $aEnd);

    }//end overlaps()

    /**
     * Collect the distinct `sessionDayBucket` values (or a computed
     * fallback derived from `startsAt`'s calendar date) present in the given
     * Sessions.
     *
     * @param array<int,array<string,mixed>> $sessions Session data arrays.
     *
     * @return array<int,int|string> Distinct day-bucket values.
     */
    private function collectDayBuckets(array $sessions): array
    {
        $buckets = [];
        foreach ($sessions as $session) {
            if (isset($session['sessionDayBucket']) === true) {
                $buckets[$session['sessionDayBucket']] = true;
                continue;
            }

            $startsAt = (string) ($session['startsAt'] ?? '');
            $ts       = strtotime($startsAt);
            if ($ts === false) {
                continue;
            }

            $buckets[gmdate('Y-m-d', $ts)] = true;
        }

        return array_keys($buckets);

    }//end collectDayBuckets()

    /**
     * Load the scan window: every non-cancelled Session sharing one of the
     * given day buckets (same tenant), merged with the input Sessions
     * themselves (in case a freshly-saved row has not yet materialised its
     * `sessionDayBucket`) — never a full-register scan.
     *
     * @param array<int,array<string,mixed>> $sessions The input Session data arrays.
     * @param array<int,int|string>          $buckets  Distinct day-bucket values.
     * @param string                         $tenantId Tenant scope.
     *
     * @return array<string,array<string,mixed>> Window sessions keyed by id, lifecycle-filtered.
     */
    private function loadWindow(array $sessions, array $buckets, string $tenantId): array
    {
        $window = [];

        foreach ($sessions as $session) {
            $id = (string) ($session['id'] ?? ($session['uuid'] ?? ''));
            if ($id === '' || in_array(($session['lifecycle'] ?? ''), self::EXCLUDED_LIFECYCLES, true) === true) {
                continue;
            }

            $window[$id] = $session;
        }

        foreach ($buckets as $bucket) {
            $filters = ['sessionDayBucket' => $bucket];
            if ($tenantId !== '') {
                $filters['tenant_id'] = $tenantId;
            }

            $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::SESSION_SCHEMA,
                    'filters'  => $filters,
                    'limit'    => 2000,
                ]
            );

            foreach ($results as $row) {
                $data = $this->normalise(row: $row);
                $id   = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
                if ($id === '' || in_array(($data['lifecycle'] ?? ''), self::EXCLUDED_LIFECYCLES, true) === true) {
                    continue;
                }

                $window[$id] = $data;
            }
        }//end foreach

        return $window;

    }//end loadWindow()

    /**
     * Load a Cohort by UUID, cached per scan.
     *
     * @param string                            $cohortId Cohort UUID.
     * @param string                            $tenantId Tenant scope.
     * @param array<string,array<string,mixed>> $cache    Per-scan cache (mutated: entries added).
     *
     * @return array<string,mixed>|null The cohort data, or null.
     */
    private function loadCohort(string $cohortId, string $tenantId, array &$cache): ?array
    {
        if ($cohortId === '') {
            return null;
        }

        if (array_key_exists($cohortId, $cache) === true) {
            return $cache[$cohortId];
        }

        $filters = ['id' => $cohortId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COHORT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        $cohort = null;
        if (empty($results) === false) {
            $cohort = $this->normalise(row: $results[0]);
        }

        $cache[$cohortId] = $cohort;

        return $cohort;

    }//end loadCohort()

    /**
     * Load a Room by UUID, cached per scan.
     *
     * @param string                            $roomId   Room UUID.
     * @param string                            $tenantId Tenant scope.
     * @param array<string,array<string,mixed>> $cache    Per-scan cache (mutated: entries added).
     *
     * @return array<string,mixed>|null The room data, or null.
     */
    private function loadRoom(string $roomId, string $tenantId, array &$cache): ?array
    {
        if ($roomId === '') {
            return null;
        }

        if (array_key_exists($roomId, $cache) === true) {
            return $cache[$roomId];
        }

        $filters = ['id' => $roomId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ROOM_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        $room = null;
        if (empty($results) === false) {
            $room = $this->normalise(row: $results[0]);
        }

        $cache[$roomId] = $room;

        return $room;

    }//end loadRoom()

    /**
     * Coerce a schema array-of-strings value into a de-duplicated string list.
     *
     * @param mixed $value The raw property value.
     *
     * @return array<int,string> The string list.
     */
    private function stringList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) === true && $item !== '') {
                $out[$item] = true;
            }
        }

        return array_keys($out);

    }//end stringList()

    /**
     * Normalise an ObjectService row to a plain array.
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
