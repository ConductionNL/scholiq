## Why

Component marks have to roll up into a final grade, and the roll-up rule belongs to the governing plan, not the gradebook. Dutch VO manages this through the PTA: each `kolom` has a `weegfactor`, kolommen group by `periode`, and the weighted average across periods is the SE-gemiddelde that (combined with the CE) yields the `eindcijfer`. An HE module does the same with `deeltoetsen → eindcijfer`. A certification track does it with `all-must-pass`. Magister/SOMtoday own the Dutch VO workflow today but draw systemic UX backlash — instant per-grade pings, no concept state, opaque impact. This spec generalises that: a `GradeEntry` is one mark on one component for one learner; a `FinalGrade` is computed from a learner's published GradeEntries using the `CurriculumPlan`'s declared `formula` and component weights; soft-publish lets a teacher review the cohort distribution before any parent/learner notification fires; the learner sees each grade's weight and its impact on the running average. This change also closes the forward-reference in `Submission.gradeEntryId` and `AssessmentResult.gradeEntryId` introduced by the `assignments` and `assessment` changes.

## What Changes

### New Schemas (3) — `lib/Settings/scholiq_register.json` (22 → 25)

Wait — OpenRegister already exposes a `NotificationPreference` mechanism via `UserService::getNotificationPreferences` / `setNotificationPreferences` + `NotificationSubscriptionsController` (confirmed by grepping `openregister/lib/`). We therefore do NOT add a `NotificationPreference` schema. **Schema count: 22 → 24.**

- **GradeScale** (slug `grade-scale`) — a named grading scale: `name`, `kind` (enum: `numeric` | `letter` | `ects` | `pass-fail` | `percentage` | `band`), `bands` (`{ bandId, label, minValue, maxValue, pass }[]` — for letter/ects/band kinds), `min`, `max`, `passThreshold` (for numeric/percentage kinds), `roundingRule` (enum: `none` | `half-up-1dp` | `half-up-int` | `nearest-half`), `tenant_id`. Lifecycle: draft → active → archived. Calculation: `bandCount`.
- **GradeEntry** (slug `grade-entry`) — one mark on one `CurriculumPlan` component for one learner: `learnerId`, `curriculumPlanId`, `componentId` (the `CurriculumPlan.components[].componentId` it scores), `courseId`, `cohortId`, `sourceKind` (enum: `assignment-submission` | `assessment-result` | `participation` | `manual`), `submissionId`, `assessmentResultId`, `sessionId` (set per sourceKind), `value`, `gradeScaleId`, `weight` (overrides the plan component default per entry), `period`, `grader`, `gradedAt`, `comment`, `tenant_id`. Lifecycle: concept → published → revised. `x-openregister-notifications`: `gradePublished` fires on lifecycle enter `published`; idempotencyKey `"${@self.id}-${@self.lifecycle}"` prevents double-fire on re-publish/backfill. `x-openregister-calculations`: `effectiveWeight` (per-entry weight if set, else `CurriculumPlan.components[].weight`), `pointsContributed` (`value * effectiveWeight`). Not appendOnly — a concept entry is editable; lifecycle (no back-transition to concept from published) enforces immutability once published.
- **FinalGrade** (slug `final-grade`) — a calculated roll-up per learner per Course or Programme: `learnerId`, `courseId`, `programmeId`, `curriculumPlanId`, `gradeScaleId`, `tenant_id`. No lifecycle (derived). `x-openregister-aggregations`: cross-schema aggregation over the learner's published GradeEntries for this curriculumPlan (same feature Regulation uses for coverage %). `x-openregister-calculations`: `value` (apply `CurriculumPlan.formula` via `GradeFormulaEvaluator`), `breakdown`, `passed`, `lastRecomputedAt`. `x-openregister-triggers`: `calculatedChange` — recompute whenever a `GradeEntry` publishes (not a TimedJob).

### Updated Schemas

- **Submission** — `gradeEntryId` forward-ref is already present (set by `MarkSubmissionView.vue` in this change).
- **AssessmentResult** — `gradeEntryId` forward-ref already present in schema; the `gradeEntryComponentId` field is added if not already present.

### New PHP (2, ADR-031 legitimate exceptions only)

- `lib/Grading/GradeFormulaEvaluator.php` — stateless evaluator. Single public method `evaluate(string $curriculumPlanId, string $learnerId): array` returning `{ value, passed, breakdown }`. Applies the `CurriculumPlan.formula` over the learner's published GradeEntries. Queried via `ObjectService::findAll()`. Legitimate per ADR-031 "calculation engine above schema metadata" — the weighted-average / last-attempt / best-of-n / all-must-pass formulas cannot be expressed in JSON-logic.
- `lib/Listener/GradeRollupHandler.php` — listens for `ObjectTransitionedEvent`; when a `GradeEntry` enters `published`, finds or creates the matching `FinalGrade` and recomputes it via `GradeFormulaEvaluator`, then persists via `ObjectService::saveObject`. Registered in `Application.php`. Legitimate per ADR-031 "lifecycle handler — event-to-object-write bridge that cannot be expressed as a schema declaration."

### Updated PHP

- `lib/Views/MarkSubmissionView.vue` — TODO(grading spec) is fulfilled: on `saveAndReturn`, after persisting the Submission, also creates/updates a `GradeEntry` (`sourceKind: assignment-submission`, `lifecycle: concept`, `value: proposedGrade`, `componentId` from the Assignment's `curriculumPlanComponentId`). Removes the TODO comment.

### New Frontend

- Manifest pages: `GradeScales` / `GradeScaleDetail`, `GradeEntries` / `GradeEntryDetail`, `FinalGrades` / `FinalGradeDetail` (readOnly — derived) + `GradebookView` (custom, component `GradebookView`) + `GradeImpactDetail` (custom, component `GradeImpactDetail`). One nav `menu` entry: "Grades".
- `src/views/GradebookView.vue` — cohort × component grade grid; teacher enters/edits GradeEntry values as `concept`; distribution preview (histogram); "Publish all" batch transition.
- `src/views/GradeImpactDetail.vue` — read-only view of one published GradeEntry: value, effectiveWeight, pointsContributed, period average, final-grade delta.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages, the gradebook view, and the grade impact detail.

## Capabilities

### New Capabilities

- `grading`: GradeScale, GradeEntry, FinalGrade schemas with declarative lifecycle / notifications / calculations; GradeFormulaEvaluator + GradeRollupHandler PHP exceptions; manifest pages + two custom Vue views; MarkSubmissionView TODO resolved; soft-publish + notification-preference integration via OR's existing notification mechanism.

### Updated Capabilities

- `assignments` — `MarkSubmissionView.vue` now writes a `concept` GradeEntry on Submission return (resolving the forward-reference).
- `assessment` — `TakeAssessmentView.vue` left unchanged (the `gradeEntryId` forward-ref on AssessmentResult is populated by the GradeRollupHandler once the AssessmentResult reaches `graded`).
