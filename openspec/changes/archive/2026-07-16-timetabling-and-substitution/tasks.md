# Tasks: timetabling-and-substitution

## 1. Schema — school-structure delta

- [x] 1.1 Add `Room` to `lib/Settings/scholiq_register.json`: `name`, `capacity` (integer, minimum 1),
  `kind` (`classroom|lab|gym|auditorium|online|other`), `facilities` (array of strings), `buildingCode`
  (nullable), `floor` (nullable), `tenant_id`. No `x-openregister-lifecycle` workflow. Title/description per
  ADR-011 on every property.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-room-is-persisted-as-a-bookable-resource`
  - **acceptance_criteria**:
    - Schema validates against the register's existing OpenAPI 3.0.0 conventions
    - `capacity` is required and numeric; `facilities` is an array of strings
- [x] 1.2 Add to `Session`: `roomId` (nullable, `$ref Room`), `externalRef` (nullable string),
  `substituteTeacherId` (nullable string), `changeReasonKind` (nullable enum:
  `teacher-absence|room-unavailable|timetable-change|other`), `changeReason` (nullable string),
  `affectedLearnerIds`/`affectedParentIds` (nullable arrays of strings). Purely additive; do not touch
  `required`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-session-references-a-room-and-carries-substitution-and-import-metadata`
  - **acceptance_criteria**:
    - Existing `Session` rows validate unchanged (all new fields absent/null)
    - `location` field is untouched
- [x] 1.3 Add `substitute-teacher` self-loop transition to `Session.x-openregister-lifecycle.transitions`
  (`from: [scheduled, in-progress]`, `to:` same state); add `requires: SessionChangeGuard` to both `cancel`
  and `substitute-teacher`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-session-references-a-room-and-carries-substitution-and-import-metadata`
  - **acceptance_criteria**:
    - `substitute-teacher` transition does not change `lifecycle` value
    - Both transitions declare `requires: SessionChangeGuard`
- [x] 1.4 Add `x-openregister-relations`: `Session.roomId` → `Room`; update the school-structure register
  documentation block accordingly.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-persist-school-structure-domain-objects-in-openregister`

## 2. Schema — timetabling capability

- [x] 2.1 Add `TimetableConflict`: `kind` (`teacher-double-booking|room-double-booking|
  cohort-double-booking|learner-double-booking|room-capacity-exceeded|exam-clash`), `sessionIds` (array of
  `$ref Session` UUIDs), `scopeRef` (nullable string — the teacherId/roomId/cohortId/learnerId in
  conflict), `severity` (`error|warning`), `detectedAt`, `lifecycle` (`open → acknowledged → resolved`),
  `resolutionNote` (nullable), `tenant_id`. `x-openregister-notifications.created` → coordinator group
  (`kind: groups`), NL/EN subject.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them`
  - **acceptance_criteria**:
    - `sessionIds` array, `kind` enum, `lifecycle` workflow all present
    - Notification recipients + subject present in both `nl`/`en`
    - `x-openregister-authorization`: read restricted to admin/coordinator/scheduling roles
- [x] 2.2 Add `ExamAccommodation`: `learnerId`, `assessmentId` (nullable, `$ref Assessment`),
  `accommodationKind` (`extra-time-percentage|separate-room|reader|screen-reader-software|rest-breaks|
  other`), `value` (nullable number), `evidenceRef` (OR file attachment reference), `approvedBy` (nullable),
  `lifecycle` (`requested → approved → active → expired | revoked`), `tenant_id`.
  `x-openregister-authorization.approve`: `admin`/`compliance-officer`/`mentor` only; `create` open to
  learner/parent-portal roles.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-exam-accommodations-are-recorded-as-approved-evidence-backed-entitlements`
  - **acceptance_criteria**:
    - `evidenceRef` present and references an OR file attachment, not inline bytes
    - `approve` transition authorization excludes the default learner role
- [x] 2.3 Add `DataMappingProfile` entries (existing schema, new rows) for `target: timetable-import`
  covering Zermelo, Untis, and Xedule field-mapping profiles (shipped as seed data or admin-configurable,
  matching the existing BRON/ROD/OSO profile precedent).
  - **spec_ref**: `specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob`

## 3. Backend — PHP

- [x] 3.1 `OCA\Scholiq\Lifecycle\SessionChangeGuard` — refuses `cancel`/`substitute-teacher` unless the
  caller is a `Cohort.teacherIds` member or admin/coordinator (server-resolved NC user id), and unless
  `changeReasonKind` (and, for `substitute-teacher`, `substituteTeacherId`) is set.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard`
  - **acceptance_criteria**: PHPUnit covers authorized-teacher-succeeds, outside-teacher-refused,
    missing-reason-refused
- [x] 3.2 `OCA\Scholiq\Listener\SessionChangeNoticeHandler` — on `cancel`/`substitute-teacher`, resolves
  `Cohort.learnerIds` → `affectedLearnerIds`, then each learner's `LearnerProfile.parentIds` →
  `affectedParentIds`, persists both onto the Session before the notification rule evaluates.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents`
  - **acceptance_criteria**: PHPUnit verifies both arrays are correctly resolved and persisted before the
    transition completes
