# Design: pupil-dossier-notes

## Context

Scholiq has a well-built **formal** pupil-support chain — `LearningPlan` (individualised OPP/handelingsplan/
IEP/PDP/IDP), `SupportRequest` (zorgvraag), `TlvApplication`, `DeliberationRecord` — all documented in
`openspec/specs/learning-plan/spec.md`. What it does not have is the layer underneath: the running log a
mentor keeps day to day — an observation, a phone call home, a behaviour incident, a "how are you doing"
check-in — most of which never becomes formal, but some of which is exactly the evidence that later
justifies raising a `SupportRequest`. All three Dutch incumbents (Magister Leerlingdossier, SOMtoday
Begeleiding, ParnasSys Groepskaart) ship this layer; Scholiq does not.

This document works out two things the proposal's "Why" flags as hard: (1) what `x-property-rbac` can and
cannot enforce for a three-tier `confidentiality` field on one schema, verified against every existing usage
in the register, and (2) how `BehaviourIncident` escalates into `SupportRequest` without duplicating it.

## Goals / Non-Goals

**Goals**
- Give staff a place to record routine observations, conversations, and incidents per learner, distinct from
  the formal OPP/zorgvraag track.
- A light, learner-authored wellbeing self-report visible to the mentor.
- A single chronological view of a learner's dossier — notes, incidents, check-ins, and the existing formal
  care-chain objects — where today a mentor has to open five separate object lists.
- Be honest about what `x-property-rbac` can enforce today for `confidentiality`, and ship the safest
  achievable posture rather than a UI-only approximation presented as server-side enforcement.

**Non-Goals**
- A generalised, reusable "confidential note" primitive for other capabilities — this change ships the
  narrowest thing that solves the stated problem; a platform-level row-conditional RBAC capability (see
  Decisions below) is a follow-up, not built here.
- Any change to `SupportRequest`, `DeliberationRecord`, or `LearningPlan` — escalation is a reference field
  on the new `BehaviourIncident` schema, not a modification to the wave-1 objects.
- A clinical wellbeing/mental-health instrument. `WellbeingCheckIn` is the Reflect-style shape (mood scale +
  optional comment); anything diagnostic is out of scope and, if ever needed, is a distinct capability with
  its own AVG Art. 9 basis.
- PDF export / audit-pack inclusion of dossier notes. `compliance-audit`'s audit pack is scoped to compliance
  training evidence today; whether pupil-dossier notes belong in a future export is a separate decision.

## Data Model

```
LearnerProfile
    │
    ├──< DossierNote (appendOnly; category, confidentiality, body)
    │        no lifecycle — flat record
    │
    ├──< BehaviourIncident (appendOnly; severity, followUpActions[], resolution)
    │        x-openregister-lifecycle: open → in-handling → resolved
    │        escalatedSupportRequestId ──> SupportRequest (wave-1, referenced not duplicated)
    │
    └──< WellbeingCheckIn (appendOnly; moodScale 1-5, comment)
             no lifecycle — single point-in-time record

PupilDossierTimelineView (custom, one page)
    merges: DossierNote + BehaviourIncident + WellbeingCheckIn
          + LearningPlan + SupportRequest + DeliberationRecord (all filtered by learnerId)
```

All three new schemas are purely additive — no existing schema's `properties`/`required` changes.
`BehaviourIncident.escalatedSupportRequestId` is a nullable `$ref: SupportRequest`, the same
reference-not-duplicate shape `TlvApplication.supportRequestId` already uses
(`lib/Settings/scholiq_register.json:7338-7344`).

## Decisions

### 1. Confidentiality: what `x-property-rbac` can actually enforce, and what it can't

The brief's requirement is unambiguous: confidentiality must be enforced server-side, not merely hidden in
the UI. Before designing around that, this change verified exactly what `x-property-rbac` supports today by
reading every one of its 22 occurrences in `lib/Settings/scholiq_register.json`. The finding, consistently,
across every schema that uses it (`Credential`, `Enrolment`, `ExternalTrainingRecord`, `LearnerProfile`,
`Submission`, `GradeEntry`, `FinalGrade`, `ExemptionCase`, `SupportRequest`, `FraudCase`, and more):

- `x-property-rbac.read` is exactly one static `{ anyOf: [...] }` list, declared once **per schema**.
- Each `anyOf` entry is either `{ role: "<NC group>" }` or `{ match: { field: "<field>", operator: "eq",
  value: "$userId" } }`.
