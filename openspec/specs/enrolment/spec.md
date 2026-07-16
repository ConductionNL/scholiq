---
slug: enrolment
title: Enrolment
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# Enrolment

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, prerequisite validation, and Studielink/Edukoppeling integration — no `#### Scenario:` headings exist in this spec.

## Purpose
Enrolment is the gateway from identity to learning record. For HE, Studielink integration is mandatory (insight #4); for corporate L&D, bulk-enrol of cohorts is the #1 line-manager workflow (5 high-priority stories). Without enrolment, every downstream capability — assessment, certification, compliance audit — has no subject.

## What
Manual and bulk enrolment of learners into courses, modules, and learning paths; cohort and group management (NL `klas`, HE `tutor group`, corporate `team`); eligibility/prerequisite checks; auto-enrol on hire (HR template) and on Studielink intake (HE); auto-enrol into certification renewal modules when expiry is detected; unenrolment with reason capture; immediate LMS account provisioning on enrolment.

## User Stories
- As an HE administrator, I want incoming Studielink enrolments to appear automatically in Scholiq so I do not rekey applicant data.
- As a student, I want an institutional account and LMS access immediately after enrolment so I can start orientation activities.
- As HR, I want to apply a 30-60-90 onboarding template when I create a new-hire account so modules are scheduled across days 1, 30, 60, and 90.
- As a line manager, I want to bulk-assign a course to my direct reports so all selected learners are enrolled with the same deadline and I see a team progress bar.
- As a compliance officer, I want to bulk-enrol every active employee in the annual refresher with deadlines T-30, T-7, T-1 days so coverage is automatic.

## Acceptance Criteria
- GIVEN a Studielink enrolment is received via the Edukoppeling adapter, WHEN it parses successfully, THEN a Learner + Enrolment object is created and an LMS account is provisioned within 60 seconds.
- GIVEN a line manager opens the team view, WHEN they multi-select reports and pick a course, THEN every selected learner is enrolled with a single shared deadline and notification.
- GIVEN HR creates a new hire, WHEN they pick the role, THEN the matching 30-60-90 template auto-applies and milestones populate Days 1/30/60/90.
- GIVEN a course has unmet prerequisites, WHEN a learner attempts enrolment, THEN the system blocks the enrolment and explains which prerequisite failed.
## Requirements
### Requirement: Bulk enrolment via cohort, role, department or CSV
The system MUST support bulk enrolment via cohort, role, department, or CSV upload.

#### Scenario: Bulk-enrol a selected group of learners
- **GIVEN** a manager with a cohort, role, department, or CSV of learners and a target course
- **WHEN** they trigger a bulk enrolment
- **THEN** the system enrols every selected learner into the course with a single shared deadline

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

### Requirement: Provision LMS account within 60 seconds via Studielink
The system MUST provision an LMS account within 60 seconds of an HE enrolment via Studielink.

#### Scenario: Provision an account on Studielink intake
- **GIVEN** a Studielink enrolment received via the Edukoppeling adapter
- **WHEN** it parses successfully
- **THEN** the system creates the Learner and Enrolment objects and provisions an LMS account within 60 seconds

### Requirement: Enrolment carries a declared lesson-progress roll-up

The system MUST expose a declared, learner-scoped lesson-progress roll-up on `Enrolment` — `completedLesson
Count` and `totalPublishedLessonCount` as `x-openregister-aggregate-refs` (cross-schema counts against
`lesson-completion` and `lesson` respectively, scoped by `learnerId`/`courseId`), and `progressPercent` as a
plain, PHP-computed field written by `OCA\Scholiq\Progress\EnrolmentProgressEvaluator` via
`OCA\Scholiq\Listener\EnrolmentProgressRollupHandler` — reusing the same `x-openregister-aggregations` +
`engine`-keyed-PHP shape `FinalGrade.value` already uses, because no declarative division operator exists in
the calculation DSL. The roll-up MUST be recomputed whenever a `LessonCompletion` for the learner+course is
created or updated — not via a `TimedJob` (ADR-022).

#### Scenario: Progress percentage recomputes when a lesson is completed
<!-- @e2e exclude Calculation + trigger behaviour is backend/PHP logic verified by PHPUnit (EnrolmentProgressEvaluatorTest, EnrolmentProgressRollupHandlerTest); no DOM surface for a declared roll-up recomputing server-side. -->

- **GIVEN** an active `Enrolment` for a learner in a `Course` with 10 published `Lesson`s and 3 existing
  `LessonCompletion` rows for that learner
- **WHEN** a 4th `LessonCompletion` is created for that learner and course
- **THEN** `Enrolment.completedLessonCount` reads `4` and `totalPublishedLessonCount` reads `10`
- **AND** `Enrolment.progressPercent` recomputes to `40`

#### Scenario: Progress percentage is null-safe before any lesson completes
<!-- @e2e exclude Backend edge-case, covered by EnrolmentProgressEvaluatorTest (zero completions, zero published lessons). -->

- **GIVEN** a newly `active` `Enrolment` with zero `LessonCompletion` rows
- **WHEN** `progressPercent` is read
- **THEN** it reads `0`, not an error, even when `totalPublishedLessonCount` is also `0`

#### Scenario: Progress percentage is visible on the learner's My-learning dashboard
- **GIVEN** a learner with an active `Enrolment` whose `progressPercent` is `40`
- **WHEN** they open their My-learning dashboard
- **THEN** the enrolment's progress is shown as a percentage, sourced from `Enrolment.progressPercent`
<!-- @e2e exclude Requires a seeded learner session with existing LessonCompletion data on the single-admin scholiq e2e harness, which cannot provision a second per-test learner identity; covered by the manual-completion scenario in progress-tracking.spec.ts instead, which exercises the same progressPercent field end-to-end from a single admin session. -->

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

## Standards
Studielink, Edukoppeling, OOAPI 5.0, IMS LIS (legacy), Schema.org `EducationEvent`, eduPersonAffiliation propagation.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Learner`, `Enrolment`, `Cohort`, `OnboardingTemplate`, `EnrolmentRule`. All in OpenRegister.

## Out of Scope
- Payment processing for paid enrolments (separate spec; routes to billing system).
- Waitlist auto-promotion (V1 enhancement).
- Cross-institution credit transfer (handled by oso-transfer / EDCI).
