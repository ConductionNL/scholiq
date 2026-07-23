---
kind: code
depends_on: []
---

## Why

Scholiq's scheduling backbone (`school-structure`) deliberately draws a hard line around itself. The spec
says so in its own words: `openspec/specs/school-structure/spec.md:96` lists, under "Out of Scope",
"Room-booking conflict resolution and timetabling optimisation (a Session just records a location
string)." That is verified at `lib/Settings/scholiq_register.json:3566` — `Session.location` is a plain
`type: string` ("Room code or online URL"), not a reference to any bookable resource; there is no `Room`
schema anywhere in the register's 59 schemas (confirmed by a full-file grep for `"slug": "room"` — zero
hits). `personal-timetable` draws an equally explicit line the other way:
`openspec/specs/personal-timetable/spec.md:39-45` — "The timetable is a read surface only, over existing
objects" — "MUST NOT introduce a new schema, new storage, or a scheduling engine … Creating or editing
sessions remains owned by the existing session / attendance surfaces; the timetable only reads." Verified
against the live controller: `lib/Controller/TimetableController.php` (397 lines) does exactly one thing —
`TimetableController::mine()` resolves the caller's cohorts and projects `Session` rows
(`id`, `title`, `startsAt`, `endsAt`, `location`, `cohortId`, `courseId`, `lessonId`, `lifecycle`); it never
writes.

Both boundaries are correct engineering calls, not gaps — but together they leave a real hole. The Dutch VO
scheduling market is dominated by **Zermelo** (reported at roughly 1,050 schools / 780k students) running a
genuine constraint-solving optimiser most institutions have already bought and are not going to replace;
**Magister** ships day-to-day substitution ("lesuitval-vervanging") handling on top of whatever timetable it
is given. Scholiq trying to out-solve Zermelo's optimiser inside a school-administration app would be
building a worse version of a product schools already own. But *something* still has to own the day the
optimiser hands off to: importing the generated timetable, catching the double-bookings that slip through a
data import, handling the 07:15 "meneer De Vries is ziek" substitution, telling learners and parents their
afternoon just changed, and running exam-day room/accommodation logistics that no generic optimiser output
covers. Right now nothing in Scholiq owns that layer — `school-structure` explicitly refuses it, and
`personal-timetable` explicitly refuses to be anything but a read mirror.

The precedent for "delegate the wire protocol, own the operational record" already exists and works:
`openspec/specs/data-exchange/spec.md:19` states the same posture for BRON/ROD, OSO, and leerplicht — "The
actual wire protocols … live in OpenConnector source/target configurations" while Scholiq owns the
`DataExchangeJob` queue, `DataMappingProfile`, and the audit trail
(`lib/Settings/scholiq_register.json:9806`, `:9502`). `course-management`'s catalog publication and
`learning-plan`'s SWV zorgvraag dossier both ride that same `DataExchangeJob` mechanism by adding a new
`target` value rather than inventing a parallel job schema (`openspec/specs/data-exchange/spec.md:66-77`,
`openspec/specs/learning-plan/spec.md:135-143`) — this change follows the identical pattern for a
Zermelo/Magister/Untis/Xedule-generated timetable (`target: timetable-import`).

