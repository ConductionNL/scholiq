# engagement Specification

## Purpose
TBD - created by archiving change engagement-gamification. Update Purpose after archive.
## Requirements
### Requirement: Persist engagement domain objects in OpenRegister

The system MUST persist `PointRule`, `PointAward`, `EngagementLevel`, `LearnerEngagement`, and `Leaderboard`
as OpenRegister objects. `PointRule` and `Leaderboard` MUST carry `x-openregister-lifecycle`
(`draft → active → archived`, mirroring `AttendanceThreshold`). `PointAward` MUST be `appendOnly: true`
(audit-immutable evidence per ADR-008, mirroring `AttendanceFlag`/`AssessmentResult`). `LearnerEngagement`
MUST be declared `x-openregister: {readOnly: true}` (mirroring `FinalGrade`) so it can never be written
directly by the frontend. `EngagementLevel` MUST be tenant-scoped config rows with no lifecycle, mirroring
how `GradeScale.bands[]` values are interpreted but promoted to independently editable rows.

<!-- @e2e exclude Pure OpenRegister schema/lifecycle/appendOnly/readOnly registration; verified by PHPUnit schema-validation tests and by reasoning over the register JSON, mirroring study-progress's identical registration requirement (no scholiq DOM surface to drive registration itself). -->

#### Scenario: Engagement objects persist in OpenRegister with the correct lifecycle and immutability

- **GIVEN** the `engagement` schemas are registered
- **WHEN** a `PointRule`, `PointAward`, `EngagementLevel`, `LearnerEngagement`, or `Leaderboard` is created
- **THEN** each is stored as an OpenRegister object with its declared lifecycle
- **AND** `PointAward` is `appendOnly: true`
- **AND** `LearnerEngagement` is `readOnly: true` and rejects direct frontend writes

### Requirement: Points are awarded only for real, already-firing events

The system MUST award a `PointAward` only in response to a real `ObjectTransitionedEvent` already produced
by existing Scholiq behaviour: `Enrolment` transitioning to `completed` (the transition
`XapiCompletionHandler` already dispatches for mandatory-training completion), `Submission` transitioning to
`submitted` with its materialised `isLate` calculation false, or `GradeEntry` transitioning to `published`/
`republish` where `GradeFormulaEvaluator::evaluate()` returns `passed: true` for the affected
`curriculumPlanId`. The system MUST NOT award points for peer review — peer review/peer grading is not
implemented in the `assignments` capability and is out of scope for this capability until it ships.

<!-- @e2e exclude Backend event-listener logic (PointAwardTriggerHandler); verified by PHPUnit (PointAwardTriggerHandlerTest covering each of the three transitions plus a negative test asserting no PointRule.kind exists for peer review); no scholiq DOM surface for a listener reacting to a lifecycle transition. -->

#### Scenario: Completing mandatory training awards enrolment-completed points

- **GIVEN** an `active` `PointRule` with `kind: enrolment-completed`
- **AND** a learner's `Enrolment` about to transition to `completed` via the existing xAPI completion bridge
- **WHEN** the transition fires
- **THEN** a `PointAward` is created for that learner referencing the rule and the `Enrolment` as
  `sourceObjectId`

#### Scenario: An on-time submission awards submission-on-time points, a late one does not

- **GIVEN** an `active` `PointRule` with `kind: submission-on-time`
- **AND** a learner's `Submission` transitions from `draft` to `submitted` with `isLate: false`
- **WHEN** the transition fires
- **THEN** a `PointAward` is created referencing the `Submission`
- **AND** when a different `Submission` instead transitions with `isLate: true`, no `PointAward` is created
  for that rule

#### Scenario: A passing GradeEntry awards finalgrade-passed points

- **GIVEN** an `active` `PointRule` with `kind: finalgrade-passed`
- **AND** a `GradeEntry` transitions to `published`, and `GradeFormulaEvaluator::evaluate()` for the
  learner's `curriculumPlanId` returns `passed: true`