- The match operator is `eq` only — never observed with any array-membership/`in` operator.
- The dialect is applied **uniformly to every row** of the schema. There is no mechanism anywhere in the
  register for a schema's RBAC outcome to vary conditionally on one of the object's own field *values*
  (e.g., "if `confidentiality == 'care-team-only'`, tighten the readable role set"). The only `allOf` in the
  entire file (`lib/Settings/scholiq_register.json:5612-5630`) is a JSON-Schema `if/then/else` gating field
  *validity* (`GradeEntry.value` requiredness by `sourceKind`), not an RBAC combinator — confirmed by reading
  it in full; it has nothing to do with `x-property-rbac`.

Given that, a single `DossierNote` schema mathematically cannot give `team-visible`, `care-team-only`, and
`private-to-author` rows three different enforced readerships — whatever `x-property-rbac.read` says applies
to all of them alike. Three concrete options were weighed:

- **(a) Split into three schemas by confidentiality tier.** Rejected — `category` (observation/conversation/
  phone-call-home/concern/positive) is orthogonal to confidentiality; splitting would triple the schema and
  make "change this note's confidentiality" a cross-schema move instead of a field edit, for a gain (row-
  conditional RBAC) achievable in three ways that all feel worse than the option below.
- **(b) Set the schema-level floor to the loosest tier (`team-visible`) and treat `care-team-only`/
  `private-to-author` as UI-only filters.** Rejected outright — this is precisely the "UI-hidden" posture the
  brief says not to ship, and it would silently over-share: any `mentor`/`coordinator` account could fetch a
  `private-to-author` note directly from the object API despite the UI never showing it to them.
  - **Reconsider if:** never — this direction is a strictly worse security posture than (c) below and
    should not be revisited without a platform capability change.
- **(c) Set the schema-level floor to the tightest common bound the dialect can express — `admin`/`mentor`/
  `coordinator`/the note's own `authorId` — and name the remaining three-way distinction as a platform gap.**
  **Chosen.** This is real, load-bearing, server-side enforcement of the boundary that matters most in
  practice: no parent, pupil, or unrelated Nextcloud account can ever read a `DossierNote`, regardless of its
  `confidentiality` value. It fails closed (under-shares — a `team-visible` note is, worst case, as
  restricted as a `care-team-only` one today) rather than failing open. The genuinely finer distinction
  between "the whole mentor/coordinator floor" and "just the author" requires row-conditional RBAC
  OpenRegister does not have at HEAD; `tasks.md` files this as a named follow-up (an OpenRegister
  platform-capability issue: `x-property-rbac.match` comparing one object field against another, or against a
  caller-role-scoped subset, not only against `$userId`) rather than papering over it.

This is the same category of honesty already established in this register: `SupportRequest.raisedBy`'s
_comment (`lib/Settings/scholiq_register.json:7274`) names "a known platform gap" for enforcing
authorship-matches-creator at create time rather than fabricating the check; `FraudCase`'s spec
(`openspec/specs/exam-board/spec.md:131-139`) explicitly states its `hearingRecords` withholding "is an
application-level UI convention, not a server-enforced field-level RBAC guarantee." This change follows the
same convention: it enforces everything the dialect can enforce, and names precisely what it can't, instead
of enforcing nothing (option b) or inventing a capability that doesn't exist (option a's implicit promise of
per-tier isolation that a triple-schema split doesn't actually deliver any more cleanly, since even three
schemas each still need row-conditional logic to separate "care-team-only" from "team-visible" *within* the
one schema that would hold both).

`confidentiality`'s default is `care-team-only` (not `team-visible`) — the safer default per privacy-by-
design: a mentor must deliberately widen a note to the full staff floor rather than accidentally narrow one
that should have stayed tighter. (Note that, per the gap above, this default currently has no observable
effect on the object API's actual readership — it changes only what the UI presents by default until the
platform gap is closed. This is stated plainly, not hidden.)

### 2. BehaviourIncident escalates by reference, never by duplication

`escalatedSupportRequestId` is a nullable `$ref: SupportRequest`, set once a coordinator raises a formal
zorgvraag from an incident. `BehaviourIncident` does not carry `supportDomain`, `urgency`, or any other
`SupportRequest` field — mirrors `TlvApplication.supportRequestId`'s reference-only shape exactly
(`lib/Settings/scholiq_register.json:7338-7344`, "A TLV always traces to a SupportRequest"). The two
lifecycles are independent: an incident can resolve without ever escalating, and an escalated incident's own
`resolved` transition does not require the linked `SupportRequest` to be `closed`.

