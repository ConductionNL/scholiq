---
slug: bpv
title: BPV — Beroepspraktijkvorming, Praktijkovereenkomst, Workplace Assessment
status: in-progress
feature_tier: must
depends_on_adrs: [ADR-005, ADR-022, ADR-024, ADR-031, ADR-046]
created: 2026-07-12
updated: 2026-07-12
profiles: [bpv-mbo-nl, dual-apprenticeship-generic]
---

# BPV — Beroepspraktijkvorming

@e2e exclude Pure backend/data-model + portal-manifest spec. Requirements define OpenRegister schema shapes, a pluggable PHP interface, lifecycle guards, and a portal-contribution manifest addition — no in-app `#### Scenario:` headings describe a Scholiq-rendered UI flow beyond the declarative manifest pages already covered by other specs' UI conventions.

## Purpose

Every MBO qualification includes **beroepspraktijkvorming (BPV)** — a work
placement at a real employer — and Dutch law (WEB art. 7.2.8/7.2.9) makes three
things mandatory: the employer must be a **leerbedrijf erkend by SBB**
(Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven), a signed
**praktijkovereenkomst (POK)** must exist before the placement starts (student,
school, and the workplace supervisor — the **praktijkopleider** — all sign it),
and the school must assess and visit the placement over its course. Scholiq has
no coverage of any of this today. This spec generalises the model where the
patterns already generalise elsewhere in Scholiq: `BpvPlacement` is this app's
first cross-organisation match record (learner ↔ external company), the
leerbedrijf check is a pluggable provider exactly like proctoring and
plagiarism, the three-party signing reuses the `Signature` schema's shape (not
literally the schema — see design.md for why), and the praktijkopleider — a
person with no Nextcloud account by definition — reaches Scholiq exclusively
through the ADR-046 portaliq external portal, as a new `praktijkopleider`
audience alongside the already-shipped `student`/`parent` ones.

## What

- **Praktijkopleider** — the workplace supervisor: name, contact details, the
  leerbedrijf they represent (name + KVK number), `active`. This object's own
  UUID is the portal identity anchor (it never had an NC user id — no
  additive-remap needed, unlike `LearnerProfile`/guardian, because this schema
  is being modelled fresh with the "no NC account" premise already built in).
- **BpvPlacement** — the learner ↔ leerbedrijf match for one BPV period:
  learner (`learnerId` NC uid + `learnerRef` UUID, both from day one),
  `programmeId`/`curriculumPlanId` (the `opleidingsplan-mbo` `CurriculumPlan`),
  `praktijkopleiderId` (→ `Praktijkopleider`), `schoolCoachId` (NC uid — the
  internal BPV-coordinator/docent-begeleider), leerbedrijf name + KVK number,
  period, a `leerbedrijfVerification` block, and `lifecycle`
  (`proposed → sbb-verification-pending → confirmed → active → completed | terminated`).
- **`ProvidesLeerbedrijfVerification`** — the one PHP seam for the SBB
  erkend-leerbedrijf check: `verify(string $kvkOrErkenningNumber): array`
  returning `{status, erkenningNumber, expiresAt, raw}`. No bundled provider
  ships with Scholiq — resolved from `BpvPlacement.leerbedrijfVerification.provider`,
  exactly like `Assessment.proctoring.provider` → `ProvidesProctoring` and
  `Assignment.plagiarismProvider` → `ProvidesPlagiarismCheck`.
- **Praktijkovereenkomst (POK)** — the three-party agreement for one
  `BpvPlacement`: period, structured terms/clauses, `lifecycle`
  (`draft → pending-signatures → active → completed | terminated`), and an
  `isFullySigned` calculation gating activation.
- **PokSignature** — a co-sign record on a specific POK, shaped identically to
  the `learning-plan` `Signature` schema (`subjectId`, `subjectVersion`,
  `signerId`, `signerRole`, `signedAt`, `assuranceLevel`, `method`,
  `evidenceRef`, append-only) with `signerRole` limited to
  `student | school | praktijkopleider`.
- **WerkprocesAssessment** — one workplace assessment against one werkproces of
  the kwalificatiedossier, for one `BpvPlacement`: the existing
  `curriculumPlanId` + `componentId` pair (feeds grading, `kind: "assessment"` —
  no new component kind needed), plus `kwalificatiedossierCode`,
  `kerntaakCode`, `werkprocesCode`, `werkprocesLabel` (the kwalificatiedossier
  taxonomy identifiers), `assessorId` (→ `Praktijkopleider`), `beoordeling`
  (`nog-niet-competent | competent`), `toelichting` (narrative), `lifecycle`
  (`draft → submitted → confirmed`).
