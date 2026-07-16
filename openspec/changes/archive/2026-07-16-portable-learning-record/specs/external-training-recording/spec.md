## ADDED Requirements

### Requirement: `ExternalTrainingRecord` carries an additive ADR-046 portal-scoping reference

`ExternalTrainingRecord` MUST carry a nullable `learnerRef` (`format: uuid`, `$ref: LearnerProfile`)
alongside its existing `learnerId` (Nextcloud user id), additive and optional so existing rows stay valid
and an unset ref is fail-closed (invisible to any `learnerRef`-scoped read, including
`LearningRecordAggregationService`) until backfilled — the identical shape `portal-identity` established for
its first slice of eight schemas (`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`, `Submission`,
`ExcuseRequest`, `LearnerProfile`, `GradeNotification`) and every wave-2 capability that introduced a new
learner-scoped schema since (`Portfolio`, `CompetencyAttainment`, `BpvPlacement`, `LessonCompletion`,
`ReportCard` all carry the same field). `ExternalTrainingRecord` was not part of `portal-identity`'s original
slice and had no `learnerRef` at HEAD — this closes that gap so `portable-learning-record` can scope it like
every other consumed schema.

#### Scenario: `ExternalTrainingRecord` gains an additive, optional `learnerRef`

<!-- @e2e exclude Pure OpenRegister schema shape; no scholiq DOM surface for the field addition itself — covered by the register-validation test referenced in tasks.md, mirroring portal-identity's own equivalent scenario. -->

- **GIVEN** the shipped `scholiq_register.json`
- **WHEN** the register configuration is parsed
- **THEN** `ExternalTrainingRecord` defines a `learnerRef` property with `format: uuid` and `$ref:
  LearnerProfile`
- **AND** its existing `learnerId` property is unchanged and `learnerRef` is not `required`
- **AND** existing `ExternalTrainingRecord` rows with no `learnerRef` remain valid and stay invisible to any
  `learnerRef`-scoped read until backfilled
