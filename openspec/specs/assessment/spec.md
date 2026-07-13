---
slug: assessment
title: Assessment — Tests, Exams, Item Banks, Proctoring
status: done
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [toets-vo, tentamen-he, examen, certification-exam, formative-quiz]
replaces: [assessment-engine, proctoring]
---

# Assessment

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, lifecycle guards, a pluggable PHP interface (ProctoringProviderInterface), and a calculation engine hook — none of this is expressed as UI scenarios. No `#### Scenario:` headings exist in this spec.

## Purpose

Beyond hand-in assignments, institutions run **structured tests**: a vmbo `toets`, an HBO `tentamen`, a state `examen`, a certification `exam`, a formative `quiz`. These have an item bank, a scoring scheme, often a time limit, sometimes proctoring. No open-source Dutch assessment platform exists today (Cito / DiatOets / IEP are all proprietary); IMS **QTI 3.0** is the lock-in escape hatch. And the **EU AI Act** (Reg. 2024/1689) classifies online proctoring as high-risk AI — so proctoring must be a *pluggable provider*, never a hard dependency on one US vendor, and any AI-assisted flag-review must register in the `AiFeature` gate (ADR-005). This spec merges what were two separate Dutch stubs (`assessment-engine` + `proctoring`) into one capability: proctoring is *configuration on an Assessment*, not a standalone schema.

## What

- **Assessment** — a structured test: title, Course/Session/CurriculumPlan-component it belongs to, item list (referenced from an item bank, or inline QTI 3.0), `scoringScheme` (points / IRT / pass-mark), `timeLimitMinutes`, attempt policy (`maxAttempts`, `keepScore: best|last|average`), availability window, and an optional `x-proctoring` config block (`provider`, `lockdownBrowser`, `recordWebcam`, `flagReviewMode: manual|ai-assisted`).
- **Item** / **ItemBank** — QTI 3.0 assessment items (multiple-choice, fill-in, essay, hotspot, ordering…), grouped into reusable banks; import from QTI 2.x/3.0 packages and Common Cartridge.
- **AssessmentResult** — a learner's attempt: answers, auto-scored points, manual-scored points (for essay items), `lifecycle` (in-progress → submitted → graded), `proctoringSessionId`. Becomes a `GradeEntry` (see `grading`).
- **ProctoringSession** — created when an Assessment is proctored: provider session id, status, recorded artefacts (URLs only — bytes stay with the provider or in OR attachments), flagged events. Reviewed by an invigilator; a flag never auto-fails — it surfaces for human decision (EU AI Act Art. 14 human oversight).
- **`ProctoringProviderInterface`** — the one PHP seam: `startSession()`, `endSession()`, `fetchFlags()`. No bundled provider; adapters (ProctorU / Honorlock / a SURF-hosted option) implement it. AI-assisted flag review, if ever enabled, MUST be registered as an `AiFeature` with a DPO acknowledgement (ADR-005).

## User Stories

- As an item author, I want to import a QTI 3.0 package and reuse those items across multiple Assessments and cohorts.
- As a teacher, I want to compose an Assessment from an item bank with a time limit and a pass mark, attach it to a CurriculumPlan component, and have the result feed the grade.
- As an exam coordinator, I want to enable proctoring on an Assessment, pick the provider, and have learners' sessions recorded — without locking the institution to one vendor.
- As an invigilator, I want flagged proctoring events queued for my review, with the decision (allow / annul) recorded, never applied automatically.
- As a learner, I want auto-scored items marked instantly and to see which items a teacher still has to grade by hand.

## Acceptance Criteria

