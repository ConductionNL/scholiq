# Tasks

- [ ] Add `RolloverPlan` schema to `lib/Settings/scholiq_register.json` (fromAcademicYear, toAcademicYear, mappings[], learnerOverrides[], dryRunReport, executedBy/executedAt, perMappingProgress, tenant_id) with lifecycle `draft → previewed → executing → completed | failed`
- [ ] Add one `transition`-trigger completion/failure notification rule on RolloverPlan in the verified dialect (recipient kind:field executedBy, subject{nl,en}) — verified keys only, per `scholiq-notifications`
- [ ] Implement RolloverService: default-mapping proposal (leerjaar increment from cohort name + Programme match; unparseable → blocking null action)
- [ ] Implement side-effect-free preview producing the per-cohort dryRunReport (promote/retain/graduate/outflow counts, cohorts to create, enrolments to carry over, NC groups to sync); editing mappings after preview drops the plan back to `draft`
- [ ] Implement execution as a chunked background job (registered via the boot-time-correct mechanism, NOT `IRegistrationContext::registerJob`): create to-year Cohorts (idempotent on toAcademicYear+toCohortName+tenant), move learnerIds per mapping + overrides, archive from-year cohorts, record per-mapping progress for resume
- [ ] Implement NC group sync via `IGroupManager` (deterministic group naming, members = new learnerIds; archived-cohort groups left intact)
- [ ] Implement enrolment carry-over: incomplete `mandatory: true` Enrolments repointed to the learner's new cohort (dueDate untouched); completed/withdrawn stay on the archived cohort; non-mandatory carry-over behind a per-mapping opt-in
- [ ] Implement outflow handling: queue a data-exchange `DataExchangeJob {direction: export, target: oso}` per outflow learner (parent review via the existing `pending-parent-review` gate, parents from `LearnerProfile.parentIds` / NC addressbook); degraded pending-action list when the OSO connection is unconfigured
- [ ] Emit OR audit-trail entries for every cohort transition and learner movement (ADR-008)
- [ ] Add the Rollover wizard manifest page + mapping-editor custom Vue view + navigation entry under School Structure; link to the existing Course "Clone for next year" for the content side
- [ ] RBAC: restrict plan create/preview/execute to `admin` (and configured coordinator group) via OR-delegated RBAC
- [ ] nl + en i18n (English keys); PHPUnit on mapping/preview/carry-over/idempotency semantics; Playwright e2e on the wizard (draft → preview → execute happy path)
- [ ] Bump `appinfo/info.xml` version

## Acceptance criteria

- A plan cannot reach `executing` without a preview matching its current mappings; editing mappings invalidates the preview.
- Executing a plan with promote/retain/graduate/outflow overrides yields: new to-year cohorts with correct members, archived from-year cohorts with historical `learnerIds` intact, synced NC groups, carried-over incomplete mandatory enrolments, queued OSO jobs (or pending actions) for outflow learners.
- Re-running a `failed` plan completes without duplicate cohorts or duplicate carried-over enrolments.
- Every learner movement is visible in the OR audit trail.
- No legacy notification keys; the completion rule uses `trigger.type`/`channels[]`/`recipients[]`/`subject{nl,en}` only.
