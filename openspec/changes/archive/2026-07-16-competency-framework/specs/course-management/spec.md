## ADDED Requirements

### Requirement: Course and Lesson declare which competencies they teach

The `Course` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies (from the `competency` capability's taxonomy) this course
teaches, and the `Lesson` object MUST support the same field at lesson granularity. Both fields MUST be
additive — existing `Course`/`Lesson` rows leave `competencyIds` as an empty array — and MUST NOT be
required. `Lesson.learningObjectives` (the existing free-text `string[]`) MUST remain unchanged and
continue to accept free text; its description gains a note pointing authors at `competencyIds` for
anything that needs to roll up into a learner's `CompetencyAttainment` or be queried structurally, since
`learningObjectives` itself is not linked to the taxonomy and never rolls up.

#### Scenario: A course declares the competencies it teaches

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface beyond the declarative manifest pages the course-management spec already covers. Consumed by the competency capability's alignment view. -->

- **GIVEN** a `Course` being authored
- **WHEN** the instructional designer sets `competencyIds` to one or more `Competency` UUIDs
- **THEN** the values persist on the `Course` object
- **AND** they are queryable by the `competency` capability's alignment and skills-gap views

#### Scenario: An existing course or lesson without declared competencies is unaffected

<!-- @e2e exclude Additive-field default-value handling; no DOM surface. -->

- **GIVEN** a pre-existing `Course` or `Lesson` row with no `competencyIds` set
- **WHEN** it is read
- **THEN** `competencyIds` resolves to an empty array and the row behaves exactly as it did before this
  change
