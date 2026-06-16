# Coverage Report — scholiq

Generated: 2026-05-24 00:00 UTC
Branch: chore/bump-docusaurus-preset-v3
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (already tagged) |
| plumbing | 28 | — (never tagged) |
| 1 — REQ matched | 117 | `/opsx-annotate scholiq` |
| 2a — existing capability, no REQ | 15 (1 cluster) | `/opsx-reverse-spec scholiq --extend mcp-tools` *(or open new `mcp-tools` capability)* |
| 2b — no capability owner | 0 (0 clusters) | — |
| 3a — REQ broken (code removed) | 0 | — |
| 3b — REQ never implemented | 13 | Mark deferred or remove (most are schema-declarative, not code-bound) |
| 4 — ADR conformance | 4 rules, ~50 file findings | Follow-up issue (esp. broken-route-reachability-adr-029) |

**Headline:** zero `@spec` annotations across the entire codebase. This is a green-field annotation target.

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-scholiq`)

Grouped by capability → REQ. Confidence in `()` after each entry. Pass B inheritances marked.

### capability: assessment (24 methods)

**REQ-1** (persist Assessment/Item/ItemBank/AssessmentResult/ProctoringSession with lifecycle + calculations)
- `lib/Lifecycle/AssessmentPublishGuard.php::check()` (0.85)
- `lib/Lifecycle/AssessmentScoringHandler.php::check()` (0.92) + `scoreResponse()` [Pass B]
- `lib/Service/AssessmentScoringService.php::autoScore()` (0.95)

**REQ-2** (QTI 3.0 import; QTI 2.x + Common Cartridge import required)
- `lib/Controller/QtiImportController.php::import()` (0.97)
- `lib/Service/QtiImportService.php::import()` (0.97) + 8 Pass-B helpers
- `src/views/ImportQtiModal.vue` (0.90)

**REQ-3** (ProctoringProviderInterface; no bundled provider; flag MUST NOT auto-alter)
- `lib/Proctoring/ProvidesProctoring.php` [interface] (0.97)

**REQ-4** (AiFeature DPO ack before AI-assisted flag review)
- `lib/Lifecycle/AiFeatureDpoAckGuard.php::check()` (0.90)

**REQ-5** (graded AssessmentResult emits GradeEntry)
- `lib/Lifecycle/AssessmentGradeGuard.php::check()` (0.92)

**REQ-6** (frontend: TakeAssessmentView / ItemAuthorView / ProctoringReviewQueue)
- `src/views/TakeAssessmentView.vue` (0.95)
- `src/views/ItemAuthorView.vue` (0.95)
- `src/views/ProctoringReviewQueue.vue` (0.95)

### capability: assignments (3 methods)

**REQ-1** (persist Assignment/Submission/Rubric; late-window guard)
- `lib/Lifecycle/AssignmentPublishGuard.php::check()` (0.85)
- `lib/Lifecycle/SubmissionWindowGuard.php::check()` (0.92)

**REQ-4** (ProvidesPlagiarismCheck interface; no bundled provider)
- `lib/Plagiarism/ProvidesPlagiarismCheck.php` [interface] (0.97)

**REQ-5** (SubmitWorkModal + MarkSubmissionView)
- `src/views/SubmitWorkModal.vue` (0.95)
- `src/views/MarkSubmissionView.vue` (0.95)

### capability: attendance (7 methods)

**REQ-1** (persist AttendanceRecord/ExcuseRequest/Threshold/Flag; append-only)
- `lib/Lifecycle/AttendanceFlagCreationHandler.php::handle()` (0.92) + `createFlag()` + `resolveMentorId()` [Pass B]
- `lib/Lifecycle/AttendanceFlagReportGuard.php::check()` (0.85)
- `lib/Lifecycle/ExcuseApprovalHandler.php::handle()` (0.90) + `flipAttendanceRecords()` [Pass B]

**REQ-4** (report to external authority MUST be a DataExchangeJob)
- `lib/Lifecycle/AttendanceFlagCreationHandler.php::queueDataExchangeJob()` (0.90)

**REQ-5** (MarkAttendanceView + SubmitExcuseModal)
- `src/views/MarkAttendanceView.vue` (0.95)
- `src/views/SubmitExcuseModal.vue` (0.95)

### capability: certification (7 entries)

**REQ-1** (issue EDCI/Europass + Open Badges 3.0 with verifiable URLs)
- `lib/Controller/CredentialVerifyController.php::verify()` (0.92)
- `lib/Controller/KeyAdminController.php::generateKey()` (0.80) + `keyStatus()` (0.75 NEEDS-REVIEW)
- `lib/Listener/CredentialIssuanceHandler.php::handle()` (0.95)
- `lib/Service/CredentialSigningService.php::buildOb3Payload()` (0.95), `signPayload()` (0.95), `resolveIssuerDid()` [Pass B], **`check()`** (0.70 NEEDS-REVIEW — misplaced lifecycle-guard signature on a Service class)
- `lib/Service/KeyManagementService.php::generateTenantKeypair()` (0.90), `getTenantKeyStatus()` (0.85)
- `src/views/CredentialVerify.vue` (0.90)

### capability: compliance-audit (10 entries)

**REQ-1** (capture attestations w/ timestamp, IP, employee, regulation, score)
- `lib/Lifecycle/AttestationSigningGuard.php::xapiCompletionExists()` [Pass B]
- `src/views/ScholiqCompliance.vue` (0.85)

**REQ-2** (append-only signed evidence log)
- `lib/Lifecycle/AttestationSigningGuard.php::check()` (0.92) + `buildCanonicalPayload()` [Pass B]
- `lib/Controller/AuditPackExportController.php::buildVerificationTxt()` [Pass B]

**REQ-3** (audit-ready ZIP export per regulation + date range)
- `lib/Controller/AuditPackExportController.php::export()` (0.95) + 5 Pass-B builders
- `src/views/AuditPackExportModal.vue` (0.95)

### capability: course-management (2 methods)

**REQ-3** (cmi5 + xAPI native; SCORM shim SHOULD)
- `lib/Lifecycle/XapiCompletionHandler.php::handle()` (0.95)
- `lib/Service/Cmi5LaunchTokenService.php::mintLaunchToken()` (0.95)

### capability: dashboard (5 entries)

**REQ-1** (per-resolved-role default dashboard)
- `lib/Lifecycle/RoleSelector.php::calculate()` (0.92)
- `src/views/ScholiqLearnerHome.vue` (0.88)

**REQ-2** (use `@conduction/nextcloud-vue` dashboard components)
- `src/views/ScholiqDashboard.vue` (0.90)
- `src/views/widgets/KpiCoursesWidget.vue` (0.85)
- `src/views/widgets/KpiCohortsWidget.vue` (0.85)

### capability: data-exchange (11 methods)

**REQ-1** (persist DataExchangeJob + DataMappingProfile with lifecycle)
- `lib/Lifecycle/DataExchangeRunGuard.php::check()` (0.90)
- `lib/Listener/DataExchangeRunHandler.php::handle()` (0.92) + 6 Pass-B helpers

**REQ-2** (delegate wire protocols to OpenConnector)
- `lib/Listener/DataExchangeRunHandler.php::callOpenConnector()` (0.92)

**REQ-4** (OSO parent-review gate is a lifecycle state)
- `lib/Lifecycle/OsoDossierReviewGuard.php::check()` (0.92)

**REQ-5** (RequestExportModal + OsoDossierReviewView)
- `src/views/RequestExportModal.vue` (0.95)
- `src/views/OsoDossierReviewView.vue` (0.95)

### capability: enrolment (1 method)

**REQ-1** (bulk enrolment via cohort/role/department/CSV)
- `src/views/BulkEnrolModal.vue` (0.95)

### capability: grading (17 entries)

**REQ-1** (persist GradeScale/GradeEntry/FinalGrade; FinalGrade via cross-schema aggregation)
- `lib/Listener/GradeRollupHandler.php::recomputeFinalGrade()` (0.92), `handleAssessmentResultGraded()` (0.88)

**REQ-2** (per-parent/per-18+-learner notification preference dispatch)
- `lib/Listener/GradeRollupHandler.php::fanOutParentNotifications()` (0.90)

**REQ-3** (declared calculatedChange trigger; GradeFormulaEvaluator as ADR-031 exception)
- `lib/Grading/GradeFormulaEvaluator.php::evaluate()` (0.96) + 11 Pass-B helpers
- `lib/Listener/GradeRollupHandler.php::handle()` (0.92), `handleGradeEntryPublished()` [Pass B]

**REQ-4** (GradebookView + GradeImpactDetail)
- `src/views/GradebookView.vue` (0.95)
- `src/views/GradeImpactDetail.vue` (0.95)

### capability: learning-plan (10 entries)

**REQ-1** (persist LearningPlan/Evaluation/Signature with lifecycle)
- `lib/Listener/LearningPlanEvaluationHandler.php::handle()` (0.92) + `handleEvaluationRecorded()` [Pass B]
- `src/views/LearningPlanEditor.vue` (0.88)

**REQ-3** (append-on-version; material change creates new version requiring re-sign)
- `lib/Lifecycle/LearningPlanSignatureGuard.php::check()` (0.95) + 3 Pass-B helpers (fetchRequiredRoles, fetchSignatures, indexByRole) + `supersedesPriorVersion()` [Pass B]

**REQ-4** (assurance-level capture declarative)
- `lib/Lifecycle/LearningPlanSignatureGuard.php::minimumAssurance()` (0.90) + `assuranceSatisfies()` + `assuranceRank()` [Pass B]

**REQ-5** (SignPlanModal)
- `src/views/SignPlanModal.vue` (0.95)

### capability: nextcloud-app (8 entries — multiple NEEDS-REVIEW)

**REQ-1** (declare openregister + openconnector deps; refuse-to-bootstrap)
- `lib/Controller/HealthController.php::index()` (0.75 NEEDS-REVIEW)
- `lib/Service/SettingsService.php::isOpenRegisterAvailable()` (0.85), `getSettings()` (0.70 NEEDS-REVIEW), `updateSettings()` (0.70 NEEDS-REVIEW), `loadConfiguration()` (0.70 NEEDS-REVIEW)
- `lib/Repair/InitializeSettings.php::run()` (0.80)
- `src/views/ScholiqSettings.vue` (0.70 NEEDS-REVIEW)
- `src/views/ScholiqAdminHealth.vue` (0.75 NEEDS-REVIEW)
- `src/views/FeaturesRoadmap.vue` (0.65 NEEDS-REVIEW) — roadmap UI; loose tie. Consider dropping from Bucket 1.

> The `nextcloud-app` REQs are conventions (refuse-to-bootstrap, hash router, NcEmptyContent, NL-Design CSS); most of them are not code-bound and the Bucket-1 mapping here is best-effort. Pair these with a manual review before annotating.

### capability: school-structure (5 entries)

**REQ-1** (persist Programme/CurriculumPlan/Cohort/Session/Material with lifecycle)
- `lib/Lifecycle/CohortMembershipGuard.php::check()` (0.80)
- `lib/Lifecycle/CoursePublishGuard.php::check()` (0.85)
- `lib/Lifecycle/ProgrammePublishGuard.php::check()` (0.85)

**REQ-5** (CohortTimetable custom view exception)
- `src/views/CohortTimetable.vue` (0.95)

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: mcp-tools (15 methods, `lib/Mcp/ScholiqToolProvider.php`)

> ⚠️ **Naming caveat**: there is no `mcp` capability in `openspec/specs/`. This cluster's nearest existing-capability anchor is `assessment` only because the tool surfaces course data; the right action is probably to file a new `ai-companion-tools` capability rather than `--extend` an existing one. Mark as **NEEDS-DECISION** before reverse-speccing.

- `getAppId()` — MCP tool provider registration
- `getTools()` — declares `list_courses`, `get_course_details`
- `invokeTool()` — dispatcher
- `handleListCourses()`, `validateListCoursesArgs()`, `handleGetCourseDetails()`, `loadCourseModules()`, `courseSource()`, `requireCourseReadAccess()`, `findCourse()`, `courseSummary()`, `moduleSummary()`, `buildDeepLink()`, `toArray()`, `extractUuid()`

Observed behaviour: implements `IMcpToolProvider` and ships course-discovery tools to the AI companion. ScholarLY no spec covers MCP/AI companion tools — this is genuine missing-REQ work.

## Bucket 2b — No capability owner

**None.** Every file path maps cleanly to one of the 13 existing capabilities.

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (implementation evidence in git history)

**None.** Removed-lines cache contains no Bucket-3a candidates (32468 removed lines, none matched unimplemented-REQ keyword pairs with ≥ 2 distinct hits).

### 3b — never implemented (no git-history evidence)

| REQ | Note |
|---|---|
| `certification#REQ-2` | No PHP scheduled-detection for expiry. Spec says daily schedule; intended as declarative calculation (ADR-031) but no schema-level calc landed either. **Deferred.** |
| `certification#REQ-3` | No auto-enrol-on-expiry handler. Likely intended as `calculatedChange` trigger; no PHP entrypoint. **Deferred.** |
| `course-management#REQ-2` | No OOAPI 5.0 endpoint controller. Likely planned as OpenConnector delegation. **Verify with maintainer.** |
| `enrolment#REQ-2` | Prerequisite-validation guard: no PHP guard in lib/Lifecycle. Likely declarative (lifecycle metadata) but not visible. **Verify.** |
| `enrolment#REQ-3` | Studielink/LMS provisioning within 60s: no PHP handler, no removed-line evidence. **Deferred.** |
| `school-structure#REQ-2` | Recursive Course schema — pure schema concern; no PHP expected. **Out of code scope.** |
| `school-structure#REQ-3` | CurriculumPlan component shape — pure schema concern. **Out of code scope.** |
| `school-structure#REQ-4` | Materials reference OR file attachments — schema. **Out of code scope.** |
| `data-exchange#REQ-3` | Federated auth is explicitly **OUT** of this spec; correctly absent from code. Negative requirement — **no action.** |
| `attendance#REQ-3` | Sick-reporting auth-strength declarative — schema. **Out of code scope.** |
| `nextcloud-app#REQ-2` | Vue Router hash mode — no `src/router/` exists (manifest-driven navigation per ADR-024). **Convention, not code unit.** |
| `nextcloud-app#REQ-3` | NcEmptyContent everywhere — convention; verify via grep, not bucketable. |
| `nextcloud-app#REQ-4` | NL Design double-fallback CSS — convention; not a code unit. |

