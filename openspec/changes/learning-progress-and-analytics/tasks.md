# Tasks: learning-progress-and-analytics

## 1. Schema — progress-tracking capability

- [ ] 1.1 Add `LessonCompletion` to `lib/Settings/scholiq_register.json`: `learnerId`, `learnerRef` (nullable
  `$ref LearnerProfile`), `lessonId` (`$ref Lesson`), `courseId` (`$ref Course`, denormalized), `enrolmentId`
  (nullable `$ref Enrolment`), `source` (`xapi | manual`), `verb` (nullable string), `score` (nullable
  number), `completedAt` (date-time), `tenant_id`. No `x-openregister-lifecycle`. `x-property-rbac.read`
  scoped to self + admin + course teacher.
  - **spec_ref**: `specs/progress-tracking/spec.md#requirement-persist-lessoncompletion-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Schema validates against the OpenAPI 3.0.0 register conventions used elsewhere in the file
    - A non-owning, non-admin, non-teacher user cannot read another learner's `LessonCompletion`

## 2. Schema — Enrolment progress roll-up (MODIFIED delta)

- [ ] 2.1 Add `x-openregister-aggregate-refs.completedLessonCount` (`schema: lesson-completion, metric:
  count, filters: {learnerId: "@self.learnerId", courseId: "@self.courseId"}`) and
  `.totalPublishedLessonCount` (`schema: lesson, metric: count, filters: {courseId: "@self.courseId",
  lifecycle: "published"}`) to `Enrolment` (`lib/Settings/scholiq_register.json:1451-1694` region). Purely
  additive.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up`
  - **acceptance_criteria**:
    - Both aggregate-refs resolve correctly against seeded `LessonCompletion`/`Lesson` fixtures
- [ ] 2.2 Add `progressPercent` (`number`, nullable, "Derived — do not set manually", mirrors
  `FinalGrade.value`'s description) as a plain property on `Enrolment`, plus an `x-openregister-triggers.
  calculatedChange` block documenting the `EnrolmentProgressRollupHandler` wiring (mirrors `FinalGrade`'s own
  `x-openregister-triggers` block).
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up`
  - **acceptance_criteria**:
    - Field present, nullable, no default value baked in

## 3. Schema — student-analytics capability

- [ ] 3.1 Add `EngagementScore` to `lib/Settings/scholiq_register.json`: `learnerId`, `learnerRef` (nullable),
  `courseId` (`$ref Course`), `activityCount` (`x-openregister-aggregate-refs` count against `xapi-statement`
  filtered by `verified_actor_id`/`courseId`), `timeOnTaskMinutes` (number, PHP-written), `lastActivityAt`
  (date-time, PHP-written), `recencyDays` (`materialise: true` `dateDiff` from `lastActivityAt` to `@now`),
  `score` (number 0–100, PHP-written), `tenant_id`. `x-property-rbac.read` scoped to self + admin + course
  teacher.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - `activityCount` and `recencyDays` resolve without any PHP class involved
    - Schema validates against the register's existing conventions
- [ ] 3.2 Add `EngagementRiskThreshold` to `lib/Settings/scholiq_register.json`: `name`, `kind` (`low-
  engagement | generic`), `scope` (`per-learner | per-cohort`), `cohortId` (nullable `$ref Cohort`), `metric`
  (`engagement-score-below | recency-days-above`), `limit`, `onAtRisk` (`notify`/`notifyRoles`/`createFlag`,
  mirrors `AttendanceThreshold.onCross`/`BsaTrajectory.onAtRisk`), `lifecycle` (`draft → active → archived`),
  `tenant_id`. `x-openregister-authorization.create` restricted to `admin`/`coordinator`.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml`
  - **acceptance_criteria**:
    - `kind`/`scope`/`metric` enums match the structural mirror of `AttendanceThreshold`
    - No `Course.ectsCredits` or HE/MBO gating anywhere on this schema
- [ ] 3.3 Add `EngagementRiskFlag` (`appendOnly: true`) to `lib/Settings/scholiq_register.json`: `learnerId`,
  `courseId`, `engagementRiskThresholdId` (`$ref EngagementRiskThreshold`), `engagementScoreId` (`$ref
  EngagementScore`), `metricValueAtFlag`, `flaggedAt`, `lifecycle` (`open → in-handling → resolved`),
  `x-openregister-notifications.flagRaised` (recipients: `notifyRoles`), NL/EN subject. `x-openregister-
  authorization.create` restricted to system (handler-created only).
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml`
  - **acceptance_criteria**:
    - `appendOnly: true`
    - Notification recipients + subject present in both `nl`/`en`

## 4. Backend — progress-tracking handlers

- [ ] 4.1 Add `OCA\Scholiq\Listener\LessonProgressHandler` (SPDX docblock; `@spec` tag referencing the
  wiring requirement): listens for OR's `ObjectCreatedEvent` on `xapi-statement` (same event
  `XapiCompletionHandler` consumes), reuses its completion-verb list and `verified_actor_id` trust boundary,
  resolves the `Lesson` from `payload.object.id` — **no** `mandatoryTraining` or last-lesson gate — and
  upserts `LessonCompletion` for `(verified_actor_id, lessonId)`. `XapiCompletionHandler` itself is not
  modified.
  - **spec_ref**: `specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated`
  - **acceptance_criteria**:
    - Unit tests cover: a non-mandatory, non-last lesson's completion statement creates a `LessonCompletion`
      (a case `XapiCompletionHandler` itself ignores); a duplicate statement for the same learner+lesson
      updates rather than duplicates; a statement with no resolvable `Lesson` is skipped without error
