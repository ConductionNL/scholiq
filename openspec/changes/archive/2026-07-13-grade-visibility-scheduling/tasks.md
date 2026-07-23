## 1. Register schema — grading

- [x] 1.1 In `lib/Settings/scholiq_register.json`, add a nullable `gradeVisibilityPolicy` object property to
      `CurriculumPlan` (`{mode: "immediate"|"nextSchoolDay", time: "HH:MM", timezone: string}`, default
      `null`, mirroring the shape described in `proposal.md`). `null` MUST resolve to `mode: "immediate"` so
      every existing `CurriculumPlan` is unaffected until a school opts in.
- [x] 1.2 Add a nullable `visibleFrom` date-time property to `GradeEntry`, matching the shape of
      `Assignment.visibleFrom` (`lib/Settings/scholiq_register.json:3838-3845`).
- [x] 1.3 Add an `x-openregister-notifications.gradePublished` block to `GradeEntry` (verified dialect only:
      `trigger.type: "scheduled"` bound to the `visibleFrom` field per the resolved DEFERRED_QUESTIONS
      approach — see task 1.4 for the fallback path if field-bound scheduling is unsupported; `channels:
      ["nc-notification"]`; `recipients: [{kind: "field", field: "learnerId"}]`; inline `subject` with `nl`
      and `en` strings). This is `GradeEntry`'s first-ever declared notification rule.
- [x] 1.4 Spike/confirm whether OR's `scheduled` trigger supports binding fire time to a schema field
      (`visibleFrom`) or only `intervalSec` polling. If field-binding is unsupported, fall back to a short
      `intervalSec` poll (e.g. 300) filtered on `visibleFrom <= now`, relying on
      `GradeNotification.idempotencyKey` for exactly-once delivery, and record the actual supported shape
      back into `specs/grading/spec.md` before archiving this change.
      **Resolved**: confirmed against the shipped OR engine (`ScheduledNotificationJob` +
      `ScheduledFilterEvaluator`) — field-bound scheduling is NOT supported, only `intervalSec` polling +
      an operator-aware `filter` (`equals`/`notEquals`/`withinNext`/`olderThan`). Implemented the fallback:
      `intervalSec: 300` + `filter: {visibleFrom: {operator: "olderThan", value: "PT0S"}}`. Recorded in
      `specs/grading/spec.md`.
- [x] 1.5 Add a nullable `visibleFrom` date-time property to `GradeNotification`
      (`lib/Settings/scholiq_register.json:7793-7889`) and change its existing
      `x-openregister-notifications.gradePublished.trigger` from `{type: "created"}` to the same
      `scheduled`/`visibleFrom`-gated shape chosen in task 1.4, so parent notifications fire on the same
      schedule as the learner's.
- [x] 1.6 Add a lead-time-sized `intervalSec`/scheduling adjustment to `Enrolment.dueReminder` and
      `Enrolment.overdue` (`lib/Settings/scholiq_register.json:1560-1610`) so the worked deadline
      lead-time-guarantee example (spec `scholiq-notifications`) is concretely satisfied, not just declared
      in prose.
      Implemented as filter scoping (both rules previously fired on every `mandatory: true` enrolment daily,
      regardless of due-date proximity — a pre-existing gap, fixed here): `dueReminder` gained
      `dueDate: {operator: "withinNext", value: "P3D"}` (first firing lands up to 3 days before the
      deadline — well clear of any realistic quiet-hours deferral); `overdue` gained
      `dueDate: {operator: "olderThan", value: "PT0S"}` (only fires once genuinely overdue).

## 2. Backend — visibility resolver + rollup wiring

