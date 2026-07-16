# pupil-dossier Specification

## Purpose
TBD - created by archiving change pupil-dossier-notes. Update Purpose after archive.
## Requirements
### Requirement: Persist DossierNote, BehaviourIncident, and WellbeingCheckIn domain objects in OpenRegister

The system MUST persist `DossierNote`, `BehaviourIncident`, and `WellbeingCheckIn` as OpenRegister objects,
each `appendOnly: true` (ADR-008 — a correction is a new record, never an in-place edit of prior evidence
about a named minor).

`DossierNote` MUST carry `learnerId`, `authorId`, `date`, `category` (enum: `observation`, `conversation`,
`phone-call-home`, `concern`, `positive`), `body`, and `confidentiality` (enum: `team-visible`,
`care-team-only`, `private-to-author`; default `care-team-only`), plus `tenant_id`. It has no
`x-openregister-lifecycle` — it is a flat record, not a workflow.

`BehaviourIncident` MUST carry `learnerId`, `reportedBy`, `occurredAt`, `what`, a nullable `location`, a
nullable `involvedUserIds` array, `severity` (enum: `low`, `medium`, `high`), an append-only
`followUpActions` array (each entry: `recordedBy`, `recordedAt`, `action` — same shape as
`AttendanceFlag.interventions`, `lib/Settings/scholiq_register.json:8554-8592`), a nullable `resolution`,
a nullable `escalatedSupportRequestId` (`$ref: SupportRequest`), `tenant_id`, and an
`x-openregister-lifecycle` (`open → in-handling → resolved`).

`WellbeingCheckIn` MUST carry `learnerId`, `submittedAt`, `moodScale` (integer, 1-5), a nullable `comment`,
and `tenant_id`. It has no `x-openregister-lifecycle` — each check-in is a single point-in-time record.

#### Scenario: A mentor records a dossier note

<!-- @e2e tests/e2e/spec-coverage/pupil-dossier.spec.ts -->

- **GIVEN** the `pupil-dossier` schemas are registered
- **WHEN** a mentor saves a `DossierNote` for a learner with a `category` and `confidentiality`
- **THEN** the note is stored as an `appendOnly` OpenRegister object and cannot subsequently be edited
  in place

#### Scenario: A behaviour incident tracks follow-up to resolution

<!-- @e2e exclude Lifecycle transitions on an appendOnly object are backend/register mechanics with no
     distinct DOM surface beyond the timeline view already covered by
     tests/e2e/spec-coverage/pupil-dossier.spec.ts; transition correctness is asserted by PHPUnit
     schema-validation coverage, not a second Playwright path. -->

- **GIVEN** an open `BehaviourIncident`
- **WHEN** staff record a `followUpActions` entry and later transition it to `resolved`
- **THEN** the incident carries the full append-only follow-up history and its final `resolution`, and the
  `open → in-handling → resolved` transitions are enforced by `x-openregister-lifecycle`

#### Scenario: A learner submits a wellbeing check-in

<!-- @e2e tests/e2e/spec-coverage/pupil-dossier.spec.ts -->

- **GIVEN** an authenticated learner
- **WHEN** they submit a `WellbeingCheckIn` with a `moodScale` and optional `comment`
- **THEN** the check-in is stored as an `appendOnly` OpenRegister object with `learnerId` set

### Requirement: DossierNote confidentiality is enforced server-side at the object level; per-tier RBAC beyond that floor is a named platform gap

`DossierNote.x-property-rbac.read` MUST restrict every row to `anyOf`: `role: admin`, `role: mentor`,
`role: coordinator`, or a `match` of `authorId` against `$userId` — the same `anyOf`-role-plus-self-match
shape already used by `SupportRequest`/`FraudCase`/`ExternalTrainingRecord`. This is a real, enforced,
fail-closed server-side boundary: no parent, pupil, or unrelated Nextcloud account can read a `DossierNote`
via the OpenRegister object API, regardless of its `confidentiality` value.

The `confidentiality` field's three-way distinction (`team-visible` / `care-team-only` /
`private-to-author`) MUST NOT be implemented as a UI-only filter that silently hides rows a user's object-
level grant can still fetch directly from the object API — that would contradict this requirement's premise
that confidentiality is server-enforced. At the same time, the specification MUST NOT claim a row-conditional
RBAC capability the platform does not have: `x-property-rbac` applies one static `read` policy per schema,
uniformly to every row (verified across all 22 `x-property-rbac` occurrences in `lib/Settings/
scholiq_register.json`; the register's only `allOf` usage, line 5612, is JSON-Schema field-validity
conditioning, not an RBAC combinator). Full per-row tiering between `team-visible` and `care-team-only`
therefore is NOT achievable with `x-property-rbac` at HEAD and MUST be filed as a named OpenRegister
platform-capability gap (row-conditional `x-property-rbac`, e.g. a `match` comparing one object field against
another rather than only against `$userId`) rather than approximated with client-side hiding presented as
enforcement.

#### Scenario: A user outside the enforced floor cannot read a DossierNote at all

<!-- @e2e exclude RBAC-floor denial is asserted by PHPUnit/OpenRegister RBAC coverage, not a scholiq UI
     surface — mirrors FraudCase's identical "uninvolved user cannot read at all" scenario
     (openspec/specs/exam-board/spec.md:141-145). -->

