---
kind: code
depends_on: []
---

# Proposal: lti-tool-placement

## Why

Scholiq already half-declares LTI as a lesson content type and reserves nothing else for it —
the schema promises a capability the runtime never delivers, exactly the pattern the sibling
`cmi5-xapi-lrs-ingest` change already documented for cmi5/xAPI.

- **`Lesson.contentType` already lists `lti`.** `lib/Settings/scholiq_register.json` —
  `Lesson.properties.contentType.enum` is `["text", "video", "scorm12", "scorm2004", "cmi5",
  "lti", "quiz"]`, and `Lesson.properties.contentRef.description` reads "nc:files path, cmi5
  launch URL, **or LTI link**" — the schema was authored with LTI in mind from the start.
- **Nothing reads that enum value.** `src/views/LessonPlayer.vue:50-53` renders `lesson.content`
  as raw `v-html` unconditionally — there is no branch on `contentType` at all, for `lti`, `cmi5`,
  or any other non-`text` value. A Lesson saved with `contentType: 'lti'` today plays back as an
  empty content block; nothing in the frontend or `lib/` even reads `contentRef`.
  Repo-wide grep for `lti`/`LTI` across `lib/` and `src/` returns zero hits outside the schema
  enum and unrelated substring matches (`QtiImportController`, `multi-...`) — confirmed via a
  dedicated grep pass, not assumed.
- **`course-management`'s own requirement never mentions LTI.** `openspec/specs/course-management/spec.md:51-58`
  ("Run cmi5 + xAPI natively with SCORM shim") covers cmi5/xAPI/SCORM only; no requirement in the
  spec names LTI at all, despite the schema enum already carrying it — the spec and the schema
  drifted apart, the same drift `cmi5-xapi-lrs-ingest`'s Why section documented for the cmi5 half.
- **`grading`'s `GradeEntry.sourceKind` enum has no automated-external-passback value.**
  `lib/Settings/scholiq_register.json` `GradeEntry.properties.sourceKind.enum` is
  `["assignment-submission", "assessment-result", "participation", "manual"]`. An LTI Assignment &
  Grade Services (AGS) score has nowhere honest to land: `manual` would misrepresent an
  automated, externally-authenticated score as a teacher's own keystroke, losing the
  provenance the grading spec's own `grader`/`gradedAt` fields exist to preserve.
- **openconnector's `lti-13-platform` change (other repo, read at
  `openconnector-dev/openspec/changes/lti-13-platform/{proposal,design}.md`) already names this
  change as its consuming leaf and defines the exact contract it expects.** Proposal.md:154-160:
  *"scholiq `lti-tool-placement` (leaf, other repo) — the consuming-app side: registers an
  `lti_deployment` placing an external tool inside a lesson/course, and subscribes an
  `event_subscription` to `nl.conduction.lti.ags.score.received` to create a `GradeEntry`."*
  REQ-LTI-010 spells out the two-object contract precisely: one `lti_deployment` (naming
  `launchTargetUrl`, `gradeSink`, `rosterSource`) plus one `event_subscription` filtered to
  `type = 'nl.conduction.lti.ags.score.received'`. This change is scholiq honouring that
  contract — the openconnector side (OIDC login/launch, JWKS, AGS/NRPS protocol handling) is
  built there, not duplicated here.
