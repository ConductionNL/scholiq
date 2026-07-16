## ADDED Requirements

### Requirement: CurriculumPlan declares elective-selection validation rules

`CurriculumPlan` MUST carry an additive, nullable `electiveRules` object: `minElectives`/`maxElectives`
(nullable integers), `mandatoryCombinations` (array of course-id sets that must be chosen together),
`mutuallyExclusive` (array of course-id sets that cannot be chosen together), and `capacityByCourseId`
(nullable map of `courseId` → maximum seats; absence means uncapped). Every existing `CurriculumPlan` row
MUST remain valid with `electiveRules` unset.

#### Scenario: A PTA declares a profiel's mandatory vak combination and a capacity limit

<!-- @e2e exclude Pure OpenRegister schema addition; verified by reasoning over the register JSON and by PHPUnit schema-validation tests — no scholiq DOM surface for the field's presence itself. -->

- **GIVEN** a `CurriculumPlan` expressing a VO profiel
- **WHEN** the plan declares `mandatoryCombinations` naming two courses that must be chosen together and a
  `capacityByCourseId` limit for one of its electives
- **THEN** the plan persists `electiveRules` with that shape
- **AND** an existing `CurriculumPlan` row with no `electiveRules` set remains valid

### Requirement: Persist SubjectChoice domain objects in OpenRegister

The system MUST persist `SubjectChoice` as an OpenRegister object with `x-openregister-lifecycle`
(`draft → submitted → validated | needs-revision → approved → locked`; `needs-revision → draft`). Every
UUID foreign key MUST use the property-level relation dialect already in use across the register.

#### Scenario: SubjectChoice persists with its declared lifecycle

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON and by PHPUnit schema-validation tests. -->

- **GIVEN** the `SubjectChoice` schema is registered
- **WHEN** a `SubjectChoice` is created
- **THEN** it is stored as an OpenRegister object carrying its declared lifecycle state

### Requirement: Guardian consent gates a minor's subject-choice submission

`SubjectChoice`'s `submit` transition (`draft → submitted`) MUST be gated by `SubjectChoiceConsentGuard`,
which resolves the caller's Nextcloud user id server-side and passes only when the caller is listed in the
target learner's `LearnerProfile.parentIds`, or the caller **is** the target learner — the identical rule
`ConferenceSignupGuardianGuard` already enforces for conference sign-ups, reapplied here rather than
reimplemented.

#### Scenario: A linked guardian can submit a subject choice for their own child

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit SubjectChoiceConsentGuardTest::testLinkedGuardianCanSubmit, mirroring ConferenceSignupGuardianGuardTest. -->

- **GIVEN** a guardian whose Nextcloud user id is in `LearnerProfile.parentIds` for learner L
- **WHEN** they submit a `SubjectChoice` naming learner L's selected electives
- **THEN** the `submit` transition succeeds

#### Scenario: An unrelated user cannot submit a subject choice for someone else's child

<!-- @e2e exclude PHPUnit SubjectChoiceConsentGuardTest::testUnrelatedUserCannotSubmit. -->

- **GIVEN** an authenticated user whose Nextcloud user id is NOT in `LearnerProfile.parentIds` for learner
  L and who is not learner L
- **WHEN** they attempt to submit a `SubjectChoice` naming learner L
- **THEN** the `submit` transition is blocked
- **AND** the `SubjectChoice` remains `draft`

### Requirement: A submitted subject choice is validated against the plan's elective rules, not persisted unchecked

On a `SubjectChoice` reaching `submitted`, `SubjectChoiceValidator` MUST check
`selectedElectiveCourseIds` against the referenced `CurriculumPlan.electiveRules`
(`minElectives`/`maxElectives`, `mandatoryCombinations`, `mutuallyExclusive`) and against the current
`capacityByCourseId` occupancy (counting sibling `SubjectChoice` rows in `approved`/`locked` state for the
same `curriculumPlanId`), then write the object to `validated` on success or `needs-revision` with a
populated `validationErrors[]` naming each unmet rule on failure.

#### Scenario: A choice satisfying every rule validates

<!-- @e2e exclude Cross-object validation is backend logic verified by PHPUnit SubjectChoiceValidatorTest::testValidChoiceMovesToValidated. -->

- **GIVEN** a `CurriculumPlan` with `minElectives: 2`, `maxElectives: 2`, and no mandatory/exclusive
  combinations involving the chosen courses
- **AND** a `submitted` `SubjectChoice` selecting exactly those 2 electives, each under its
  `capacityByCourseId` limit
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `validated`

#### Scenario: A choice violating a mandatory combination is sent back for revision

<!-- @e2e exclude PHPUnit SubjectChoiceValidatorTest::testMandatoryCombinationViolationMovesToNeedsRevision. -->

- **GIVEN** a `CurriculumPlan` with a `mandatoryCombinations` entry requiring courses X and Y together
- **AND** a `submitted` `SubjectChoice` selecting X but not Y
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `needs-revision`
- **AND** `validationErrors` names the unmet mandatory combination

#### Scenario: A choice exceeding a course's capacity is sent back for revision

<!-- @e2e exclude PHPUnit SubjectChoiceValidatorTest::testCapacityExceededMovesToNeedsRevision. -->

- **GIVEN** a `CurriculumPlan` course with `capacityByCourseId` limit 1 already filled by another
  `locked` `SubjectChoice`
- **AND** a `submitted` `SubjectChoice` also selecting that course
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `needs-revision`
- **AND** `validationErrors` names the capacity conflict

### Requirement: An approved subject choice feeds Enrolment

When a `SubjectChoice` transitions `approved → locked`, `SubjectChoiceEnrolmentBridge` MUST create or update
an `Enrolment` (`source: "subject-choice"`) for each course in `selectedElectiveCourseIds` that the learner
is not already enrolled in.

#### Scenario: Locking a subject choice enrols the learner in the chosen electives

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit SubjectChoiceEnrolmentBridgeTest::testLockCreatesEnrolments. -->

- **GIVEN** an `approved` `SubjectChoice` selecting two elective courses the learner is not yet enrolled in
- **WHEN** it transitions to `locked`
- **THEN** two `Enrolment` objects are created with `source: "subject-choice"`, one per selected course

### Requirement: Frontend is declarative with one named subject-choice-picker exception

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `SubjectChoice`. The only
custom Vue component for subject choice MUST be `SubjectChoicePicker.vue` — an interactive elective picker
showing live rule and capacity feedback from the referenced `CurriculumPlan.electiveRules`, which a generic
manifest form cannot render. No PHP CRUD controllers.

#### Scenario: A learner picks electives with live rule feedback

<!-- @e2e tests/e2e/spec-coverage/admissions-and-subject-choice.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception is the drivable DOM scenario, mirroring BookConferenceSlotsView's e2e coverage pattern; the underlying validation logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** a `CurriculumPlan` with declared `electiveRules`
- **WHEN** a learner opens `SubjectChoicePicker.vue` for that plan and selects electives
- **THEN** the picker shows live feedback against `minElectives`/`maxElectives`, mandatory combinations, and
  remaining capacity before submission
- **AND** every other subject-choice screen (list/detail) is a declarative `src/manifest.json` page
