## ADDED Requirements

### Requirement: Course declares an ECTS credit value

The `Course` object MUST support an `ectsCredits` field (nullable number, `minimum: 0`) declaring the
Bologna-style credit value the course/module contributes toward a learner's cumulative EC total. The field
MUST be additive — existing `Course` rows leave it `null` — and MUST NOT be required, since `po`/`vo`/
`corporate` courses (which do not participate in ECTS-bearing programmes) never need to set it. Any
consumer summing a learner's earned credits MUST treat a `null` `ectsCredits` as `0`, not as an error.

#### Scenario: A course declares its ECTS value

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface. Consumed by the study-progress capability's BsaProgressEvaluator, itself covered by PHPUnit as referenced in that spec. -->

- **GIVEN** an HBO/WO course being authored
- **WHEN** the instructional designer sets `ectsCredits` to a positive number
- **THEN** the value persists on the `Course` object
- **AND** it is available to any downstream credit-summing calculation (e.g. the `study-progress`
  capability's `BsaProgressEvaluator`)

#### Scenario: An existing course without a declared credit value defaults to zero for summation

<!-- @e2e exclude Null-handling verified by the study-progress capability's BsaProgressEvaluatorTest; no scholiq DOM surface here. -->

- **GIVEN** a pre-existing `Course` row with `ectsCredits` unset (`null`)
- **WHEN** a downstream calculation sums a learner's earned credits across their passed courses
- **THEN** that course contributes `0` EC to the total
- **AND** the calculation does not error
