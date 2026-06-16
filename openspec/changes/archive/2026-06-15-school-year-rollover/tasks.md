# Tasks

> Status: implemented 2026-06-15 (info.xml 0.2.1 → 0.2.2). The plan schema,
> default-mapping proposal, side-effect-free preview, idempotent/resumable
> execution, NC-group sync, enrolment carry-over, and OSO outflow queueing are
> real and PHPUnit-covered (8 new tests, 65/65 green). Notes below each item.

- [x] Add `RolloverPlan` schema to `lib/Settings/scholiq_register.json` (fromAcademicYear, toAcademicYear, mappings[], learnerOverrides[], dryRunReport, executedBy/executedAt, perMappingProgress, failureReason, tenant_id) with lifecycle `draft → previewed → executing → completed | failed` (+ retry)
- [x] Add `transition`-trigger completion/failure notification rules on RolloverPlan in the verified dialect (recipient kind:field executedBy, subject{nl,en}) — verified keys only, per `scholiq-notifications`
- [x] Implement RolloverService default-mapping proposal (leerjaar increment from cohort name; unparseable → blocking null action) — `RolloverService::proposeDefaultMapping`
- [x] Implement side-effect-free preview producing the per-cohort dryRunReport (promote/retain/graduate/outflow counts, cohorts to create, enrolments to carry over, NC groups to sync) — `RolloverService::preview`; editing mappings after preview drops the plan back to `draft` via `previewMatchesMappings` (the gate `RolloverExecutionHandler` enforces and `RolloverController::preview` only advances on a non-blocked preview)
- [x] Implement execution registered via the boot-time-correct mechanism (event-driven `RolloverExecutionHandler` on the `previewed → executing` transition — NOT `IRegistrationContext::registerJob`, per the fleet jobs-never-ran bug): create to-year Cohorts (idempotent on toAcademicYear+toCohortName+tenant), move learnerIds per mapping + overrides, archive from-year cohorts, record per-mapping progress for resume — `RolloverService::execute`
- [x] Implement NC group sync via `IGroupManager` (deterministic group naming via `RolloverService::groupName`, members = new learnerIds; archived-cohort groups left intact)
- [x] Implement enrolment carry-over: incomplete `mandatory: true` Enrolments repointed to the learner's new cohort (dueDate untouched); completed/withdrawn stay on the archived cohort; non-mandatory carry-over behind a per-mapping opt-in (`carryNonMandatory`) — `RolloverService::carryEnrolments`
- [x] Implement outflow handling: queue a `DataExchangeJob {direction: export, target: oso}` per outflow learner (the existing data-exchange `pending-parent-review` gate and parent resolution apply downstream) — `RolloverService::queueOutflow`. [~] The degraded pending-action list (when the OSO connection is unconfigured) is a follow-up: outflow learners are queued as DataExchangeJobs which the data-exchange spec already degrades; an explicit per-plan pending-action surface is not yet rendered.
- [x] Emit OR audit-trail entries for every cohort transition and learner movement (ADR-008) — OR's lifecycle engine + object writes record these automatically (saveObject + lifecycle transitions); the service performs no manual audit writes
- [x] Add the Rollover wizard manifest page + mapping-editor custom Vue view (`RolloverWizard.vue`, registered) + admin-gated navigation entry under School Structure. [~] An explicit link to the Course "Clone for next year" content-side operation is not embedded in the wizard yet.
- [x] RBAC: restrict plan create/preview/execute to `admin` via the ADR-023 action matrix (`rollover.plan`) + `x-property-rbac` (admin-only read); broaden to a coordinator group via Admin Settings
- [x] nl + en i18n (English keys); PHPUnit on mapping/preview/carry-over/idempotency semantics. [~] Playwright e2e is scoped to the wizard-page render (`tests/e2e/spec-coverage/school-year-rollover.spec.ts`); the full draft → preview → execute browser flow is covered at the unit level (preview/execute semantics) because execution is OR-event-driven across many objects with no single drivable DOM scenario.
- [x] Bump `appinfo/info.xml` version (0.2.1 → 0.2.2)

## Acceptance criteria

- A plan cannot reach `executing` without a preview matching its current mappings; editing mappings invalidates the preview. — `RolloverExecutionHandler` guards on `previewMatchesMappings`; `testPreviewMatchesMappings`.
- Executing yields new to-year cohorts with correct members, archived from-year cohorts with historical `learnerIds` intact, synced NC groups, carried-over incomplete mandatory enrolments, queued OSO jobs for outflow learners. — `testExecuteCreatesCohortArchivesAndRecordsProgress` + the `execute`/`carryEnrolments`/`queueOutflow` paths.
- Re-running a `failed` plan completes without duplicate cohorts or duplicate carried-over enrolments. — `testExecuteSkipsCompletedMappings` (per-mapping `done` skip) + idempotent `createOrFindToCohort`/`carryEnrolments`.
- Every learner movement is visible in the OR audit trail. — OR records each `saveObject`/lifecycle transition.
- No legacy notification keys; the completion rule uses verified dialect only. — register-validation suite passes.
