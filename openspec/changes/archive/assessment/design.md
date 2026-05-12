# Design — Assessment, ItemBank & Items, Proctoring

## 1. Schemas

### 1.1 ItemBank (slug `item-bank`)

A reusable collection of Items grouped by subject.

| field | type | notes |
|---|---|---|
| name | string | required |
| description | string\|null | |
| subject | string\|null | e.g. "wiskunde A", "Engels" |
| itemIds | uuid[] | Items that belong to this bank |
| tenant_id | string | required |
| lifecycle | string | draft → published → archived |

`x-openregister-calculations`: `itemCount` (len of itemIds).
`x-openregister-relations`: items (resolves itemIds to Item objects).

### 1.2 Item (slug `item`)

A single QTI 3.0 assessment item. Stored in QTI 3.0 XML/JSON body; imported from QTI 2.x / Common Cartridge by `QtiImportService` which converts on import.

| field | type | notes |
|---|---|---|
| itemBankId | uuid\|null | parent ItemBank |
| title | string | required |
| interactionType | enum | `choice`, `textEntry`, `extendedText`, `hotspot`, `order`, `match`, `gapMatch`, `inlineChoice` |
| qtiBody | string | QTI 3.0 XML or JSON body |
| correctResponse | object\|null | structured correct answer; null for extendedText / manually scored |
| maxScore | number | declared max score for this item |
| subjectTags | string[] | free-text subject tags |
| difficulty | number\|null | 0..1 IRT difficulty parameter; null if unset |
| tenant_id | string | required |
| lifecycle | string | draft → published → retired |

`x-openregister-calculations`: `needsManualScoring` (true when interactionType is `extendedText` or `correctResponse` is null).
`x-openregister-relations`: itemBank (resolves itemBankId to ItemBank).

### 1.3 Assessment (slug `assessment`)

A structured test. Scope is a Course, Session, or Cohort (all optional). Proctoring is configuration on the Assessment rather than a standalone object.

| field | type | notes |
|---|---|---|
| title | string | required |
| description | string\|null | |
| courseId | uuid\|null | scope — at most one of course/session/cohort |
| sessionId | uuid\|null | |
| cohortId | uuid\|null | |
| curriculumPlanComponentId | uuid\|null | which CurriculumPlan component this scores |
| itemRefs | array | `{ itemId: uuid, points: number }[]` — items in order |
| scoringScheme | enum | `points`, `passMark`, `irt` |
| passMark | number\|null | required when scoringScheme is `passMark` |
| timeLimitMinutes | number\|null | wall-clock limit; null = untimed |
| maxAttempts | integer | default 1 |
| keepScore | enum | `best`, `last`, `average` |
| availableFrom | datetime\|null | learner availability window |
| availableUntil | datetime\|null | |
| proctoring | object\|null | `{ provider: string, lockdownBrowser: bool, recordWebcam: bool, flagReviewMode: "manual"\|"ai-assisted" }`; null = unproctored |
| gradeEntryComponentId | uuid\|null | forward-ref to grading spec |
| tenant_id | string | required |
| lifecycle | string | draft → published (via AssessmentPublishGuard) → closed \| archived |

`x-openregister-lifecycle.transitions.publish.requires`: `OCA\Scholiq\Lifecycle\AssessmentPublishGuard`.
`x-openregister-relations`: course, session, cohort, items (resolves itemRefs[].itemId).
`x-openregister-calculations`: `itemCount`, `totalPoints` (sum of itemRefs[].points), `isProctored` (proctoring !== null), `isAvailable` (now inside availableFrom..availableUntil window).

### 1.4 AssessmentResult (slug `assessment-result`, appendOnly)

One learner attempt. Append-only — no in-place editing; a new object is created per attempt.

| field | type | notes |
|---|---|---|
| assessmentId | uuid | required |
| learnerId | string | NC user ID |
| attemptNumber | integer | 1-based |
| responses | array | `{ itemId: uuid, response: any, autoScore: number\|null, manualScore: number\|null }[]` |
| startedAt | datetime | |
| submittedAt | datetime\|null | set on `submit` transition |
| proctoringSessionId | uuid\|null | forward-ref to ProctoringSession |
| gradeEntryId | uuid\|null | forward-ref to grading spec |
| tenant_id | string | required |
| lifecycle | string | in-progress → submitted (via AssessmentScoringHandler) → graded (via AssessmentGradeGuard) |

