# Tasks: engagement-gamification

## 1. Schema — engagement capability

- [ ] 1.1 Add `PointRule` to `lib/Settings/scholiq_register.json`: `name`, `kind` (`enrolment-completed` |
  `submission-on-time` | `finalgrade-passed` | `streak-milestone`), `points` (number), `milestoneDays`
  (nullable integer, only meaningful for `kind: streak-milestone`), `scope` (nullable object:
  `cohortId`/`courseId`), `active` (boolean), `lifecycle` (`draft → active → archived`, mirrors
  `AttendanceThreshold`), `tenant_id`. No `x-openregister-authorization` block (mirrors
  `AttendanceThreshold`, which also has none).
  - **spec_ref**: `specs/engagement/spec.md#requirement-persist-engagement-domain-objects-in-openregister`
  - **acceptance_criteria**: schema validates against the register's OpenAPI 3.0.0 conventions; `kind` enum
    matches exactly the four values named in the spec, no `peer-review` value.
- [ ] 1.2 Add `PointAward` (`appendOnly: true`): `learnerId`, `pointRuleId` ($ref `PointRule`), `points`
  (number, copied at award time), `sourceKind` (`enrolment` | `submission` | `grade-entry` |
  `streak-milestone`), `sourceObjectId` (nullable uuid), `awardedAt` (date-time), `tenant_id`. Add
  `x-openregister-notifications.pointsAwarded` (`created` trigger, recipient `field: learnerId`, mirrors
  `AttendanceFlag.flagRaised`).
  - **spec_ref**: `specs/engagement/spec.md#requirement-persist-engagement-domain-objects-in-openregister`
  - **acceptance_criteria**: `appendOnly: true` set; notification fires on creation with NL/EN subject.
