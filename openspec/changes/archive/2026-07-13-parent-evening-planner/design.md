# Design: parent-evening-planner

## Architecture Overview

```
ConferenceRound (draft)
  ├─ coordinator adds scope (cohortIds, teacherIds), window, slotDurationMinutes, bufferMinutes
  ├─ transition: send-invitations  → invitations-sent   (x-openregister-notifications, transition trigger)
  ├─ transition: open-booking      → booking-open
  │     ├─ teachers submit TeacherAvailability (draft → submitted)
  │     └─ guardians/self submit ConferenceSignup (draft → submitted, guarded)
  ├─ trigger: scheduled (bookingClosesAt reached) → booking-closed  (x-openregister-notifications, scheduled trigger)
  ├─ transition: generate | regenerate → scheduled
  │     └─ ConferenceScheduleGenerator (event listener) reads submitted ConferenceSignups +
  │        submitted/locked TeacherAvailability, runs the greedy solver, writes ConferenceSlot
  │        objects (proposed), flips consumed TeacherAvailability → locked
  ├─ coordinator resolves any `waitlisted` ConferenceSignup via ConferenceScheduleBoard
  │     (manual ConferenceSlot creation, or add more TeacherAvailability + regenerate)
  ├─ ConferenceSlot: proposed → confirmed (parent/teacher confirms, or auto-confirm on generate)
  │                              → completed | no-show | cancelled
  └─ ConferenceReport (gespreksverslag) recorded against a completed ConferenceSlot, appendOnly
```

Every write goes through `ObjectService`; there is no PHP CRUD controller. The two pieces of real
logic — the authorization guard and the scheduling algorithm — are the two ADR-031 "legitimate
exception" shapes already established in this codebase (`lib/Lifecycle/CohortMembershipGuard.php`
for a pre-transition guard, `lib/Lifecycle/ExcuseApprovalHandler.php` for a cross-object
event-driven write bridge).

## Scheduling algorithm: greedy, submission-order, earliest-fit

### Inputs

- `TeacherAvailability.blocks[]` per teacher for the round: `{startsAt, endsAt}`.
- `ConferenceRound.slotDurationMinutes` (default 10) and `.bufferMinutes` (walking-time gap,
  configurable per story 10056 AC "Buffer/walking time between consecutive conversations is
  configurable").
- `ConferenceSignup` records with `status: submitted`, each carrying `requestedTeacherIds[]` in the
  parent's stated preference order, ordered by `createdAt` ascending across signups (submission
  order).

### Step 1 — slice availability into a per-teacher slot queue

For each teacher, walk each availability block from `startsAt`, cutting consecutive slots of
`slotDurationMinutes` with a `bufferMinutes` gap between the end of one slot and the start of the
next, stopping when the next slot's end would exceed the block's `endsAt`. This produces one
chronologically ordered FIFO queue of candidate `(teacherId, startsAt, endsAt)` slots per teacher.
Pure function, no side effects, deterministic for the same availability input.

### Step 2 — walk signups in submission order

For each `ConferenceSignup` (oldest `createdAt` first), for each of its `requestedTeacherIds` in
the parent's stated order:

1. Peek the earliest remaining slot in that teacher's queue.
2. **Overlap guard**: if that slot's `[startsAt, endsAt)` overlaps any slot already tentatively
   assigned to *this same signup* earlier in this pass (a parent must never receive two
   overlapping appointments across different teachers), skip it and peek the next slot in the same
   teacher's queue; repeat until a non-overlapping slot is found or the queue is exhausted.
3. If found: pop it, create a `ConferenceSlot(proposed)` linking `conferenceRoundId`, `teacherId`,
   the signup's `learnerId`/`learnerRef`, `signupId`.
4. If the teacher's queue is exhausted before a non-overlapping slot is found: record that specific
   teacher-request as unmet for this signup.

### Step 3 — resolve signup status

A `ConferenceSignup` with **zero** unmet teacher-requests moves to `scheduled`. A signup with
**any** unmet request moves to `waitlisted` (not silently dropped, not partially hidden — the
`ConferenceScheduleBoard` view lists exactly which teacher-request within a waitlisted signup could
not be met, so a coordinator can add availability or hand-place it).

### Step 4 — idempotent regenerate (republish after cancellations)

