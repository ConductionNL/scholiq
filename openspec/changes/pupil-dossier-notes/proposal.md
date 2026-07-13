---
kind: code
depends_on: []
---

## Why

Scholiq's only pupil-support instruments today are the **formal** ones — and there is a real gap
underneath them for the everyday record a mentor actually keeps day to day:

- **The formal care chain exists and is well-built.** `LearningPlan` (`lib/Settings/scholiq_register.json:
  6631-6929`) is the individualised plan (OPP/handelingsplan/IEP/PDP/IDP) with goals, support measures, a
  version chain, and co-signing. `SupportRequest` (`lib/Settings/scholiq_register.json:7151-7319`) is the
  zorgvraag a coordinator raises when a learner's needs may exceed what the school can provide alone, and it
  auto-queues an SWV data-exchange job. `DeliberationRecord` (`lib/Settings/scholiq_register.json:
  7580-7786`) is the append-only, signed record of a consultation round including the pupil's hoorrecht
  voice. `openspec/specs/learning-plan/spec.md` (read in full — 259 lines, 9 Requirements) covers exactly
  this: the document, the zorgvraag escalation, the SWV routing, and the TLV decision. All of it is
  formal-track: a `LearningPlan` requires a `coordinatorId` and a `kind` from a fixed OPP-style enum
  (`lib/Settings/scholiq_register.json:6643-6667`); `SupportRequest` requires a `supportDomain`/`urgency`
  and is restricted to `admin`/`principal` at create (`lib/Settings/scholiq_register.json:7269-7275`). None
  of these is meant for, or usable for, "the mentor jotted down that a phone call home happened" or "a
  learner had a rough week."
- **There is no everyday note/incident/wellbeing object anywhere in the register.** A case-insensitive,
  whole-file grep for `"Note"|"Incident"|"Observation"|"DossierNote"|"BehaviourIncident"|"Wellbeing"|
  "Welbevinden"|"CheckIn"` across all 59 schemas in `lib/Settings/scholiq_register.json` returns zero
  schema-name hits. The only matches at all are generic string sub-properties literally named `note` inside
  unrelated objects (e.g. `lib/Settings/scholiq_register.json:3365,6994,8562,10005` — free-text fields on
  other records, not a dossier-note object in their own right) and one unrelated enum value:
  `BpvVisitReport.visitKind` includes `"incident"` (`lib/Settings/scholiq_register.json:10976`) to classify
  a work-placement visit conversation — a BPV-visit taxonomy value, not a general pupil-behaviour-incident
  schema. `openspec/specs/learning-plan/spec.md` confirms the same gap in prose: every one of its
  Requirements is phrased around a single learner's formal document, its signatures, or the zorgvraag/TLV
  chain — nothing about routine observations, conversations, or incidents.
- **All three NL incumbents ship exactly this "everyday" layer** underneath their formal instruments —
  Magister's Leerlingdossier, SOMtoday's Begeleiding module, and ParnasSys's Groepskaart all give a mentor a
  running log of notes/incidents per pupil, separate from and feeding into the formal zorg/OPP track. A
  Dutch-market LVS that only has the formal chain and nothing underneath it is missing the module every
  mentor opens daily — this is the wave-2 planning brief's evidence for this change; no local
  Specter gap-report artifact names this feature by slug or demand count, so no such number is claimed here.
  Microsoft Teams Education's "Reflect" check-ins are cited (by the brief) as the shape precedent for the
  wellbeing self-report: a light, periodic mood/comment prompt visible to the educator, not a clinical
  instrument.