- GIVEN a QTI 3.0 package, WHEN an item author imports it, THEN the items appear in the chosen ItemBank and validate against QTI 3.0.
- GIVEN an Assessment with `maxAttempts=2` and `keepScore=best`, WHEN a learner submits two attempts, THEN the `GradeEntry` value is the higher of the two.
- GIVEN a proctored Assessment with `provider="X"`, WHEN a learner starts, THEN a `ProctoringSession` is created via the X adapter; provider X is *not* hard-coded anywhere — swapping the config to `provider="Y"` uses the Y adapter with no code change.
- GIVEN a proctoring flag is raised, WHEN the invigilator opens the review queue, THEN the flag is shown for a human decision; no result is altered until the invigilator records one.
- GIVEN an Assessment with essay items, WHEN auto-scoring runs, THEN the `AssessmentResult` lifecycle is `submitted` (not `graded`) until the teacher scores the essay items.
## Requirements
### Requirement: Persist Assessment domain objects in OpenRegister
The system MUST persist `Assessment`, `Item`, `ItemBank`, `AssessmentResult`, `ProctoringSession` as OpenRegister objects with `x-openregister-lifecycle` (AssessmentResult: in-progress → submitted → graded), `x-openregister-relations`, and `x-openregister-calculations` (AssessmentResult `autoScore`, `totalScore`, `passed`).

#### Scenario: Assessment objects persist in OpenRegister
- **GIVEN** the assessment schemas are registered in OpenRegister
- **WHEN** an `Assessment`, `Item`, `ItemBank`, `AssessmentResult`, or `ProctoringSession` is created
- **THEN** it is stored as an OpenRegister object carrying its `x-openregister-lifecycle`, `x-openregister-relations`, and `x-openregister-calculations` metadata (AssessmentResult moving in-progress → submitted → graded)

### Requirement: Items use QTI 3.0 as canonical form
Items MUST be QTI 3.0 (importing QTI 2.x and Common Cartridge is required; QTI 3.0 is the canonical stored form).

#### Scenario: Items stored canonically as QTI 3.0
- **GIVEN** a QTI 2.x package or Common Cartridge import
- **WHEN** an item author imports the package into an ItemBank
- **THEN** the items are converted to and stored in canonical QTI 3.0 form

### Requirement: Proctoring is a pluggable provider
Proctoring via an external vendor MUST be a declared `x-proctoring.provider` config resolving to
`ProctoringProviderInterface`; the app MUST ship NO concrete external provider. A proctoring flag — from an
external provider or from native test mode — MUST NOT auto-alter a result (EU AI Act Art. 14). Native test
mode (see "Native test mode" below) is a separate, always-available, non-pluggable path and is not a
`ProvidesProctoring` implementation; the "no concrete provider" rule applies to the external-adapter seam
only.

#### Scenario: Proctoring resolves to a pluggable provider without auto-altering results
- **GIVEN** an Assessment with an `x-proctoring.provider` config and no concrete provider bundled in the app
- **WHEN** the config resolves the provider through `ProctoringProviderInterface` and a proctoring flag is
  raised
- **THEN** the configured adapter handles the session and the flag never auto-alters a result (EU AI Act
  Art. 14 human oversight)

<!-- @e2e exclude Pre-existing external-provider path, unchanged by this proposal; no scholiq DOM surface
     to drive without a live vendor adapter (the canonical assessment spec's own top-level note already
     excludes this seam as pure backend/PHP-interface). -->

