# Tasks — Assessment, ItemBank & Items, Proctoring

> Scope: 5 new schemas (ItemBank, Item, Assessment, AssessmentResult, ProctoringSession), 7 PHP files (3 lifecycle guards/handlers + 2 services + 1 interface + 1 controller), manifest pages + 4 custom Vue views, seed data, l10n (en+nl).

---

## Phase 0: Deduplication Check

- [ ] Search `openspec/specs/` and `openregister/lib/Service/` for overlap with ObjectService, RegisterService, SchemaService, ImportService — confirm no existing QTI parser, assessment lifecycle handler, or proctoring interface exists. Document: "no overlap found — QtiImportService, AssessmentScoringHandler, and ProvidesProctoring are net-new with no OR equivalent."
- [ ] Verify `lib/Settings/scholiq_register.json` current schema count (expect 17) to confirm 17 → 22 delta is correct.

---

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `ItemBank` schema per design §1.1 — name, description, subject, itemIds[]; lifecycle draft → published → archived; calculations itemCount (count of itemIds); relations items (resolves itemIds to Item). Required: name, tenant_id.
- [ ] Add `Item` schema per design §1.2 — itemBankId, title, interactionType (8-value enum: choice | textEntry | extendedText | hotspot | order | match | gapMatch | inlineChoice), qtiBody, correctResponse (object|null), maxScore, subjectTags[], difficulty (0..1|null); lifecycle draft → published → retired; calculations needsManualScoring (true when interactionType=extendedText OR correctResponse=null); relations itemBank. Required: title, interactionType, maxScore, tenant_id.
- [ ] Add `Assessment` schema per design §1.3 — course/session/cohort scope (uuid|null each), curriculumPlanComponentId, itemRefs[] ({itemId,points}), scoringScheme (points|passMark|irt), passMark (null unless passMark scheme), timeLimitMinutes (null=untimed), maxAttempts (default 1), keepScore (best|last|average), availableFrom/Until, proctoring (object|null — {provider, lockdownBrowser, recordWebcam, flagReviewMode: manual|ai-assisted}), gradeEntryComponentId; lifecycle draft → published (requires AssessmentPublishGuard) → closed | archived; calculations itemCount + totalPoints + isProctored + isAvailable; relations course/session/cohort/items. Required: title, tenant_id.
- [ ] Add `AssessmentResult` schema per design §1.4 — assessmentId, learnerId, attemptNumber (1-based), responses[] ({itemId,response,autoScore,manualScore}), startedAt, submittedAt, proctoringSessionId, gradeEntryId; `appendOnly: true` at schema top level; lifecycle in-progress → submitted (requires AssessmentScoringHandler) → graded (requires AssessmentGradeGuard); relations assessment. Required: assessmentId, learnerId, tenant_id.
- [ ] Add `ProctoringSession` schema per design §1.5 — assessmentResultId, learnerId, provider, providerSessionId, status (enum: created|active|ended|error), recordedArtefactRefs[], flags[] ({flagId,kind,occurredAt,severity,reviewDecision: pending|allowed|annulled,reviewedBy,reviewedAt}); `appendOnly: true` at schema top level; lifecycle created → active → ended | error; calculations pendingFlagCount (count flags where reviewDecision=pending) + hasAnnulledFlag (any flag with reviewDecision=annulled); relations assessmentResult. Required: assessmentResultId, learnerId, provider, tenant_id.
- [ ] Validate JSON: no duplicate keys; no appendOnly nested inside x-openregister (must be at schema root); schema count 17 → 22. Run `node tests/validate-json-strict.js` + `node tests/validate-register.js`.

---

## Phase 2: Seed data in `lib/Settings/scholiq_register.json`

