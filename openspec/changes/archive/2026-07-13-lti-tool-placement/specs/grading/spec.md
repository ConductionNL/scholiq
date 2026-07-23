# Grading — LTI AGS Grade Passback Delta

**Spec refs**: `grading`; openconnector `lti-13-platform` REQ-LTI-007 (AGS score CloudEvent),
REQ-LTI-010 (consuming-app contract).

## MODIFIED Requirements

### Requirement: Persist grading domain objects in OpenRegister

The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects.
`GradeEntry` has `x-openregister-lifecycle` (concept → published → revised) and
`x-openregister-notifications` keyed so a re-publish/backfill doesn't double-notify. `FinalGrade`
is computed via `x-openregister-calculations` + cross-schema aggregation over the learner's
published `GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights.
`GradeEntry.sourceKind` MUST include `lti-ags` alongside the existing `assignment-submission`,
`assessment-result`, `participation`, and `manual` values, so a score received via LTI
Assignment & Grade Services (AGS) passback carries an honest, traceable origin rather than being
recorded as `manual`. When `sourceKind = lti-ags`, `GradeEntry` MUST carry `ltiToolPlacementId`
(the originating `LtiToolPlacement`) and `ltiAgsResultId` (the AGS result/CloudEvent message
identifier, used as an idempotency key so a redelivered event cannot create a duplicate
`GradeEntry`).

#### Scenario: Grading objects persisted in OpenRegister

- **GIVEN** the grading domain schemas are registered
- **WHEN** a `GradeEntry` is published for a learner
- **THEN** `GradeScale`, `GradeEntry`, and `FinalGrade` are stored as OpenRegister objects and the
  `FinalGrade` is computed via `x-openregister-calculations` over the learner's published entries

#### Scenario: An LTI AGS score creates a traceable concept GradeEntry

- **GIVEN** a published `LtiToolPlacement` configured with `curriculumPlanId` and
  `gradeEntryComponentId`, and an AGS score-received CloudEvent for its
  `openconnectorDeploymentId`
- **WHEN** the score is translated into a `GradeEntry`
- **THEN** the entry is created with `sourceKind: 'lti-ags'`, `lifecycle: 'concept'`,
  `ltiToolPlacementId` set to the originating placement, and `ltiAgsResultId` set to the AGS
  result identifier
- **AND** it is NOT recorded with `sourceKind: 'manual'`

#### Scenario: A redelivered AGS message does not create a duplicate GradeEntry

- **GIVEN** a `GradeEntry` already exists with a given `(ltiToolPlacementId, ltiAgsResultId)`
  pair
- **WHEN** the same AGS score-received message is processed again
- **THEN** no second `GradeEntry` is created for that pair

## Data Model

New (this delta): `LtiToolPlacement` (`course-management` — cross-referenced here as the source
of `GradeEntry.ltiToolPlacementId`). `GradeEntry` gains `ltiToolPlacementId` (nullable, `$ref:
LtiToolPlacement`) and `ltiAgsResultId` (nullable, string) alongside the existing per-`sourceKind`
id fields (`submissionId`, `assessmentResultId`, `sessionId`). No change to `FinalGrade` or the
roll-up calculation — an `lti-ags` `GradeEntry` publishes through the same
`x-openregister-lifecycle` / `calculatedChange` trigger as every other `sourceKind`.
