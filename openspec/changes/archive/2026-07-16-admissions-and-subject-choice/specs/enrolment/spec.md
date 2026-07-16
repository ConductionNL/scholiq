## ADDED Requirements

### Requirement: Persist AdmissionsRound and Application domain objects in OpenRegister

The system MUST persist `AdmissionsRound` and `Application` as OpenRegister objects. `AdmissionsRound` MUST
carry `x-openregister-lifecycle` (`draft → open → closed → archived`). `Application` MUST carry
`x-openregister-lifecycle` (`draft → submitted → intake-scheduled → intake-completed → placed | waitlisted
| rejected`; `waitlisted → placed`; `placed → converted`; any of `submitted`/`intake-scheduled`/
`intake-completed`/`waitlisted`/`placed` → `withdrawn`). Every UUID foreign key MUST use the
property-level relation dialect already in use across the register (`format: uuid` + `$ref:
<SchemaTitle>` on the property itself).

#### Scenario: AdmissionsRound and Application persist with their declared lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON and by PHPUnit schema-validation tests — no scholiq DOM surface to drive registration itself. -->

- **GIVEN** the `AdmissionsRound` and `Application` schemas are registered
- **WHEN** an `AdmissionsRound` and an `Application` are each created
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle state

### Requirement: Application captures guardian identity via the reused ADR-046 dual-identity pattern

