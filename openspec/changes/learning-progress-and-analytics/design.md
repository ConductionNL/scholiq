# Design: learning-progress-and-analytics

## Context

Scholiq has two verified gaps (see `proposal.md` "Why", every claim grounded against HEAD):

1. There is no per-`Lesson` completion state and no `Enrolment` progress percentage. The completion *signal*
   already exists (`XapiStatement` + `XapiCompletionHandler`) but is consumed only for the last lesson of
   mandatory-training courses, to flip `Enrolment.lifecycle` to `completed` — never persisted per lesson.
2. There is no engagement/at-risk signal that applies beyond the HE/MBO-only, ECTS-only `BsaTrajectory`
   guard, and the `dashboard` spec's claimed "skill-area heat map" was never built.

This document works out the data model, which parts are genuinely new derivation logic vs. which parts are
free UI over data that already exists, and the PHP/declarative split — reusing the `FinalGrade`/
`GradeRollupHandler` and `BsaTrajectory`/`BsaProgressFlagHandler` precedents throughout rather than inventing
a third pattern.

## Goals / Non-Goals

**Goals**
- Persist per-learner, per-`Lesson` completion state, sourced from the existing xAPI completion signal
  (wired, not duplicated) plus a manual self-report path for non-xAPI content.
- Persist a per-`Enrolment` progress percentage as a declared aggregate + a narrowly-scoped PHP roll-up
  (the DSL has no division operator — verified, see proposal.md), mirroring `FinalGrade.value`'s shape.
- Persist a per-learner, per-`Course` engagement score (time-on-task + activity recency) derived from
  `XapiStatement`, and a rule-based at-risk flag that applies to any course/programme, not just HE/MBO
  BSA-eligible ones.
- Give the already-promised (but unbuilt) cohort/group test-score trend a real rendering surface, reusing
  existing `GradeEntry` data — no new schema for the trend itself.
- Reuse existing declarative machinery (aggregate-refs, calculatedChange-style triggers, append-only Flag
  pattern) — no new parallel mechanism (ADR-022).

**Non-Goals**
- Any AI/ML/predictive at-risk scoring. `EngagementRiskThreshold`/`EngagementRiskFlag` are deterministic
  threshold comparisons over `EngagementScore` fields — no inference. A future predictive extension is a
  separate change routed through Hermiq's `agentaifeature` register behind the ADR-005 gate (see
  `ai-feature-delegate-to-hermiq`, concurrently removing Scholiq's *local* AI-feature governance surface —
  the opposite direction from adding a new local AI feature here).
- Generalising `AttendanceThreshold` into a fully generic "any metric" threshold engine.
  `BsaTrajectory`'s own design.md already made and documented this call ("cheaper and lower-risk... a future
  refactor could unify them, but is out of scope"); `EngagementRiskThreshold` mirror-clones the same shape a
  third time rather than reopening that refactor here.
- SCORM-native time-on-task instrumentation. ADR-002 makes xAPI the primary content runtime and SCORM a
  compatibility shim; this change instruments the primary runtime only.
- Cross-tenant / sector-wide benchmarking — `dashboard` spec already routes heavier cross-tenant analytics
  to launchpad; this change stays tenant-local.
- A generic BI/report-builder. `dashboard` spec's own Out of Scope already excludes a custom-report builder.

## What is genuinely new derivation logic vs. what is free abstraction

This is the honesty check the brief asked for, stated plainly:

| Piece | New persisted state / logic? | Why |
|---|---|---|
| `LessonCompletion` | **Yes — new.** | No per-lesson fact exists anywhere today. |
| `Enrolment.progressPercent` | **Yes — new (small PHP).** | Division isn't expressible in the JSON-logic DSL at HEAD; a 6-line PHP class is genuinely needed, same as `FinalGrade.value`. |
| `EngagementScore.timeOnTaskMinutes`/`.score` | **Yes — new (PHP).** | Summing nested `XapiStatement.result` duration fields has no declarative `sum` operator anywhere in this register (verified). |
| `EngagementScore.activityCount` | **Yes, but pure declarative.** | A plain `x-openregister-aggregate-refs` count — no PHP. |
| `EngagementScore.recencyDays` | **Yes, but pure declarative.** | `materialise: true` `dateDiff`, identical shape to `Enrolment.daysRemaining`. |
| `EngagementRiskThreshold` / `EngagementRiskFlag` | **Yes — new, but a mirror-clone of an existing pattern**, not a new mechanism. | Same shape as `AttendanceThreshold`/`AttendanceFlag` and `BsaTrajectory`/`BsaProgressFlag`. |
| Cohort/group test-score **data** | **No — already exists.** | `GradeEntry.cohortId` + `GradeEntry.gradedAt` are already there; no new schema. |
| Cohort/group trend **chart** | **One new custom view.** | `dashboard` spec claimed this shipped (Acceptance Criteria); grep of `src/views/**` proves it did not. This change is the first real implementation, not a duplicate. |
| KPI tiles for `progressPercent` / `EngagementScore` / open `EngagementRiskFlag` counts | **No new component.** | Declarative `config.widgets` entries on the existing `CnDashboardPage` teacher/manager routes — the dashboard capability's `KpiCard`/`Kpi*Widget.vue` pattern already used by `KpiActiveEnrolmentsWidget.vue` etc. |
| Export / drill-down | **No new code.** | OpenRegister's existing object API (`list`/`filter`/`aggregate`/`export`) serves it directly, per the architecture rule that apps consume OR abstractions. |

