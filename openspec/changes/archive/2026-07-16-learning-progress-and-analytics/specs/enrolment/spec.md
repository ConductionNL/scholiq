## ADDED Requirements

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
