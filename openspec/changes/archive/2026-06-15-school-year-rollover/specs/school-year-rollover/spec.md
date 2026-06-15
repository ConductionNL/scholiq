---
status: draft
---

# School-Year Rollover (Jaarovergang)

## Purpose

Plan, preview, and execute the annual jaarovergang: bulk cohort promotion with per-learner overrides (doubleur retention, graduation, OSO outflow), carry-over of incomplete mandatory enrolments, NC-group sync, and archival of the old year — as one audited, resumable operation driven by a `RolloverPlan` OpenRegister object with a mandatory dry-run gate.

## ADDED Requirements

### Requirement: RolloverPlan MUST be an OpenRegister object with a preview-gated lifecycle

The system MUST persist `RolloverPlan` objects (fromAcademicYear, toAcademicYear, cohort `mappings[]` with actions `promote | graduate | dissolve`, `learnerOverrides[]` with actions `promote | retain | graduate | outflow`, `dryRunReport`, per-mapping progress, `executedBy`, `tenant_id`) with lifecycle `draft → previewed → executing → completed | failed`. The `previewed → executing` transition MUST only be reachable when the stored preview matches the plan's current mappings; editing mappings MUST drop the plan back to `draft`.

#### Scenario: Editing a previewed plan invalidates the preview
<!-- @e2e exclude Lifecycle-gate behaviour verified by PHPUnit RolloverServiceTest::testPreviewMatchesMappings; the previewed->draft drop is OR lifecycle + RolloverController::preview. No single drivable DOM scenario. -->

- **GIVEN** a RolloverPlan in lifecycle `previewed`
- **WHEN** the administrator changes one cohort mapping
- **THEN** the plan returns to `draft`
- **AND** execution is not possible until a new preview runs

#### Scenario: Unmappable cohort blocks preview
<!-- @e2e exclude Default-mapping block verified by PHPUnit RolloverServiceTest::testProposeDefaultMappingBlocksUnparseableName + testPreviewBlockedOnNullAction. No scholiq DOM surface. -->

- **GIVEN** a from-year cohort whose name yields no parseable leerjaar and no manual mapping
- **WHEN** the administrator requests a preview
- **THEN** the preview is refused, naming the unmapped cohort

### Requirement: Preview MUST be side-effect free and produce a complete dry-run report

Running a preview MUST NOT create, mutate, or archive any Cohort, Enrolment, NC group, or DataExchangeJob, and MUST produce a per-cohort report: learners to promote/retain/graduate/outflow, cohorts to create, incomplete mandatory enrolments to carry over, and NC groups to sync.

#### Scenario: Dry run changes nothing
<!-- @e2e exclude Side-effect-free preview verified by PHPUnit RolloverServiceTest::testPreviewCountsAndCohortsToCreate (preview performs no saveObject). No scholiq DOM surface. -->

- **GIVEN** a draft plan mapping `2A → 3A` for 28 learners with 2 retain overrides
- **WHEN** the preview runs
- **THEN** the report shows 26 promotions, 2 retentions, 1 new cohort, and the enrolment carry-over count
- **AND** no Cohort, Enrolment, or NC group has been created or modified

### Requirement: Execution MUST promote by creating new cohorts and archiving old ones

Execution MUST create to-year Cohorts (`academicYear = toAcademicYear`), populate `learnerIds` per mappings and overrides, create or reuse the backing NC group per new cohort and sync its members, and transition from-year cohorts to `archived` with their historical `learnerIds` intact. From-year cohorts MUST NOT be mutated into to-year cohorts in place. Courses are NOT cloned by the rollover.

#### Scenario: Klas 2A becomes 3A
<!-- @e2e exclude Cohort creation + archival verified by PHPUnit RolloverServiceTest::testExecuteCreatesCohortArchivesAndRecordsProgress; execution is the OR-event-driven RolloverExecutionHandler. No scholiq DOM surface. -->

- **GIVEN** a previewed plan mapping cohort `2A` (`academicYear: 2025-2026`) to `3A` with action `promote`
- **WHEN** the plan executes
- **THEN** a new Cohort `3A` (`academicYear: 2026-2027`) exists containing the promoted learners
- **AND** its backing NC group contains exactly those members
- **AND** cohort `2A` is `archived` with its original `learnerIds` unchanged

#### Scenario: Doubleur stays in the same leerjaar
<!-- @e2e exclude retain-override placement is execution logic (RolloverService::executePromotion); covered at the unit level. No scholiq DOM surface. -->

