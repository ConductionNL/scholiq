---
status: draft
---

# AVG Verwerkingsregister (Record of Processing Activities)

## Purpose

Backs the App Store claim "Immutable audit trail backs … AVG record-of-processing" with a real AVG Art. 30 record of processing activities: a `ProcessingActivity` OpenRegister schema seeded with Scholiq's own processing, owned and activated by the school's privacy officer, audit-trail-versioned, cyclically reviewed, and exportable for the supervisory authority and the compliance audit pack.

## ADDED Requirements

### Requirement: ProcessingActivity objects MUST carry the AVG Art. 30(1) mandatory elements

The system MUST persist `ProcessingActivity` objects in OpenRegister with: name, purposes, role (`controller` | `processor`), categories of data subjects, categories of personal data, a `specialCategories` flag with an Art. 9 `specialCategoriesBasis` when set, `legalBasis` (enum: `consent` | `contract` | `legal-obligation` | `vital-interests` | `public-task` | `legitimate-interest`), recipients, third-country transfers with safeguards, retention period, and security measures (TOMs). Each activity MUST additionally carry `linkedSchemas[]`, `ownerUserId`, `reviewIntervalMonths`, `nextReviewAt`, `dpiaRequired`/`dpiaReference`, `tenant_id`, and an `x-openregister-lifecycle` of `draft → active → retired`.

#### Scenario: Privacy officer creates a processing activity

- **GIVEN** a user in the `privacy-officer` group
- **WHEN** they create a ProcessingActivity "Leerlingadministratie" with `legalBasis: legal-obligation`, data subjects "leerlingen", personal-data categories including "BSN (encrypted)" and retention "tot 2 jaar na uitschrijving"
- **THEN** the object persists in OpenRegister in lifecycle `draft`
- **AND** all Art. 30(1) fields are present on the stored object

#### Scenario: Special-category data requires an Art. 9 basis

- **GIVEN** a ProcessingActivity being saved with `specialCategories: true` (e.g. health data in verzuim/excuse processing)
- **WHEN** `specialCategoriesBasis` is empty
- **THEN** schema validation MUST reject the save with a message naming the missing Art. 9 basis

#### Scenario: Legitimate interest requires an assessment

- **GIVEN** a ProcessingActivity being saved with `legalBasis: legitimate-interest`
- **WHEN** `legitimateInterestAssessment` is empty
- **THEN** schema validation MUST reject the save

### Requirement: Scholiq MUST ship a seed catalogue of its own processing activities as drafts

The register import MUST seed ProcessingActivity drafts describing Scholiq's own processing — at minimum: learner administration (LearnerProfile incl. `bsnEncrypted`, `eckId`, `schoolId`), attendance and leerplicht reporting, grading and assessment, compliance training and signed attestations (incl. `actorIp`), credentialing, data exchange (DUO/OSO/municipality/HR), and AI features. Seeds MUST be `draft`; activation MUST be an explicit transition by an authorised user. Seeds MUST reference covered schemas via `linkedSchemas[]` and MUST NOT copy any personal-data values.

#### Scenario: Fresh install seeds the register

- **GIVEN** a fresh Scholiq install completing its register import
- **WHEN** the privacy officer opens the verwerkingsregister index
- **THEN** the seeded draft entries are listed, including "Leerlingadministratie" with `linkedSchemas` containing `LearnerProfile` and personal-data categories naming BSN (encrypted), ECK iD, and SchoolID
- **AND** no seeded entry is in lifecycle `active`

#### Scenario: Activation is an explicit decision

- **GIVEN** a seeded draft entry
- **WHEN** a privacy officer reviews and triggers the `activate` transition
- **THEN** the entry moves to `active`
- **AND** an OR audit-trail entry records who activated it and when

### Requirement: Mutations of active entries MUST be audit-trail-backed with retrievable history

Every mutation of an `active` ProcessingActivity MUST emit an OpenRegister audit-trail entry (ADR-008 consumption — no app-local history store), and prior versions MUST remain retrievable.

#### Scenario: Editing an active entry leaves a trail

- **GIVEN** an `active` ProcessingActivity with retention "5 jaar"
- **WHEN** an authorised user changes retention to "7 jaar"
- **THEN** an OR audit-trail entry records the before/after values, actor, and timestamp
- **AND** the previous version of the object is retrievable

### Requirement: The register MUST be exportable per AVG Art. 30(4) and included in the audit pack

The system MUST export the verwerkingsregister (all `active` entries, full Art. 30(1) column set) as CSV/JSON on demand, and the compliance audit-pack ZIP MUST include `verwerkingsregister.csv`. Styled PDF rendering is delegated to DocuDesk and out of scope.

#### Scenario: Supervisory-authority export on demand

- **GIVEN** at least one `active` ProcessingActivity
- **WHEN** a privacy officer triggers the Art. 30 export from the index page
- **THEN** a CSV is produced containing every active entry with the Art. 30(1) columns
- **AND** an OR audit-trail entry records the export

#### Scenario: Audit pack includes the register

- **GIVEN** a compliance audit-pack export for any regulation and date range
- **WHEN** the ZIP is produced
- **THEN** it contains `verwerkingsregister.csv`

### Requirement: Review reminders MUST use the verified notification dialect

A single `scheduled` notification rule on ProcessingActivity MUST notify `ownerUserId` when `nextReviewAt` falls within the review window, declared exclusively in the verified engine dialect (`trigger.type` / `channels[]` / `recipients[]` / inline `subject{nl,en}`) as established by the `scholiq-notifications` migration. No legacy-dialect keys are permitted.

#### Scenario: Owner is reminded of a due review

- **GIVEN** an `active` ProcessingActivity whose `nextReviewAt` is within 30 days
- **WHEN** the daily scheduled rule evaluates
- **THEN** the owner receives a Nextcloud notification with an nl/en subject
- **AND** the rule block in `scholiq_register.json` contains only verified dialect keys

### Requirement: UI MUST be declarative and access OR-delegated

ProcessingActivity index and detail MUST be `src/manifest.json` pages over the OpenRegister objects API (no PHP CRUD controllers); write access MUST be restricted to the `privacy-officer` and `admin` groups via OR-delegated RBAC.

#### Scenario: Non-privileged user cannot edit the register

- **GIVEN** an authenticated user in neither `privacy-officer` nor `admin`
- **WHEN** they attempt to update a ProcessingActivity via the objects API
- **THEN** the request is rejected by OpenRegister's RBAC
