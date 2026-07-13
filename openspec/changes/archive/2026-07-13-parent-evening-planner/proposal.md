---
kind: code
depends_on: []
---

## Why

Every report period, Dutch schools run an **oudergesprekken-cyclus**: parents are invited to
10-minute conversation slots with their child's teachers, they sign up for the teachers/times
they want, and someone has to turn hundreds of overlapping preferences into a conflict-free
room-by-room schedule. Scholiq has none of the pieces:

- **Zero schema footprint.** `grep -niE "ouderavond|parent.evening|conversation.slot|10-minute|gespreksverslag|gesprek"` across
  `openspec/specs/**/spec.md` returns exactly one hit and it is a false positive —
  `compliance-audit/spec.md:22` uses "10-minute video-based microlearning delivery" for corporate
  training modules, unrelated to parent conferences. A second grep directly against
  `lib/Settings/scholiq_register.json` for `availability`/`teacherAvailability` returns only
  `assessment`'s unrelated `x-proctoring` config and `Assessment`'s exam `availability window`
  (`lib/Settings/scholiq_register.json:4440`) — no `TeacherAvailability`, no conversation/slot
  schema anywhere in the register's 37 schemas (verified via `python3 -c "json.load(...)"` listing
  every `components.schemas` key).
- **No planner page.** `src/manifest.json` has no conference/oudergesprek page; the `dashboard`
  spec's parent view is explicitly scoped to "digest preferences, OPP signing tasks, sick reports"
  (`openspec/specs/dashboard/spec.md:22`) — booking a conversation slot is not one of them.
- **`school-structure` explicitly punts on this.** Its `Session` schema records a scheduled
  occurrence but the spec's own Out of Scope says "Room-booking conflict resolution and
  timetabling optimisation" (`openspec/specs/school-structure/spec.md:96`) is not covered — a
  conference round is not a `Session` (no cohort, no course, N:N teacher↔family matching, not a
  class meeting) and forcing it into that schema would violate that stated boundary.
- **`personal-timetable` is read-only over existing schemas by design** — "MUST NOT introduce a
  new schema, new storage, or a scheduling engine" (`openspec/specs/personal-timetable/spec.md:41-42`).
  It is the wrong place for a solver; this change owns its own capability instead.

