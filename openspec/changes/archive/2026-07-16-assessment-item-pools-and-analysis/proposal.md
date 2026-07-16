---
kind: code
depends_on: []
---

## Why

**Part A — item pools have no randomisation, and nothing records what a learner actually saw.**

- **`Assessment.itemRefs` is a flat, static array.** `lib/Settings/scholiq_register.json:4697-4722` declares
  it as `{itemId, points}[]` with `default: []` — every learner assigned to an `Assessment` is served the
  *identical* ordered list of items. A full-file grep for `shuffle|random|draw|variant` against the
  `assessment` schemas returns zero hits. `Assessment.timeLimitMinutes` (`4740-4745`) and the attempt policy
  `maxAttempts`/`keepScore` (`4746-4762`) already exist and are explicitly out of scope for this change.
- **The frontend reads that static list directly.** `src/views/TakeAssessmentView.vue:408-410`
  (`loadItems()`): `const itemRefs = this.assessment?.itemRefs ?? []` — there is no pool, no filter, no
  per-attempt resolution step. `createResult()` (`439-467`) posts a client-built `AssessmentResult` straight
  to OR's generic object-create endpoint
  (`/apps/openregister/api/objects/scholiq/AssessmentResult`) — there is no PHP controller in this path at
  all (ADR-022-consuming, by design), which matters directly for where a server-side draw can be enforced
  (see design.md).
- **`ItemBank` and `Item` already carry the filtering vocabulary a draw would use, but nothing reads it for
  drawing.** `Item.subjectTags` (`4536-4544`) and `Item.difficulty` (`4545-4552`, IRT 0..1) exist purely as
  descriptive/searchable metadata today; `ItemBank.itemIds` (`4407-4417`) is the only mechanism that groups
  items, and it is consumed only by the `itemCount` aggregation (`4453-4469`), never by a selection
  algorithm.
- **`AssessmentResult` is the append-only evidence record, but it freezes nothing about item *presentation*.**
  `AssessmentResult` (`4938-5093`) is `appendOnly: true` and stores `responses[]` (`itemId`, `response`,
  `autoScore`, `manualScore`) as the attempt is answered — but the *set, order, and per-item answer-option
  order the learner was shown* is never captured; today it is always fully reconstructible only because
  `itemRefs` is static and un-shuffled. The moment any randomisation exists, that guarantee breaks unless the
  concrete resolved view is persisted on the result itself.
- **This gap is not hypothetical — the exam-board capability already depends on `AssessmentResult` being a
  faithful reconstruction of what a learner saw.** `FraudCase.assessmentResultId`
  (`lib/Settings/scholiq_register.json:6204-6211`) links a fraud/plagiarism allegation directly to the
  contested `AssessmentResult`; a decided `FraudCase` stamps a 42-day statutory appeal deadline
  (`openspec/specs/exam-board/spec.md:87-98`, the CBE 6-week appeal window). An appeal or exam-board hearing
  that reviews a randomised assessment needs to reconstruct the *exact* items, order, and option order the
  accused learner faced — a static `itemRefs` array cannot do that once a draw exists, and nothing in the
  current schema captures a per-attempt snapshot at all.

**Competitor grounding (cc=6):** Moodle (question banks + "Random question" + per-quiz "Shuffle within
questions"), LearnDash (question pools per quiz), LearnUpon, ATutor (item-bank randomised delivery), and
Questionmark and Sakai (both ship parameterised/randomised item delivery with recorded delivery state for
audit) all treat "draw N from a pool, shuffle presentation, freeze what was shown" as baseline assessment
functionality — Scholiq's `assessment` capability (`status: done`, `feature_tier: must`,
`openspec/specs/assessment/spec.md:1-9`) currently has none of it.

**Part B — zero item statistics exist; nothing tells a toetscommissie whether an item (or a whole exam) is
psychometrically sound.**

- **`Assessment.scoringScheme`** (`4723-4733`) supports `points`/`passMark`/`irt`, and `Item.difficulty`
  (`4545-4552`) is a *manually declared* IRT parameter — but nothing in the schema, and nothing in
  `lib/Lifecycle/` or `lib/Listener/`, ever *computes* a statistic from actual `AssessmentResult` data. A
  full-file case-insensitive grep across `lib/Settings/scholiq_register.json` and every file under
  `openspec/specs/` for `pvalue|p-value|discrimination|item-total|correlation|cronbach|reliability|
  psychometric|distractor` returns **zero hits**. The only existing statistical machinery in the codebase is
  `GradeFormulaEvaluator` (`openspec/specs/grading/spec.md:120-121`, `lib/Grading/`), which computes a
  learner's weighted grade average — not an item-level or assessment-level psychometric statistic.
- **Dutch exam quality assurance needs this.** `openspec/specs/exam-board/spec.md` already models
  `FraudCase`/`ExemptionCase` adjudication with a hard due-process trail (verdict, rationale, capped
  sanction, 42-day appeal window) precisely because WHW art. 7.13 gives the examencommissie/toetscommissie
  exclusive authority and accountability for exam quality — but that body currently has no data-driven way to
  see whether a specific exam item is *itself* defective (too hard, too easy, non-discriminating, or has a
  broken distractor) rather than the learner being at fault. ExamSoft ships item analysis explicitly for
  accreditation reporting; Questionmark markets "psychometric analysis" as a named capability. Scholiq's own
  `exam-board` capability is the natural consumer of a "this item is statistically broken" signal, but no
  such signal exists to consume.