- [x] 2.1 Add `lib/Grading/GradeVisibilityResolver.php` (SPDX docblock; stateless service; ADR-031-exception
      docblock naming this change, mirroring `GradeFormulaEvaluator`'s exception comment style): given an
      optional teacher-supplied `visibleFrom` override, the governing `CurriculumPlan.gradeVisibilityPolicy`,
      and the publish timestamp, return the resolved `visibleFrom`. `nextSchoolDay` computes the next
      non-Saturday/non-Sunday day at `policy.time` in `policy.timezone`, rolling to the following day if the
      publish moment is already past that time on the same day.
- [x] 2.2 Unit tests for `GradeVisibilityResolver`: explicit override wins; `null` policy resolves to
      immediate (today's value); `nextSchoolDay` before cutoff rolls to today-is-not-possible/next weekday;
      `nextSchoolDay` computed across a Friday-evening publish rolls to Monday; timezone handling
      (`Europe/Amsterdam`).
      `tests/Unit/Grading/GradeVisibilityResolverTest.php` — 10 tests covering the above plus explicit
      `mode: "immediate"`, malformed-override fail-safe, and missing time/timezone defaults.
- [x] 2.3 In `lib/Listener/GradeRollupHandler.php::handleGradeEntryPublished`
      (`lib/Listener/GradeRollupHandler.php:125-145`), call `GradeVisibilityResolver` once per publish and
      persist the resolved `visibleFrom` onto the `GradeEntry` via `ObjectService`, using the same write
      pattern already used for `FinalGrade`/`GradeNotification` in this handler. Leave
      `recomputeFinalGrade`/`fanOutParentNotifications` call order and `FinalGrade` semantics unchanged —
      only the notification-eligibility timing is gated, not the roll-up computation.
      Added `ITimeFactory` as a fourth constructor dependency (injectable "now" for tests), mirroring the
      OR engine's own `ScheduledNotificationJob` pattern.
- [x] 2.4 In `fanOutParentNotifications` (`lib/Listener/GradeRollupHandler.php:230-298`), stamp the same
      resolved `visibleFrom` onto every `GradeNotification` row it creates.
- [x] 2.5 Unit tests for the updated `GradeRollupHandler`: publish at 23:40 under a `nextSchoolDay` policy →
      `GradeEntry.visibleFrom` and every fanned-out `GradeNotification.visibleFrom` equal the resolved next
      school day; explicit override → resolved value equals the override; `FinalGrade` recompute is
      unaffected by (does not wait on) `visibleFrom`.
      `tests/Unit/Listener/GradeRollupHandlerTest.php` — 4 tests covering all three scenarios plus the
      null-policy/immediate case.

## 3. Frontend — quiet hours settings + lifecycle badge

- [x] 3.1 In `src/views/ScholiqNotificationSettings.vue`, add a quiet-hours / delivery-window control
      section below the existing per-`(schema, notification)` toggle list, reading/writing through the same
      `GET`/`PUT /apps/openregister/api/notification-preferences` family the file already uses
      (`src/views/ScholiqNotificationSettings.vue:104-168`) once the OR `notification-delivery-windows`
      dispatcher preference surface is available. No new scholiq-local preference store or endpoint.
      Degrades gracefully today: `GET` reads an optional `data.quietHours` key (absent today, so the
      control defaults to off); `PUT` sends `{quietHours: {...}}` to the same endpoint, which the *current*
      `NotificationPreferencesController::update()` rejects (422, `schema`/`notification` still required) —
      caught and surfaced as a neutral hint ("not yet enforced by your Nextcloud instance"), not an error,
      per DEFERRED_QUESTIONS #3.
- [x] 3.2 In `src/views/GradeImpactDetail.vue`, extend the existing lifecycle badge
      (`src/views/GradeImpactDetail.vue:42-46`) to show a distinct "scheduled" state when
      `entry.lifecycle === 'published'` and `entry.visibleFrom` is in the future, alongside the existing
      `concept`/`published`/`revised` states.
- [ ] 3.3 Vitest coverage for the `GradeImpactDetail.vue` badge: published + future `visibleFrom` renders
      "scheduled"; published + past/absent `visibleFrom` renders the existing "published" state unchanged.
      **BLOCKED — spec/HEAD conflict**: this worktree has no Vitest (or any JS unit-test runner) configured
      — `package.json` has no `vitest`/`jest` devDependency, no config file, and no `test` script; the only
      JS-side checks are `eslint`, `stylelint`, and Playwright e2e (`test:e2e`). Adding a JS unit-test
      framework from scratch is out of scope for this change. Left unchecked per the honesty rule — flagging
      rather than silently reinterpreting as "add a Playwright e2e test" (a different testing layer per
      `feedback_playwright-ui-only-newman-api`) or fabricating a vitest setup.

## 4. Verify + docs

- [x] 4.1 Run `composer check:strict` on all new/touched PHP files (`GradeVisibilityResolver.php`,
      `GradeRollupHandler.php`) and fix any pre-existing warnings encountered in them, per CLAUDE.md.
      Ran phpcs + phpstan + psalm scoped to the two files (ADR-020: gate scope is the diff, not the whole
      repo) plus the full `phpunit-unit.xml` suite. phpcs: 0 errors/warnings after fixing 2 disallowed
      ternaries + missing class-level `@spec` tags (both pre-existing patterns also present in
      `GradeFormulaEvaluator.php`, harmless warnings, fixed here since touched anyway). phpstan: 0 errors.
      psalm: 0 errors. Full whole-repo `composer check:strict` was not run — its `phpcs`/`phpmd`/`psalm`
      steps sweep all of `lib/`, which is pre-existing debt outside this change's diff scope.
- [x] 4.2 Update `docs/features/` (or the equivalent grading feature doc, if one exists) with a short
      "scheduled grade visibility" section and a screenshot of the settings/lifecycle-badge UI, per this
      worktree's ADR-010 documentation convention.
      Added a "Scheduled grade visibility" section to `docs/user-guide/user/06-grading.md` (the only
      grading user-guide doc; no `docs/features/` dir exists). **Screenshot NOT captured** — this task
      environment has no live-deployed scholiq instance to drive a browser against; a real screenshot needs
      a follow-up pass against a deployed build (journeydoc convention, ADR-030).
- [x] 4.3 Add `@spec openspec/changes/grade-visibility-scheduling/specs/<capability>/spec.md#requirement-...`
      docblock tags to `GradeVisibilityResolver`, the updated `GradeRollupHandler` methods, and the two
      touched Vue views.
- [x] 4.4 Run `openspec validate grade-visibility-scheduling --strict` and resolve any errors.
      `Change 'grade-visibility-scheduling' is valid`.
