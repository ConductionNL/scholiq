# bpv Specification

## Purpose
TBD - created by archiving change bpv-praktijkovereenkomst. Update Purpose after archive.
## Requirements
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

