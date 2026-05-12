# Tasks — Enrolment

> Scope: a single JSON patch on `lib/Settings/scholiq_register.json` adding the `Enrolment` schema with full `x-openregister-lifecycle` / `-calculations` / `-notifications` / `-relations`. One PHP file (`XapiCompletionHandler`) lands as an ADR-031 legitimate exception. No `EnrolmentController`, no `EnrolmentService`, no `BulkEnrolmentService`, no `EnrolmentDueReminderJob`. **In-fleet references**: `decidesk/lib/Settings/decidesk_register.json` ActionItem schema for the `calculations` shape we use. `lifecycle` + `notifications` follow the contracts in [openregister#1470](https://github.com/ConductionNL/openregister/issues/1470) — no in-fleet working example yet.

## Phase 1: Schema patch on `lib/Settings/scholiq_register.json`

- [ ] Add `Enrolment` schema per design §1 — lifecycle (`pending → active → completed | withdrawn | failed`), relations (`learner` + `course`), calculations (`isOverdue`, `daysRemaining`, `ragStatus`), notifications (welcomeOnActivate, completionOnComplete, reminderT30/T7/T1 with `idempotencyKey`, managerAlertOnOverdue with fallback recipient). Validate via OR's schema-validation endpoint. Reference example: decidesk Meeting + Decision schemas.
- [ ] Write a JSON-validation test that asserts the schema parses against OR's schema-extension contract and that the calculations/notifications resolve correctly against a sample object.

## Phase 2: PHP — single ADR-031 legitimate exception

- [ ] Create `lib/Lifecycle/XapiCompletionHandler.php`: receives the `xapi.statement.received` audit-event payload via OR's event dispatcher; if `verb.id ∈ {http://adlnet.gov/expapi/verbs/completed, http://adlnet.gov/expapi/verbs/passed}` AND `lesson.mandatoryTraining=true` AND the lesson is the final published lesson of its course, dispatches the `complete` transition on the corresponding active Enrolment via `ObjectService::transition('Enrolment', $enrolmentId, 'complete')`. Single method. Legitimate per ADR-031 §"Lifecycle guards as called from `x-openregister-lifecycle.requires`" (treated as a lifecycle handler). Integration test: post a cmi5 completed statement → assert Enrolment transitions to `completed` → assert OR audit-trail contains `enrolment.completed`.
- [ ] Register `XapiCompletionHandler` in `lib/AppInfo/Application.php` as an OR audit-event listener (via OR's `IEventDispatcher::addListener('openregister.audit.xapi.statement.received', ...)` extension point per ADR-022).

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with `EnrolmentDetail` page (type=detail, register=scholiq schema=Enrolment) and `BulkEnrol` page (type=custom, component=BulkEnrolModal). Re-run `npm run check:manifest`.
- [ ] Create `src/views/BulkEnrolModal.vue` per design §3.2 — 3 steps (audience picker, section+config, confirm+submit). Step 1 calls NC OCS `/ocs/v2.php/cloud/groups` for groups OR parses uploaded CSV browser-side. Step 3 POSTs directly to OR's REST batch endpoint `POST /api/openregister/scholiq/Enrolment/batch`; **no Scholiq backend involvement**. Polls `GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid>` for progress. Playwright test: select a group + Course + dueDate → submit → assert N Enrolment objects exist in OR with `source=bulk` and matching `bulkJobId`.
- [ ] Register `BulkEnrolModal` via `customComponents` on `CnAppRoot` in `src/main.js`.
- [ ] **Do NOT** create `src/router/index.js` entries, `src/stores/enrolmentStore.js`, or `src/views/EnrolmentListView.vue` / `EnrolmentDetailView.vue` — `CnAppRoot` built-in renderers cover them.

## Phase 4: Audit-event vocabulary — none

- [ ] **Do NOT** add `enrolment.overdue` / `enrolment.reminder.sent` to a Scholiq-side `AuditEventTypes::KNOWN`. OR's lifecycle + notification engines emit these event types automatically based on the schema declarations. ADR-022 + ADR-008-rewrite prohibit a parallel app-side vocabulary.

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Integration test (PHPUnit + OR): seed an Enrolment with `dueDate = today + 30`, trigger OR's notification-evaluation tick, assert the `reminderT30` notification dispatches exactly once (idempotencyKey works).
- [ ] Integration test (PHPUnit + OR): seed an Enrolment with `dueDate = today - 1`, trigger OR's calculation-refresh tick, assert `isOverdue` recomputes to true AND the `managerAlertOnOverdue` notification dispatches to the manager (or to the HR group fallback).
- [ ] Playwright integration test: full compliance-officer workflow — open `Enrolments`, click "Bulk enrol", pick a group + Course + dueDate, submit, assert N Enrolment objects with correct fields appear in the index after polling completes.
