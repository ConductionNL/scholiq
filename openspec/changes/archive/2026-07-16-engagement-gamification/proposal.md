---
kind: code
depends_on: []
---

## Why

Scholiq already ships verifiable digital credentials — **Open Badges 3.0 is NOT the gap**. `certification`'s
`Requirement: Issue EDCI/Europass and Open Badges 3.0 credentials` (`openspec/specs/certification/spec.md:33-39`)
requires "an EDCI / Europass credential and Open Badges 3.0 badge ... each with a verifiable URL", and the
`Credential` schema in `lib/Settings/scholiq_register.json` carries a materialised `openbadges3Payload` /
`edciPayload` pair consumed all the way to `EudiWalletController` (`openspec/specs/certification/spec.md:181,185,198,211`).
Badges, as an artefact, are done.

What is missing is the **engagement layer that makes progress visible and motivating in the moment** — not
at course completion, but session to session. A full case-insensitive grep of every capability spec, the
59-schema register, and the 141-page manifest for `leaderboard|karma|gamif|points|streak` returns zero hits
for the concept of gamified engagement itself: the only `points` hits are `Rubric`/`Submission` marking
points (`openspec/specs/assignments/spec.md:22,24,31,39`) and `GradeEntry` "points contributed" to a grade
average (`openspec/specs/grading/spec.md:27,34,42`) — scoring vocabulary for grading, not an engagement
mechanic. No `PointRule`, `PointAward`, learner level, streak, or `Leaderboard` object exists anywhere in
the register.

**Demand:** 7-8 competitors ship a points/levels/leaderboard engagement layer as a named product surface —
Odoo (karma points), Docebo, TalentLMS, Litmos, iSpring, eFront, LearnUpon, and Absorb Engage (Absorb's
badges/points/leaderboards module is literally named "Engage"). Scholiq's own gap: it has the *credentialing*
half of gamification (badges) shipped, but none of the *momentum* half (points earned for the small,
frequent actions that keep a learner coming back between badge-earning milestones).

**What already exists to build on (verified at HEAD):**
- `XapiCompletionHandler` (`lib/Lifecycle/XapiCompletionHandler.php`) listens for `ObjectCreatedEvent` on
  `xapi-statement` objects and dispatches the `Enrolment` `complete` transition when a mandatory-training
  final lesson's xAPI verb is `completed`/`passed` — the real, already-firing "lesson/course completed"
  signal. `Enrolment`'s `lifecycle` (`lib/Settings/scholiq_register.json:1455-1547`) reaches `completed` only
  through that bridge.
- `Submission` (`lib/Settings/scholiq_register.json:4146-4372`) has a `submit` transition
  (`draft → submitted`, guarded by `SubmissionWindowGuard`) and a materialised `isLate` calculation
  (`eq: [{prop: lifecycle}, "late"]`, lines 4292-4305) — the real "assignment submitted on time" signal, no
  invention required.
- `GradeEntry` → `FinalGrade` (`lib/Settings/scholiq_register.json:5418-5868`) is the real "assessment
  passed" signal, but only at the roll-up level: `FinalGrade.passed` is computed by
  `GradeFormulaEvaluator::evaluatePassed()` (`lib/Grading/GradeFormulaEvaluator.php:485-538`) because pass/fail
  requires a cross-schema lookup of `GradeScale.passThreshold`/`bands[].pass`
  (`lib/Settings/scholiq_register.json:5309-5371`) that plain JSON-logic cannot express — there is no
  single-`GradeEntry`-level "passed" field to hook. `GradeRollupHandler` (`lib/Listener/GradeRollupHandler.php`)
  already listens on `GradeEntry` → `published`/`republish` and recomputes `FinalGrade` the same way this
  change's point-award listener must.
- **"Peer review given" from the wave-2 brief does NOT exist at HEAD and is dropped from this change's
  event list.** `openspec/specs/assignments/spec.md:95` explicitly lists "Peer review / peer grading" as
  "(a follow-up)" — not built. Inventing a peer-review event would violate the ground-truth rule; it is
  called out as a future `PointRule` kind once peer review ships, not built here.
- Append-only ledger precedent: `AttendanceFlag` (`appendOnly: true`,
  `lib/Settings/scholiq_register.json:8413-8420`) and `AssessmentResult` (`appendOnly: true`, line 4945) —
  "created once, never edited" evidence rows are an established register idiom (ADR-008).
  `AttendanceThreshold` → `AttendanceFlag` (config object with `draft → active → archived` lifecycle,
  firing a `calculatedChange`-triggered append-only row) is the exact shape this change reuses for
  `PointRule` → `PointAward`.
