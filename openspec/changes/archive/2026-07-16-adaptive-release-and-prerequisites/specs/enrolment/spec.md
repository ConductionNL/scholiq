## MODIFIED Requirements

### Requirement: Validate prerequisites before persistence

The system MUST validate prerequisites before enrolment is persisted. This MUST be implemented as an
OpenRegister `ObjectCreatingEvent` listener on the `enrolment` schema (`EnrolmentPrerequisiteListener`) —
NOT as an `x-openregister-lifecycle` transition `requires` guard — because a `requires` guard only resolves
on a transition between two already-persisted states, and `Enrolment` has no transition into its `pending`
initial state for a guard to attach to. For each UUID in the target `Course`'s `prerequisiteCourseIds`, the
listener MUST check whether the enrolling learner already holds an `Enrolment` with `lifecycle: completed`
for that prerequisite course. If any required prerequisite is unmet, the listener MUST call the event's
error-setting and propagation-stopping methods so OpenRegister aborts the create with a validation error
naming the specific failing prerequisite by course name — not a generic rejection. When `Course` has no
`prerequisiteCourseIds` (empty or absent), enrolment MUST proceed unaffected. A lookup failure caused by an
infrastructure error (not an unmet prerequisite) MUST NOT block the enrolment — the listener fails open on
infrastructure faults and fails closed only on an actually-unmet, successfully-checked prerequisite.

#### Scenario: Block enrolment when prerequisites are unmet

<!-- @e2e exclude Pure backend/data-model requirement — no scholiq DOM surface to drive an ObjectCreatingEvent rejection directly; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course with prerequisites the learner has not met
- **WHEN** the learner attempts to enrol
- **THEN** the system blocks the enrolment before persistence and names the failing prerequisite

#### Scenario: Enrolment proceeds unaffected when a course has no prerequisites

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course with an empty or absent `prerequisiteCourseIds`
- **WHEN** a learner attempts to enrol
- **THEN** the enrolment is created without any prerequisite check blocking it

#### Scenario: Enrolment succeeds once the prerequisite course is completed

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course requiring a prerequisite course the learner holds a `completed` `Enrolment` for
- **WHEN** the learner attempts to enrol
- **THEN** the enrolment is created

#### Scenario: An infrastructure error during the prerequisite lookup does not block enrolment

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener, simulating an ObjectService failure. -->

- **GIVEN** the prerequisite course lookup fails due to an infrastructure error, not an unmet prerequisite
- **WHEN** a learner attempts to enrol
- **THEN** the system allows the enrolment and logs the failure, rather than blocking on an unverifiable
  check
