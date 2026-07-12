# Tasks: parent-evening-planner

## 1. Schema — ConferenceRound + TeacherAvailability

- [ ] 1.1 Add `ConferenceRound` schema to `lib/Settings/scholiq_register.json`: `cohortIds[]`
      (`format: uuid`, `$ref: Cohort`, array), `teacherIds[]` (NC user ids), `slotDurationMinutes`
      (default 10), `bufferMinutes`, `bookingOpensAt`/`bookingClosesAt`, `invitedLearnerIds[]`
      (NC user ids, populated at `send-invitations`), `tenant_id`, `lifecycle` enum `draft |
      invitations-sent | booking-open | booking-closed | scheduled | completed | cancelled`.
- [ ] 1.2 Add `x-openregister-lifecycle` transitions on `ConferenceRound`: `send-invitations`
      (`draft → invitations-sent`), `open-booking` (`invitations-sent → booking-open`),
      `close-booking` (`booking-open → booking-closed`), `generate` (`booking-closed →
      scheduled`), `regenerate` (`scheduled → scheduled`), `complete` (`scheduled → completed`),
      `cancel` (any → `cancelled`).
- [ ] 1.3 Add `x-openregister-notifications` on `ConferenceRound`: `invitationsSent`
      (`trigger.type: transition`, `action: send-invitations`, `recipients: [{kind: field, field:
      invitedLearnerIds}]`, `nl`/`en` subject) and `bookingAutoClosed` (`trigger.type: scheduled`,
      keyed to `bookingClosesAt`, firing `close-booking`).
- [ ] 1.4 Add `TeacherAvailability` schema: `conferenceRoundId` (`format: uuid`, `$ref:
      ConferenceRound`), `teacherId` (NC user id), `blocks[]` (`{startsAt, endsAt}`), `tenant_id`,
      `lifecycle` enum `draft | submitted | locked`, with `submit` (`draft → submitted`) and `lock`
      (`submitted → locked`) transitions.
- [ ] 1.5 Register-validation test: both schemas validate against the project's register-JSON
      validation suite (`tests/validate-register.js` per the pattern used by `scholiq-notifications`).

## 2. Schema — ConferenceSignup

- [ ] 2.1 Add `ConferenceSignup` schema: `conferenceRoundId` (`$ref: ConferenceRound`), `learnerId`
      (NC user id), `learnerRef` (nullable, `format: uuid`, `$ref: LearnerProfile`, additive per the
      `ExcuseRequest.learnerRef`/`GradeEntry.learnerRef` convention), `guardianId` (NC user id,
      nullable), `guardianRef` (nullable UUID, additive, same convention), `requestedTeacherIds[]`
      (ordered NC user ids), `notes` (nullable string), `tenant_id`, `lifecycle` enum `draft |
      submitted | scheduled | waitlisted | cancelled`.
- [ ] 2.2 Add `x-openregister-lifecycle` transitions: `submit` (`draft → submitted`, `requires:
      [OCA\Scholiq\Lifecycle\ConferenceSignupGuardianGuard]`), `schedule` (`submitted → scheduled`,
      system-only via the generator), `waitlist` (`submitted → waitlisted`, system-only),
      `cancel` (`submitted | scheduled | waitlisted → cancelled`).
- [ ] 2.3 Create `lib/Lifecycle/ConferenceSignupGuardianGuard.php` (namespace
      `OCA\Scholiq\Lifecycle`, matching `CohortMembershipGuard.php`'s SPDX header + docblock
      style): inject `IUserSession` + `ObjectService` + `LoggerInterface`; `check(array
      &$transitionContext): bool` fetches the `LearnerProfile` matching `object['learnerId']` via
      `ObjectService::findAll(['register' => 'scholiq', 'schema' => 'learner-profile', 'filters' =>
      ['ncUserId' => $learnerId]])` and returns `true` only if the current session user id is in
      `parentIds` or equals the profile's own `ncUserId`; logs + returns `false` (fails closed) on
      any lookup miss.
- [ ] 2.4 Unit test `tests/Unit/Lifecycle/ConferenceSignupGuardianGuardTest.php`: linked guardian
      passes; unrelated user is blocked; self-signup (caller == learner) passes; missing
      `LearnerProfile` fails closed.

## 3. Schema — ConferenceSlot + ConferenceReport

- [ ] 3.1 Add `ConferenceSlot` schema: `conferenceRoundId` (`$ref: ConferenceRound`), `teacherId`
      (NC user id), `learnerId` (NC user id), `learnerRef` (nullable UUID, `$ref: LearnerProfile`),
      `signupId` (nullable, `$ref: ConferenceSignup`), `startsAt`/`endsAt`, `location` (nullable
      string, mirrors `Session.location`), `tenant_id`, `lifecycle` enum `proposed | confirmed |
      completed | no-show | cancelled`.
- [ ] 3.2 Add `x-openregister-lifecycle` transitions on `ConferenceSlot`: `confirm` (`proposed →
      confirmed`), `complete` (`confirmed → completed`), `markNoShow` (`confirmed → no-show`),
      `cancel` (`proposed | confirmed → cancelled`).
- [ ] 3.3 Add `x-openregister-notifications.confirmed` on `ConferenceSlot` (`trigger.type:
      transition`, `action: confirm`, `recipients: [{kind: field, field: learnerId}]`).
