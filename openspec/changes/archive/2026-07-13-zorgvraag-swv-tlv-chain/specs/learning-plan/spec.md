# Learning Plan — Zorgvraag → SWV → TLV Support Chain Delta

**Spec refs**: `learning-plan`, `data-exchange`, ADR-008 (immutable audit trail), ADR-022 (apps consume
OR abstractions), ADR-031 (declarative notifications)

## MODIFIED Requirements

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

## ADDED Requirements

### Requirement: Persist SupportRequest, TlvApplication, and DeliberationRecord domain objects in OpenRegister

The system MUST persist `SupportRequest`, `TlvApplication`, and `DeliberationRecord` as OpenRegister
objects with `x-openregister-lifecycle` (`SupportRequest`: draft → submitted → routed-to-swv →
in-deliberation → decided → closed; `TlvApplication`: draft → submitted → under-review → decided
(approved | rejected | conditional) → expired; `DeliberationRecord`: `appendOnly: true`, scheduled →
recorded), `x-openregister-relations` (`SupportRequest`↔learner/optional-`LearningPlan`,
`TlvApplication`↔`SupportRequest`, `DeliberationRecord`↔`SupportRequest`/`TlvApplication`),
`x-openregister-calculations` (`TlvApplication.tlvExpiringSoon`), and `x-openregister-notifications`
(`supportRequestRouted`, `tlvDecisionReceived`, `tlvExpiringSoon`, idempotency-keyed). `DeliberationRecord`
MUST be `appendOnly: true` for audit (ADR-008), matching the existing `LearningPlanEvaluation`/`Signature`
pattern.

#### Scenario: Support-chain objects persisted in OpenRegister

- **GIVEN** the learning-plan domain schemas are registered
- **WHEN** a coordinator creates a `SupportRequest`, a `TlvApplication`, and records a `DeliberationRecord`
- **THEN** all three are stored as OpenRegister objects carrying their declared lifecycle, relations,
  calculations, and notification config, and `DeliberationRecord` is `appendOnly: true`

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
