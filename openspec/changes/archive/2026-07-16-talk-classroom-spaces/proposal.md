---
kind: code
depends_on: []
---

## Why

Scholiq has zero Nextcloud Talk integration anywhere in its own code, and it has architecturally
*opted out* of Talk everywhere a comms leaf could go — confirmed two ways:

- **Zero real hits.** A case-insensitive grep for `talk|spreed|OCA\\Talk|Talk\\IBroker` across `lib/` and
  `src/` returns nothing except one unused mention: `openspec/specs/nextcloud-app/spec.md:172` lists
  `Talk\IBroker` in the Standards section's list of *available* Nextcloud OCP interfaces, alongside
  `Calendar\IManager` and `Notification\IManager` — but unlike those two (both are wired up elsewhere),
  no PHP file constructs, injects, or calls it. (The only other `talk|conversation` hits are the
  `parent-conferences` capability's *human* "parent-teacher conversation" domain language — booking slots,
  not Nextcloud Talk — e.g. `lib/Listener/ConferenceScheduleGenerator.php:8`, `src/manifest.json:346`.)
- **`Session.location` is free text.** `lib/Settings/scholiq_register.json:3566-3571` — the property's
  `description` is `"Room code or online URL (MS Teams, Zoom, etc.)"`. Scholiq's own schema anticipates
  online sessions but literally names *competitors'* video tools in the example, because there is nothing
  native to point at.
- **The exclusion is a deliberate, repeated manifest convention ("HARD RULE 1"), applied to exactly the
  two objects this change targets.** `src/manifest.json:2697` (Cohort): *"No comms leaves (no
  linkedTypes)."* `src/manifest.json:3031` (Session): *"No calendar/email leaf: Session declares no
  linkedTypes despite being time-boxed (HARD RULE 1)."* The same phrase appears on `Course`
  (`src/manifest.json:522`, *"No email/calendar/talk... a course is a definition, not a correspondence
  thread"*), `Programme`, `Lesson`, `Enrolment`, and 20+ other objects — it is scholiq's settled position
  that most objects should not sprout comms leaves. This change does not fight that convention; it carves
  one narrow, justified exception into it (see "What Changes").
- **Competitor pressure is exactly on the objects HARD RULE 1 currently blanks out.** 8+ competitors
  (Canvas BBB/Zoom, Google Classroom Meet, TalentLMS, Cornerstone, Litmos, LearnUpon, Podia, SAP ILT) ship
  a virtual-classroom join action from the class/session surface. Scholiq's `Cohort` — *"a group of
  learners doing a Course or Programme together... backed by a Nextcloud group for permissioning"*
  (`lib/Settings/scholiq_register.json:3132-3133`) — and `Session` — *"a scheduled occurrence of a Cohort
  meeting... Carries start/end datetime, location"* (`lib/Settings/scholiq_register.json:3510`) — are
  exactly the two "live" archetypes (delivery-run and scheduled-occurrence) a virtual classroom needs;
  `Course`/`Programme`/`CurriculumPlan` are catalog *definitions* and correctly keep zero comms leaves.

**The platform capability already exists and is unused.** OpenRegister — a hard dependency
(`appinfo/info.xml:96`, `<app>openregister</app>`) — already ships a complete, generic, cross-app Talk
integration that scholiq has never consumed:
- `apps-extra/openregister/lib/Service/TalkLinkService.php` (Tier-2): `linkRoom`, `createAndLinkRoom`,
  `unlinkRoom`, `getLinkedRooms`, `getAvailableRoomsForUser`, and `isTalkAvailable()` — which is exactly
  `IAppManager::isEnabledForUser('spreed')` (line ~99-101) — the fail-soft check this change needs, already
  written.
- `apps-extra/openregister/lib/Controller/TalkLinksController.php` + routes
  `apps-extra/openregister/appinfo/routes.php:442-446` (`GET/POST /api/objects/{register}/{schema}/{id}/talk`,
  `POST .../talk/new`, `DELETE .../talk/{roomToken}`, `GET /api/integrations/talk/rooms`) — a full REST
  surface, keyed by any OR object's `(register, schema, id)`, storing the room↔object link in OR's own
  `openregister_talk_links` table (**not** on the linked object itself).
  `apps-extra/openregister/lib/Service/Integration/Providers/TalkProvider.php:73` registers this as
  integration id `'talk'` in OR's `IntegrationRegistry` (`AD-19`/`ADR-019` pluggable integration registry) —
  no app-side registration call is needed; it is already live on any NC instance with openregister enabled.
- `apps-extra/nextcloud-vue` already ships the leaf-row UI that renders it: `src/components/CnTalkRoomPicker`,
  `src/components/CnTalkRoomCreate`, `src/integrations/builtin/talk/{CnTalkTab.vue,CnTalkCard.vue}` — the
  `CnTalkCard.vue` header comment documents a `degraded` surface state for exactly the "Talk not installed"
  case, so a `manifest.json` widget of `{"type":"integration","integrationId":"talk"}` already degrades
  gracefully with zero scholiq code.
- The wiring contract is proven in `larpingapp/src/manifest.json:409-427`: a schema declares
  `"linkedTypes": ["calendar", "maps", "forms"]` (merged in from
  `larpingapp/lib/Settings/register.d/event-calendar-leaf.json`) and the manifest page adds one
  `{"id":"event-calendar","type":"integration","integrationId":"calendar",...}` widget — no new PHP
  controller, no schema field for the linked resource, because the link lives in OR's own table.
  `pipelinq/lib/Settings/pipelinq_register.json:73` shows the same `linkedTypes` array as a schema
  top-level key (sibling to `properties`), which is the pattern this change reuses for `talk`.
- **Nobody in the whole `apps-extra` fleet has used `linkedTypes: ["talk"]` yet** — a repo-wide grep for
  `"talk"` inside every file that declares `linkedTypes` returns zero matches. `TalkProvider` was built and
  registered but has no consumer. This change is the first.

**What's still genuinely missing (the leaf this change builds):** OR's Talk linking is generic — it knows
nothing about who a Cohort's learners are. `lib/Lifecycle/CohortMembershipGuard.php:43-45` says the quiet
part out loud: *"Note: Full NC group synchronisation (ncGroupId provisioning) is deferred to a separate
event listener or manual admin action."* — `ncGroupId` provisioning stays deferred (out of scope here,
unchanged), but the same gap exists for Talk participants, and is squarely in scope: nothing keeps a
Cohort's enrolled learners in sync with its linked Talk room's participant list. That domain-aware sync
(`Enrolment.cohortId` → Talk participant) is business logic only scholiq has, and is the one piece of new
PHP this change adds.