#### Scenario: Native test mode does not require or resolve an external provider
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true` and no `provider` set
- **WHEN** a learner starts the assessment
- **THEN** no `ProvidesProctoring` implementation is resolved or required, and the assessment proceeds using
  only Scholiq's built-in browser-JS hardening

<!-- @e2e exclude Negative assertion (no ProvidesProctoring resolved) verified by TakeAssessmentView.vue's
     init() branch logic — there is no PHP adapter call in the native-mode branch to observe absence of at
     runtime; a live vendor would be required to assert non-invocation against. -->

### Requirement: AI-assisted flag review requires DPO acknowledgement
Any AI-assisted flag review MUST be registered via the `AiFeature` schema with a DPO acknowledgement before it can be enabled (ADR-005); v1 ships with `flagReviewMode: manual` only — `ai-assisted` is a future, gated feature.

#### Scenario: AI-assisted flag review gated behind AiFeature DPO acknowledgement
- **GIVEN** an attempt to enable `flagReviewMode: ai-assisted`
- **WHEN** the feature is activated
- **THEN** it is blocked unless the AI-assisted review is registered via the `AiFeature` schema with a DPO acknowledgement (ADR-005); v1 permits only `flagReviewMode: manual`

### Requirement: Graded AssessmentResult emits a GradeEntry
A graded `AssessmentResult` MUST emit (or update) a `GradeEntry` for its CurriculumPlan component (consumed by `grading`).

#### Scenario: Graded result emits a GradeEntry
- **GIVEN** an `AssessmentResult` that reaches the `graded` lifecycle state
- **WHEN** the result is graded
- **THEN** a `GradeEntry` for its CurriculumPlan component is emitted or updated for consumption by the `grading` spec

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for Assessment/ItemBank index+detail; a custom `TakeAssessmentView` (the timed test-taking surface — genuine UI), `ItemAuthorView`, and `ProctoringReviewQueue` Vue components. No PHP CRUD controllers. The only PHP: `ProctoringProviderInterface` + the auto-scoring lifecycle handler (an ADR-031 "calculation engine above schema metadata" exception) + QTI import.

#### Scenario: Frontend is declarative with named custom views
- **GIVEN** the assessment app frontend
- **WHEN** the UI is composed
- **THEN** Assessment/ItemBank index+detail are declarative `src/manifest.json` pages and the only custom Vue views are `TakeAssessmentView`, `ItemAuthorView`, and `ProctoringReviewQueue`, with no PHP CRUD controllers (PHP limited to `ProctoringProviderInterface`, the auto-scoring lifecycle handler, and QTI import)

### Requirement: Native test mode provides browser-JS delivery hardening for low/mid-stakes assessments
When `Assessment.proctoring.nativeTestMode` is `true`, the system SHALL, without any external adapter:
present a pre-start screen to the learner stating in plain language what is logged (fullscreen exits, tab/
window focus loss, blocked navigation), what is never captured (no camera, no microphone, no page content
outside the assessment), and that this is deterrence rather than guaranteed prevention; request the browser
Fullscreen API when `lockdownBrowser: true`; and block/warn on back-navigation and tab close/reload when
`navigationLock: true`. The system MUST NOT request camera or microphone access, and MUST NOT perform any
biometric, gaze, or emotion inference, under native test mode — native test mode is out of EU AI Act
Annex III §3 scope precisely because it performs no such inference.

#### Scenario: Learner sees the native test-mode disclosure before starting
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true`
- **WHEN** a learner opens `TakeAssessmentView` for that Assessment
- **THEN** a pre-start screen states what is logged, what is never captured, and that the mode deters rather
  than prevents circumvention, before any attempt is created
- **AND** the learner must actively choose to start before an `AssessmentResult` is created

<!-- @e2e tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts -->

