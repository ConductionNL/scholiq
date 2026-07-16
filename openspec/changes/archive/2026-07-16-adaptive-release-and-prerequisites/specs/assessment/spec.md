## ADDED Requirements

### Requirement: Assessment declares per-learner release conditions

The `Assessment` object MUST support a `releaseConditions` field: an array of condition objects, each with
a `kind` (`lesson-completed` | `assessment-min-score`), and — depending on `kind` — a `lessonId` (`$ref
Lesson`, cross-referencing the `course-management` capability's schema), an `assessmentId` (`$ref
Assessment`), and/or a `minScore` (number). The field MUST be additive (default `[]`) and AND-combined,
mirroring the `course-management` capability's equivalent `Lesson.releaseConditions` requirement exactly.
An `assessment-min-score` condition MUST be satisfied by a `graded` `AssessmentResult` for the referenced
`Assessment` and the evaluating learner whose summed item scores (`responses[].autoScore` falling back to
`responses[].manualScore`, summed across all items) meet or exceed `minScore`. This score sum MUST be
computed directly by `LessonReleaseEvaluator` at evaluation time — it MUST NOT depend on a `GradeEntry`
having been created or soft-published, since coupling per-learner release-gating to the grading capability's
soft-publish review workflow is out of scope for this requirement (a candidate follow-up, not a dependency).
`releaseConditions` gating is layered ON TOP of `Assessment`'s existing `availableFrom`/`availableUntil`
absolute window and its materialised `isAvailable` calculation (unchanged) — an `Assessment` is available to
a given learner only when both the absolute window and every listed `releaseConditions` entry are satisfied.
An `Assessment` with an empty/absent `releaseConditions` array is gated by the absolute window alone,
matching today's behaviour.

#### Scenario: An assessment is unavailable until a minimum score on a prior assessment is met

<!-- @e2e exclude Score-summation and graded-lifecycle behaviour verified by PHPUnit against LessonReleaseEvaluator; the equivalent UI-locked-state rendering path is covered end-to-end by the course-management capability's Lesson release-condition scenarios, which exercise the same LessonPlayer gating call and evaluator. -->

- **GIVEN** an `Assessment` B with `releaseConditions: [{kind: "assessment-min-score", assessmentId: <Assessment A's id>, minScore: 60}]`
- **AND** a learner whose graded `AssessmentResult` for Assessment A sums to less than 60
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports Assessment B as unavailable, naming the unmet minimum-score condition

#### Scenario: An assessment unlocks once the learner meets the minimum score on the prior assessment

<!-- @e2e exclude Score-summation verified by PHPUnit against LessonReleaseEvaluator; no distinct DOM surface beyond the Lesson scenarios already covering LessonPlayer's gating call. -->

- **GIVEN** the same `Assessment` B from the scenario above
- **AND** the learner's graded `AssessmentResult` for Assessment A now sums to 60 or more
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports Assessment B as available (subject to its own absolute `availableFrom`/`availableUntil`
  window, unchanged)

### Requirement: Assessment supports drip release relative to each learner's own enrolment date

The `Assessment` object MUST support an `availableAfterDays` field: a nullable, non-negative integer
declaring the number of days after the learner's OWN `Enrolment.created` timestamp (for the assessment's
`courseId`) before the assessment becomes available to that learner, mirroring the `course-management`
capability's equivalent `Lesson.availableAfterDays` requirement exactly. `availableAfterDays` MUST NOT be
materialised as a schema-level calculated field, for the same reason `Lesson.availableAfterDays` cannot be:
the resolved per-learner instant differs per learner sharing the same `Assessment` row. When set, this gate
applies in addition to both the existing absolute `availableFrom`/`availableUntil` window and any
`releaseConditions` — an `Assessment` is available to a learner only once all three are satisfied.

#### Scenario: An assessment is locked until N days after the learner's own enrolment date, even within its absolute availability window

<!-- @e2e exclude Per-learner date arithmetic verified by PHPUnit against LessonReleaseEvaluator; the equivalent UI-locked-state rendering path is covered by the course-management capability's Lesson drip scenarios, which exercise the same LessonPlayer gating call and evaluator. -->

- **GIVEN** an `Assessment` with `availableFrom` already in the past (its absolute window is open) and
  `availableAfterDays: 7`
- **AND** a learner whose `Enrolment` for the course was created 3 days ago
- **WHEN** `LessonReleaseEvaluator` evaluates availability for that learner
- **THEN** it reports the assessment as unavailable, naming the remaining drip delay (4 days), despite the
  absolute window already being open
