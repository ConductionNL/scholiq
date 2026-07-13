# Tasks: parent-evening-planner

## 1. Schema — ConferenceRound + TeacherAvailability

- [x] 1.1 Add `ConferenceRound` schema to `lib/Settings/scholiq_register.json`: `cohortIds[]`
      (`format: uuid`, `$ref: Cohort`, array), `teacherIds[]` (NC user ids), `slotDurationMinutes`
      (default 10), `bufferMinutes`, `bookingOpensAt`/`bookingClosesAt`, `invitedLearnerIds[]`
      (NC user ids, populated at `send-invitations`), `tenant_id`, `lifecycle` enum `draft |
      invitations-sent | booking-open | booking-closed | scheduled | completed | cancelled`.
- [x] 1.2 Add `x-openregister-lifecycle` transitions on `ConferenceRound`: `send-invitations`
      (`draft → invitations-sent`), `open-booking` (`invitations-sent → booking-open`),
      `close-booking` (`booking-open → booking-closed`), `generate` (`booking-closed →
      scheduled`), `regenerate` (`scheduled → scheduled`), `complete` (`scheduled → completed`),
      `cancel` (any → `cancelled`).
- [x] 1.3 Add `x-openregister-notifications` on `ConferenceRound`: `invitationsSent`
      (`trigger.type: transition`, `action: send-invitations`, `recipients: [{kind: field, field:
      invitedLearnerIds}]`, `nl`/`en` subject) and `bookingAutoClosed` (`trigger.type: scheduled`,
      keyed to `bookingClosesAt`). **CONFLICT vs HEAD reality (flagged, not silently reinterpreted):**
      the verified `scheduled` trigger (`OCA\OpenRegister\BackgroundJob\ScheduledNotificationJob` →
      `AnnotationNotificationDispatcher`) only ever *sends a notification*; it has no mechanism to
      also fire a lifecycle transition, so it cannot "fire `close-booking`" as this task originally
      specified. Implemented instead: (a) a materialised `isBookingClosed` calculation
      (`Session.isPast` idiom) so "window has passed" is a queryable derived fact with no TimedJob,
      and (b) `bookingAutoClosed` fires an advisory `nc-notification` to the `coordinator` group when
      `bookingClosesAt` has passed while still `booking-open`, telling them to trigger `close-booking`
      themselves (a manual/UI transition, same posture as every other transition in this register —
      see 2.2 note). Automating the transition itself would require either a PHP TimedJob (excluded
      by design.md) or a new dialect capability (declared out of scope in design.md's own
      "Rejected alternative" for the sibling `invitedLearnerIds` problem) — flagging as a follow-up
      rather than inventing a third mechanism un-reviewed.
- [x] 1.4 Add `TeacherAvailability` schema: `conferenceRoundId` (`format: uuid`, `$ref:
      ConferenceRound`), `teacherId` (NC user id), `blocks[]` (`{startsAt, endsAt}`), `tenant_id`,
      `lifecycle` enum `draft | submitted | locked`, with `submit` (`draft → submitted`) and `lock`
      (`submitted → locked`) transitions.
- [x] 1.5 Register-validation test: both schemas validate against the project's register-JSON
      validation suite (`tests/validate-register.js` per the pattern used by `scholiq-notifications`).
      Verified: `node tests/validate-register.js` → PASS.

## 2. Schema — ConferenceSignup

- [x] 2.1 Add `ConferenceSignup` schema: `conferenceRoundId` (`$ref: ConferenceRound`), `learnerId`
      (NC user id), `learnerRef` (nullable, `format: uuid`, `$ref: LearnerProfile`, additive per the
      `ExcuseRequest.learnerRef`/`GradeEntry.learnerRef` convention), `guardianId` (NC user id,
      nullable), `guardianRef` (nullable UUID, additive, same convention), `requestedTeacherIds[]`
      (ordered NC user ids), `notes` (nullable string), `tenant_id`, `lifecycle` enum `draft |
      submitted | scheduled | waitlisted | cancelled`.