#### Scenario: Native test mode never captures camera or microphone
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true`
- **WHEN** the learner takes the assessment
- **THEN** no camera or microphone permission is requested and no audio/video is captured at any point

<!-- @e2e exclude Negative code-absence assertion — no getUserMedia/mediaDevices call exists anywhere in
     TakeAssessmentView.vue (grep-verifiable); a live permission-prompt-absence check is flaky and
     browser/instance-dependent, not a stable e2e assertion. -->

### Requirement: Native test-mode events log into the existing ProctoringSession review queue
When native test mode is active, the system SHALL create a `ProctoringSession` (`provider:
"native-test-mode"`) scoped to the learner's `AssessmentResult`, and SHALL append fullscreen-exit,
tab/window-focus-loss, and blocked-navigation events to `ProctoringSession.flags[]` using the schema's
existing flag shape (`flagId`, `kind`, `occurredAt`, `severity`, `reviewDecision: "pending"`). These sessions
and flags SHALL appear, unmodified, in the existing `ProctoringReviewQueue` alongside externally-provided
proctoring sessions. A native test-mode flag MUST NOT auto-alter the associated `AssessmentResult` — only an
invigilator's decision, recorded through the existing review queue, changes a flag's `reviewDecision` (EU AI
Act Art. 14, same discipline as the external-provider path).

#### Scenario: A fullscreen exit is logged as a reviewable flag
- **GIVEN** a learner taking an Assessment with `proctoring.nativeTestMode: true` and `lockdownBrowser: true`
- **WHEN** the learner exits fullscreen during the attempt
- **THEN** a `fullscreen-exit` flag with `reviewDecision: "pending"` is appended to the associated
  `ProctoringSession.flags[]`
- **AND** the flag never alters the `AssessmentResult`'s lifecycle, score, or `passed` value

<!-- @e2e exclude Requires a seeded nativeTestMode Assessment plus a live Fullscreen API interaction
     (browsers restrict programmatic fullscreen exit without a user gesture in automation) and a running
     OpenRegister backend to observe the PUT round-trip. The append-flag PUT pattern itself is identical to
     ProctoringReviewQueue.vue::recordDecision(), already relied on in production and unit-testable at the
     schema level (tests/Unit/Settings/SecureExamTestModeTest.php). -->

#### Scenario: Native test-mode sessions appear in the existing review queue unchanged
- **GIVEN** a `ProctoringSession` created by native test mode with at least one pending flag
- **WHEN** an invigilator opens `ProctoringReviewQueue`
- **THEN** the session and its flags are listed exactly as an externally-provided session would be, and the
  invigilator's `allowed`/`annulled` decision is recorded the same way

<!-- @e2e tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts -->

### Requirement: Single-attempt window guard prevents concurrent duplicate attempts
The system SHALL prevent a learner from having more than one non-terminal (`in-progress`) `AssessmentResult`
open concurrently for the same `Assessment`: before creating a new `AssessmentResult`, `TakeAssessmentView`
SHALL check for an existing `in-progress` result for the same learner and Assessment and resume it instead of
creating a duplicate. When a second browser tab or window attaches to an `Assessment` that already has an
active native test-mode session open in another tab on the same device, the system SHALL block that second
tab from proceeding and SHALL log a `concurrent-session-detected` flag on the associated `ProctoringSession`
when one exists.

#### Scenario: Reloading the assessment resumes the in-progress attempt instead of duplicating it
- **GIVEN** a learner has an `in-progress` `AssessmentResult` for an Assessment
- **WHEN** the learner reloads or reopens `TakeAssessmentView` for the same Assessment
- **THEN** the existing `in-progress` `AssessmentResult` is resumed and no second `AssessmentResult` is
  created

<!-- @e2e exclude Requires a seeded in-progress AssessmentResult plus a reload-then-inspect-created-count
     assertion against a live OpenRegister backend. The resume-vs-create branch logic
     (getOrCreateResult()/checkExistingAttempt() in TakeAssessmentView.vue) is deterministic client-side
     code, not environment-dependent DOM behaviour. -->

#### Scenario: A second tab on the same device is blocked and logged
- **GIVEN** a learner has an active native test-mode attempt open in one browser tab
- **WHEN** the learner opens the same Assessment in a second tab in the same browser
- **THEN** the second tab is blocked from rendering assessment items
- **AND** a `concurrent-session-detected` flag with `severity: "high"` is appended to the `ProctoringSession`
  for review

<!-- @e2e exclude Requires two concurrent browser contexts sharing the same origin's localStorage plus a
     seeded native-mode attempt — out of scope for this M-sized change's lightweight smoke coverage,
     consistent with how sibling specs (e.g. school-year-rollover) treat backend/concurrency-heavy
     scenarios. The tab-lock heartbeat logic (acquireTabLock()/writeTabLock() in TakeAssessmentView.vue) is
     deterministic client-side code. -->

## Standards

IMS QTI 3.0 (canonical), QTI 2.x + Common Cartridge (import), LTI 1.3 (external tool launch), Caliper (events), AICC/SCORM/cmi5 for content-embedded quizzes (via `course-management`); EU AI Act Reg. 2024/1689 Annex III §3 (proctoring = high-risk) → ADR-005 gate; ISO/IEC 23988 (computer-based assessment) for proctoring conduct.

## Data Model

All in OpenRegister. New: `Assessment`, `Item`, `ItemBank`, `AssessmentResult`, `ProctoringSession`. Touches: `GradeEntry` (`grading`), `AiFeature` (existing — proctoring AI registration). ADR-031 PHP exceptions: `ProctoringProviderInterface`, the auto-scoring handler, the QTI import service. See `docs/ARCHITECTURE.md`.

## Out of Scope

- AI item generation, AI essay scoring, adaptive testing (EU AI Act Annex III §3 — explicitly deferred; would each be an `AiFeature` registration).
- Building a QTI authoring editor as a separate product (we run + import; a basic in-app item editor is in scope, a full standalone editor is not).
- Hand-in assignments (the `assignments` spec).
- The actual proctoring vendor implementations (interface only here).