- [ ] 3.4 Add `ConferenceReport` schema, `appendOnly: true`: `conferenceSlotId` (`$ref:
      ConferenceSlot`), `learnerId` (NC user id), `learnerRef` (nullable UUID), `teacherId` (NC user
      id), `narrative` (nullable string), `attendeeIds[]` (NC user ids, mirrors
      `LearningPlanEvaluation.attendeeIds`), `recordedBy`, `recordedAt`, `tenant_id`, `lifecycle`
      enum `draft | recorded`, `record` transition (`draft → recorded`).
- [ ] 3.5 Add `x-openregister-notifications.recorded` on `ConferenceReport` (`trigger.type:
      transition`, `action: record`, `recipients: [{kind: field, field: learnerId}]`).

## 4. Scheduling generator

- [ ] 4.1 Create `lib/Listener/ConferenceScheduleGenerator.php` (namespace `OCA\Scholiq\Listener`,
      `implements IEventListener`, matching `ExcuseApprovalHandler.php`'s constructor-injected
      `ObjectService` + `LoggerInterface` shape): `handle()` filters to
      `ObjectTransitionedEvent` with `register=scholiq`, `schema=conference-round`, `to=scheduled`.
- [ ] 4.2 Implement Step 1 (availability slicing): pure function `sliceAvailability(array $blocks,
      int $slotDurationMinutes, int $bufferMinutes): array` returning an ordered list of
      `{startsAt, endsAt}` candidate slots per teacher.
- [ ] 4.3 Implement Step 2 (signup walk + overlap guard): sort `submitted` `ConferenceSignup`s by
      `createdAt`; for each, for each `requestedTeacherIds` entry in order, peek/skip/pop the
      teacher's queue per the overlap guard (design.md "Step 2"); build the set of `ConferenceSlot`
      writes.
- [ ] 4.4 Implement Step 3 (signup resolution): write all `ConferenceSlot(proposed)` objects; flip
      each fully-satisfied signup to `scheduled`, each partially-unmet signup to `waitlisted`
      recording which requested teacher could not be met (a `notes`-appended reason string is
      sufficient — no new schema field needed).
- [ ] 4.5 Implement Step 4 (idempotent `regenerate`): exclude `confirmed` `ConferenceSlot`s'
      consumed minutes from re-slicing; restrict the signup walk to `submitted`/`waitlisted`
      signups only; free `cancelled` signups' minutes back into the teacher queue before re-slicing.
- [ ] 4.6 Register the listener in `lib/AppInfo/Application.php` against
      `ObjectTransitionedEvent::class` via `addServiceListener()` (same call shape as the existing
      `ExcuseApprovalHandler` registration at `lib/AppInfo/Application.php:216-217`).
- [ ] 4.7 Unit tests `tests/Unit/Listener/ConferenceScheduleGeneratorTest.php`: conflict-free
      generation across teachers and signups (no overlapping `ConferenceSlot`s for the same teacher
      or the same signup); a signup with an unmet teacher-request lands `waitlisted` with the unmet
      teacher identified; `regenerate` after a cancellation frees exactly that signup's minutes and
      leaves `confirmed` slots untouched; `regenerate` after adding availability schedules a
      previously `waitlisted` signup.

## 5. Frontend

- [ ] 5.1 Add `src/manifest.json` index+detail pages for `ConferenceRound`, `TeacherAvailability`,
      `ConferenceSlot`, `ConferenceReport` (declarative CRUD forms/lists over the OpenRegister
      objects — no PHP controller).
- [ ] 5.2 Create `src/views/BookConferenceSlotsView.vue`: for an authenticated guardian/self, list
      `booking-open` rounds the caller can book into, show each requested teacher's remaining
      availability as a calendar grid, submit a `ConferenceSignup` (draft), and trigger its `submit`
      transition.
- [ ] 5.3 Create `src/views/ConferenceScheduleBoard.vue`: coordinator view listing `waitlisted`
      `ConferenceSignup`s per round with the unmet teacher-request, a manual `ConferenceSlot`
      creation action, and a `regenerate` trigger button.
- [ ] 5.4 Wire `ConferenceSlot(confirmed)` to Nextcloud `Calendar` (`IManager`), reusing the same
      OCP interface pattern already used for `Session` lesson/exam scheduling, so a confirmed slot
      can be added to the guardian's calendar per story 10055's acceptance criteria.

## 6. Docs + tests + traceability

- [ ] 6.1 Add `@spec openspec/changes/parent-evening-planner/tasks.md#task-N` docblock tags to
      `ConferenceSignupGuardianGuard`, `ConferenceScheduleGenerator`, and the two new Vue views.
- [ ] 6.2 SPDX headers (`@license EUPL-1.2` + `@copyright`) on both new PHP files, per CLAUDE.md.
- [ ] 6.3 i18n: add English source strings for the two new custom views and the four declared
      notification subjects (`nl` + `en` per the verified dialect requirement); Dutch translations
      per ADR-005.
- [ ] 6.4 Add `docs/features/parent-conferences.md` with Playwright MCP screenshots of
      `BookConferenceSlotsView` and `ConferenceScheduleBoard`, per ADR-010.
- [ ] 6.5 Unit tests: minimum 75% coverage on `ConferenceSignupGuardianGuard` and
      `ConferenceScheduleGenerator` per ADR-009.
- [ ] 6.6 Run `composer check:strict` on all new/touched PHP files and fix any pre-existing warnings
      encountered in them, per CLAUDE.md.
- [ ] 6.7 Run `openspec validate parent-evening-planner --strict` and resolve any errors.
