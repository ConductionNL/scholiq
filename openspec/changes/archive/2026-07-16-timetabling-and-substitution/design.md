# Design: timetabling-and-substitution

## Context

`school-structure` (`openspec/specs/school-structure/spec.md:96`) explicitly puts "Room-booking conflict
resolution and timetabling optimisation" out of scope: "a Session just records a location string."
`personal-timetable` (`openspec/specs/personal-timetable/spec.md:39-58`) is explicitly read-only over
existing objects. Both are correct — but the gap between them is where a real school lives day to day: a
generated timetable has to land somewhere, double-bookings in that import have to be caught, a sick teacher
has to be substituted before first period, learners and parents have to find out before they walk to the
wrong room, and exam day needs room capacity and legally-required accommodations sorted out. This document
works out the data model, the import/detection/substitution flows, and — first — the strategic boundary
that keeps this change from turning into "Scholiq builds a worse Zermelo."

## The Zermelo boundary: import-and-operate, not optimise

Zermelo (reported ~1,050 NL VO schools / 780k students) and Untis run genuine constraint-solving
optimisers: hundreds of teachers, rooms, and cohorts, multi-day soft/hard-constraint solving, years of
tuning. Reproducing that inside a school-administration app would take a multi-year engineering investment
to build something schools that already pay for Zermelo have no reason to switch away from. Magister, by
contrast, does **not** try to out-solve Zermelo either — it consumes a generated timetable and owns the
operational layer on top of it (viewing, substitution, day-to-day changes). That is the model this change
follows:

- **Scholiq does not generate a timetable.** No solver, no constraint engine, no "auto-schedule" button.
- **Scholiq imports a generated timetable** through the same delegate-the-wire-protocol pattern
  `data-exchange` already established for BRON/ROD/OSO/leerplicht
  (`openspec/specs/data-exchange/spec.md:19,66-77`): a `DataExchangeJob` with `target: timetable-import`
  hands the payload to an OpenConnector source configured for Zermelo/Untis/Xedule's export format (JSON,
  CSV, or their native APIs — OpenConnector's problem, not this change's). Scholiq defines the *contract*
  (which fields a mapping profile must resolve to `Session`/`Room`/`Cohort`/`Course`), not the wire format.
- **Scholiq detects conflicts, it does not resolve them.** Import or manual edits can still produce a
  double-booking (a stale export, a manually-added Session, two source systems disagreeing). Scholiq flags
  it for a human; it never silently reassigns a room or bumps a class to make the conflict disappear — that
  would be exactly the optimisation this change deliberately avoids.
- **Scholiq owns the *operational record* of change**: substitutions, cancellations, exam-day room/
  accommodation logistics. This is inherently local, same-day, and about *people* (who's covering, who's
  notified, who gets extra time) — not a scheduling-theory problem, and not something Zermelo's export
  format has any opinion about.

**Reconsider if**: a buyer segment emerges with zero existing optimiser (small MBO/corporate training that
never bought Zermelo/Untis) and needs Scholiq to *generate* a first-draft timetable, not just operate one.
That is a different, much larger change (a constraint solver) and is explicitly not scoped here.

## Goals / Non-Goals

**Goals**
- Let a `Session` reference a real `Room` (capacity, facilities) instead of only a free-text string.
- Accept a generated timetable from an external optimiser via OpenConnector, idempotently.
- Detect (not resolve) teacher/room/cohort/learner double-bookings and exam-specific clashes/capacity
  overruns.
- Let a coordinator mark a Session cancelled or substituted, always with a reason, and reliably notify
  affected learners and parents through the existing verified notification dialect and quiet-hours engine.
- Extend the existing read-only personal-timetable surface with room detail and same-day changes.
- Record per-learner exam accommodations (extra time, separate room, etc.) as approved, evidence-backed
  entitlements.

**Non-Goals**
- Timetable *generation* / constraint-solving optimisation (Zermelo's/Untis's job, permanently).
- Automatic conflict *resolution* (a human decides; this change only detects and surfaces).
- The OpenConnector Zermelo/Untis/Xedule wire adapters themselves (separate `openconnector` issues).
- Wiring `ExamAccommodation`'s effective time limit into `assessment`'s `TakeAssessmentView`/
  `AssessmentResult` (flagged as an `assessment`-capability follow-up in `tasks.md`; this change only
  models and RBAC-gates the accommodation data itself).
- Literal exam seating charts (only room-level capacity, not per-seat assignment).

## Data Model