## Data Model

```
Lesson ──< XapiStatement (existing; verb.id completed|passed → XapiCompletionHandler already
              consumes this for the FINAL mandatory lesson only, to flip Enrolment.lifecycle)
              │
              │ (same ObjectCreatedEvent, sibling listener — NOT a XapiCompletionHandler edit)
              ▼
        LessonProgressHandler ──creates/updates──> LessonCompletion (learnerId, lessonId, source, completedAt)
              ▲
              │ (learner self-report for non-xAPI content)
        manual create (source: manual), mirrors AssessmentResult's unrestricted self-serve create

Enrolment (learnerId, courseId)
   │  x-openregister-aggregate-refs: completedLessonCount (from lesson-completion),
   │                                  totalPublishedLessonCount (from lesson, lifecycle=published)
   │  progressPercent (plain field, PHP-written)
   ▲
   └── EnrolmentProgressRollupHandler (listens LessonCompletion writes)
          └── EnrolmentProgressEvaluator (PHP: round(completed/total*100), null-safe)

XapiStatement ──< EngagementSignalHandler (same event source as LessonProgressHandler, independent listener)
                       │
                       ├─ EngagementScoreEvaluator (PHP: sum result durations, max timestamp)
                       │     └─> EngagementScore (learnerId, courseId, activityCount[declarative],
                       │           timeOnTaskMinutes[PHP], lastActivityAt[PHP], recencyDays[declarative
                       │           dateDiff], score[PHP])
                       │
                       └─ compares EngagementScore against active EngagementRiskThreshold(s)
                             └─> EngagementRiskFlag (appendOnly; open → in-handling → resolved)
                                   idempotency-keyed like AttendanceFlag/BsaProgressFlag

GradeEntry (existing; cohortId, gradedAt, period, value) ──queried by── GroupTrendHeatmap.vue
   (OR's existing aggregate/list API — no new schema)
```

### `LessonCompletion`

One row per `(learnerId, lessonId)` — an upsert target, not an append-only log (the append-only source of
truth for the xAPI half is already `XapiStatement` itself; `LessonCompletion` is the derived per-pair state,
same relationship `FinalGrade` has to `GradeEntry`). Fields: `learnerId`, `learnerRef` (nullable `$ref
LearnerProfile`, mirrors the portal-scoping pattern on `Enrolment`/`FinalGrade`), `lessonId` (`$ref Lesson`),
`courseId` (`$ref Course`, denormalized — mirrors `XapiStatement.courseId`/`.lessonId`), `enrolmentId`
(nullable `$ref Enrolment`, resolved at write time the same way `XapiCompletionHandler` resolves the active
enrolment today), `source` (`xapi | manual`), `verb` (nullable string, the xAPI verb IRI when `source:
xapi`), `score` (nullable number, from `result.score.scaled` when present), `completedAt` (date-time),
`tenant_id`. No `x-openregister-lifecycle` — a completion fact has no workflow states to move through.

**Authorization**: no `x-openregister-authorization.create` restriction — mirrors `AssessmentResult`
(`lib/Settings/scholiq_register.json:4938-5100`), not the stricter `xapi-statement` admin-only stopgap. The
two are different risk classes: `xapi-statement` is raw LRS evidence with academic/compliance weight (a
forged statement can trigger a real credential or attestation); `LessonCompletion` is a low-stakes progress
marker (self-reporting "I read this text lesson" inflates only *your own* progress %, with no downstream
grade, credential, or compliance effect). `x-property-rbac.read`: learner sees own rows; admin/teacher of
the course's cohort see all (mirrors `FinalGrade`'s admin+self shape, teacher added since progress is a
teaching-relevant signal `FinalGrade` doesn't need to expose as broadly).

