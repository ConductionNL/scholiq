# Tasks: assessment-item-pools-and-analysis

## 1. Schema — Part A (item pools / randomisation)

- [ ] 1.1 Add `itemSelectionMode` (`fixed` | `random-draw`, default `fixed`) and `itemPoolConfig` (nullable
  object: `itemBankId` $ref `ItemBank`, `drawCount` integer minimum 1, `subjectTags` string array default
  `[]`, `difficultyMin`/`difficultyMax` nullable number 0..1) to `Assessment` in
  `lib/Settings/scholiq_register.json` (`4697-4762` region). Purely additive.
  - **spec_ref**: `specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list`
  - **acceptance_criteria**: existing `Assessment` rows validate unchanged (`itemSelectionMode` defaults
    `fixed`, `itemPoolConfig` absent/null); `openspec validate` passes
- [ ] 1.2 Add `shuffleItemOrder` (boolean, default `false`) and `shuffleAnswerOptions` (boolean, default
  `false`) to `Assessment`, independent of `itemSelectionMode`.
  - **spec_ref**: `specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable`
- [ ] 1.3 Add `variantGroupId` (nullable, `format: uuid`) to `Item` (`4472-4613` region).
  - **spec_ref**: `specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list`
- [ ] 1.4 Add `drawnItemRefs` (array of `{itemId: uuid, points: number, optionOrder: string[] | null}`,
  default `[]`, description noting it is server-resolved and never client-set) to `AssessmentResult`
  (`4938-5059` region).
  - **spec_ref**: `specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented`
  - **acceptance_criteria**: field is additive; existing `AssessmentResult` rows leave it `[]`

## 2. Schema — Part B (item analysis / psychometrics)

- [ ] 2.1 Add `ItemStatistics` object to `lib/Settings/scholiq_register.json`: `itemId` ($ref `Item`),
  `assessmentId` ($ref `Assessment`), `sampleSize` (integer), `pValue` (nullable number 0..1),
  `itemTotalCorrelation` (nullable number -1..1), `distractorAnalysis` (nullable array of
  `{optionId, selectedByHighGroup, selectedByLowGroup}`), `insufficientData` (boolean), `computedAt`
  (date-time), `tenant_id`. No `x-openregister-lifecycle` — fully derived, "do not set manually"
  (`FinalGrade` precedent). `x-property-rbac` read restricted to `admin`/`teacher`/`examboard`.
  - **spec_ref**: `specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size`
  - **acceptance_criteria**: schema validates against the register's OpenAPI conventions; RBAC block present
- [ ] 2.2 Add `AssessmentReliability` object: `assessmentId` ($ref `Assessment`), `sampleSize` (integer),
  `itemCount` (integer), `cronbachAlpha` (nullable number), `insufficientData` (boolean), `computedAt`
  (date-time), `tenant_id`. No lifecycle. `x-property-rbac` read restricted to `admin`/`teacher`/`examboard`.
  - **spec_ref**: `specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size`
- [ ] 2.3 Add `ItemRevisionFlag` object (`appendOnly: true`): `itemId` ($ref `Item`), `itemStatisticsId`
  ($ref `ItemStatistics`), `reason` (`too-difficult`|`too-easy`|`low-discrimination`|
  `negative-discrimination`), `pValueAtFlag`/`itemTotalCorrelationAtFlag` (nullable numbers, snapshot),
  `flaggedAt` (date-time), `lifecycle` (`open → acknowledged → revised | dismissed`),
  `x-openregister-notifications.flagRaised` (`trigger: {type: created}`, `recipients: [{kind: groups,
  groups: ["examboard", "admin"]}]`, NL/EN subject), `tenant_id`.
  - **spec_ref**: `specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board`
  - **acceptance_criteria**: `appendOnly: true`; lifecycle transitions match; notification recipients
    present in `nl`/`en`
