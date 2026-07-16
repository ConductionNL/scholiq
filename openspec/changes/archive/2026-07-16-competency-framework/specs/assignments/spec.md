## ADDED Requirements

### Requirement: Assignment declares which competencies it assesses

The `Assignment` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies a graded `Submission` for this assignment provides evidence
for. The field MUST be additive — existing `Assignment` rows leave `competencyIds` as an empty array — and
MUST NOT be required. When set, the `competency` capability's `CompetencyAttainmentRollupHandler` MUST
treat every listed competency as aligned when the resulting `GradeEntry` (`sourceKind:
assignment-submission`) publishes.

#### Scenario: A published GradeEntry from an aligned Assignment feeds the competency roll-up

<!-- @e2e exclude Pure OpenRegister schema field; the roll-up behaviour itself is covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest, not a scholiq DOM surface here. -->

- **GIVEN** an `Assignment` with `competencyIds` set to one `Competency` UUID
- **WHEN** a learner's `Submission` for that assignment is marked and its `GradeEntry` transitions to
  `published`
- **THEN** the `competency` capability's roll-up handler creates or updates a `CompetencyAttainment` row
  for that learner and competency

#### Scenario: An assignment with no declared competencies does not participate in the roll-up

<!-- @e2e exclude Additive-field default-value / no-op handling; no DOM surface. -->

- **GIVEN** a pre-existing `Assignment` row with `competencyIds` unset (defaults to `[]`)
- **WHEN** a `Submission` for it is marked and published
- **THEN** no `CompetencyAttainment` row is created or updated, and grading behaves exactly as it did
  before this change