- **OpenRegister's declarative aggregation engine cannot compute this — verified, not assumed.** OR's
  aggregation runner (`openregister/lib/Service/Aggregation/AggregationRunner.php:965-972,1256`) supports
  exactly `count`/`sum`/`avg`/`min`/`max` over a single flat/top-level field on matching *rows* (objects) —
  e.g. `ItemBank.itemCount` (`4453-4469`) counts `Item` rows filtered by `itemBankId`. Every response to
  every item lives *nested* inside `AssessmentResult.responses[]` (`4975-5012`), one array per learner
  per attempt — there is no per-item row to aggregate over, and OR's aggregation engine has no group-by over
  elements of a nested array. A p-value is already out of reach declaratively; item-total correlation
  (Pearson correlation between a per-item score vector and a per-respondent total-score vector) and
  Cronbach's alpha (a variance-ratio formula over every item's score vector for an assessment) are further
  still — these are not "OpenRegister could do this with more aggregation config," they are arithmetic
  OpenRegister's declarative engine has no primitive for at all (see design.md for exactly where the line
  is and what runs in PHP).

## What Changes

**Part A — item pools / randomisation** (`assessment` capability delta):
- `Assessment` gains `itemSelectionMode` (`fixed` | `random-draw`), `itemPoolConfig` (nullable —
  `itemBankId`, `drawCount`, optional `subjectTags`/`difficultyMin`/`difficultyMax` filters), and two
  independent booleans `shuffleItemOrder` / `shuffleAnswerOptions` that apply to *either* selection mode.
- `Item` gains `variantGroupId` (nullable) so a random draw can treat interchangeable variants of the same
  underlying question as mutually exclusive picks within one drawn set (Moodle/Questionmark precedent).
- `AssessmentResult` gains `drawnItemRefs` — the concrete, frozen `{itemId, points, optionOrder}[]` a
  specific attempt presented, populated server-side (never client-supplied) at attempt creation and never
  recomputed afterward, so exam-board review/appeal can reconstruct exactly what the learner saw regardless
  of later edits to the `Assessment` or `ItemBank`.
- `AssessmentPublishGuard` is extended: an `Assessment` may publish with either a non-empty `itemRefs`
  (existing rule) *or* a resolvable `itemPoolConfig` (bank exists, has ≥ `drawCount` matching published
  items) — today only the former is checked, and a `random-draw` assessment would currently be
  unpublishable.
- New PHP: `AssessmentDrawResolver` (event listener, ADR-031 exception — server-side randomness/shuffle
  cannot be client-supplied, mirroring the trust boundary `AssessmentScoringHandler` already defends for
  `autoScore`).

**Part B — item analysis / psychometrics** (`assessment` capability delta):
- New OpenRegister objects `ItemStatistics` (per `Item`+`Assessment`: sample size, p-value, item-total
  (item-rest) correlation, distractor breakdown) and `AssessmentReliability` (per `Assessment`: Cronbach's
  alpha), both fully-derived/read-only, mirroring the existing `FinalGrade` precedent
  (`lib/Settings/scholiq_register.json:5720-5725`, "no lifecycle — it is fully derived").
- New append-only `ItemRevisionFlag`, notifying the `examboard`/`admin` groups (the same groups
  `FraudCase`/`exam-board` already use) when an item's statistics cross a configurable minimum-sample
  quality threshold — an item-revision workflow, not an automatic removal.
- New PHP: `ItemAnalysisService` (stateless statistics computation — p-value, corrected item-total
  correlation, distractor analysis, Cronbach's alpha) and `ItemAnalysisRecomputeHandler` (event listener
  bridge, same `ObjectTransitionedEvent` class `GradeRollupHandler` already listens on, filtered to
  `AssessmentResult` reaching `graded`).
- New custom view `ItemAnalysisView` (declarative manifest pages can't render statistic charts/distractor
  tables; the `ItemRevisionFlag` review queue itself stays a plain manifest list+detail, matching the
  existing `AttendanceFlag`/`BsaProgressFlag` precedent — no new custom view needed there).

## Impact

- **Affected specs:** `assessment` (delta only — this change does not touch `grading`, `exam-board`, or any
  other capability's spec; it adds two new schema-linked fields for `exam-board`'s `FraudCase` to *read*
  from, but does not modify the `exam-board` spec itself).
- **Affected code:** `lib/Settings/scholiq_register.json` (Assessment, Item, AssessmentResult deltas; new
  ItemStatistics, AssessmentReliability, ItemRevisionFlag objects), `lib/Listener/` (2 new classes),
  `lib/Service/` (1 new class), `lib/Lifecycle/AssessmentPublishGuard.php` (extended, not replaced),
  `src/views/TakeAssessmentView.vue` (read `drawnItemRefs` instead of `Assessment.itemRefs` directly),
  `src/views/ItemAuthorView.vue` (link to the new `ItemAnalysisView`), `src/manifest.json` (new pages for
  `ItemStatistics`/`AssessmentReliability`/`ItemRevisionFlag`, new `ItemAnalysisView` custom-view
  registration).
- **No new dependency on any other wave-2 change in this repo.**
