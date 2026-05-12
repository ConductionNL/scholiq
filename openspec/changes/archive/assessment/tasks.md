# Tasks — Assessment, ItemBank & Items, Proctoring (Phase 3)

> Scope: 5 new schemas (ItemBank, Item, Assessment, AssessmentResult, ProctoringSession), 7 PHP files (3 lifecycle guards/handlers + 2 services + 1 interface + 1 controller), manifest pages + 4 custom Vue views, l10n (en+nl).

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `ItemBank` schema per design §1.1 — name, description, subject, itemIds[]; lifecycle draft → published → archived; calculations itemCount; relations items. Required: name, tenant_id.
- [x] Add `Item` schema per design §1.2 — itemBankId, title, interactionType (8-value enum), qtiBody, correctResponse (object|null), maxScore, subjectTags[], difficulty (0..1|null); lifecycle draft → published → retired; calculations needsManualScoring; relations itemBank. Required: title, interactionType, maxScore, tenant_id.
- [x] Add `Assessment` schema per design §1.3 — course/session/cohort scope, curriculumPlanComponentId, itemRefs[] ({itemId,points}), scoringScheme, passMark, timeLimitMinutes, maxAttempts, keepScore, availableFrom/Until, proctoring (object|null), gradeEntryComponentId; lifecycle draft → published (via AssessmentPublishGuard) → closed | archived; relations course/session/cohort/items; calculations itemCount + totalPoints + isProctored + isAvailable. Required: title, tenant_id.
- [x] Add `AssessmentResult` schema per design §1.4 — assessmentId, learnerId, attemptNumber, responses[] ({itemId,response,autoScore,manualScore}), startedAt, submittedAt, proctoringSessionId, gradeEntryId; appendOnly: true at top level; lifecycle in-progress → submitted (via AssessmentScoringHandler) → graded (via AssessmentGradeGuard); relations assessment/learner. Required: assessmentId, learnerId, tenant_id.
- [x] Add `ProctoringSession` schema per design §1.5 — assessmentResultId, learnerId, provider, providerSessionId, status, recordedArtefactRefs[], flags[] ({flagId,kind,occurredAt,severity,reviewDecision,reviewedBy,reviewedAt}); appendOnly: true at top level; lifecycle created → active → ended | error; calculations pendingFlagCount + hasAnnulledFlag; relations assessmentResult. Required: assessmentResultId, learnerId, provider, tenant_id.
- [x] Validate JSON; no duplicate keys; schema count 17 → 22. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Lifecycle/AssessmentPublishGuard.php` — single `check(array &$transitionContext): bool`; returns false when itemRefs is empty; if proctoring.flagReviewMode = 'ai-assisted' additionally queries ObjectService for AiFeature slug `assessment-ai-proctor-review` in `enabled` state (ADR-005 DPO gate).
- [x] Create `lib/Lifecycle/AssessmentGradeGuard.php` — single `check(array &$transitionContext): bool`; fetches parent Assessment and each referenced Item; blocks grade transition until all manual-scoring items have non-null manualScore.
- [x] Create `lib/Lifecycle/AssessmentScoringHandler.php` — always-true `check(array &$transitionContext): bool`; auto-scores responses as side-effect on submit transition; exact-match for choice/textEntry/inlineChoice; proportional for order/match/gapMatch; array-intersect for hotspot; autoScore=null for extendedText and null correctResponse.
- [x] Create `lib/Service/AssessmentScoringService.php` — public API `autoScore(string $assessmentResultId): void`; delegates to AssessmentScoringHandler; saves result via ObjectService.
- [x] Create `lib/Service/QtiImportService.php` — `import(string $packagePath, string $itemBankId): array`; detects qti3/qti2/cc package type; full parser for choice + extendedText; other types import raw qtiBody; creates Item objects via ObjectService.
- [x] Create `lib/Proctoring/ProvidesProctoring.php` — pluggable interface (startSession, endSession, fetchFlags); no concrete provider ships.
- [x] Create `lib/Controller/QtiImportController.php` — thin POST `/api/assessment/qti-import`; `@NoAdminRequired @NoCSRFRequired`; reads upload via getUploadedFile; calls QtiImportService::import; returns {itemCount, itemIds}.
- [x] Register route in `appinfo/routes.php`.
- [x] No `Application.php` registration — guards resolved by OR's lifecycle engine via FQCN in schema `requires:`.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add ItemBanks / ItemBankDetail, Items / ItemDetail, Assessments / AssessmentDetail (index + detail) pages.
- [x] Add AssessmentResults / AssessmentResultDetail (index + detail, readOnly) pages.
- [x] Add ProctoringSessions / ProctoringSessionDetail (index + detail, readOnly) pages.
- [x] Add `TakeAssessmentView`, `ItemAuthorView`, `ProctoringReviewQueue`, `ImportQtiModal` custom pages.
- [x] Add an "Assessments" nav `menu` entry (order: 48).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). No string-array `config.tabs` on detail pages.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/TakeAssessmentView.vue` — timed test-taking surface; loads Assessment + Items; creates AssessmentResult (in-progress); countdown timer; dispatches submit transition (triggers AssessmentScoringHandler). Options API + direct fetch; no Pinia module.
- [x] Create `src/views/ItemAuthorView.vue` — QTI item editor for choice + extendedText; builds QTI 3.0 XML body; other types show import notice. Options API + direct fetch; no Pinia module.
- [x] Create `src/views/ProctoringReviewQueue.vue` — flag-review queue; allow/annul decisions written to ProctoringSession only (never AssessmentResult — EU AI Act Art. 14). Options API + direct fetch; no Pinia module.
- [x] Create `src/views/ImportQtiModal.vue` — ZIP upload → QtiImportController → display created item count. Options API + direct fetch; no Pinia module.
- [x] Register all four in `src/main.js` via `customComponents` on `CnAppRoot`.
- [x] `npm run lint` 0 errors; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages, the four custom views, proctoring notices, and flag review decisions.

## Phase 6: OpenSpec change documents

- [x] Create `openspec/changes/assessment/proposal.md`.
- [x] Create `openspec/changes/assessment/design.md`.
- [x] Create `openspec/changes/assessment/tasks.md`.
- [x] Create `openspec/changes/assessment/specs/assessment/spec.md`.

## Phase 7: Spec-validation gate

- [x] `node tests/validate-json-strict.js` PASS (no dup keys; no appendOnly nested in x-openregister).
- [x] `node tests/validate-register.js` PASS (schema shape, slug uniqueness, lifecycle requires → PHP class exists, clobber heuristic).
- [x] `node tests/validate-manifest.js` PASS (54 pages).
