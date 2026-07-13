# Tasks: adaptive-release-and-prerequisites

## 1. Schema

- [ ] 1.1 Add `prerequisiteCourseIds` to `Course` in `lib/Settings/scholiq_register.json` (`:810+` region):
  array of `{type: string, format: uuid, $ref: "Course"}`, `default: []`, English `title`/`description`
  per ADR-011. Purely additive; do not touch `required`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-course-declares-prerequisite-courses-via-a-relation-not-a-separate-prerequisite-entity`
  - **acceptance_criteria**:
    - Existing `Course` rows validate unchanged (`prerequisiteCourseIds` absent/`[]`)
    - A `Course` can declare one or more prerequisite `Course` UUIDs
- [ ] 1.2 Add `releaseConditions` and `availableAfterDays` to `Lesson` (`:1080+` region): `releaseConditions`
  — array of `{kind: enum(lesson-completed, assessment-min-score), lessonId?: uuid $ref Lesson,
  assessmentId?: uuid $ref Assessment, minScore?: number}`, `default: []`; `availableAfterDays` — nullable
  integer, `minimum: 0`. English `title`/`description` on every new property (per ADR-011, and per this
  worktree's own precedent of documenting per-learner-vs-shared-object semantics directly in the field
  description, e.g. `CurriculumPlan.passRules`/`Assessment.availableFrom`). Purely additive.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date`
  - **acceptance_criteria**:
    - Existing `Lesson` rows validate unchanged (`releaseConditions` absent/`[]`, `availableAfterDays` `null`)
    - A `Lesson` can declare `releaseConditions` and/or `availableAfterDays` without touching `required`
- [ ] 1.3 Add `releaseConditions` and `availableAfterDays` to `Assessment` (`:4640+` region), identical shape
  to task 1.2, layered on top of the existing `availableFrom`/`availableUntil`/`isAvailable` block
  (unchanged).
  - **spec_ref**: `specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions`, `specs/assessment/spec.md#requirement-assessment-supports-drip-release-relative-to-each-learners-own-enrolment-date`
  - **acceptance_criteria**:
    - Existing `Assessment` rows validate unchanged
    - `isAvailable`'s existing expression (`:4884+`) is untouched — the new fields are evaluated separately
      by `LessonReleaseEvaluator`, not folded into the materialised calculation

## 2. Backend — enrolment prerequisite guard

- [ ] 2.1 Add `OCA\Scholiq\Listener\EnrolmentPrerequisiteListener` (SPDX docblock; `@spec` tag): subscribes
  to OpenRegister's `ObjectCreatingEvent`, filters to the `enrolment` schema (mirror
  `apps-extra/decidesk/lib/Listener/SubmissionDeadlineListener.php`'s schema-slug-resolution shape exactly),
  resolves `courseId` from the creating payload, loads the `Course`, and for each UUID in
  `prerequisiteCourseIds` checks for an `Enrolment{learnerId, courseId: <prereq>, lifecycle: completed,
  tenant_id}` scoped to the same tenant (mirror `XapiCompletionHandler`'s tenant-scoped `findAll` filter
  shape). On any unmet prerequisite: `$event->setErrors([...])` naming the failing course by name +
  `$event->stopPropagation()`. On an infrastructure error during the lookup: catch, log a warning, and allow
  (fail open on infra faults, fail closed only on a successfully-checked unmet prerequisite — same
  documented split as `SubmissionDeadlineListener`).
  - **spec_ref**: `specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence`
  - **acceptance_criteria**:
    - Unit tests cover: unmet prerequisite blocks with the correct course name in the error; met
      prerequisite (completed `Enrolment` exists) allows; no `prerequisiteCourseIds` allows unaffected;
      simulated `ObjectService` failure during lookup allows (fail-open) and logs a warning
