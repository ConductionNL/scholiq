# Grading — Scheduled Visibility Window Delta

**Spec refs**: `grading`, ADR-031

## MODIFIED Requirements

### Requirement: Persist grading domain objects in OpenRegister

The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects. `GradeEntry` has
`x-openregister-lifecycle` (concept → published → revised), a declared `x-openregister-notifications`
`gradePublished` rule addressed at the learner (`recipients: [{kind: "field", field: "learnerId"}]`,
verified dialect per `scholiq-notifications`), and a nullable `visibleFrom` date-time field marking the
earliest moment that rule is eligible to fire. `visibleFrom` may be set explicitly by the teacher as part
of the publish action (an override); when left unset at publish time it MUST be resolved from the
governing `CurriculumPlan`'s `gradeVisibilityPolicy` (a nullable `{mode, time, timezone}` object — `null`
resolves to `mode: "immediate"`, preserving today's behaviour) and persisted back onto the entry.
`FinalGrade` is computed via `x-openregister-calculations` + cross-schema aggregation over the learner's
published `GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights, and this
computation is unaffected by `visibleFrom` — it still recomputes at `publish`, not at `visibleFrom`.

#### Scenario: Grading objects persisted in OpenRegister

- **GIVEN** the grading domain schemas are registered
- **WHEN** a `GradeEntry` is published for a learner
- **THEN** `GradeScale`, `GradeEntry`, and `FinalGrade` are stored as OpenRegister objects and the
  `FinalGrade` is computed via `x-openregister-calculations` over the learner's published entries,
  immediately, regardless of `visibleFrom`

#### Scenario: GradeEntry schema carries a scheduled visibility window

- **GIVEN** a `GradeEntry` schema definition
- **THEN** it has a nullable `visibleFrom` date-time property
- **AND** it has an `x-openregister-notifications.gradePublished` rule whose recipient is
  `{kind: "field", field: "learnerId"}`

**Resolved (DEFERRED_QUESTIONS #1)**: OpenRegister's `scheduled` trigger does not support binding
its fire time to a schema field — confirmed by inspecting the shipped engine
(`ScheduledNotificationJob`/`ScheduledFilterEvaluator`): a `scheduled` rule only supports a
60-second-minimum `intervalSec` poll plus an operator-aware `filter` map (`equals`, `notEquals`,
`withinNext`, `olderThan`, evaluated against each object's live field data). The fallback path
from DEFERRED_QUESTIONS #1 is therefore the implemented shape: `GradeEntry.gradePublished` and
`GradeNotification.gradePublished` both declare `trigger: {type: "scheduled", intervalSec: 300,
filter: {visibleFrom: {operator: "olderThan", value: "PT0S"}, ...}}` — `olderThan` with a
zero-duration threshold matches exactly the entries whose `visibleFrom` has passed. `GradeEntry`'s
filter additionally scopes on `lifecycle: "published"` so a `revised`/`concept` row is never
matched. `GradeNotification.idempotencyKey` plus the dispatcher's own per-`(schema, notification,
object)` dedup state (fingerprinted on the watched `visibleFrom` field) together prevent duplicate
delivery across the 5-minute poll and across a re-publish that resolves an unchanged `visibleFrom`.

#### Scenario: CurriculumPlan supplies the default visibility policy when a teacher does not override

- **GIVEN** a `CurriculumPlan` with `gradeVisibilityPolicy: {mode: "nextSchoolDay", time: "10:00",
  timezone: "Europe/Amsterdam"}`
- **WHEN** a teacher publishes a `GradeEntry` under that plan without setting `visibleFrom` explicitly
- **THEN** the entry's `visibleFrom` is resolved and persisted as the next non-weekend day at 10:00
  Europe/Amsterdam following the publish moment

### Requirement: Notification dispatch honours per-parent/per-18+-learner preference

Notification dispatch MUST honour per-parent / per-18+-learner preference (instant vs daily digest),
backed by a `NotificationPreference` schema or the existing OR notification-preference mechanism
(whichever OR exposes). Dispatch additionally MUST NOT occur before the triggering `GradeEntry`'s resolved
`visibleFrom`, regardless of the recipient's instant-vs-digest preference — the preference controls
batching/timing of an already-eligible notification, not whether the visibility window has opened yet.

#### Scenario: Dispatch respects recipient preference

- **GIVEN** a parent has set "daily digest" and an 18+ learner has set "instant"
- **WHEN** grades publish for that learner and their `visibleFrom` has already passed
- **THEN** the parent receives one batched digest notification and the learner receives an instant
  notification, each according to their own preference

#### Scenario: Night publish defers notification to the resolved visibleFrom

- **GIVEN** a teacher batch-publishes a cohort's `GradeEntry`s at 23:40 under a `CurriculumPlan` whose
  `gradeVisibilityPolicy.mode` is `nextSchoolDay` at `10:00`
- **WHEN** the publish transition completes
- **THEN** no `nc-notification` is delivered to any learner or parent that night
- **AND** the learner's and parents' notifications (per their own instant/digest preference) are eligible
  to fire only once `visibleFrom` (the next school day, 10:00) has passed

#### Scenario: Teacher overrides the default visibility window

- **GIVEN** a teacher batch-publishes a cohort's `GradeEntry`s and explicitly sets `visibleFrom` to
  "right now" as part of the publish action
- **WHEN** the publish transition completes
- **THEN** the explicit override is used instead of the `CurriculumPlan.gradeVisibilityPolicy` default
- **AND** dispatch proceeds immediately (subject to each recipient's instant/digest preference)

### Requirement: Roll-up is a declared calculation, not a TimedJob

The roll-up MUST NOT be a PHP TimedJob — it MUST be a declared calculation that re-fires on `GradeEntry`
publish (the `calculatedChange` trigger feature). The only PHP exceptions allowed: a stateless
`GradeFormulaEvaluator` invoked by the calculation engine if a formula can't be expressed in JSON-logic,
and a stateless `GradeVisibilityResolver` invoked once per publish to resolve an unset `visibleFrom` from
the `CurriculumPlan.gradeVisibilityPolicy` (ADR-031 "calculation engine above schema metadata" — neither
exception introduces a TimedJob or polling loop of its own).

#### Scenario: Roll-up re-fires on publish without a TimedJob

- **GIVEN** a learner's `FinalGrade` is derived from a declared calculation
- **WHEN** a `GradeEntry` for that learner transitions to `published`
- **THEN** the `FinalGrade` recomputes via the `calculatedChange` trigger with no PHP TimedJob involved,
  and this recompute is not delayed by `visibleFrom` resolution