- [ ] 4.2 Add `OCA\Scholiq\Progress\EnrolmentProgressEvaluator` (SPDX; engine-keyed calculation, mirrors
  `GradeFormulaEvaluator`): computes `progressPercent = round(completedLessonCount / totalPublishedLesson
  Count * 100)`, null-safe when either count is `0`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up`
  - **acceptance_criteria**:
    - Unit tests cover: normal ratio; zero completions; zero published lessons (returns `0`, not a
      divide-by-zero error); a ratio that requires rounding
- [ ] 4.3 Add `OCA\Scholiq\Listener\EnrolmentProgressRollupHandler` (SPDX; mirrors `GradeRollupHandler`'s
  shape): listens for `LessonCompletion` writes, resolves the matching active `Enrolment` via `Lesson.
  courseId` + the completion's `learnerId`, recomputes via `EnrolmentProgressEvaluator`, and saves
  `progressPercent` onto the `Enrolment`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up`
  - **acceptance_criteria**:
    - Unit tests cover: a new `LessonCompletion` triggers a recompute; a learner with no active `Enrolment`
      for that course is skipped without error

## 5. Backend — student-analytics handlers

- [ ] 5.1 Add `OCA\Scholiq\Analytics\EngagementScoreEvaluator` (SPDX; engine-keyed calculation): given a
  learner + course, sums each matching `XapiStatement.result` duration extension into `timeOnTaskMinutes`
  (treating a statement with no duration extension as `0`, not an error), sets `lastActivityAt` to the max
  `timestamp`, and computes a bounded `score` (0–100) as a weighted combination of time-on-task (against the
  course's summed `Lesson.durationMinutes`) and recency decay — a plain formula, documented inline, not a
  model.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Unit tests cover: multiple statements summing correctly; a statement missing a duration extension
      contributing 0; `score` bounded to `[0, 100]`; `lastActivityAt` resolves to the latest statement
- [ ] 5.2 Add `OCA\Scholiq\Listener\EngagementSignalHandler` (SPDX; mirrors `BsaProgressFlagHandler`'s
  combined evaluate-then-flag shape): listens for OR's `ObjectCreatedEvent` on `xapi-statement`, calls
  `EngagementScoreEvaluator`, saves the updated `EngagementScore`, then checks active `EngagementRisk
  Threshold`s in scope (per-learner or the learner's `Cohort`) and creates an idempotency-keyed
  `EngagementRiskFlag` when a threshold is crossed and no `open`/`in-handling` flag already exists for that
  learner+threshold.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml`
  - **acceptance_criteria**:
    - Unit tests cover: score recompute always runs; flag created on first crossing; no duplicate flag while
      one is already open; a resolved flag allows a new flag on a later relapse; no AI/ML client, HTTP call,
      or Hermiq dependency anywhere in this class

## 6. Frontend

- [ ] 6.1 Add `src/manifest.json` index/detail pages for `LessonCompletion`, `EngagementScore`,
  `EngagementRiskThreshold`, `EngagementRiskFlag` (list/create/edit/detail per the standard declarative
  pattern used by `attendance`/`grading`).
  - **spec_ref**: `specs/progress-tracking/spec.md#requirement-persist-lessoncompletion-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added
- [ ] 6.2 Add a "Mark lesson complete" action to `src/views/LessonPlayer.vue` for `contentType`s that do not
  emit xAPI statements (`text`, non-embedded `video`, non-cmi5 `quiz`) — creates a `LessonCompletion`
  (`source: manual`) via the OpenRegister object API for the current learner; hidden for `cmi5`/`scorm12`/
  `scorm2004` content, which rely on the xAPI-sourced path.
  - **spec_ref**: `specs/progress-tracking/spec.md#requirement-learners-can-self-report-completion-of-non-xapi-content`
  - **acceptance_criteria**:
    - Action visible and functional for `text`; absent for `cmi5`
    - `t()`-wrapped strings only, no hardcoded copy
- [ ] 6.3 Add `EnrolmentProgressBar` display to the learner's My-learning dashboard widget (`Kpi*Widget.vue`
  pattern or a small addition to the existing enrolment list widget), reading `Enrolment.progressPercent` —
  declarative widget config, no new custom view.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up`
  - **acceptance_criteria**:
    - Renders seeded `progressPercent` values on the My-learning dashboard
- [ ] 6.4 Add `src/views/GroupTrendHeatmap.vue`: queries `GradeEntry` via the OpenRegister object API grouped
  by `cohortId` × `period`/`gradedAt`, renders a colour-banded heat-map grid of average `value` per cell; no
  new schema. Scoped to teacher/admin (`visibleIf` mirrors the Teaching/Administration dashboard gating);
  strings via `t()`; any `NcSelect` carries `inputLabel`; does not nest a `CnDashboardPage`. Add a manifest
  page + nav entry.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-cohortgroup-test-score-trend-renders-as-a-heat-map-over-existing-data`
  - **acceptance_criteria**:
    - Renders seeded `GradeEntry` data as a cohort × period heat map; empty state shown when a cohort has no
      grade entries yet
    - `hydra-gate-dashboard-antipattern` passes (no nested `CnDashboardPage`)
- [ ] 6.5 Add declarative KPI-tile widgets surfacing average `EngagementScore.score` and open
  `EngagementRiskFlag` count on the existing Teaching dashboard (`config.widgets` entries, `Kpi*Widget.vue`
  pattern like `KpiOpenFlagsWidget.vue`) — no new chart component.
  - **spec_ref**: `specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Widgets render seeded values without a nested `CnDashboardPage`

## 7. Tests and docs

- [ ] 7.1 PHPUnit for `LessonProgressHandler`, `EnrolmentProgressEvaluator`, `EnrolmentProgressRollup
  Handler`, `EngagementScoreEvaluator`, `EngagementSignalHandler` per the acceptance criteria in tasks 4.1–
  5.2 (minimum 75% coverage for new code per ADR-009).
  - **spec_ref**: all `progress-tracking` and `student-analytics` requirements
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 7.2 Add `tests/e2e/spec-coverage/progress-tracking.spec.ts` (Playwright): learner marks a `text` lesson
  complete and sees the completed state; a `cmi5` lesson shows no manual-complete action.
  - **spec_ref**: `specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete`, `specs/progress-tracking/spec.md#scenario-manual-completion-is-not-available-for-xapi-instrumented-content`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` references in both scenarios
- [ ] 7.3 Add `tests/e2e/spec-coverage/student-analytics.spec.ts` (Playwright): teacher opens the Group trend
  heat map and sees seeded cohort/period cells.
  - **spec_ref**: `specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` reference in the spec scenario
- [ ] 7.4 Add Dutch and English translations for all new i18n keys (ADR-005... i18n keys authored in English
  per project convention; strings added to `l10n/en.json`/`l10n/nl.json` via the existing extraction
  pipeline, not hand-edited).
  - **spec_ref**: all `progress-tracking` and `student-analytics` requirements
  - **acceptance_criteria**:
    - No hardcoded strings in `GroupTrendHeatmap.vue` or the `LessonPlayer.vue` mark-complete action
    - Notification subjects carry both `nl`/`en`

## 8. Verify

- [ ] 8.1 `openspec validate learning-progress-and-analytics --strict` clean; PHPUnit green for all five new
  PHP classes; Playwright `progress-tracking.spec.ts` and `student-analytics.spec.ts` green; no dangling
  `$ref`s in the register JSON; `EngagementSignalHandler`'s idempotency behaviour re-verified against seeded
  fixtures (no duplicate `open` flags; a resolved flag allows a new one).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; idempotency invariant re-verified end-to-end
