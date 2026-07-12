## 1. Register schema — grading

- [ ] 1.1 In `lib/Settings/scholiq_register.json`, add a nullable `gradeVisibilityPolicy` object property to
      `CurriculumPlan` (`{mode: "immediate"|"nextSchoolDay", time: "HH:MM", timezone: string}`, default
      `null`, mirroring the shape described in `proposal.md`). `null` MUST resolve to `mode: "immediate"` so
      every existing `CurriculumPlan` is unaffected until a school opts in.
- [ ] 1.2 Add a nullable `visibleFrom` date-time property to `GradeEntry`, matching the shape of
      `Assignment.visibleFrom` (`lib/Settings/scholiq_register.json:3838-3845`).
- [ ] 1.3 Add an `x-openregister-notifications.gradePublished` block to `GradeEntry` (verified dialect only:
      `trigger.type: "scheduled"` bound to the `visibleFrom` field per the resolved DEFERRED_QUESTIONS
      approach — see task 1.4 for the fallback path if field-bound scheduling is unsupported; `channels:
      ["nc-notification"]`; `recipients: [{kind: "field", field: "learnerId"}]`; inline `subject` with `nl`
      and `en` strings). This is `GradeEntry`'s first-ever declared notification rule.
- [ ] 1.4 Spike/confirm whether OR's `scheduled` trigger supports binding fire time to a schema field
      (`visibleFrom`) or only `intervalSec` polling. If field-binding is unsupported, fall back to a short
      `intervalSec` poll (e.g. 300) filtered on `visibleFrom <= now`, relying on
      `GradeNotification.idempotencyKey` for exactly-once delivery, and record the actual supported shape
      back into `specs/grading/spec.md` before archiving this change.
- [ ] 1.5 Add a nullable `visibleFrom` date-time property to `GradeNotification`
      (`lib/Settings/scholiq_register.json:7793-7889`) and change its existing
      `x-openregister-notifications.gradePublished.trigger` from `{type: "created"}` to the same
      `scheduled`/`visibleFrom`-gated shape chosen in task 1.4, so parent notifications fire on the same
      schedule as the learner's.
- [ ] 1.6 Add a lead-time-sized `intervalSec`/scheduling adjustment to `Enrolment.dueReminder` and
      `Enrolment.overdue` (`lib/Settings/scholiq_register.json:1560-1610`) so the worked deadline
      lead-time-guarantee example (spec `scholiq-notifications`) is concretely satisfied, not just declared
      in prose.

## 2. Backend — visibility resolver + rollup wiring

- [ ] 2.1 Add `lib/Grading/GradeVisibilityResolver.php` (SPDX docblock; stateless service; ADR-031-exception
      docblock naming this change, mirroring `GradeFormulaEvaluator`'s exception comment style): given an
      optional teacher-supplied `visibleFrom` override, the governing `CurriculumPlan.gradeVisibilityPolicy`,
      and the publish timestamp, return the resolved `visibleFrom`. `nextSchoolDay` computes the next
      non-Saturday/non-Sunday day at `policy.time` in `policy.timezone`, rolling to the following day if the
      publish moment is already past that time on the same day.
- [ ] 2.2 Unit tests for `GradeVisibilityResolver`: explicit override wins; `null` policy resolves to
      immediate (today's value); `nextSchoolDay` before cutoff rolls to today-is-not-possible/next weekday;
      `nextSchoolDay` computed across a Friday-evening publish rolls to Monday; timezone handling
      (`Europe/Amsterdam`).
- [ ] 2.3 In `lib/Listener/GradeRollupHandler.php::handleGradeEntryPublished`
      (`lib/Listener/GradeRollupHandler.php:125-145`), call `GradeVisibilityResolver` once per publish and
      persist the resolved `visibleFrom` onto the `GradeEntry` via `ObjectService`, using the same write
      pattern already used for `FinalGrade`/`GradeNotification` in this handler. Leave
      `recomputeFinalGrade`/`fanOutParentNotifications` call order and `FinalGrade` semantics unchanged —
      only the notification-eligibility timing is gated, not the roll-up computation.
- [ ] 2.4 In `fanOutParentNotifications` (`lib/Listener/GradeRollupHandler.php:230-298`), stamp the same
      resolved `visibleFrom` onto every `GradeNotification` row it creates.
- [ ] 2.5 Unit tests for the updated `GradeRollupHandler`: publish at 23:40 under a `nextSchoolDay` policy →
      `GradeEntry.visibleFrom` and every fanned-out `GradeNotification.visibleFrom` equal the resolved next
      school day; explicit override → resolved value equals the override; `FinalGrade` recompute is
      unaffected by (does not wait on) `visibleFrom`.

## 3. Frontend — quiet hours settings + lifecycle badge

- [ ] 3.1 In `src/views/ScholiqNotificationSettings.vue`, add a quiet-hours / delivery-window control
      section below the existing per-`(schema, notification)` toggle list, reading/writing through the same
      `GET`/`PUT /apps/openregister/api/notification-preferences` family the file already uses
      (`src/views/ScholiqNotificationSettings.vue:104-168`) once the OR `notification-delivery-windows`
      dispatcher preference surface is available. No new scholiq-local preference store or endpoint.
- [ ] 3.2 In `src/views/GradeImpactDetail.vue`, extend the existing lifecycle badge
      (`src/views/GradeImpactDetail.vue:42-46`) to show a distinct "scheduled" state when
      `entry.lifecycle === 'published'` and `entry.visibleFrom` is in the future, alongside the existing
      `concept`/`published`/`revised` states.
- [ ] 3.3 Vitest coverage for the `GradeImpactDetail.vue` badge: published + future `visibleFrom` renders
      "scheduled"; published + past/absent `visibleFrom` renders the existing "published" state unchanged.

## 4. Verify + docs

- [ ] 4.1 Run `composer check:strict` on all new/touched PHP files (`GradeVisibilityResolver.php`,
      `GradeRollupHandler.php`) and fix any pre-existing warnings encountered in them, per CLAUDE.md.
- [ ] 4.2 Update `docs/features/` (or the equivalent grading feature doc, if one exists) with a short
      "scheduled grade visibility" section and a screenshot of the settings/lifecycle-badge UI, per this
      worktree's ADR-010 documentation convention.
- [ ] 4.3 Add `@spec openspec/changes/grade-visibility-scheduling/specs/<capability>/spec.md#requirement-...`
      docblock tags to `GradeVisibilityResolver`, the updated `GradeRollupHandler` methods, and the two
      touched Vue views.
- [ ] 4.4 Run `openspec validate grade-visibility-scheduling --strict` and resolve any errors.