```
                     ┌────────────────────────────────────────────┐
                     │  DataExchangeJob (existing, data-exchange)  │
                     │  target: timetable-import                  │
                     │  scope: {cohortIds?, period, provider}      │
                     └───────────────┬──────────────────────────────┘
                                     │ TimetableImportHandler (ADR-031 external-system bridge)
                                     │ resolves via DataMappingProfile (existing, target-scoped)
                                     ▼
Room (new) ──────────< Session (school-structure, MODIFIED)
  capacity, kind,          roomId, externalRef (idempotency key for re-import upsert),
  facilities, building/    substituteTeacherId, changeReasonKind, changeReason,
  floor, tenant_id         affectedLearnerIds / affectedParentIds (materialised, like
                           ConferenceRound.invitedLearnerIds),
                           lifecycle: scheduled → in-progress → completed | cancelled (existing)
                           + new self-loop transition: substitute-teacher (scheduled|in-progress → same)
                           both `cancel` and `substitute-teacher` require SessionChangeGuard
                                     │
                                     │ SessionConflictListener (OR-event, create/update)
                                     │ + batch run after a timetable-import DataExchangeJob succeeds
                                     ▼
                     TimetableConflict (new, appendable workflow)
                       kind: teacher-double-booking | room-double-booking | cohort-double-booking |
                             learner-double-booking | room-capacity-exceeded | exam-clash
                       sessionIds[], scopeRef (teacherId|roomId|cohortId|learnerId), severity,
                       lifecycle: open → acknowledged → resolved
                       x-openregister-notifications: created → coordinator group

ExamAccommodation (new)
  learnerId, assessmentId (nullable — generic vs per-assessment override), accommodationKind
  (extra-time-percentage | separate-room | reader | screen-reader-software | rest-breaks | other),
  value (e.g. percentage), evidenceRef (OR file attachment — the verklaring), approvedBy,
  lifecycle: requested → approved → active → expired | revoked
```

### `Room`

A plain resource, not a workflow object: `name`/`code`, `capacity` (integer), `kind`
(`classroom|lab|gym|auditorium|online|other`), `facilities` (array of strings — projector, whiteboard,
lab-equipment…), `buildingCode`/`floor` (nullable), `tenant_id`. No `x-openregister-lifecycle` beyond the
standard `x-openregister.active` toggle — mirrors `Material`'s "metadata object, not a workflow" shape
(`openspec/specs/school-structure/spec.md:27`) rather than `Session`'s state machine.

### `Session` additions

All additive, mirroring the `Course.ectsCredits` precedent (`openspec/changes/archive/
2026-07-13-bsa-study-progress-guard/specs/course-management/spec.md`) — existing rows leave every new field
`null`/empty and continue to validate:

- `roomId` (nullable, `$ref Room`) — the canonical structured room reference. `location` (existing free
  string) is **kept**, not replaced: it remains the display label for an online-meeting URL or for a
  Session that has no `Room` row (e.g. an off-site training venue). A Session with `roomId` set SHOULD
  treat `location` as a display convenience only; consumers reading the room's real capacity/facilities MUST
  read `Room`, not parse `location`.
- `externalRef` (nullable string) — the source system's own identifier for this occurrence (Zermelo/Untis
  event id). Used only for idempotent upsert on re-import; never shown as a user-facing field.
- `substituteTeacherId` (nullable, NC user id).
- `changeReasonKind` (nullable enum: `teacher-absence|room-unavailable|timetable-change|other`).
- `changeReason` (nullable free text).
- `affectedLearnerIds` / `affectedParentIds` (materialised NC-user-id arrays) — see "Substitution
  notification posture" below.

### Lifecycle: `substitute-teacher` self-loop + guarded `cancel`

`ConferenceRound.regenerate` (`lib/Settings/scholiq_register.json`, `x-openregister-lifecycle.transitions`)
is an existing precedent for a same-state transition (`scheduled → scheduled`) used purely to get a named,
guardable, notification-triggerable event out of the OR lifecycle engine. `Session` gains an identical
`substitute-teacher` transition (`from: [scheduled, in-progress], to: same state`) requiring
`substituteTeacherId` and `changeReasonKind` to be set on the transition payload. The existing `cancel`
transition (`scheduled|in-progress → cancelled`) is unchanged in shape but gains `requires:
SessionChangeGuard`, which now additionally requires `changeReasonKind` — "no cancellation without a
reason," the same class of structural invariant as `BsaDecisionGuard`'s "no negative BSA without a logged
warning" (`openspec/changes/archive/2026-07-13-bsa-study-progress-guard/design.md`).

This is why substitution could not be modelled as a plain field update: `scholiq-notifications`
(`openspec/specs/scholiq-notifications/spec.md:16`) states the verified `x-openregister-notifications`
dialect's `trigger.type` is one of `created|updated|transition|scheduled|threshold|calculatedChange`, and
elsewhere explicitly defers "non-numeric field change" conditions as having no verified equivalent. A named
transition is the only verified-dialect-compatible way to fire "a substitute was just assigned."