- [ ] 2.2 Register `EnrolmentPrerequisiteListener` on `ObjectCreatingEvent` in `lib/AppInfo/Application.php`
  alongside the app's existing `registerEventListener()` calls.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence`
  - **acceptance_criteria**:
    - Listener fires on `Enrolment` creation in an integration/manual smoke check

## 3. Backend — release evaluator + controller

- [ ] 3.1 Add `OCA\Scholiq\Release\LessonReleaseEvaluator` (ADR-031 stateless-service exception; SPDX; `@spec`
  tags): `evaluate(array $item, string $itemSchema, string $learnerId, array $enrolment): array{available:
  bool, reason: ?string, availableAt: ?string}`. Checks, in order: (a) `availableAfterDays` against
  `$enrolment['created'] ?? $enrolment['dateCreated']`-equivalent (verify the exact OR `ObjectEntity`
  metadata key exposed through `ObjectService`/`jsonSerialize()` before wiring this — do not assume
  `@self.created` calc-DSL syntax works, since no existing calculation in this register uses it); (b) each
  `releaseConditions` entry — `lesson-completed` via a tenant-scoped `XapiStatement` lookup mirroring
  `XapiCompletionHandler`'s own query shape (`lessonId`, `verified_actor_id`, `verb` in the completion-verb
  list), `assessment-min-score` via a tenant-scoped `AssessmentResult` lookup (`assessmentId`, `learnerId`,
  `lifecycle: graded`), summing `responses[].autoScore ?? responses[].manualScore` and comparing to
  `minScore`. Returns the first unmet reason found, or `available: true`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date`, `specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions`, `specs/assessment/spec.md#requirement-assessment-supports-drip-release-relative-to-each-learners-own-enrolment-date`
  - **acceptance_criteria**:
    - Unit tests cover: no conditions/no delay → available; unmet `lesson-completed` → unavailable with
      reason; met `lesson-completed` → available; unmet/met `assessment-min-score` (score sum, `autoScore`
      vs `manualScore` fallback, `null` responses); unmet/met `availableAfterDays` (including the
      two-different-enrolment-dates case); `Assessment`'s absolute `availableFrom`/`availableUntil` window
      combined correctly with the new per-learner gates (both must pass)
