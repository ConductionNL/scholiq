<?php

/**
 * Scholiq Personal Timetable Controller
 *
 * Read-only personal timetable: returns the signed-in caller's own scheduled
 * `Session` objects for a time window. The caller's sessions are resolved by
 * first determining the cohorts the caller belongs to — as a teacher via
 * `Cohort.teacherIds`, and as a learner via `Cohort.learnerIds` and/or
 * `Enrolment.cohortId` — then returning the `Session` objects whose `cohortId`
 * is one of those cohorts and whose `startsAt`/`endsAt` overlap the requested
 * window, ordered by `startsAt`.
 *
 * All reads go through OpenRegister's `ObjectService` so RBAC and multitenancy
 * scope the result (ADR-022): the caller never receives a session for a cohort
 * they do not belong to, and a caller with no cohorts receives an empty
 * timetable (HTTP 200), never an error. This controller introduces NO new
 * schema or storage and NEVER creates or mutates an object — it only reads the
 * existing `Session`, `Cohort`, `Room`, and `Enrolment` objects.
 *
 * timetabling-and-substitution additionally projects each Session's `roomId`
 * (with resolved `Room` name/capacity/facilities when set), `lifecycle`,
 * `substituteTeacherId`, `changeReasonKind`, and `changeReason`, plus a
 * same-day `changes` list (Sessions whose `cancel`/`substitute-teacher`
 * transition — server-stamped onto `changedAt` — occurred today, regardless
 * of the requested window) — the "dagrooster" surface. Still read-only: no
 * new write endpoint, no new schema.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Personal timetable read surface over existing Session/Cohort/Enrolment objects.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-the-timetable-is-a-read-surface-only-over-existing-objects
 */
class TimetableController extends Controller
{
    /**
     * OpenRegister register slug that owns the Scholiq schemas.
     *
     * @var string
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param IRequest        $request       HTTP request.
     * @param IUserSession    $userSession   Current user session.
     * @param ObjectService   $objectService OR object query service (RBAC-scoped).
     * @param LoggerInterface $logger        Application logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the caller's own sessions for a time window.
     *
     * The window defaults to the current ISO week (Monday 00:00 → Sunday
     * 23:59:59, UTC) when `from`/`to` are not supplied. Cohort membership is
     * resolved from the caller's Nextcloud user id against `Cohort.teacherIds`,
     * `Cohort.learnerIds` and `Enrolment.cohortId`. A caller with no cohorts
     * receives an empty list (HTTP 200).
     *
     * @param string|null $from Inclusive ISO 8601 window start (optional).
     * @param string|null $to   Exclusive ISO 8601 window end (optional).
     *
     * @return JSONResponse The ordered session list plus the resolved window.
     *
     * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function mine(?string $from=null, ?string $to=null): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        [$windowFrom, $windowTo] = $this->resolveWindow(from: $from, to: $to);

        $cohortIds = $this->resolveCallerCohortIds(uid: $uid);

        // A caller with no cohorts gets an empty timetable — not an error.
        if (empty($cohortIds) === true) {
            $this->logger->debug(
                '[TimetableController] No cohorts resolved for {uid}; returning empty timetable.',
                ['uid' => $uid, 'from' => $windowFrom, 'to' => $windowTo]
            );
            return new JSONResponse(
                data: ['sessions' => [], 'from' => $windowFrom, 'to' => $windowTo, 'changes' => []],
                statusCode: Http::STATUS_OK
            );
        }

        $rawSessions = $this->loadRawSessionsForCohorts(cohortIds: $cohortIds);
        $roomCache   = $this->preloadRooms(sessions: $rawSessions);

        $sessions = $this->projectWindowedSessions(rawSessions: $rawSessions, windowFrom: $windowFrom, windowTo: $windowTo, roomCache: $roomCache);
        $changes  = $this->projectTodaysChanges(rawSessions: $rawSessions, roomCache: $roomCache);

        return new JSONResponse(
            data: ['sessions' => $sessions, 'from' => $windowFrom, 'to' => $windowTo, 'changes' => $changes],
            statusCode: Http::STATUS_OK
        );
    }//end mine()

    /**
     * Resolve the requested window, defaulting to the current ISO week (UTC).
     *
     * @param string|null $from Requested inclusive window start.
     * @param string|null $to   Requested exclusive window end.
     *
     * @return array{0:string,1:string} The [from, to] ISO 8601 pair.
     */
    private function resolveWindow(?string $from, ?string $to): array
    {
        $tz = new DateTimeZone('UTC');

        $start = null;
        if ($from !== null && trim($from) !== '') {
            try {
                $start = new DateTimeImmutable($from, $tz);
            } catch (Throwable $e) {
                $this->logger->warning('[TimetableController] Ignoring unparseable "from"; using default window.', ['from' => $from]);
                $start = null;
            }
        }

        $end = null;
        if ($to !== null && trim($to) !== '') {
            try {
                $end = new DateTimeImmutable($to, $tz);
            } catch (Throwable $e) {
                $this->logger->warning('[TimetableController] Ignoring unparseable "to"; using default window.', ['to' => $to]);
                $end = null;
            }
        }

        if ($start === null) {
            // Monday 00:00:00 of the current week.
            $now   = new DateTimeImmutable('now', $tz);
            $start = $now->modify('monday this week')->setTime(0, 0, 0);
        }

        if ($end === null) {
            // One week after the resolved start (exclusive end).
            $end = $start->modify('+7 days');
        }

        return [$start->format(DateTimeInterface::ATOM), $end->format(DateTimeInterface::ATOM)];
    }//end resolveWindow()

