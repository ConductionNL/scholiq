# Design: engagement-gamification

## Context

Scholiq has the credentialing half of gamification (Open Badges 3.0, `certification` spec) but nothing
between "learner does something" and "learner earns a badge at the end of a module." Odoo, Docebo,
TalentLMS, Litmos, iSpring, eFront, LearnUpon, and Absorb Engage all ship a points/levels/leaderboard layer
that rewards the small, frequent actions (finishing a lesson, submitting on time, passing an assessment,
keeping a streak) — the momentum mechanic that keeps a learner coming back between the rarer badge
milestones.

This design reuses three already-proven register patterns rather than inventing new ones:
1. **Config object → append-only evidence row**, e.g. `AttendanceThreshold` → `AttendanceFlag`
   (`lib/Settings/scholiq_register.json:8181-8412`).
2. **Cross-schema roll-up computed in PHP because no `sum` aggregation metric exists**, e.g.
   `CurriculumPlan`/`GradeEntry` → `FinalGrade` via `GradeFormulaEvaluator`
   (`lib/Grading/GradeFormulaEvaluator.php`), and `BsaTrajectory` → `ectsEarned` via
   `BsaProgressEvaluator` (`openspec/changes/archive/2026-07-13-bsa-study-progress-guard/`).
3. **A narrow, authorizing controller when RBAC has no cross-object primitive to express the read**, the
   same gap `SupportRequest`/`TlvApplication`/`PupilVoice` (`zorgvraag-swv-tlv-chain`) already documented.

## Goals / Non-Goals

**Goals**: award points for real, already-firing HEAD events; make totals/levels/streaks visible to the
learner privately at all times; make a *ranked, peer-visible* view strictly opt-in per cohort/course and
never forced on a learner who wants out.

**Non-Goals**: peer-review points (peer review is unbuilt — `openspec/specs/assignments/spec.md:95`); a
tenant-wide "enable gamification" toggle (see Rejected Alternatives); cross-tenant or cross-school
leaderboards; a generic configurable-JSON-logic points engine (four concrete `PointRule.kind` values only —
extending the enum is a small, low-risk follow-up, not a generalised rule DSL).

## Data Model

### `PointRule` (config)

Tenant-scoped, `draft → active → archived` lifecycle (identical shape to `AttendanceThreshold`).

| field | type | notes |
|---|---|---|
| `name` | string | human label, e.g. "On-time submission bonus" |
| `kind` | enum | `enrolment-completed` \| `submission-on-time` \| `finalgrade-passed` \| `streak-milestone` |
| `points` | number | flat points awarded per firing |
| `milestoneDays` | integer, nullable | only meaningful for `kind: streak-milestone` (e.g. 7, 30) |
| `scope` | object, nullable | optional `cohortId`/`courseId` restriction; null = tenant-wide |
| `active` | boolean | mirrors `AttendanceThreshold.active` |
| `lifecycle` | enum | `draft` \| `active` \| `archived` |
| `tenant_id` | uuid | multi-tenant isolation |

No `x-openregister-authorization` block — mirrors `AttendanceThreshold`, which also has none; creation is
gated by the same app-wide admin/coordinator posture every other config object in this register relies on.

### `PointAward` (append-only ledger)

`appendOnly: true` (mirrors `AttendanceFlag`/`AssessmentResult`). Created **only** by
`PointAwardTriggerHandler` / `LearnerEngagementRollupHandler` — never directly by a learner, so a learner
cannot retroactively pad their own score.

| field | type | notes |
|---|---|---|
| `learnerId` | string | NC user id |
| `pointRuleId` | uuid, `$ref: PointRule` | which rule fired |
| `points` | number | copied from `PointRule.points` at award time (a later edit to the rule does not retroactively reprice past awards — same "immutable evidence" reasoning as `AttendanceFlag.metricValue`) |
| `sourceKind` | enum | `enrolment` \| `submission` \| `grade-entry` \| `streak-milestone` |
| `sourceObjectId` | uuid, nullable | the `Enrolment`/`Submission`/`GradeEntry` (via its `curriculumPlanId`) that fired the rule; null for `streak-milestone` |
| `awardedAt` | date-time | |
| `tenant_id` | uuid | |