- Cross-schema roll-up precedent: `FinalGrade` is a `x-openregister: {readOnly: true}` derived object
  (`lib/Settings/scholiq_register.json:5727-5732`) recomputed by a PHP evaluator
  (`GradeFormulaEvaluator`) because the register has **no `sum` aggregation metric anywhere** — a grep of
  every `"metric":` value in `lib/Settings/scholiq_register.json` returns only `count` and `count_distinct`,
  never `sum`/`avg`/`max`. `BsaProgressEvaluator` (the just-archived `bsa-study-progress-guard` change) sets
  the same precedent for summing `Course.ectsCredits` across a learner's `FinalGrade`s in PHP rather than
  guessing at an unverified `sum` metric. This change's learner totals follow the identical, precedented
  path: PHP evaluator, not a declarative `sum` aggregation.
- `preferences-api` (`openspec/specs/preferences-api/spec.md`, status `done`) already exposes a generic
  per-user `pref_*` key/value store via `PreferencesController` — the precedented mechanism for "a learner
  wants to persist a personal cross-device flag without a bespoke endpoint." This change reuses it for the
  leaderboard opt-out rather than adding a new register field.
- `x-property-rbac` in this register only supports `role` checks and a single `match: {field, operator,
  value: "$userId"}` self-comparison (verified across `LearnerProfile`, `GradeEntry`, `Submission`,
  `FinalGrade`) — there is **no precedent for a cross-object "same cohort as me" RBAC rule**. Three existing
  schemas (`SupportRequest`, `TlvApplication`, `PupilVoice` in the `zorgvraag-swv-tlv-chain` change,
  `lib/Settings/scholiq_register.json:7269-7275,7436-7442,7765-7771`) hit the identical wall and each
  documents it as a "known platform gap" rather than inventing an RBAC primitive. This change hits the same
  wall for peer-visible leaderboard rankings and resolves it the same documented way: a narrow, authorizing
  controller (see design.md), not a raw-object RBAC rule that doesn't exist.

## What Changes