- [ ] Add 3 ItemBank seed objects per design §5 — slug, name, subject, itemIds=[], lifecycle; Dutch values; idempotent by slug.
- [ ] Add 3 Item seed objects per design §5 — one choice item, one extendedText item, one additional choice item; include realistic Dutch qtiBody, correctResponse (null for extendedText), maxScore, subjectTags; lifecycle published.
- [ ] Add 3 Assessment seed objects per design §5 — wiskunde toets (timeLimitMinutes=90, passMark=5.5), Engels examen (timeLimitMinutes=120), formatieve quiz (maxAttempts=3, keepScore=best, timeLimitMinutes=15); itemRefs=[] (empty — seeds don't reference item UUIDs); lifecycle draft.
- [ ] Add 2 AssessmentResult seed objects per design §5 — one submitted with autoScore, one submitted with null autoScore (essay); appendOnly schema means no PUT on these seeds after creation.
- [ ] Add 1 ProctoringSession seed object per design §5 — provider=surf-proctoring; one flag with reviewDecision=allowed; lifecycle=ended.
- [ ] Re-run `node tests/validate-register.js` — PASS with seed objects included.

---

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/AssessmentPublishGuard.php` — single `check(array &$transitionContext): bool`; (1) returns false when itemRefs is empty, sets `$transitionContext['errors']['itemRefs'] = 'Assessment must have at least one item'`; (2) if `proctoring.flagReviewMode === 'ai-assisted'`, queries `ObjectService::findAll(['register'=>'scholiq','schema'=>'AiFeature','filters'=>['slug'=>'assessment-ai-proctor-review','lifecycle'=>'enabled'],'limit'=>1])` — returns false when not found, sets `$transitionContext['errors']['aiFeature'] = 'AI-assisted proctoring requires an enabled AiFeature (ADR-005 DPO gate)'`; otherwise returns true.
- [ ] Create `lib/Lifecycle/AssessmentGradeGuard.php` — single `check(array &$transitionContext): bool`; fetches parent Assessment to get itemRefs; fetches each referenced Item; for each response entry where the Item's needsManualScoring is true, checks manualScore is non-null; returns false (with per-item error) if any manual item lacks a manualScore.
- [ ] Create `lib/Lifecycle/AssessmentScoringHandler.php` — always-true `check(array &$transitionContext): bool`; as side-effect writes `autoScore` into each responses entry: exact-match for choice/textEntry/inlineChoice; array-intersect for hotspot; proportional partial for order/match/gapMatch (correct positions / total positions × maxScore); `autoScore = null` for extendedText and items where correctResponse is null. Sets `submittedAt = now()` on the AssessmentResult. Calls `ObjectService::saveObject()` to persist scored responses before returning true.
- [ ] Create `lib/Service/AssessmentScoringService.php` — public API `autoScore(string $assessmentResultId): void`; fetches AssessmentResult; wraps in transitionContext; delegates to `AssessmentScoringHandler::check()`; calls `ObjectService::saveObject()` to persist. ADR-031 exception: calculation engine above schema metadata.
- [ ] Create `lib/Service/QtiImportService.php` — `import(string $packagePath, string $itemBankId): array` returns array of created Item UUIDs. Extracts ZIP to temp dir; reads `imsmanifest.xml` for package type (qti3/qti2/cc/unknown); parses item XML; full parser for choice (correct response + maxScore from `<outcomeDeclaration>`) and extendedText (maxScore only, correctResponse=null); other types import with raw qtiBody, correctResponse=null; creates Item objects via `ObjectService::saveObject()`. Cleans up temp dir in finally block.
- [ ] Create `lib/Proctoring/ProvidesProctoring.php` — interface with three methods: `startSession(string $assessmentResultId, array $config): string` (returns providerSessionId), `endSession(string $providerSessionId): void`, `fetchFlags(string $providerSessionId): array`. No concrete provider ships with this change.
- [ ] Create `lib/Controller/QtiImportController.php` — thin controller; `@NoAdminRequired @NoCSRFRequired`; single POST `/api/assessment/qti-import`; reads upload via `$this->request->getUploadedFile('file')` and `$this->request->getParam('itemBankId')`; writes file to temp path; calls `QtiImportService::import()`; returns JSON `{itemCount: n, itemIds: [...]}` on success; returns HTTP 422 on parse error.
- [ ] Register route in `appinfo/routes.php`: `['name' => 'qti_import#import', 'url' => '/api/assessment/qti-import', 'verb' => 'POST']`.
- [ ] Confirm no `registerEventListener` in `Application.php` — guards resolved by OR lifecycle engine via FQCN in schema `requires:`.
- [ ] Run `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

---

## Phase 4: Manifest pages in `src/manifest.json`

- [ ] Add ItemBanks / ItemBankDetail (index + detail, register=scholiq schema=ItemBank).
- [ ] Add Items / ItemDetail (index + detail, register=scholiq schema=Item).
- [ ] Add Assessments / AssessmentDetail (index + detail, register=scholiq schema=Assessment).
- [ ] Add AssessmentResults / AssessmentResultDetail (index + detail, readOnly=true, register=scholiq schema=AssessmentResult).
- [ ] Add ProctoringSessions / ProctoringSessionDetail (index + detail, readOnly=true, register=scholiq schema=ProctoringSession).
- [ ] Add custom pages: TakeAssessmentView (route /assessments/:id/take), ItemAuthorView (route /items/:id/author), ProctoringReviewQueue (route /proctoring/review-queue), ImportQtiModal (route /items/import-qti).
- [ ] Add "Assessments" nav menu entry (icon: ClipboardTextOutline, order: 48).
- [ ] Run `node tests/validate-manifest.js` PASS (0 Ajv errors). Confirm detail pages do NOT carry a string-array `config.tabs`.

---

## Phase 5: Frontend Vue components

- [ ] Create `src/views/TakeAssessmentView.vue` (Options API + direct fetch; no Pinia module):
  - Loads Assessment + Items via OR REST on mount.
  - Creates AssessmentResult (`lifecycle: in-progress`) via OR REST POST on mount.
  - Presents items one at a time with a countdown timer (timeLimitMinutes → seconds).
  - On timer expiry: auto-dispatches the `submit` lifecycle transition.
  - On manual submit: dispatches `submit` transition; shows per-item autoScore for auto-scored items; shows "wacht op nakijk" for extendedText.
  - If Assessment.isProctored: shows a proctoring notice (placeholder — no concrete adapter call).
  - EVERY `await` MUST be wrapped in `try/catch` with `NcDialog` error feedback (no `window.alert()`).
- [ ] Create `src/views/ItemAuthorView.vue` (Options API + direct fetch; no Pinia module):
  - Loads existing Item (or creates new) via OR REST.
  - Full editor for `choice`: radio option list, mark-correct toggle, maxScore field.
  - Full editor for `extendedText`: essay prompt textarea, maxScore field only (correctResponse=null).
  - All other interaction types: import notice ("Gebruik ImportQtiModal om dit itemtype toe te voegen").
  - Builds a QTI 3.0 XML body string from form state.
  - Saves via OR REST POST (new) or PATCH (existing).
- [ ] Create `src/views/ProctoringReviewQueue.vue` (Options API + direct fetch; no Pinia module):
  - Fetches all ProctoringSession objects; filters to those with pendingFlagCount > 0.
  - Per flag: renders flag kind, occurredAt, severity.
  - "Toestaan" button: PATCHes ProctoringSession flag.reviewDecision=allowed + reviewedBy + reviewedAt. NEVER touches AssessmentResult.
  - "Annuleren" button: PATCHes ProctoringSession flag.reviewDecision=annulled + reviewedBy + reviewedAt. NEVER touches AssessmentResult.
  - Displays `<CnAiTransparencyBanner>` if the linked Assessment has flagReviewMode=ai-assisted (future gated feature — render banner proactively for ui completeness).
- [ ] Create `src/views/ImportQtiModal.vue` (Options API + direct fetch; no Pinia module):
  - Loads available ItemBanks from OR (lifecycle=published).
  - `<NcSelect inputLabel="Itembank">` picker (NOT a manual `<label>` element — ADR-004 NcSelect rule).
  - File picker for `.zip`.
  - POSTs multipart form to `/api/assessment/qti-import`.
  - Displays `{itemCount}` items aangemaakt on success; lists itemIds.
  - MUST live in `src/modals/ImportQtiModal.vue` if NcModal-based, or `src/dialogs/` if NcDialog-based — not inline in parent (ADR-004 modal isolation).
- [ ] Register all four components in `src/main.js` via `customComponents` on `CnAppRoot`.
- [ ] Confirm: no component uses `window.confirm()` or `window.alert()`.
- [ ] Confirm: no component reads state from DOM attributes (`document.getElementById`, `dataset`).
- [ ] Run `npm run lint` 0 errors; `npm run build` succeeds.

---

## Phase 6: i18n

- [ ] Add new keys to `l10n/en.json` for: all assessment page titles and action labels, TakeAssessmentView timer/submit labels, ItemAuthorView editor labels, ProctoringReviewQueue "Toestaan"/"Annuleren" labels and proctoring notices, ImportQtiModal upload labels.
- [ ] Add Dutch translations to `l10n/nl.json` for all keys added in `l10n/en.json`.
- [ ] Confirm all user-visible strings in the four Vue components use `t(appName, 'key')` — no hardcoded strings.

---

## Phase 7: Spec-validation gate

- [ ] `node tests/validate-json-strict.js` PASS — no duplicate keys; no appendOnly nested inside x-openregister block.
- [ ] `node tests/validate-register.js` PASS — schema shape valid; slug uniqueness (17→22); lifecycle requires → PHP class exists; clobber heuristic clean.
- [ ] `node tests/validate-manifest.js` PASS — all new pages declared; custom pages registered.
- [ ] `./vendor/bin/phpcs lib/` PASS — coding standards.
- [ ] `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS — 0 errors.

---

## Phase 8: OpenSpec change documents

- [ ] Verify `openspec/changes/assessment/proposal.md` exists and matches actual implementation scope.
- [ ] Verify `openspec/changes/assessment/design.md` exists with seed data section (§5) and reuse analysis (§6).
- [ ] Verify `openspec/changes/assessment/specs/assessment/spec.md` exists with REQ-ASS-001 through REQ-ASS-008 and GIVEN/WHEN/THEN scenarios.
- [ ] Verify `openspec/changes/assessment/tasks.md` exists (this file).