**xAPI-sourced writes are server-authored** by `LessonProgressHandler`, so the schema-level authorization
looseness above only matters for the `manual` path; the xAPI path never touches user-supplied `learnerId` —
`LessonProgressHandler` reads `verified_actor_id` exactly as `XapiCompletionHandler` already does (same C6
trust-boundary reasoning, `lib/Lifecycle/XapiCompletionHandler.php:216-221`).

### `LessonProgressHandler`

New PHP listener (ADR-031 legitimate exception: event-to-object-write bridge). Listens for OR's
`ObjectCreatedEvent` on `xapi-statement` — the **same** event `XapiCompletionHandler` already consumes.
Deliberately a separate class, not an edit to `XapiCompletionHandler`, for two reasons: (1) single
responsibility per ADR-031 — `XapiCompletionHandler`'s job is "decide whether an `Enrolment` completes";
this handler's job is "record that a `Lesson` was completed" — different questions with different guards;
(2) `XapiCompletionHandler`'s mandatory-training/last-lesson gates are deliberate compliance-attestation
logic (feeds `Attestation.xapiStatementId`) that must **not** loosen just because progress-tracking wants a
broader trigger. Guards: verb is `completed`/`passed` (reuse `XapiCompletionHandler::COMPLETION_VERBS`),
`lessonId` resolves to a `Lesson` — **no** `mandatoryTraining` filter, **no** last-lesson filter. Upserts
`LessonCompletion` for `(verified_actor_id, lessonId)`.

### `Enrolment` (MODIFIED)