- **BpvVisitReport** — a visit or three-way-conversation record for one
  `BpvPlacement`, linked to the learner's `LearnerProfile` dossier via
  `learnerRef`: `visitKind` (`voortgangsbezoek | tussentijds-gesprek |
  eindgesprek | incident`), attendees, narrative, action points, and a declared
  `visitDueReminder` notification off `nextVisitDue`.

## User Stories

- As a BPV-coordinator, I want to record a BpvPlacement for a learner and a
  leerbedrijf, have the SBB erkenning check run before I can confirm it, and be
  blocked from confirming an unverified company.
- As a student, a school coordinator, and a praktijkopleider, I each want to
  review and sign the POK before the placement starts, and see it become
  `active` only once all three signatures exist.
- As a praktijkopleider, I want to log into the shared portal, see my current
  placements, and submit a workplace assessment per werkproces without ever
  needing a Nextcloud account.
- As a BPV-coordinator, I want to log a visit or three-way conversation against
  a placement and see the next one's due date, so nothing falls through the
  cracks over a multi-month placement.
- As an auditor, I want the full trail — verification result, three signatures,
  every werkproces assessment, every visit report — for one placement, appeal-proof.

## Acceptance Criteria

- GIVEN a `BpvPlacement` whose `leerbedrijfVerification.status` is not
  `verified`, WHEN a coordinator attempts to confirm it, THEN the transition is
  blocked by `BpvConfirmationGuard`.
- GIVEN a `BpvPlacement` with `leerbedrijfVerification.provider` set to an
  adapter that returns `status: verified`, WHEN the coordinator confirms it,
  THEN the transition succeeds and no concrete SBB adapter ships with Scholiq
  itself — the app works with the interface unconfigured (confirmation simply
  stays blocked).
- GIVEN a `Praktijkovereenkomst` with two of three `PokSignature`s recorded,
  WHEN the third (any role) is recorded, THEN `isFullySigned` becomes true and
  `PokActivationGuard` allows the `activate` transition; before that, activation
  is blocked.
- GIVEN a praktijkopleider authenticated at the portal with `subjectRef` equal
  to their own `Praktijkopleider` object UUID, WHEN they call
  `getContribution()`, THEN they see only `BpvPlacement`s where
  `praktijkopleiderId == subjectRef`, field-projected to drop
  `schoolCoachId` and the verification's raw provider payload.
- GIVEN the same praktijkopleider, WHEN they submit `createWerkprocesAssessment`
  or `signPraktijkovereenkomst` via the portal, THEN `assessorId` /
  `signerId` is stamped server-side from `subjectRef` — never accepted from the
  request body.
- GIVEN a `BpvVisitReport` is finalized, WHEN `nextVisitDue` arrives, THEN a
  `visitDueReminder` notification fires to the `schoolCoachId` (the
  praktijkopleider has no NC account to notify — see design.md).

## ADDED Requirements

### Requirement: Persist BPV domain objects in OpenRegister
The system MUST persist `Praktijkopleider`, `BpvPlacement`, `Praktijkovereenkomst`, `PokSignature`, `WerkprocesAssessment`, `BpvVisitReport` as OpenRegister objects with `x-openregister-lifecycle` (`BpvPlacement`: proposed → sbb-verification-pending → confirmed → active → completed | terminated; `Praktijkovereenkomst`: draft → pending-signatures → active → completed | terminated; `WerkprocesAssessment`: draft → submitted → confirmed; `BpvVisitReport`: draft → finalized), relations expressed as `$ref` UUID properties (`BpvPlacement`↔`Praktijkopleider`/`LearnerProfile`/`CurriculumPlan`, `Praktijkovereenkomst`↔`BpvPlacement`, `PokSignature`↔`Praktijkovereenkomst`, `WerkprocesAssessment`↔`BpvPlacement`/`CurriculumPlan`, `BpvVisitReport`↔`BpvPlacement`/`LearnerProfile`), and `x-openregister-calculations` (`Praktijkovereenkomst.isFullySigned`, `BpvVisitReport.nextVisitDue`).

#### Scenario: BPV objects persisted in OpenRegister
- **GIVEN** the BPV domain schemas are registered
- **WHEN** a coordinator creates a `BpvPlacement`, its `Praktijkovereenkomst` with `PokSignature`s, a `WerkprocesAssessment`, and a `BpvVisitReport`
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle, `$ref`-based relations, and calculations

### Requirement: BpvPlacement confirmation is gated on verified leerbedrijf status
`BpvPlacement` MUST NOT transition to `confirmed` unless `leerbedrijfVerification.status` is `verified`, enforced by a lifecycle guard (`BpvConfirmationGuard`, an ADR-031 PHP exception).

