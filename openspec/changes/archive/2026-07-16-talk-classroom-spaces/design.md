# Design: talk-classroom-spaces

## Context

Scholiq has no way to create or join a video call anywhere in the product, even though `Session.location`
(`lib/Settings/scholiq_register.json:3566-3571`) already anticipates online sessions ("Room code or online
URL (MS Teams, Zoom, etc.)"). OpenRegister — a hard dependency (`appinfo/info.xml:96`) — already ships a
complete, generic, cross-app Talk-linking abstraction (`TalkLinkService`, `TalkLinksController`,
`TalkProvider`, plus nc-vue's `CnTalkTab`/`CnTalkCard`/`CnTalkRoomPicker`/`CnTalkRoomCreate`) that no app in
the `apps-extra` fleet has consumed yet. This change wires exactly two scholiq objects — `Cohort` and
`Session` — to that existing capability, and adds the one piece of genuinely new logic OR's generic linking
cannot express: keeping a Cohort's enrolled learners in sync with its linked conversation's participant
list.

## Goals / Non-Goals

**Goals**
- Give a Cohort a persistent Talk "class space" and a Session a join-call action, using OR's existing
  Talk-linking abstraction unchanged.
- Keep the Cohort's Talk conversation's participant list in sync with active `Enrolment`s, event-driven,
  fail-soft.
- Preserve HARD RULE 1 ("no comms leaf on a catalog/definition object") for `Course`, `Programme`, and
  `CurriculumPlan`.

**Non-Goals**
- Building a scholiq-owned Talk client, storing a conversation token on Cohort/Session, or duplicating any
  part of `TalkLinkService`/`TalkLinksController`.
- `Cohort.ncGroupId` (Nextcloud group) provisioning — already a separately deferred item
  (`lib/Lifecycle/CohortMembershipGuard.php:43-45`), untouched here.
- Retroactively backfilling Talk participants for `Enrolment`s that were already `active` before a
  conversation was linked to their Cohort (see Decisions).
- Any chat/video feature work — Talk remains a pure platform abstraction; this change only makes it
  reachable from the two objects that need it.

## Decisions

**1. Talk API surface used: OpenRegister's existing Tier-2 abstraction, not a raw OCS/PHP Talk call.**
`OCA\OpenRegister\Service\TalkLinkService` already wraps NC Talk's internal `Manager`/`RoomService` (link,
create-and-link, unlink, list-linked, list-available) and is exposed over REST at
`/api/objects/{register}/{schema}/{id}/talk[...]` (`apps-extra/openregister/appinfo/routes.php:442-446`).
Scholiq consumes this the same way it already consumes `OCA\OpenRegister\Service\ObjectService` in every
other listener (e.g. `lib/Listener/GradeRollupHandler.php:46`) — cross-app service injection, no new HTTP
call, no new controller. The frontend gets the leaf UI for free: `src/manifest.json` only needs
`{"type":"integration","integrationId":"talk"}` widgets on `CohortDetail`/`SessionDetail`
(`larpingapp/src/manifest.json:409-427` is the proven wiring pattern for a different `linkedTypes` value —
`talk` is registered by OR's own `TalkProvider` at `apps-extra/openregister/lib/Service/Integration/Providers/TalkProvider.php:73`
and needs zero scholiq-side provider registration). The one new PHP class,
`CohortTalkMembershipHandler`, only needs `TalkLinkService::getLinkedRooms()` /
`isTalkAvailable()` plus Talk's `ParticipantService` for add/remove — nothing OR's abstraction already
exposes generically, because OR's linking layer is intentionally domain-agnostic (it doesn't know what a
"learner" or "cohort" is).

**2. Not-installed fail-soft posture: two independent layers, both already required, neither new.**
- *Widget layer* (Requirement 1): already solved by the platform. OR's `IntegrationProvider` contract
  defines a `health()`/degraded state (`CnTalkCard.vue`'s header comment documents the `degraded` surface
  branch) — a Cohort/Session page with Talk disabled just shows the talk widget as unavailable; every other
  widget is unaffected. Scholiq writes no code for this.
- *Listener layer* (Requirement 2, `CohortTalkMembershipHandler`): must guard itself, because this is new
  scholiq PHP making a direct participant-add/remove call. It checks
  `TalkLinkService::isTalkAvailable()` (itself `IAppManager::isEnabledForUser('spreed')`) before attempting
  anything, and separately no-ops when `getLinkedRooms()` returns empty (Talk available but this Cohort
  simply has no room yet). Both cases log at `info` and return — never throw, never block the `Enrolment`
  transition itself (the listener runs *after* the transition succeeds, mirroring every other
  `ObjectTransitionedEvent` bridge in `lib/AppInfo/Application.php`).

**3. Membership sync is event-driven off `Enrolment` lifecycle, not off Cohort membership arrays or a
scheduled job — kept deliberately narrow.**
`Cohort.learnerIds`/`teacherIds` (`lib/Settings/scholiq_register.json:3172-3184`) are informational rosters;
the authoritative "is this learner currently taking this cohort" signal is `Enrolment.lifecycle`
(`pending → active → completed | withdrawn | failed`,
`lib/Settings/scholiq_register.json:1549-1561`) scoped by `Enrolment.cohortId`. Reusing that transition —
exactly the trigger `GradeRollupHandler`, `ExcuseApprovalHandler`, and every other cross-object bridge in
this codebase already key off — means no new polling, no `TimedJob` (ADR-022), and no second source of
truth for "who's in this cohort." The trade-off, made explicit rather than silently swallowed: OR fires no
event when a room is *linked* to a Cohort (`TalkLinksController`/`TalkLinkService` dispatch nothing), so
there is no hook to retroactively add learners whose `Enrolment` was already `active` before the room
existed. Rather than inventing a synthetic "room-linked" polling mechanism to close that gap — which the
brief's "keep it simple" instruction argues against, and which OR's own generic linking layer deliberately
does not provide since it doesn't model cohorts — this change accepts the cold-start gap and documents it:
a coordinator who links a Cohort's conversation after learners are already enrolled adds that initial batch
through Talk's own native "add participants" UI once; every enrolment change *after* that point is synced
automatically. This mirrors the same posture `CohortMembershipGuard.php:43-45` already takes toward
`ncGroupId` — provisioning deferred to explicit action, sync of *changes* handled going forward.

## Risks / Trade-offs

- **Cold-start participant gap** (Decision 3): acceptable — one-time manual step per Cohort, not a
  per-learner recurring burden, and avoids a second membership-sync mechanism (schedule/poll) this codebase
  otherwise avoids everywhere else (ADR-022).
- **`Session` reuses the Cohort's room by convention, not by schema constraint**: a teacher *could* link an
  unrelated Talk conversation to a Session. Not guarded, because `Session.location` already allows an
  arbitrary external URL today with no validation — this is not a new trust boundary, and OR's room picker
  surfaces the Cohort's existing rooms first, making the intended path the easy path.
