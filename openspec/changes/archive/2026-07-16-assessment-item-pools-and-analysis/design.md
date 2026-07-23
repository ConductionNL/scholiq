# Design: assessment-item-pools-and-analysis

## Context

`Assessment.itemRefs` (`lib/Settings/scholiq_register.json:4697-4722`) is a static array today: every
learner sees the same items in the same order. `AssessmentResult` (`4938-5093`) is `appendOnly: true` and
already the append-only evidence record `FraudCase` links to for exam-board fraud/appeal dossiers
(`FraudCase.assessmentResultId`, `6204-6211`). Two things must both be added without breaking that
evidence-record guarantee: (1) item pools that draw and shuffle, and (2) statistics computed from the
resulting attempt data. Both halves need PHP, for different reasons — Part A because randomness cannot be
client-supplied, Part B because the arithmetic exceeds OpenRegister's declarative aggregation engine. This
document works out where each piece of logic runs and why.

## Goals / Non-Goals

**Goals**
- Let an `Assessment` draw `drawCount` items at random from an `ItemBank`, optionally filtered by
  `subjectTags`/`difficulty`, instead of (or in addition to shuffling) a fixed `itemRefs` list.
- Freeze, per `AssessmentResult`, the exact items/order/answer-option-order a specific attempt presented —
  for **every** attempt, fixed or drawn — so exam-board review/appeal always has a faithful reconstruction.
- Compute per-item p-value, item-total (item-rest) correlation, and distractor analysis, and per-assessment
  Cronbach's alpha, from real `AssessmentResult` data, with an explicit minimum-sample gate so a statistic
  computed from 3 respondents is never presented as if it were reliable.
- Surface a flag-and-review workflow so the exam board / toetscommissie (the `examboard` group, which
  already owns `FraudCase` adjudication) can see and act on a poorly-performing item.

**Non-Goals**
- Adaptive testing / computerised adaptive testing (CAT) — explicitly out of scope per the existing
  `assessment` spec's "Out of Scope" section (`openspec/specs/assessment/spec.md`, AI-adaptive testing line)
  and not requested here.
- IRT-based (2PL/3PL) item calibration — `Item.difficulty` remains the existing *manually declared* 0..1
  value; this change adds classical test theory (CTT) statistics computed from real data, it does not
  replace or auto-calibrate the declared IRT parameter. A future change could feed CTT p-value back into
  `Item.difficulty` as a suggestion; this change does not wire that.
- Cross-assessment item banking analytics (trend of an item's p-value across many different assessments
  over years). `ItemStatistics` is scoped to one `(Item, Assessment)` pair per the reasons in "Statistics
  scope" below; a historical rollup is a natural follow-up, not required here.
- A bespoke QTI parser/editor for answer-option identifiers. Where this design needs a per-item list of
  option identifiers (for `optionOrder` / distractor analysis), it relies on the identifiers already present
  in the item's canonical QTI 3.0 `qtiBody` (see "Answer-option shuffle" below) rather than introducing a
  parallel scholiq-owned choice-list schema.

## Part A — Item pools, randomisation, and appeal-grade persistence

### Data model