- **New `engagement` capability** with five OpenRegister objects:
  - **`PointRule`** — declarative event → points config (tenant-scoped, `draft → active → archived`,
    mirrors `AttendanceThreshold`). `kind` enum: `enrolment-completed` | `submission-on-time` |
    `finalgrade-passed` | `streak-milestone`. Each kind maps to a real, already-firing HEAD event (see
    Why) — no invented event sources.
  - **`PointAward`** — append-only ledger (`appendOnly: true`, mirrors `AttendanceFlag`/`AssessmentResult`):
    `learnerId`, `pointRuleId`, `points`, `sourceKind`, `sourceObjectId`, `awardedAt`, `tenant_id`. Created
    only by backend listeners, never directly by a learner — an append-only ledger that can't be
    retroactively edited to inflate a score.
  - **`EngagementLevel`** — tenant-scoped, admin-configurable ordered level thresholds (`name`, `order`,
    `minPoints`, `icon`), one row per level (mirrors `GradeScale.bands[]`'s shape as standalone,
    independently editable rows rather than a buried array, following `AttendanceThreshold`'s row-per-rule
    precedent).
  - **`LearnerEngagement`** — `x-openregister: {readOnly: true}` derived roll-up (mirrors `FinalGrade`
    exactly): `totalPoints`, `levelId`, `currentStreakDays`, `longestStreakDays`, `lastActivityDate`,
    `lastRecomputedAt`. Recomputed by a new PHP evaluator, never written by the frontend.
  - **`Leaderboard`** — tenant-scoped scope/policy object (`cohortId`/`courseId` nullable dual-scope,
    mirroring `FinalGrade`'s `courseId`/`programmeId` pattern), `draft → active → archived`. **Default OFF
    by construction**: no `Leaderboard` row exists until a coordinator/admin explicitly creates and
    activates one for a specific cohort or course — there is no global "enable gamification" switch to
    trip by accident, and archiving one instantly turns that leaderboard back off. See design.md for why
    this is the deliberate, minimal opt-in mechanism instead of a separate tenant-wide policy schema.
- **PHP** (all ADR-031 "event-to-object-write bridge" exceptions, mirroring `GradeRollupHandler` /
  `BsaProgressFlagHandler` exactly):
  - `OCA\Scholiq\Engagement\PointEngagementEvaluator` — pure computation: sums a learner's `PointAward`s,
    resolves `levelId` against `EngagementLevel` ordered by `minPoints`, computes current/longest streak
    from distinct `PointAward.awardedAt` calendar dates. Mirrors `GradeFormulaEvaluator`/
    `BsaProgressEvaluator`'s role exactly, for the same reason (no `sum` aggregation metric exists).
  - `OCA\Scholiq\Listener\PointAwardTriggerHandler` — listens on the three real `ObjectTransitionedEvent`s
    (`Enrolment → completed`, `Submission → submitted` where `isLate` is false, `GradeEntry →
    published`/`republish` calling `GradeFormulaEvaluator` directly for the pass check), looks up matching
    active `PointRule`s, and creates idempotency-keyed `PointAward`s (mirrors `BsaProgressFlagHandler`'s
    idempotency-by-existing-row check).
  - `OCA\Scholiq\Listener\LearnerEngagementRollupHandler` — listens on `PointAward` `ObjectCreatedEvent`,
    recomputes `LearnerEngagement` via `PointEngagementEvaluator`, and — only when the triggering award's
    `sourceKind` is not itself `streak-milestone` (recursion guard) — checks active
    `PointRule(kind: streak-milestone)` rows for a newly crossed threshold and awards a bonus `PointAward`.
  - `OCA\Scholiq\Controller\LeaderboardController` — **one narrow, non-CRUD controller** (`GET
    /api/leaderboard/{cohortId}`), `#[NoAdminRequired]` + an in-method authorization check (admin, or the
    caller's Nextcloud user id is in `Cohort.learnerIds`/`teacherIds`), requires an `active` `Leaderboard`
    row for that cohort, filters out learners whose `pref_leaderboardOptOut` preference (via the existing
    `preferences-api`) is set, and returns a minimal `{learnerId, totalPoints, level, rank}` projection —
    not raw `LearnerEngagement` objects. This exists precisely because the RBAC gap above means the raw OR
    object API cannot serve a peer-visible ranking; it is not a pass-through CRUD wrapper (ADR-031/
    `hydra-gate-redundant-controller`).
- **Frontend**: declarative `src/manifest.json` pages for `PointRule`/`EngagementLevel`/`Leaderboard`
  admin config, a learner's-own-points KPI widget reusing `src/views/widgets/*` on the existing student
  dashboard (`ScholiqDashboards role="student"`, per `openspec/specs/dashboard/spec.md`'s "reuse existing
  KPI cards" constraint), and **one** named custom view, `LeaderboardView.vue` (mirrors
  `BsaRiskDashboard.vue`'s status as the sole custom-UI exception), rendering the `LeaderboardController`
  response with an inline "hide me from this leaderboard" toggle wired to the existing preferences-api
  endpoints.
- **Pedagogical posture (design.md §Rejected Alternatives / §Pedagogical posture)**: Specter's own research
  found grade-pressure/social comparison is the #1 pupil complaint in NL schools. A public ranking is
  therefore never forced on anyone: default OFF at the `Leaderboard` object level, explicit per-cohort
  admin opt-in, and an always-available per-learner opt-out that hides that learner from the ranked list
  while leaving their own points/level fully visible to them.

## Impact

- **`lib/Settings/scholiq_register.json`** — five new schemas: `PointRule`, `PointAward`, `EngagementLevel`,
  `LearnerEngagement`, `Leaderboard`. No existing schema is modified — `Enrolment`, `Submission`,
  `GradeEntry`, `FinalGrade`, `Cohort` are read-only precedents/event sources for this change, not touched.
- **New PHP** — `OCA\Scholiq\Engagement\PointEngagementEvaluator` (calculation engine),
  `OCA\Scholiq\Listener\PointAwardTriggerHandler`, `OCA\Scholiq\Listener\LearnerEngagementRollupHandler`,
  `OCA\Scholiq\Controller\LeaderboardController` (new route in `appinfo/routes.php`).
- **`src/manifest.json`** — index/detail pages for the five new objects (admin/coordinator config surfaces
  for `PointRule`/`EngagementLevel`/`Leaderboard`; read-only detail for `PointAward`/`LearnerEngagement`), a
  points/level KPI widget on the student dashboard, and one new custom view `LeaderboardView.vue`.
- **Affected specs**: new `engagement` capability spec only. `certification` (Open Badges 3.0),
  `course-management`/`grading`/`assignments` (event sources), `preferences-api` (opt-out mechanism), and
  `dashboard` (KPI-widget placement constraint) are read-only precedents, not modified.
- **Out of scope**: peer-review point awards (peer review itself is unbuilt — future `PointRule` kind once
  `assignments`' peer-review follow-up ships), any cross-tenant or cross-school leaderboard (leaderboards
  are strictly `tenant_id`-scoped, same isolation as every other schema), and a tenant-wide "enable
  gamification" admin toggle (deliberately not built — see design.md for why per-`Leaderboard` opt-in is
  the chosen, minimal mechanism).
