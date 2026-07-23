# Tasks: talk-classroom-spaces

## 1. Schema — school-structure delta

- [x] 1.1 Add `"linkedTypes": ["talk"]` to the `Cohort` schema in `lib/Settings/scholiq_register.json`
  (`3128-3245` region), as a top-level key sibling to `properties`/`required` (mirrors
  `pipelinq/lib/Settings/pipelinq_register.json:73`'s placement). Purely additive — no new property, no
  migration.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-cohort-and-session-expose-a-nextcloud-talk-conversation-via-linkedtypes`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `Cohort` gains no new schema property; the room↔object link lives entirely in OpenRegister's own
      `openregister_talk_links` table
- [x] 1.2 Add `"linkedTypes": ["talk"]` to the `Session` schema in `lib/Settings/scholiq_register.json`
  (`3505-3660` region), same placement convention as 1.1.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-cohort-and-session-expose-a-nextcloud-talk-conversation-via-linkedtypes`
  - **acceptance_criteria**:
    - Schema validates unchanged otherwise
    - `Course`, `Programme`, `CurriculumPlan` are NOT touched — confirm no `linkedTypes` key was added to
      any of them

## 2. Backend — Cohort↔Talk participant sync

- [x] 2.1 Create `OCA\Scholiq\Listener\CohortTalkMembershipHandler`, an `IEventListener<ObjectTransitionedEvent>`
  (mirror the class shape/docblock style of `lib/Listener/GradeRollupHandler.php` and
  `lib/Listener/ExcuseApprovalHandler.php`). Constructor injects
  `OCA\OpenRegister\Service\TalkLinkService` (cross-app, same pattern as `ObjectService` injection
  elsewhere), `OCA\OpenRegister\Service\ObjectService`, and `Psr\Log\LoggerInterface`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes`
  - **acceptance_criteria**:
    - `handle()` filters to `ObjectTransitionedEvent` where the transitioned object's schema is `enrolment`
      and the transition action is `activate` or `withdraw`, and `cohortId` is set on the object
    - Non-matching events are a fast no-op (same filter-in-handler shape as every other listener in
      `lib/AppInfo/Application.php`)
- [x] 2.2 Implement the `activate` path: call `TalkLinkService::isTalkAvailable()`; if false, log info and
  return. Otherwise call `TalkLinkService::getLinkedRooms($cohortUuid)`; if empty, log info and return.
  Otherwise, for each linked room, add `Enrolment.learnerId` as a Talk participant via Talk's
  `ParticipantService` (resolve the same defensive way `TalkLinkService::resolveParticipantService()`
  already does — `class_exists` guard + container lookup + try/catch).
  - **spec_ref**: `specs/school-structure/spec.md#scenario-activating-an-enrolment-adds-the-learner-to-the-cohorts-linked-conversation`
  - **acceptance_criteria**:
    - PHPUnit `CohortTalkMembershipHandlerTest::testActivateAddsParticipant` — learner added when a room is
      linked and Talk available
    - PHPUnit `CohortTalkMembershipHandlerTest::testActivateWithNoLinkedRoomIsNoop`
    - PHPUnit `CohortTalkMembershipHandlerTest::testActivateWithTalkUnavailableIsNoop`
- [x] 2.3 Implement the `withdraw` path: same guards, then remove `Enrolment.learnerId` as a participant
  from each linked room.
  - **spec_ref**: `specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-cohorts-linked-conversation`
  - **acceptance_criteria**:
    - PHPUnit `CohortTalkMembershipHandlerTest::testWithdrawRemovesParticipant`
- [x] 2.4 Register `CohortTalkMembershipHandler` in `lib/AppInfo/Application.php` via
  `$context->registerEventListener(event: ObjectTransitionedEvent::class, listener: CohortTalkMembershipHandler::class)`,
  with a docblock comment matching the existing ADR-031-exception style used for every other listener
  registration in that file (state the cross-object lookup + external-API-call reason).
  - **spec_ref**: `specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes`
  - **acceptance_criteria**:
    - Listener fires in the running app (verified via PHPUnit integration test or manual event dispatch)

## 3. Frontend — manifest widgets

- [x] 3.1 Add an `{"id":"cohort-talk","type":"integration","integrationId":"talk","title":"Class space","icon":"ChatOutline"}`
  widget to the `CohortDetail` page in `src/manifest.json`, placed in `layout` near the Related panel
  (mirror `larpingapp/src/manifest.json:409-427`'s widget/layout wiring). Update the page's `_note` to
  record the HARD RULE 1 exception and why (Cohort is the delivery-run archetype, not a catalog
  definition).
  - **spec_ref**: `specs/school-structure/spec.md#scenario-coordinator-links-a-talk-conversation-to-a-cohort-as-its-persistent-class-space`
  - **acceptance_criteria**:
    - Widget renders on `CohortDetail` for a user with access; degrades gracefully when Talk is disabled
      (no scholiq code needed — verify the existing `CnTalkCard` `degraded` branch covers it)
- [x] 3.2 Add an equivalent `{"id":"session-talk","type":"integration","integrationId":"talk","title":"Join call","icon":"VideoOutline"}`
  widget to the `SessionDetail` page in `src/manifest.json`, visible per the Session's existing RBAC to
  the teacher and enrolled learners. Update the page's `_note` similarly.
  - **spec_ref**: `specs/school-structure/spec.md#scenario-teacher-links-a-sessions-call-to-the-parent-cohorts-existing-conversation`
  - **acceptance_criteria**:
    - Widget renders on `SessionDetail`; no dead/empty join action shown when nothing is linked yet

## 4. Tests

- [ ] 4.1 Write `tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts` covering: coordinator links a new
  Talk conversation from the Cohort detail page; teacher links the Cohort's existing conversation to a
  Session; an enrolled learner sees and can use the Session's join-call action; a Session with nothing
  linked shows no join action.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-cohort-and-session-expose-a-nextcloud-talk-conversation-via-linkedtypes`
  - **acceptance_criteria**: all four scenarios pass against a live instance with Talk (`spreed`) enabled
- [x] 4.2 Write `tests/Unit/Listener/CohortTalkMembershipHandlerTest.php` covering the four PHPUnit cases
  referenced in tasks 2.2/2.3 (activate-adds, withdraw-removes, no-room-noop, talk-unavailable-noop), using
  mocked `TalkLinkService`/`ParticipantService` per the existing `hermiq/tests/Stubs/Talk/*` stub pattern.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes`
  - **acceptance_criteria**: 4 passing tests, no live Talk instance required

## 5. Docs

- [x] 5.1 Add a short note to `docs/Integrations/index.md` (the relevant integrations
  section) documenting the `linkedTypes: ["talk"]` leaf and the cold-start participant-backfill limitation
  from `design.md` Decision 3.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-cohort-and-session-expose-a-nextcloud-talk-conversation-via-linkedtypes`
  - **acceptance_criteria**: doc change reviewed alongside the PR, no separate follow-up needed
