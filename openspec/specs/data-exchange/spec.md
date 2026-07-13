---
slug: data-exchange
title: Data Exchange — Export/Import Jobs to External Registries
status: done
feature_tier: should
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [bron-rod-duo, oso-po-vo, leerplicht-digikoppeling, surfconext-attributes, hr-system]
replaces_thin_slice_of: [bron-rod-exchange, oso-transfer, identity-federation]
---

# Data Exchange

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, OpenConnector delegation, and audit-trail emission — no `#### Scenario:` headings exist in this spec.

## Purpose

An institution's data has to flow to and from external systems: a Dutch school's `leveringsverplichting` to **DUO BRON/ROD**, a pupil's **OSO** transfer dossier PO→VO, a `leerplichtmelding` to the municipality over **Digikoppeling**, **SURFconext** attribute mapping for HE login, a corporate **HR-system** sync for who must do which mandatory training. These are real and non-negotiable for the relevant buyers — but they are **integration adapters**, not Scholiq schemas. Scholiq's job is to (a) expose its data (`LearnerProfile`, `Enrolment`, `GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Credential`, `Attestation`…) in a mappable form, (b) hold a small `DataExchangeJob` queue so a user can *request* an export/import and watch it, and (c) record every exchange in the audit trail (ADR-008). The actual wire protocols (Edukoppeling, StUF, OSO XML, OOAPI, OAuth/SAML attribute release) live in **OpenConnector** source/target configurations — separate issues filed against `ConductionNL/openconnector`. Federated *authentication* (DigiD / SURFconext / eduID) is likewise an OpenConnector + Nextcloud-auth concern: Scholiq only stores the resulting pseudonymous identifiers, which `LearnerProfile` already carries (`eckId`, `schoolId`, `bsnEncrypted`).

## What