> Most 3b entries are **schema-declarative or convention REQs**, correctly NOT in `lib/` per ADR-031. Only `certification#REQ-2/3` and `enrolment#REQ-3` represent real implementation gaps (background-style work expressed as declarative triggers, but neither the trigger metadata nor a PHP fallback is present).

## Bucket 4 — ADR conformance findings

### ⚠️ broken-route-reachability (ADR-029) — 1 file, 5 routes

`appinfo/routes.php` references controllers that do **not** exist in `lib/Controller/`:

| Route name | URL | Missing controller |
|---|---|---|
| `lrs#postStatements` | `POST /api/lrs/statements` | `LrsController` |
| `lrs#getStatements` | `GET /api/lrs/statements` | `LrsController` |
| `scorm#launch` | `GET /api/scorm/{lessonId}/launch` | `ScormController` |
| `scorm#api` | `POST /api/scorm/{lessonId}/api` | `ScormController` |
| `cmi5_launch#token` | `GET /api/lessons/{lessonId}/launch` | `Cmi5LaunchController` |

`lib/Service/Cmi5LaunchTokenService::mintLaunchToken()` exists but has no controller wrapping it. These five routes will 500 (or get auto-mapped to NotFound) at request time. **Fix-PR worthy.**

### missing-spec-in-file-docblock — every PHP file in `lib/`