The notification and cross-object-materialisation muscle also already exists and is directly reusable.
`ConferenceRound.invitedLearnerIds` (`lib/Settings/scholiq_register.json:11164`) is "computed once from
cohortIds → each Cohort.learnerIds and persisted at the send-invitations transition (denormalised so the
verified notification dialect's kind:field recipient can resolve it)" — exactly the shape needed to notify
"affected learners/parents" when a `Session` is cancelled or gets a substitute teacher. And
`ConferenceRound.regenerate` (`lib/Settings/scholiq_register.json`, transitions block) is a precedent for a
same-state self-loop lifecycle transition (`scheduled → scheduled`) purely to get a named, guardable,
notification-triggerable event — the shape this change needs for "assign a substitute teacher without
changing the Session's lifecycle state." `openspec/specs/scholiq-notifications/spec.md:16` is explicit that
the verified dialect's `trigger.type` is one of `created|updated|transition|scheduled|threshold|
calculatedChange` and that a bare "field changed" condition has **no verified equivalent**
(`openspec/specs/scholiq-notifications/spec.md` — "Rules with no verified-dialect equivalent MUST be
deferred") — which is precisely why substitution notification must ride a named `transition`, not a generic
field-update watch.

Exam accommodations are a real, currently-unmodelled legal duty. A learner with a dyslexie- or
ADHD-verklaring is legally entitled to adjustments (typically extra time) on formal assessments; nothing in
`Assessment`/`AssessmentResult` (`openspec/specs/assessment/spec.md`, read in full) or `LearnerProfile`
(`lib/Settings/scholiq_register.json` — properties read in full) records such an entitlement, its evidence,
or who approved it — confirmed by a case-insensitive grep across the register and all 30 capability specs
for `dyslex|ADHD|verklaring|accommodat|extra.?time`: zero hits outside this change.

## What Changes

- **New `timetabling` capability** — the operational layer that sits between "an external optimiser
  produced a timetable" and "a learner/teacher sees today's reality," without competing with the optimiser
  itself:
  - **Import seam**: a generated timetable from Zermelo/Untis/Xedule is delegated to OpenConnector through
    the existing `DataExchangeJob`/`DataMappingProfile` mechanism (`target: timetable-import`), landing as
    idempotent `Session` upserts keyed by a new `externalRef`. Scholiq defines the contract (what fields an
    import must map to); it implements no wire protocol. The OpenConnector adapters themselves are filed as
    a cross-repo follow-up against `ConductionNL/openconnector`.
  - **Conflict detection, not optimisation**: a `TimetableConflictDetector` (an OR-event-driven "cross-object
    write bridge," the same class of ADR-031 exception as `ConferenceScheduleGenerator`) flags — never
    auto-resolves — teacher/room/cohort double-bookings, a learner with two overlapping Sessions, and (see
    exam extras below) room-capacity overruns and exam clashes, as append-workflow `TimetableConflict`
    records queued for a coordinator.
  - **Substitution / lesuitval-vervanging workflow**: mark a `Session` cancelled, or assign a substitute
    teacher via a new guarded `substitute-teacher` self-loop transition, always with a required reason;
    affected learners and their parents are notified through the verified `x-openregister-notifications`
    dialect (`transition` trigger, `kind:field` recipients on materialised `affectedLearnerIds`/
    `affectedParentIds` — the `ConferenceRound.invitedLearnerIds` pattern applied to Session changes) and
    honour the existing quiet-hours/delivery-window engine per `scholiq-notifications` — this change adds no
    local suppression logic.
  - **Dagrooster distribution**: today's Session changes (cancellations, substitutions) are surfaced through
    an extended `personal-timetable` read surface, without breaking its read-only invariant.
  - **Exam scheduling extras**: room allocation validated against `Room.capacity`, and a new
    `ExamAccommodation` schema recording per-learner, evidence-backed entitlements (extra time, separate
    room, etc.) approved by an authorised role — feeding the same conflict-detection engine rather than a
    parallel mechanism. Wiring the accommodation's effective time limit into `TakeAssessmentView` touches the
    `assessment` capability and is flagged as a follow-up (see Impact), not implemented in this change.
- **`school-structure` delta (MODIFIED/ADDED)**: a new `Room` resource (capacity, kind, facilities,
  building/floor) so a `Session` can reference a real bookable resource instead of only a free-text
  `location` string (which is kept, additively, for the online-URL / display-label case); `Session` gains
  `roomId`, `externalRef`, and the substitution fields (`substituteTeacherId`, `changeReasonKind`,
  `changeReason`, `affectedLearnerIds`, `affectedParentIds`), a `SessionChangeGuard` on `cancel` requiring a
  reason, and the new `substitute-teacher` transition.
- **`personal-timetable` delta (MODIFIED)**: the existing read-only `mine()` surface now also projects
  `roomId`/room details and substitution fields on each `Session`, plus a same-day "changes" list — still no
  new schema, no new storage, no write path.

## Impact

- **`lib/Settings/scholiq_register.json`** — new schemas `Room`, `TimetableConflict`, `ExamAccommodation`;
  additive fields on `Session` (`roomId`, `externalRef`, `substituteTeacherId`, `changeReasonKind`,
  `changeReason`, `affectedLearnerIds`, `affectedParentIds`) and a new lifecycle transition
  (`substitute-teacher`, self-loop) plus a guard on `cancel`.
- **New PHP** — `OCA\Scholiq\Lifecycle\SessionChangeGuard` (lifecycle guard), `OCA\Scholiq\Listener\
  SessionChangeNoticeHandler` (materialises `affectedLearnerIds`/`affectedParentIds` at the `cancel`/
  `substitute-teacher` transitions, mirrors `ConferenceScheduleGenerator`), `OCA\Scholiq\Listener\
  SessionConflictListener` + `OCA\Scholiq\Timetabling\TimetableConflictDetector` (OR-event-driven cross-object
  conflict scan), `OCA\Scholiq\Timetabling\TimetableImportHandler` (`DataExchangeJob` `target:
  timetable-import` execution — an ADR-031 "external-system bridge" exception, the same shape as
  `data-exchange`'s existing job-execution handler). No new PHP CRUD controller; `TimetableController`
  (personal-timetable) gains projected fields only, no new write endpoints.
- **`src/manifest.json`** — index/detail pages for `Room`, `TimetableConflict`, `ExamAccommodation`; named
  custom views `SubstitutionModal.vue` and `TimetableConflictQueue.vue`. `MyTimetable.vue` extended to
  render the new fields; no new custom view required there.
- **Affected specs**: new `timetabling` capability; `school-structure` (MODIFIED — `Room`, `Session`
  fields/transition); `personal-timetable` (MODIFIED — extended read projection). `data-exchange` and
  `scholiq-notifications` are consumed, unmodified — this change adds a `DataExchangeJob` target and
  notification rules using mechanisms both specs already declare generically.
- **Out of scope / follow-ups**: the OpenConnector Zermelo/Untis/Xedule adapters themselves (separate
  `ConductionNL/openconnector` issues); wiring `ExamAccommodation`'s effective time limit into
  `assessment`'s `TakeAssessmentView`/`AssessmentResult` (a small `assessment`-capability follow-up, tracked
  in `tasks.md`, not a spec delta here); literal exam seating charts; and any actual constraint-solving
  timetable *generation* — that remains Zermelo's/Untis's job, permanently, by design.
