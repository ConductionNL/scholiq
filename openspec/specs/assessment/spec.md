---
slug: assessment
title: Assessment — Tests, Exams, Item Banks, Proctoring
status: implemented
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
Proctoring MUST be a declared `x-proctoring.provider` config resolving to `ProctoringProviderInterface`; the app MUST ship NO concrete provider. A proctoring flag MUST NOT auto-alter a result (EU AI Act Art. 14).

#### Scenario: Proctoring resolves to a pluggable provider without auto-altering results
- **GIVEN** an Assessment with an `x-proctoring.provider` config and no concrete provider bundled in the app
- **WHEN** the config resolves the provider through `ProctoringProviderInterface` and a proctoring flag is raised
- **THEN** the configured adapter handles the session and the flag never auto-alters a result (EU AI Act Art. 14 human oversight)

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

## Standards

IMS QTI 3.0 (canonical), QTI 2.x + Common Cartridge (import), LTI 1.3 (external tool launch), Caliper (events), AICC/SCORM/cmi5 for content-embedded quizzes (via `course-management`); EU AI Act Reg. 2024/1689 Annex III §3 (proctoring = high-risk) → ADR-005 gate; ISO/IEC 23988 (computer-based assessment) for proctoring conduct.

## Data Model

All in OpenRegister. New: `Assessment`, `Item`, `ItemBank`, `AssessmentResult`, `ProctoringSession`. Touches: `GradeEntry` (`grading`), `AiFeature` (existing — proctoring AI registration). ADR-031 PHP exceptions: `ProctoringProviderInterface`, the auto-scoring handler, the QTI import service. See `docs/ARCHITECTURE.md`.

## Out of Scope

- AI item generation, AI essay scoring, adaptive testing (EU AI Act Annex III §3 — explicitly deferred; would each be an `AiFeature` registration).
- Building a QTI authoring editor as a separate product (we run + import; a basic in-app item editor is in scope, a full standalone editor is not).
- Hand-in assignments (the `assignments` spec).
- The actual proctoring vendor implementations (interface only here).