`appendOnly: true` — declared at schema top level, consistent with other append-only schemas.
`x-openregister-lifecycle.transitions.submit.requires`: `OCA\Scholiq\Lifecycle\AssessmentScoringHandler`.
`x-openregister-lifecycle.transitions.grade.requires`: `OCA\Scholiq\Lifecycle\AssessmentGradeGuard`.
`x-openregister-relations`: assessment, learner.

### 1.5 ProctoringSession (slug `proctoring-session`, appendOnly)

Created by `TakeAssessmentView` when a proctored Assessment is started. Records artefacts and flags; flag review decisions are written by `ProctoringReviewQueue`. Append-only.

| field | type | notes |
|---|---|---|
| assessmentResultId | uuid | required |
| learnerId | string | NC user ID |
| provider | string | adapter identifier matching Assessment.proctoring.provider |
| providerSessionId | string\|null | external provider reference |
| status | enum | `created`, `active`, `ended`, `error` |
| recordedArtefactRefs | string[] | OR file-attachment references |
| flags | array | `{ flagId: uuid, kind: string, occurredAt: datetime, severity: string, reviewDecision: "pending"\|"allowed"\|"annulled", reviewedBy: string\|null, reviewedAt: datetime\|null }[]` |
| tenant_id | string | required |
| lifecycle | string | created → active → ended \| error |

`appendOnly: true`.
`x-openregister-calculations`: `pendingFlagCount` (flags where reviewDecision = `pending`), `hasAnnulledFlag` (any flag reviewDecision = `annulled`).
`x-openregister-relations`: assessmentResult.

**EU AI Act Art. 14 invariant**: A flag — even one bearing reviewDecision `annulled` — NEVER automatically alters any AssessmentResult field. The `ProctoringReviewQueue` writes only to ProctoringSession. Any consequence (e.g. annulling the attempt) is a human decision made outside this system.

## 2. PHP — ADR-031 legitimate exceptions

- **`AssessmentPublishGuard`** — `check(array &$transitionContext): bool`. Returns false when `itemRefs` is empty. If `proctoring.flagReviewMode === 'ai-assisted'`, additionally queries `ObjectService::findAll(['register'=>'scholiq','schema'=>'AiFeature','filters'=>['slug'=>'assessment-ai-proctor-review','status'=>'enabled'],'limit'=>1])` and returns false when no record is found (ADR-005 DPO gate). Both checks have distinct error-message entries in `$transitionContext['errors']`.

- **`AssessmentGradeGuard`** — `check(array &$transitionContext): bool`. Fetches the parent Assessment, fetches each referenced Item, and returns false if any item with `needsManualScoring === true` (i.e. interactionType `extendedText` or `correctResponse` null) has a null `manualScore` in the matching response entry.

- **`AssessmentScoringHandler`** — `check(array &$transitionContext): bool`. Declared as a `requires:` guard on the `submit` transition so that the OR lifecycle engine calls it before persisting the state change. Always returns true (never gates). As a side-effect it writes `autoScore` into each `responses` entry: exact-match scoring for `choice`, `textEntry`, `inlineChoice`; proportional partial scoring for `order`, `match`, `gapMatch`; array-intersect scoring for `hotspot`; `autoScore = null` for `extendedText` and items where `correctResponse` is null.

- **`AssessmentScoringService`** — public API for programmatic auto-scoring outside the lifecycle engine. `autoScore(string $assessmentResultId): void` fetches the AssessmentResult, wraps it in a transitionContext, delegates to `AssessmentScoringHandler::check()`, then calls `ObjectService::saveObject()` to persist the scored responses.

