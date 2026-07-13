## ADDED Requirements

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