`x-openregister-notifications.pointsAwarded` (`created` trigger, recipient `field: learnerId`) — mirrors
`AttendanceFlag.flagRaised`'s shape exactly.

### `EngagementLevel` (config)

Tenant-scoped, one row per level (no lifecycle needed — a level either exists or is deleted, like
`GradeScale.bands[]` entries but promoted to standalone rows for independent editability, mirroring
`AttendanceThreshold`'s row-per-rule precedent rather than a buried array a coordinator has to edit
in-place).

| field | type | notes |
|---|---|---|
| `name` | string | e.g. "Bronze", "Silver" |
| `order` | integer | ascending display/comparison order |
| `minPoints` | number | inclusive threshold |
| `icon` | string, nullable | Material Design Icons name, matches every other schema's `icon` convention |
| `tenant_id` | uuid | |

### `LearnerEngagement` (derived roll-up)

`x-openregister: {active: true, hardDelete: false, searchable: true, readOnly: true}` — mirrors
`FinalGrade` exactly. One row per `(learnerId, tenant_id)`. **Never written by the frontend.**

| field | type | notes |
|---|---|---|
| `learnerId` | string | |
| `totalPoints` | number | sum of the learner's `PointAward.points`, computed by `PointEngagementEvaluator` (no `sum` aggregation metric exists in this register — see Why) |
| `levelId` | uuid, nullable, `$ref: EngagementLevel` | highest `EngagementLevel` whose `minPoints <= totalPoints` |
| `currentStreakDays` | integer | consecutive calendar days (ending today or yesterday) with ≥1 `PointAward` |
| `longestStreakDays` | integer | historical max of the above |
| `lastActivityDate` | date, nullable | most recent `PointAward.awardedAt` date |
| `lastRecomputedAt` | date-time, nullable | mirrors `FinalGrade.lastRecomputedAt` |
| `tenant_id` | uuid | |

`x-property-rbac.read`: `anyOf: [{role: admin}, {match: {field: learnerId, operator: eq, value: $userId}}]`
— identical shape to `GradeEntry`/`FinalGrade`/`Submission`. This is deliberately **not** opened up to
cohort-mates (see next section) — a learner's own dashboard widget reads their own row via this self-match;
peer-visible rankings go through `LeaderboardController` instead.

### `Leaderboard` (config/policy — the opt-in mechanism)

Tenant-scoped, `draft → active → archived` (mirrors `CurriculumPlan`/`GradeScale`).

| field | type | notes |
|---|---|---|
| `name` | string | |
| `cohortId` | uuid, nullable, `$ref: Cohort` | mutually exclusive with `courseId`, mirrors `FinalGrade.courseId`/`programmeId`'s dual-scope shape |
| `courseId` | uuid, nullable, `$ref: Course` | |
| `topN` | integer, nullable | display-limit hint for the frontend; null = show all non-opted-out members |
| `lifecycle` | enum | `draft` \| `active` \| `archived` |
| `tenant_id` | uuid | |

No ranking data is stored on `Leaderboard` itself — see "Why `LeaderboardController` computes the ranking
live" below.

## Why the RBAC gap forces a controller, not a raw-object read

Every existing `x-property-rbac` block in this register (`LearnerProfile`, `GradeEntry`, `Submission`,
`FinalGrade`) expresses read access as `role` checks plus one self-comparison
(`match: {field, operator: eq, value: "$userId"}`). There is no precedent anywhere for "any user whose id
appears in `Cohort.learnerIds`" — a cross-object membership check. Three schemas already hit this exact
wall (`SupportRequest`, `TlvApplication`, `PupilVoice`, added by `zorgvraag-swv-tlv-chain`) and each
documents it in an `_comment` as a known platform gap rather than inventing a primitive that doesn't exist
(`lib/Settings/scholiq_register.json:7269-7275,7436-7442,7765-7771`).

