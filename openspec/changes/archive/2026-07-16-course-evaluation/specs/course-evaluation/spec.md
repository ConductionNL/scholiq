## ADDED Requirements

### Requirement: Persist course-evaluation domain objects in OpenRegister

The system MUST persist `EvaluationCampaign`, `EvaluationInvitation`, `CourseEvaluationResponse`,
`CourseQualityScore`, and `ImprovementAction` as OpenRegister objects. `EvaluationCampaign` MUST carry
`x-openregister-lifecycle` (`draft → open → closed → archived`). `CourseEvaluationResponse` MUST be
`appendOnly: true` and carry its own `x-openregister-lifecycle` (`draft → submitted`, terminal).
`ImprovementAction` MUST carry `x-openregister-lifecycle` (`planned → in-progress → done | dropped`).
`EvaluationInvitation` and `CourseQualityScore` are system-provisioned/materialised rows and carry no
user-initiated lifecycle. `Course`/`Cohort` are referenced by `$ref` only — this requirement does not
modify either schema.

#### Scenario: Course-evaluation objects persist in OpenRegister with the correct lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON and by PHPUnit schema-validation tests. No scholiq DOM surface to drive registration itself. -->

- **GIVEN** the `course-evaluation` schemas are registered in OpenRegister
- **WHEN** an `EvaluationCampaign`, `EvaluationInvitation`, `CourseEvaluationResponse`,
  `CourseQualityScore`, or `ImprovementAction` is created
- **THEN** it is stored as an OpenRegister object with its declared lifecycle (or no lifecycle, for the two
  system-provisioned schemas)
- **AND** `CourseEvaluationResponse` is `appendOnly: true`

### Requirement: A campaign scopes its courses/cohorts, academic period, and instrument

`EvaluationCampaign` MUST record which `Course`(s)/`Cohort`(s) it evaluates (`courseIds[]`/`cohortIds[]`,
at least one populated), `academicYear`/`period` (matching `Cohort.academicYear`/`period`'s shape,
`lib/Settings/scholiq_register.json:3191-3202`), and an `instrumentKind` of `built-in`
(the default — a `questions[]` array of `{questionId, text: {nl, en}, kind: likert-5|free-text,
required}` that Scholiq owns end-to-end) or `external-form` (an `externalFormUrl` pointing learners at a
supplementary Nextcloud Forms survey that Scholiq does NOT ingest, read, or aggregate — a convenience link
only, per design.md Decision 1). `anonymityPolicy` MUST be present and fixed to `fully-anonymous` — it is
documentation of a platform invariant, not a per-campaign configurable toggle.

#### Scenario: A campaign scopes one or more courses with a built-in instrument

<!-- @e2e exclude Pure OpenRegister schema field validation; no scholiq DOM surface for the schema shape itself — covered by the manifest-page e2e scenario below. -->

- **GIVEN** a coordinator authoring an `EvaluationCampaign` for a Course
- **WHEN** they set `courseIds` to that Course's UUID, `instrumentKind: built-in`, and a non-empty
  `questions[]`
- **THEN** the campaign persists with those fields
- **AND** `anonymityPolicy` is `fully-anonymous`

#### Scenario: An external-form campaign links out without ingesting responses

<!-- @e2e exclude Pure OpenRegister schema field validation and an explicit non-behaviour (no ingestion code path exists to test); no scholiq DOM surface. -->

- **GIVEN** a coordinator sets `instrumentKind: external-form` and an `externalFormUrl`
- **WHEN** the campaign is saved and opened
- **THEN** learners are shown the linked URL
- **AND** no `CourseEvaluationResponse` is created or updated by Scholiq as a result of anything submitted
  through that external form

### Requirement: A response is anonymous by schema shape, not by RBAC

`CourseEvaluationResponse` MUST NOT declare any property that identifies the responding learner (no
`learnerId`, `submittedBy`, or equivalent) — anonymity MUST be enforced by the absence of such a property
in the schema, not by an `x-property-rbac` rule hiding an existing one. The `draft → submitted` transition
MUST require a `CourseEvaluationEligibilityGuard` that resolves the caller's identity server-side via
`IUserSession` (never from the request payload), and MUST NOT write that identity onto the
`CourseEvaluationResponse` object at any point.

#### Scenario: A submitted response carries no learner-identifying field

<!-- @e2e exclude Schema-shape absence is verified by reading the register JSON (no learnerId/submittedBy property exists) and by PHPUnit asserting the guard/handler never read from or wrote such a key onto the response payload; no scholiq DOM surface distinguishes "field absent" from "field present but empty". -->

