# Grading — Portfolio GradeEntry Source Delta

**Spec refs**: `grading`; `eportfolio` (this change's new capability, whose `PortfolioGradeEmitHandler`
consumes this delta).

## MODIFIED Requirements

### Requirement: Persist grading domain objects in OpenRegister

The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects.
`GradeEntry` has `x-openregister-lifecycle` (concept → published → revised) and
`x-openregister-notifications` keyed so a re-publish/backfill doesn't double-notify. `FinalGrade`
is computed via `x-openregister-calculations` + cross-schema aggregation over the learner's
published `GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights.
`GradeEntry.sourceKind` MUST include `portfolio` alongside the existing `assignment-submission`,
`assessment-result`, `participation`, `manual`, `exemption`, and `lti-ags` values, so a mark
originating from a graded `course-bound` `Portfolio` carries an honest, traceable origin rather
than being recorded as `manual`. When `sourceKind = portfolio`, `GradeEntry` MUST carry
`portfolioId` (the originating `Portfolio`, `$ref: Portfolio`), nullable and set only for this
`sourceKind` — the same additive, per-`sourceKind` reference-field shape `submissionId` /
`assessmentResultId` / `exemptionCaseId` / `fraudCaseId` / `ltiToolPlacementId` already use.

#### Scenario: Grading objects persisted in OpenRegister

- **GIVEN** the grading domain schemas are registered
- **WHEN** a `GradeEntry` is published for a learner
- **THEN** `GradeScale`, `GradeEntry`, and `FinalGrade` are stored as OpenRegister objects and the
  `FinalGrade` is computed via `x-openregister-calculations` over the learner's published entries

#### Scenario: A graded course-bound portfolio creates a traceable concept GradeEntry

<!-- @e2e exclude Cross-schema event-to-object-write bridge is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest, mirroring GradeRollupHandlerTest::testAssessmentResultGradedCreatesConceptGradeEntry's equivalent coverage. -->

- **GIVEN** a `course-bound` `Portfolio` with `gradeValue` set, in `submitted` state
- **WHEN** the portfolio transitions to `graded`
- **THEN** a `GradeEntry` is created with `sourceKind: 'portfolio'`, `lifecycle: 'concept'`, and
  `portfolioId` set to the originating `Portfolio`
- **AND** it is NOT recorded with `sourceKind: 'manual'`

#### Scenario: Re-processing the graded transition does not create a duplicate GradeEntry

<!-- @e2e exclude Idempotency guard is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest::testNoDuplicateWhenGradeEntryIdAlreadySet, mirroring GradeRollupHandler::handleAssessmentResultGraded()'s existing `gradeEntryId already set` skip. -->

- **GIVEN** a `Portfolio` whose `gradeEntryId` is already set from a prior `graded` transition
- **WHEN** the `graded` transition is processed again for the same portfolio
- **THEN** no second `GradeEntry` is created

## Data Model

New (this delta): `Portfolio`, `PortfolioEntry` (`eportfolio` — cross-referenced here as the source of
`GradeEntry.portfolioId`). `GradeEntry` gains `portfolioId` (nullable, `$ref: Portfolio`) alongside the
existing per-`sourceKind` id fields (`submissionId`, `assessmentResultId`, `sessionId`, `exemptionCaseId`,
`fraudCaseId`, `ltiToolPlacementId`). No change to `FinalGrade` or the roll-up calculation — a `portfolio`
`GradeEntry` publishes through the same `x-openregister-lifecycle` / `calculatedChange` trigger as every
other `sourceKind`, exactly as the `lti-tool-placement` change's `lti-ags` addition did before it.
