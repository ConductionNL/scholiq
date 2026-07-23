## ADDED Requirements

### Requirement: Credential declares which competencies it attests

The `Credential` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies this issued credential attests the learner has attained — e.g.
a diploma attesting every competency in its `Programme`'s `requiredCompetencyIds`, or a microcredential
attesting one leaf competency. The field MUST be additive and MUST NOT be required; issuance logic is
unchanged by this change (no new issuance trigger is added).

#### Scenario: A microcredential attests one leaf competency

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface beyond the declarative manifest pages this spec already covers. -->

- **GIVEN** a `Credential` of `kind: microcredential` being issued
- **WHEN** the issuer sets `competencyIds` to one leaf `Competency` UUID
- **THEN** the value persists on the `Credential` object and is available to any consumer cross-referencing
  what a credential attests against `CompetencyAttainment`

#### Scenario: An existing credential without declared competencies is unaffected

<!-- @e2e exclude Additive-field default-value handling; no DOM surface. -->

- **GIVEN** a pre-existing `Credential` row with no `competencyIds` set
- **WHEN** it is read
- **THEN** `competencyIds` resolves to an empty array and issuance/revocation behave exactly as they did
  before this change