- **GIVEN** a learner submits a `CourseEvaluationResponse` for an open campaign they are eligible for
- **WHEN** the response transitions `draft → submitted`
- **THEN** the stored object contains `answers`, `overallScore`, `campaignId`, `courseId`, and
  `submittedAt`, and no field naming the learner
- **AND** no scholiq code path ever writes the caller's NC user id onto that object

### Requirement: Eligibility and duplicate-submission are blocked by a lifecycle guard

The `CourseEvaluationEligibilityGuard` MUST block the `draft → submitted` transition unless the caller
(resolved via `IUserSession`) holds an `EvaluationInvitation` for the same campaign with
`hasResponded: false`. This structurally prevents both an uninvited submission and a second submission
from the same learner for the same campaign, mirroring `ConferenceSignupGuardianGuard`'s
identity-resolved-via-session, looked-up-against-a-different-schema shape
(`lib/Lifecycle/ConferenceSignupGuardianGuard.php`).

#### Scenario: A learner without an invitation cannot submit

<!-- @e2e exclude Lifecycle-transition guard is backend logic; verified by PHPUnit CourseEvaluationEligibilityGuardTest::testNoInvitationBlocksSubmit. No scholiq DOM surface for the guard itself. -->

- **GIVEN** a learner with no `EvaluationInvitation` for a given campaign
- **WHEN** they attempt to transition a `CourseEvaluationResponse` for that campaign to `submitted`
- **THEN** the transition is refused

#### Scenario: A learner cannot submit a second response for the same campaign

<!-- @e2e exclude PHPUnit CourseEvaluationEligibilityGuardTest::testAlreadyRespondedBlocksSecondSubmit; backend guard behaviour, no DOM surface. -->

- **GIVEN** a learner whose `EvaluationInvitation` for a campaign already has `hasResponded: true`
- **WHEN** they attempt to submit a second `CourseEvaluationResponse` for that campaign
- **THEN** the transition is refused

### Requirement: A successful submission flips the invitation without linking to the response

`CourseEvaluationResponseSubmittedHandler` MUST listen for `CourseEvaluationResponse`'s `submit`
transition and, using the same session-resolved caller identity the guard used, update that learner's
`EvaluationInvitation` to `hasResponded: true` and `respondedAt` to the current time.
`EvaluationInvitation` MUST NOT gain a field referencing which `CourseEvaluationResponse` satisfied it.

#### Scenario: Submitting flips the caller's own invitation, not anyone else's

<!-- @e2e exclude Listener side-effect is backend logic; verified by PHPUnit CourseEvaluationResponseSubmittedHandlerTest::testFlipsCallersOwnInvitationOnly, mirroring GradeRollupHandlerTest's find-and-update assertions. No scholiq DOM surface. -->

- **GIVEN** a learner with an eligible, not-yet-responded `EvaluationInvitation` for a campaign
- **WHEN** they submit a `CourseEvaluationResponse` for that campaign
- **THEN** their own `EvaluationInvitation` becomes `hasResponded: true` with a `respondedAt` timestamp
- **AND** no other learner's `EvaluationInvitation` for the same campaign is affected
- **AND** the `EvaluationInvitation` row gains no reference to the submitted response's identity or content

### Requirement: Non-responder reminders reuse the verified notification dialect's scheduled+filter shape

`EvaluationInvitation` MUST declare a `reminder` rule under `x-openregister-notifications` using
`trigger.type: scheduled` with a `filter` of `hasResponded: false` and `campaignClosesAt` `withinNext`
a fixed lead window, on the `nc-notification` channel, with `recipients: [{kind: field, field:
learnerId}]` — the same shape `Enrolment.dueReminder` already uses
(`lib/Settings/scholiq_register.json:1736-1761`). No scholiq `TimedJob` MUST be used for this.

#### Scenario: A non-responder is reminded ahead of the campaign closing

<!-- @e2e exclude Declarative notification dispatch is OpenRegister's AnnotationNotificationDispatcher (app id openregister); scholiq only declares the verified-dialect rule, asserted by the register-validation suite. No scholiq DOM surface drives NC notification fan-out. -->

- **GIVEN** an `EvaluationInvitation` with `hasResponded: false` and `campaignClosesAt` within the declared
  lead window
- **WHEN** the scheduled evaluation runs
- **THEN** OpenRegister delivers an `nc-notification` to the learner resolved from `learnerId`

#### Scenario: A responder receives no reminder

<!-- @e2e exclude Declarative filter exclusion is OpenRegister dispatcher behaviour; no scholiq DOM surface. -->