`Application` MUST carry `guardianId` (nullable Nextcloud user id) and `guardianRef` (nullable UUID domain
ref, ADR-046 A4) as a pair, copying the shape `ConferenceSignup.guardianId`/`guardianRef` already uses, plus
free-text `guardianGivenName`/`guardianFamilyName`/`guardianEmail`/`guardianPhone` and a
`submittedAuthLevel` eIDAS-assurance enum (reusing `ExcuseRequest.submittedAuthLevel`'s shape) for the
common case where no NC account or `LearnerProfile` exists yet at intake time. The system MUST NOT
introduce a second parent/guardian-account mechanism — no new authentication, invitation, or account-linking
class is added by this capability.

#### Scenario: An application is recorded for a family with no existing Scholiq identity

<!-- @e2e exclude Schema-shape verification (field presence, nullability); no distinct DOM behaviour beyond the standard manifest form covered by the frontend requirement's scenario below. -->

- **GIVEN** an intake coordinator records an `Application` for a family with no Nextcloud account and no
  `LearnerProfile`
- **WHEN** the application is saved
- **THEN** `guardianId` and `guardianRef` are both null
- **AND** the guardian's name, email, and phone are captured in the free-text fields
- **AND** no account, invitation, or credential is created as a side effect

### Requirement: Required intake documents reference OpenRegister file attachments

`Application.requiredDocuments` MUST be an array of `{kind, materialId}` entries, where `kind` is one of
`schooladvies`, `doorstroomtoets-result`, `id-document`, `prior-report`, `medical-statement`, `other`, and
`materialId` is a UUID `$ref` to a `Material` object — reusing the `school-structure` rule that materials
reference OpenRegister file attachments and this app stores no file bytes of its own.

#### Scenario: A PO schooladvies document is attached to a VO application

<!-- @e2e exclude Declarative $ref relation, no new file-storage code path; covered by the Material schema's own existing PHPUnit/register-validation coverage. -->

- **GIVEN** a VO `Application` requiring a schooladvies document
- **WHEN** the coordinator attaches the document
- **THEN** `requiredDocuments` carries an entry with `kind: "schooladvies"` referencing a `Material` object
- **AND** no file bytes are stored on the `Application` object itself

### Requirement: An MBO applicant who applies by the deadline and completes the mandatory intake has a right to admission

For an `AdmissionsRound` with `kind: "mbo-toelatingsrecht"`, the `AdmissionsDecisionGuard` MUST block an
`Application`'s transition to `rejected` when `submittedAt` is on or before `AdmissionsRound
.applicationDeadline`, the applicant reached `intake-completed`, and `studiekeuzeadviesGiven` is true —
unless `decisionReason` names a specific unmet prerequisite or additional requirement. `Application` MUST
NOT be transitionable to `intake-completed` while `AdmissionsRound.mandatoryIntake` is true and the intake
conversation has not been recorded.

#### Scenario: A timely, intake-complete MBO application cannot be rejected without a named reason

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testToelatingsrechtBlocksRejectionWithoutNamedReason; no scholiq DOM surface for the guard itself. -->

- **GIVEN** an `Application` under an `mbo-toelatingsrecht` round, submitted before `applicationDeadline`,
  with `intake-completed` reached and `studiekeuzeadviesGiven` true
- **WHEN** a coordinator attempts to transition it to `rejected` with an empty `decisionReason`
- **THEN** the transition is refused

#### Scenario: A named prerequisite failure still allows rejection

<!-- @e2e exclude PHPUnit AdmissionsDecisionGuardTest::testToelatingsrechtAllowsRejectionWithNamedReason; backend guard behaviour. -->

- **GIVEN** the same `Application` as above
- **WHEN** a coordinator transitions it to `rejected` with `decisionReason: "prerequisite diploma not
  held"`
- **THEN** the transition succeeds

### Requirement: A VO schooladvies must be adjusted upward when the doorstroomtoets scores higher, unless motivated

The `AdmissionsDecisionGuard` MUST block any decision transition for an `AdmissionsRound` with
`kind: "vo-schooladvies-doorstroomtoets"` when `Application.doorstroomtoetsLevel` outranks
`Application.schooladviesLevel` on the shared ordinal
(`pro < vmbo-bb < vmbo-kb < vmbo-gt < havo < vwo`), unless `schooladviesAdjustedLevel` equals
`doorstroomtoetsLevel`, or `adjustmentMotivation` is
non-empty, or both levels are `pro`/`vmbo-bb`.

#### Scenario: A higher doorstroomtoets score without an adjustment or motivation blocks the decision

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testSchooladviesAdjustmentRequiredBlocksDecision. -->

- **GIVEN** an `Application` with `schooladviesLevel: "vmbo-gt"` and `doorstroomtoetsLevel: "havo"`, and
  `schooladviesAdjustedLevel` still `"vmbo-gt"` with an empty `adjustmentMotivation`
- **WHEN** a coordinator attempts to move the application to a decision
- **THEN** the transition is refused

#### Scenario: The pro/vmbo-bb exemption allows the decision without adjustment

<!-- @e2e exclude PHPUnit AdmissionsDecisionGuardTest::testProVmboBbExemptionAllowsDecision. -->

- **GIVEN** an `Application` with `schooladviesLevel: "pro"` and `doorstroomtoetsLevel: "vmbo-bb"`
- **WHEN** a coordinator moves the application to a decision without raising `schooladviesAdjustedLevel`
- **THEN** the transition succeeds

### Requirement: Placement capacity is enforced and a waitlisted Application is auto-promoted when a seat frees up

When `AdmissionsRound.capacity` is set, `AdmissionsDecisionGuard` MUST block an `Application`'s transition
to `placed` once the count of that round's `placed`/`converted` `Application`s reaches `capacity` — the
transition MUST target `waitlisted` instead. When a `placed` `Application` transitions to `withdrawn` or
`rejected`, `AdmissionsWaitlistPromoter` MUST promote the oldest-`submittedAt` `waitlisted` `Application`
for the same `admissionsRoundId` to `placed`, re-running `AdmissionsDecisionGuard`.

#### Scenario: A full round routes a new placement to the waitlist

<!-- @e2e exclude Cross-object count in a lifecycle guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testCapacityReachedBlocksPlacement. -->

- **GIVEN** an `AdmissionsRound` with `capacity: 2` and two `Application`s already `placed`
- **WHEN** a coordinator attempts to transition a third `Application` to `placed`
- **THEN** the transition is refused and the coordinator must target `waitlisted` instead

#### Scenario: A withdrawal promotes the oldest waitlisted applicant

<!-- @e2e exclude Event-driven promotion is backend logic verified by PHPUnit AdmissionsWaitlistPromoterTest::testOldestWaitlistedApplicationPromotedOnWithdrawal. -->

- **GIVEN** an `AdmissionsRound` at capacity with two `waitlisted` `Application`s, `A` (older `submittedAt`)
  and `B`
- **WHEN** a `placed` `Application` for the same round transitions to `withdrawn`
- **THEN** `A` transitions to `placed`
- **AND** `B` remains `waitlisted`

### Requirement: An accepted Application converts into a LearnerProfile and Enrolments

When an `Application` transitions to `placed`, `ApplicationConversionHandler` MUST create a `LearnerProfile`
(`guardianRefs` stamped from `Application.guardianRef` when set), create one `Enrolment`
(`source: "admission"`) per course in the chosen `Programme.courseIds`, stamp `Application
.convertedLearnerProfileId` and `convertedEnrolmentIds`, and transition the `Application` to `converted`.
This handler MUST NOT provision a Nextcloud user account or LMS access as a side effect.

#### Scenario: Placement creates a LearnerProfile and Enrolments

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit ApplicationConversionHandlerTest::testPlacementCreatesLearnerProfileAndEnrolments. -->

- **GIVEN** an `Application` for a `Programme` with three courses, transitioning to `placed`
- **WHEN** `ApplicationConversionHandler` runs
- **THEN** a `LearnerProfile` is created with `guardianRefs` containing the application's `guardianRef`
- **AND** three `Enrolment` objects are created with `source: "admission"`
- **AND** the `Application` transitions to `converted` with both reference fields stamped

### Requirement: Enrolment records its origin including admission and subject-choice sources

The `Enrolment.source` enum MUST gain two additive values: `"admission"` (created by
`ApplicationConversionHandler` on placement) and `"subject-choice"` (created by a `SubjectChoice`'s
approval, per the `school-structure` capability). Existing enum values and existing `Enrolment` rows are
unaffected.

#### Scenario: An admission-created Enrolment carries the admission source

<!-- @e2e exclude Additive enum value; covered by ApplicationConversionHandlerTest and the register JSON-schema validation gate, no distinct DOM surface. -->

- **GIVEN** the `Enrolment.source` enum
- **WHEN** an `Enrolment` is created by `ApplicationConversionHandler`
- **THEN** its `source` is `"admission"`

### Requirement: Frontend is declarative with one named admissions-review exception

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `AdmissionsRound` and
`Application`. The only custom Vue component for admissions MUST be `AdmissionsReviewBoard.vue` — a
coordinator's queue of applications needing intake scheduling or a decision, cross-referencing each
`Application` against its round's deadline, kind, and remaining capacity. No PHP CRUD controllers.

#### Scenario: A coordinator reviews pending applications on the review board

<!-- @e2e tests/e2e/spec-coverage/admissions-and-subject-choice.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception is the drivable DOM scenario, mirroring BsaRiskDashboard's e2e coverage pattern; the underlying guard/handler logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** one or more `Application`s in `intake-completed` state for the coordinator's scope
- **WHEN** the coordinator opens the admissions review board
- **THEN** the pending applications are listed with their round's deadline, kind, and remaining capacity
- **AND** the coordinator can navigate from a listed application to record its decision