- [x] 3.3 `OCA\Scholiq\Timetabling\TimetableConflictDetector` + `OCA\Scholiq\Listener\
  SessionConflictListener` — OR-event listener on `Session` create/update, plus a batch invocation after a
  `timetable-import` `DataExchangeJob` reaches `succeeded`. Implements the pairwise overlap scan (teacher/
  room/cohort/learner double-booking, room-capacity-exceeded, exam-clash) scoped to the affected window;
  idempotent `TimetableConflict` upsert by `(sessionIds, kind)`.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them`
  - **acceptance_criteria**: PHPUnit covers each conflict `kind`, the idempotent-rescan case, and asserts no
    `Session` field is ever written by the detector
- [x] 3.4 `OCA\Scholiq\Timetabling\TimetableImportHandler` — `DataExchangeJob` execution handler for
  `target: timetable-import`; resolves the `DataMappingProfile`, upserts `Session` objects keyed by
  `externalRef`, triggers `SessionConflictListener`'s batch scan on completion.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-timetable-import-upserts-session-objects-idempotently-by-externalref`
  - **acceptance_criteria**: PHPUnit covers create-on-first-import, update-on-re-import (no duplicate),
    manually-created-Session-untouched

## 4. Frontend

- [x] 4.1 `src/manifest.json`: index/detail pages for `Room`, `TimetableConflict`, `ExamAccommodation`.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views`
- [x] 4.2 `src/dialogs/SubstitutionModal.vue` — mark a Session cancelled or assign a substitute teacher,
  requiring `changeReasonKind` (and `substituteTeacherId` for substitution) before submit.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views`
- [x] 4.3 `src/views/TimetableConflictQueue.vue` — coordinator review surface for `open`/`acknowledged`
  `TimetableConflict` rows, with acknowledge/resolve actions.
  - **spec_ref**: `specs/timetabling/spec.md#requirement-detected-conflicts-are-queued-for-coordinator-review`
- [x] 4.4 Extend `src/views/MyTimetable.vue` and `src/api/timetable.js` to render `roomId`/Room detail,
  `lifecycle`, `substituteTeacherId`, `changeReasonKind`, `changeReason`, and the same-day `changes` list.
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions`

## 5. Backend — TimetableController projection

- [x] 5.1 Extend `lib/Controller/TimetableController.php::fetchSessions()` to project `roomId` (+ resolved
  Room `name`/`capacity`/`facilities` when set), `substituteTeacherId`, `changeReasonKind`, `changeReason`.
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions`
  - **acceptance_criteria**: existing PHPUnit tests for `mine()` still pass unmodified; new assertions cover
    the added fields
- [x] 5.2 Add a `changes` computation to `TimetableController::mine()`: caller's cohorts' Sessions whose
  `cancel`/`substitute-teacher` transition timestamp falls today, regardless of the requested window.
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions`
  - **acceptance_criteria**: PHPUnit covers a Session scheduled outside the window but changed today still
    appearing in `changes`
- [x] 5.3 Confirm no new write path was added to `TimetableController` (read-only invariant preserved).
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-the-timetable-is-a-read-surface-only-over-existing-objects`

## 6. Tests

- [x] 6.1 PHPUnit: `SessionChangeGuardTest`, `SessionChangeNoticeHandlerTest`, `TimetableConflictDetectorTest`
  (all six conflict kinds + idempotent rescan), `TimetableImportHandlerTest` (create/update/no-duplicate/
  manual-Session-untouched).
- [x] 6.2 PHPUnit: `TimetableControllerTest` additions for the new projected fields and the `changes` list.
- [x] 6.3 Register-validation suite: confirm `Room`, `TimetableConflict`, `ExamAccommodation`, and the
  `Session` field additions validate against the register's OpenAPI conventions and the verified
  notification dialect.

## 7. Docs / follow-ups

- [ ] 7.1 File `ConductionNL/openconnector` issues for the Zermelo/Untis/Xedule `timetable-import` source
  adapters (out of scope for this change — Scholiq only defines the `DataMappingProfile` contract).
- [ ] 7.2 File a follow-up against the `assessment` capability to wire `ExamAccommodation`'s effective time
  limit into `TakeAssessmentView`/`AssessmentResult` (explicitly deferred — see `design.md` "Exam
  accommodations").
- [x] 7.3 Update `docs/ARCHITECTURE.md` with `Room`, `TimetableConflict`, `ExamAccommodation`, and the
  `timetable-import` `DataExchangeJob` target.