- [ ] 3.2 Add `OCA\Scholiq\Controller\LessonReleaseController` (mirrors
  `LtiToolPlacementController`'s auth/resolution shape): `#[NoAdminRequired]` `status(string $lessonId)` and
  `assessmentStatus(string $assessmentId)`. Each resolves the caller's identity, verifies the caller holds an
  `Enrolment` for the item's `courseId` (or an admin/teacher role) — 403 otherwise — resolves that
  `Enrolment`, calls `LessonReleaseEvaluator`, and returns `{available, reason, availableAt}` only (never the
  raw `releaseConditions` configuration or another learner's data).
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions`
  - **acceptance_criteria**:
    - Unit tests cover: enrolled learner gets a real decision; non-enrolled non-admin caller gets 403;
      admin/teacher caller gets a real decision regardless of their own enrolment
- [ ] 3.3 Register `['name' => 'lessonRelease#status', 'url' => '/api/lessons/{lessonId}/release-status',
  'verb' => 'GET']` and `['name' => 'lessonRelease#assessmentStatus', 'url' =>
  '/api/assessments/{assessmentId}/release-status', 'verb' => 'GET']` in `appinfo/routes.php`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`
  - **acceptance_criteria**:
    - Both routes resolve to `LessonReleaseController` methods; no 404/ReflectionException

## 4. Frontend

- [ ] 4.1 `src/views/LessonPlayer.vue`: before rendering any `contentType` (text, video, scorm12/2004, cmi5,
  lti, quiz), call the relevant release-status endpoint (`lessonId` for a `Lesson`, or the `assessmentId` it
  resolves to for an assessment-backed lesson). When `available: false`, render a locked state showing
  `reason` and, if present, `availableAt` — do not mount the underlying content-type renderer (including not
  initiating the LTI launch delegation call for `contentType: lti`).
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date`
  - **acceptance_criteria**:
    - Locked state renders and blocks content mounting when unavailable; normal rendering proceeds
      unaffected when `available: true` (covers the existing, unconditioned lessons — no regression)
- [ ] 4.2 Verify `Course`'s manifest create/edit form (`src/manifest.json`) surfaces `prerequisiteCourseIds`
  through the same generic multi-select relation-field renderer already used for
  `CurriculumPlan.requiredCourseIds`/`Course.programmeIds`; verify `Lesson`'s and `Assessment`'s forms
  surface `availableAfterDays` as a plain number input. Only add bespoke manifest widget config if the
  generic renderer does not already cover an array-of-`$ref` or a nullable-integer field (it is expected to,
  per existing precedent — this task is a verification step, not presumed new manifest work).
  - **spec_ref**: `specs/course-management/spec.md#requirement-course-declares-prerequisite-courses-via-a-relation-not-a-separate-prerequisite-entity`
  - **acceptance_criteria**:
    - An instructional designer can set `prerequisiteCourseIds` and `availableAfterDays` through the
      existing forms with no new custom view
- [ ] 4.3 `releaseConditions` (array of objects with a `kind` discriminator) on `Lesson`/`Assessment`: verify
  whether the generic manifest form renderer handles this shape out of the box (it is a new, more complex
  shape than any array-of-scalar-`$ref` field seen elsewhere in this register); if not, add the minimal
  manifest widget config needed to add/edit `releaseConditions` entries — do not build a bespoke modal
  unless the generic renderer genuinely cannot express it.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions`
  - **acceptance_criteria**:
    - An instructional designer can add/edit/remove `releaseConditions` entries through the Lesson and
      Assessment forms

## 5. Tests and docs

- [ ] 5.1 PHPUnit for `EnrolmentPrerequisiteListener`, `LessonReleaseEvaluator`, `LessonReleaseController`
  per the acceptance criteria in tasks 2.1, 3.1, 3.2 (minimum 75% coverage for new code per ADR-009).
  - **spec_ref**: all requirements in this change
  - **acceptance_criteria**: all PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 5.2 Add `tests/e2e/spec-coverage/adaptive-release.spec.ts` (Playwright) covering the `@e2e`-tagged
  scenarios in `specs/course-management/spec.md`: `lesson-locked-until-prerequisite-lesson-completed`,
  `lesson-unlocks-once-prerequisite-lesson-completed`, `lesson-locked-until-drip-delay-elapses` — a seeded
  learner opens a gated `LessonPlayer` route and the test asserts the locked/unlocked DOM state, mirroring
  `school-year-rollover.spec.ts`'s render-and-assert-no-fatal-error pattern plus explicit locked-state text
  assertions.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`, `specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date`
  - **acceptance_criteria**: test passes against a seeded dev instance; matches the `@e2e` references in the
    spec scenarios
- [ ] 5.3 Add Dutch and English translations for all new i18n keys introduced by the `LessonPlayer.vue`
  locked-state UI (ADR-005). No hardcoded strings.
  - **spec_ref**: `specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions`
  - **acceptance_criteria**: no hardcoded strings; `nl`/`en` both populated

## 6. Verify

- [ ] 6.1 `openspec validate adaptive-release-and-prerequisites --strict` clean; PHPUnit green for all three
  new PHP classes; Playwright `adaptive-release.spec.ts` green; no dangling `$ref`s in the register JSON;
  `EnrolmentPrerequisiteListener`'s fail-closed-on-rule / fail-open-on-infra split re-verified against the
  seeded fixtures.
  - **spec_ref**: all requirements in this change
  - **acceptance_criteria**: strict validation + full test suite green; the prerequisite block and the
    release/drip gates are each re-verified end-to-end against a live seeded dev instance, not PHPUnit alone
