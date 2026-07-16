## ADDED Requirements

### Requirement: Course declares prerequisite courses via a relation, not a separate `Prerequisite` entity

The `Course` object MUST support a `prerequisiteCourseIds` field: an array of `$ref Course` UUIDs
(additive, default `[]`), naming the courses a learner MUST hold a `completed` `Enrolment` for before they
may enrol in this course. This corrects the Data Model claim elsewhere in this spec that a separate
`Prerequisite` OpenRegister entity exists — no such schema exists, was ever built, or is being introduced by
this requirement; the relation is a plain array-of-`$ref` field on `Course`, structurally identical to the
existing `CurriculumPlan.requiredCourseIds`/`electiveCourseIds` and `Course.programmeIds` fields. The field
MUST NOT be required — existing `Course` rows leave it `[]`/absent, and courses with no prerequisites are
unaffected. Enforcement (blocking enrolment when a prerequisite is unmet) is specified in the `enrolment`
capability's "Validate prerequisites before persistence" requirement, not here — this requirement covers
only the relation's existence and shape.

#### Scenario: A course declares one or more prerequisite courses

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface for declaring the relation itself. Consumed by the enrolment capability's EnrolmentPrerequisiteListener, covered by PHPUnit as referenced in that spec. -->

- **GIVEN** an instructional designer authoring a course
- **WHEN** they set `prerequisiteCourseIds` to one or more existing `Course` UUIDs
- **THEN** the value persists on the `Course` object
- **AND** it is available to the `enrolment` capability's prerequisite check

#### Scenario: A course with no declared prerequisites is unaffected

<!-- @e2e exclude Pure OpenRegister schema field; null-handling verified by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a pre-existing `Course` row with `prerequisiteCourseIds` unset (`[]`/absent)
- **WHEN** a learner attempts to enrol
- **THEN** no prerequisite check blocks the enrolment

### Requirement: Lesson declares per-learner release conditions

The `Lesson` object MUST support a `releaseConditions` field: an array of condition objects, each with a
`kind` (`lesson-completed` | `assessment-min-score`), and — depending on `kind` — a `lessonId` (`$ref
Lesson`), an `assessmentId` (`$ref Assessment`, cross-referencing the `assessment` capability's schema),
and/or a `minScore` (number). The field MUST be additive (default `[]`) and AND-combined: a `Lesson` is
available to a learner only when every listed condition is satisfied for that learner. A `lesson-completed`
condition MUST be satisfied by the existence of an `XapiStatement` for the referenced `Lesson` whose
`verified_actor_id` matches the learner and whose `verb` indicates completion or passing. An
`assessment-min-score` condition MUST be satisfied per the `assessment` capability's equivalent requirement
on `Assessment.releaseConditions`. Evaluation MUST happen per-learner at request time via the shared
`LessonReleaseEvaluator` service — it MUST NOT be materialised as a schema-level calculation, because
availability differs per learner while the `Lesson` row is shared across every learner enrolled in the
course. A `Lesson` with an empty/absent `releaseConditions` array is available to every enrolled learner as
soon as it is published, matching today's behaviour. When a learner opens a `Lesson` in `LessonPlayer.vue`
regardless of its `contentType` (`text`, `video`, `scorm12`, `scorm2004`, `cmi5`, `lti`, `quiz`), the system
MUST evaluate `releaseConditions` before rendering the lesson's content and render a locked state naming
the unmet condition when unavailable.

#### Scenario: A lesson is unavailable until its prerequisite lesson is completed

- **GIVEN** a `Lesson` B with `releaseConditions: [{kind: "lesson-completed", lessonId: <Lesson A's id>}]`
- **AND** a learner enrolled in the course who has not completed Lesson A
- **WHEN** the learner opens Lesson B in `LessonPlayer`
- **THEN** the system renders a locked state naming Lesson A as the unmet condition instead of the lesson
  content

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-locked-until-prerequisite-lesson-completed -->

#### Scenario: A lesson unlocks once its prerequisite lesson is completed

- **GIVEN** the same `Lesson` B from the scenario above
- **AND** the learner now holds a completion `XapiStatement` for Lesson A
- **WHEN** the learner opens Lesson B in `LessonPlayer`
- **THEN** the lesson content renders normally

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-unlocks-once-prerequisite-lesson-completed -->

### Requirement: Lesson supports drip release relative to each learner's own enrolment date

The `Lesson` object MUST support an `availableAfterDays` field: a nullable, non-negative integer declaring
the number of days after the learner's OWN `Enrolment.created` timestamp (for the lesson's course) before
the lesson becomes available to that learner. `availableAfterDays` MUST NOT be materialised as a
schema-level calculated field — the resolved per-learner instant (`enrolment.created + N days`) differs per
learner sharing the same `Lesson` row, so only the static duration is stored on the schema; the per-learner
resolution happens in `LessonReleaseEvaluator` at request time, reading the requesting learner's own
`Enrolment`. When set, this gate applies in addition to any `releaseConditions` — a `Lesson` is available
to a learner only once both are satisfied.

#### Scenario: A lesson is locked until N days after the learner's own enrolment date

- **GIVEN** a `Lesson` with `availableAfterDays: 7`
- **AND** a learner whose `Enrolment` for the course was created 3 days ago
- **WHEN** the learner opens the lesson in `LessonPlayer`
- **THEN** the system renders a locked state showing the date it becomes available (4 days from now)

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-locked-until-drip-delay-elapses -->

#### Scenario: Two learners with different enrolment dates see different unlock dates for the same lesson

<!-- @e2e exclude Per-learner date arithmetic verified by PHPUnit against LessonReleaseEvaluator; the single-learner locked-state rendering path is already covered by the scenario above. -->

- **GIVEN** a `Lesson` with `availableAfterDays: 7`
- **AND** learner A enrolled 10 days ago and learner B enrolled 1 day ago
- **WHEN** `LessonReleaseEvaluator` evaluates availability for each
- **THEN** the lesson is available to learner A and unavailable to learner B, from the same `Lesson` row