- **WHEN** the transition fires
- **THEN** a `PointAward` is created referencing the `curriculumPlanId`

#### Scenario: No PointRule.kind exists for peer review

- **GIVEN** the `PointRule.kind` enum
- **WHEN** it is inspected
- **THEN** it contains only `enrolment-completed`, `submission-on-time`, `finalgrade-passed`, and
  `streak-milestone` — no `peer-review` kind, since peer review is not built in `assignments`

### Requirement: PointAward creation is idempotent and immutable

The system MUST NOT create a duplicate `PointAward` for the same `(learnerId, pointRuleId, sourceObjectId)`
triple — before creating an award, the system MUST check for an existing award with that triple and skip
creation if found. Once created, a `PointAward`'s `points` value MUST be copied from `PointRule.points` at
award time and MUST NOT be recomputed or altered by a later edit to the originating `PointRule`.

<!-- @e2e exclude Idempotency-key check and immutability are backend logic verified by PHPUnit PointAwardTriggerHandlerTest::testRepublishDoesNotDuplicateAward and PointAwardTriggerHandlerTest::testRuleEditDoesNotReprisePastAwards; no scholiq DOM surface for this guard. -->

#### Scenario: Republishing a revised GradeEntry does not duplicate the award

- **GIVEN** a learner already holds a `PointAward` for `(learnerId, pointRuleId, curriculumPlanId)`
- **WHEN** the same `GradeEntry` is `revise`d and then `republish`ed, and `GradeFormulaEvaluator` again
  returns `passed: true` for the same `curriculumPlanId`
- **THEN** no second `PointAward` is created for that triple

#### Scenario: Editing a PointRule's points value does not reprice existing awards

- **GIVEN** a learner holds a `PointAward` with `points: 10` from a `PointRule` whose `points` was `10` at
  award time
- **WHEN** an admin edits that `PointRule.points` to `20`
- **THEN** the existing `PointAward.points` remains `10`

### Requirement: Learner totals, level, and streak are computed by a PHP evaluator, not a sum aggregation

The system MUST compute `LearnerEngagement.totalPoints` as the sum of a learner's `PointAward.points`,
`levelId` as the highest `EngagementLevel` whose `minPoints` is at most `totalPoints`, and
`currentStreakDays`/`longestStreakDays` from distinct `PointAward.awardedAt` calendar dates, via a
constructor-injected PHP evaluator (mirroring `GradeFormulaEvaluator`/`BsaProgressEvaluator`) — NOT a
declarative `x-openregister-aggregations` `sum` metric, since no `sum` metric is used anywhere else in this
register (only `count`/`count_distinct` are precedented). Recomputation MUST be triggered by a `PointAward`
`ObjectCreatedEvent`, mirroring `GradeRollupHandler`'s trigger shape — NOT a `TimedJob` (ADR-022).

<!-- @e2e exclude Cross-object sum/streak computation is backend logic verified by PHPUnit PointEngagementEvaluatorTest (totals, level resolution, streak continuation and reset across a date gap); no scholiq DOM surface for the calculation itself. -->

#### Scenario: A new PointAward recomputes totals and level

- **GIVEN** a learner whose `LearnerEngagement.totalPoints` is below an `EngagementLevel`'s `minPoints`
- **WHEN** a new `PointAward` pushes their summed points at or above that threshold
- **THEN** `LearnerEngagement.levelId` updates to that `EngagementLevel`

#### Scenario: A streak milestone awards a bonus PointAward exactly once

- **GIVEN** an `active` `PointRule` with `kind: streak-milestone` and `milestoneDays: 7`
- **AND** a learner whose `currentStreakDays` is about to cross from 6 to 7
- **WHEN** the qualifying `PointAward` that extends the streak to 7 is created
- **THEN** exactly one bonus `PointAward` with `sourceKind: streak-milestone` is created
- **AND** that bonus award's own recomputation does not itself re-trigger a further streak-milestone check