#### Scenario: Confirmation blocked until the leerbedrijf is verified
- **GIVEN** a `BpvPlacement` with `leerbedrijfVerification.status` of `unverified`, `pending`, `rejected`, or `expired`
- **WHEN** a coordinator attempts the `confirm` transition
- **THEN** `BpvConfirmationGuard` blocks it
- **AND** once a verification result of `verified` is recorded, the `confirm` transition succeeds

### Requirement: Leerbedrijf verification is a pluggable provider
The SBB erkend-leerbedrijf check MUST be a declared `leerbedrijfVerification.provider` config on `BpvPlacement` resolving to `ProvidesLeerbedrijfVerification` (a new PHP interface, analogous to `ProvidesProctoring` in `assessment` and `ProvidesPlagiarismCheck` in `assignments`). Scholiq MUST ship NO concrete provider; an OpenConnector-based SBB register adapter is out-of-repo follow-up work and MUST NOT be required for this app to function (an unconfigured provider simply leaves `BpvPlacement` unable to confirm, not broken).

#### Scenario: Verification resolves to a pluggable provider without a bundled adapter
- **GIVEN** a `BpvPlacement` with a `leerbedrijfVerification.provider` config and no concrete provider bundled in the app
- **WHEN** the config resolves the provider through `ProvidesLeerbedrijfVerification`
- **THEN** the configured adapter (if any) returns the verification result and no SBB wire protocol is implemented inside Scholiq itself

### Requirement: Three-party POK signing reuses the Signature pattern via PokSignature
`Praktijkovereenkomst` signing MUST use a `PokSignature` schema shaped identically to the `learning-plan` `Signature` schema (`subjectId`, `subjectVersion`, `signerId`, `signerRole`, `signedAt`, `assuranceLevel`, `method`, `evidenceRef`, append-only), with `signerRole` restricted to `student | school | praktijkopleider`. The existing `Signature` schema MUST NOT be widened (its `subjectId` is hard-`$ref`'d to `LearningPlan`; widening it to a polymorphic subject would violate the fleet's single-schema relation-dialect rule).

#### Scenario: A POK version requires all three roles signed
- **GIVEN** a `Praktijkovereenkomst` version with a `PokSignature` from `student` and `school` recorded
- **WHEN** the `praktijkopleider` signs (via the portal)
- **THEN** a third `PokSignature` is recorded, append-only, for that version
- **AND** the prior two signatures remain unchanged

### Requirement: POK activation is gated on all three signatures
`Praktijkovereenkomst` MUST NOT transition to `active` unless a `PokSignature` exists for each of `student`, `school`, and `praktijkopleider` on the current version, enforced by `PokActivationGuard` and reflected in the `isFullySigned` calculation.

#### Scenario: Activation blocked until fully signed
- **GIVEN** a `Praktijkovereenkomst` missing the `praktijkopleider` signature
- **WHEN** an attempt is made to activate it
- **THEN** `PokActivationGuard` blocks the transition and `isFullySigned` is `false`
- **AND** once the missing signature is recorded, `isFullySigned` becomes `true` and activation succeeds

### Requirement: WerkprocesAssessment aligns to the kwalificatiedossier and emits a GradeEntry
`WerkprocesAssessment` MUST carry the kwalificatiedossier taxonomy (`kwalificatiedossierCode`, `kerntaakCode`, `werkprocesCode`, `werkprocesLabel`) alongside an existing `curriculumPlanId`/`componentId` pair (`kind: "assessment"`), and a confirmed assessment MUST emit (or update) a `GradeEntry` for that component consumed by the `grading` spec; this schema MUST NOT compute the final grade itself.

#### Scenario: A confirmed werkproces assessment feeds grading
- **GIVEN** a `WerkprocesAssessment` reaches the `confirmed` lifecycle state
- **WHEN** it is confirmed
- **THEN** a `GradeEntry` is emitted or updated for its `curriculumPlanId`/`componentId`, consumed by the `grading` spec, and this schema computes no final grade itself

### Requirement: BpvVisitReport links to the learner dossier with a declared reminder
`BpvVisitReport` MUST relate to the learner via `learnerRef` (the same `LearnerProfile` UUID convention used fleet-wide) and MUST declare a `visitDueReminder` notification off `nextVisitDue`, targeting the internal `schoolCoachId` recipient (not the praktijkopleider — no NC-reachable channel exists for them; see design.md).

#### Scenario: A visit report is linked to the dossier and a reminder fires on cadence
- **GIVEN** a finalized `BpvVisitReport` for a `BpvPlacement`
- **WHEN** it is viewed from the learner's `LearnerProfile`
- **THEN** it appears linked via `learnerRef`
- **AND WHEN** `nextVisitDue` arrives, a `visitDueReminder` notification fires to `schoolCoachId` via the declared notification mechanism, idempotency-keyed