- **`QtiImportService`** — `import(string $packagePath, string $itemBankId): array` returns the UUIDs of created Items. Extracts the ZIP to a temporary directory, reads `imsmanifest.xml` to detect package type (qti3 / qti2 / cc / unknown), parses item XML files, and creates `Item` objects via `ObjectService::saveObject()`. Full parser implemented for `choice` (correct response + `maxScore` from `<outcomeDeclaration>`) and `extendedText` (`maxScore` only; `correctResponse = null`); all other interaction types import with a raw `qtiBody` and no `correctResponse`. QTI 2.x is handled identically to QTI 3.0 at parse time since the relevant node names are compatible; Common Cartridge discovery walks the `imsmanifest.xml` resource list for `<resource type="imsqti_item_xmlv3p0">` or `<resource type="imsqti_item_xmlv2p2">` entries.

- **`ProvidesProctoring`** (interface) — `startSession(string $assessmentResultId, array $config): string`, `endSession(string $providerSessionId): void`, `fetchFlags(string $providerSessionId): array`. No concrete provider ships. `Assessment.proctoring.provider` holds the adapter identifier.

- **`QtiImportController`** — thin controller. Single POST `/api/assessment/qti-import`; `@NoAdminRequired @NoCSRFRequired`. Reads the uploaded file via `$this->request->getUploadedFile('file')`, writes it to a temp path, calls `QtiImportService::import()`, returns `{ itemCount: n, itemIds: [...] }`.

Guards are resolved by the OR lifecycle engine via DI from the FQCN in the schema `requires:` — no `registerEventListener` in `Application.php` needed.

## 3. Frontend

### 3.1 Manifest pages

`ItemBanks` (index), `ItemBankDetail` (detail), `Items` (index), `ItemDetail` (detail), `Assessments` (index), `AssessmentDetail` (detail), `AssessmentResults` (index, readOnly), `AssessmentResultDetail` (detail, readOnly), `ProctoringSessions` (index, readOnly), `ProctoringSessionDetail` (detail, readOnly). Custom pages: `TakeAssessmentView`, `ItemAuthorView`, `ProctoringReviewQueue`, `ImportQtiModal`. One nav `menu` entry: "Assessments". `validate-manifest` must pass — detail pages do not carry a string-array `config.tabs`.

### 3.2 TakeAssessmentView.vue

Timed test-taking surface. Loads the Assessment + its Items, creates an AssessmentResult (in-progress), optionally shows a proctoring notice (placeholder — no concrete adapter ships), presents items one at a time with a countdown timer, collects responses, and dispatches the `submit` transition (which runs `AssessmentScoringHandler`). On submit, shows per-item `autoScore` for auto-scored items. Options API; direct fetch; no custom Pinia module.

### 3.3 ItemAuthorView.vue

QTI item editor. Supports full authoring for `choice` (radio option list + mark correct) and `extendedText` (maxScore only). All other interaction types show an import notice ("Use ImportQtiModal to add this item type"). Builds a QTI 3.0 XML body string from the form state. Saves via POST (create) or PUT (update) to OR. Options API; direct fetch; no custom Pinia module.

### 3.4 ProctoringReviewQueue.vue

Invigilator flag-review queue. Fetches all ProctoringSession objects and filters to those with `pendingFlagCount > 0`. Per flag: "Allow" and "Annul" buttons record `reviewDecision`, `reviewedBy`, and `reviewedAt` via PUT to ProctoringSession. NEVER reads or writes AssessmentResult (EU AI Act Art. 14 human oversight). Options API; direct fetch; no custom Pinia module.

### 3.5 ImportQtiModal.vue

ZIP upload surface. Loads available ItemBanks from OR, lets the author select a target bank and choose a `.zip` file, and POSTs to `QtiImportController`. Displays the created item count on success. Options API; direct fetch; no custom Pinia module.

## 4. Out of scope

- Concrete proctoring adapters — only the `ProvidesProctoring` interface ships.
- IRT-based scoring — `scoringScheme: irt` is stored but the IRT calculation is deferred.
- Final-grade computation — the `grading` spec (`GradeEntry` → `FinalGrade`).
- Peer review of assessments — a follow-up spec.
- The full QTI 3.0 interaction-type editor — only `choice` and `extendedText` have native editors; others use the import path.
