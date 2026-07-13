---
kind: code
depends_on: []
---

## Why

### 1. The prerequisite hole — the spec has claimed this since May, the code never had it

`openspec/specs/enrolment/spec.md` (`created: 2026-05-11`, over two months ago) has required prerequisite
enforcement since it was written. Its Acceptance Criteria already promise it —
`openspec/specs/enrolment/spec.md:31`: "GIVEN a course has unmet prerequisites, WHEN a learner attempts
enrolment, THEN the system blocks the enrolment and explains which prerequisite failed" — and it carries a
dedicated, named `MUST` requirement: `openspec/specs/enrolment/spec.md:43-49`, "### Requirement: Validate
prerequisites before persistence" / "The system MUST validate prerequisites before enrolment is persisted."
`openspec/specs/course-management/spec.md` makes the same promise from the catalog side —
`openspec/specs/course-management/spec.md:24,29`: "As a student, I want the catalog to tell me up-front
whether I meet a course prerequisite..." / "the enrol button is disabled and the failing prerequisite is
named in plain text" — and its own Data Model line claims a `Prerequisite` entity exists:
`openspec/specs/course-management/spec.md:181`, "Uses entities: `Course`, `Module`, `Lesson`,
`LearningPath`, `Prerequisite`, `CatalogChangeRequest`, `LtiToolPlacement`."

None of it was ever built. `grep -rni "prerequisite" lib/ src/` (re-verified at HEAD in this worktree,
2026-07-13) returns **zero matches** in application code. `lib/Settings/scholiq_register.json` (59 schemas)
has no `Prerequisite` schema and no `prerequisiteCourseIds`-shaped field anywhere — confirmed by dumping
every schema name and grepping the file directly. `Course` (`lib/Settings/scholiq_register.json:810+`) has
no field referencing another `Course` as a gating condition; its only self-referential field is
`parentCourseId` (`lib/Settings/scholiq_register.json:921-928`, module-as-course recursion — an unrelated
concept). `Enrolment` (`lib/Settings/scholiq_register.json:1452+`) has no PHP lifecycle guard, no
`ObjectCreatingEvent` listener, nothing in `lib/Lifecycle/` or `lib/Listener/` that reads a course's
prerequisites. A learner can enrol in any course today regardless of what they have or have not completed.
This is not a partially-built feature with rough edges — it is a requirement the spec has stated as `MUST`
for months while shipping code that does not implement, or even attempt, any part of it.

**Why the fix cannot be "add a `lib/Lifecycle/*Guard.php` like the others"** (the obvious first instinct,
since every existing guard in `lib/Lifecycle/` — e.g. `CohortMembershipGuard`, `CoursePublishGuard` — is
wired via a schema's `x-openregister-lifecycle.transitions.*.requires` clause): OpenRegister's lifecycle
engine only resolves a `requires` guard on an explicit named transition **between two already-persisted
states** (`LifecycleValidationListener::onObjectUpdating()`, which bails immediately with `if ($oldObject
=== null) { return; }` when there is no prior state — verified against
`apps/openregister/lib/Listener/LifecycleValidationListener.php:107-112`). `Enrolment`'s lifecycle
(`lib/Settings/scholiq_register.json:1569+`, `pending → active → completed → withdrawn/failed`) has no
transition *into* `pending` — the initial state is stamped by the separate, non-blocking
`LifecycleInitialStateListener` on `ObjectCreatingEvent`, which never resolves a `requires` guard
(`apps/openregister/lib/Listener/LifecycleInitialStateListener.php:90-108`). A `requires`-style guard
literally cannot run "before persistence" — there is no state to transition from. The requirement can only
be met by hooking OpenRegister's `ObjectCreatingEvent` directly (`StoppableEventInterface`;
`MagicMapper::insertObjectEntity()` throws `HookStoppedException` and aborts the insert when a listener
calls `stopPropagation()` — `apps/openregister/lib/Db/MagicMapper.php:5802-5827`). This is not a new
pattern in the fleet: `apps-extra/decidesk/lib/Listener/SubmissionDeadlineListener.php` and
`apps-extra/larpingapp/lib/Listener/CharacterRequirementListener.php` both already gate object creation this
exact way. This change follows that precedent rather than the (unworkable) `Lifecycle/*Guard.php` shape.

### 2. Conditional / adaptive release — no gating primitive exists on content at all