If `LearnerEngagement`'s RBAC were loosened to let cohort-mates read each other's rows, that would also
leak `totalPoints`/`level`/streaks to any cohort member regardless of the target's opt-out preference or
whether a `Leaderboard` is even active for that cohort — the opt-out and opt-in gates would have nowhere to
attach at the object level.

`LeaderboardController::getRankings(cohortId)` (`#[NoAdminRequired]`, per-request authorization in the
method body: admin, or caller's NC user id in `Cohort.learnerIds`/`teacherIds`) is therefore where all four
gates compose in one place:
1. An `active` `Leaderboard` row exists for that cohort/course (the opt-in gate).
2. The caller is a member of that cohort (the authorization gate `x-property-rbac` can't express).
3. Each candidate learner's `pref_leaderboardOptOut` preference (read via the existing `preferences-api`
   IConfig-backed store — a plain server-side `IConfig::getUserValue()` call, no cross-object RBAC needed
   because it isn't an OR object) is not set (the per-learner opt-out gate).
4. The response is a minimal `{learnerId, totalPoints, level, rank}` projection, not the raw
   `LearnerEngagement` object — the controller never exposes more than a leaderboard needs.

This is not a pass-through CRUD wrapper (`hydra-gate-redundant-controller`/ADR-022 concern): OR's object API
already serves plain CRUD/list/filter directly to the frontend for every other object in this change
(`PointRule`, `EngagementLevel`, `PointAward`, and a learner's own `LearnerEngagement` row all go through it
unmodified). `LeaderboardController` exists only for the one read that genuinely cannot be expressed as a
declarative RBAC rule today — the same class of exception `RolloverController`/`LrsController` already are
in this codebase.

## Event → points mechanics

`PointAwardTriggerHandler` listens on `ObjectTransitionedEvent`, filtered to the `scholiq` register, for
exactly three real transitions (mirrors `GradeRollupHandler`'s dual-purpose `handle()` dispatch shape):

1. **`Enrolment` → `completed`** — the transition `XapiCompletionHandler` already dispatches when a
   mandatory-training final lesson's xAPI verb is `completed`/`passed`. No new xAPI parsing — this change
   listens one hop downstream of infrastructure that already exists and already fires.
2. **`Submission` → `submitted`**, where the submission's materialised `isLate` calculation is `false` —
   read directly off the transitioned object's payload (`isLate` is `materialise: true`, so it is present on
   the entity at transition time, same as `GradeRollupHandler` reads `AssessmentResult` payload fields).
3. **`GradeEntry` → `published` or `republish`** — `PointAwardTriggerHandler` calls
   `GradeFormulaEvaluator::evaluate($curriculumPlanId, $learnerId)` directly (constructor-injected, the same
   class `GradeRollupHandler` already injects) rather than reading `FinalGrade.passed` off a possibly
   stale/not-yet-recomputed object — this avoids an event-ordering dependency on `GradeRollupHandler` having
   already run in the same dispatch cycle, exactly the reasoning `BsaProgressFlagHandler` used for injecting
   `BsaProgressEvaluator` directly instead of reading a `FinalGrade` another listener might not have written
   yet.

For each matching transition, the handler looks up `active` `PointRule`s of the matching `kind` (optionally
scope-filtered), and for each match creates a `PointAward` **idempotency-keyed** by
`(learnerId, pointRuleId, sourceObjectId)` — before creating, it queries for an existing `PointAward` with
that triple and skips if found (mirrors `BsaProgressFlagHandler`'s `OPEN_FLAG_STATES` existing-row check).
This matters because `GradeEntry` can `republish` after a `revise`, and `Enrolment.complete` should only
ever award once per enrolment.

`LearnerEngagementRollupHandler` listens on `PointAward`'s `ObjectCreatedEvent`, finds-or-creates the
learner's `LearnerEngagement` row, and calls `PointEngagementEvaluator::evaluate($learnerId, $tenantId)`
which:
- Sums all `PointAward.points` for the learner → `totalPoints`.
- Resolves `levelId` as the highest `EngagementLevel` (ordered by `minPoints` desc) whose `minPoints <=
  totalPoints`.
- Computes streak: sorts distinct `PointAward.awardedAt` calendar dates descending; `currentStreakDays`
  counts back from today (or yesterday, so a learner who was active yesterday but hasn't yet acted today
  keeps their streak alive through the day) while consecutive dates exist; `longestStreakDays` is
  `max(existing longestStreakDays, currentStreakDays)`.

Then, **only if the triggering award's `sourceKind` is not itself `streak-milestone`** (the recursion
guard — a milestone bonus award must not re-trigger its own milestone check), the handler compares the
newly computed `currentStreakDays` against every active `PointRule(kind: streak-milestone)`'s
`milestoneDays`; for any threshold newly crossed (`previousStreak < milestoneDays <= newStreak`), it awards
a bonus `PointAward(sourceKind: streak-milestone, sourceObjectId: null)`. That second award re-enters
`LearnerEngagementRollupHandler` once more to fold the bonus into `totalPoints`/`levelId`, but the recursion
guard means it terminates after exactly one extra pass — no infinite loop.

**Why this is a PHP evaluator, not a declarative `x-openregister-aggregations` `sum`**: a grep of every
`"metric":` value across `lib/Settings/scholiq_register.json` returns only `count` and `count_distinct` —
never `sum`, `avg`, or `max`, anywhere in this register. `FinalGrade.value` and `BsaTrajectory`'s
`ectsEarned` both hit this exact limitation and both resolved it the same way: a constructor-injected PHP
evaluator (`GradeFormulaEvaluator`, `BsaProgressEvaluator`). This is not a design preference — it is the
established, repeated precedent for "sum a numeric field across a filtered set of related objects" in this
codebase, followed here rather than assuming an unverified `sum` metric exists in OpenRegister core (which
is not present in this repo to confirm against — same caveat `bsa-study-progress-guard`'s design.md already
flagged for its own `count_distinct` usage).

## Pedagogical posture: opt-in, not opt-out, and never forced

Specter's competitive research for this app found that grade-pressure and social comparison between pupils
is consistently the #1 pupil complaint in Dutch schools. A public points ranking is the single feature in
this change most capable of causing real harm if it ships as an always-on default — turning "I finished my
homework" into "I am ranked below my classmates" for every learner, every day.

This design makes that impossible by construction, not by convention:

1. **No `Leaderboard` row = no ranking, anywhere.** There is no seed data, no default-created leaderboard,
   and no tenant-wide toggle that silently activates ranking for every cohort at once. A coordinator must
   explicitly create *and* `activate` a `Leaderboard` for one specific cohort or course before any ranked
   view can render for it.
2. **`LeaderboardController` refuses to serve a ranking without an `active` `Leaderboard` row** — this is
   enforced at the one read path that can produce a ranking at all, not left to the frontend to "remember"
   to check.
3. **A learner's own points/level are always visible to them, unconditionally** — opting out of the
   *ranking* never hides a learner's own progress from themselves; it only removes them from other people's
   view. `LearnerEngagement`'s self-read RBAC is independent of `Leaderboard`/opt-out state entirely.
4. **Opt-out is a standing preference, not a one-time consent click** — stored via the existing
   `preferences-api` (`pref_leaderboardOptOut`), so a learner (or, realistically, a parent/mentor advising
   them) can flip it at any time from the `LeaderboardView` itself, and it applies retroactively to every
   `LeaderboardController` call from that point on, not just future point awards.

## Rejected Alternatives

- **A tenant-wide `EngagementPolicy.leaderboardsEnabled` boolean, in addition to per-`Leaderboard`
  `active`.** Rejected as a redundant second on/off switch: the per-`Leaderboard` `draft`/`active` lifecycle
  already IS the opt-in decision, scoped exactly where it needs to be (one cohort/course, not the whole
  tenant). A second tenant-level gate would only add a second place a coordinator has to remember to check,
  for no additional safety — "no row exists" is already the strictest possible default. Revisit only if a
  buyer specifically asks for a hard tenant-wide kill switch independent of individual `Leaderboard` rows.
- **`leaderboardOptOut` as a new `LearnerProfile` field.** Rejected: no existing capability spec "owns" a
  `Persist LearnerProfile` requirement to attach a MODIFIED delta to (verified — no spec in
  `openspec/specs/*/spec.md` has a `LearnerProfile`-persistence requirement), and `LearnerProfile` is a
  readOnly-by-convention identity record populated by learner administration, not a learner-editable
  preferences surface. The already-shipped, purpose-built `preferences-api` is the correct, precedented home
  for "a learner's own persisted UI flag" and needed zero new schema or RBAC surface.
- **Loosen `LearnerEngagement`'s RBAC to allow cohort-mate reads.** Rejected: no cross-object RBAC primitive
  exists in this register (see "Why the RBAC gap forces a controller" above); loosening it would also leak
  points/level/streak to cohort-mates regardless of `Leaderboard` opt-in state or the target's opt-out —
  exactly the "forced ranking" outcome this change is designed to prevent.
- **Cross-schema `x-openregister-aggregations` with a `sum` metric for `totalPoints`.** Rejected as an
  unverified assumption: `sum` has zero precedent anywhere in this register (only `count`/`count_distinct`
  are used), and OpenRegister core is not present in this repo to confirm the metric exists at all. Follows
  `FinalGrade`/`BsaTrajectory`'s proven PHP-evaluator path instead.
- **Materialising the leaderboard ranking onto the `Leaderboard` object itself** (a `rankings[]` array,
  scheduled-recomputed). Rejected for this S/M scope: it would require either a `TimedJob` (forbidden by
  ADR-022 for this kind of on-demand read) or re-deriving the exact same authorization logic
  `LeaderboardController` already needs to compute the ranking live, for no benefit — the controller call is
  cheap (bounded by cohort size) and always reflects the current opt-out state instead of a possibly-stale
  snapshot.

## Security / Privacy Posture

- `PointAward` is append-only — no endpoint or role can edit or delete an award once created, so a
  compromised or malicious teacher/admin account cannot retroactively erase evidence of how a score was
  reached (though `hardDelete: false` soft-delete remains available for AVG erasure requests at the tenant
  level, same as every other schema).
- `PointAward.tenant_id` and `LearnerEngagement.tenant_id` enforce the same multi-tenant isolation as every
  other schema — no leaderboard or points total is ever visible across tenants.
- `LeaderboardController` never returns raw `LearnerEngagement` objects or any field beyond
  `{learnerId, totalPoints, level, rank}` — no PII beyond the id the caller could already resolve via
  `Cohort.learnerIds` (which they must already be a member of to call the endpoint at all).
- No AI is involved anywhere in this change — no `AiFeature` gate applies.

## Per-App Architecture Rules Checked

- Data lives in `lib/Settings/scholiq_register.json`; every new property has an English `title` +
  `description`; relations use `type: string` + `format: uuid` + `$ref`.
- Declarative first: lifecycle (`x-openregister-lifecycle`) and notifications
  (`x-openregister-notifications`) are used everywhere the pattern applies; PHP is used only for the three
  cross-object computations (`sum`, streak-gap detection, cross-schema pass lookup) that have no declarative
  precedent in this register, each justified against a specific existing precedent above.
- `LeaderboardController` carries an explicit `#[NoAdminRequired]` attribute, a per-request authorization
  check in the method body, and a route entry in `appinfo/routes.php` — satisfies `hydra-gate-route-auth`,
  `hydra-gate-no-admin-idor`, and `hydra-gate-route-reachability`.
- UI is `src/manifest.json`-driven; the one custom view (`LeaderboardView.vue`) is a genuine
  controller-backed surface OR's declarative list/filter cannot serve, mirroring `BsaRiskDashboard.vue`'s
  precedent as the sole custom-UI exception in a recent change of similar size.
- i18n keys stay in English (en + nl catalogues); SPDX docblocks on all new PHP.