`regenerate` is a pure re-run of Steps 1–3 restricted to: (a) `TeacherAvailability` blocks not yet
consumed by a `confirmed` `ConferenceSlot` (a `confirmed` slot's block-minutes are excluded from
re-slicing — confirmed appointments are pinned, matching story 10056's "no double bookings" AC even
across a republish), and (b) `ConferenceSignup`s still in `submitted` or `waitlisted` status (a
`cancelled` signup frees its `ConferenceSlot`s, which return their minutes to the teacher's queue
for the next run). This directly satisfies story 10056 AC "Schedule can be republished after
last-minute cancellations" without a bespoke diff/merge algorithm — cancelling just shrinks the
consumed set and the greedy walk re-fills from where it left off.

### Complexity

Sorting signups: O(S log S). Each signup-teacher-request pops/peeks its target queue amortised
O(1) (each slot is visited at most once per pass, because a rejected-for-overlap slot is skipped
permanently within that signup's own resolution, not requeued). Overall O(S·T + total_slots) where
S = signups in the round, T = average requested teachers per signup — linear in the size of a
single evening's sign-up sheet (typically low hundreds of signups), not the years-scale of the
underlying `ConferenceSignup`/`TeacherAvailability` history.

## Rejected alternatives

1. **Global-optimum constraint solver (ILP / max-flow bipartite matching with time-window
   constraints).** Would minimise total parent waiting time and better balance teacher load across
   an evening. **Rejected** because it requires an external solver dependency (no CSP/ILP library
   exists in scholiq's PHP stack today, and adding one for a single `should`-priority M-size
   feature is a disproportionate new maintenance surface — matching the "no new external
   dependency" posture the sibling `cmi5-xapi-lrs-ingest` change also holds to). Noted as a future
   optimisation if buyer feedback specifically demands tighter parent-side slot clustering; the
   greedy solver's output quality is bounded but always conflict-free, which is the hard
   requirement (story 10056), not schedule-optimality.
2. **Manual admin puzzle-board with no algorithm (coordinator drags every slot by hand).**
   **Rejected** — this is *exactly* the status quo the evidence names as the pain being solved
   (Spectr `external_sources` id 6585: "planning and communication split over two systems; schools
   without link use paper forms"; journey 1736 `current_pain`: "Planning runs in a separate
   scheduling tool... schools without the link do it with paper forms and spreadsheets"). Shipping
   only a manual board reproduces the Zermelo-license-or-paper choice this change exists to remove.
3. **Randomised / round-robin assignment ignoring submission order.** Simpler than greedy-by-time,
   but has no defensible fairness story a school can explain to a complaining parent. **Rejected**
   in favour of submission-order-first-come-first-served, because it mirrors the transparent rule
   families already use on the paper sign-up sheet it replaces — easing change management, not just
   simplifying the algorithm.
4. **Teacher-first iteration (assign each teacher's slot queue outward to waiting signups) instead
   of signup-first.** Considered because it maps naturally onto "each teacher publishes a queue."
   **Rejected** because a parent's ordered multi-teacher wishlist then needs a *second*
   reconciliation pass to catch cross-teacher overlaps for the same family — the chosen signup-first
   walk keeps the overlap guard local to Step 2 with no second pass.

## Authorization: `ConferenceSignupGuardianGuard`

Same shape as `lib/Lifecycle/CohortMembershipGuard.php` — a single `check(array &$transitionContext): bool`
method wired as `x-openregister-lifecycle.transitions.submit.requires` on `ConferenceSignup`, plus
`IUserSession` injected via constructor (the guard resolves the *caller's* NC user id server-side;
`CohortMembershipGuard` doesn't need caller identity so it has no such dependency — this guard does,
because the check is "is the caller allowed to submit for this child", not just "is the object
valid"). Logic: fetch the target `LearnerProfile` via `ObjectService::findAll(['filters' =>
['ncUserId' => $signup['learnerId']]])` and pass only if the caller's user id is in that profile's
`parentIds`, OR the caller's user id equals the profile's own `ncUserId` (18+ self-signup). Fails
closed (returns `false`, blocking the transition) on any lookup miss.

**Why the guard is on `submit`, not on create.** A `draft` `ConferenceSignup` is never read by
`ConferenceScheduleGenerator` (Step 2 only considers `status: submitted`), so an unauthenticated or
mismatched `draft` row is inert — it can never consume a teacher's slot or leak into anyone's
schedule. This matches the *existing* posture of `ExcuseRequest` (which has no creation guard at
all today, per `lib/Settings/scholiq_register.json`'s empty `excuse-request.x-openregister-authorization`)
while being strictly stronger: this change adds a real, enforced check at the one point that
matters (the transition that makes the signup live), rather than leaving the whole object
ungated as the pre-existing `ExcuseRequest` pattern does. Retrofitting `ExcuseRequest` itself is out
of scope for this change (different schema, different PR).

## Notifications (verified `x-openregister-notifications` dialect only)

- `ConferenceRound.invitationsSent` — `trigger.type: transition`, `action: send-invitations`,
  `recipients: [{kind: field, field: invitedLearnerIds}]` (array field; the verified dialect's
  `kind: field` recipient resolves an NC-user-id-bearing property, and an array-of-user-ids field
  is exactly what the existing `AttendanceThreshold`/`AttendanceFlag` mentor+coordinator rules
  already resolve against — see `openspec/specs/attendance/spec.md`'s "notify mentor + coordinator"
  language backed by field recipients).
- `ConferenceRound.bookingAutoClosed` — `trigger.type: scheduled` (the dialect's fifth trigger kind,
  `openspec/specs/scholiq-notifications/spec.md:16`), firing the `close-booking` transition at
  `bookingClosesAt` — no PHP TimedJob, matching the `attendance` spec's "declared calculation +
  `calculatedChange` trigger — NOT a PHP TimedJob" posture (`openspec/specs/attendance/spec.md`
  "Threshold crossing is a declared calculation trigger").
- `ConferenceSlot.confirmed` — `trigger.type: transition`, `action: confirm`, `recipients: [{kind:
  field, field: learnerId}]`.
- `ConferenceReport.recorded` — `trigger.type: transition`, `action: record`, `recipients: [{kind:
  field, field: learnerId}]`.

### Why `invitedLearnerIds` is denormalised onto `ConferenceRound`, not resolved dynamically from `cohortIds`

The verified dialect's `kind: field` recipient reads a property on the notifying object itself; it
has no "join through `cohortIds` to each `Cohort.learnerIds`" primitive (that is exactly the kind of
dynamic multi-hop resolution the `portal-parent` change had to solve with a bespoke reverse `via`
join *inside portaliq* — a much larger and differently-scoped mechanism than this app's own
notification dialect exposes). **Rejected alternative:** extend the notification dialect with a
new "resolve field from a joined object" recipient kind — rejected as scope creep on shared,
cross-app infrastructure (`ADR-031`) that a single M-size scholiq change should not be the one to
propose. **Chosen:** the `send-invitations` transition guard/handler (part of Step 0, not the
solver) computes `invitedLearnerIds` once from the round's `cohortIds` → each `Cohort.learnerIds`
and writes it onto the `ConferenceRound` object before firing the transition — a one-time
denormalisation at invitation time, not a live join, which is exactly the shape the dialect's
`kind: field` recipient already supports.

## Field projection / dossier visibility

`ConferenceReport` carries no portal-specific projection in this change (it is an NC-authenticated
surface only, per the Dependencies section of proposal.md) — `learnerId`/`teacherId`/`attendeeIds`
are all plain NC user ids, following `LearningPlanEvaluation`'s exact shape
(`attendeeIds: NC user IDs of meeting attendees`, `lib/Settings/scholiq_register.json`
`learning-plan-evaluation`). The additive `learnerRef` (UUID) is included from day one, unused by
any portal provider yet, following the same "additive and optional, fail-closed until backfilled"
convention already used on `ExcuseRequest.learnerRef` and `GradeEntry.learnerRef` — so a future
`portal-parent`-style read extension needs no schema migration, only a provider-side addition.

## Future extensions (explicitly out of scope here)

- Including `ConferenceReport` in the OSO transfer dossier composer's field list
  (`openspec/specs/data-exchange/spec.md:25`) — a `data-exchange` MODIFIED-requirement follow-up.
- A portaliq `parentContribution()` read (and, once portaliq's writer fix lands, create) extension
  for guardians without an NC account — a `portal-parent`-style follow-up once that upstream writer
  gap is closed (see proposal.md Dependencies).
- Unifying confirmed `ConferenceSlot`s into the `personal-timetable` read surface — that spec is
  deliberately `Session`/`Cohort`/`Enrolment`-only today; broadening it is a separate, small change.
