## ADDED Requirements

### Requirement: A signed-in user can see their own upcoming sessions

The system MUST provide a personal timetable that returns the caller's own scheduled
`Session` objects for a time window. The caller's sessions MUST be resolved by first
determining the cohorts the caller belongs to — as a teacher via `Cohort.teacherIds`, and
as a learner via `Cohort.learnerIds` and/or `Enrolment.cohortId` — then returning the
`Session` objects whose `cohortId` is one of those cohorts and whose `startsAt`/`endsAt`
fall within the requested window, ordered by `startsAt`. All reads MUST go through
OpenRegister `ObjectService` so RBAC and tenancy scope the result; the caller MUST NOT
receive a session for a cohort they do not belong to. A caller with no cohorts MUST receive
an empty timetable (not an error).

#### Scenario: A learner sees this week's sessions for their enrolled cohorts

- **GIVEN** a learner enrolled in one or more cohorts that have scheduled sessions this week
- **WHEN** the learner requests their timetable for the current week
- **THEN** the system MUST return the `Session` objects for those cohorts within the week, ordered by `startsAt`, each with `title`, `startsAt`, `endsAt`, and `location`
- **AND** sessions of cohorts the learner is not in MUST NOT appear

#### Scenario: A teacher sees the sessions of the cohorts they teach

- **GIVEN** a teacher listed in `teacherIds` of one or more cohorts with scheduled sessions
- **WHEN** the teacher requests their timetable
- **THEN** the system MUST return the sessions of the cohorts they teach for the window

#### Scenario: A user with no cohorts gets an empty timetable

- **GIVEN** a signed-in user who is neither a teacher of nor enrolled in any cohort
- **WHEN** they request their timetable
- **THEN** the system MUST return an empty list (HTTP 200), never an error

@e2e exclude the cross-object resolution + windowing is unit-tested against seeded cohorts/sessions; a Playwright week-view smoke is a follow-up once seed data lands.

### Requirement: The timetable is a read surface only, over existing objects

The personal timetable MUST NOT introduce a new schema, new storage, or a scheduling
engine. It MUST consume the existing `Session`, `Cohort`, and `Enrolment` objects through
`ObjectService`. Creating or editing sessions remains owned by the existing session /
attendance surfaces; the timetable only reads.

#### Scenario: No new persisted state is created by viewing a timetable

- **WHEN** any user opens their timetable
- **THEN** the system MUST only read existing `Session`/`Cohort`/`Enrolment` objects
- **AND** MUST NOT create or mutate any object

@e2e exclude read-only invariant asserted by the controller unit test (no write calls).
