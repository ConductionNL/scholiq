# Tasks: lti-tool-placement

Size M. This is the scholiq-side consuming leaf of openconnector's in-flight `lti-13-platform`
adapter — no LTI protocol code (OIDC, JWT, JWKS) is built here; tasks cover the placement schema,
launch delegation, and AGS→GradeEntry poll bridge only.

## 1. Data model

- [x] 1.1 Add `LtiToolPlacement` to `lib/Settings/scholiq_register.json`: `lessonId` (nullable,
      `$ref: Lesson`), `courseId` (nullable, `$ref: Course`), `openconnectorDeploymentId`
      (`type: string, format: uuid`, no `$ref` — cross-register reference, mirrors
      `DataExchangeJob.connectorRunId`'s bare-string pattern), `launchMode`
      (`enum: [resource-link, deep-linking]`), `curriculumPlanId` (nullable, `$ref:
      CurriculumPlan`), `gradeEntryComponentId` (nullable, string — mirrors
      `AssessmentResult.gradeEntryComponentId`'s naming), `gradeScaleId` (nullable, `$ref:
      GradeScale`), `lifecycle` (`x-openregister-lifecycle`, `draft → active → retired`),
      `tenant_id`.
- [x] 1.2 Update `Lesson.contentRef`'s description in `lib/Settings/scholiq_register.json`
      (doc-only — no type change) to state that for `contentType: lti`, `contentRef` MUST be an
      `LtiToolPlacement` UUID, replacing the current vague "or LTI link" wording.
- [x] 1.3 Extend `GradeEntry.sourceKind` enum with `lti-ags`; add `ltiToolPlacementId` (nullable,
      `$ref: LtiToolPlacement`) and `ltiAgsResultId` (nullable, string) fields, following the
      exact shape of the existing `submissionId`/`assessmentResultId`/`sessionId` per-`sourceKind`
      fields.
- [x] 1.4 Bump the register JSON's schema version metadata per the app's existing convention
      (patch bump — additive fields, no breaking change). Bumped `info.version` 0.6.1 → 0.7.0
      (minor, matching the exam-board-case-handling precedent for "new schema + modified
      GradeEntry"); `GradeEntry`'s own per-schema `version` bumped 0.2.0 → 0.3.0.

## 2. Launch delegation (backend)

- [x] 2.1 Add `lib/Controller/LtiToolPlacementController.php`: `launch(string $placementId)`
      resolves the `LtiToolPlacement` via `ObjectService::find`, calls openconnector's
      Platform-role launch-initiation endpoint (REQ-LTI-006 in the other repo) passing
      `openconnectorDeploymentId`, using the same `IClientService` +
      `IURLGenerator::getAbsoluteURL()` + `IAppConfig` bearer-token shape
      `DataExchangeRunHandler::callOpenConnector()` already established (reuses the
      `scholiq.openconnector_api_token` config key — no second token added). Returns the opaque
      launch response (`{formActionUrl, idToken}`, plus a `launchMode` field Scholiq itself adds
      from the placement — not an LTI claim) unmodified; scholiq does not parse any LTI claim.
      **KNOWN GAP** (verified against openconnector HEAD, not assumed): the REST endpoint this
      calls does not exist yet — openconnector's merged `lti-13-platform` adapter exposes
      `LtiLaunchService::initiatePlatformLaunch()` only as an in-process PHP method with no HTTP
      route (`appinfo/routes.php` in that repo covers only the Tool-role inbound surface). This
      call will 404 until openconnector adds a thin REST wrapper — documented in full on
      `LtiToolPlacementController::OPENCONNECTOR_LAUNCH_PATH` and in
      `docs/Technical/architecture.md` §8.
- [x] 2.2 Register the route in `appinfo/routes.php` with the correct auth attribute
      (`#[NoAdminRequired]` — any authenticated learner may launch a placement they can see;
      per-object visibility is whatever already gates Lesson access).
- [x] 2.3 Document the assumed openconnector launch-initiation endpoint shape (path, request/
      response body) in a class-level docblock on `LtiToolPlacementController`, mirroring
      `DataExchangeRunHandler::OPENCONNECTOR_RUN_PATH`'s documented-assumption comment style,
      since the openconnector side of this contract is still in-flight in the other repo.
- [x] 2.4 Integration test (mocked `IClientService` response): a valid placement's launch call
      forwards the correct `openconnectorDeploymentId` and returns the mocked response
      unmodified. `tests/Unit/Controller/LtiToolPlacementControllerTest.php`.
- [x] 2.5 Error-path test: openconnector unreachable / non-2xx → `launch()` returns a clear error
      response (mirrors `callOpenConnector()`'s `null`-on-failure handling), not a silent empty
      body. Same test file.

## 3. LessonPlayer frontend delegation

- [x] 3.1 In `src/views/LessonPlayer.vue`, added a branch on `lesson.contentType === 'lti'`:
      resolves `lesson.contentRef` as an `LtiToolPlacement` UUID, calls the new `launch()`
      endpoint on mount, and auto-submits the opaque `{formActionUrl, idToken}` response as a
      real POST (an id_token cannot travel via GET) — new tab for `launchMode: 'resource-link'`,
      an in-page `<iframe>` for `launchMode: 'deep-linking'` (no content-picker UI, per
      Non-goals).
- [x] 3.2 Loading/error states for the launch call follow the existing `LessonPlayer.vue`
      `loading`/`error` pattern (own `ltiLaunching`/`ltiError` state, same shape).
- [x] 3.3 Added `LtiToolPlacements` (index) + `LtiToolPlacementDetail` (detail) manifest pages
      (`src/manifest.json`, `register: scholiq, schema: lti-tool-placement`) plus a "LTI tools"
      menu entry — no new PHP CRUD controller beyond `launch()` (creation goes through the
      existing generic OR object-save path via the manifest-declared detail page's create flow).

## 4. AGS grade-passback poll job

- [x] 4.1 Added `lib/Cron/LtiAgsScorePollJob.php` (NC `TimedJob`, 300s interval — matches
      openconnector's own `EventRetryJob` cadence): calls openconnector's real, verified
      `pull(subscriptionId)` REST endpoint (`GET /apps/openconnector/api/events/subscriptions/
      {id}/pull`) via `IClientService`/`IURLGenerator`, persisting the cursor via `IAppConfig`
      (`scholiq.lti_ags_pull_cursor`) — documented in the class docblock. **Documented deviation**
      from design.md: the real pull endpoint requires an authenticated NC session +
      `event.pull` group authorization (`EventsController::pull()`), not a bearer token — a bare
      `Authorization: Bearer` header does not authenticate it. The job therefore sends HTTP Basic
      auth using a new `scholiq.openconnector_api_user` config key (NC username) plus the SAME
      `scholiq.openconnector_api_token` value reused as the app-password (no second secret added,
      only a companion username). Full rationale in the class docblock and
      `docs/Technical/architecture.md` §8.
- [x] 4.2 For each pulled `nl.conduction.lti.ags.score.received` message: resolves the
      `LtiToolPlacement` by `openconnectorDeploymentId`; skips (logs, does not error) if no
      matching placement is found.
- [x] 4.3 Idempotency check: queries `GradeEntry` for an existing row with the same
      `(ltiToolPlacementId, ltiAgsResultId)` pair; skips if found. `ltiAgsResultId` = the pulled
      `event_message` row's own `id`/`uuid` (there is no AGS "resultId" on a Score POST itself;
      the message id is the stable, unique-per-delivery identifier available for idempotency —
      documented in the class docblock).
- [x] 4.4 Creates the concept `GradeEntry` mirroring `GradeRollupHandler
      ::handleAssessmentResultGraded()`'s shape: `sourceKind: 'lti-ags'`, `componentId`/
      `curriculumPlanId` from the placement, `value` (normalised against `gradeScaleId` via a
      new `normaliseScore()` helper — linear min/max scaling for numeric/percentage scales,
      raw `scoreGiven` fallback otherwise), `grader: 'lti-ags'`, `gradedAt`, `tenant_id` from the
      placement, `lifecycle: 'concept'`, `ltiToolPlacementId`, `ltiAgsResultId`. `learnerId` is
      read from the AGS score payload's `userId` field (the LTI `sub` the launch itself set —
      not an unvalidated external claim, since Scholiq minted it at launch time).
- [x] 4.5 Registered `LtiAgsScorePollJob` via `<background-jobs>` in `appinfo/info.xml`.
- [x] 4.6 The job catches and logs any exception per pulled message (per-message try/catch in
      `run()`) so one malformed AGS message cannot wedge the whole poll sweep.
- [x] 4.7 Integration test: a pulled AGS message for a configured placement creates exactly one
      concept `GradeEntry` with the correct `componentId`/`curriculumPlanId`/`ltiAgsResultId`.
      `tests/Unit/Cron/LtiAgsScorePollJobTest.php`.
- [x] 4.8 Idempotency test: pulling the same message twice creates exactly one `GradeEntry`.
- [x] 4.9 Orphan test: a message whose `openconnectorDeploymentId` matches no `LtiToolPlacement`
      is logged and skipped without throwing.

## 5. Registration bootstrap (admin-time, documented)

- [x] 5.1 Documented the two-step OpenConnector-side bootstrap plus the Scholiq-side placement
      creation in `docs/Technical/architecture.md` §8 ("LTI 1.3 tool placement (cross-repo)"),
      per openconnector's REQ-LTI-010 contract — including the `scholiq.lti_ags_subscription_id`
      / `scholiq.openconnector_api_user` config keys this change adds.
- [x] 5.2 Noted NRPS (`rosterSource`) as explicitly out of scope in the same doc section.

## 6. Docs + specs + traceability

- [x] 6.1 Added `@spec openspec/changes/lti-tool-placement/tasks.md#task-N` docblock tags to
      `LtiToolPlacementController`, `LtiAgsScorePollJob`, and the `LessonPlayer.vue` launch
      branch (same `tasks.md#task-N` convention used fleet-wide by every other Scholiq
      `@spec` tag — see the gate-46 flag below).
- [x] 6.2 Manually merged this change's `specs/course-management/spec.md` and
      `specs/grading/spec.md` deltas into `openspec/specs/course-management/spec.md` and
      `openspec/specs/grading/spec.md` (`opsx-sync` CLI not invoked; merged by hand, same
      resulting content).
- [x] 6.3 Updated `openspec/specs/course-management/spec.md`'s Data Model section to list
      `LtiToolPlacement` alongside the existing `Course`/`Module`/`Lesson`/... entities.
- [x] 6.4 Ran `composer` quality tooling on all new/touched PHP (lint, phpcs, phpstan, psalm) —
      all clean on `lib/Controller/LtiToolPlacementController.php` and
      `lib/Cron/LtiAgsScorePollJob.php` (phpcbf auto-fixed mechanical alignment issues; four
      "inline IF" violations fixed by hand; one phpstan always-non-null-offset finding fixed).
      Did not run the full-repo `composer check:strict` (`test:all` inside it requires a live NC
      environment per its own fallback echo) — relied on `phpunit-unit.xml` + the hydra gates
      instead, per the apply-common instructions.
- [x] 6.5 Ran `openspec validate lti-tool-placement --type change --strict` →
      "Change 'lti-tool-placement' is valid".
