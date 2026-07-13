# parent-conferences Specification

## Purpose
TBD - created by archiving change parent-evening-planner. Update Purpose after archive.
## Requirements
### Requirement: Persist parent-conferences domain objects in OpenRegister

The system MUST persist `ConferenceRound`, `TeacherAvailability`, `ConferenceSignup`,
`ConferenceSlot`, `ConferenceReport` as OpenRegister objects, each with an
`x-openregister-lifecycle` block (`ConferenceRound`: `draft → invitations-sent → booking-open →
booking-closed → scheduled → completed | cancelled`; `TeacherAvailability`: `draft → submitted →
locked`; `ConferenceSignup`: `draft → submitted → scheduled | waitlisted → cancelled`;
`ConferenceSlot`: `proposed → confirmed → completed | no-show | cancelled`; `ConferenceReport`:
`draft → recorded`). Every UUID foreign key MUST use the property-level relation dialect already in
use across the register (`format: uuid` + `$ref: <SchemaTitle>` on the property itself — no
separate `x-openregister-relations` block). `ConferenceReport` MUST be `appendOnly: true`.

#### Scenario: All five schemas persist with their declared lifecycles

- **GIVEN** the parent-conferences schemas are registered
- **WHEN** a `ConferenceRound`, `TeacherAvailability`, `ConferenceSignup`, `ConferenceSlot`, and
  `ConferenceReport` are each created
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle state
- **AND** `ConferenceReport` is `appendOnly: true`

### Requirement: A conference round declares its scope, slot duration, and buffer time

`ConferenceRound` MUST carry `cohortIds[]` (scope), `teacherIds[]` (eligible teachers),
`slotDurationMinutes` (default 10), `bufferMinutes` (walking-time gap between consecutive slots,
configurable per-round), and a booking window (`bookingOpensAt`/`bookingClosesAt`).

#### Scenario: A coordinator configures a round with a 10-minute slot and a 2-minute buffer

- **GIVEN** a coordinator creates a `ConferenceRound` for a report period
- **WHEN** they set `slotDurationMinutes: 10` and `bufferMinutes: 2` for three cohorts
- **THEN** the round persists that scope and timing, and every generated `ConferenceSlot` for the
  round is exactly 10 minutes long with at least 2 minutes before the next slot for the same
  teacher

### Requirement: Booking window auto-closes on schedule, not by a PHP TimedJob

The transition from `booking-open` to `booking-closed` MUST fire via a declared `scheduled`-type
`x-openregister-notifications` trigger keyed to `ConferenceRound.bookingClosesAt` — not a PHP
TimedJob — matching the `attendance` spec's "declared calculation trigger, not a TimedJob" posture.

#### Scenario: Booking closes automatically at the declared close time

- **GIVEN** a `ConferenceRound` in `booking-open` with `bookingClosesAt` in the past
- **WHEN** the declared `scheduled` trigger evaluates
- **THEN** the round transitions to `booking-closed` without any PHP TimedJob polling it

### Requirement: Digital invitations are a declared transition notification to the round's invited learners

Sending invitations MUST be the `ConferenceRound.send-invitations` transition (`draft →
invitations-sent`), computing `invitedLearnerIds[]` once from `cohortIds[]` → each `Cohort.learnerIds`
and persisting it onto the round, then declaring an `x-openregister-notifications` `transition`
rule with `recipients: [{kind: field, field: invitedLearnerIds}]` and an inline `subject` carrying
`nl` and `en` strings, per the verified dialect (`openspec/specs/scholiq-notifications/spec.md`).

#### Scenario: Every learner in the round's cohorts is invited

- **GIVEN** a `ConferenceRound` scoped to two cohorts totalling 40 learners
- **WHEN** the round transitions `draft → invitations-sent`
- **THEN** `invitedLearnerIds` contains exactly the 40 learners' NC user ids
- **AND** each receives an `nc-notification` per the declared `transition` rule

### Requirement: A guardian or self-signup submission is gated by a per-object authorization guard

`ConferenceSignup`'s `submit` transition (`draft → submitted`) MUST be gated by
`ConferenceSignupGuardianGuard`, which resolves the caller's NC user id server-side (never a
client-supplied claim) and passes only when the caller is listed in the target learner's
`LearnerProfile.parentIds`, or the caller **is** the target learner (18+ self-signup). A `draft`
`ConferenceSignup` MUST NOT be considered by the scheduling generator.

#### Scenario: A linked guardian can submit a signup for their own child

- **GIVEN** a guardian whose NC user id is in `LearnerProfile.parentIds` for learner L
- **WHEN** they submit a `ConferenceSignup` naming learner L
- **THEN** the `submit` transition succeeds and the signup moves to `submitted`

