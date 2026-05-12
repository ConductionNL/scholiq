# Tasks — Attendance (capability)

> Scope: 4 new schemas (AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag), 3 PHP exceptions (ExcuseApprovalHandler + AttendanceFlagCreationHandler + AttendanceFlagReportGuard), manifest pages + 2 custom Vue views, l10n (en+nl). Count: 29 → 33.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `AttendanceRecord` schema per design §2.1 — sessionId, learnerId, cohortId, status enum, minutesAttended, markedBy, markedAt, reason, excuseRequestId, tenant_id; x-openregister-calculations (isUnexcusedAbsence, lesuren); x-openregister-relations (session, learner, cohort, excuseRequest).
- [x] Add `ExcuseRequest` schema per design §2.2 — learnerId, submittedBy, dateFrom, dateTo, reason, reasonKind enum, attachmentRef, submittedAuthLevel enum, decidedBy, decidedAt, decisionNote, tenant_id; lifecycle submitted → approved | rejected; x-openregister-relations (learner).
- [x] Add `AttendanceThreshold` schema per design §2.3 — name, kind enum, scope enum, cohortId, window object, metric enum, limit, lesuurMinutes, onCross object, active, tenant_id; lifecycle draft → active → archived; x-openregister-calculations (unexcusedLesuren); x-openregister-notifications (onThresholdCrossed via calculatedChange — mirrors Regulation ragStatus pattern, NOT a TimedJob); x-openregister-relations (cohort); x-openregister-widgets (cohortAttendanceGrid).
- [x] Add `AttendanceFlag` schema per design §2.4 — appendOnly:true; learnerId, attendanceThresholdId, cohortId, windowStart, windowEnd, metricValue, breachingRecordIds, dataExchangeJobId, mentorId, tenant_id; lifecycle open → in-handling → reported → resolved; report transition requires AttendanceFlagReportGuard; x-openregister-calculations (daysSinceFlag); x-openregister-relations (learner, attendanceThreshold, cohort).
- [x] Validate JSON (`python3 -c 'import json; json.load(open(...))'`); no duplicate slugs; schema count 29 → 33. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Lifecycle/ExcuseApprovalHandler.php` — IEventListener for ObjectTransitionedEvent; filters to excuse-request → approved; queries AttendanceRecords for learnerId + absent-unexcused; filters date range in PHP; flips each to absent-excused + sets excuseRequestId; persists via ObjectService::saveObject.
- [x] Create `lib/Lifecycle/AttendanceFlagCreationHandler.php` — IEventListener for ObjectTransitionedEvent; filters to attendance-threshold calculatedChange crossing event; resolves mentorId from LearnerProfile; creates AttendanceFlag (open); records dataExchangeTarget intent on flag; does NOT auto-act against learner; includes TODO(data-exchange spec) for DataExchangeJob queueing.
- [x] Create `lib/Lifecycle/AttendanceFlagReportGuard.php` — guard for report transition; check() returns true; TODO(data-exchange spec) comment for tightening.
- [x] Register `ExcuseApprovalHandler` + `AttendanceFlagCreationHandler` in `Application.php` for `ObjectTransitionedEvent`.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add AttendanceRecords / AttendanceRecordDetail, ExcuseRequests / ExcuseRequestDetail, AttendanceThresholds / AttendanceThresholdDetail, AttendanceFlags / AttendanceFlagDetail (readOnly — appendOnly) pages.
- [x] Add MarkAttendanceView (custom, component=MarkAttendanceView) and SubmitExcuseModal (custom, component=SubmitExcuseModal) pages.
- [x] Add "Attendance" nav menu entry (order=55, route=AttendanceRecords).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/MarkAttendanceView.vue` — Session roster grid; bulk-mark shortcut; save → create/update one AttendanceRecord per learner. Options API + direct fetch; no Pinia module.
- [x] Create `src/views/SubmitExcuseModal.vue` — date range + reason + reasonKind + DigiD placeholder; POST ExcuseRequest. Options API + direct fetch; no Pinia module.
- [x] Register both in `src/main.js` via customComponents.
- [x] `npm run lint` 0 errors; `npm run stylelint` clean for new files; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages and the two custom views (plain-English keys, both languages).

## Phase 6: Spec-validation gate

- [x] `node tests/validate-json-strict.js` PASS.
- [x] `node tests/validate-register.js` PASS (slug uniqueness, lifecycle requires → PHP class exists).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.