```
Assessment (existing, extended)
  itemSelectionMode: "fixed" | "random-draw"          default "fixed"
  itemPoolConfig: nullable {                            required when itemSelectionMode = random-draw
    itemBankId: uuid ($ref ItemBank)
    drawCount: integer, minimum 1
    subjectTags: string[]                                default [] — empty = no filter
    difficultyMin / difficultyMax: number 0..1, nullable
  }
  shuffleItemOrder: boolean                             default false — applies to EITHER selection mode
  shuffleAnswerOptions: boolean                          default false — applies to EITHER selection mode

Item (existing, extended)
  variantGroupId: nullable uuid
    Groups Items that are interchangeable variants of the same underlying question (same competency,
    different wording/numbers/distractors). A random draw MUST include at most one Item per
    variantGroupId in a single drawn set — the same anti-duplicate-question convention Moodle and
    Questionmark item banks use for variant families.

AssessmentResult (existing, extended)
  drawnItemRefs: [{ itemId: uuid, points: number, optionOrder: string[] | null }]
    Frozen once, at AssessmentResult creation, by AssessmentDrawResolver (never by the client).
    - itemSelectionMode = fixed, no shuffle:  mirrors Assessment.itemRefs 1:1 (same order, same points).
    - itemSelectionMode = fixed, shuffleItemOrder: same items, array order permuted.
    - itemSelectionMode = random-draw: the drawCount items actually drawn for this attempt, in
      presentation order; points = the drawn Item's maxScore (pool draws carry no per-assessment
      points override — only a fixed itemRefs entry can override points).
    - optionOrder: QTI simpleChoice `identifier` values in the order presented, when
      shuffleAnswerOptions is true and the item's interaction exposes discrete option identifiers;
      null otherwise (essay/textEntry items, or shuffle disabled).
    Array position IS presentation order — no separate order field.
```

**Why `drawnItemRefs` is populated for every attempt, not only `random-draw` ones.** The brief's evidence
requirement — an appeal/hearing must reconstruct exactly what the learner saw — does not stop being true
just because the item list happened to be fixed. If `shuffleItemOrder`/`shuffleAnswerOptions` is on, a
"fixed" assessment still presents a different concrete order per learner; only a persisted record answers
"what did this specific learner see" without relying on re-deriving it from an `Assessment`/`Item` state
that may have changed since (an `Item` can be edited, a `ItemBank` can be re-curated, `Assessment.itemRefs`
can be re-ordered after the fact). One field, one code path, one guarantee — rather than two evidence models
depending on `itemSelectionMode`.

### Draw and shuffle MUST be resolved server-side, never client-supplied

`TakeAssessmentView.createResult()` (`src/views/TakeAssessmentView.vue:439-467`) posts a client-built
`AssessmentResult` body straight to OR's generic object-create endpoint
(`/apps/openregister/api/objects/scholiq/AssessmentResult`) — there is no scholiq PHP controller in that
request path today (by design, per ADR-022: apps consume OR's object API directly rather than wrapping it in
a pass-through controller). That means the *only* points where scholiq PHP can run are OR's object
lifecycle events, exactly the seam six existing listeners already use in
`lib/AppInfo/Application.php` (`XapiCompletionHandler`, `GradeRollupHandler`, `ExemptionGrantHandler`,
`FraudCaseDecisionHandler`, `BsaProgressFlagHandler`, etc.).