Additive only. `x-openregister-aggregate-refs.completedLessonCount` (`schema: lesson-completion, metric:
count, filters: {learnerId: "@self.learnerId", courseId: "@self.courseId"}`) and `.totalPublishedLessonCount`
(`schema: lesson, metric: count, filters: {courseId: "@self.courseId", lifecycle: "published"}`) — the
declarative read-surface, mirroring the `Praktijkovereenkomst.isFullySigned` pattern
(`lib/Settings/scholiq_register.json:10644-10666`). `progressPercent` (`number`, nullable, "Derived — do not
set manually", mirrors `FinalGrade.value`'s own description) is a plain field written by
`EnrolmentProgressRollupHandler` calling `EnrolmentProgressEvaluator::evaluate()` — not a `materialise: true`
expression, because (as established above) no division operator exists. `x-openregister-triggers.
calculatedChange` documents the wiring exactly like `FinalGrade`'s own block does, pointing at the new
handler's FQCN.

### `EngagementScore`

One row per `(learnerId, courseId)`. `activityCount` is a genuine `x-openregister-aggregate-refs` count
(`schema: xapi-statement, metric: count, filters: {verified_actor_id: "@self.learnerId", courseId: "@self.
courseId"}`) — no PHP needed for a count, same as `Praktijkovereenkomst`'s signature counts.
`timeOnTaskMinutes` and `score` are PHP-computed by `EngagementScoreEvaluator` (parses each matching
`XapiStatement.result` for a duration extension, sums to minutes; `score` is a bounded 0–100 combination of
time-on-task against the course's total `Lesson.durationMinutes` and `recencyDays` decay — a plain weighted
formula, not a model). `lastActivityAt` is PHP-set (max `XapiStatement.timestamp`). `recencyDays` **is**
declarative: `materialise: true`, `dateDiff` from `lastActivityAt` to `@now`, byte-for-byte the same idiom as
`Enrolment.daysRemaining` (`lib/Settings/scholiq_register.json:1629-1654`).

### `EngagementRiskThreshold` / `EngagementRiskFlag`

Structural mirror of `AttendanceThreshold`/`AttendanceFlag` (config: `name`, `kind` [`low-engagement` |
`generic`], `scope` [`per-learner` | `per-cohort`], `cohortId` nullable, `metric` [`engagement-score-below` |
`recency-days-above`], `limit`, `onAtRisk` [`notify`/`notifyRoles`/`createFlag`], `lifecycle: draft → active
→ archived`) and `BsaProgressFlag` (append-only, `open → in-handling → resolved`, human-in-the-loop — it
never auto-acts against the learner). Deliberately **not** scoped to HE/MBO or to `ectsCredits`-bearing
courses — it applies to any `Course`/`Cohort`, closing the gap the BSA-only signal leaves for `po`/`vo`/
corporate learners and for HE/MBO learners who are disengaging before any grade reflects it.

**Detection is combined into one handler**, `EngagementSignalHandler`, mirroring `BsaProgressFlagHandler`'s
most-recently-established shape (evaluate + compare + flag in one event-driven class off a real upstream
event) rather than the older `AttendanceThreshold`/`AttendanceFlagCreationHandler` split across a synthetic
`calculatedChange` marker event and a second handler. It listens to the same `XapiStatement` `ObjectCreated
Event`, calls `EngagementScoreEvaluator`, saves the updated `EngagementScore`, then checks active
`EngagementRiskThreshold`s in scope and idempotency-keys the resulting `EngagementRiskFlag` (no duplicate
flag while one is already `open`/`in-handling` for the same learner+threshold).

### `GroupTrendHeatmap.vue`

The one genuine custom view (mirrors `BsaRiskDashboard.vue`'s "the only genuine custom UI" precedent). Reads
`GradeEntry` via OR's existing list/aggregate API, grouped by `cohortId` × `period`, colour-banded by average
`value` (mirrors the ILIAS-cited colour-coded-per-object competitor pattern). No new schema. Scoped to
teacher/admin roles, added as a manifest page + a nav entry off the existing Teaching/Administration
dashboard, not a nested `CnDashboardPage` (respects the `dashboard` spec's single-`CnDashboardPage`-per-route
rule).

## Security / RBAC posture

- `LessonCompletion`: read scoped to self + admin + course teacher (see above); xAPI-sourced writes are
  server-authored via the already-hardened `verified_actor_id` boundary; manual writes carry the same
  "self-reported, low-stakes" risk profile as `AssessmentResult` already accepted.
- `Enrolment.progressPercent`/aggregate-refs: inherits `Enrolment`'s existing read scoping (unchanged) —
  purely additive fields, no new RBAC surface.
- `EngagementScore`: read scoped to self + admin + course teacher (progress/engagement is a teaching signal,
  same reasoning as `LessonCompletion`).
- `EngagementRiskThreshold`: `x-openregister-authorization.create` restricted to `admin`/`coordinator`
  (config, not learner data) — mirrors `AttendanceThreshold`'s implicit admin-configured posture.
- `EngagementRiskFlag`: `x-openregister-authorization.create` restricted to system (handler-created only,
  like `BsaProgressFlag`/`AttendanceFlag`); read scoped to admin/coordinator/mentor + the flagged learner's
  own record — mirrors `BsaProgressFlag`'s "never auto-acts against the learner" human-in-the-loop posture.
- No new controller, no new route — every write in this change is either a declarative aggregate, a
  lifecycle-guard-equivalent PHP listener (ADR-031), or a plain `ObjectService::saveObject` call from a
  learner-authenticated session using OpenRegister's existing per-object authorization (no pass-through CRUD
  controller, per ADR-022).

## Rejected alternatives

- **Store `progressPercent` as a `materialise: true` JSON-logic expression.** Rejected — no division
  operator exists in the DSL at HEAD (verified by a full scan of every calculation in the register); forcing
  it would mean inventing a new engine capability out-of-band from a single app change, which is out of
  scope and inconsistent with how `FinalGrade`/`BsaTrajectory` already solved the identical shape of problem.
- **Extend `XapiCompletionHandler` itself to also write `LessonCompletion`.** Rejected — conflates two
  different questions (enrolment-completion compliance gate vs. lesson-progress tracking) in one handler and
  risks accidentally loosening `XapiCompletionHandler`'s deliberate mandatory-training/last-lesson guards.
  A sibling listener on the same event is cheaper and safer.
- **Extend `AttendanceThreshold` to a generic "any metric" engine instead of cloning `EngagementRisk
  Threshold`.** Rejected for the same reason `BsaTrajectory` already rejected it (see its own design.md) —
  consistent precedent, not a third ad-hoc decision.
- **Build the cohort/group trend as a new persisted `GroupTrendSnapshot` schema (e.g. a nightly rollup).**
  Rejected — `GradeEntry` already carries every field the trend needs (`cohortId`, `gradedAt`, `value`); a
  snapshot schema would be a parallel, staler copy of data OR's aggregate API can already serve live. This
  is the honesty point from the brief: build the view, not a duplicate data store.
- **Gate `EngagementRiskFlag` behind the ADR-005 `AiFeature`/AI-Act flow.** Rejected — the detection is a
  deterministic threshold comparison, not an AI/ML feature; ADR-005 governs AI-assisted decisions
  (`AssessmentPublishGuard`'s proctoring gate is the existing example), and misclassifying a plain threshold
  as "AI" would be exactly the kind of governance-surface bloat `ai-feature-delegate-to-hermiq` is
  concurrently trying to shed.