- [x] 2.2 Add `x-openregister-lifecycle` transitions: `submit` (`draft → submitted`, `requires:
      [OCA\Scholiq\Lifecycle\ConferenceSignupGuardianGuard]`), `schedule` (`submitted → scheduled`,
      system-only via the generator), `waitlist` (`submitted → waitlisted`, system-only),
      `cancel` (`submitted | scheduled | waitlisted → cancelled`). Note: the register dialect has no
      declarative "system-only" transition restriction anywhere in this codebase (verified — no
      schema carries such a marker); `schedule`/`waitlist` are convention-only reserved for the
      generator, same posture already accepted for other unguarded transitions (Risk 3 in
      proposal.md). ConferenceScheduleGenerator is the only writer that uses them in this change.
- [x] 2.3 Create `lib/Lifecycle/ConferenceSignupGuardianGuard.php` (namespace
      `OCA\Scholiq\Lifecycle`, matching `CohortMembershipGuard.php`'s SPDX header + docblock
      style): inject `IUserSession` + `ObjectService` + `LoggerInterface`; `check(array
      &$transitionContext): bool` fetches the `LearnerProfile` matching `object['learnerId']` via
      `ObjectService::findAll(['register' => 'scholiq', 'schema' => 'learner-profile', 'filters' =>
      ['ncUserId' => $learnerId]])` and returns `true` only if the current session user id is in
      `parentIds` or equals the profile's own `ncUserId`; logs + returns `false` (fails closed) on
      any lookup miss.
- [x] 2.4 Unit test `tests/Unit/Lifecycle/ConferenceSignupGuardianGuardTest.php`: linked guardian
      passes; unrelated user is blocked; self-signup (caller == learner) passes; missing
      `LearnerProfile` fails closed. (Plus 2 extra cases: missing `learnerId` fails closed without
      querying; no authenticated session fails closed.) 6/6 green.

## 3. Schema — ConferenceSlot + ConferenceReport

- [x] 3.1 Add `ConferenceSlot` schema: `conferenceRoundId` (`$ref: ConferenceRound`), `teacherId`
      (NC user id), `learnerId` (NC user id), `learnerRef` (nullable UUID, `$ref: LearnerProfile`),
      `signupId` (nullable, `$ref: ConferenceSignup`), `startsAt`/`endsAt`, `location` (nullable
      string, mirrors `Session.location`), `tenant_id`, `lifecycle` enum `proposed | confirmed |
      completed | no-show | cancelled`.
- [x] 3.2 Add `x-openregister-lifecycle` transitions on `ConferenceSlot`: `confirm` (`proposed →
      confirmed`), `complete` (`confirmed → completed`), `markNoShow` (`confirmed → no-show`),
      `cancel` (`proposed | confirmed → cancelled`).
- [x] 3.3 Add `x-openregister-notifications.confirmed` on `ConferenceSlot` (`trigger.type:
      transition`, `action: confirm`, `recipients: [{kind: field, field: learnerId}]`).
- [x] 3.4 Add `ConferenceReport` schema, `appendOnly: true`: `conferenceSlotId` (`$ref:
      ConferenceSlot`), `learnerId` (NC user id), `learnerRef` (nullable UUID), `teacherId` (NC user
      id), `narrative` (nullable string), `attendeeIds[]` (NC user ids, mirrors
      `LearningPlanEvaluation.attendeeIds`), `recordedBy`, `recordedAt`, `tenant_id`, `lifecycle`
      enum `draft | recorded`, `record` transition (`draft → recorded`).
- [x] 3.5 Add `x-openregister-notifications.recorded` on `ConferenceReport` (`trigger.type:
      transition`, `action: record`, `recipients: [{kind: field, field: learnerId}]`).

## 4. Scheduling generator

- [x] 4.1 Create `lib/Listener/ConferenceScheduleGenerator.php` (namespace `OCA\Scholiq\Listener`,
      `implements IEventListener`, matching `ExcuseApprovalHandler.php`'s constructor-injected
      `ObjectService` + `LoggerInterface` shape): `handle()` filters to
      `ObjectTransitionedEvent` with `register=scholiq`, `schema=conference-round`, `to=scheduled`.
