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

### Requirement: Assessment declares which competencies it assesses, and Item carries competency tags for authoring

The `Assessment` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies a graded `AssessmentResult` for this assessment provides
evidence for, following the same shape as `Assignment.competencyIds`. The `Item` object MUST support the
same field for authoring/analytics purposes (cross-bank filtering by competency, alongside the existing
free-text `subjectTags`) — `Item.competencyIds` is NOT consumed by the `competency` capability's roll-up
in this change, because `AssessmentResult` produces one `GradeEntry` per attempt at `Assessment` grain,
not per `Item`; `Item.subjectTags` MUST remain unchanged. Both new fields MUST be additive and MUST NOT be
required.

#### Scenario: A published GradeEntry from an aligned Assessment feeds the competency roll-up

<!-- @e2e exclude Pure OpenRegister schema field; the roll-up behaviour is covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest, not a scholiq DOM surface here. -->

- **GIVEN** an `Assessment` with `competencyIds` set to one `Competency` UUID
- **WHEN** a learner's `AssessmentResult` for that assessment is graded and its `GradeEntry` transitions to
  `published`
- **THEN** the `competency` capability's roll-up handler creates or updates a `CompetencyAttainment` row
  for that learner and competency

#### Scenario: An item author tags items by competency for cross-bank search without affecting grading

<!-- @e2e exclude Pure OpenRegister schema field on authoring metadata; no DOM surface beyond the existing item-bank search UI already covered by this spec. -->

- **GIVEN** an `Item` being authored in an `ItemBank`
- **WHEN** the author sets `competencyIds` alongside the existing `subjectTags`
- **THEN** both fields persist independently, and `competencyIds` is available for cross-bank filtering
  without being consumed by the `competency` roll-up

### Requirement: Assessment declares per-learner release conditions