- **GIVEN** a user who is not `admin`, not `mentor`, not `coordinator`, and not the note's `authorId`
- **WHEN** that user requests the `DossierNote` object via the OpenRegister API
- **THEN** the request is denied by `x-property-rbac.read` (fail-closed), independent of the note's
  `confidentiality` value

#### Scenario: The three-way confidentiality tier is a documented gap, not a fabricated guarantee

<!-- @e2e exclude Documents an absence of capability; there is nothing to drive in a browser. Tracked as a
     tasks.md follow-up item (file an OpenRegister platform-capability issue), not a scholiq test. -->

- **GIVEN** two `DossierNote`s for the same learner, one `team-visible` and one `care-team-only`, both
  authored by different `coordinator`-role staff
- **WHEN** a third `coordinator`-role staff member (not the author of either) requests both via the object
  API
- **THEN** both are currently returned (the enforced floor from the prior scenario is the same for both
  values) — this is the honest, current behaviour, and this requirement records it as a follow-up
  platform-capability gap rather than a documentation error to be silently "fixed" by a client-side filter

### Requirement: A behaviour incident escalates by referencing SupportRequest, never duplicating it

When a `BehaviourIncident` warrants formal support, staff MUST set `escalatedSupportRequestId` to the UUID of
a `SupportRequest` (`lib/Settings/scholiq_register.json:7151-7319`) created for that purpose. The incident
schema MUST NOT re-declare `supportDomain`, `urgency`, or any other `SupportRequest` field — escalation is a
reference, not a data fork. `BehaviourIncident`'s own lifecycle (`open → in-handling → resolved`) is
independent of the linked `SupportRequest`'s lifecycle; resolving the incident does not require the
`SupportRequest` to be closed, and vice versa.

#### Scenario: An incident escalates into a SupportRequest by reference

<!-- @e2e tests/e2e/spec-coverage/pupil-dossier.spec.ts -->

- **GIVEN** a `BehaviourIncident` with no `escalatedSupportRequestId`
- **WHEN** a coordinator creates a `SupportRequest` for the same learner and links it back to the incident
- **THEN** `BehaviourIncident.escalatedSupportRequestId` is set to the new `SupportRequest`'s UUID
- **AND** no `SupportRequest` field is duplicated onto `BehaviourIncident`

### Requirement: Creation is role-restricted; WellbeingCheckIn is learner-authored

`DossierNote` and `BehaviourIncident` creation MUST be restricted via `x-openregister-authorization.create`
to `admin`/`mentor`/`coordinator` — a learner or parent MUST NOT be able to author a dossier note or
behaviour incident about themselves or anyone else. `WellbeingCheckIn` creation carries no
`x-openregister-authorization` restriction — any authenticated learner may submit their own check-in;
server-side enforcement that `learnerId` matches the submitting user is a known platform gap at create time
(the same documented gap as `SupportRequest.raisedBy`, `lib/Settings/scholiq_register.json:7274`), not a new
one introduced by this change.

#### Scenario: A learner cannot author a DossierNote about another learner

<!-- @e2e exclude Create-time role authorization is asserted by PHPUnit/OpenRegister RBAC coverage, the same
     pattern as BsaWarning/BsaDecision's create-restriction tests; no distinct DOM surface. -->

- **GIVEN** an authenticated user holding only the `learner` role
- **WHEN** they attempt to create a `DossierNote` or `BehaviourIncident`
- **THEN** the request is rejected by `x-openregister-authorization.create`

### Requirement: Frontend is declarative, surfaced on the learner dossier page, with one shared custom timeline view

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `DossierNote`,
`BehaviourIncident`, and `WellbeingCheckIn`. `LearnerProfileDetail` MUST gain three new `object-list`
widgets (one per new schema, each `filter: { learnerId: "@objectId" }`, matching the existing `lprof-*`
widget pattern, e.g. `lprof-plans`). The only custom page is `PupilDossierTimelineView` (`type: "custom"`),
needed because merging `DossierNote` + `BehaviourIncident` + `WellbeingCheckIn` with the existing
`LearningPlan`/`SupportRequest`/`DeliberationRecord` care-chain objects into one chronological feed is
conditional cross-schema composition no `object-list` widget filter can express (single-schema, single-value
equality only). No PHP CRUD controllers.

#### Scenario: Pages are manifest-declared with one shared timeline-view exception

<!-- @e2e tests/e2e/spec-coverage/pupil-dossier.spec.ts -->

- **GIVEN** the `pupil-dossier` frontend is configured
- **WHEN** the app renders `DossierNote`/`BehaviourIncident`/`WellbeingCheckIn` screens and the learner
  dossier page
- **THEN** index/detail pages and the three `LearnerProfileDetail` widgets come from `src/manifest.json`,
  the only custom page is `PupilDossierTimelineView`, and no PHP CRUD controller is added

#### Scenario: The timeline view merges notes, incidents, check-ins, and the care chain

<!-- @e2e tests/e2e/spec-coverage/pupil-dossier.spec.ts -->

- **GIVEN** a learner with a mix of `DossierNote`, `BehaviourIncident`, `WellbeingCheckIn`, `LearningPlan`,
  and `SupportRequest` records
- **WHEN** a mentor opens `PupilDossierTimelineView` for that learner
- **THEN** all of them appear in one chronologically ordered feed