### 3. Why a new `pupil-dossier` capability, not a `learning-plan` delta

`learning-plan`'s own Purpose and all nine of its Requirements are phrased around the **formal** instrument
(the plan document, its co-signing, the zorgvraag/TLV escalation chain). Routine notes/incidents/check-ins
are a different shape (appendOnly evidence records, no version-chain, no co-signing) serving a different
audience (every mentor, daily) than the formal chain (coordinators, occasionally). This is the same call
`attendance` made relative to `school-structure`, and `study-progress` made relative to `grading`/
`enrolment` (`openspec/changes/archive/2026-07-13-bsa-study-progress-guard/design.md` "Rejected
Alternatives"): a capability that consumes an existing one by reference, rather than folding unrelated
requirements into it, keeps each spec's ownership boundary clean (ADR-022).

### 4. Why one custom view, not three more manifest widgets alone

`object-list` widgets (the `lprof-*` pattern on `LearnerProfileDetail`) filter exactly one schema by one
equality match. Three new widgets there (`DossierNote`/`BehaviourIncident`/`WellbeingCheckIn`, each scoped
by `learnerId: "@objectId"`) fully satisfy "surface these objects on the dossier page" declaratively — and
this change adds them. But the brief also asks for a genuine merged, chronological view across six schemas
(the three new ones plus `LearningPlan`/`SupportRequest`/`DeliberationRecord`), which no single `object-list`
filter can express (verified: the four widget types in `src/manifest.json` are `data`/`integration`/
`object-list`/`related` — none merge schemas). `PupilDossierTimelineView` is therefore the one custom-view
exception, the same bar `ExamCaseDossierView` and `BsaRiskDashboard` were held to: a genuine cross-schema
composition, not a CRUD form the manifest could otherwise express.

## Security / Privacy Posture

- `DossierNote`, `BehaviourIncident`, `WellbeingCheckIn` are all `appendOnly: true` (ADR-008) — corrections
  are new records, never in-place edits of evidence about a named minor.
- `DossierNote`/`BehaviourIncident` creation is restricted to `admin`/`mentor`/`coordinator`
  (`x-openregister-authorization.create`) — a learner or parent cannot author either about anyone.
- `x-property-rbac.read` on `DossierNote` and `BehaviourIncident` restricts every row to
  `admin`/`mentor`/`coordinator`/the author — see Decision 1 above for the full reasoning and its explicitly
  named residual gap.
- `WellbeingCheckIn.x-property-rbac.read` restricts every row to `admin`/`mentor`/`coordinator`/the
  submitting learner (self-read of one's own check-ins) — the "mentor" role check here is the school-wide
  `mentor` NC group, not the learner's specifically assigned mentor (`LearnerProfile.managerId`);
  `x-property-rbac.match` can only compare a field on the object itself against `$userId`, not against a
  related object's field, so scoping to "this learner's specific mentor" is not expressible — the identical
  granularity limit already documented for `SupportRequest.raisedBy`.
- **Open question for the privacy officer, not resolved by this spec**: whether `WellbeingCheckIn.moodScale`
  constitutes AVG Art. 9 special-category (health-adjacent) data requiring a stricter legal basis than
  `public-task`. This change ships `public-task` (matching `LearnerProfile`'s own basis) as a draft seed —
  the processing-activity entry arrives as a **draft**, per `avg-verwerkingsregister`'s existing convention,
  specifically so the privacy officer makes this call before activation, not this spec.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects (`lib/Settings/scholiq_register.json`); no new database tables.
- No pass-through CRUD controller — all three objects are plain declarative schemas; no PHP is needed at
  all (no calculation engine, no lifecycle guard class, no event listener — `BehaviourIncident`'s lifecycle
  transitions have no guard, matching `AttendanceThreshold`'s own unguarded `activate`/`archive` transitions,
  same precedent the `bsa-study-progress-guard` change cites for its own unguarded appeal transitions).
- UI is manifest-driven; the one custom view (`PupilDossierTimelineView`) is a genuine cross-schema merge, not
  a CRUD form.
- i18n keys in English; SPDX headers on any new PHP (none is anticipated by this design, but `tasks.md`
  carries the gate regardless in case implementation surfaces a need).