- [ ] 1.3 Add `EngagementLevel`: `name`, `order` (integer), `minPoints` (number), `icon` (nullable string),
  `tenant_id`. No lifecycle (config rows, mirrors `AttendanceThreshold`'s row-per-rule editability).
  - **spec_ref**: `specs/engagement/spec.md#requirement-persist-engagement-domain-objects-in-openregister`
  - **acceptance_criteria**: rows are independently creatable/editable/orderable via the standard OR object
    UI.
- [ ] 1.4 Add `LearnerEngagement` with `x-openregister: {active: true, hardDelete: false, searchable: true,
  readOnly: true}` (mirrors `FinalGrade`): `learnerId`, `totalPoints`, `levelId` (nullable $ref
  `EngagementLevel`), `currentStreakDays`, `longestStreakDays`, `lastActivityDate` (nullable date),
  `lastRecomputedAt` (nullable date-time), `tenant_id`. Add `x-property-rbac.read`:
  `anyOf: [{role: admin}, {match: {field: learnerId, operator: eq, value: "$userId"}}]` (identical shape to
  `GradeEntry`/`FinalGrade`).
  - **spec_ref**: `specs/engagement/spec.md#requirement-persist-engagement-domain-objects-in-openregister`
  - **acceptance_criteria**: `readOnly: true` rejects a direct frontend PATCH/POST; self-match RBAC verified
    against a non-owning user.
- [ ] 1.5 Add `Leaderboard`: `name`, `cohortId` (nullable $ref `Cohort`), `courseId` (nullable $ref
  `Course`), `topN` (nullable integer), `lifecycle` (`draft → active → archived`, mirrors
  `CurriculumPlan`/`GradeScale`), `tenant_id`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohortcourse-default-off-and-respects-a-per-learner-opt-out`
  - **acceptance_criteria**: no seed data creates an `active` row; a fresh tenant has zero `Leaderboard`
    rows by default.

## 2. Backend — evaluator, listeners, controller

- [ ] 2.1 Add `OCA\Scholiq\Engagement\PointEngagementEvaluator`: `evaluate(string $learnerId, string
  $tenantId): array{totalPoints: float, levelId: ?string, currentStreakDays: int, longestStreakDays: int,
  lastActivityDate: ?string}`. Sums `PointAward.points` for the learner (no `x-openregister-aggregations`
  `sum` metric — none is precedented in this register; mirrors `GradeFormulaEvaluator`/
  `BsaProgressEvaluator`'s PHP-summation precedent). Resolves `levelId` as the highest `EngagementLevel`
  (ordered by `minPoints` desc) with `minPoints <= totalPoints`. Computes streak from distinct
  `PointAward.awardedAt` calendar dates, counting back from today-or-yesterday.
  - **spec_ref**: `specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation`
  - **acceptance_criteria**: unit-testable pure computation given a fixture set of `PointAward`s and
    `EngagementLevel`s; streak resets across a date gap, continues across a same-day/yesterday boundary.
- [ ] 2.2 Add `OCA\Scholiq\Listener\PointAwardTriggerHandler` (`IEventListener<ObjectTransitionedEvent>`):
  handles `Enrolment → completed`, `Submission → submitted` (checking materialised `isLate === false`), and
  `GradeEntry → published`/`republish` (calling constructor-injected `GradeFormulaEvaluator::evaluate()`
  directly for the pass check, not reading `FinalGrade.passed`). For each matching transition, looks up
  `active` `PointRule`s of the matching `kind`, and creates an idempotency-keyed `PointAward` — skip if one
  already exists for `(learnerId, pointRuleId, sourceObjectId)`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events`,
    `specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable`
  - **acceptance_criteria**: all three transitions covered; late submissions never award
    `submission-on-time`; republish after revise never duplicates an award.
- [ ] 2.3 Add `OCA\Scholiq\Listener\LearnerEngagementRollupHandler` (`IEventListener<ObjectCreatedEvent>` on
  `PointAward`): finds-or-creates the learner's `LearnerEngagement`, calls
  `PointEngagementEvaluator::evaluate()`, writes `totalPoints`/`levelId`/`currentStreakDays`/
  `longestStreakDays`/`lastActivityDate`/`lastRecomputedAt`. When the triggering award's `sourceKind` is not
  `streak-milestone` (recursion guard), checks active `PointRule(kind: streak-milestone)` rows for a newly
  crossed `milestoneDays` threshold and creates a bonus `PointAward(sourceKind: streak-milestone,
  sourceObjectId: null)`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation`
  - **acceptance_criteria**: a streak crossing 7 awards exactly one bonus award; the bonus award's own
    rollup does not re-check streak milestones (no infinite loop).
- [ ] 2.4 Add `OCA\Scholiq\Controller\LeaderboardController::getRankings(string $cohortId)`:
  `#[NoAdminRequired]`; in-method authorization (admin, or caller's NC user id present in
  `Cohort.learnerIds`/`teacherIds` — 403 otherwise); requires an `active` `Leaderboard` row scoped to that
  cohort (404 otherwise); queries `LearnerEngagement` for the cohort's `learnerIds`, sorted by `totalPoints`
  desc; excludes any learner whose `pref_leaderboardOptOut` preference (read via the existing
  `preferences-api`'s underlying `IConfig`) is set; returns `{learnerId, totalPoints, level, rank}[]`
  (`topN`-limited when set) — never the raw `LearnerEngagement` object.
  - **spec_ref**: `specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohortcourse-default-off-and-respects-a-per-learner-opt-out`
  - **acceptance_criteria**: no-active-`Leaderboard` refused; non-cohort-member refused; opted-out learner
    absent from the response; opted-out learner's own `LearnerEngagement` self-read is unaffected.
- [ ] 2.5 Register the route in `appinfo/routes.php` for `LeaderboardController::getRankings`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohortcourse-default-off-and-respects-a-per-learner-opt-out`
  - **acceptance_criteria**: route reachable; `hydra-gate-route-reachability` and `hydra-gate-route-auth`
    pass.

## 3. Frontend

- [ ] 3.1 Add `src/manifest.json` index/detail pages for `PointRule`, `EngagementLevel`, `Leaderboard`
  (admin/coordinator config) and read-only detail pages for `PointAward`/`LearnerEngagement`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`
  - **acceptance_criteria**: pages render via standard manifest binding; no bespoke CRUD component needed.
- [ ] 3.2 Add a points/level KPI widget to the student dashboard (`ScholiqDashboards role="student"`),
  reusing an existing `src/views/widgets/*` KPI-card component, reading the caller's own
  `LearnerEngagement` row via self-match RBAC. Visible regardless of any `Leaderboard`/opt-out state.
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`
  - **acceptance_criteria**: widget renders `totalPoints`/`levelId`/`currentStreakDays` for the logged-in
    learner; no widget change needed to teacher/admin dashboards.
- [ ] 3.3 Add `src/views/LeaderboardView.vue` — the one custom view: renders `LeaderboardController`'s
  ranked response for a cohort with an active `Leaderboard`, plus an inline "hide me from this leaderboard"
  toggle wired to the existing `preferences-api` GET/SET endpoints for `pref_leaderboardOptOut`. `NcSelect`
  (if used for cohort selection) carries `inputLabel`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`
  - **acceptance_criteria**: renders the ranking; toggling opt-out persists and removes the learner from the
    ranking on next load; no ranking renders when no active `Leaderboard` exists for the selected scope.
- [ ] 3.4 i18n: add all new `en`/`nl` translation keys (English keys, per project convention) for the new
  manifest pages, KPI widget labels, and `LeaderboardView` strings.
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`
  - **acceptance_criteria**: no hardcoded user-facing string outside `t('scholiq', ...)`.

## 4. Tests and docs

- [ ] 4.1 PHPUnit `PointEngagementEvaluatorTest`: totals sum, level resolution at exact thresholds, streak
  continuation across same-day/yesterday, streak reset across a gap.
  - **spec_ref**: `specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation`
- [ ] 4.2 PHPUnit `PointAwardTriggerHandlerTest`: each of the three transitions awards correctly; late
  submission awards nothing; republish-after-revise does not duplicate; no `peer-review` kind exists.
  - **spec_ref**: `specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events`,
    `specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable`
- [ ] 4.3 PHPUnit `LearnerEngagementRollupHandlerTest`: streak-milestone bonus fires exactly once per
  crossing; recursion guard verified (bonus award does not re-trigger another bonus check).
  - **spec_ref**: `specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation`
- [ ] 4.4 PHPUnit `LeaderboardControllerTest`: no-active-`Leaderboard` refused; non-member refused; opted-out
  learner excluded from response but retains self-read of their own `LearnerEngagement`.
  - **spec_ref**: `specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohortcourse-default-off-and-respects-a-per-learner-opt-out`
- [ ] 4.5 Vitest for `LeaderboardView.vue` (renders seeded rankings; renders empty/no-active-leaderboard
  state; opt-out toggle round-trip).
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`
- [ ] 4.6 Playwright `tests/e2e/spec-coverage/engagement.spec.ts` covering the two scenarios in the frontend
  requirement: the student's own KPI widget, and opening an active leaderboard plus the opt-out toggle.
  - **spec_ref**: `specs/engagement/spec.md#requirement-frontend-surfaces-a-private-pointslevel-widget-and-one-opt-in-leaderboard-view`

## 5. Verify

- [ ] 5.1 `openspec validate engagement-gamification --strict` clean; `composer check:strict` (PHPCS, PHPMD,
  Psalm, PHPStan) green for all new PHP; hydra mechanical gates (spdx-headers, forbidden-patterns,
  stub-scan, route-auth, no-admin-idor, route-reachability, redundant-controller, spec-coverage) pass on the
  new controller/listeners/evaluator; full PHPUnit suite green including the new tests; vitest green for
  `LeaderboardView`; Playwright `engagement.spec.ts` green; no dangling `$ref`s in the register JSON.
  - **spec_ref**: all
  - **acceptance_criteria**: strict validation and full test suite green; recursion-guard and idempotency
    invariants re-verified end-to-end against seeded fixtures.
