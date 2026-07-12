# Tasks: lti-tool-placement

Size M. This is the scholiq-side consuming leaf of openconnector's in-flight `lti-13-platform`
adapter — no LTI protocol code (OIDC, JWT, JWKS) is built here; tasks cover the placement schema,
launch delegation, and AGS→GradeEntry poll bridge only.

## 1. Data model

- [ ] 1.1 Add `LtiToolPlacement` to `lib/Settings/scholiq_register.json`: `lessonId` (nullable,
      `$ref: Lesson`), `courseId` (nullable, `$ref: Course`), `openconnectorDeploymentId`
      (`type: string, format: uuid`, no `$ref` — cross-register reference, mirrors
      `DataExchangeJob.connectorRunId`'s bare-string pattern), `launchMode`
      (`enum: [resource-link, deep-linking]`), `curriculumPlanId` (nullable, `$ref:
      CurriculumPlan`), `gradeEntryComponentId` (nullable, string — mirrors
      `AssessmentResult.gradeEntryComponentId`'s naming), `gradeScaleId` (nullable, `$ref:
      GradeScale`), `lifecycle` (`x-openregister-lifecycle`, `draft → active → retired`),
      `tenant_id`.
- [ ] 1.2 Update `Lesson.contentRef`'s description in `lib/Settings/scholiq_register.json`
      (doc-only — no type change) to state that for `contentType: lti`, `contentRef` MUST be an
      `LtiToolPlacement` UUID, replacing the current vague "or LTI link" wording.
- [ ] 1.3 Extend `GradeEntry.sourceKind` enum with `lti-ags`; add `ltiToolPlacementId` (nullable,
      `$ref: LtiToolPlacement`) and `ltiAgsResultId` (nullable, string) fields, following the
      exact shape of the existing `submissionId`/`assessmentResultId`/`sessionId` per-`sourceKind`
      fields.
- [ ] 1.4 Bump the register JSON's schema version metadata per the app's existing convention
      (patch bump — additive fields, no breaking change).

## 2. Launch delegation (backend)

- [ ] 2.1 Add `lib/Controller/LtiToolPlacementController.php`: `launch(string $placementId)`
      resolves the `LtiToolPlacement` via `ObjectService::findAll`, calls openconnector's
      Platform-role launch-initiation endpoint (REQ-LTI-006 in the other repo) passing
      `openconnectorDeploymentId`, using the same `IClientService` +
      `IURLGenerator::getAbsoluteURL()` + `IAppConfig` bearer-token shape
      `DataExchangeRunHandler::callOpenConnector()` already established (reuse the
      `scholiq.openconnector_api_token` config key — do not add a second token). Return the
      opaque launch response (form HTML or URL) to the caller unmodified; scholiq MUST NOT parse
      any LTI claim from it.
- [ ] 2.2 Register the route in `appinfo/routes.php` with the correct auth attribute
      (`#[NoAdminRequired]` — any authenticated learner may launch a placement they can see;
      per-object visibility is whatever already gates Lesson access).
- [ ] 2.3 Document the assumed openconnector launch-initiation endpoint shape (path, request/
      response body) in a class-level docblock on `LtiToolPlacementController`, mirroring
      `DataExchangeRunHandler::OPENCONNECTOR_RUN_PATH`'s documented-assumption comment style,
      since the openconnector side of this contract is still in-flight in the other repo.
- [ ] 2.4 Integration test (mocked `IClientService` response): a valid placement's launch call
      forwards the correct `openconnectorDeploymentId` and returns the mocked response
      unmodified.
- [ ] 2.5 Error-path test: openconnector unreachable / non-2xx → `launch()` returns a clear error
      response (mirrors `callOpenConnector()`'s `null`-on-failure handling), not a silent empty
      body.

## 3. LessonPlayer frontend delegation

- [ ] 3.1 In `src/views/LessonPlayer.vue`, add a branch on `lesson.contentType === 'lti'`:
      resolve `lesson.contentRef` as an `LtiToolPlacement` UUID, call the new
      `launch()` endpoint, and render the response — new tab for `launchMode: 'resource-link'`,
      an in-page frame for `launchMode: 'deep-linking'` (no content-picker UI; render only the
      launch/response exchange per this change's Non-goals).
- [ ] 3.2 Loading/error states for the launch call follow the existing
      `LessonPlayer.vue` `loading`/`error` pattern already used for the Course/Lesson fetch.
- [ ] 3.3 A small admin-facing form (or a manifest-declared page, per the app's declarative
      frontend convention) to create an `LtiToolPlacement` naming a Lesson/Course and an
      `openconnectorDeploymentId` — no new PHP CRUD controller beyond `launch()` (creation goes
      through the existing generic OR object-save path).

## 4. AGS grade-passback poll job

- [ ] 4.1 Add `lib/Cron/LtiAgsScorePollJob.php` (NC `TimedJob`, 300s interval — matches
      openconnector's own `EventRetryJob` cadence): calls openconnector's
      `pull(subscriptionId)` REST endpoint (REQ-LTI-003 pull-cursor contract) via the same
      `IClientService`/`IURLGenerator`/`IAppConfig`-token pattern as `LtiToolPlacementController`,
      persisting the cursor between runs (app-config or a small tracking object — document the
      choice in the class docblock).
- [ ] 4.2 For each pulled `nl.conduction.lti.ags.score.received` message: resolve the
      `LtiToolPlacement` by `openconnectorDeploymentId`; skip (log, do not error) if no matching
      placement is found (an orphaned subscription message, e.g. a placement deleted after the
      score was already in flight).
- [ ] 4.3 Idempotency check: query `GradeEntry` for an existing row with the same
      `(ltiToolPlacementId, ltiAgsResultId)` pair; skip if found.
- [ ] 4.4 Create the concept `GradeEntry` mirroring
      `GradeRollupHandler::handleAssessmentResultGraded()`'s exact shape: `sourceKind: 'lti-ags'`,
      `componentId` = placement's `gradeEntryComponentId`, `curriculumPlanId` = placement's
      `curriculumPlanId`, `value` = the AGS score (normalised against `gradeScaleId`), `grader:
      'lti-ags'`, `gradedAt` = now, `tenant_id` from the placement, `lifecycle: 'concept'`,
      `ltiToolPlacementId`, `ltiAgsResultId`.
- [ ] 4.5 Register `LtiAgsScorePollJob` via `<background-jobs>` in `appinfo/info.xml` (NOT a
      non-existent `IRegistrationContext::registerJob()` call — matches the documented
      openconnector `EventRetryJob` registration requirement, same NC constraint applies here).
- [ ] 4.6 The job MUST catch and log any exception per pulled message so one malformed AGS
      message cannot wedge the whole poll sweep (mirrors `EventRetryJob`'s per-sweep exception
      containment).
- [ ] 4.7 Integration test: a pulled AGS message for a configured placement creates exactly one
      concept `GradeEntry` with the correct `componentId`/`curriculumPlanId`/`ltiAgsResultId`.
- [ ] 4.8 Idempotency test: pulling the same message twice (simulating a redelivery) creates
      exactly one `GradeEntry`, not two.
- [ ] 4.9 Orphan test: a message whose `openconnectorDeploymentId` matches no `LtiToolPlacement`
      is logged and skipped without throwing.

## 5. Registration bootstrap (admin-time, documented)

- [ ] 5.1 Document (admin settings help text or `docs/ARCHITECTURE.md`) the two-step bootstrap an
      admin performs once per tool placement, per openconnector's REQ-LTI-010 contract: (a)
      create the `lti_deployment` on the openconnector side naming this instance's launch-resolve
      URL as `launchTargetUrl` and leaving `gradeSink`/`rosterSource` informational (scholiq never
      has openconnector write there directly); (b) create the `event_subscription` on
      openconnector filtered to `type = 'nl.conduction.lti.ags.score.received'`,
      `style = 'pull'`.
- [ ] 5.2 Note in the same doc that NRPS (`rosterSource`) wiring is explicitly out of scope for
      this change (see `design.md` Non-goals) — the field exists on the openconnector contract but
      scholiq does not yet configure or consume it.

## 6. Docs + specs + traceability

- [ ] 6.1 Add `@spec openspec/changes/lti-tool-placement/tasks.md#task-N` docblock tags to
      `LtiToolPlacementController`, `LtiAgsScorePollJob`, and the `LessonPlayer.vue` launch
      branch.
- [ ] 6.2 Run `opsx-sync` (or manually merge) this change's `specs/course-management/spec.md` and
      `specs/grading/spec.md` deltas into `openspec/specs/course-management/spec.md` and
      `openspec/specs/grading/spec.md`.
- [ ] 6.3 Update `openspec/specs/course-management/spec.md`'s Data Model section to list
      `LtiToolPlacement` alongside the existing `Course`/`Module`/`Lesson`/... entities.
- [ ] 6.4 Run `composer check:strict` on all touched/new PHP files and fix any pre-existing
      warnings encountered in them (per CLAUDE.md).
- [ ] 6.5 Run `openspec validate lti-tool-placement --strict` and resolve any errors.
