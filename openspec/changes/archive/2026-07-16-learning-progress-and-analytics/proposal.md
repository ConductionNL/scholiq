---
kind: code
depends_on: [cmi5-xapi-lrs-ingest]
---

## Why

Scholiq can enrol a learner and grade a learner, but it cannot tell anyone **how far through a course** that
learner is, and it has exactly one at-risk signal in the whole register — a credit-count rule that only
fires for HE/MBO BSA trajectories. Both gaps are verified at HEAD, not inferred from the roadmap.

### (a) No per-lesson completion state and no progress %

- **`Enrolment.lifecycle` is a four-state coarse gate, not a progress meter.** `pending → active → completed
  | withdrawn | failed` (`lib/Settings/scholiq_register.json:1549-1561`). The only derived fields on
  `Enrolment` are `isOverdue`, `daysRemaining`, and `ragStatus` (`lib/Settings/scholiq_register.json:1594-
  1694`) — all three are *deadline* math (`dueDate` vs. `@now`), none of them read a single `Lesson` or
  content-completion fact. A learner three lessons into a ten-lesson course and a learner on lesson nine look
  identical: both `active`, both whatever `ragStatus` their `dueDate` produces.
- **`Lesson` has no completion concept at all.** `lib/Settings/scholiq_register.json:1079-1228` — `order`,
  `contentType` (`text|video|scorm12|scorm2004|cmi5|lti|quiz`), `mandatoryTraining`, a three-state
  `draft → published → retired` lifecycle. Nothing records that *a specific learner* finished *a specific
  lesson`. Repo-wide grep for a `LessonCompletion`-shaped object returns zero hits — confirmed against the
  full 62-schema list in `lib/Settings/scholiq_register.json`.
- **The completion *signal* already exists and is already wired to exactly one place: the finish line.**
  `lib/Lifecycle/XapiCompletionHandler.php` listens for OR's `ObjectCreatedEvent` on `xapi-statement`
  (`lib/Settings/scholiq_register.json:1340-1450`, `verified_actor_id` trust boundary at 1427-1434) and, when
  the verb is `completed`/`passed` (lines 69-72), the related `Lesson.mandatoryTraining` is `true` (line 159),
  **and** it is the *highest-`order` published `Lesson` of its `Course`* (lines 168-214), dispatches
  `Enrolment`'s `complete` transition (line 277). Every xAPI statement for lesson 1 of 10, or for any
  non-mandatory lesson, or for any lesson that is not the last one, is received, stored (xAPI statements are
  `appendOnly: true`), and then **silently ignored** by this handler — no per-lesson fact is ever written.
  This is the "signal may already arrive" case the brief anticipated: it does, for the *last* lesson of
  *mandatory-training* courses only, and it is consumed for a binary enrolment flip, not a progress trail.
  Reusing this event source without duplicating `XapiCompletionHandler`'s own logic is exactly what this
  change wires up (see What Changes).
- **Reachability caveat, verified from the sibling wave-2 change:** `xapi-statement`'s own schema comment
  (`lib/Settings/scholiq_register.json:1442-1447`) says creation is `["admin"]`-only "as a stopgap until a
  dedicated xAPI ingest controller stamps `verified_actor_id` server-side" — and the open
  `cmi5-xapi-lrs-ingest` change's own Why section (read in full) confirms `appinfo/routes.php` has no
  `lrs`/`cmi5` route today, so **no real learner can produce an xAPI statement yet**; `XapiCompletionHandler`
  itself is presently unreachable end-to-end. This change's per-lesson wiring inherits the same reachability
  gap — hence `depends_on: [cmi5-xapi-lrs-ingest]` — and, unlike the enrolment-completion path, also ships a
  learner-facing **manual** completion path (mirroring `AssessmentResult`'s self-serve create precedent,
  `lib/Settings/scholiq_register.json:4938-5100`, which carries no `x-openregister-authorization.create`
  restriction) so text/video/quiz lessons that never emit xAPI can still be marked complete and progress %
  is not permanently stuck below 100 for every non-cmi5 course.
- **No declarative arithmetic operator exists to compute a percentage.** A repo-wide programmatic scan of
  every `x-openregister-calculations` expression in `lib/Settings/scholiq_register.json` (62 schemas) finds
  exactly these JSON-logic operators in use: `and, case, dateAdd, dateDiff, default, eq, gt, gte, if, lt,
  lte, ne, now, or, prop, then, when` — no `sum`, `divide`, `multiply`, or `count`-as-expression-operator.
  `x-openregister-aggregate-refs` (the newer of two aggregation dialects present, 6 uses vs. `x-openregister-
  aggregations`'s 5; example at `lib/Settings/scholiq_register.json:10644-10706`, the `Praktijkovereenkomst`
  schema's `isFullySigned` calculation) *can* expose a cross-schema `count` as `{"prop":
  "@aggregate.<name>"}` inside a JSON-logic comparison — but only for `eq`/`gte`-style boolean checks, never
  for division. `FinalGrade.value` (`lib/Settings/scholiq_register.json:5720-5862`) is the established
  precedent for exactly this shape of gap: a cross-schema roll-up expressed as `x-openregister-aggregations`
  (documentation/read-surface) plus an `engine`-keyed PHP class (`GradeFormulaEvaluator`) invoked by
  `GradeRollupHandler` (`lib/Listener/GradeRollupHandler.php`) on an `ObjectTransitionedEvent`, writing the
  derived value as a plain field — not a `materialise: true` expression. `study-progress`'s `BsaTrajectory.
  ectsEarned` (`openspec/specs/study-progress/spec.md`, `lib/Settings/scholiq_register.json:8900-8912`) is
  the second, most recent precedent for the identical reasoning (its own task 2.1 IMPLEMENTATION NOTE spells
  out why a per-learner derived value can't be a standalone materialised property). This change's
  `Enrolment.progressPercent` follows the same, twice-precedented shape.

**Demand:** canonical `progress-tracking` (demand 11, 4 competitors). Competitor evidence: Odoo, ILIAS
(colour-coded per-object completion), Kolibri (time-on-task), Brightspace, Teachable, Claroline all expose a
per-object completion state and a roll-up percentage; Scholiq exposes neither.

### (b) No engagement/at-risk signal beyond the HE/MBO-only BSA credit rule, and no cohort/group trend view

- **`BsaTrajectory`/`BsaProgressFlag` is the only at-risk mechanism in the register, and it is scoped to
  credits, not engagement.** `lib/Settings/scholiq_register.json:8721-9012` (capability `study-progress`,
  archived under `openspec/changes/archive/2026-07-13-bsa-study-progress-guard/`). Its own `kind` enum is
  `he-eerstejaar-bsa | mbo-studieadvies | generic` (`lib/Settings/scholiq_register.json:8747-8756`) — it
  fires only from `GradeEntry.published` via `BsaProgressFlagHandler`
  (`openspec/specs/study-progress/spec.md`, "Credit-earned and at-risk detection" requirement) and only for
  `Course`s that declare `ectsCredits` (additive, `level: hbo|wo|mbo` only per
  `lib/Settings/scholiq_register.json:951`). A `po`/`vo`/corporate learner, or any HE/MBO learner who is
  attending but disengaged with no failing grade yet, has **no** at-risk signal at all — confirmed by
  repo-wide grep for `engagement|at-risk|atRisk|heatmap|time-on-task` returning only the BSA/`onAtRisk`
  hits.
- **No engagement metric exists.** `XapiStatement` (`lib/Settings/scholiq_register.json:1340-1450`) is
  received and stored per-interaction (`result`, `timestamp` fields present) but nothing aggregates
  time-on-task or activity recency from it into a queryable per-learner signal — the schema's own
  `x-openregister` block sets `"searchable": false` (line 1350), so it is not even index-searchable, let
  alone rolled up.
- **Dashboard capability already *claims* a group-level skill-area heat map and never shipped it.**
  `openspec/specs/dashboard/spec.md` "Acceptance Criteria": "GIVEN a manager opens the team tab... THEN
  every report row shows assigned/in-progress/completed/overdue counts plus a skill-area heat map" — status
  `done`. Repo-wide grep for `heatmap|heat-map|heat map` across `src/views/**/*.vue` (including
  `src/views/widgets/`, 13 files) returns **zero hits**; `DashboardTeacher.vue` (read in full) is a nine-line
  pass-through to `ScholiqDashboards.vue role="teacher"` with no heat-map widget. This is the same
  claimed-but-not-built pattern the BSA change documented for cmi5/xAPI. This change closes it rather than
  leaving a second stale claim.
- **The raw data for a cohort/group trend already exists — this is a query gap, not a schema gap.**
  `GradeEntry` already carries `cohortId` and `gradedAt` (`lib/Settings/scholiq_register.json:5468-5475` and
  the `gradedAt` property a few lines below), and `Cohort` carries `learnerIds`
  (`lib/Settings/scholiq_register.json:3177-3184`). OpenRegister's existing object API already serves
  filter/aggregate/export over these fields (per the architecture rule: "OR's object API already serves
  CRUD/list/filter/search/aggregate/export/audit") — no new persisted schema is needed to plot a test-score
  trend per cohort over time; what is missing is the one rendering surface, which is exactly the
  "abstraction vs. build" split this proposal is honest about in `design.md`.
- **AI/predictive at-risk scoring is explicitly out of scope here.** The open `ai-feature-delegate-to-hermiq`
  change (read in full) is, concurrently, relocating Scholiq's *entire* local AI-feature governance surface
  (the `AiFeature` schema, `AiFeatureDpoAckGuard`, `/ai-features` pages) to Hermiq's `agentaifeature`
  register — Scholiq is actively shedding local AI governance, not a place to add a new local AI feature.
  Every derivation in this change (engagement score, at-risk threshold) is a deterministic, auditable
  arithmetic/threshold computation — no inference, no model call. A future ML-based extension of the at-risk
  signal is a separate concern routed through Hermiq behind the ADR-005 AI-Act gate (candidate: a future
  `ai-course-recommendations` change), not built here.

**Demand:** canonical `student-analytics` (demand 34, 14 competitors: Canvas risk indicators, Moodle,
Studytube, Blackboard time-on-content, ParnasSys Groepskaart/progress graphs, Magister Inzicht, Gibbon,
ESIS, MS Teams Insights, Kolibri), `learning-analytics` (demand 26, 10 competitors), `generate-group-level-
trend-report` (demand 15, 6 competitors).

## What Changes

- **New `progress-tracking` capability**:
  - `LessonCompletion` — one row per `(learnerId, lessonId)`, `source: xapi | manual`, `completedAt`,
    optional `score` (from an xAPI `result.score.scaled` when `source: xapi`). Written by a new
    `LessonProgressHandler` listening on the **same** `ObjectCreatedEvent<XapiStatement>` `XapiCompletion
    Handler` already consumes — a sibling, single-responsibility listener (ADR-031), not a modification of
    `XapiCompletionHandler`'s own enrolment-completion logic. It is **not** gated on `mandatoryTraining` or
    "last lesson" — every `completed`/`passed` statement for a resolvable `Lesson` produces or updates a
    `LessonCompletion` row. Learners may also self-report completion for non-xAPI content
    (`source: manual`), mirroring `AssessmentResult`'s unrestricted self-serve create.
  - `Enrolment` (MODIFIED delta): two `x-openregister-aggregate-refs` (`completedLessonCount` from
    `lesson-completion`, `totalPublishedLessonCount` from `lesson`) as the declarative read-surface, plus a
    plain `progressPercent` field written by a new `EnrolmentProgressEvaluator` (PHP, `engine`-keyed,
    mirrors `GradeFormulaEvaluator`) via a new `EnrolmentProgressRollupHandler` (mirrors `GradeRollup
    Handler`) triggered off `LessonCompletion` writes.
- **New `student-analytics` capability**:
  - `EngagementScore` — one row per `(learnerId, courseId)`: `activityCount` (declarative `x-openregister-
    aggregate-refs` count of `XapiStatement` where `verified_actor_id`/`courseId` match — genuinely
    declarative, no PHP needed for a count), `timeOnTaskMinutes` and `score` (PHP-computed by a new
    `EngagementScoreEvaluator` — summing nested `XapiStatement.result` durations has no declarative `sum`
    operator, verified above), `lastActivityAt` (PHP-set) and `recencyDays` (genuinely declarative
    `materialise: true` `dateDiff` from `lastActivityAt` to `@now`, mirroring `Enrolment.daysRemaining`
    exactly).
  - `EngagementRiskThreshold` + `EngagementRiskFlag` — mirrors the `AttendanceThreshold`/`AttendanceFlag`
    and `BsaTrajectory`/`BsaProgressFlag` config-plus-append-only-flag pattern, generalised to **any**
    course/programme (not gated on `ectsCredits`/HE-MBO), comparing `EngagementScore.score`/`recencyDays`
    against a configured limit. Rule-based only — see "AI/predictive... out of scope" above. A single
    `EngagementSignalHandler` (mirrors `BsaProgressFlagHandler`'s combined evaluate-then-flag shape, the
    most recent precedent) does both the score recompute and the threshold check off the same
    `XapiStatement` event, idempotency-keyed like every other Flag handler in this codebase.
  - One named custom view, `GroupTrendHeatmap.vue` — cohort × period grade-trend heat map over existing
    `GradeEntry` data via OR's aggregate API (no new schema), closing the `dashboard` spec's unbuilt
    Acceptance Criteria claim. Declarative KPI-tile additions to the existing teacher/manager dashboard
    (`src/manifest.json`) surface `EngagementScore`/`EngagementRiskFlag` counts — no new chart components
    beyond the one heat map.

## Impact

- **`lib/Settings/scholiq_register.json`** — `Enrolment` MODIFIED (additive: aggregate-refs + `progress
  Percent`); five new schemas: `LessonCompletion`, `EngagementScore`, `EngagementRiskThreshold`,
  `EngagementRiskFlag`.
- **New PHP** — `OCA\Scholiq\Listener\LessonProgressHandler`, `OCA\Scholiq\Progress\EnrolmentProgress
  Evaluator`, `OCA\Scholiq\Listener\EnrolmentProgressRollupHandler`, `OCA\Scholiq\Analytics\Engagement
  ScoreEvaluator`, `OCA\Scholiq\Listener\EngagementSignalHandler`. No new controller, no new route.
- **`src/manifest.json`** — index/detail pages for the five new/modified objects; one new custom view
  `GroupTrendHeatmap.vue`; additive KPI-tile widget entries on the existing teacher/manager dashboard pages.
- **Affected specs**: `enrolment` (MODIFIED-by-addition: progress roll-up), new `progress-tracking` and
  `student-analytics` capability specs. `study-progress`, `attendance`, `grading`, `dashboard` are read-only
  precedents, not modified.
- **Out of scope**: any ML/predictive at-risk scoring (routed to Hermiq behind ADR-005, separate change);
  cross-tenant benchmarking (`dashboard` spec already routes this to launchpad); SCORM-native time-on-task
  (SCORM content is a compatibility shim per ADR-002 — xAPI is the primary runtime this change instruments).