    /**
     * Resolve the set of cohort UUIDs the caller belongs to.
     *
     * Teacher membership: the caller's uid appears in `Cohort.teacherIds`.
     * Learner membership: the caller's uid appears in `Cohort.learnerIds`, or
     * the caller has an `Enrolment` whose `learnerId` is the caller and whose
     * `cohortId` is set. All reads are RBAC/multitenancy-scoped by ObjectService.
     *
     * @param string $uid The caller's Nextcloud user id.
     *
     * @return array<int,string> The unique cohort UUIDs (may be empty).
     */
    private function resolveCallerCohortIds(string $uid): array
    {
        $cohortIds = [];

        // Cohorts where the caller is a teacher or a listed learner. teacherIds
        // and learnerIds are arrays, so membership is filtered in PHP over the
        // RBAC-scoped cohort set rather than via an equality filter.
        $cohorts = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'cohort',
            ]
        );

        foreach ($cohorts as $row) {
            $cohort     = $this->toArray(row: $row);
            $teacherIds = $this->toStringList(value: ($cohort['teacherIds'] ?? []));
            $learnerIds = $this->toStringList(value: ($cohort['learnerIds'] ?? []));

            if (in_array($uid, $teacherIds, true) === true || in_array($uid, $learnerIds, true) === true) {
                $cohortId = (string) ($cohort['id'] ?? ($cohort['uuid'] ?? ''));
                if ($cohortId !== '') {
                    $cohortIds[$cohortId] = true;
                }
            }
        }

        // Cohorts reached through the caller's own enrolments.
        $enrolments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'enrolment',
                'filters'  => ['learnerId' => $uid],
            ]
        );

        foreach ($enrolments as $row) {
            $enrolment = $this->toArray(row: $row);
            // Defensive: the RBAC-scoped filter should already guarantee this,
            // but never trust a mismatched learnerId to reach another's cohort.
            if ((string) ($enrolment['learnerId'] ?? '') !== $uid) {
                continue;
            }

            $cohortId = (string) ($enrolment['cohortId'] ?? '');
            if ($cohortId !== '') {
                $cohortIds[$cohortId] = true;
            }
        }

        return array_keys($cohortIds);
    }//end resolveCallerCohortIds()

    /**
     * Load every raw Session row for the resolved cohorts (no window filter).
     *
     * Sessions are fetched per cohort (an equality filter on `cohortId`) so no
     * cross-cohort session is ever loaded. The unfiltered result backs both
     * the windowed `sessions` projection and the same-day `changes` list —
     * one query pass, not two.
     *
     * @param array<int,string> $cohortIds The caller's cohort UUIDs.
     *
     * @return array<int,array<string,mixed>> Raw session data arrays, all cohorts.
     */
    private function loadRawSessionsForCohorts(array $cohortIds): array
    {
        $rows = [];

        foreach ($cohortIds as $cohortId) {
            $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'session',
                    'filters'  => ['cohortId' => $cohortId],
                    'sort'     => ['startsAt' => 'ASC'],
                ]
            );

            foreach ($results as $row) {
                $rows[] = $this->toArray(row: $row);
            }
        }

        return $rows;
    }//end loadRawSessionsForCohorts()

    /**
     * Pre-load every distinct Room referenced by `roomId` across the given
     * raw sessions, so the projection step never issues an N+1 query.
     *
     * @param array<int,array<string,mixed>> $sessions Raw session data arrays.
     *
     * @return array<string,array<string,mixed>> Room data keyed by room UUID.
     */
    private function preloadRooms(array $sessions): array
    {
        $roomIds = [];
        foreach ($sessions as $session) {
            $roomId = (string) ($session['roomId'] ?? '');
            if ($roomId !== '') {
                $roomIds[$roomId] = true;
            }
        }

        $rooms = [];
        foreach (array_keys($roomIds) as $roomId) {
            $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'room',
                    'filters'  => ['id' => $roomId],
                    'limit'    => 1,
                ]
            );

            if (empty($results) === false) {
                $rooms[$roomId] = $this->toArray(row: $results[0]);
            }
        }

        return $rooms;
    }//end preloadRooms()

    /**
     * Project the raw sessions overlapping the requested window, ordered
     * globally by `startsAt`.
     *
     * @param array<int,array<string,mixed>>    $rawSessions Raw session data arrays, all cohorts.
     * @param string                            $windowFrom  Inclusive window start (ISO 8601).
     * @param string                            $windowTo    Exclusive window end (ISO 8601).
     * @param array<string,array<string,mixed>> $roomCache   Pre-loaded Room data keyed by UUID.
     *
     * @return array<int,array<string,mixed>> The ordered, projected sessions.
     */
    private function projectWindowedSessions(array $rawSessions, string $windowFrom, string $windowTo, array $roomCache): array
    {
        $fromTs = strtotime($windowFrom);
        $toTs   = strtotime($windowTo);

        $sessions = [];
        foreach ($rawSessions as $session) {
            if ($this->overlapsWindow(session: $session, fromTs: $fromTs, toTs: $toTs) === false) {
                continue;
            }

            $sessions[] = $this->projectSession(session: $session, roomCache: $roomCache);
        }

        usort(
            $sessions,
            static function (array $a, array $b): int {
                return strcmp((string) $a['startsAt'], (string) $b['startsAt']);
            }
        );

        return $sessions;
    }//end projectWindowedSessions()

    /**
     * Project the raw sessions whose `cancel`/`substitute-teacher` transition
     * (`changedAt`, stamped server-side by SessionChangeNoticeHandler)
     * occurred today (UTC calendar date) — the dagrooster surface —
     * regardless of whether the Session's own `startsAt` falls inside the
     * requested window.
     *
     * @param array<int,array<string,mixed>>    $rawSessions Raw session data arrays, all cohorts.
     * @param array<string,array<string,mixed>> $roomCache   Pre-loaded Room data keyed by UUID.
     *
     * @return array<int,array<string,mixed>> The projected same-day changes, ordered by changedAt.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/personal-timetable/spec.md#scenario-today-s-cancellation-surfaces-in-the-dagrooster-changes-list-even-for-a-future-session
     */
    private function projectTodaysChanges(array $rawSessions, array $roomCache): array
    {
        $today = gmdate('Y-m-d');

        $changes = [];
        foreach ($rawSessions as $session) {
            $changedAt = (string) ($session['changedAt'] ?? '');
            if ($changedAt === '') {
                continue;
            }

            $ts = strtotime($changedAt);
            if ($ts === false || gmdate('Y-m-d', $ts) !== $today) {
                continue;
            }

            $changes[] = $this->projectSession(session: $session, roomCache: $roomCache);
        }

        usort(
            $changes,
            static function (array $a, array $b): int {
                return strcmp((string) $a['changedAt'], (string) $b['changedAt']);
            }
        );

        return $changes;
    }//end projectTodaysChanges()

    /**
     * Project one raw Session row to the caller-facing shape, including
     * resolved Room detail (when `roomId` is set) and substitution fields.
     *
     * @param array<string,mixed>               $session   Raw session data.
     * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
     *
     * @return array<string,mixed> The projected session.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
     */
    private function projectSession(array $session, array $roomCache): array
    {
        $roomId       = (string) ($session['roomId'] ?? '');
        $room         = null;
        $roomIdOrNull = null;
        if ($roomId !== '') {
            $roomIdOrNull = $roomId;
        }

        if ($roomId !== '' && isset($roomCache[$roomId]) === true) {
            $roomData = $roomCache[$roomId];
            $room     = [
                'id'         => (string) ($roomData['id'] ?? ($roomData['uuid'] ?? $roomId)),
                'name'       => (string) ($roomData['name'] ?? ''),
                'capacity'   => $roomData['capacity'] ?? null,
                'facilities' => $roomData['facilities'] ?? [],
            ];
        }

        return [
            'id'                  => (string) ($session['id'] ?? ($session['uuid'] ?? '')),
            'title'               => (string) ($session['title'] ?? ''),
            'startsAt'            => (string) ($session['startsAt'] ?? ''),
            'endsAt'              => (string) ($session['endsAt'] ?? ''),
            'location'            => (string) ($session['location'] ?? ''),
            'cohortId'            => (string) ($session['cohortId'] ?? ''),
            'courseId'            => (string) ($session['courseId'] ?? ''),
            'lessonId'            => (string) ($session['lessonId'] ?? ''),
            'lifecycle'           => (string) ($session['lifecycle'] ?? ''),
            'roomId'              => $roomIdOrNull,
            'room'                => $room,
            'substituteTeacherId' => $session['substituteTeacherId'] ?? null,
            'changeReasonKind'    => $session['changeReasonKind'] ?? null,
            'changeReason'        => $session['changeReason'] ?? null,
            'changedAt'           => $session['changedAt'] ?? null,
        ];
    }//end projectSession()

    /**
     * Decide whether a session overlaps the requested window.
     *
     * A session overlaps when it starts before the window end AND ends after
     * the window start. When `endsAt` is absent, the session is treated as a
     * point in time and included if its `startsAt` falls within the window.
     *
     * @param array<string,mixed> $session The session data.
     * @param int|false           $fromTs  Window start as a unix timestamp.
     * @param int|false           $toTs    Window end as a unix timestamp.
     *
     * @return bool True when the session overlaps the window.
     */
    private function overlapsWindow(array $session, int|false $fromTs, int|false $toTs): bool
    {
        if ($fromTs === false || $toTs === false) {
            // Unparseable window — do not silently drop everything.
            return true;
        }

        $startsAt = (string) ($session['startsAt'] ?? '');
        if ($startsAt === '') {
            return false;
        }

        $startTs = strtotime($startsAt);
        if ($startTs === false) {
            return false;
        }

        $endsAtRaw = (string) ($session['endsAt'] ?? '');
        $endTs     = $startTs;
        if ($endsAtRaw !== '') {
            $endTs = strtotime($endsAtRaw);
        }

        if ($endTs === false) {
            $endTs = $startTs;
        }

        // Overlap: starts before window end AND ends after window start.
        return ($startTs < $toTs && $endTs >= $fromTs);
    }//end overlapsWindow()

    /**
     * Normalise an ObjectService row (entity or array) to a plain array.
     *
     * @param mixed $row The row returned by ObjectService::findAll.
     *
     * @return array<string,mixed> The serialized object data.
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];
    }//end toArray()

    /**
     * Coerce a schema array-of-strings value into a list of strings.
     *
     * @param mixed $value The raw property value.
     *
     * @return array<int,string> The string list (empty when not an array).
     */
    private function toStringList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) === true || is_numeric($item) === true) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }//end toStringList()
}//end class