## Conflict-detection algorithm (detection, not optimisation)

`TimetableConflictDetector`, invoked by `SessionConflictListener` on every `Session` create/update and once
in batch after a `timetable-import` `DataExchangeJob` reaches `succeeded`:

1. **Scope the scan** to the affected window (the changed Session's day, or the imported batch's date
   range) — never a full-register scan, to keep this an O(sessions-in-window) operation, not O(all
   sessions ever).
2. For each pair of `Session`s in scope whose `[startsAt, endsAt)` intervals overlap, check:
   - **teacher-double-booking**: do their cohorts' `teacherIds` (or, once substituted, `substituteTeacherId`)
     intersect?
   - **room-double-booking**: same non-null `roomId`?
   - **cohort-double-booking**: same `cohortId` (a cohort scheduled twice at once — an import/data error)?
   - **learner-double-booking**: do the two Sessions' cohorts' `learnerIds` (resolved once per scan window,
     not per pair) intersect — catching a learner enrolled across cohorts with an overlap a same-cohort
     check would miss?
3. **room-capacity-exceeded**: for a Session with both `roomId` and a linked `Assessment` (exam context),
   compare the candidate count (`Cohort.learnerIds` length for the Session's cohort, unless the exam scope
   is otherwise narrowed) against `Room.capacity`.
4. **exam-clash**: any of the above overlap kinds where at least one of the two Sessions has a linked
   `Assessment` — surfaced as its own `kind` because a coordinator triages exam clashes with higher urgency
   than an ordinary lesson clash.
5. Each finding becomes (or updates, if the same `sessionIds` pair + `kind` already has an `open`
   `TimetableConflict`) a `TimetableConflict` row — idempotent by design so a re-scan of an unchanged window
   does not spam duplicate conflicts. Nothing is auto-resolved: the detector only ever writes
   `TimetableConflict` rows and a `created` notification to the coordinator group; it never edits a
   `Session`.

This is deliberately a listener-driven "cross-object write bridge" (the same ADR-031 exception class as
`ConferenceScheduleGenerator`), not a declared `x-openregister-calculations` entry, because a conflict is a
relationship *between* two or more `Session` rows, not a property materialisable on one row.

## Substitution notification posture

Mirrors `ConferenceRound.invitedLearnerIds` exactly
(`lib/Settings/scholiq_register.json:11164`, described in its own field text as "computed once from
cohortIds → each Cohort.learnerIds and persisted at the send-invitations transition"): a
`SessionChangeNoticeHandler` (ADR-031 "cross-object write bridge," same shape as
`ConferenceScheduleGenerator`) runs at both the `cancel` and `substitute-teacher` transitions and:

1. Resolves `Cohort.learnerIds` for the Session's `cohortId` (single hop) into `affectedLearnerIds`.
2. Resolves each of those learners' `LearnerProfile.parentIds` (a second hop — this is the reason a PHP
   handler is needed rather than a JSON-logic expression, matching the cross-schema-join rationale already
   accepted for `BsaProgressEvaluator`/`GradeFormulaEvaluator`) into `affectedParentIds`.
3. Persists both arrays onto the `Session` at transition time (denormalised, exactly like
   `invitedLearnerIds`), so the verified dialect's `kind:field` recipient can resolve them without a runtime
   join.

