## ADDED Requirements

### Requirement: Cohort and Session expose a Nextcloud Talk conversation via linkedTypes

The `Cohort` schema MUST declare `linkedTypes: ["talk"]` and the `Session` schema MUST declare
`linkedTypes: ["talk"]`, consuming OpenRegister's existing Talk integration
(`TalkLinksController` + `TalkLinkService`, `Talk\Provider` id `talk`) rather than Scholiq building its
own Talk client or storing a conversation token on either object — the room↔object link is persisted in
OpenRegister's own link table, keyed by the Cohort's or Session's object uuid. `Course`, `Programme`, and
`CurriculumPlan` MUST NOT declare `linkedTypes` — they remain catalog/definition objects with no comms
leaf. `src/manifest.json` MUST render the resulting `integration`/`talk` widget on the Cohort detail page
(the persistent class space) and the Session detail page (the per-occurrence join-call action), visible to
teachers/coordinators and, per each object's existing RBAC, enrolled learners.

#### Scenario: Coordinator links a Talk conversation to a Cohort as its persistent class space

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** an active Cohort with no Talk conversation linked yet
- **WHEN** a coordinator opens the Cohort detail page and creates-and-links a new Talk conversation via the
  talk widget
- **THEN** the conversation is stored as a link against the Cohort's object id
- **AND** the Cohort detail page shows the linked conversation as the cohort's class space

#### Scenario: Teacher links a Session's call to the parent Cohort's existing conversation

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session whose Cohort already has a linked Talk conversation, and the Session has none linked
  yet
- **WHEN** the teacher opens the Session detail page and links the Cohort's existing conversation via the
  talk widget's room picker
- **THEN** the Session detail page shows a join-call action for that conversation

#### Scenario: An enrolled learner sees and can use the join-call action on a Session

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session with a linked Talk conversation, and a learner enrolled (active) in its Cohort
- **WHEN** the learner opens the Session detail page
- **THEN** the join-call action for the linked conversation is visible and usable to them

#### Scenario: Talk not installed or not enabled degrades gracefully

<!-- @e2e exclude Requires an NC instance with the spreed app disabled; not reproducible in the standard Playwright environment. Covered by PHPUnit against OpenRegister's TalkLinkService::isTalkAvailable() contract and the existing CnTalkCard 'degraded' surface, both pre-existing platform behaviour this change consumes unchanged. -->

- **GIVEN** Nextcloud Talk (`spreed`) is not installed or not enabled for the current user
- **WHEN** a coordinator or teacher opens the Cohort or Session detail page
- **THEN** the talk widget renders a degraded/unavailable state instead of erroring
- **AND** every other widget on the page renders normally

#### Scenario: A Session without a linked conversation shows no dead action

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session with no Talk conversation linked
- **WHEN** anyone with access opens the Session detail page
- **THEN** no join-call action is shown

### Requirement: Enrolled learners sync as Talk room participants on Cohort membership changes

When an `Enrolment` with `cohortId` set transitions to `active`, the system MUST add
`Enrolment.learnerId` as a participant of every Talk conversation currently linked to that Cohort, if any.
When such an `Enrolment` transitions to `withdrawn`, the system MUST remove that learner from every Talk
conversation currently linked to that Cohort. The sync MUST fail soft (no-op, logged) — never raise an
error to the caller — when Talk is unavailable or the Cohort has no conversation linked at the time of the
transition; learners whose `Enrolment` was already `active` before a conversation was later linked are NOT
retroactively added (documented limitation — the coordinator adds the initial batch through Talk's own
participant UI after linking).

#### Scenario: Activating an enrolment adds the learner to the cohort's linked conversation

<!-- @e2e exclude Pure backend event-bridge (ObjectTransitionedEvent → Talk ParticipantService call); no scholiq DOM surface for the sync action itself — the resulting membership is a Talk-native surface, not a scholiq one. Verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateAddsParticipant. -->

- **GIVEN** a Cohort with a linked Talk conversation
- **WHEN** a learner's `Enrolment` (`cohortId` = this Cohort) transitions from `pending` to `active`
- **THEN** the learner is added as a participant of the linked conversation

#### Scenario: Withdrawing an enrolment removes the learner from the cohort's linked conversation

<!-- @e2e exclude Pure backend event-bridge; verified by PHPUnit CohortTalkMembershipHandlerTest::testWithdrawRemovesParticipant. -->

- **GIVEN** a learner enrolled and synced as a participant of the Cohort's linked Talk conversation
- **WHEN** their `Enrolment` transitions to `withdrawn`
- **THEN** they are removed as a participant of the conversation

#### Scenario: No conversation linked yet is a no-op, not an error

<!-- @e2e exclude Pure backend fail-soft path; verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateWithNoLinkedRoomIsNoop. -->

- **GIVEN** a Cohort with no Talk conversation linked
- **WHEN** a learner's `Enrolment` (`cohortId` = this Cohort) transitions to `active`
- **THEN** the system logs and continues without error
- **AND** no participant-sync call is attempted

#### Scenario: Talk unavailable is a no-op, not an error

<!-- @e2e exclude Requires Talk disabled at the instance level; verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateWithTalkUnavailableIsNoop against TalkLinkService::isTalkAvailable() returning false. -->

- **GIVEN** Nextcloud Talk (`spreed`) is not installed or not enabled
- **WHEN** a learner's `Enrolment` transitions to `active` or `withdrawn` for a Cohort with a stale linked
  conversation record
- **THEN** the system logs and continues without error
- **AND** no participant-sync call is attempted
