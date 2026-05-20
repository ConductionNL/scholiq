## Why

Scholiq already holds courses, cohorts, sessions, and hand-in assignments, but has no place for **structured testing**: a vmbo `toets`, an HBO `tentamen`, a state `examen`, a certification exam, or a formative quiz. These differ from assignments in three fundamental ways: (1) they draw from a curated **item bank** of QTI 3.0 items rather than open-ended submissions; (2) scoring is formulaic — choice and text-entry items are auto-scored on submit, essay items require teacher marking; (3) high-stakes exams often require **proctoring**, which the EU AI Act (Reg. 2024/1689 Annex III §3) classifies as high-risk AI when any AI-assisted flag review is involved.

No open-source Dutch assessment platform exists today (Cito / DiatOets / IEP are all proprietary). IMS **QTI 3.0** is the lock-in escape hatch. This spec merges the `assessment-engine` and `proctoring` stubs: proctoring is *configuration on an Assessment*, not a standalone concern.

## What Changes

### New Schemas (5) — `lib/Settings/scholiq_register.json` (17 → 22)

- **ItemBank** (slug `item-bank`) — a reusable collection of Items grouped by subject. Properties: name, description, subject, itemIds (uuid[]), tenant_id. Lifecycle: draft → published → archived. Calculations: itemCount.
- **Item** (slug `item`) — a QTI 3.0 assessment item. Properties: itemBankId, title, interactionType (enum: choice | textEntry | extendedText | hotspot | order | match | gapMatch | inlineChoice), qtiBody (QTI 3.0 XML/JSON body), correctResponse (object|null — null for extendedText), maxScore, subjectTags, difficulty (0..1|null), tenant_id. Lifecycle: draft → published → retired. Calculations: needsManualScoring.
- **Assessment** (slug `assessment`) — a structured test. Properties: title, description, courseId / sessionId / cohortId (uuid|null), curriculumPlanComponentId, itemRefs ({ itemId, points }[]), scoringScheme (points | passMark | irt), passMark, timeLimitMinutes, maxAttempts (default 1), keepScore (best | last | average), availableFrom / availableUntil, proctoring (object|null — { provider, lockdownBrowser, recordWebcam, flagReviewMode: manual|ai-assisted }), gradeEntryComponentId, tenant_id. Lifecycle: draft → published (via AssessmentPublishGuard) → closed | archived. Calculations: itemCount, totalPoints, isProctored, isAvailable.
- **AssessmentResult** (slug `assessment-result`, **appendOnly: true**) — a learner's attempt. Properties: assessmentId, learnerId, attemptNumber, responses ({ itemId, response, autoScore, manualScore }[]), startedAt, submittedAt, proctoringSessionId, gradeEntryId (forward-ref), tenant_id. Lifecycle: in-progress → submitted (via AssessmentScoringHandler — auto-scores on submit) → graded (via AssessmentGradeGuard — blocks unless all manual-scoring items have manualScore).
- **ProctoringSession** (slug `proctoring-session`, **appendOnly: true**) — created when a proctored Assessment is started. Properties: assessmentResultId, learnerId, provider, providerSessionId, status, recordedArtefactRefs, flags ({ flagId, kind, occurredAt, severity, reviewDecision: pending|allowed|annulled, reviewedBy, reviewedAt }[]), tenant_id. Lifecycle: created → active → ended | error. Calculations: pendingFlagCount, hasAnnulledFlag. A flag NEVER auto-alters a result (EU AI Act Art. 14 human oversight).

### New PHP (7, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/AssessmentPublishGuard.php` — blocks publish unless itemRefs is non-empty AND (if proctoring.flagReviewMode === 'ai-assisted') an AiFeature with slug `assessment-ai-proctor-review` exists in `enabled` state (ADR-005 DPO gate).
- `lib/Lifecycle/AssessmentGradeGuard.php` — blocks grade transition until every item flagged needsManualScoring has a non-null manualScore.
- `lib/Lifecycle/AssessmentScoringHandler.php` — runs as a `requires:` guard on the submit transition; always allows but auto-scores responses as side-effect. Returns true always.
- `lib/Service/AssessmentScoringService.php` — public API for programmatic auto-scoring; delegates to AssessmentScoringHandler.
- `lib/Service/QtiImportService.php` — imports QTI 2.x / 3.0 packages and Common Cartridge ZIPs → creates Item objects. Full parser for choice + extendedText; other types import raw qtiBody.
- `lib/Proctoring/ProvidesProctoring.php` — pluggable interface (startSession, endSession, fetchFlags). No concrete provider ships.
- `lib/Controller/QtiImportController.php` — thin POST `/api/assessment/qti-import`; `@NoAdminRequired @NoCSRFRequired`; reads upload; calls QtiImportService; returns {itemCount, itemIds}.

### New Frontend

Manifest pages: ItemBanks / ItemBankDetail, Items / ItemDetail, Assessments / AssessmentDetail, AssessmentResults / AssessmentResultDetail (readOnly), ProctoringSessions / ProctoringSessionDetail (readOnly) + 4 custom pages: TakeAssessmentView, ItemAuthorView, ProctoringReviewQueue, ImportQtiModal. One nav `menu` entry: "Assessments". `validate-manifest` passes.

Vue components: `TakeAssessmentView.vue` (timed test-taking surface + submit → auto-scoring), `ItemAuthorView.vue` (choice + extendedText editor; others show import notice), `ProctoringReviewQueue.vue` (invigilator flag-review queue — never mutates AssessmentResult), `ImportQtiModal.vue` (ZIP upload → QtiImportController). All Options API + direct fetch; no custom Pinia store modules.

### i18n

`l10n/en.json` + `l10n/nl.json` — new keys for all assessment pages, the four custom views, proctoring notices, and flag review decisions.

## Capabilities

### New Capabilities

- `assessment`: ItemBank, Item, Assessment, AssessmentResult, ProctoringSession schemas with declarative lifecycle / relations / calculations; AssessmentPublishGuard (non-empty itemRefs + ADR-005 AI gate), AssessmentGradeGuard, AssessmentScoringHandler (auto-scoring on submit transition); pluggable ProvidesProctoring interface; QTI import pipeline; manifest pages + 4 custom Vue views; EU AI Act Art. 14 human-oversight enforcement on proctoring flags.