- **GIVEN** an `EvaluationInvitation` with `hasResponded: true`
- **WHEN** the scheduled evaluation runs
- **THEN** no reminder is delivered for that invitation

### Requirement: Course/teacher quality scores are a declared aggregation and calculation engine, not a TimedJob

`CourseQualityScore` (one row per `courseId`/`teacherId` (nullable)/`academicYear`/`period`) MUST derive
`responseCount` and `invitationCount` via plain `x-openregister-aggregations` `count` metrics (the only
proven declarative aggregation metric in this register — a full-file grep of `scholiq_register.json` for
`"metric":` shows no `avg`/`sum` usage anywhere), and `averageOverallScore`/`responseRate` via an
`engine`-keyed `x-openregister-calculations` entry (`CourseQualityScoreEvaluator`), mirroring
`FinalGrade.value`'s `GradeFormulaEvaluator` shape (`lib/Settings/scholiq_register.json:5830-5849`).
Recompute MUST fire via `CourseQualityScoreRollupHandler` listening for `CourseEvaluationResponse`'s
`submit` transition (mirrors `GradeRollupHandler`), NOT a `TimedJob`.

#### Scenario: A new response recomputes the course's quality score

<!-- @e2e exclude Calculation + trigger behaviour is backend logic verified by PHPUnit (CourseQualityScoreEvaluatorTest, CourseQualityScoreRollupHandlerTest); no DOM surface for a declared calculation firing. -->

- **GIVEN** a Course with two prior `submitted` `CourseEvaluationResponse`s (`overallScore` 4 and 5) and a
  `CourseQualityScore` row showing `averageOverallScore: 4.5`
- **WHEN** a third response (`overallScore: 3`) is submitted for the same course/period
- **THEN** `CourseQualityScoreRollupHandler` recomputes the matching `CourseQualityScore` row
- **AND** `averageOverallScore` becomes 4 and `responseCount` becomes 3

#### Scenario: Response rate reflects invitations, not just responses

<!-- @e2e exclude PHPUnit CourseQualityScoreEvaluatorTest::testResponseRateDividesByInvitationCount; backend calculation, no DOM surface. -->

- **GIVEN** a course/period with 20 `EvaluationInvitation`s and 5 `submitted` `CourseEvaluationResponse`s
- **WHEN** the `CourseQualityScore` for that course/period recomputes
- **THEN** `responseRate` is `0.25`

### Requirement: The evaluation cycle closes the loop with a recorded improvement action

`ImprovementAction` MUST record a governance reviewer's (opleidingscommissie/vaksectie) findings and a
resulting action against a campaign/course, with a `targetPeriod` naming when the action should show
results, and a `planned → in-progress → done | dropped` status — purely declarative CRUD via manifest
pages, no PHP class required.

#### Scenario: A reviewer records an improvement action against a campaign's results

<!-- @e2e tests/e2e/spec-coverage/course-evaluation.spec.ts -->
<!-- Declarative manifest CRUD is the drivable DOM scenario for this requirement; no lifecycle guard or calculation engine sits behind it. -->

- **GIVEN** a closed `EvaluationCampaign` with a `CourseQualityScore` showing a declining trend
- **WHEN** a reviewer creates an `ImprovementAction` with `findings`, `actionDescription`, and
  `targetPeriod` set, referencing that campaign
- **THEN** the action persists with `status: planned`
- **AND** it is visible on the course's quality-report page

### Requirement: Frontend is declarative with one named custom view for the quality report

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `EvaluationCampaign`,
`CourseEvaluationResponse` (read-only, respecting `appendOnly`), and `ImprovementAction`.
`EvaluationInvitation` and `CourseQualityScore` get no dedicated authoring UI (system-provisioned and
materialised, respectively). The only custom Vue component MUST be `CourseQualityReport.vue` — a
coordinator/opleidingscommissie view of a course or teacher's `CourseQualityScore` trend over time,
response rate, and raw free-text answers, with a link to draft an `ImprovementAction`. No PHP CRUD
controllers.

#### Scenario: A coordinator opens the course quality report and sees the score trend

<!-- @e2e tests/e2e/spec-coverage/course-evaluation.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception (CourseQualityReport) is the drivable DOM scenario, mirroring school-year-rollover's wizard-page e2e coverage pattern; the underlying guard/handler/evaluator logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** a `CourseQualityScore` row exists for a course across two periods
- **WHEN** the coordinator opens the course quality report
- **THEN** they see the score trend, response rate, and a list of raw free-text answers for that course
- **AND** a link to draft an `ImprovementAction` for that course
