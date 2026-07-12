# Grading — Exam Board Fraud/Exemption Delta

**Spec refs**: `grading`, `exam-board`, ADR-022 (apps consume OR abstractions), ADR-031 (declarative-first;
PHP only for legitimate exceptions)

## MODIFIED Requirements

### Requirement: Persist grading domain objects in OpenRegister
The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects. `GradeEntry` has
`x-openregister-lifecycle` (`concept → published → revised`, plus a new terminal `invalidated` state reachable
only via `invalidate` from `concept`, guarded by `FraudCaseInvalidationGuard`) and `x-openregister-notifications`
keyed so a re-publish/backfill doesn't double-notify. `GradeEntry.sourceKind` gains an `exemption` value and
two new nullable reference fields, `exemptionCaseId` and `fraudCaseId`, linking to the `exam-board` capability's
schemas; `value` is required for every `sourceKind` except `exemption`, where it MUST be `null`. `publish` and
`republish` gain `requires: [FraudCaseBlockGuard]`. `FinalGrade` is computed via `x-openregister-calculations` +
cross-schema aggregation over the learner's published `GradeEntry`s, parameterised by the
`CurriculumPlan.formula` + component weights, and now excludes `sourceKind: exemption` entries from the
weighted-average numeric sum while still counting their component as satisfied.

#### Scenario: Grading objects persisted in OpenRegister, exemption-aware
- **GIVEN** the grading domain schemas are registered with the exam-board delta applied
- **WHEN** a `GradeEntry` with `sourceKind: exemption` is published for a learner
- **THEN** it is stored with `value: null`, `exemptionCaseId` set, and is excluded from the `FinalGrade`
  weighted-average sum while still satisfying its component's completion requirement

#### Scenario: A linked FraudCase blocks publish and republish
- **GIVEN** a `GradeEntry` in `concept` (or `revised`) with `fraudCaseId` set to an open (`reported`,
  `hearing-scheduled`, or `heard`) `FraudCase`
- **WHEN** `publish` or `republish` is attempted
- **THEN** `FraudCaseBlockGuard` blocks the transition
- **AND** once the `FraudCase` is `decided` with `verdict: unfounded` or `dismissed`, the transition succeeds
  normally

#### Scenario: A permanently fraud-proven link blocks publish even after decision
- **GIVEN** a `GradeEntry` with `fraudCaseId` set to a `FraudCase` that is `decided` with `verdict:
  fraud-proven`
- **WHEN** `publish` or `republish` is attempted
- **THEN** `FraudCaseBlockGuard` blocks it permanently — the only path forward for that `GradeEntry` is
  `invalidate`, not `publish`

### Requirement: Roll-up is a declared calculation, not a TimedJob
The roll-up MUST NOT be a PHP TimedJob — it MUST be a declared calculation that re-fires on `GradeEntry`
publish (the `calculatedChange` trigger feature). The only PHP exception allowed: a stateless
`GradeFormulaEvaluator` invoked by the calculation engine if a formula can't be expressed in JSON-logic
(ADR-031 "calculation engine above schema metadata"). `GradeFormulaEvaluator` is extended, not replaced, to
treat `sourceKind: exemption` entries as follows: excluded from `weightedAverage()`'s `weightedSum`/
`totalWeight` accumulation (both overall and per-period) so a `null` value never corrupts the average; in
`evaluatePassed()`'s `all-must-pass` branch, a component whose best entry is `sourceKind: exemption` is
treated as satisfying that component's `passRules` threshold without a numeric comparison.
`FinalGrade.breakdown.components[componentId]` gains an `exempt: true` marker on any component satisfied
this way, so the roll-up UI can show why the component counts.

#### Scenario: Roll-up re-fires on publish without a TimedJob
- **GIVEN** a learner's `FinalGrade` is derived from a declared calculation
- **WHEN** a `GradeEntry` for that learner transitions to `published`
- **THEN** the `FinalGrade` recomputes via the `calculatedChange` trigger with no PHP TimedJob involved

#### Scenario: An exemption entry does not corrupt the weighted average
- **GIVEN** a `CurriculumPlan` with `formula: weighted-average` and two components, one satisfied by a
  numeric `GradeEntry` (value 7.0, weight 2) and one satisfied by a `sourceKind: exemption` entry (value
  null, weight 3)
- **WHEN** `GradeFormulaEvaluator` computes the `FinalGrade`
- **THEN** the exemption entry contributes nothing to `weightedSum` or `totalWeight`
- **AND** the resulting `value` equals the numeric entry's own value (7.0), not a value dragged down by
  treating the null exemption value as zero
- **AND** `breakdown.components[componentId]` for the exempted component carries `exempt: true`

#### Scenario: An exemption satisfies an all-must-pass component without a numeric check
- **GIVEN** a `CurriculumPlan` with `formula: all-must-pass` and a `passRules` threshold for a component that
  is satisfied only by a `sourceKind: exemption` `GradeEntry`
- **WHEN** `GradeFormulaEvaluator.evaluatePassed()` runs
- **THEN** that component's `passRules` threshold is treated as satisfied without comparing the (null)
  exemption value against it, and `passed` is not forced to `false` solely because of that component

### Requirement: `GradeEntry`/`FinalGrade` read access includes the exam-board role
`GradeEntry.x-property-rbac.read` and `FinalGrade.x-property-rbac.read` MUST gain an `examboard` role
alongside the existing `admin`/self-match (`learnerId`) `anyOf`, so an exam-board member can read the specific
grade their `ExemptionCase` or `FraudCase` concerns — without widening read access to every grade in the
school.

#### Scenario: An exam-board member reads a grade tied to their case
- **GIVEN** a user in the `examboard` NC group, and a `GradeEntry` linked via `exemptionCaseId` or
  `fraudCaseId` to a case they are handling
- **WHEN** they request that `GradeEntry` or its `FinalGrade`
- **THEN** `x-property-rbac.read`'s `examboard` role clause grants access

## ADDED Requirements

### Requirement: `GradeEntry.invalidate` is a guarded terminal transition
`GradeEntry` MUST gain a new terminal lifecycle state `invalidated` and a transition `invalidate` (`concept →
invalidated`), guarded by `FraudCaseInvalidationGuard`, which permits the transition only when the entry's
`fraudCaseId` refers to a `FraudCase` that is `decided` with `verdict: fraud-proven`. The transition is fired
by `FraudCaseDecisionHandler` (an `exam-board` capability listener), never invoked directly by a user action.

#### Scenario: Invalidate is blocked without a fraud-proven decision
- **GIVEN** a `GradeEntry` in `concept` with `fraudCaseId` set to a `FraudCase` that is not yet `decided`, or
  `decided` with `verdict: unfounded`
- **WHEN** `invalidate` is attempted
- **THEN** `FraudCaseInvalidationGuard` blocks it

#### Scenario: Invalidate succeeds once the linked case is decided fraud-proven
- **GIVEN** a `GradeEntry` in `concept` with `fraudCaseId` set to a `FraudCase` that is `decided` with
  `verdict: fraud-proven`
- **WHEN** `FraudCaseDecisionHandler` drives `invalidate`
- **THEN** the `GradeEntry` transitions to `invalidated`, a terminal state from which no further transition is
  possible