**Evidence this is real, differentiated demand, not a nice-to-have:**
- Spectr `customer_journeys` id 1736, `oudergesprekken-cyclus` ("Parent-Teacher Conference
  Planning Cycle"), priority `high`, frequency `per-term`: "Around report periods the school
  organises 10-minute parent conversations: parents are invited digitally, sign up for slots with
  chosen teachers via the parent portal/app, and a conflict-free conversation schedule is
  generated (in practice via a Zermelo–Somtoday web-service link)." `current_pain`: "Planning runs
  in a separate scheduling tool while invitations and communication run in the LAS/ELO; schools
  without the link do it with paper forms and spreadsheets." `desired_outcome`: "All parents booked
  into a conflict-free schedule without manual puzzle work; **conversation notes land in the pupil
  dossier**."
- `user_stories` id 10055 `ouderavond-slot-inschrijven` (priority `high`): "As a parent I want to
  pick conversation slots with my child's teachers from a digital invitation so that I do not have
  to fill in paper preference forms and wait for a manually puzzled schedule." Acceptance criteria
  include "Confirmed schedule is visible in the parent portal and can be added to a calendar."
- `user_stories` id 10056 `gespreksrooster-genereren` (priority `medium`, complexity `complex`):
  "As a mentor I want the system to generate a conflict-free conversation schedule from parent
  sign-ups and teacher availability so that no parent has overlapping slots and walking time
  between rooms is respected." Acceptance criteria: "Buffer/walking time between consecutive
  conversations is configurable" and "Schedule can be republished after last-minute cancellations."
- `external_sources` id 6585 (Somtoday servicedesk, `documentation`): confirms the two-system split
  — "scheduling handled in Zermelo via web-service link, invitations and sign-up via Somtoday
  ELO/app" — and names the pain directly: `pain_points` = `["planning and communication split over
  two systems", "schools without link use paper forms"]`.
- `competitor_features` id 1478 (`app_slug=scholiq`, competitor **Zermelo**): "Exam and
  parent-evening scheduling" / "Dedicated schedulers for exam timetables and parent-teacher evening
  appointments" (category `core`). Both Dutch K-12 incumbents (SOMtoday + Zermelo) monetise this as
  a paid, separately-licensed integration; a school without the link is stuck on paper.

This is a genuine, evidenced gap: two NL incumbents ship it (as a paid add-on requiring a second
vendor contract), a `high`-priority per-term journey names the exact pain it causes, and it is a
natural extension of the already-shipped parent-facing surfaces (`portal-contribution`, `dashboard`
parent view) rather than a new product area.

## What Changes

New capability `parent-conferences`, entirely additive — no existing schema or spec is modified.

- **Five new OpenRegister schemas** in `lib/Settings/scholiq_register.json` (relation dialect:
  property-level `format: uuid` + `$ref`, per the schemas already in the register, e.g.
  `Session.cohortId` at `lib/Settings/scholiq_register.json` — no separate `x-openregister-relations`
  block; confirmed none of the 37 existing schemas carry that top-level key):
  - `ConferenceRound` — one oudergesprekken cycle per report period: scope (`cohortIds[]`),
    `teacherIds[]`, `slotDurationMinutes` (default 10, per story 10055/10056), `bufferMinutes`
    (walking time between consecutive slots, per story 10056 AC), booking window
    (`bookingOpensAt`/`bookingClosesAt`), and a denormalised `invitedLearnerIds[]` populated at
    invitation-send time (see design.md for why denormalised, not a dynamic group fan-out).
    Lifecycle: `draft → invitations-sent → booking-open → booking-closed → scheduled → completed |
    cancelled`.
  - `TeacherAvailability` — a teacher's declared free blocks for one round (`blocks[]:
    {startsAt, endsAt}`). Lifecycle: `draft → submitted → locked` (locked once generation
    consumes it, preventing a race between a late availability edit and a running solve).
  - `ConferenceSignup` — a guardian's (or self, for an 18+ learner) request: `learnerId`/`learnerRef`,
    `guardianId`/`guardianRef` (the additive UUID-ref pair, matching the `learnerRef`/`submittedByRef`
    convention already on `ExcuseRequest` and `GradeEntry`), `requestedTeacherIds[]` (ordered
    preference), optional `notes`. Lifecycle: `draft → submitted → scheduled | waitlisted →
    cancelled`; `submitted` is gated by a new `ConferenceSignupGuardianGuard` (see design.md).
  - `ConferenceSlot` — one generated appointment: `conferenceRoundId`, `teacherId`,
    `learnerId`/`learnerRef`, `signupId`, `startsAt`/`endsAt`, `location`. Lifecycle: `proposed →
    confirmed → completed | no-show | cancelled`.
  - `ConferenceReport` (gespreksverslag) — `appendOnly: true` (matching `AttendanceFlag` and
    `LearningPlanEvaluation`'s audit posture): `conferenceSlotId`, `learnerId`/`learnerRef`,
    `teacherId`, `narrative`, `attendeeIds[]` (mirrors `LearningPlanEvaluation.attendeeIds`),
    `recordedAt`/`recordedBy`. Lifecycle: `draft → recorded`.
- **One new lifecycle guard** — `lib/Lifecycle/ConferenceSignupGuardianGuard.php` (same ADR-031
  "legitimate PHP" shape as `lib/Lifecycle/CohortMembershipGuard.php`): gates `ConferenceSignup`'s
  `submit` transition on the caller being the target `LearnerProfile.parentIds` (guardian) or the
  `LearnerProfile.ncUserId` itself (18+ self-signup) — resolved server-side from `IUserSession`,
  never a client-supplied claim.
- **One new event-driven scheduling handler** — `lib/Listener/ConferenceScheduleGenerator.php`
  (same ADR-031 "cross-object write bridge" shape as `lib/Lifecycle/ExcuseApprovalHandler.php`):
  listens for `ObjectTransitionedEvent` on `ConferenceRound`'s `generate`/`regenerate` transitions,
  runs the greedy slot-assignment algorithm (design.md) over `submitted` `ConferenceSignup`s +
  `TeacherAvailability`, and writes `ConferenceSlot` objects via `ObjectService::saveObject`.
- **Declarative notifications** (`x-openregister-notifications`, verified dialect only, per
  `openspec/specs/scholiq-notifications/spec.md:16`): a `scheduled`-trigger auto-close of the
  booking window at `bookingClosesAt`; a `transition`-trigger digital invitation on
  `invitations-sent`; a `transition`-trigger slot-confirmed notice to the learner; a
  `transition`-trigger gespreksverslag-recorded notice to the learner.
- **Declarative frontend**: `src/manifest.json` index+detail pages for `ConferenceRound`,
  `TeacherAvailability`, `ConferenceSlot`, `ConferenceReport`; two named custom Vue views (the
  `attendance` spec's `MarkAttendanceView`/`SubmitExcuseModal` exception pattern,
  `openspec/specs/attendance/spec.md:79`) — `BookConferenceSlotsView` (the parent/self slot picker;
  genuine calendar-grid UI, not a generic CRUD form) and `ConferenceScheduleBoard` (the coordinator's
  manual-override board for resolving waitlisted signups and republishing after cancellations).
- **No PHP CRUD controller, no new external dependency, no wire protocol.** The solver is a pure
  PHP algorithm over already-fetched `ObjectService::findAll()` results; NC `Calendar` (`IManager`)
  is reused for the "add to calendar" AC on confirmed `ConferenceSlot`s, matching the existing
  `Session`/exam-scheduling reuse of the same OCP interface (scholiq's `openspec/config.yaml`
  "Nextcloud reuse: ... Calendar (IManager for lesson/exam scheduling)").

## Impact

- `lib/Settings/scholiq_register.json` — five new schemas (`ConferenceRound`,
  `TeacherAvailability`, `ConferenceSignup`, `ConferenceSlot`, `ConferenceReport`).
- `lib/Lifecycle/ConferenceSignupGuardianGuard.php` — new.
- `lib/Listener/ConferenceScheduleGenerator.php` — new.
- `lib/AppInfo/Application.php` — register the new listener against `ObjectTransitionedEvent`
  (same `addServiceListener()` pattern already used for `ExcuseApprovalHandler` at
  `lib/AppInfo/Application.php:216-217`).
- `src/manifest.json` — new pages + the two named custom views.
- `openspec/changes/parent-evening-planner/*` — this change (new capability
  `parent-conferences`; no existing spec file is modified).
- **Not touched, on purpose:** `school-structure` (conferences are not `Session`s — see Why),
  `personal-timetable` (stays read-only/no-solver by its own spec; unifying the "my timetable" view
  with confirmed `ConferenceSlot`s is a natural follow-up, not required for this change to deliver
  value), `data-exchange` (the OSO dossier composer's field list — `LearnerProfile` + `GradeEntry`
  + `AttendanceRecord` + `LearningPlan`, `openspec/specs/data-exchange/spec.md:25` — could later add
  `ConferenceReport`; out of scope here, noted as a future extension in design.md), `portal-contribution`
  / `portal-parent` (see Dependencies below — deliberately not depended on for this change's MVP).

## Dependencies

The gap report lists `portal-parent` as a dependency (parents booking "from the parent portal").
`openspec/changes/portal-parent/` exists in this worktree and its `design.md` "Write-IDOR guard"
section documents exactly the risk a conference-signup **create** action would carry if routed
through portaliq: the guardian's `subjectRef` stamps `submittedByRef`, but the child's `learnerRef`
must be **client-supplied** and portaliq's writer does not yet verify it against the guardian's own
`guardianRefs` — which is precisely why `portal-parent` ships `excuse-request` reads only and
withholds the parent create action pending a portaliq writer follow-up.

Scholiq already has a **second, NC-authenticated parent surface** that does not carry that risk:
`LearnerProfile.roles` includes `parent` (`lib/Settings/scholiq_register.json`, `roles` enum) and
the `dashboard` spec already serves a `scholiq-parent`-group-gated view
(`openspec/specs/dashboard/spec.md:22`). This change's `ConferenceSignup` create/submit path targets
**that** surface — an NC-authenticated guardian in the `scholiq-parent` group, verified server-side
by `ConferenceSignupGuardianGuard` against `LearnerProfile.parentIds` — which needs nothing from
`portal-parent` and carries none of its write-IDOR class. Extending the portaliq-portal (for
guardians with no NC account at all) to also read/book conferences is a legitimate follow-up once
portaliq's writer fix lands, but it is out of scope for this M-size change and is **not** declared
as a `depends_on` — see DEFERRED_QUESTIONS.

## Risks

### Risk 1: Greedy solver leaves some requests waitlisted under high demand
**Severity:** Medium — **Mitigation:** `ConferenceSignup.status: waitlisted` is a first-class
lifecycle state, not a silent drop; `ConferenceScheduleBoard` (custom view) surfaces waitlisted
signups for manual coordinator resolution (add more `TeacherAvailability`, or hand-create a
`ConferenceSlot`); `regenerate` is idempotent so re-running after a coordinator adds availability
or a parent cancels only touches unresolved signups (design.md).

### Risk 2: A parent double-books overlapping slots across multiple requested teachers
**Severity:** Medium — **Mitigation:** the solver's per-signup walk rejects any candidate slot that
overlaps an already-assigned slot for the *same* signup before accepting it (design.md "Overlap
guard"), so a single `ConferenceSignup` can never produce two overlapping `ConferenceSlot`s.

### Risk 3: `ConferenceSignupGuardianGuard` is the only authorization check, and only on `submit`
**Severity:** Low — **Mitigation:** matching the existing `ExcuseRequest` precedent (no guard at
all — `lib/Settings/scholiq_register.json`'s `excuse-request` schema has an empty
`x-openregister-authorization` block), a `draft` `ConferenceSignup` is inert (no slot is ever
generated for a non-`submitted` signup), so gating the *submit* transition — not creation — is
sufficient and is a strictly stronger posture than the app's existing parent-submission surface.

## Rollback Strategy

Remove the five new schemas from the register, the guard, and the listener registration; drop the
manifest pages/views. No existing schema, spec, or controller is touched, so rollback has zero
blast radius on any other capability.
