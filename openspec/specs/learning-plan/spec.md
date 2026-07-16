---
slug: learning-plan
title: Individual Learning Plan
status: done
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opp-passend-onderwijs, handelingsplan, iep, pdp-he, idp-corporate]
replaces: [opp-cycle]
---

# Individual Learning Plan

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, version-chain immutability, and notification config — no `#### Scenario:` headings exist in this spec.

## Purpose

Some learners need an individualised plan: a school pupil with extra ondersteuningsbehoeften, a university student on a remediation track, an employee on a personal-development plan. The structure is the same everywhere — a set of **goals**, the **support measures** in place to reach them, a **review cycle** with dated **evaluations**, and **signatures** (the learner / parent / coordinator co-sign each version). In the Netherlands the **Wet Passend Onderwijs** makes the **Ontwikkelingsperspectief (OPP)** mandatory for every pupil with extra needs, and `handelingsplannen` sit underneath it; ParnasSys owns ~65% of the PO market but the OPP UI is widely criticised. This spec generalises it: `LearningPlan` is the abstract document, the Dutch **OPP** is one profile (with its sector-template structure and DigiD parent-signing), an **IEP** (US), a higher-ed **PDP**, and a corporate **IDP** are others.

## What

- **LearningPlan** — per learner: `kind` (the profile: `opp` | `handelingsplan` | `iep` | `pdp` | `idp`), a `templateId` (sector/institution template that pre-structures the sections), `goals` (`{ goalId, description, targetDate, domain, baseline, target, status }[]`), `supportMeasures` (`{ measureId, description, responsibleId, startDate, endDate }[]`), `period`, `lifecycle` (draft → active → under-evaluation → closed | superseded), and a `version` chain (each material revision is a new version that must be re-signed).
- **LearningPlanEvaluation** — a dated review point: which goals were met / adjusted / dropped, narrative, attendees, next-review date. Created on the plan's review cadence (a `quarterlyReviewReminder` notification driven off the plan's `period` — no TimedJob).
- **Signature** — a co-sign on a specific LearningPlan version by the learner, a parent/guardian, and the coordinator. Signing may go through an external authenticated flow (Dutch: DigiD) — the *signing-strength* is configurable; the plan only records who signed which version when and with what assurance level.
- Linking to evidence: a LearningPlan goal may reference `AssessmentResult`s, `GradeEntry`s, attendance, or uploaded artefacts as progress evidence.

## User Stories

- As a learning-support coordinator, I want to create a LearningPlan from a sector template so the required sections are pre-structured and I just fill them in.
- As a coordinator, I want a reminder when a plan's quarterly evaluation is due, and a place to record the evaluation outcome against each goal.
- As a parent, I want to review a new plan version and co-sign it (via DigiD where required) before it becomes active.
- As a teacher, I want to see the active LearningPlan goals for a pupil in my cohort so my teaching reflects the support measures.
- As an auditor (inspectie / accreditation body), I want the full version + signature history of a plan so I can verify it was co-signed and reviewed on cadence.

## Acceptance Criteria