- **Demand evidence** (Spectr, same insights the openconnector adapter itself cites): insight
  1005 (Moodle-coexistence on-ramp — LTI is how Scholiq interoperates with the Moodle-based
  state platforms and existing school LMS deployments without requiring a full migration) and
  insight 1033 ("LTI belongs in openconnector" — the protocol adapter is a platform concern; the
  consuming-app leaf, this change, is what actually makes a lesson launchable). `nl_standards`
  id 215 tracks LTI 1.3 as the standard; 15 of 53 surveyed competitors ship it (Moodle,
  Blackboard, Brightspace, Sakai, ILIAS, OpenOLAT, Chamilo, GoodHabitz, Docebo, ProctorU,
  Coursera, Questionmark, MS Teams, It's Learning, ATutor) — the broadest single
  competitor-coverage gap identified in the scholiq gap sweep.

## What Changes

- **`course-management` — ADD `LtiToolPlacement`**, a new OpenRegister schema: `lessonId`
  (`$ref: Lesson`), `courseId` (nullable, `$ref: Course`, for a course-level placement not tied
  to one lesson), `openconnectorDeploymentId` (`type: string, format: uuid` — the UUID of the
  `lti_deployment` object living in **openconnector's** register; a plain UUID string, not a
  `$ref`, because OR relations do not span registers/apps — mirrors how `DataExchangeRunHandler`
  already carries a bare `connectorRunId` string for the same cross-app reason), `launchMode`
  (`resource-link | deep-linking`), `curriculumPlanId` (nullable, `$ref: CurriculumPlan`),
  `gradeEntryComponentId` (nullable — the `CurriculumPlan.components[].componentId` an AGS score
  for this placement feeds, mirroring `AssessmentResult.gradeEntryComponentId`'s exact naming),
  `gradeScaleId` (nullable, `$ref: GradeScale`), `lifecycle` (`draft → active → retired`).
  `Lesson.contentRef`'s existing description ("nc:files path, cmi5 launch URL, or LTI link") is
  corrected in place (doc-only, no type change — the field is already a free-text string) to say
  the LTI convention is an `LtiToolPlacement` UUID, not a raw link — a static "LTI link" cannot
  carry a signed OIDC launch.
- **`course-management` — ADD "LessonPlayer delegates OIDC launch to the openconnector
  adapter."** `LessonPlayer.vue` gains a branch on `lesson.contentType === 'lti'`: it resolves
  `lesson.contentRef` to an `LtiToolPlacement`, then calls a new scholiq backend endpoint
  (`LtiToolPlacementController::launch(string $placementId)`) that delegates to openconnector's
  Platform-role launch-initiation service (openconnector REQ-LTI-006) using the **exact**
  outbound-call shape `DataExchangeRunHandler::callOpenConnector()` already established
  (`IClientService` + `IURLGenerator::getAbsoluteURL()` + a bearer token from `IAppConfig` —
  `scholiq.openconnector_api_token`, the same config key, reused rather than adding a second
  token). Scholiq never touches OIDC/JWT/JWK material itself — it forwards the placement's
  `openconnectorDeploymentId`, gets back an auto-submitting launch form / URL, and renders it
  (new tab for `resource-link`, an in-page frame for `deep-linking` review). This is the launch
  seam in full: **one authenticated REST call out, one opaque response rendered back** — no LTI
  protocol logic in scholiq.
- **`grading` — MODIFY `GradeEntry`**: extend `sourceKind` enum with `lti-ags`; add
  `ltiToolPlacementId` (nullable, `$ref: LtiToolPlacement`) and `ltiAgsResultId` (nullable,
  `type: string` — the AGS `resultId`/CloudEvent message id, used as the idempotency key so a
  redelivered pull message cannot create a duplicate `GradeEntry`) alongside the existing
  per-`sourceKind` id fields (`submissionId`, `assessmentResultId`, `sessionId`), same shape,
  new kind.
- **New `LtiAgsScorePollJob`** (NC `TimedJob`, cron — mirrors openconnector's own
  `EventRetryJob` interval-cron pattern, `lib/Cron/EventRetryJob.php`, and matches
  `data-exchange`'s existing convention that exchange jobs run "pull/push on demand or on a
  schedule," `openspec/specs/data-exchange/spec.md:98"): calls openconnector's
  `pull(subscriptionId)` REST endpoint (openconnector REQ-LTI-003's pull-cursor contract) for the
  scholiq-owned `event_subscription`, and for each `nl.conduction.lti.ags.score.received`
  message: resolves the `LtiToolPlacement` by `openconnectorDeploymentId`, skips if a
  `GradeEntry` with the same `(ltiToolPlacementId, ltiAgsResultId)` already exists (idempotency),
  and otherwise creates a **concept** `GradeEntry` — the exact same shape
  `GradeRollupHandler::handleAssessmentResultGraded()` already produces for `assessment-result`
  (`sourceKind`, `componentId`, `curriculumPlanId`, `value`, `gradeScaleId`, `grader`, `gradedAt`,
  `tenant_id`, `lifecycle: 'concept'`), so it enters the identical soft-publish review flow a
  teacher already uses for auto-scored assessments — no new grading UX.
- **No push/webhook receiver in scholiq.** `data-exchange`'s own Out of Scope
  (`openspec/specs/data-exchange/spec.md:98`) already rules out "real-time webhook ingestion …
  streaming is a follow-up," and no scholiq `@PublicPage` inbound-webhook precedent exists at
  HEAD. Pulling extends the one cross-app pattern scholiq already has
  (`DataExchangeRunHandler`'s authenticated outbound REST call) instead of adding a new inbound
  public surface for a size-M change.
- **Explicitly out of scope**: NRPS roster serving (would require exposing scholiq
  Enrolment/Cohort membership through openconnector's ADR-008 register/schema read path — a
  real, separate follow-up, not implied by "tool placement"); a Deep Linking content-picker UI
  (the openconnector `lti-13-platform` design.md's own Non-goals section already names this as
  "a consuming-app concern (scholiq `lti-tool-placement` or equivalent)" — acknowledged here as
  deferred, not silently dropped); any LTI protocol code (OIDC login, JWT signing/verification,
  JWKS) — all of that is `lti-13-platform`'s scope in the other repo, not duplicated here.

## Cross-repo / cross-change relationships (prose)

- **openconnector `lti-13-platform`** (other repo, in-flight — `design.md` + `proposal.md` read
  in full for this change) defines the contract this leaf consumes: `lti_deployment` shape
  (REQ-LTI-001), the Platform-role launch-initiation service (REQ-LTI-006), the AGS CloudEvent
  `nl.conduction.lti.ags.score.received` fanned out via the existing `events-cloudevents`
  mechanism, never written directly to a consuming-app register (REQ-LTI-007), and the
  two-object consuming-app contract (REQ-LTI-010). This change does not implement any of that —
  it is the scholiq-side consumer.
- **`cmi5-xapi-lrs-ingest`** (this repo, in-flight) — the closest in-repo parallel for a
  learner-facing external-content launch: a server-minted, short-lived launch reference that the
  consuming surface resolves server-side, never trusting client-supplied identity claims
  (`Cmi5LaunchTokenService`/`XapiCompletionHandler`'s documented trust boundary). This change's
  launch endpoint follows the identical stance — the opaque launch response openconnector
  returns is rendered, not parsed for identity claims, by `LessonPlayer.vue`.
- **`DataExchangeRunHandler`** (this repo, done) — the one existing outbound
  scholiq→openconnector authenticated-REST precedent (`IClientService` + `IURLGenerator` +
  `IAppConfig` bearer token). Both this change's launch-delegation call and its poll job reuse
  that exact shape and the same `scholiq.openconnector_api_token` config key rather than
  inventing a second cross-app auth mechanism.

## Impact

- **Specs**: `course-management` (2 ADDED requirements: `LtiToolPlacement` data model, launch
  delegation); `grading` (1 MODIFIED requirement: `GradeEntry.sourceKind` + new id fields).
- **Schema**: `lib/Settings/scholiq_register.json` — new `LtiToolPlacement` schema;
  `Lesson.contentRef` description corrected (doc-only); `GradeEntry.sourceKind` enum extended,
  two new nullable fields added.
- **Backend (new)**: `lib/Controller/LtiToolPlacementController.php` (`launch()` — delegates to
  openconnector); `lib/Cron/LtiAgsScorePollJob.php` (TimedJob, calls openconnector's pull
  endpoint, creates concept `GradeEntry`s); `appinfo/info.xml` background-job registration;
  `appinfo/routes.php` new route for `launch()`.
- **Backend (reused, not modified)**: `DataExchangeRunHandler`'s outbound-call shape (pattern
  reused, not the class itself); `ObjectService::saveObject`/`findAll` (existing OR access).
- **Frontend**: `src/views/LessonPlayer.vue` gains a `contentType === 'lti'` branch; a small
  admin-facing form (or manifest page) to create an `LtiToolPlacement` referencing an
  openconnector `lti_deployment` UUID — no new custom Vue beyond that (index/detail pages stay
  manifest-declared per the app's existing frontend convention).
- **Not affected**: openconnector's own LTI protocol implementation (other repo, not built
  here); NRPS; Deep Linking content-picker UI; `FinalGrade` roll-up logic (`lti-ags` `GradeEntry`
  rows flow through the existing unmodified `calculatedChange` trigger).