#### Scenario: An unrelated user cannot submit a signup for someone else's child

- **GIVEN** an authenticated user whose NC user id is NOT in `LearnerProfile.parentIds` for learner L
  and who is not learner L
- **WHEN** they attempt to submit a `ConferenceSignup` naming learner L
- **THEN** the `submit` transition is blocked by `ConferenceSignupGuardianGuard`
- **AND** the signup remains `draft`, inert to the scheduling generator

### Requirement: Schedule generation is a declared greedy solver triggered by a round transition, not a PHP CRUD controller

`ConferenceRound`'s `generate`/`regenerate` transitions MUST be observed by an OR-event-driven
handler (`ConferenceScheduleGenerator`, an ADR-031 "cross-object write bridge" exception, matching
`ExcuseApprovalHandler`'s shape) that reads `submitted` `ConferenceSignup`s and
`submitted`/`locked` `TeacherAvailability` for the round, runs the greedy earliest-fit
submission-order algorithm (design.md), and writes `ConferenceSlot` objects. The algorithm MUST
guarantee no two `ConferenceSlot`s for the same teacher overlap, and no two `ConferenceSlot`s for
the same signup overlap. `regenerate` MUST be idempotent: it MUST NOT re-shuffle `confirmed`
`ConferenceSlot`s, and MUST re-fill only from availability freed by cancelled signups or newly
submitted availability.

#### Scenario: Conflict-free generation from sign-ups and availability

- **GIVEN** a `ConferenceRound` with submitted `TeacherAvailability` for 3 teachers and 20 submitted
  `ConferenceSignup`s each requesting 1–3 of those teachers
- **WHEN** the round transitions to `generate`
- **THEN** every produced `ConferenceSlot` is conflict-free per teacher and per signup
- **AND** every signup with all requested teachers satisfied moves to `scheduled`
- **AND** every signup with an unmet teacher-request moves to `waitlisted`, naming which request
  could not be met

#### Scenario: Republish after a last-minute cancellation does not disturb confirmed slots

- **GIVEN** a `scheduled` round with some `confirmed` `ConferenceSlot`s and one `ConferenceSignup`
  that just moved to `cancelled`, freeing its teacher's slot
- **WHEN** the round transitions `regenerate`
- **THEN** all `confirmed` slots are unchanged
- **AND** the freed slot becomes available to any still-`waitlisted` signup requesting that teacher

### Requirement: A gespreksverslag is recorded to the pupil dossier as an append-only record

A `ConferenceReport` MUST be creatable against a `completed` `ConferenceSlot`, MUST be
`appendOnly: true` once `recorded`, and MUST carry `narrative`, `attendeeIds[]`, `recordedBy`,
`recordedAt`, and the learner reference — mirroring `LearningPlanEvaluation`'s shape
(`lib/Settings/scholiq_register.json` `learning-plan-evaluation`) so it becomes part of the
learner's queryable record set the same way `LearningPlan`/`GradeEntry`/`AttendanceRecord` already
do (per the OSO dossier composer's existing field list, `openspec/specs/data-exchange/spec.md:25`;
adding `ConferenceReport` to that composer is an explicit future follow-up, not this change).

#### Scenario: A teacher records a conversation report after a completed slot

- **GIVEN** a `ConferenceSlot` in `completed` status
- **WHEN** the teacher records a `ConferenceReport` with a narrative and attendees
- **THEN** the report persists linked to the slot and the learner
- **AND** it transitions `draft → recorded`, becoming append-only (immutable) from that point
- **AND** a declared `transition` notification informs the learner it was recorded

### Requirement: Frontend is declarative with two named custom views

The frontend MUST be declarative: `src/manifest.json` index+detail pages for `ConferenceRound`,
`TeacherAvailability`, `ConferenceSlot`, `ConferenceReport`. The only custom Vue views MUST be
`BookConferenceSlotsView` (the guardian/self slot-picker — genuine calendar-grid UI a generic form
cannot render) and `ConferenceScheduleBoard` (the coordinator's manual-override board for resolving
`waitlisted` signups and triggering `regenerate`). No PHP CRUD controller MUST be introduced.

#### Scenario: Booking and coordinator resolution use the two named custom views only

- **GIVEN** the parent-conferences frontend is configured
- **WHEN** a guardian books slots and a coordinator resolves a waitlisted signup
- **THEN** the guardian's flow renders via `BookConferenceSlotsView` and the coordinator's via
  `ConferenceScheduleBoard`
- **AND** every other parent-conferences screen (round list/detail, availability list/detail, slot
  list/detail, report list/detail) is a declarative `src/manifest.json` page
- **AND** no PHP CRUD controller exists for any of the five schemas