### Requirement: Praktijkopleider portal access is a direct-scope PortalContributionProvider audience
`lib/Portal/PortalContributionProvider.php` MUST add a `praktijkopleider` audience to `getAudiences()`, scoped by a **direct** match (`praktijkopleiderId == subject.subjectRef` on `BpvPlacement`/`WerkprocesAssessment`/`PokSignature`) — never a reverse join — and MUST return `null` for any subject whose audience it does not recognise (fail-closed, matching the existing `student`/`parent` branches).

#### Scenario: A praktijkopleider sees only their own placements, field-projected
- **GIVEN** a portal subject with `audience: "praktijkopleider"` and `subjectRef` equal to a `Praktijkopleider` object UUID
- **WHEN** `getContribution()` is called
- **THEN** the returned manifest's `bpvPlacements` collection is scoped by `praktijkopleiderId == subjectRef`, and its `fields` whitelist excludes `schoolCoachId` and `leerbedrijfVerification.raw`

### Requirement: Praktijkopleider portal actions never trust client-supplied identity
The `createWerkprocesAssessment` and `signPraktijkovereenkomst` portal actions MUST be `type: "create"` with `scopeField` stamped server-side from `subject.subjectRef` (never accepted from the request body), a strict field whitelist that excludes any grade/status/staff-decision field, and `minTrust: "substantial"`.

#### Scenario: A werkproces assessment is created with a server-stamped assessor
- **GIVEN** a praktijkopleider portal subject submitting `createWerkprocesAssessment`
- **WHEN** the action runs
- **THEN** `assessorId` is stamped from `subject.subjectRef` server-side, the whitelisted fields are limited to placement/kwalificatiedossier/beoordeling data, and the action requires `minTrust: "substantial"`

### Requirement: Frontend is declarative with one named custom view
The frontend MUST be declarative: `src/manifest.json` index/detail pages for `BpvPlacement`, `Praktijkopleider`, `Praktijkovereenkomst`, `WerkprocesAssessment`, `BpvVisitReport`. The only custom page is `SignPokModal` (`type: "custom"`, mounting the shared `CnSignatureCapture` component — mirroring the existing `SignPlanModal` pattern) for the student/school signing legs inside Scholiq's own UI. No PHP CRUD controllers.

#### Scenario: Pages are manifest-declared with one signing exception
- **GIVEN** the BPV frontend is configured
- **WHEN** the app renders BpvPlacement, Praktijkopleider, Praktijkovereenkomst, WerkprocesAssessment, and BpvVisitReport screens
- **THEN** index/detail pages come from `src/manifest.json` and the only custom page is `SignPokModal` mounting `CnSignatureCapture`, with no PHP CRUD controllers

## Standards

WEB art. 7.2.8/7.2.9 (praktijkovereenkomst + erkend leerbedrijf mandate); SBB
erkenning register (MBO Raad servicedocument POK); the MBO kwalificatiedossier /
kerntaak / werkproces taxonomy (SBB); eIDAS assurance levels for the signing
strength (shared with `learning-plan`); ADR-046 portaliq for external-portal
access.

## Data Model

All in OpenRegister. New: `Praktijkopleider`, `BpvPlacement`,
`Praktijkovereenkomst`, `PokSignature`, `WerkprocesAssessment`,
`BpvVisitReport`. Consumes: `LearnerProfile`, `CurriculumPlan` (existing
`opleidingsplan-mbo` profile), `GradeEntry` (from `grading`). New PHP
interface: `ProvidesLeerbedrijfVerification` (`lib/Bpv/`). Two ADR-031 PHP
exceptions: `BpvConfirmationGuard`, `PokActivationGuard`. See design.md.

## Out of Scope

- The SBB register OpenConnector adapter itself (interface only here — a
  `ConductionNL/openconnector` cross-repo follow-up).
- PDF/print rendering of the signed POK (a `docudesk` leaf note, follow-up; the
  POK's legal state is the OpenRegister object + its signatures).
- Leerbedrijf re-verification / erkenning-expiry monitoring past the initial
  confirmation gate.
- An email/external-recipient notification channel (documented gap; declared
  BPV notifications reach only NC-account holders today).
- Automated BPV-to-leerbedrijf matching/suggestion (this spec is a manual
  match record, not a matching algorithm).
- Portal read/write access to `BpvVisitReport` (visit reports are recorded by
  school staff via the standard manifest pages in this change; a
  praktijkopleider-facing view of visit reports is a later portal slice).