- GIVEN a sector template `opp-vo`, WHEN a coordinator creates a LearningPlan with `kind=opp` and that template, THEN the plan is pre-populated with the template's sections and goal domains.
- GIVEN an active LearningPlan with a quarterly review cadence, WHEN the next-review date arrives, THEN a `quarterlyReviewReminder` notification fires to the coordinator (idempotency-keyed so a re-tick doesn't double-fire).
- GIVEN a plan moves to a new version, WHEN it is presented for signing, THEN it is `draft` until all required co-signers have signed; only then does it become `active` and supersede the prior version.
- GIVEN a DigiD-required signature, WHEN a parent signs, THEN the Signature records the assurance level; a signature without the required level does not satisfy the co-sign requirement.
- GIVEN an evaluation records a goal as "met", WHEN the plan is viewed, THEN that goal shows `status: met` with the evaluation that closed it linked.
## Requirements
### Requirement: Persist LearningPlan domain objects in OpenRegister
The system MUST persist `LearningPlan`, `LearningPlanEvaluation`, `Signature` as OpenRegister objects with `x-openregister-lifecycle` (LearningPlan: draft → active → under-evaluation → closed | superseded), `x-openregister-relations` (LearningPlan↔learner/template/cohort, Evaluation↔LearningPlan, Signature↔LearningPlan-version), `x-openregister-calculations` (LearningPlan `goalsMetCount`, `nextReviewDue`, `isFullySigned`), and `x-openregister-notifications` (`quarterlyReviewReminder`, `signatureRequested`, idempotency-keyed).

#### Scenario: LearningPlan objects persisted in OpenRegister
- **GIVEN** the learning-plan domain schemas are registered
- **WHEN** a coordinator saves a `LearningPlan` with its evaluations and signatures
- **THEN** `LearningPlan`, `LearningPlanEvaluation`, and `Signature` are stored as OpenRegister objects carrying the declared lifecycle, relations, calculations, and notification config

### Requirement: Review reminder is a declared notification
The review-reminder MUST be a declared notification off the plan's period — not a PHP TimedJob.

#### Scenario: Review reminder fires from a declared notification
- **GIVEN** an active LearningPlan with a quarterly review cadence
- **WHEN** the next-review date arrives
- **THEN** a `quarterlyReviewReminder` notification fires to the coordinator via the declared notification mechanism, idempotency-keyed so a re-tick does not double-fire, with no PHP TimedJob

### Requirement: Append-on-version with immutable prior versions
LearningPlan MUST be effectively append-on-version: a material change creates a new version requiring re-sign; prior versions and their signatures are immutable (`appendOnly: true` on the version records, or an OR versioning mechanism if available).

#### Scenario: New version supersedes immutable prior version
- **GIVEN** an active LearningPlan version with recorded signatures
- **WHEN** a material change is made and the new version is co-signed
- **THEN** a new version is created and activated while the prior version and its signatures remain immutable

### Requirement: Signing assurance level is declarative config
Signing assurance-level capture MUST be declarative config; the actual DigiD/eIDAS handshake is an external auth concern (see `data-exchange` / openconnector), not implemented here.

#### Scenario: Assurance level captured as declarative config
- **GIVEN** a LearningPlan version requiring a DigiD-strength signature
- **WHEN** a parent co-signs through the external auth flow
- **THEN** the `Signature` records the assurance level from declarative config, and a signature below the required level does not satisfy the co-sign requirement

### Requirement: Frontend is declarative with named custom views

The frontend MUST be declarative: `src/manifest.json` pages for `SupportRequest`/`TlvApplication`/
`DeliberationRecord` index+detail, following the same `<Schema>s`/`<Schema>Detail` convention as the
existing `LearningPlans`/`LearningPlanDetail` pages. The SWV dossier review step MUST reuse the existing
`OsoDossierReviewView` custom view (`CnStructuredDocReview`) rather than introduce a new bespoke
component. There MUST be no PHP CRUD controllers.

#### Scenario: Pages are manifest-declared and reuse the existing dossier-review view

- **GIVEN** the support-chain frontend is configured
- **WHEN** the app renders `SupportRequest`/`TlvApplication`/`DeliberationRecord` screens and the SWV
  dossier review step
- **THEN** index/detail pages come from `src/manifest.json`, the dossier review step reuses the existing
  `OsoDossierReviewView`, and there are no PHP CRUD controllers

### Requirement: Persist SupportRequest, TlvApplication, and DeliberationRecord domain objects in OpenRegister

The system MUST persist `SupportRequest`, `TlvApplication`, and `DeliberationRecord` as OpenRegister
objects with `x-openregister-lifecycle` (`SupportRequest`: draft → submitted → routed-to-swv →
in-deliberation → decided → closed; `TlvApplication`: draft → submitted → under-review → decided
(approved | rejected | conditional) → expired; `DeliberationRecord`: `appendOnly: true`, scheduled →
recorded), `x-openregister-relations` (`SupportRequest`↔learner/optional-`LearningPlan`/optional-
`GroupPlanSubgroup`, `TlvApplication`↔`SupportRequest`, `DeliberationRecord`↔`SupportRequest`/
`TlvApplication`), `x-openregister-calculations` (`TlvApplication.tlvExpiringSoon`), and
`x-openregister-notifications` (`supportRequestRouted`, `tlvDecisionReceived`, `tlvExpiringSoon`,
idempotency-keyed). `DeliberationRecord` MUST be `appendOnly: true` for audit (ADR-008), matching the
existing `LearningPlanEvaluation`/`Signature` pattern. `SupportRequest` MUST additionally carry a nullable
`originGroupPlanSubgroupId` ($ref `GroupPlanSubgroup`), alongside the existing nullable `learningPlanId`,
recording that this zorgvraag was raised because a group-level differentiated approach proved insufficient
for this learner — nullable and independent of `learningPlanId`, since a request may originate from a
`GroupPlanSubgroup`, from an existing `LearningPlan`, from both, or from neither.

#### Scenario: Support-chain objects persisted in OpenRegister

- **GIVEN** the learning-plan domain schemas are registered
- **WHEN** a coordinator creates a `SupportRequest`, a `TlvApplication`, and records a `DeliberationRecord`
- **THEN** all three are stored as OpenRegister objects carrying their declared lifecycle, relations,
  calculations, and notification config, and `DeliberationRecord` is `appendOnly: true`

#### Scenario: SupportRequest raised from a GroupPlanSubgroup carries its origin, independent of any LearningPlan link

<!-- @e2e exclude Reuses the existing SupportRequest submit flow; this scenario only asserts the new field is populated independently of learningPlanId, which is plain schema/relation behaviour with no new DOM interaction. -->

- **GIVEN** a learner with no `LearningPlan` but who is a member of a `GroupPlanSubgroup`
- **WHEN** a coordinator raises a `SupportRequest` for that learner from the subgroup context
- **THEN** the `SupportRequest` is created with `originGroupPlanSubgroupId` set and `learningPlanId` left
  null
- **AND** the request is not blocked by the absence of a `LearningPlan`

### Requirement: SupportRequest raised from the pupil dossier, optionally linked to a LearningPlan

A `SupportRequest` MUST be raisable directly from a pupil's dossier by a coordinator, independently of
whether a `LearningPlan` already exists for that pupil — a zorgvraag can precede the OPP that follows
from it. When a `LearningPlan` exists, the `SupportRequest` MUST link to it; when it does not, the link
field MUST be nullable and the request MUST still be creatable.

#### Scenario: SupportRequest created without a prior LearningPlan

- **GIVEN** a pupil with no existing `LearningPlan`
- **WHEN** a coordinator raises a `SupportRequest` from the pupil's dossier
- **THEN** the `SupportRequest` is created with its `LearningPlan` link left null, and it is not blocked
  by the absence of a plan

#### Scenario: SupportRequest linked to an existing LearningPlan

- **GIVEN** a pupil with an active `LearningPlan`
- **WHEN** a coordinator raises a `SupportRequest` for that pupil
- **THEN** the `SupportRequest` links to the existing `LearningPlan` so its goals/support measures are
  visible as context to the SWV

### Requirement: SWV routing reuses DataExchangeJob and the existing pending-parent-review gate

Submitting a `SupportRequest` MUST auto-queue a `data-exchange` `DataExchangeJob` with `target: swv` and
`scope.schema: support-request`, composing the OSO-format care-request dossier from the `SupportRequest`
plus the linked `LearnerProfile` (and `LearningPlan`, when present) using the same dossier-composition
approach the existing OSO PO→VO overstapdossier uses. The job MUST enter `pending-parent-review` before
the send to the SWV proceeds, identically to the existing OSO gate — this MUST NOT be a new, parallel
lifecycle mechanism. The wire send to the SWV MUST be delegated entirely to an OpenConnector connection
(see `data-exchange` delta below); this spec MUST NOT implement any wire protocol.

#### Scenario: Submitting a SupportRequest queues a gated SWV dossier job

- **GIVEN** a `SupportRequest` in `draft` with all required fields set
- **WHEN** the coordinator submits it
- **THEN** the `SupportRequest` moves to `submitted`, a `DataExchangeJob` is auto-queued with
  `target: swv` and `scope.schema: support-request`
- **AND** the job enters `pending-parent-review` before any send to the SWV proceeds

#### Scenario: SupportRequest tracks the routed job through to decision

- **GIVEN** a `DataExchangeJob` for a `SupportRequest` has succeeded
- **WHEN** the SWV's dossier has been delivered
- **THEN** the `SupportRequest` moves to `routed-to-swv`, and subsequent `DeliberationRecord`s and the
  resulting `TlvApplication` reference this `SupportRequest`

### Requirement: TLV decision and validity period are recorded, not adjudicated

`TlvApplication` MUST record the SWV's `decision` (`approved | rejected | conditional`), the requested
arrangement type, the SWV's own case reference, and — once decided — a `validFrom`/`validUntil` validity
period plus a `decisionDocumentRef` OR file attachment. The system MUST NOT implement any logic that
auto-approves, auto-rejects, or predicts the SWV's decision — the SWV is the sole deciding authority; this
spec only records the externally-issued outcome.

#### Scenario: TLV decision recorded with validity period

- **GIVEN** a `TlvApplication` in `under-review`
- **WHEN** the coordinator records the SWV's decision as `approved` with a `validFrom`/`validUntil` and
  the decision document
- **THEN** the `TlvApplication` moves to `decided`, the validity period and document reference are
  persisted, and no automated approval/rejection logic ran

### Requirement: TLV expiry is a declared calculation trigger, not a PHP TimedJob

`TlvApplication.tlvExpiringSoon` MUST be a declared `x-openregister-calculations` expression evaluated
against `validUntil`, reusing the same declared-calculation-trigger pattern as `attendance`'s threshold
crossings and `certification`'s renewal reminders (ADR-022) — NOT a PHP TimedJob.

#### Scenario: Approaching TLV expiry fires a declared notification

- **GIVEN** a `TlvApplication` decided `approved` with a `validUntil` date
- **WHEN** the calculated `tlvExpiringSoon` window is entered
- **THEN** a `tlvExpiringSoon` notification fires to the coordinator via the declared notification
  mechanism, idempotency-keyed, with no PHP TimedJob involved

### Requirement: Deliberation records are structured and append-only

`DeliberationRecord` MUST capture role-tagged `attendees` (at minimum: parent, pupil, municipality,
care-partner, school, swv-coordinator), a `scheduledAt`/`recordedAt`, an `outcome`/recommendation, and a
link to the `SupportRequest`/`TlvApplication` it concerns. Once `recorded`, a `DeliberationRecord` MUST be
immutable (`appendOnly: true`) — a correction requires a new record referencing the one it supersedes,
mirroring `LearningPlanEvaluation`'s append-only pattern.

#### Scenario: Deliberation round recorded as immutable

- **GIVEN** a consultation round with parents, the municipality, and a care partner
- **WHEN** the coordinator records the `DeliberationRecord` with attendees and an outcome
- **THEN** the record is persisted `appendOnly: true`, and any later correction creates a new record
  referencing the original rather than mutating it

### Requirement: The pupil's own voice (hoorrecht) is a first-class, non-optional field

`DeliberationRecord` MUST carry a `pupilVoice` field distinct from parent consent — `heard: boolean` plus
a `statementNote`, OR an explicit `waived: true` with a `waiverReason` for the cases where directly
hearing the pupil is not appropriate (e.g. very young children) — per the 2025 Wet versterking positie
ouders en leerlingen in passend onderwijs (insight 1145). A `DeliberationRecord` MUST NOT reach `recorded`
unless `pupilVoice.heard` is true or `pupilVoice.waived` carries a non-empty reason — enforced by a
lifecycle guard, mirroring how `LearningPlanSignatureGuard` blocks `LearningPlan.activate` without the
required signatures.

#### Scenario: Deliberation blocked from recording without pupil voice or a waiver

- **GIVEN** a `DeliberationRecord` being finalised with `pupilVoice.heard: false` and no `waived`/
  `waiverReason` set
- **WHEN** the coordinator attempts to transition it to `recorded`
- **THEN** the transition is blocked by the lifecycle guard until `pupilVoice.heard` is set true or a
  `waiverReason` is supplied

#### Scenario: Deliberation records the pupil's own statement independently of parent consent

- **GIVEN** a `DeliberationRecord` for a pupil old enough to be heard directly
- **WHEN** the pupil is consulted separately from their parents
- **THEN** `pupilVoice.heard` is set true with the pupil's own `statementNote`, distinct from any parent
  `Signature`/consent recorded elsewhere on the chain

### Requirement: Minimal disclosure to the SWV via a field-whitelisting DataMappingProfile

The `DataMappingProfile` used for `target: swv` MUST whitelist only the fields the OSO care-request
dossier schema requires; the full `LearnerProfile`/`LearningPlan`/`SupportRequest` objects MUST NOT be
handed to OpenConnector wholesale. This mirrors the existing `data-exchange` `DataMappingProfile` pattern
and reflects that zorg (care/support) data is the most sensitive category this application handles.

#### Scenario: SWV dossier composition drops non-whitelisted fields

- **GIVEN** a `SupportRequest`'s `DataExchangeJob` for `target: swv` is composed
- **WHEN** the dossier payload is built from `LearnerProfile`/`LearningPlan`/`SupportRequest` data
- **THEN** only fields present in the `swv` `DataMappingProfile` whitelist appear in the payload — no
  field outside that whitelist reaches OpenConnector

### Requirement: Persist GroupPlan, GroupPlanSubgroup, and GroupPlanEvaluation domain objects in OpenRegister

The system MUST persist `GroupPlan`, `GroupPlanSubgroup`, and `GroupPlanEvaluation` as OpenRegister objects.
`GroupPlan` MUST carry `x-openregister-lifecycle` (`draft → active → under-evaluation → closed |
superseded`, identical to the existing `LearningPlan` lifecycle), `x-openregister-relations`
(`GroupPlan`↔`Cohort`, `GroupPlanSubgroup`↔`GroupPlan`, `GroupPlanEvaluation`↔`GroupPlan`), and
`x-openregister-calculations` (`periodEndDue`, mirroring `LearningPlan.nextReviewDue`'s pattern). A
`periodEndReminder` notification MUST be declared via `x-openregister-notifications`, idempotency-keyed,
firing off `GroupPlan.periodEndDate` — reusing the same declared-notification/calculation-trigger machinery
as `LearningPlan.quarterlyReviewReminder`, NOT a PHP `TimedJob` (ADR-022).

#### Scenario: GroupPlan objects persisted with the same lifecycle shape as LearningPlan

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface exercises schema registration itself — verified by reasoning over the register JSON plus PHPUnit schema-validation coverage at apply time. -->

- **GIVEN** the `learning-plan` domain schemas are registered
- **WHEN** a coordinator saves a `GroupPlan` with its subgroups and evaluations
- **THEN** `GroupPlan`, `GroupPlanSubgroup`, and `GroupPlanEvaluation` are stored as OpenRegister objects
  carrying the declared lifecycle, relations, and calculation config
- **AND** `GroupPlan.lifecycle` starts `draft` and follows `draft → active → under-evaluation → closed |
  superseded`

#### Scenario: Period-end reminder is a declared notification, not a TimedJob

<!-- @e2e exclude Declared-notification trigger behaviour is backend/lifecycle logic with no DOM surface; verified by PHPUnit at apply time, mirroring the existing quarterlyReviewReminder coverage. -->

- **GIVEN** an active `GroupPlan` with a `periodEndDate` set
- **WHEN** `periodEndDate` arrives
- **THEN** a `periodEndReminder` notification fires to the `coordinatorId` via the declared notification
  mechanism, idempotency-keyed so a re-tick does not double-fire, with no PHP `TimedJob` involved

### Requirement: GroupPlan's results analysis references existing grade/assessment evidence, not a duplicate results schema

`GroupPlan.resultsAnalysis` MUST be a narrative plus `evidenceRefs` — an array of UUIDs of existing
evidence objects (`GradeEntry`, `AssessmentResult`, `FinalGrade`, or a prior `GroupPlanEvaluation`) the
analysis is based on, following the exact pattern already established by
`LearningPlan.goals[].evidenceRefs`. The system MUST NOT introduce a Cito/LVS-specific results schema in
this change: no such schema exists anywhere in the register at the time of this change, and importing
actual Cito/LVS toets scores is out of scope here — it is a follow-up `data-exchange`/`openconnector`
connector that would land scores as ordinary `GradeEntry` rows (`sourceKind`), reusable by this same
`evidenceRefs` mechanism without any schema change to `GroupPlan`.

#### Scenario: Results analysis references existing GradeEntry/AssessmentResult evidence

<!-- @e2e exclude Reference-array persistence with no cross-schema validation logic beyond the existing evidenceRefs pattern; no new DOM surface beyond the standard object-list/data widgets already covered by the frontend requirement below. -->

- **GIVEN** a `Cohort` with published `GradeEntry`s and `AssessmentResult`s for its learners
- **WHEN** a teacher creates a `GroupPlan` for that cohort and records a `resultsAnalysis`
- **THEN** the `evidenceRefs` array stores UUIDs of the referenced `GradeEntry`/`AssessmentResult`/
  `FinalGrade` objects
- **AND** no new results/toets schema is created — the existing objects are referenced, not re-entered

#### Scenario: No Cito/LVS import exists yet — analysis degrades to the generic evidence reference, not a blocked create

<!-- @e2e exclude Absence-of-a-feature assertion with no behaviour to drive through the DOM; verified by the repo-wide grep recorded in proposal.md "Why" and re-checked at apply time. -->

- **GIVEN** a school whose Cito/LVS toets results have not been imported into Scholiq (no import path
  exists at the time of this change)
- **WHEN** a teacher creates a `GroupPlan`'s `resultsAnalysis`
- **THEN** the analysis MAY still be recorded, referencing whatever `GradeEntry`/`AssessmentResult`
  evidence already exists, or as narrative-only with an empty `evidenceRefs`
- **AND** the `GroupPlan` creation is NOT blocked on a Cito/LVS import that does not exist

### Requirement: GroupPlanSubgroup differentiates instructieniveau and links to, without duplicating, LearningPlan and SupportRequest

`GroupPlanSubgroup` MUST carry `learnerIds` (array of Nextcloud user IDs, same convention as
`Cohort.learnerIds`), `instructieniveau` (`intensief | basis | verdiept | custom`), a `differentiatedGoal`,
an `approach`, and an `intendedOutcome`. The system MUST NOT store a denormalised link from
`GroupPlanSubgroup` to a learner's `LearningPlan` or `SupportRequest` — a learner already carries both via
their own `learnerId`; `GroupPlanSubgroup` MUST instead be resolvable to those objects at read time via a
learner-ID lookup (see the frontend requirement below for the one named view this requires, since the
manifest's equality-only filter DSL cannot express an array-membership match against `learnerIds`).

#### Scenario: A subgroup member's existing LearningPlan is surfaced without a duplicate field

<!-- @e2e tests/e2e/spec-coverage/groepsplan.spec.ts -->

- **GIVEN** a `GroupPlanSubgroup` with `instructieniveau: intensief` whose `learnerIds` includes a learner
  who already has an `active` `LearningPlan`
- **WHEN** a coordinator opens the `GroupPlanSubgroup` detail page
- **THEN** that learner's active `LearningPlan` is shown as context
- **AND** `GroupPlanSubgroup`'s own schema carries no `learningPlanId`/`learningPlanIds` field — the link is
  resolved, not stored

#### Scenario: Insufficient group-level differentiation escalates to a SupportRequest, linked back to its origin

<!-- @e2e exclude Reuses the existing SupportRequest submit flow (already covered by the wave-1 zorgvraag-chain change); this scenario only asserts the new originGroupPlanSubgroupId field is populated, which is plain object-list-filter behaviour with no new DOM interaction. -->

- **GIVEN** a learner in a `GroupPlanSubgroup` for whom the subgroup's differentiated approach is judged
  insufficient
- **WHEN** a coordinator raises a `SupportRequest` for that learner from the subgroup context
- **THEN** the `SupportRequest` is created with `originGroupPlanSubgroupId` set to that `GroupPlanSubgroup`
- **AND** the `GroupPlanSubgroup` detail page can list SupportRequests raised from it via a standard
  equality-filtered object-list widget (`originGroupPlanSubgroupId: @objectId`) — no schema change to
  `GroupPlanSubgroup` itself

### Requirement: GroupPlanEvaluation closes the HGW cycle and the next period's plan supersedes the prior one

`GroupPlanEvaluation` MUST record, at period end, a per-subgroup `outcomes` array (`subgroupId`, `outcome`:
`met | partially-met | not-met`, narrative) plus an overall narrative, `evaluatedBy`, and `evaluatedAt`.
Starting the next period's `GroupPlan` MUST reuse `GroupPlan.supersedesId` (the same version-chain field
`LearningPlan` already has) to link forward from the prior period's plan — the system MUST NOT introduce a
second, forward-pointing "seeds next plan" field on `GroupPlanEvaluation`; the new period's `GroupPlan`
typically references the prior plan's final `GroupPlanEvaluation` in its own `resultsAnalysis.evidenceRefs`
instead.

#### Scenario: Evaluating a GroupPlan records a per-subgroup outcome

<!-- @e2e exclude Pure object-create/lifecycle-transition behaviour, no new DOM interaction beyond the standard object-list/data widgets already exercised by the frontend requirement's e2e scenario. -->

- **GIVEN** an active `GroupPlan` with two `GroupPlanSubgroup`s, moved to `under-evaluation`
- **WHEN** a coordinator records a `GroupPlanEvaluation` with an `outcome` for each subgroup
- **THEN** the `GroupPlanEvaluation` persists the per-subgroup outcomes and overall narrative
- **AND** the `GroupPlan` can transition to `closed`

#### Scenario: A new period's GroupPlan supersedes the prior one and can cite its evaluation as evidence

<!-- @e2e exclude Version-chain assertion identical in shape to LearningPlan.supersedesId, already the established pattern; no new DOM surface. -->

- **GIVEN** a `closed` `GroupPlan` for a cohort/subject/period with a recorded `GroupPlanEvaluation`
- **WHEN** a coordinator creates the next period's `GroupPlan` for the same cohort/subject
- **THEN** the new `GroupPlan.supersedesId` references the prior plan
- **AND** the new plan's `resultsAnalysis.evidenceRefs` MAY include the prior plan's
  `GroupPlanEvaluation` UUID as analysis evidence

### Requirement: GroupPlan frontend is declarative with one named custom view for the cross-schema learner lookup

Frontend MUST be declarative: `src/manifest.json` index+detail pages for `GroupPlan`/`GroupPlanSubgroup`/
`GroupPlanEvaluation`, following the same page-and-sub-object-list-widget convention already used by
`LearningPlan`/`LearningPlanEvaluation`/`Signature`. Exactly one named custom view,
`GroupPlanSubgroupLearnerContext.vue`, MUST render on the `GroupPlanSubgroup` detail page to resolve each
member learner's active `LearningPlan` (if any) — the one lookup the manifest's equality-only `object-list`
filter DSL cannot express, since `learnerIds` is a multi-value array and no filter in `src/manifest.json`
supports array-membership matching. There MUST be no PHP CRUD controller.

#### Scenario: Pages are manifest-declared; the one array-membership lookup uses a named custom view

<!-- @e2e tests/e2e/spec-coverage/groepsplan.spec.ts -->

- **GIVEN** the `learning-plan` frontend is configured
- **WHEN** the app renders `GroupPlan`/`GroupPlanSubgroup`/`GroupPlanEvaluation` index and detail screens
- **THEN** index/detail pages come from `src/manifest.json`
- **AND** the `GroupPlanSubgroup` detail page's learner-context panel is the one named custom view,
  `GroupPlanSubgroupLearnerContext.vue`
- **AND** there is no PHP CRUD controller for any of the three new schemas

## Standards

Schema.org `EducationalOccupationalProgram` (loosely) — there is no clean schema.org type, so the canonical form is OpenRegister-native; NL Wet Passend Onderwijs / OPP sector templates as a `LearningPlan` profile; eIDAS / DigiD assurance levels for the signing strength.

## Data Model

All in OpenRegister. New: `LearningPlan`, `LearningPlanEvaluation`, `Signature`, `LearningPlanTemplate`. Consumes: `AssessmentResult`, `GradeEntry`, `AttendanceRecord` (as evidence). No PHP service classes — fully declarative; the only seam is whatever the DigiD signing flow needs, which lives outside scholiq. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The DigiD / eIDAS authentication handshake itself (openconnector / NC auth — see `data-exchange`).
- Sector-wide OPP analytics (launchpad).
- Auto-generation of goals from assessment results (would be an `AiFeature` registration).
- The samenwerkingsverband (SWV) zorgvraag/deliberation/TLV chain itself is now IN scope — see `SupportRequest`,
  `TlvApplication`, `DeliberationRecord` above (`openspec/changes/zorgvraag-swv-tlv-chain`). Still out of scope:
  the SWV's own funding/bekostiging administration for an issued arrangement (Scholiq records the TLV decision,
  it does not administer the resulting funding flow), any TLV adjudication/decision-support logic (the SWV is
  the sole deciding authority — see design.md "TLV decision recorded, never adjudicated"), and a
  `portal-contribution` provider surfacing this chain to parents/pupils (deferred to a follow-up).