**Rejected alternative: compute the draw in `TakeAssessmentView.vue` before the POST.** This is the
simplest-looking option and was rejected outright: a client-resolved draw is a client-controlled draw — the
exact trust violation `AssessmentScoringHandler`'s own docblock already calls out for `autoScore`
(`lib/Lifecycle/AssessmentScoringHandler.php:12-16`, "blocking the transition to prevent client-controlled
... values from persisting"). A learner's browser could report having drawn the easiest subset of the bank,
or an unshuffled option order, and there would be no server-side truth to contradict it.

**Rejected alternative: a new PHP controller + route for "create AssessmentResult".** This would violate
ADR-022's "no pass-through CRUD controller" rule for a capability OR's object API already serves, *and* it
is unnecessary — the `ObjectCreatedEvent` listener bridge pattern already used six times in this app is a
proven, minimal-diff seam for exactly this "mutate right after creation" shape.

**Chosen approach: `AssessmentDrawResolver`, an `ObjectCreatedEvent` listener (ADR-031 exception).**
Registered in `Application.php` alongside the existing `ObjectCreatedEvent`/`ObjectTransitionedEvent`
listeners, filtered to `schema === 'assessment-result'`. On fire:
1. Loads the parent `Assessment`.
2. If `itemSelectionMode = random-draw`: queries `Item` objects (`lifecycle: published`,
   `itemBankId = itemPoolConfig.itemBankId`, matching `subjectTags`/`difficultyMin`/`difficultyMax` when
   set), applies the variant-group exclusivity rule, and draws `drawCount` items using `random_int()`
   (cryptographically-strong PHP RNG — no seed persisted, see "Determinism" below).
   If `itemSelectionMode = fixed`: starts from `Assessment.itemRefs` unchanged.
3. Applies `shuffleItemOrder` (Fisher-Yates over the resolved item list) and, per item, resolves
   `optionOrder` when `shuffleAnswerOptions` is true (see "Answer-option shuffle" below).
4. Writes the result onto `drawnItemRefs` via `ObjectService::saveObject()` — a follow-up update to the
   same `AssessmentResult` row, the same "create, then listener-updates" shape `XapiCompletionHandler`,
   `GradeRollupHandler`, and `ExemptionGrantHandler` already use.
5. Fails closed: if the pool cannot supply `drawCount` matching items (should not happen — the extended
   `AssessmentPublishGuard`, below, already checked this at publish time — but the bank can shrink after
   publish if items are retired), the listener leaves `drawnItemRefs` empty and logs an error; the frontend
   surfaces "this assessment's item pool is misconfigured" rather than silently serving a short attempt.

**Frontend consequence — an explicit re-fetch, not an assumed synchronous body.** `createResult()`
currently reads `this.resultId` straight off the POST response body (`TakeAssessmentView.vue:464-466`).
Whether OR's event dispatch completes *before* that response is serialized is an implementation detail this
design does not assume either way. `createResult()` MUST re-fetch the created `AssessmentResult` (a GET by
id, the same pattern `loadAssessment()` already uses) before `loadItems()` reads `drawnItemRefs`, rather than
trusting the original POST's response body. This is a one-call addition to an already-async method, not a
new pattern.

### Determinism — persisted resolution, not a replayable seed

**Rejected alternative: persist only a random seed on `AssessmentResult`, replay it against the `ItemBank`
whenever the drawn set needs to be shown.** Rejected because it is not actually deterministic in the sense
that matters: the `ItemBank` this seed would replay against is mutable (items are added, edited, retired
after this attempt happened). Replaying the same seed against a *changed* bank produces a *different* drawn
set than what the learner actually saw — exactly the failure mode the appeal requirement exists to prevent.
**Chosen approach: resolve once, persist the concrete result, never recompute.** `drawnItemRefs` is written
once by `AssessmentDrawResolver` at creation and is never touched again by any later process — "determinism"
here means "the record is the ground truth," not "the process is replayable." This is the same posture
`AssessmentResult.responses[].autoScore` already takes: computed once at `submit`, never recomputed.

### Answer-option shuffle uses QTI 3.0's own `shuffle`/`fixed` vocabulary

IMS QTI 3.0 (the canonical stored form for `Item.qtiBody`, `openspec/specs/assessment/spec.md` "Items use
QTI 3.0 as canonical form") already defines a `shuffle` attribute on choice-bearing interactions
(`choiceInteraction`, `orderInteraction`, `matchInteraction`, …) and a `fixed` attribute on individual
`simpleChoice` options that must not move even when the interaction shuffles (e.g. "None of the above"
pinned last). `Assessment.shuffleAnswerOptions` is the Scholiq-level opt-in: when true,
`AssessmentDrawResolver` parses each drawn item's `qtiBody` for its interaction's choice identifiers,
permutes the non-`fixed` ones, and writes the resulting identifier order to `drawnItemRefs[].optionOrder`.
This does not introduce a parallel choice-list schema — it reads the identifiers QTI already declares. Items
whose interaction has no discrete choice identifiers (`extendedText`, `textEntry`) get `optionOrder: null`.
`TakeAssessmentView` renders each item's choices in `optionOrder` when present, falling back to `qtiBody`'s
declared order otherwise.

### `AssessmentPublishGuard` gains a second valid item source

Today `AssessmentPublishGuard` (`lib/Lifecycle/AssessmentPublishGuard.php`) blocks `publish` unless
`itemRefs` is non-empty — checked, verified at `lib/Lifecycle/AssessmentPublishGuard.php:6-7`'s docblock.
That rule assumed `itemRefs` was the only item source; it now MUST accept `itemPoolConfig` as an alternative:
`itemBankId` resolves to an existing `ItemBank`, and the bank has at least `drawCount` `published` `Item`s
matching the configured `subjectTags`/`difficulty` filters (and, if `variantGroupId` grouping would make
`drawCount` unreachable — e.g. `drawCount` exceeds the number of *distinct* variant groups available — that
also blocks publish with an explicit reason, not a silent short draw at attempt time).

## Part B — Item analysis / psychometrics

### Statistics scope: per `(Item, Assessment)`, not per `Item` alone

An item's difficulty and discrimination are a property of *how it performed for a specific cohort taking a
specific assessment*, not an absolute property of the item text — the same item can behave differently
across cohorts, terms, or when reused in a different assessment with different surrounding items. `ExamSoft`
and `Questionmark` both compute item analysis per exam administration for this reason. `ItemStatistics` is
therefore keyed `(itemId, assessmentId)`, computed from the `graded` `AssessmentResult`s for that
`assessmentId` only. (A cross-assessment historical rollup for a reused item is a natural follow-up and is
explicitly out of scope — see "Non-Goals.")

### Where the computation lives, precisely

OpenRegister's aggregation engine (verified against
`openregister/lib/Service/Aggregation/AggregationRunner.php:965-972` and the metric allow-list at line 1256)
supports exactly `count | sum | avg | min | max` over **one flat/top-level field per matching object row** —
the same shape `ItemBank.itemCount` already uses (`lib/Settings/scholiq_register.json:4453-4469`: count of
`Item` rows filtered by `itemBankId`). That is genuinely insufficient here, and not by a small margin:

| Statistic | Why it exceeds `x-openregister-aggregations` |
|---|---|
| p-value (proportion correct on one item) | The input isn't a top-level field on a row — it's one element of the `responses[]` **array nested inside** each `AssessmentResult` (`lib/Settings/scholiq_register.json:4975-5012`), one array per learner per attempt. Computing "average score on item X" requires grouping by array-element `itemId` across many parent rows — OR's aggregation engine has no group-by over nested array elements at all, flat-field `avg` cannot reach in. |
| item-total (item-rest) correlation | Even granting a per-item score vector, this needs a **second** vector — each respondent's total score *excluding* this item's own contribution (the standard "corrected" item-total correlation; using the raw uncorrected total inflates the correlation because the item is part of its own total, a well-known CTT pitfall) — then a Pearson correlation coefficient between the two vectors. There is no aggregation metric for "correlation," and no declarative way to exclude one array element from a sum computed over the same array. |
| distractor analysis | Requires splitting respondents into upper/lower scoring groups (this design uses the standard Kelley 27% split — top 27% and bottom 27% by total score) and, per answer option, counting selections within each group separately. Multi-dimensional group-then-count over nested array elements — not an aggregation metric. |
| Cronbach's alpha | `α = (k/(k-1)) × (1 − Σσᵢ² / σ_total²)` — the variance of *every item's* score vector, plus the variance of the total-score vector, across every respondent. A multi-item, multi-pass statistical formula; nothing resembling this exists in the aggregation metric set. |

None of these are "OR could do this with a cleverer aggregation config" — they require iterating
`AssessmentResult` rows in PHP, building per-item and per-respondent vectors, and running actual statistical
formulas. This is squarely `ItemAnalysisService`, a stateless PHP class (ADR-031's "domain rule engine /
calculation class" exception — the same class of exception `GradeFormulaEvaluator` is for weighted-average
grade formulas), not a schema declaration.

### Computation flow

`ItemAnalysisRecomputeHandler` — an `ObjectTransitionedEvent` listener, registered alongside
`GradeRollupHandler` (which already reacts to this exact event/schema/target-state combination for a
different purpose: `AssessmentResult.graded → concept GradeEntry`). Filtered to `schema === 'assessment-result'`
and `toState === 'graded'`:

1. Loads every `graded` `AssessmentResult` for the transitioned result's `assessmentId`.
2. For each `itemId` referenced across those results' `responses[]`: calls
   `ItemAnalysisService::computeItemStatistics()` (p-value, corrected item-total correlation, distractor
   breakdown when the item is choice-type with a non-null `correctResponse`), and upserts the
   `(itemId, assessmentId)` `ItemStatistics` row via `ObjectService::saveObject()` — no lifecycle, fully
   derived, "do not set manually," the same posture `FinalGrade` already documents
   (`lib/Settings/scholiq_register.json:5720-5725`).
3. Calls `ItemAnalysisService::computeReliability()` for the assessment as a whole and upserts
   `AssessmentReliability`.
4. For each `ItemStatistics` row that crosses a quality threshold (below) and has no existing `open`
   `ItemRevisionFlag` for the same `(itemId, reason)`, creates one.

### Minimum-N thresholds — a statistic below threshold is `null`, not a bad number

Every one of these statistics is meaningless (and actively misleading if displayed) below a minimum sample.
This design sets:

- **p-value / item-total correlation:** require `n ≥ 20` graded results referencing the item. Below that,
  `ItemStatistics.pValue`/`.itemTotalCorrelation` are `null` and `.insufficientData` is `true` — the item
  detail view and `ItemAnalysisView` render "not enough attempts yet (n=X of 20)" rather than a number.
- **Distractor analysis:** also gated at `n ≥ 20` — the Kelley 27% split at n=20 already only yields ~5-6
  respondents per group, the practical floor below which a per-option breakdown is noise, not signal.
- **Cronbach's alpha:** requires `n ≥ 30` graded results **and** `k ≥ 2` items in the assessment (alpha is
  undefined for a single-item test). Below `n=30`, `AssessmentReliability.cronbachAlpha` is `null` and
  `.insufficientData` is `true`.