Two declared `x-openregister-notifications` rules (`trigger.type: transition`, `action: cancel` /
`action: substitute-teacher`) then fire to `recipients: [{kind: field, field: affectedLearnerIds},
{kind: field, field: affectedParentIds}]` with an inline `nl`/`en` subject naming the changed Session. Per
`scholiq-notifications`'s existing per-user override and quiet-hours requirements
(`openspec/specs/scholiq-notifications/spec.md` — "Notification delivery MUST honor the per-user override
preference"), this change adds **no** local suppression or quiet-hours logic of its own — delivery timing
and opt-out are entirely OpenRegister's dispatcher's job, exactly as `scholiq-notifications` already
mandates for every other rule in the register. A same-day cancellation is time-sensitive, but this change
does not special-case it against quiet hours; that trade-off already exists for every other notification in
this app and is out of scope to relitigate here.

## Exam accommodations

`ExamAccommodation` records a learner's approved entitlement (`accommodationKind`, a `value` where
applicable, an `evidenceRef` OR file attachment for the verklaring, `approvedBy`, and a
`requested → approved → active → expired | revoked` lifecycle). `x-openregister-authorization.create` is
restricted to `admin`/`compliance-officer`/`mentor` roles (mirrors `BsaWarning`'s restriction pattern) — a
learner cannot self-approve their own accommodation, though a learner or parent can be the one who
*requests* it (creating a `requested` row is intentionally left open to the learner/parent-portal roles;
only the `approve` transition is role-restricted).

This change deliberately stops at **recording** the entitlement, correctly evidenced and RBAC'd. Computing
an "effective time limit" for a specific `Assessment` sitting and having `TakeAssessmentView` honour it is
left as an explicit `assessment`-capability follow-up (see `proposal.md` Impact and `tasks.md`) rather than
folded into this change's scope, for two reasons: (1) it requires reading `Assessment.timeLimitMinutes` and
writing into `AssessmentResult`'s existing calculation shape, which belongs to a capability this change was
scoped not to touch; (2) shipping the *data model and legal evidence trail* now, correctly, is more valuable
and lower-risk than shipping a half-wired time-limit calculation that could silently not apply during an
actual exam.

## Security / RBAC posture

- `Room` — read-open (any authenticated user can see room capacity/facilities to understand their own
  timetable), write restricted to `admin`/`facilities`-equivalent roles via
  `x-openregister-authorization.create/update` (mirrors the general "coordinator writes, learner reads"
  posture used throughout `school-structure`).
- `Session.cancel` / `Session.substitute-teacher` — both transitions require the caller to be a teacher of
  the Session's cohort or `admin`/coordinator, enforced by `SessionChangeGuard` resolving the caller's NC
  user id server-side (never a client-supplied claim) against `Cohort.teacherIds`, the same pattern
  `ConferenceSignupGuardianGuard` already uses for guardian/self-signup authorization
  (`openspec/specs/parent-conferences/spec.md:67-71`).
- `TimetableConflict` — visible to `admin`/coordinator/scheduling roles only (not learners/parents); a
  double-booking is an internal operational signal, not a fact to surface to the people affected by it (they
  get the substitution/cancellation notification once a human has acted on it, not the raw conflict).
- `ExamAccommodation` — `evidenceRef` file attachments and the record itself are AVG-sensitive (health/
  disability-adjacent data); read access is restricted to `admin`/`compliance-officer`/`mentor`/the learner
  themselves (and their guardian, via the existing `parentIds`/`guardianRefs` portal-scoping pattern already
  used by `ExcuseRequest`/`SupportRequest`), never broadcast to a Session's other cohort members.
- `TimetableImportHandler` — the one OR-event-driven bridge to OpenConnector; it runs under the
  `DataExchangeJob`'s existing authorization posture (`data-exchange` capability, unmodified), not a new
  auth surface.

## Rejected Alternatives

- **Build a constraint-solving timetable generator inside Scholiq.** Rejected — see "The Zermelo boundary"
  above; this is a multi-year, high-risk investment competing with an incumbent most buyers already own, for
  no proportionate return.
- **Model substitution as a plain field update (`substituteTeacherId` set via ordinary object update, no
  transition).** Rejected — `scholiq-notifications` has no verified-dialect trigger for a non-numeric field
  change, so this would leave the notification either undeliverable through the verified dialect or
  requiring a second, unverified/legacy notification path — exactly what `scholiq-notifications` was built
  to eliminate.
- **Auto-resolve detected conflicts (e.g. auto-reassign a room).** Rejected — this is the "timetabling
  optimisation" `school-structure` explicitly disclaims, and auto-moving a class without a human decision
  is worse than surfacing the conflict, not better.
- **A new parallel `TimetableImportJob` schema instead of reusing `DataExchangeJob`.** Rejected — `
  data-exchange` already generalises exactly this shape (`direction: import`, a named `target`) and two
  other capabilities (`course-management`'s catalog publication, `learning-plan`'s SWV dossier) already
  extend it by adding a `target` rather than a new schema; a parallel job schema would be the same "second
  mechanism" anti-pattern the BSA change's design explicitly rejected for a nightly `TimedJob`.
  - **Reconsider if**: timetable import needs a fundamentally different lifecycle shape (e.g. streaming/
    incremental sync rather than request/response jobs) that `DataExchangeJob`'s `queued → running →
    succeeded | failed | partial` cannot express — not the case today.
- **Fold `ExamAccommodation` into `SupportRequest`/`LearningPlan`.** Rejected — `SupportRequest` is the SWV
  zorgvraag routing process (a different legal track, PO→VO/SWV-facing); `LearningPlan` is a goals/
  interventions document. Exam accommodation is a narrower, evidence-and-approval entitlement record that
  needs its own lifecycle and its own restricted-write posture, not a sub-field of either.