Sakai's Conditional Release, Canvas's Mastery Paths, and LearnDash's Course/Content Drip all let an author
require completion of item X (or a minimum score on it) before item Y unlocks — a standard LMS/eLearning
pattern the market has shipped for years. Scholiq has none of it. `Lesson`
(`lib/Settings/scholiq_register.json:1080+`) has an `order` field (`:1114`, "Position within the course
(1-based, contiguous)") used only for *display sequencing* — `lib/Lifecycle/XapiCompletionHandler.php`
reads it purely to find "the final lesson" for auto-completing an `Enrolment`, never to gate access to a
*later* lesson. `CurriculumPlan.passRules` (`lib/Settings/scholiq_register.json:2993+`, "Per-component (or
overall) minimum-pass thresholds") gates whether a learner's **grade** passes, not whether *content* is
released — re-verified directly: its only consumer is the roll-up `formula`, unrelated to lesson/assessment
availability. `Assessment` (`lib/Settings/scholiq_register.json:4640+`) does have an availability gate —
`availableFrom`/`availableUntil` (`:4763`, `:4770`) plus a materialised `isAvailable` calculation
(`:4884-4930`, a pure absolute-datetime-window check) — but it is a single fixed window shared by every
learner, with no concept of "only after learner X finishes item Y" or "only after learner X scores ≥ N on
item Z."

This change adds a `releaseConditions` array to both `Lesson` and `Assessment`: prior-item completion and
minimum-score conditions. **Competency-attainment gating is deliberately NOT included as a condition kind
in this change.** The sibling wave-2 change `competency-framework` (same repo, in flight in parallel)
introduces `CompetencyAttainment` — that schema does not exist yet in this worktree at the time of writing,
so this change cannot verify its shape and will not reference fields it cannot confirm. Taking a
`depends_on` on an in-flight sibling would also make this change's own validation/merge order depend on a
parallel agent's completion, which is avoidable: prior-item-completion and minimum-score conditions already
cover the two most common adaptive-release patterns (Canvas Mastery Paths ships both; LearnDash ships
completion-based drip) and are fully groundable against code that exists today (`XapiStatement`,
`AssessmentResult`). Competency-attainment as a third condition kind is noted as a follow-up once
`competency-framework` lands and its shape can be verified.

### 3. Drip scheduling — `Assessment.availableFrom` is absolute; no per-learner relative scheduling exists

`Assessment.availableFrom`/`availableUntil` (`lib/Settings/scholiq_register.json:4763-4784`) are plain
`format: date-time` fields — one fixed instant for every learner, set once by the author. There is no
concept anywhere of "N days after *this learner's own* enrolment date." `Lesson` has no availability field
at all.

Re-verifying the wave-1 precedent this task's brief pointed at: the archived
`openspec/changes/archive/2026-07-13-grade-visibility-scheduling` change did **not** build enrolment-relative
scheduling. Reading its shipped delta directly
(`openspec/changes/archive/2026-07-13-grade-visibility-scheduling/proposal.md` and
`specs/scholiq-notifications/spec.md:50-54,86-91`): `CurriculumPlan.gradeVisibilityPolicy` gates *when in
the day/week* a published grade becomes visible (`mode: immediate | nextSchoolDay`, a fixed
`time`/`timezone`) — the same absolute instant for every learner whose grade publishes at that moment — and
the only "relative" concept it introduces is a **deadline lead-time guarantee** for `Enrolment.dueReminder`/
`overdue` (ensuring a reminder still lands before a shared due date after a quiet-hours deferral), not
per-learner relative content release. Correcting the brief: the reusable part of that change is not literal
"enrolment-relative scheduling for grades" but its **pattern** — a nullable schema field
(`GradeEntry.visibleFrom`) resolved server-side by a stateless resolver service
(`GradeVisibilityResolver`, mirroring the existing `GradeFormulaEvaluator` ADR-031 exception) from a policy
field on a parent object. This change reuses that *shape* (stateless resolver + explicit new field), not
its specific "day/time window" semantics — because drip scheduling has a structurally different problem
(below).

**Why drip cannot be a single materialised field, unlike `visibleFrom`:** every `x-openregister-calculations`
expression in this register resolves against `@self` (the object's own fields), `@aggregate.*`
(cross-schema counts/aggregates filtered by `@self`), or `@ref.*` (a single foreign-key lookup) — confirmed
by extracting every `@`-prefixed token used across all 59 schemas; there is no per-viewer/per-requesting-user
token. `GradeEntry.visibleFrom` works as one materialised field because one `GradeEntry` row belongs to
exactly one learner already. A `Lesson` is shared by every learner enrolled in its `Course` — there is no
single "availableAfterDays" *datetime* that could be materialised onto the `Lesson` row itself, because each
learner's own `Enrolment.created` timestamp differs. The declarative-safe part is the static **duration**
(`Lesson.availableAfterDays`, an integer, the same for every learner); the per-learner *evaluation* — "is it
past that duration for **this** learner" — must happen at read/launch time in a small PHP service, the same
way `XapiCompletionHandler` (`lib/Lifecycle/XapiCompletionHandler.php`) already resolves per-learner facts
by querying `XapiStatement`/`Enrolment` directly rather than relying on a materialised calculation. `design.md`
works through this in full.

## What Changes

### `enrolment` (MODIFIED)

- New `lib/Listener/EnrolmentPrerequisiteListener.php`: subscribes to OpenRegister's `ObjectCreatingEvent`
  for `enrolment` objects (mirrors `apps-extra/decidesk/lib/Listener/SubmissionDeadlineListener.php`
  exactly). Resolves the target `Course`'s `prerequisiteCourseIds`; for each, looks up whether the enrolling
  learner already holds a `completed` `Enrolment` for that course. If any required prerequisite is unmet,
  calls `$event->setErrors([...])` + `$event->stopPropagation()`, naming the failing prerequisite by course
  name — OpenRegister's write path then rejects the create with `HookStoppedException` (surfaced as an
  HTTP 4xx by the generic OR object API; no bespoke Scholiq controller/frontend code needed). Rule
  enforcement is fail-closed (unmet prerequisite always blocks); infrastructure faults during the lookup
  (e.g. OR read failure) are fail-open and logged, so a transient error can never brick all enrolment —
  same documented posture as `SubmissionDeadlineListener`.
- Registered in `lib/AppInfo/Application.php` alongside the app's other `registerEventListener()` calls.
- `openspec/specs/enrolment/spec.md`'s existing "Validate prerequisites before persistence" requirement is
  MODIFIED to name the actual mechanism (`ObjectCreatingEvent` listener, not a lifecycle guard) and add a
  concrete scenario for the "no prerequisites configured" pass-through case.

### `course-management` (MODIFIED)

- `Course` (`lib/Settings/scholiq_register.json:810+`) gains `prerequisiteCourseIds`: array of `$ref
  Course` UUIDs, additive, default `[]`. Mirrors the existing `CurriculumPlan.requiredCourseIds`/
  `Course.programmeIds` array-of-`$ref` shape exactly.
- `Lesson` (`:1080+`) gains `releaseConditions` (array of `{kind: "lesson-completed" | "assessment-min-score",
  lessonId?, assessmentId?, minScore?}`, default `[]`, AND-combined) and `availableAfterDays` (nullable
  integer ≥ 0, drip delay relative to the learner's own `Enrolment.created`). Both additive; existing
  `Lesson` rows are unaffected (empty conditions + null delay = today's unconditional-on-publish behaviour).
- New `lib/Release/LessonReleaseEvaluator.php` (ADR-031 stateless-service exception, mirrors
  `GradeVisibilityResolver`/`BsaProgressEvaluator`'s shape): given a `Lesson` or `Assessment`, the requesting
  learner's id, and their `Enrolment` for that course, evaluates `releaseConditions` +
  `availableAfterDays` and returns `{available, reason}`.
- New `lib/Controller/LessonReleaseController.php` (`GET
  /apps/scholiq/api/lessons/{lessonId}/release-status`) — a genuinely computed gate decision, not a
  pass-through CRUD read; per-object authorization requires the caller to hold an `Enrolment` for the
  lesson's course (or an admin/teacher role).
- `src/views/LessonPlayer.vue` calls the new endpoint before rendering **any** content type (text, video,
  scorm12/2004, cmi5, lti, quiz) and renders a locked state naming the unmet condition when `available` is
  false — this is the single funnel point every content type already passes through, so gating there covers
  all types uniformly without touching per-content-type launch code.
- `openspec/specs/course-management/spec.md`'s Data Model line (`:181`) and its catalog-facing Acceptance
  Criterion (`:29`, "the enrol button is disabled...") are corrected: the `Prerequisite` "entity" never
  existed as a separate schema (it's the new `Course.prerequisiteCourseIds` relation) and there is currently
  **no self-service course catalog page anywhere in `src/manifest.json` or `src/views/`** (verified: no
  `CourseCatalog`-shaped view, no enrol-button component) for a "disable the enrol button" UI to attach to —
  that acceptance criterion is corrected to describe the reactive block that actually ships (the
  `EnrolmentPrerequisiteListener` rejection surfacing through the existing generic `CnWizardDialog` error
  handling `BulkEnrol` already uses), with the proactive catalog-disable UI flagged as a follow-up once a
  catalog page exists to build it on.

### `assessment` (ADDED)

- `Assessment` (`:4640+`) gains the same `releaseConditions` array shape as `Lesson`, plus
  `availableAfterDays`, layered as an **additional** per-learner gate on top of the existing
  `availableFrom`/`availableUntil`/`isAvailable` absolute window (unchanged) — an assessment must satisfy
  both to be available to a given learner.
- `LessonReleaseEvaluator` and `LessonReleaseController` (above) are shared across `Lesson` and `Assessment`
  — a second `GET /apps/scholiq/api/assessments/{assessmentId}/release-status` route reuses the same
  evaluator.
- A `minScore` condition is evaluated directly against the referenced `Assessment`'s graded
  `AssessmentResult.responses[].autoScore ?? manualScore`, summed — **not** via `GradeEntry.value`. This is a
  deliberate, narrower scope: coupling to `GradeEntry`/grading's soft-publish `visibleFrom` workflow would
  mean content unlocks only once a teacher formally publishes the grade, which is arguably more correct
  pedagogically but adds real complexity and cross-capability coupling this M-sized change does not need;
  noted as a rejected alternative in `design.md`. Along the way this surfaced a second, smaller pre-existing
  gap worth flagging (not fixed here — out of scope, belongs to the `assessment` capability): despite
  `openspec/specs/assessment/spec.md:46,51` claiming `AssessmentResult` carries `x-openregister-calculations`
  for `autoScore`/`totalScore`/`passed`, the actual schema
  (`lib/Settings/scholiq_register.json:4939-5419`) has **no** `x-openregister-calculations` block at all —
  `LessonReleaseEvaluator` computes the score sum itself rather than depending on a field that does not
  exist.

## Impact

- `lib/Settings/scholiq_register.json` — `Course.prerequisiteCourseIds` (new), `Lesson.releaseConditions` +
  `Lesson.availableAfterDays` (new), `Assessment.releaseConditions` + `Assessment.availableAfterDays` (new).
  No changes to any `required` array; all additions are additive/nullable/default-empty.
- `lib/Listener/EnrolmentPrerequisiteListener.php` (new) — `ObjectCreatingEvent` gate on `Enrolment`
  creation.
- `lib/Release/LessonReleaseEvaluator.php` (new) — stateless per-learner release evaluator shared by
  `Lesson` and `Assessment`.
- `lib/Controller/LessonReleaseController.php` (new) + two new `appinfo/routes.php` entries.
- `lib/AppInfo/Application.php` — register the new `ObjectCreatingEvent` listener.
- `src/views/LessonPlayer.vue` — calls the release-status endpoint before rendering any content type;
  renders a locked state with the unmet-condition reason.
- No PHP CRUD controller for prerequisites/release conditions themselves — both are plain schema fields
  edited through the existing manifest-driven `Course`/`Lesson`/`Assessment` forms.
- No new OR calculation/aggregation dialect capability invented — the array-of-`$ref` shape, the
  stateless-resolver-service shape, and the `ObjectCreatingEvent`-listener shape are all reused precedent.

## DEFERRED_QUESTIONS

1. **No admin/HR override for bulk/manager-sourced enrolments.** `Enrolment.source` (`self | manager | hr |
   bulk | migrated | system`) has no carve-out — the guard blocks unmet prerequisites uniformly regardless
   of source, since `openspec/specs/enrolment/spec.md`'s requirement text makes no source distinction and an
   unconditional read is the safer default. Provisional decision: ship uniform enforcement now; add a
   `prerequisiteOverrideReason` field later only if a real onboarding workflow needs to bypass it (e.g. HR
   hiring a senior employee directly into an advanced course) — not invented speculatively here.
2. **`Programme`-level prerequisites are out of scope.** The brief suggested "Course/Programme"; verified
   there is no `ProgrammeRegistration`-shaped object to enforce against — a learner only ever enrols in a
   `Course` (`Enrolment.courseId`), never directly in a `Programme` (`Programme.courseIds` just aggregates
   courses). Adding a `Programme.prerequisiteProgrammeIds` field with zero enforcement would repeat exactly
   the spec-promises-but-code-doesn't-deliver pattern this change exists to fix. Provisional decision:
   `Course`-level only; revisit if/when a `Programme`-level enrolment or registration object is introduced.
3. **Competency-attainment release condition deferred to `competency-framework`.** See "What Changes" §2 —
   `CompetencyAttainment` does not exist in this worktree yet. Provisional decision: ship
   `lesson-completed`/`assessment-min-score` now; add a third `competency-attained` condition `kind` as a
   small additive follow-up once that sibling change lands and its schema can be verified directly.