The `Assessment` object MUST support a `releaseConditions` field: an array of condition objects, each with
a `kind` (`lesson-completed` | `assessment-min-score`), and — depending on `kind` — a `lessonId` (`$ref
Lesson`, cross-referencing the `course-management` capability's schema), an `assessmentId` (`$ref
Assessment`), and/or a `minScore` (number). The field MUST be additive (default `[]`) and AND-combined,
mirroring the `course-management` capability's equivalent `Lesson.releaseConditions` requirement exactly.
An `assessment-min-score` condition MUST be satisfied by a `graded` `AssessmentResult` for the referenced
`Assessment` and the evaluating learner whose summed item scores (`responses[].autoScore` falling back to
`responses[].manualScore`, summed across all items) meet or exceed `minScore`. This score sum MUST be
computed directly by `LessonReleaseEvaluator` at evaluation time — it MUST NOT depend on a `GradeEntry`
having been created or soft-published, since coupling per-learner release-gating to the grading capability's
soft-publish review workflow is out of scope for this requirement (a candidate follow-up, not a dependency).
`releaseConditions` gating is layered ON TOP of `Assessment`'s existing `availableFrom`/`availableUntil`
absolute window and its materialised `isAvailable` calculation (unchanged) — an `Assessment` is available to
a given learner only when both the absolute window and every listed `releaseConditions` entry are satisfied.
An `Assessment` with an empty/absent `releaseConditions` array is gated by the absolute window alone,
matching today's behaviour.

#### Scenario: An assessment is unavailable until a minimum score on a prior assessment is met

<!-- @e2e exclude Score-summation and graded-lifecycle behaviour verified by PHPUnit against LessonReleaseEvaluator; the equivalent UI-locked-state rendering path is covered end-to-end by the course-management capability's Lesson release-condition scenarios, which exercise the same LessonPlayer gating call and evaluator. -->

- **GIVEN** an `Assessment` B with `releaseConditions: [{kind: "assessment-min-score", assessmentId: <Assessment A's id>, minScore: 60}]`
- **AND** a learner whose graded `AssessmentResult` for Assessment A sums to less than 60
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports Assessment B as unavailable, naming the unmet minimum-score condition

#### Scenario: An assessment unlocks once the learner meets the minimum score on the prior assessment

<!-- @e2e exclude Score-summation verified by PHPUnit against LessonReleaseEvaluator; no distinct DOM surface beyond the Lesson scenarios already covering LessonPlayer's gating call. -->

- **GIVEN** the same `Assessment` B from the scenario above
- **AND** the learner's graded `AssessmentResult` for Assessment A now sums to 60 or more
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports Assessment B as available (subject to its own absolute `availableFrom`/`availableUntil`
  window, unchanged)

### Requirement: Assessment supports drip release relative to each learner's own enrolment date

The `Assessment` object MUST support an `availableAfterDays` field: a nullable, non-negative integer
declaring the number of days after the learner's OWN `Enrolment.created` timestamp (for the assessment's
`courseId`) before the assessment becomes available to that learner, mirroring the `course-management`
capability's equivalent `Lesson.availableAfterDays` requirement exactly. `availableAfterDays` MUST NOT be
materialised as a schema-level calculated field, for the same reason `Lesson.availableAfterDays` cannot be:
the resolved per-learner instant differs per learner sharing the same `Assessment` row. When set, this gate
applies in addition to both the existing absolute `availableFrom`/`availableUntil` window and any
`releaseConditions` — an `Assessment` is available to a learner only once all three are satisfied.

#### Scenario: An assessment is locked until N days after the learner's own enrolment date, even within its absolute availability window

<!-- @e2e exclude Per-learner date arithmetic verified by PHPUnit against LessonReleaseEvaluator; the equivalent UI-locked-state rendering path is covered by the course-management capability's Lesson drip scenarios, which exercise the same LessonPlayer gating call and evaluator. -->

- **GIVEN** an `Assessment` with `availableFrom` already in the past (its absolute window is open) and
  `availableAfterDays: 7`
- **AND** a learner whose `Enrolment` for the course was created 3 days ago
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports the assessment as unavailable, naming the remaining drip delay (4 days), despite the
  absolute window already being open

### Requirement: Assessment supports a pooled random item draw as an alternative to a fixed item list
The system SHALL support `Assessment.itemSelectionMode` of `fixed` (existing behaviour — `itemRefs` is used
as-is) or `random-draw`. When `random-draw`, `Assessment.itemPoolConfig` MUST declare `itemBankId` (the
source `ItemBank`), `drawCount` (integer ≥ 1), and MAY declare `subjectTags` and/or
`difficultyMin`/`difficultyMax` to restrict the pool. The draw MUST be resolved only from `published`
`Item`s in the referenced bank matching the declared filters, and MUST include at most one `Item` per
`variantGroupId` in a single drawn set.

#### Scenario: A random-draw assessment draws the configured number of items from the filtered pool
- **GIVEN** an `ItemBank` with 10 `published` items tagged `subjectTags: ["algebra"]` and an `Assessment`
  with `itemSelectionMode: random-draw`, `itemPoolConfig: {itemBankId, drawCount: 5, subjectTags:
  ["algebra"]}`
- **WHEN** a learner starts the assessment
- **THEN** exactly 5 items are drawn, each `published` and tagged `algebra`

<!-- @e2e exclude Requires a seeded ItemBank with 10+ items and inspecting the exact drawn count/tag match
     server-side; the draw algorithm itself (AssessmentDrawResolver) is deterministic, unit-testable PHP
     (ItemAnalysisService/AssessmentDrawResolverTest), not a DOM assertion. -->

#### Scenario: A drawn set never includes two items from the same variant group
- **GIVEN** an `ItemBank` where items A and B share `variantGroupId: "v1"`
- **WHEN** a random draw resolves a set that would otherwise include both A and B
- **THEN** at most one of A or B is included in the drawn set

<!-- @e2e exclude Requires seeding items sharing a variantGroupId and repeated draws to observe the
     exclusivity property statistically; covered by AssessmentDrawResolverTest (PHPUnit), not a DOM
     assertion. -->

### Requirement: Per-attempt item-order and answer-option shuffle are independently configurable
`Assessment.shuffleItemOrder` and `Assessment.shuffleAnswerOptions` SHALL each be independently settable
booleans that apply regardless of `itemSelectionMode` (`fixed` or `random-draw`). When
`shuffleAnswerOptions` is true, the presented option order for a choice-bearing item MUST respect the QTI
3.0 `fixed` attribute on any `simpleChoice` that must not move.

#### Scenario: A learner taking a fixed-list assessment with shuffle enabled sees a permuted item order
- **GIVEN** an `Assessment` with `itemSelectionMode: fixed`, a 5-item `itemRefs` list, and
  `shuffleItemOrder: true`
- **WHEN** two different learners each start an attempt
- **THEN** `TakeAssessmentView` renders the 5 items for each learner, and the two learners' presented
  orders are independently resolved (not guaranteed identical)

<!-- @e2e tests/e2e/spec-coverage/assessment-item-pools-and-analysis.spec.ts -->

#### Scenario: A pinned distractor never moves when answer options shuffle
- **GIVEN** a choice item whose `qtiBody` marks one `simpleChoice` as `fixed`
- **WHEN** `shuffleAnswerOptions` is true and the item is drawn into an attempt
- **THEN** the fixed choice's position in `drawnItemRefs[].optionOrder` is unchanged relative to `qtiBody`'s
  declared order, while the other choices may be permuted

<!-- @e2e exclude Requires inspecting the resolved optionOrder array against the item's qtiBody-declared
     fixed attribute; covered by AssessmentDrawResolverTest (PHPUnit parsing test), not a DOM assertion
     (option-label positions are not a stable automation target across shuffle runs). -->

### Requirement: Every AssessmentResult persists a frozen, server-resolved snapshot of what was presented
The system SHALL populate `AssessmentResult.drawnItemRefs` (`{itemId, points, optionOrder}[]`) once, at
attempt creation, for every `AssessmentResult` regardless of `itemSelectionMode` or shuffle settings. Once
written, `drawnItemRefs` MUST NOT be recomputed or altered by any later process, so that a review or appeal
can reconstruct exactly what the learner saw independent of later edits to the `Assessment`, `ItemBank`, or
`Item` objects.

#### Scenario: The drawn snapshot survives a later edit to the source Assessment
- **GIVEN** a learner's `AssessmentResult` with a populated `drawnItemRefs`
- **WHEN** the parent `Assessment`'s `itemRefs` or `itemPoolConfig` is edited afterward
- **THEN** the existing `AssessmentResult.drawnItemRefs` is unchanged

<!-- @e2e exclude Requires editing an Assessment after an attempt exists and re-inspecting the prior
     result's persisted field server-side; covered by AssessmentDrawResolverTest and an integration test
     mirroring XapiCompletionHandlerIntegrationTest's shape, not a DOM assertion. -->

### Requirement: Item draw and shuffle resolution runs server-side and never trusts a client-supplied value
The system MUST resolve `AssessmentResult.drawnItemRefs` server-side only, via `AssessmentDrawResolver` (an
OpenRegister `ObjectCreatedEvent` listener, ADR-031 exception — the same trust boundary
`AssessmentScoringHandler` already enforces for `autoScore`), which MUST be the sole writer of the field. A
`drawnItemRefs` value supplied in the client's `AssessmentResult` create request MUST be ignored/overwritten,
not trusted.

#### Scenario: A client-supplied drawnItemRefs value is overwritten by the server-resolved draw
- **GIVEN** a crafted `AssessmentResult` create request that includes a `drawnItemRefs` value chosen by the
  client
- **WHEN** the object is created and `AssessmentDrawResolver` fires
- **THEN** the persisted `drawnItemRefs` reflects the server-side resolution, not the client-supplied value

<!-- @e2e exclude Negative-trust assertion requiring a crafted raw API request bypassing the normal UI
     flow and inspecting the persisted object server-side; covered by AssessmentDrawResolverTest
     (PHPUnit), consistent with how AssessmentScoringHandler's equivalent autoScore guarantee is verified
     (no DOM surface exists for "the server ignored what I sent"). -->

### Requirement: Publishing an Assessment requires a resolvable item source
`AssessmentPublishGuard`'s existing non-empty-`itemRefs` check MUST be extended: an `Assessment` with
`itemSelectionMode: random-draw` MAY publish instead when `itemPoolConfig.itemBankId` resolves to an
existing `ItemBank` that has at least `itemPoolConfig.drawCount` matching `published` `Item`s (after
applying `subjectTags`/`difficulty` filters and variant-group exclusivity — i.e. at least `drawCount`
*distinct variant groups* are available, not merely `drawCount` items that might collapse into fewer groups
after exclusivity is applied).

#### Scenario: A random-draw assessment with an insufficient pool cannot publish
- **GIVEN** an `Assessment` with `itemSelectionMode: random-draw` and `itemPoolConfig.drawCount: 10`, but
  its referenced `ItemBank` has only 6 matching `published` items
- **WHEN** the assessment's `publish` transition is attempted
- **THEN** the transition is blocked by `AssessmentPublishGuard`

<!-- @e2e exclude Lifecycle-guard rejection verified against the transition's return value/persisted
     lifecycle state; covered by an extended AssessmentPublishGuard PHPUnit test (mirrors the existing
     non-empty-itemRefs guard test shape), no DOM surface for a blocked transition's internal reason. -->

### Requirement: Per-item statistics are computed from graded results, gated by a minimum sample size
The system SHALL compute, per `(itemId, assessmentId)`, an `ItemStatistics` object from `graded`
`AssessmentResult`s referencing that item: `sampleSize`, `pValue` (proportion-correct), `itemTotalCorrelation`
(the corrected item-rest Pearson correlation — total score computed *excluding* the item's own
contribution), and, for choice-type items with a declared `correctResponse`, a `distractorAnalysis`
(per-option selection counts split by the top/bottom 27% scoring groups). `pValue`, `itemTotalCorrelation`,
and `distractorAnalysis` MUST be `null` (with `insufficientData: true`) when `sampleSize < 20`. This
computation MUST run in a PHP service (`ItemAnalysisService`) — it is not expressible via
`x-openregister-aggregations`, which supports only `count`/`sum`/`avg`/`min`/`max` over a single flat field
per matching row, not a group-by over elements of the nested `AssessmentResult.responses[]` array nor a
correlation/variance formula.

#### Scenario: An item's statistics remain null below the minimum sample
- **GIVEN** an `Item` referenced by only 12 `graded` `AssessmentResult`s for a given `Assessment`
- **WHEN** `ItemStatistics` for that `(itemId, assessmentId)` is computed
- **THEN** `pValue`, `itemTotalCorrelation`, and `distractorAnalysis` are `null` and `insufficientData` is
  `true`

<!-- @e2e exclude Requires seeding exactly 12 graded AssessmentResults and inspecting the computed
     ItemStatistics row; covered by ItemAnalysisServiceTest (PHPUnit), the threshold arithmetic itself has
     no DOM surface. -->

#### Scenario: An item's p-value and discrimination are computed once the minimum sample is met
- **GIVEN** an `Item` referenced by 25 `graded` `AssessmentResult`s for a given `Assessment`
- **WHEN** `ItemAnalysisRecomputeHandler` fires on the 25th result reaching `graded`
- **THEN** `ItemStatistics.pValue` reflects the proportion of respondents who scored full marks on the item,
  and `ItemStatistics.itemTotalCorrelation` reflects the Pearson correlation between the item's score vector
  and each respondent's item-excluded total score

<!-- @e2e exclude Statistical-formula correctness (p-value, corrected item-total correlation) requires a
     seeded fixture with known expected outputs; covered by ItemAnalysisServiceTest against hand-computed
     reference values, not a DOM assertion. -->

### Requirement: Per-assessment reliability (Cronbach's alpha) is computed with a minimum sample size
The system SHALL compute `AssessmentReliability.cronbachAlpha` for an `Assessment` from its `graded`
`AssessmentResult`s once `sampleSize ≥ 30` and the assessment has `≥ 2` items; below that,
`cronbachAlpha` is `null` and `insufficientData` is `true`. Both `ItemStatistics` and
`AssessmentReliability` carry no `x-openregister-lifecycle` and are fully derived — recomputed by
`ItemAnalysisRecomputeHandler`, never set directly by any client request.

#### Scenario: Reliability is null until 30 graded attempts exist
- **GIVEN** an `Assessment` with 22 `graded` `AssessmentResult`s
- **WHEN** `AssessmentReliability` for that assessment is inspected
- **THEN** `cronbachAlpha` is `null` and `insufficientData` is `true`

<!-- @e2e exclude Requires seeding 22 graded AssessmentResults and inspecting the computed reliability row;
     covered by ItemAnalysisServiceTest (PHPUnit), the threshold gate has no DOM surface. -->

### Requirement: A quality-threshold breach opens an ItemRevisionFlag routed to the exam board
The system SHALL create an append-only `ItemRevisionFlag` (`open` lifecycle state) when an `ItemStatistics`
computation with `insufficientData: false` crosses a configured quality threshold (too-difficult, too-easy,
low-discrimination, or negative-discrimination), referencing the item and the triggering
`ItemStatistics`, unless an `open` flag for the same `(itemId, reason)` already exists. `ItemRevisionFlag`
creation MUST NOT alter the flagged `Item` automatically — it is a review signal, not an automatic
retirement. Its `x-openregister-notifications` recipients MUST be the `examboard` and `admin` groups (the
same groups the `exam-board` capability's `FraudCase` notifications already use).

#### Scenario: A low-discrimination item opens a flag for the exam board, without altering the item
- **GIVEN** an `ItemStatistics` computation with `sampleSize: 40` and `itemTotalCorrelation: -0.15`
- **WHEN** `ItemAnalysisRecomputeHandler` evaluates the thresholds
- **THEN** an `ItemRevisionFlag` (`open`, `reason: negative-discrimination`) is created, the `examboard`
  and `admin` groups are notified, and the flagged `Item`'s own fields are unchanged

<!-- @e2e exclude Requires seeding statistics that cross the configured threshold and inspecting the
     created flag + notification recipients server-side; covered by ItemAnalysisRecomputeHandlerTest
     (PHPUnit). -->

#### Scenario: A resolved ItemRevisionFlag is reviewed through the standard flag queue
- **GIVEN** an `open` `ItemRevisionFlag`
- **WHEN** an `examboard` user opens its manifest-declared detail page and transitions it to `revised` or
  `dismissed`
- **THEN** the flag's lifecycle state updates and the review is recorded, using the same declarative
  list+detail surface `AttendanceFlag`/`BsaProgressFlag` already use

<!-- @e2e tests/e2e/spec-coverage/assessment-item-pools-and-analysis.spec.ts -->

### Requirement: Item and assessment statistics are read-restricted to staff roles
`ItemStatistics`, `AssessmentReliability`, and `ItemRevisionFlag` MUST carry `x-property-rbac` restricting
read access to `admin`/`teacher`/`examboard` roles, mirroring `AssessmentResult`'s existing property-level
RBAC. A learner MUST NOT be able to read an item's difficulty/discrimination statistics.

#### Scenario: A learner cannot read an item's psychometric statistics
- **GIVEN** a learner account with no `admin`/`teacher`/`examboard` role
- **WHEN** the learner requests an `ItemStatistics` object via the OpenRegister object API
- **THEN** the request is denied by RBAC

<!-- @e2e tests/e2e/spec-coverage/assessment-item-pools-and-analysis.spec.ts -->

### Requirement: ItemBank exports its items as a QTI 3.0 package

The system MUST support exporting an `ItemBank` and its `Item`s as a QTI 3.0 package (a ZIP containing an
`imsmanifest.xml` and one `assessmentItem` XML per `Item`), completing the "Items use QTI 3.0 as canonical
form" requirement's import-only coverage into a round-trip. Because every `Item.qtiBody` already holds
verbatim, valid QTI 3.0 XML — written by both `QtiImportService` on import and `ItemAuthorView` on manual
authoring — the exporter MUST wrap the stored `qtiBody` directly rather than re-deriving it from
`interactionType`/`correctResponse`, so export fidelity is not limited by the pre-existing import-side
interaction-type parsing gap (that gap affects what `QtiImportService` can *extract into* `correctResponse`
on import; it does not affect what is already stored in `qtiBody` and therefore does not affect export). The
export MUST be usable independently of course-package export (e.g. an item author moving one bank between
Scholiq tenants) and MUST be the same code path `course-management`'s course export calls for embedded
assessment items, per this capability's ownership of `Item`/`ItemBank`.

#### Scenario: Exporting an ItemBank produces a valid QTI 3.0 package

- **GIVEN** an `ItemBank` containing `Item`s of mixed `interactionType` (some fully parsed on import, some
  with only raw `qtiBody` preserved)
- **WHEN** an authorised user exports the `ItemBank`
- **THEN** the system produces a ZIP with an `imsmanifest.xml` referencing one `assessmentItem` XML per
  `Item`, each containing that item's stored `qtiBody` verbatim

#### Scenario: Export fidelity is not limited by the import-side parsing gap

<!-- @e2e exclude Verifies a data-fidelity property (stored qtiBody is exported byte-for-byte regardless of
     interactionType) via PHPUnit comparing the exported XML to the stored qtiBody; no DOM surface for XML
     byte-equality. -->

- **GIVEN** an `Item` whose `interactionType` was imported with the pre-existing "raw qtiBody preserved,
  correctResponse pending a future parser extension" degradation (an interaction type beyond `choice`/
  `extendedText`)
- **WHEN** that `Item`'s `ItemBank` is exported
- **THEN** the exported `assessmentItem` XML matches the stored `qtiBody` exactly, unaffected by the fact
  that `correctResponse` was not fully parsed on import

## Standards

IMS QTI 3.0 (canonical), QTI 2.x + Common Cartridge (import), LTI 1.3 (external tool launch), Caliper (events), AICC/SCORM/cmi5 for content-embedded quizzes (via `course-management`); EU AI Act Reg. 2024/1689 Annex III §3 (proctoring = high-risk) → ADR-005 gate; ISO/IEC 23988 (computer-based assessment) for proctoring conduct.

## Data Model

All in OpenRegister. New: `Assessment`, `Item`, `ItemBank`, `AssessmentResult`, `ProctoringSession`. Touches: `GradeEntry` (`grading`), `AiFeature` (existing — proctoring AI registration). ADR-031 PHP exceptions: `ProctoringProviderInterface`, the auto-scoring handler, the QTI import service. See `docs/ARCHITECTURE.md`.

## Out of Scope

- AI item generation, AI essay scoring, adaptive testing (EU AI Act Annex III §3 — explicitly deferred; would each be an `AiFeature` registration).
- Building a QTI authoring editor as a separate product (we run + import; a basic in-app item editor is in scope, a full standalone editor is not).
- Hand-in assignments (the `assignments` spec).
- The actual proctoring vendor implementations (interface only here).
