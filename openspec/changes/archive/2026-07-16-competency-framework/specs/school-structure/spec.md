## ADDED Requirements

### Requirement: Programme declares its required competencies for the skills-gap view

The `Programme` object MUST support a `requiredCompetencyIds` field (array of `format: uuid` `$ref:
Competency`, default `[]`) declaring which competencies a learner must attain to complete this programme —
the "required by Programme" half of the `competency` capability's skills-gap view (the other half,
"required by role," is declared on `Competency.requiredForRoles` in the `competency` capability itself).
The field MUST be additive and MUST NOT be required; it does not change `Programme`'s existing
`courseIds`/`curriculumPlanId`/`credentialTemplateId` relations or its `publish`/`archive`/`unarchive`
lifecycle.

#### Scenario: A programme declares its required competencies

<!-- @e2e exclude Pure OpenRegister schema field; consumed by the competency capability's SkillsGapDashboard.vue, whose own scenario and @e2e reference live in specs/competency/spec.md. -->

- **GIVEN** a `Programme` being authored
- **WHEN** the coordinator sets `requiredCompetencyIds` to the set of competencies the programme's
  qualification requires
- **THEN** the values persist on the `Programme` object

#### Scenario: An existing programme without declared required competencies is unaffected

<!-- @e2e exclude Additive-field default-value handling; no DOM surface. -->

- **GIVEN** a pre-existing `Programme` row with no `requiredCompetencyIds` set
- **WHEN** it is read
- **THEN** `requiredCompetencyIds` resolves to an empty array, and the skills-gap view treats that
  programme as declaring no Programme-required competencies (role-required competencies via
  `Competency.requiredForRoles` are unaffected)
