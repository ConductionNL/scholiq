## ADDED Requirements

### Requirement: Assessment declares which competencies it assesses, and Item carries competency tags for authoring

The `Assessment` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies a graded `AssessmentResult` for this assessment provides
evidence for, following the same shape as `Assignment.competencyIds`. The `Item` object MUST support the
same field for authoring/analytics purposes (cross-bank filtering by competency, alongside the existing
free-text `subjectTags`) — `Item.competencyIds` is NOT consumed by the `competency` capability's roll-up
in this change, because `AssessmentResult` produces one `GradeEntry` per attempt at `Assessment` grain,
not per `Item`; `Item.subjectTags` MUST remain unchanged. Both new fields MUST be additive and MUST NOT be
required.

#### Scenario: A published GradeEntry from an aligned Assessment feeds the competency roll-up

<!-- @e2e exclude Pure OpenRegister schema field; the roll-up behaviour is covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest, not a scholiq DOM surface here. -->

- **GIVEN** an `Assessment` with `competencyIds` set to one `Competency` UUID
- **WHEN** a learner's `AssessmentResult` for that assessment is graded and its `GradeEntry` transitions to
  `published`
- **THEN** the `competency` capability's roll-up handler creates or updates a `CompetencyAttainment` row
  for that learner and competency

#### Scenario: An item author tags items by competency for cross-bank search without affecting grading

<!-- @e2e exclude Pure OpenRegister schema field on authoring metadata; no DOM surface beyond the existing item-bank search UI already covered by this spec. -->

- **GIVEN** an `Item` being authored in an `ItemBank`
- **WHEN** the author sets `competencyIds` alongside the existing `subjectTags`
- **THEN** both fields persist independently, and `competencyIds` is available for cross-bank filtering
  without being consumed by the `competency` roll-up
