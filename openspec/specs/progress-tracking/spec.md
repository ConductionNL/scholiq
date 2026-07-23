# progress-tracking Specification

## Purpose
TBD - created by archiving change learning-progress-and-analytics. Update Purpose after archive.
## Requirements
### Requirement: Persist LessonCompletion domain objects in OpenRegister

The system MUST persist `LessonCompletion` as an OpenRegister object — one row per `(learnerId, lessonId)` —
carrying `learnerId`, `learnerRef` (nullable), `lessonId` (`$ref Lesson`), `courseId` (`$ref Course`,
denormalized), `enrolmentId` (nullable `$ref Enrolment`), `source` (`xapi | manual`), `verb` (nullable),
`score` (nullable), `completedAt`, and `tenant_id`. It carries no `x-openregister-lifecycle` — a completion
fact has no workflow states. `x-property-rbac.read` MUST scope to the learner themself, admin, and the
course's teacher(s); it MUST NOT be broadly readable.

#### Scenario: LessonCompletion objects persist in OpenRegister
<!-- @e2e exclude Pure OpenRegister schema/RBAC registration; verified by PHPUnit schema-validation tests and by reasoning over the register JSON (no scholiq DOM surface to drive registration itself). -->

- **GIVEN** the `progress-tracking` schemas are registered in OpenRegister
- **WHEN** a `LessonCompletion` is created for a `(learnerId, lessonId)` pair
- **THEN** it is stored as an OpenRegister object with no lifecycle field
- **AND** a learner who is not the row's own `learnerId`, an admin, or the course's teacher cannot read it

### Requirement: xAPI completion statements are wired into per-lesson completion, not duplicated

The system MUST derive `LessonCompletion` from the same `ObjectCreatedEvent<XapiStatement>` that `Xapi
CompletionHandler` already consumes, via a new sibling listener (`OCA\Scholiq\Listener\LessonProgress
Handler`) — reusing `XapiCompletionHandler`'s existing `verified_actor_id` trust boundary and completion-verb
list rather than re-implementing them. Unlike `XapiCompletionHandler`, this listener MUST NOT gate on
`Lesson.mandatoryTraining` or on being the highest-`order` published `Lesson` of its `Course` — every
resolvable `completed`/`passed` xAPI statement for a `Lesson` MUST produce or update a `LessonCompletion` row,
independent of whether that lesson also happens to trigger an `Enrolment` completion. `XapiCompletionHandler`
itself MUST NOT be modified by this change.

#### Scenario: A non-final, non-mandatory lesson's completion statement is recorded
<!-- @e2e exclude Backend event-listener behaviour verified by PHPUnit (LessonProgressHandlerTest: asserts a LessonCompletion is created for a statement XapiCompletionHandler itself would ignore); no DOM surface for an xAPI ObjectCreatedEvent firing server-side. -->

- **GIVEN** a `Course` with 10 published `Lesson`s, none marked `mandatoryTraining`
- **AND** a learner has an active `Enrolment` in that course
- **WHEN** an `XapiStatement` with verb `completed` is received for lesson 3 of 10
- **THEN** a `LessonCompletion` row is created for `(learner, lesson 3)` with `source: xapi`
- **AND** the learner's `Enrolment.lifecycle` does NOT transition (only the final lesson does that, per
  `XapiCompletionHandler`'s unmodified guards)

#### Scenario: A duplicate completion statement for the same lesson updates, not duplicates
<!-- @e2e exclude Backend idempotency, covered by LessonProgressHandlerTest. -->

- **GIVEN** a learner already has a `LessonCompletion` for a given `Lesson`
- **WHEN** a second `completed`/`passed` `XapiStatement` for the same learner and lesson is received
- **THEN** the existing `LessonCompletion` row is updated (`completedAt` refreshed), not duplicated

### Requirement: Learners can self-report completion of non-xAPI content

The system MUST allow a learner to create their own `LessonCompletion` (`source: manual`) for a `Lesson`
whose `contentType` does not emit xAPI statements (`text`, `video` without an embedded xAPI player, `quiz`
without cmi5 wrapping), mirroring `AssessmentResult`'s unrestricted self-serve create posture rather than the
stricter `xapi-statement` admin-only stopgap — a self-reported progress marker carries no grade or
credentialing weight. The frontend MUST expose a "Mark lesson complete" action on the `Lesson` player for
these content types.

#### Scenario: Learner marks a text lesson complete
- **GIVEN** a learner viewing a published `Lesson` with `contentType: text` and no existing `LessonCompletion`
- **WHEN** they select "Mark lesson complete"
- **THEN** a `LessonCompletion` (`source: manual`) is created for that learner and lesson
- **AND** the action becomes disabled/shows "Completed" on the same view
- **@e2e** tests/e2e/spec-coverage/progress-tracking.spec.ts

#### Scenario: Manual completion is not available for xAPI-instrumented content
- **GIVEN** a learner viewing a published `Lesson` with `contentType: cmi5`
- **WHEN** the lesson player renders
- **THEN** no "Mark lesson complete" action is shown — completion is expected to arrive via the xAPI
  statement the cmi5 launch itself emits
- **@e2e** tests/e2e/spec-coverage/progress-tracking.spec.ts