- [ ] 2.4 Add a nullable `Assessment.itemAnalysisConfig` object (`minSampleSize` default `20`,
  `reliabilityMinSampleSize` default `30`, `tooDifficultyBelow` default `0.20`, `tooEasyAbove` default
  `0.95`, `lowDiscriminationBelow` default `0.10`) that `ItemAnalysisService` reads per-`Assessment` (falling
  back to the schema defaults when unset) — a verified, already-used "field with a schema `default`" shape,
  not an invented register-level config extension (design.md "Minimum-N thresholds").
  - **spec_ref**: `specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size`

## 3. Backend — Part A

- [ ] 3.1 `lib/Listener/AssessmentDrawResolver.php` — `ObjectCreatedEvent` listener filtered to
  `schema === 'assessment-result'`: loads the parent `Assessment`; for `random-draw`, queries `published`
  `Item`s in the configured `ItemBank` matching `subjectTags`/`difficulty` filters, applies variant-group
  exclusivity, draws `drawCount` via `random_int()`; for `fixed`, starts from `itemRefs`; applies
  `shuffleItemOrder` (Fisher-Yates) and, per item, resolves `shuffleAnswerOptions`'s `optionOrder` from the
  item's `qtiBody` (respecting QTI `fixed` choices); writes `drawnItemRefs` via `ObjectService::saveObject()`;
  fails closed (logs, leaves `drawnItemRefs` empty) when the pool cannot supply `drawCount` distinct variant
  groups. SPDX docblock; ADR-031 exception justification in the class docblock (mirrors
  `AssessmentScoringHandler`'s docblock style).
  - **spec_ref**: `specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value`
- [ ] 3.2 Register `AssessmentDrawResolver` on `ObjectCreatedEvent` in `lib/AppInfo/Application.php`,
  alongside the existing `XapiCompletionHandler` registration, with the same explanatory comment style.
  - **spec_ref**: same as 3.1
- [ ] 3.3 Extend `lib/Lifecycle/AssessmentPublishGuard.php`: when `itemSelectionMode: random-draw`, accept
  publish if `itemPoolConfig.itemBankId` resolves and has ≥ `drawCount` matching `published` items across
  ≥ `drawCount` distinct `variantGroupId` groups (in addition to the existing non-empty-`itemRefs` check for
  `fixed`).
  - **spec_ref**: `specs/assessment/spec.md#requirement-publishing-an-assessment-requires-a-resolvable-item-source`

## 4. Backend — Part B

- [ ] 4.1 `lib/Service/ItemAnalysisService.php` — stateless computation: `computeItemStatistics(itemId,
  assessmentId)` (p-value, corrected item-rest Pearson correlation, Kelley-27%-split distractor analysis,
  gated by `itemStatisticsMinN`) and `computeReliability(assessmentId)` (Cronbach's alpha, gated by
  `reliabilityMinN` and `itemCount ≥ 2`). Reads thresholds from `x-openregister-assessment-analysis-config`
  (task 2.4). SPDX docblock; ADR-031 exception justification (mirrors `GradeFormulaEvaluator`'s docblock
  style, citing design.md's aggregation-engine-insufficiency table).
  - **spec_ref**: `specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size`
- [ ] 4.2 `lib/Listener/ItemAnalysisRecomputeHandler.php` — `ObjectTransitionedEvent` listener filtered to
  `schema === 'assessment-result'` and `toState === 'graded'`: loads all `graded` results for the
  assessment, calls `ItemAnalysisService` per referenced item and once for reliability, upserts
  `ItemStatistics`/`AssessmentReliability` via `ObjectService::saveObject()`, and creates an
  `ItemRevisionFlag` (deduplicated per `(itemId, reason)` while `open`) when a threshold is crossed.
  - **spec_ref**: `specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board`
- [ ] 4.3 Register `ItemAnalysisRecomputeHandler` on `ObjectTransitionedEvent` in
  `lib/AppInfo/Application.php`, alongside `GradeRollupHandler`'s registration on the same event class.
  - **spec_ref**: same as 4.2

## 5. Frontend

- [ ] 5.1 `src/views/TakeAssessmentView.vue`: `loadItems()` reads `AssessmentResult.drawnItemRefs` (once
  populated) instead of `Assessment.itemRefs` directly (`408-410`); render each choice item's options in
  `drawnItemRefs[].optionOrder` order when present.
  - **spec_ref**: `specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented`
- [ ] 5.2 `src/views/TakeAssessmentView.vue`: `createResult()` re-fetches the created `AssessmentResult` by
  id (GET) before `loadItems()` runs, rather than trusting the original POST response body for
  `drawnItemRefs` (design.md "Frontend consequence").
  - **spec_ref**: `specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value`
- [ ] 5.3 New `src/views/ItemAnalysisView.vue`: renders `ItemStatistics` (p-value, item-total correlation,
  distractor bars, or an "insufficient data (n=X of 20)" state) for an `Item` across the `Assessment`s it
  appears in, and `AssessmentReliability` for an `Assessment`. Linked from `ItemAuthorView.vue` and from the
  `Assessment` detail page. Staff-only (RBAC already enforced server-side; view also gates on role).
  - **spec_ref**: `specs/assessment/spec.md#requirement-item-and-assessment-statistics-are-read-restricted-to-staff-roles`
- [ ] 5.4 `src/manifest.json`: register `ItemAnalysisView` as a named custom view; add declarative
  list+detail pages for `ItemStatistics`, `AssessmentReliability`, and `ItemRevisionFlag` (mirroring the
  existing `attendance-flag`/`bsa-progress-flag` page shape, including lifecycle-transition buttons for
  `ItemRevisionFlag`); add `itemSelectionMode`/`itemPoolConfig`/`shuffleItemOrder`/`shuffleAnswerOptions`
  fields to the `Assessment` form.
  - **spec_ref**: `specs/assessment/spec.md#requirement-a-quality-threshold-breach-opens-an-itemrevisionflag-routed-to-the-exam-board`

## 6. Tests

- [ ] 6.1 `tests/Unit/Settings/AssessmentItemPoolsRegisterTest.php` — schema shape assertions for the
  `Assessment`/`Item`/`AssessmentResult` deltas and the three new objects (mirrors
  `SecureExamTestModeTest.php`'s style).
- [ ] 6.2 `tests/Unit/Listener/AssessmentDrawResolverTest.php` — draw count, subjectTags/difficulty
  filtering, variant-group exclusivity, Fisher-Yates shuffle, QTI `fixed`-choice-respecting option shuffle,
  fail-closed on an insufficient pool, ignores a client-supplied `drawnItemRefs`.
- [ ] 6.3 `tests/Integration/Lifecycle/AssessmentPublishGuardRandomDrawIntegrationTest.php` — publish
  succeeds/blocks per the extended item-source rule (mirrors
  `XapiCompletionHandlerIntegrationTest.php`'s integration-test shape).
- [ ] 6.4 `tests/Unit/Service/ItemAnalysisServiceTest.php` — p-value and corrected item-total correlation
  against hand-computed reference fixtures, distractor analysis (Kelley 27% split), Cronbach's alpha against
  a hand-computed reference fixture, minimum-N gating (`insufficientData` true/false at the boundary).
- [ ] 6.5 `tests/Unit/Listener/ItemAnalysisRecomputeHandlerTest.php` — recompute fires on `graded`
  transition, upserts (not duplicates) `ItemStatistics`/`AssessmentReliability`, creates a deduplicated
  `ItemRevisionFlag` when a threshold is crossed, does not mutate the flagged `Item`.
- [ ] 6.6 `tests/e2e/spec-coverage/assessment-item-pools-and-analysis.spec.ts` — Playwright: a learner takes
  a `shuffleItemOrder`-enabled fixed assessment and sees all configured items; an `examboard` user reviews
  and resolves an `ItemRevisionFlag` through the manifest-declared queue; a learner account is denied when
  requesting an `ItemStatistics` object (RBAC).

## 7. Docs

- [ ] 7.1 Update `openspec/specs/assessment/spec.md`'s "What"/"Data Model" prose (post-archive, per this
  repo's archive convention) to mention pooled random draw, shuffle, and the two new ADR-031 PHP exceptions
  (`AssessmentDrawResolver`, `ItemAnalysisService`/`ItemAnalysisRecomputeHandler`), alongside the existing
  `ProctoringProviderInterface`/auto-scoring/QTI-import exceptions already listed.