- **DataExchangeJob** — a request: `direction` (`export` | `import` | `sync`), `target` (a named OpenConnector connection — `bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`), `scope` (which objects / which cohort / which period), `format` (resolved by the target), `lifecycle` (`queued → running → succeeded | failed | partial`), `result` (counts, validation report, the produced artefact reference), `requestedBy`, timestamps. The job *delegates* to OpenConnector — it does not implement the protocol.
- **DataMappingProfile** — declares how a Scholiq schema maps to a target schema (`{ scholiqField → targetField, transform }[]`) for a given `target`. The Dutch BRON/ROD, OSO, and leerplicht mappings are profiles shipped (or downloadable) with the app; an HR mapping is configured by the admin. Validation runs against the target's schema before the job leaves the queue.
- **The OSO dossier composer** — for `target=oso`, the job assembles the transfer dossier from existing `LearnerProfile` + `GradeEntry` + `AttendanceRecord` + `LearningPlan` data, presents it for parent review (a parent must approve before it's sent — `lifecycle` gains a `pending-parent-review` state), then hands the approved XML to OpenConnector for the Edukoppeling send. The receiving VO LAS imports it as a `DataExchangeJob` with `direction=import`.
- Audit: every `DataExchangeJob` state transition emits an OR audit-trail entry; the job's produced artefact (the XML, the report) is retained as an OR file attachment for the legally-required period.

## User Stories

- As a school administrator, I want to send the term's pupil + enrolment data to BRON, see the validation report, and know the `leveringsverplichting` is met — without leaving Scholiq.
- As a PO mentor at the sending school, I want to compose a pupil's OSO dossier from existing data, have the parent review and approve it, and then transfer it to the receiving VO school.
- As a VO mentor at the receiving school, I want to import an incoming OSO dossier into the pupil's LearnerProfile with one click.
- As an attendance coordinator, I want a crossed leerplicht threshold to queue a `leerplichtmelding` to the municipality (Digikoppeling) and show me whether it was accepted.
- As a corporate L&D admin, I want a nightly HR-system sync that creates/retires `LearnerProfile`s and sets who's in which mandatory-training audience.

## Acceptance Criteria

- GIVEN a `DataExchangeJob` `{direction:export, target:bron-rod, scope:<cohort>}`, WHEN it runs, THEN Scholiq builds the payload from the `DataMappingProfile`, hands it to the OpenConnector `bron-rod` connection, and the job lifecycle becomes `succeeded` (or `partial` with a per-record validation report) — Scholiq itself implements no Edukoppeling/StUF wire code.
- GIVEN an OSO export job, WHEN the dossier is composed, THEN the job sits in `pending-parent-review` until the parent approves; only then does it move to `running` and the send proceed.
- GIVEN an incoming OSO dossier, WHEN a VO mentor imports it, THEN the matching `LearnerProfile` is updated (or created) and an audit entry records the import source.
- GIVEN an `AttendanceThreshold` with `onCross` targeting `leerplicht`, WHEN a flag is created (see `attendance`), THEN a `DataExchangeJob` is auto-queued to the `leerplicht` target and the flag's lifecycle tracks it.
- GIVEN any `DataExchangeJob`, WHEN it changes state, THEN an OR audit-trail entry is emitted and the produced artefact is attached to the job for retention.

## Requirements

### Requirement: Persist DataExchangeJob and DataMappingProfile in OpenRegister
The system MUST persist `DataExchangeJob`, `DataMappingProfile` as OpenRegister objects with `x-openregister-lifecycle` (queued → running → succeeded | failed | partial; OSO adds pending-parent-review), `x-openregister-relations`, `x-openregister-notifications` (job-done alert), and audit-trail emission on every transition (ADR-008). `DataExchangeJob` artefacts MUST be OR file attachments.

#### Scenario: Persist a job and emit audit on transition
- **GIVEN** a `DataExchangeJob` request
- **WHEN** the job is created and changes lifecycle state
- **THEN** the system persists it as an OpenRegister object, emits an audit-trail entry on every transition, and attaches the produced artefact as an OR file attachment

### Requirement: Delegate wire protocols to OpenConnector
Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, or SAML/OAuth attribute-release wire
protocols. Those MUST be OpenConnector source/target configurations referenced by the `target` field,
including a `ooapi-catalog` target used by `course-management`'s catalog-publication contract to sync
published `Course`/`Programme`/`Cohort` data to the OOAPI 5.0 endpoint hosted by opencatalogi. (File the
OpenConnector adapter issues: BRON/ROD, OSO PO→VO, leerplicht-Digikoppeling, SURFconext attributes, generic
HR, OOAPI catalog.)

#### Scenario: Delegate the wire send to OpenConnector
- **GIVEN** a `DataExchangeJob` with a `target` referencing an OpenConnector connection
- **WHEN** the job runs
- **THEN** Scholiq hands the payload to the OpenConnector source/target configuration and implements no wire protocol itself

#### Scenario: Course-management catalog publication delegates through the same DataExchangeJob mechanism

- **GIVEN** a `Course` or `Programme` transitions to `published` or `archived`
- **WHEN** `course-management`'s catalog-publication contract queues the catalog sync
- **THEN** it does so as a `DataExchangeJob` with `target: ooapi-catalog`
- **AND** Scholiq implements no OOAPI wire protocol itself — the OpenConnector `ooapi-catalog` adapter and
  opencatalogi's public OOAPI 5.0 endpoint handle the wire send and public exposure

### Requirement: Federated authentication is out of scope
Federated authentication (DigiD / SURFconext / eduID) is OUT of this spec — it MUST be handled by a Nextcloud-auth-provider + OpenConnector; Scholiq only persists the pseudonymous identifiers on `LearnerProfile` (already does).

#### Scenario: Store only pseudonymous identifiers
- **GIVEN** a learner authenticated via DigiD / SURFconext / eduID
- **WHEN** the authentication completes outside this spec's scope
- **THEN** Scholiq persists only the resulting pseudonymous identifiers on `LearnerProfile` and does not implement the federated authentication itself

### Requirement: OSO parent-review is a lifecycle gate
The OSO parent-review gate MUST be a lifecycle state; the dossier MUST NOT leave the queue until parent approval is recorded.

#### Scenario: Hold an OSO dossier until parent approval
- **GIVEN** an OSO export job whose dossier has been composed
- **WHEN** the job awaits review
- **THEN** the job stays in `pending-parent-review` and does not leave the queue until parent approval is recorded

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for DataExchangeJob/DataMappingProfile index+detail; a custom `RequestExportModal` and `OsoDossierReviewView` Vue component. There MUST be no PHP CRUD controllers; the job execution is an OR-event-driven handler that calls OpenConnector (an ADR-031 "external-system bridge" exception, single method).

#### Scenario: Render the surface declaratively with named views
- **GIVEN** the data-exchange frontend
- **WHEN** it renders the DataExchangeJob/DataMappingProfile index and detail surfaces
- **THEN** it is driven by `src/manifest.json` with the custom `RequestExportModal` and `OsoDossierReviewView` views and ships no PHP CRUD controllers

## Standards

Edukoppeling / Digikoppeling / StUF (NL gov messaging — implemented in OpenConnector); OSO standard (Edu-K); DUO BRON/ROD schemas; OOAPI 5.0 (HE); SAML 2.0 / OIDC for SURFconext attribute release; SCIM for HR-system sync; eIDAS / DigiD for federated auth (out of scope here).

## Data Model

All in OpenRegister. New: `DataExchangeJob`, `DataMappingProfile`. Consumes: every Scholiq schema as a source. Delegates to: OpenConnector connections (configured separately). One ADR-031 PHP exception: the job-execution handler that invokes OpenConnector. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The OpenConnector adapters themselves (separate issues on `ConductionNL/openconnector`).
- Federated authentication (DigiD / SURFconext / eduID — NC auth + OpenConnector).
- Real-time webhook ingestion from external registries (jobs are pull/push on demand or on a schedule; streaming is a follow-up).
- Cross-tenant / SIVON federation of the data itself.