### Requirement: A ranked leaderboard is opt-in per cohort/course, default off, and respects a per-learner opt-out

The system MUST NOT expose any ranked, peer-visible view of learner points unless an `active` `Leaderboard`
row exists for that specific cohort or course — there MUST be no tenant-wide default-on switch and no seed
data that activates ranking automatically. A learner MUST be able to set a standing opt-out preference (via
the existing `preferences-api` `pref_leaderboardOptOut` key) that excludes them from every ranked view while
leaving their own `LearnerEngagement` fully visible to themselves regardless of opt-out state.

<!-- @e2e exclude Policy/gating logic is backend authorization behaviour verified by PHPUnit LeaderboardControllerTest (no active Leaderboard → 404; opted-out learner excluded from ranking; own LearnerEngagement still readable via self-match RBAC regardless of opt-out); the DOM-visible consequence (the LeaderboardView page and the opt-out toggle) is covered by the following requirement's e2e scenario. -->

#### Scenario: No ranking is served without an active Leaderboard

- **GIVEN** a Cohort with no `Leaderboard` row, or one whose `Leaderboard` is `draft`/`archived`
- **WHEN** `LeaderboardController::getRankings()` is called for that cohort
- **THEN** the request is refused (no ranking is returned)

#### Scenario: An opted-out learner is excluded from the ranking but keeps their own view

- **GIVEN** an `active` `Leaderboard` for a Cohort, and a member learner with `pref_leaderboardOptOut` set
- **WHEN** another cohort member requests the ranking
- **THEN** the opted-out learner does not appear in the returned list
- **AND** the opted-out learner's own `LearnerEngagement` (points/level/streak) remains readable to
  themselves via the unchanged self-match RBAC rule

### Requirement: Frontend surfaces a private points/level widget and one opt-in leaderboard view

The frontend MUST be declarative for `PointRule`/`EngagementLevel`/`Leaderboard` admin configuration and
`PointAward`/`LearnerEngagement` read-only detail pages in `src/manifest.json`. The system MUST surface a
learner's own points and level as a KPI widget on the existing student dashboard, reusing
`src/views/widgets/*` KPI-card components per the `dashboard` capability's constraint, visible unconditionally
regardless of any `Leaderboard`/opt-out state. The only custom Vue component MUST be `LeaderboardView.vue` —
rendering `LeaderboardController`'s response for a cohort/course with an active `Leaderboard`, including an
inline toggle that writes the `pref_leaderboardOptOut` preference via the existing `preferences-api`
endpoints. No PHP CRUD controller is added for `PointRule`, `PointAward`, `EngagementLevel`, or
`LearnerEngagement` — only `LeaderboardController`, justified by the RBAC gap in the preceding requirement.

<!-- @e2e tests/e2e/spec-coverage/engagement.spec.ts -->
<!-- Declarative page rendering, the always-visible student KPI widget, and the one custom-view exception (LeaderboardView, including the opt-out toggle) are the drivable DOM scenarios, mirroring study-progress's BsaRiskDashboard e2e coverage pattern; the underlying event/idempotency/computation logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding requirements. -->

#### Scenario: A learner sees their own points and level regardless of leaderboard opt-out

- **GIVEN** a learner with a `LearnerEngagement` row and `pref_leaderboardOptOut` set
- **WHEN** they open their student dashboard
- **THEN** their points/level KPI widget renders their current `totalPoints` and `levelId`

#### Scenario: A cohort member opens an active leaderboard and can opt out from within it

- **GIVEN** an `active` `Leaderboard` for the learner's cohort
- **WHEN** the learner opens `LeaderboardView`
- **THEN** the ranked list renders via `LeaderboardController`
- **AND** the learner can toggle "hide me from this leaderboard", which persists via `preferences-api` and
  removes them from the ranking on the next load

