# Tasks — Attendance (capability)

> Scope: 4 new schemas (AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag), 3 PHP exceptions (ExcuseApprovalHandler + AttendanceFlagCreationHandler + AttendanceFlagReportGuard), manifest pages + 2 custom Vue views, l10n (en+nl), seed data. Schema count: 29 → 33.

## Phase 0: Deduplication check

- [ ] Search `openspec/specs/` and `lib/Service/` for any existing attendance, threshold, absence, leerplicht, or excuse handling. Document findings (expected: none — `absence-leerplicht` stub in school-structure is superseded by this change per `replaces: [absence-leerplicht]` in context-brief).
- [ ] Confirm no existing `AttendanceRecord`, `AttendanceThreshold`, `AttendanceFlag`, or `ExcuseRequest` slugs in `lib/Settings/scholiq_register.json`. If any collision found, resolve before proceeding.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `AttendanceRecord` schema (slug `attendance-record`) per design §2.1 — sessionId, learnerId, cohortId, status enum (present|absent-unexcused|absent-excused|late|left-early), minutesAttended, markedBy, markedAt, reason, excuseRequestId, tenant_id; `x-openregister-calculations`: isUnexcusedAbsence, lesuren; `x-openregister-relations`: session → Session, learner, cohort → Cohort, excuseRequest → ExcuseRequest. NOT appendOnly.
- [ ] Add `ExcuseRequest` schema (slug `excuse-request`) per design §2.2 — learnerId, submittedBy, dateFrom, dateTo, reason, reasonKind enum (illness|medical-appointment|family-circumstance|religious-observance|bereavement|other), attachmentRef, submittedAuthLevel enum (none|basic|substantial|high), decidedBy, decidedAt, decisionNote, tenant_id; `x-openregister-lifecycle`: submitted → approved | rejected; `x-openregister-relations`: learner.
- [ ] Add `AttendanceThreshold` schema (slug `attendance-threshold`) per design §2.3 — name, kind enum (leerplicht-16uur|college-aanwezigheid|training-attendance|compliance-presence|generic), scope enum (per-learner|per-cohort), cohortId, window object, metric enum (unexcused-lesuren|unexcused-sessions|attendance-percent-below), limit, lesuurMinutes, onCross object, active, tenant_id; `x-openregister-lifecycle`: draft → active → archived; `x-openregister-calculations`: unexcusedLesuren; `x-openregister-notifications`: onThresholdCrossed (calculatedChange trigger, idempotencyKey, recipientFromTenantRole — mirrors Regulation ragStatus pattern, NOT a TimedJob); `x-openregister-relations`: cohort → Cohort; `x-openregister-widgets`: cohortAttendanceGrid (type cohort-attendance-grid).
- [ ] Add `AttendanceFlag` schema (slug `attendance-flag`) per design §2.4 — appendOnly:true; learnerId, attendanceThresholdId, cohortId, windowStart, windowEnd, metricValue, breachingRecordIds (uuid[]), dataExchangeJobId, mentorId, tenant_id; `x-openregister-lifecycle`: open → in-handling → reported → resolved; report transition `requires: OCA\Scholiq\Lifecycle\AttendanceFlagReportGuard`; `x-openregister-calculations`: daysSinceFlag; `x-openregister-relations`: learner, attendanceThreshold → AttendanceThreshold, cohort → Cohort.
- [ ] Validate JSON: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'` — no parse errors, no duplicate slugs, schema count 29 → 33.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/ExcuseApprovalHandler.php` — `IEventListener<ObjectTransitionedEvent>`; filter to register=scholiq, schema=excuse-request, to=approved; read learnerId, dateFrom, dateTo, request.id; fetch all AttendanceRecords for learner with status=absent-unexcused via `ObjectService::findAll`; filter PHP-side to markedAt within [dateFrom, dateTo]; for each: set status=absent-excused, excuseRequestId=request.id, persist via `ObjectService::saveObject`. ADR-031 exception: cross-object write bridge on lifecycle transition.
- [ ] Create `lib/Lifecycle/AttendanceFlagCreationHandler.php` — `IEventListener<ObjectTransitionedEvent>` (or CalculatedChangeEvent if OR exposes one); filter to attendance-threshold calculatedChange event with to=threshold-crossed; read threshold.id, learnerId, cohortId, window, onCross; resolve mentorId from LearnerProfile.managerId; create AttendanceFlag (lifecycle=open) via `ObjectService::saveObject`; if onCross.dataExchangeTarget set, record intent on flag with dataExchangeJobId=null; include `// TODO(data-exchange spec)` comment; MUST NOT auto-act against learner. ADR-031 exception: new-object creation from calculatedChange event not expressible declaratively.
- [ ] Create `lib/Lifecycle/AttendanceFlagReportGuard.php` — guard for AttendanceFlag report transition; `check(array &$transitionContext): bool { return true; }` with `// TODO(data-exchange spec): verify DataExchangeJob succeeded before allowing reported` comment. NOT registered in Application.php — OR resolves by class name from schema requires: string.
- [ ] Register `ExcuseApprovalHandler` and `AttendanceFlagCreationHandler` in `Application.php` for `ObjectTransitionedEvent` (and CalculatedChangeEvent if applicable).
- [ ] Run: `./vendor/bin/phpcs lib/` — 0 errors; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` — 0 errors; `php -l lib/Lifecycle/ExcuseApprovalHandler.php lib/Lifecycle/AttendanceFlagCreationHandler.php lib/Lifecycle/AttendanceFlagReportGuard.php` — no parse errors.

## Phase 3: Manifest pages in `src/manifest.json`

- [ ] Add `AttendanceRecords` page (route=/attendance/records, type=index, schema=AttendanceRecord).
- [ ] Add `AttendanceRecordDetail` page (route=/attendance/records/:id, type=detail, schema=AttendanceRecord).
- [ ] Add `ExcuseRequests` page (route=/attendance/excuses, type=index, schema=ExcuseRequest).
- [ ] Add `ExcuseRequestDetail` page (route=/attendance/excuses/:id, type=detail, schema=ExcuseRequest).
- [ ] Add `AttendanceThresholds` page (route=/attendance/thresholds, type=index, schema=AttendanceThreshold).
- [ ] Add `AttendanceThresholdDetail` page (route=/attendance/thresholds/:id, type=detail, schema=AttendanceThreshold).
- [ ] Add `AttendanceFlags` page (route=/attendance/flags, type=index, schema=AttendanceFlag, readOnly=true).
- [ ] Add `AttendanceFlagDetail` page (route=/attendance/flags/:id, type=detail, schema=AttendanceFlag, readOnly=true).
- [ ] Add `MarkAttendanceView` custom page (route=/sessions/:sessionId/attendance, type=custom, component=MarkAttendanceView).
- [ ] Add `SubmitExcuseModal` custom page (route=/attendance/excuses/submit, type=custom, component=SubmitExcuseModal).
- [ ] Add "Attendance" nav menu entry (route=AttendanceRecords, order=55).
- [ ] Run: `npm run check:manifest` — 0 Ajv errors.

## Phase 4: Frontend Vue + main.js

- [ ] Create `src/views/MarkAttendanceView.vue` — fetch Session (:sessionId) and Cohort learnerIds; render roster grid (one row per learner: status dropdown + optional minutesAttended input); "Mark all present" shortcut fills all rows; Save: GET existing AttendanceRecord per learner (check), then POST (create) or PUT (update); Options API, no Pinia module, direct fetch calls to OR API.
- [ ] Create `src/views/SubmitExcuseModal.vue` — form fields: learnerId (pre-filled from currentUser), dateFrom, dateTo, reason, reasonKind (select); DigiD flow toggle: if selected show placeholder banner + record submittedAuthLevel=substantial; default: submittedAuthLevel=basic; on Submit: POST ExcuseRequest with submittedBy=currentUser; Options API, no Pinia module.
- [ ] Register both custom components in `src/main.js` via `customComponents` (per ADR-024 CnAppRoot pattern).
- [ ] Run: `npm run lint` — 0 errors; `npm run stylelint` — clean for new files; `npm run build` — succeeds.

## Phase 5: i18n

- [ ] Add new i18n keys to `l10n/en.json` for all 10 manifest pages (nav label, page titles, action labels) and both custom views (form labels, button text, placeholder messages).
- [ ] Add corresponding Dutch translations to `l10n/nl.json` for all same keys (plain-Dutch values, no machine translation for UI-facing copy).
- [ ] Verify no untranslated string literals remain in MarkAttendanceView.vue or SubmitExcuseModal.vue — all user-facing strings MUST use `t()`.

## Phase 6: Seed data in `lib/Settings/scholiq_register.json`

- [ ] Add 4 `AttendanceThreshold` seed objects per design §6.1 under `components.objects[]` with `@self` envelope: threshold-leerplicht-16uur (active), threshold-hbo-aanwezigheid (active), threshold-nis2-training (active), threshold-mbo-stages (draft). Use Dutch institution names and realistic field values.
- [ ] Add 4 `AttendanceRecord` seed objects per design §6.2: present, absent-unexcused, late, absent-excused statuses. Dutch learner IDs and school context.
- [ ] Add 3 `ExcuseRequest` seed objects per design §6.3: approved (with attachmentRef), submitted (pending), rejected. Mix of reasonKind values and submittedAuthLevel values.
- [ ] Add 3 `AttendanceFlag` seed objects per design §6.4: in-handling (no dataExchangeJobId), reported (with dataExchangeJobId), open (fresh flag). Different learners and thresholds.
- [ ] Re-validate JSON after seed data addition: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'` — no parse errors.
- [ ] Verify seed data idempotency: re-importing with force:false MUST NOT create duplicates (slugs are unique across all seed objects).

## Phase 7: Spec-validation gate

- [ ] `node tests/validate-json-strict.js` PASS.
- [ ] `node tests/validate-register.js` PASS (slug uniqueness; lifecycle `requires:` → PHP class exists for AttendanceFlagReportGuard).
- [ ] `node tests/validate-manifest.js` PASS (0 Ajv errors, all 10 attendance pages present).
- [ ] Confirm no new file matches `lib/Job/*Attendance*.php` or `lib/Job/*Threshold*.php` (no TimedJob — REQ-AT-006-A).
- [ ] Confirm no new file matches `lib/Service/*AuditTrail*.php` or introduces a parallel audit substrate (ADR-008 / ADR-022 compliance).