Zero `@spec openspec/changes/...` tags across the tree. This is the expected baseline for a green-field annotate run — `/opsx-annotate scholiq` will fix all of them in one PR.

### missing-license-tag — 2 files

- `lib/Lifecycle/AttendanceFlagCreationHandler.php` — header missing `@license`
- `lib/Listener/DataExchangeRunHandler.php` — header missing `@license`

### missing-copyright-tag — 1 file

- `lib/Listener/DataExchangeRunHandler.php` — header missing `@copyright`/`SPDX-FileCopyrightText`

### Clean (no findings)

- Forbidden patterns (`var_dump`, `dd`, `die`, `print_r`, `error_log`) — **0 hits in lib/**.
- Direct SQL (`$this->db->query`, `prepare`) — **0 hits in lib/**.

## Notes for the human reviewer

1. **REQ-ID convention**: scholiq specs use plain-bullet `## Requirements` sections, NOT `###`/`####` REQ-NNN headings. The scanner synthesised ordinal IDs (`assessment#REQ-1` = first bullet of `assessment/spec.md`). Before running `/opsx-annotate`, the operator should decide whether to (a) migrate specs to REQ-ID headings or (b) carry these synthesised IDs through annotation. Option (b) is the cheaper path but creates a divergence from other Conduction apps.

2. **Zero existing annotations**: this is a fully green-field annotation target. Expect `/opsx-annotate scholiq` to produce one ghost change touching ~40 PHP files + ~25 Vue components. Per the SKILL.md warning, Bucket 1 has 117 entries which is at the upper end for one PR — consider running `/opsx-annotate scholiq --capability <cap>` per spec when that flag lands.

3. **MCP tool provider is the only Bucket-2 cluster**, with 15 methods. Reverse-speccing this is a real "new spec needed" decision, not an `--extend`; suggest filing an `ai-companion-tools` or `mcp-tools` capability rather than extending `assessment`.

4. **Bucket 3b is mostly false-positive by design**: 8 of 13 entries are schema/convention REQs that intentionally have no PHP code. Only `certification#REQ-2/3` (expiry detection + renewal auto-enrol) and `enrolment#REQ-3` (Studielink provisioning) represent actual deferred work. File issues for those three before the next sprint.

5. **`CredentialSigningService::check()` is suspicious**: a `check(array &$transitionContext): bool` signature is the lib/Lifecycle/ guard convention, not a Service convention. It is the only Service method that takes a transition context by reference. Verify whether this is misplaced code that should live in `lib/Lifecycle/` (mark **NEEDS-REVIEW**).

6. **Broken-route finding is critical**: 5 routes in `appinfo/routes.php` (LRS, SCORM, cmi5-launch endpoint wrapping) point at controllers that don't exist. This is exactly the class of issue ADR-029 (route reachability gate) was created to catch. File a fix-PR before any new feature work touches xAPI / SCORM.

7. **PHP-file count**: 44, well below the 500-file large-app threshold. No chunking needed.