These thresholds (20 / 20 / 30) are a deliberate, conservative default informed by standard classical-test-
theory practice (the n≥30 "stable estimate" convention this design uses for reliability is the same order of
magnitude ExamSoft and Questionmark both apply before surfacing item statistics as reportable). They are
**not** hard-coded as PHP constants with no escape hatch. **Verified constraint:** the register JSON's only
extension point outside an individual schema's own `properties`/`x-openregister-*` blocks is the fixed,
OR-owned top-level `x-openregister` register-metadata block (`type`/`app`/`openregister`/`description` —
confirmed by inspecting `lib/Settings/scholiq_register.json`'s top-level keys); there is no verified
mechanism for an app-defined, register-wide config block sitting outside a schema. So instead of inventing
one, this design reuses the already-verified, already-used-hundreds-of-times mechanism: a nullable
`Assessment.itemAnalysisConfig` property (`minSampleSize`, `reliabilityMinSampleSize`,
`tooDifficultyBelow`, `tooEasyAbove`, `lowDiscriminationBelow`, each with a JSON Schema `default` matching
the values above) — the same "field with a schema `default`, overridable per object" shape every other
configurable value in this register already uses (e.g. `Assessment.maxAttempts`, `keepScore`). This also has
a real advantage over a single fleet-wide constant: different assessment types (a low-stakes formative quiz
vs. a high-stakes tentamen) legitimately warrant different quality bars, and per-`Assessment` configuration
lets an institution set that without a global trade-off. `ItemAnalysisService` reads
`Assessment.itemAnalysisConfig` (falling back to the schema defaults when unset). The exact default numbers
are flagged as provisional — see `DEFERRED_QUESTIONS` below.

### Item-revision flag thresholds and the review workflow

`ItemRevisionFlag` (`appendOnly: true`, mirroring `AttendanceFlag`/`BsaProgressFlag`) is created when, for a
sample meeting the minimum-N gate above:
- `pValue < 0.20` (`reason: too-difficult`) or `pValue > 0.95` (`reason: too-easy`) — both extremes mean the
  item contributes little discriminating information;
- `itemTotalCorrelation < 0.10` (`reason: low-discrimination`), or `< 0` (`reason: negative-discrimination`
  — learners who scored well overall did *worse* on this item than learners who scored poorly overall, the
  strongest signal an item is broken or mis-keyed).

These are the same order-of-magnitude cut-points Questionmark/ExamSoft-style item analysis guidance uses;
like the sample thresholds above, they are register-config, not hard-coded, and flagged provisional.

**Recipients: the `examboard` group, not an invented "item author" concept.** `ItemBank`/`Item`
(`lib/Settings/scholiq_register.json:4373-4614`) have no owner/author field today, and this design does not
add one just to have a notification target — introducing object-level authorship metadata OR's `owner`
column doesn't already expose to the schema layer is unjustified scope for this change. Instead,
`ItemRevisionFlag`'s `x-openregister-notifications` recipients are `kind: groups, groups: ["examboard",
"admin"]` — the *same* group `FraudCase` decisions and multiple other exam-quality notifications already
use (`lib/Settings/scholiq_register.json:5707,5856,6051,6109,6427`), and the natural owner of "is this exam
item defective" per the WHW art. 7.13 toetscommissie/examencommissie authority `exam-board`'s own spec
already documents. Review itself is a plain manifest list+detail page with lifecycle-transition buttons —
`ItemRevisionFlag`'s `open → acknowledged → revised | dismissed` lifecycle — the same declarative surface
`AttendanceFlag`/`BsaProgressFlag` already use; no bespoke queue view is needed for that part.

**`ItemAnalysisView` (new custom view) is only for the numbers.** There is no existing declarative
chart/statistics-panel primitive in this register (`grep '"type": "chart"' lib/Settings/scholiq_register.json`
— zero hits) — this is the "no manifest expression exists" case the assessment spec's own frontend
requirement already carves out custom views for. `ItemAnalysisView` renders `ItemStatistics` (p-value,
item-total correlation, distractor bars) for one `Item` across the `Assessment`s it appears in, and
`AssessmentReliability` for one `Assessment`; it does not duplicate the flag review queue, which stays
declarative.

## RBAC

`ItemStatistics`, `AssessmentReliability`, and `ItemRevisionFlag` all carry `x-property-rbac` restricting
read to `admin`/`teacher`/`examboard` roles — mirroring `AssessmentResult`'s existing
`x-property-rbac` block (`lib/Settings/scholiq_register.json:5076-5091`). A learner MUST NOT see an item's
difficulty/discrimination statistics before or after taking an assessment — that data would let a learner
infer which items are "safe" to guess on, undermining the assessment's validity for everyone else.

## Consequences

### Positive
- Random item draw + shuffle closes a `must`-tier assessment-capability gap present in every cc=6 competitor.
- Every attempt (not just drawn ones) gets a tamper-evident, server-resolved snapshot of what was presented
  — directly strengthens the existing `FraudCase`/appeal evidence chain rather than adding a parallel one.
- Item statistics give the `examboard` group (WHW art. 7.13's actual accountable body) a data-driven signal
  it currently has zero access to, closing a real accreditation/quality-assurance gap (ExamSoft/Questionmark
  parity).

### Negative / accepted trade-offs
- `ItemAnalysisRecomputeHandler` recomputes every `ItemStatistics` row referenced by an assessment on *every*
  new `graded` result for that assessment — O(items × results) work per grading event. This mirrors the
  existing `GradeRollupHandler`/`FinalGrade` recompute-on-every-relevant-transition posture already accepted
  elsewhere in this codebase; for assessment cohort sizes this is not a scaling concern, but it is a real,
  accepted cost, not a free declarative recompute.
- The draw/shuffle listener adds one extra network round-trip's worth of server work (a follow-up
  `saveObject()` after the initial create) to attempt start, and the frontend must add an explicit re-fetch
  rather than trusting the create response body — a small, deliberate latency/complexity cost for the
  server-side-only trust guarantee.