- [x] 4.2 Implement Step 1 (availability slicing): pure function `sliceAvailability(array $blocks,
      int $slotDurationMinutes, int $bufferMinutes): array` returning an ordered list of
      `{startsAt, endsAt}` candidate slots per teacher.
- [x] 4.3 Implement Step 2 (signup walk + overlap guard): sort `submitted` `ConferenceSignup`s by
      `createdAt`; for each, for each `requestedTeacherIds` entry in order, peek/skip/pop the
      teacher's queue per the overlap guard (design.md "Step 2"); build the set of `ConferenceSlot`
      writes.
- [x] 4.4 Implement Step 3 (signup resolution): write all `ConferenceSlot(proposed)` objects; flip
      each fully-satisfied signup to `scheduled`, each partially-unmet signup to `waitlisted`
      recording which requested teacher could not be met (a `notes`-appended reason string is
      sufficient — no new schema field needed).
- [x] 4.5 Implement Step 4 (idempotent `regenerate`): exclude `confirmed` `ConferenceSlot`s'
      consumed minutes from re-slicing; restrict the signup walk to `submitted`/`waitlisted`
      signups only; free `cancelled` signups' minutes back into the teacher queue before re-slicing
      (implemented as an explicit cascade: any still-active `ConferenceSlot` belonging to a
      `cancelled` signup is itself flipped to `cancelled` by the generator, which is what actually
      returns its minutes to the teacher's queue on the next slicing pass).
- [x] 4.6 Register the listener in `lib/AppInfo/Application.php` against
      `ObjectTransitionedEvent::class` via `registerEventListener()` (same call shape as the existing
      `ExcuseApprovalHandler` registration).
- [x] 4.7 Unit tests `tests/Unit/Listener/ConferenceScheduleGeneratorTest.php`: conflict-free
      generation across teachers and signups (no overlapping `ConferenceSlot`s for the same teacher
      or the same signup) — asserted via a generic pairwise-overlap property check, not just fixed
      expected values; a signup with an unmet teacher-request lands `waitlisted` with the unmet
      teacher identified; `regenerate` after a cancellation frees exactly that signup's minutes and
      leaves `confirmed` slots untouched; `regenerate` after adding availability schedules a
      previously `waitlisted` signup. (Plus 1 extra: unrelated transitions are ignored, zero queries.)
      5/5 green.

## 5. Frontend

- [x] 5.1 Add `src/manifest.json` index+detail pages for `ConferenceRound`, `TeacherAvailability`,
      `ConferenceSlot`, `ConferenceReport` (declarative CRUD forms/lists over the OpenRegister
      objects — no PHP controller). `ConferenceRoundDetail` additionally lists the round's generated
      `ConferenceSlot`s as a related object-list. Menu group `GroupConferences` added.
- [x] 5.2 Create `src/views/BookConferenceSlotsView.vue`: for an authenticated guardian/self, list
      `booking-open` rounds the caller can book into, pick which linked child (or self) and which of
      the round's teachers (multi-select, order = preference order), submit a `ConferenceSignup`
      (draft via OR object API), and trigger its `submit` transition (PUT `lifecycle: submitted`).
      Note: renders teacher *identity* selection, not a live per-teacher calendar-grid of remaining
      slots (that would need a dedicated read endpoint resolving TeacherAvailability minus already-
      consumed minutes, which no task in this file specifies building) — flagged as a scope note vs.
      design.md's "calendar-grid UI" phrasing.
- [x] 5.3 Create `src/views/ConferenceScheduleBoard.vue`: coordinator view listing `waitlisted`
      `ConferenceSignup`s per round with the unmet teacher-request (from `notes`), a manual
      `ConferenceSlot` creation action, and a `regenerate` trigger button (PUT `lifecycle: scheduled`
      on an already-`scheduled` round, which OR's lifecycle engine resolves to the `regenerate`
      self-transition).
- [ ] 5.4 **NOT DONE.** Wire `ConferenceSlot(confirmed)` to Nextcloud `Calendar` (`IManager`).
      **CONFLICT vs HEAD reality:** this task's own justification — "reusing the same OCP interface
      pattern already used for `Session` lesson/exam scheduling" — does not hold: `grep -rn
      "IManager\|ICalendar" lib/` finds zero Calendar integration anywhere in this codebase; the only
      reference is an aspirational line in `openspec/config.yaml`'s "Nextcloud reuse" list, never
      implemented for `Session` either. There is no existing pattern to mirror. Building a first-ever
      Calendar/IManager integration un-reviewed, under this pass's time budget, was judged higher-risk
      than flagging it; left as a follow-up (a lightweight `.ics` download link on `ConferenceSlotDetail`
      is likely the smaller, more idiomatic NC pattern than a live `IManager` write).

## 6. Docs + tests + traceability

- [x] 6.1 Add `@spec openspec/changes/parent-evening-planner/tasks.md#task-N` docblock tags to
      `ConferenceSignupGuardianGuard`, `ConferenceScheduleGenerator`, and the two new Vue views.
      (Tagged against the spec.md requirement/scenario anchors rather than tasks.md task numbers,
      consistent with how `bpv-praktijkovereenkomst`'s newest files tag — both forms already coexist
      in this codebase.)
- [x] 6.2 SPDX headers (`@license EUPL-1.2` + `@copyright`) on both new PHP files, per CLAUDE.md.
- [x] 6.3 i18n: added English source strings (33 new keys) for the two new custom views and the
      manifest titles/menu labels to `l10n/en.json`, with Dutch translations to `l10n/nl.json` (kept
      in exact parity — `node tests/l10n/check-l10n-parity.js` shows `nl` fully synced to `en` both
      before and after this change). The four declared notification subjects live inline in
      `x-openregister-notifications.*.subject.{nl,en}` in the register JSON (verified dialect), not
      in `l10n/*.json` — that is how every other notification in this register already works.
      **Not done:** backfilling the other 33 required locales (`check-l10n-parity.js` already FAILs
      pre-existing, unrelated to this change — 83 missing keys per locale before this change, 116
      after, purely because `en`/`nl` grew by 33 keys neither the check nor any other locale file had
      before; see apply report).
- [ ] 6.4 **NOT DONE.** `docs/features/parent-conferences.md` with Playwright MCP screenshots. This
      apply pass has no browser/MCP tooling and no live dev instance to screenshot against — left for
      a follow-up pass with `test-app`/Playwright access, per ADR-010.
- [~] 6.5 Unit tests added covering every branch of both new classes (guard: 6 tests; generator: 5
      tests, including a conflict-freeness *property* check, not just fixed-value assertions) — but
      the literal "minimum 75% coverage" number is **not verified**: the `php:8.3-cli` container has
      no coverage driver (`xdebug`/`pcov`) installed (PHPUnit prints "No code coverage driver
      available" on every run in this environment, unchanged by this task).
- [x] 6.6 Ran the two `composer check:strict` sub-checks that have no `|| echo ...skipping` soft-fail
      in `composer.json` (`phpcs`, `phpstan`) directly against both new PHP files: `phpcs
      --standard=phpcs.xml` → 0 errors (fixed 3 real pre-fix issues: a >150-char line, two
      single-arg internal calls needing named params, one disallowed ternary — see apply report);
      `phpstan analyse` (project's own `phpstan.neon`, level 5) → 0 errors. `phpmd`/`psalm`/`test:all`
      all soft-fail-skip outside a full NC install (`composer.json`'s own `|| echo` fallback), so
      running the literal `composer check:strict` chain adds no signal beyond phpcs+phpstan+phpunit
      (which was run separately, 149/149 green).
- [x] 6.7 Ran `openspec validate parent-evening-planner --type change --strict` → "Change
      'parent-evening-planner' is valid".