- **GIVEN** a learner in `2A` with override action `retain`
- **WHEN** the plan executes
- **THEN** the learner is placed in the new-year cohort of the same leerjaar (`2A`, `academicYear: 2026-2027`), not in `3A`

### Requirement: Incomplete mandatory enrolments MUST carry over

Execution MUST repoint `mandatory: true` Enrolments not in a terminal lifecycle to the learner's new cohort (`cohortId`), leaving `dueDate` untouched; completed or withdrawn enrolments MUST remain attached to the archived cohort. Non-mandatory carry-over MUST be a per-mapping opt-in.

#### Scenario: Compliance coverage survives the summer
<!-- @e2e exclude Enrolment carry-over is execution logic (RolloverService::carryEnrolments); covered at the unit level. No scholiq DOM surface. -->

- **GIVEN** a promoted learner with an active `mandatory: true` Enrolment due 2026-10-01
- **WHEN** the plan executes
- **THEN** the Enrolment's `cohortId` points at the learner's new cohort and `dueDate` is unchanged
- **AND** the learner's completed enrolments remain on the archived cohort

### Requirement: Outflow learners MUST be handed to the OSO data-exchange path

For each `outflow` override, execution MUST queue a data-exchange job (`direction: export`, `target: oso`) that follows the existing data-exchange spec, including its `pending-parent-review` gate (parents resolved from `LearnerProfile.parentIds`; contact data stays in the NC addressbook). When the OSO OpenConnector connection is not configured, the rollover MUST record the learner on a pending-action list instead of failing the run. The rollover MUST NOT implement any wire protocol itself.

#### Scenario: Leaver gets an OSO dossier job
<!-- @e2e exclude OSO outflow queueing (RolloverService::queueOutflow) creates a DataExchangeJob handled by the data-exchange spec; no scholiq DOM surface. -->

- **GIVEN** a learner with override action `outflow`
- **WHEN** the plan executes on an instance with the OSO connection configured
- **THEN** a `DataExchangeJob {direction: export, target: oso}` exists for that learner awaiting parent review

#### Scenario: Missing OSO connection degrades gracefully
<!-- @e2e exclude Degraded-mode behaviour depends on OpenConnector OSO config absent in the e2e env; the queueing path is covered at the unit level. -->

- **GIVEN** the OSO OpenConnector connection is not configured
- **WHEN** a plan with outflow overrides executes
- **THEN** the plan completes and the outflow learners appear on the plan's pending-action list

### Requirement: Execution MUST be audited, idempotent per mapping, and resumable

Execution MUST run as a background job that records per-mapping completion on the plan; every cohort transition and learner movement MUST emit an OR audit-trail entry (ADR-008). Re-running a `failed` plan MUST skip completed mappings and MUST NOT create duplicate cohorts or duplicate carried-over enrolments.

#### Scenario: Resume after mid-run failure
<!-- @e2e exclude Idempotent resume verified by PHPUnit RolloverServiceTest::testExecuteSkipsCompletedMappings. No scholiq DOM surface. -->

- **GIVEN** a plan that failed after completing 12 of 30 mappings
- **WHEN** the administrator re-runs the plan
- **THEN** the 12 completed mappings are skipped
- **AND** after completion no duplicate to-year cohort and no duplicate carried-over enrolment exists

### Requirement: The wizard MUST be declarative with a single custom-view exception, and completion MUST notify via the verified dialect

The rollover UI MUST be a `src/manifest.json` page, with one custom Vue view permitted for the mapping editor (the school-structure custom-view exception pattern). Plan create/preview/execute MUST be restricted to `admin` (plus a configured coordinator group) via OR-delegated RBAC. A single `transition`-trigger notification rule in the verified dialect (per `scholiq-notifications`) MUST notify `executedBy` on `completed`/`failed`; no per-learner placement fan-out is sent.

#### Scenario: Executor is notified on completion
<!-- @e2e tests/e2e/spec-coverage/school-year-rollover.spec.ts -->
<!-- Notification delivery is OpenRegister's dispatcher; the e2e asserts the declarative wizard page (the single custom-view exception) renders so an admin can reach the mapping editor. -->

- **GIVEN** an executing plan started by user `admin-jan`
- **WHEN** the plan reaches `completed`
- **THEN** `admin-jan` receives a Nextcloud notification with an nl/en subject
- **AND** no notification is sent to the moved learners