**Confidentiality is the hard part, and this proposal is explicit about what is and is not achievable.**
`DossierNote` is, alongside the zorgvraag chain, the most sensitive data category this app handles: routine
observations about a named minor, written by staff, about behaviour/conversations/concerns. A full survey of
every `x-property-rbac` block in the register (22 occurrences) shows the dialect supports exactly one shape:
a flat `read.anyOf` list of `{role: "<NC group>"}` and `{match: {field: "<field>", operator: "eq", value:
"$userId"}}` entries, applied **uniformly to every row of a schema** — there is no row-conditional
composition anywhere in the register (the only `allOf` in the whole file, `lib/Settings/
scholiq_register.json:5612`, is a plain JSON-Schema `if/then/else` for field *validity*, not an RBAC
combinator) and the only match operator ever used is `eq` against `$userId` (no `in`/array-membership
operator exists). That means a single `DossierNote` schema **cannot** enforce three different server-side
read policies for its three `confidentiality` values on a per-row basis — this is a genuine, verified
platform-capability gap, not a design oversight, and `design.md` §Decisions works out the honest, fail-closed
posture this change ships instead of pretending the gap away (mirrors how `SupportRequest.raisedBy`
(`lib/Settings/scholiq_register.json:7274`) and `FraudCase`'s hearing-record withholding
(`openspec/specs/exam-board/spec.md:131-139`) each name an equivalent gap rather than fabricate a capability
that isn't there).

## What Changes

- **New `pupil-dossier` capability** with three new OpenRegister objects:
  - **`DossierNote`** — author, learner, date, `category` (`observation`/`conversation`/`phone-call-home`/
    `concern`/`positive`), `body`, and `confidentiality` (`team-visible`/`care-team-only`/
    `private-to-author`). `appendOnly: true`. Server-side `x-property-rbac.read` restricts every row to
    `admin`/`mentor`/`coordinator`/the author — the enforceable floor today; the three-way tiering beyond
    that floor is a named, deferred platform gap (see design.md).
  - **`BehaviourIncident`** — what/where/who-involved, `severity`, an append-only `followUpActions` log
    (mirrors `AttendanceFlag.interventions`), `resolution`, and a nullable `escalatedSupportRequestId`
    (`$ref: SupportRequest`) — an incident *references* a `SupportRequest` when it escalates, it never
    duplicates zorgvraag fields. `appendOnly: true`, own lifecycle `open → in-handling → resolved`.
  - **`WellbeingCheckIn`** — a light, learner-authored periodic self-report: `moodScale` (1-5) + optional
    `comment`, visible to mentor/coordinator/admin and to the learner's own submissions. `appendOnly: true`,
    no lifecycle (a single point-in-time record).
- **Timeline surface on the learner dossier page**: `LearnerProfileDetail` gains three new declarative
  `object-list` widgets (DossierNotes/BehaviourIncidents/WellbeingCheckIns, each filtered by
  `learnerId: "@objectId"`, the existing `lprof-*` widget pattern), plus one new custom view,
  `PupilDossierTimelineView` (mirrors `ExamCaseDossierView`'s "one shared custom view" precedent), that
  chronologically merges `DossierNote` + `BehaviourIncident` + `WellbeingCheckIn` with the existing
  `LearningPlan`/`SupportRequest`/`DeliberationRecord` care-chain objects for one learner — genuinely needed
  because no `object-list` widget filter can merge more than one schema (verified: the four widget `type`s
  in `src/manifest.json` are `data`/`integration`/`object-list`/`related`, none of which merge schemas; the
  same limitation `groepsplan`'s proposal independently documents for a different reason).
- **AVG processing catalogue**: `avg-verwerkingsregister`'s seed catalogue gets three new draft entries
  (`scholiq-pupil-dossier-notes`, `scholiq-behaviour-incidents`, `scholiq-wellbeing-checkins`) — this data is
  personal data with a retention duty and was entirely absent from the Art. 30 catalogue because the schemas
  didn't exist yet.

## Impact

- **Affected specs**: `pupil-dossier` (new capability, ADDED), `avg-verwerkingsregister` (MODIFIED — one
  requirement's "at minimum" catalogue list gains the three new entries).
- **Affected code**: `lib/Settings/scholiq_register.json` (three new schemas, purely additive — no existing
  schema is modified), `src/manifest.json` (six new index/detail pages, three new widgets on
  `LearnerProfileDetail`, one new custom page), `src/views/PupilDossierTimelineView.vue` (new).
- **No PHP CRUD controllers.** All three objects are plain declarative OpenRegister schemas — no lifecycle
  guard classes, no calculation engine, no event listener is needed (unlike `BsaWarningSigningGuard`-style
  changes, nothing here computes a derived value or blocks a transition on a cross-object check).
- **Depends on nothing else in this wave** — `BehaviourIncident.escalatedSupportRequestId` references the
  wave-1 `SupportRequest` schema, which already exists at HEAD; no other wave-2 change needs to land first.