## What Changes

- **`school-structure` delta** (the only capability touched — `Cohort` and `Session` are both defined
  there, `openspec/specs/school-structure/spec.md:20,22`; `Course`/`Programme`/`CurriculumPlan`, defined in
  the same spec, are deliberately left untouched, preserving HARD RULE 1 for catalog/definition objects):
  - `Cohort` (`lib/Settings/scholiq_register.json:3128`) gains `"linkedTypes": ["talk"]` — a persistent
    class space a coordinator/teacher links or creates via OR's existing picker/create UI. **No new schema
    property** — the room↔Cohort link lives in OR's `openregister_talk_links` table, not on the Cohort
    object.
  - `Session` (`lib/Settings/scholiq_register.json:3505`) gains `"linkedTypes": ["talk"]` — the
    join-call action for "the online-lesson case." In practice a teacher picks the parent Cohort's
    already-linked room (OR's picker supports linking an *existing* room to a second object) for a
    recurring class, or links a fresh room for a one-off online session.
  - `src/manifest.json`: add an `{"type":"integration","integrationId":"talk"}` widget to the `CohortDetail`
    and `SessionDetail` pages (mirroring `larpingapp/src/manifest.json:409-427`'s wiring), visible to
    teachers/coordinators and (per each object's existing RBAC) enrolled learners.
  - `Course` is explicitly **not** given `linkedTypes`, keeping `src/manifest.json:522`'s "a course is a
    definition, not a correspondence thread" intact — the live class instance is the Cohort, not the
    catalog entry.
- **New PHP — membership sync only** (everything else is declarative + OR consumption):
  `OCA\Scholiq\Listener\CohortTalkMembershipHandler`, an `ObjectTransitionedEvent` listener (registered the
  same way as every other bridge in `lib/AppInfo/Application.php`, e.g. `GradeRollupHandler` at line
  ~199-207) that:
  - filters to `Enrolment` objects transitioning `activate`/`withdraw` where `cohortId` is set
    (`lib/Settings/scholiq_register.json:1541-1548,1573-1587`);
  - looks up the Cohort's linked rooms via `TalkLinkService::getLinkedRooms($cohortUuid)` (consumed
    cross-app exactly as `ObjectService` already is in every other scholiq listener, e.g.
    `lib/Listener/GradeRollupHandler.php:46`);
  - on `activate`, adds `Enrolment.learnerId` (already an NC user id,
    `lib/Settings/scholiq_register.json:1470-1474`) as a Talk participant of each linked room; on
    `withdraw`, removes it.
  - **Fails soft**: no-op + log when Talk is unavailable (`TalkLinkService::isTalkAvailable()`) or the
    Cohort has no linked room yet. This is an ADR-031 legitimate exception (event-to-external-API bridge
    with cross-object lookup, not expressible as a schema declaration) — the same category as
    `GradeRollupHandler`/`ExcuseApprovalHandler`.
- **Explicit non-goal**: `Cohort.ncGroupId` provisioning stays deferred exactly as
  `CohortMembershipGuard.php:43-45` already documents — this change does not touch NC-group sync, only
  Talk-room participant sync, and does not backfill participants for Enrolments that were already `active`
  before a room was linked (documented limitation, see `design.md`).

## Impact

- **`lib/Settings/scholiq_register.json`** — `Cohort` and `Session` schemas gain `linkedTypes: ["talk"]`
  (additive, no property changes, no migration).
- **`src/manifest.json`** — one new `integration`/`talk` widget on `CohortDetail`, one on `SessionDetail`.
- **New PHP** — `OCA\Scholiq\Listener\CohortTalkMembershipHandler` (registered in
  `lib/AppInfo/Application.php`), consuming `OCA\OpenRegister\Service\TalkLinkService` cross-app (already a
  hard dependency, `appinfo/info.xml:96`). No new controller, no new route, no new OR-side code.
- **Affected specs**: `school-structure` (MODIFIED-by-addition on the Cohort/Session persistence
  requirement; ADDED requirement for membership sync).
- **Out of scope**: `Cohort.ncGroupId` NC-group provisioning (pre-existing, separately deferred);
  retroactive participant backfill for pre-existing active Enrolments when a room is linked after the fact
  (documented limitation); any change to `Course`, `Programme`, or `CurriculumPlan` (HARD RULE 1 stays
  intact there).
