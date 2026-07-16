# personal-timetable Specification

## Purpose
TBD - created by archiving change personal-timetable. Update Purpose after archive.
## Requirements
### Requirement: A signed-in user can see their own upcoming sessions

The system MUST provide a personal timetable that returns the caller's own scheduled
`Session` objects for a time window. The caller's sessions MUST be resolved by first
determining the cohorts the caller belongs to — as a teacher via `Cohort.teacherIds`, and
as a learner via `Cohort.learnerIds` and/or `Enrolment.cohortId` — then returning the
`Session` objects whose `cohortId` is one of those cohorts and whose `startsAt`/`endsAt`
fall within the requested window, ordered by `startsAt`. Each returned Session MUST additionally project
`roomId` and, when set, the referenced `Room`'s `name`/`capacity`/`facilities`, plus `lifecycle`,
`substituteTeacherId`, `changeReasonKind`, and `changeReason` — so a caller can see, from their own
timetable alone, that a Session has been cancelled or has a substitute teacher, without a separate lookup.
The response MUST also include a same-day `changes` list: `Session` objects belonging to the caller's own
cohorts whose `cancel` or `substitute-teacher` transition occurred today (regardless of whether the
Session's own `startsAt` falls inside the requested `from`/`to` window) — the "dagrooster" surface for
today's disruptions to a caller's schedule. All reads MUST go through OpenRegister `ObjectService` so RBAC
and tenancy scope the result; the caller MUST NOT receive a session for a cohort they do not belong to, and
MUST NOT receive another cohort's `TimetableConflict` data (that queue remains admin/coordinator-only). A
caller with no cohorts MUST receive an empty timetable (not an error) and an empty `changes` list.

#### Scenario: A learner sees this week's sessions for their enrolled cohorts

- **GIVEN** a learner enrolled in one or more cohorts that have scheduled sessions this week
- **WHEN** the learner requests their timetable for the current week
- **THEN** the system MUST return the `Session` objects for those cohorts within the week, ordered by `startsAt`, each with `title`, `startsAt`, `endsAt`, `location`, `roomId` (and resolved Room detail when set), `lifecycle`, `substituteTeacherId`, `changeReasonKind`, and `changeReason`
- **AND** sessions of cohorts the learner is not in MUST NOT appear

#### Scenario: A teacher sees the sessions of the cohorts they teach

- **GIVEN** a teacher listed in `teacherIds` of one or more cohorts with scheduled sessions
- **WHEN** the teacher requests their timetable
- **THEN** the system MUST return the sessions of the cohorts they teach for the window, with the same
  Room/substitution detail projected

#### Scenario: A user with no cohorts gets an empty timetable

- **GIVEN** a signed-in user who is neither a teacher of nor enrolled in any cohort
- **WHEN** they request their timetable
- **THEN** the system MUST return an empty list (HTTP 200) and an empty `changes` list, never an error

#### Scenario: Today's cancellation surfaces in the dagrooster changes list even for a future Session

- **GIVEN** a learner enrolled in a cohort with a Session scheduled for tomorrow
- **WHEN** a teacher cancels that Session today
- **THEN** the learner's timetable response for today includes the cancelled Session in its `changes` list,
  even though the requested window is "today" and the Session's own `startsAt` is tomorrow

@e2e exclude the cross-object resolution + windowing is unit-tested against seeded cohorts/sessions; a Playwright week-view smoke is a follow-up once seed data lands.

### Requirement: The timetable is a read surface only, over existing objects

The personal timetable MUST NOT introduce a new schema, new storage, or a scheduling
engine. It MUST consume the existing `Session`, `Cohort`, and `Enrolment` objects through
`ObjectService`. Creating or editing sessions remains owned by the existing session /
attendance surfaces; the timetable only reads. Projecting `Room` detail, substitution fields, and the
same-day `changes` list added by this change MUST NOT change this invariant: every projected field is read
from the existing `Session`, `Cohort`, `Room`, and `Enrolment` objects (the `Room`/substitution fields being
additive `Session` fields introduced by the `school-structure` delta of this same change, and `Room` being a
pre-existing read-only lookup) through `ObjectService`; `TimetableController` gains no new write endpoint.

#### Scenario: No new persisted state is created by viewing a timetable

- **WHEN** any user opens their timetable
- **THEN** the system MUST only read existing `Session`/`Cohort`/`Enrolment` objects
- **AND** MUST NOT create or mutate any object

@e2e exclude read-only invariant asserted by the controller unit test (no write calls).

#### Scenario: Viewing the extended timetable, including the same-day changes list, still creates no persisted state

- **WHEN** any user opens their timetable, including the same-day `changes` list and Room/substitution
  projection
- **THEN** the system only reads existing `Session`/`Cohort`/`Room`/`Enrolment` objects
- **AND** MUST NOT create or mutate any object, and no new controller write endpoint is added

@e2e exclude read-only invariant asserted by the controller unit test (no write calls); mirrors the existing scenario for the base read surface.

