---
slug: data-exchange
title: Data Exchange — Export/Import Jobs to External Registries
status: proposed
feature_tier: should
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-20
updated: 2026-05-20
profiles: [bron-rod-duo, oso-po-vo, leerplicht-digikoppeling, surfconext-attributes, hr-system]
---

# Data Exchange

## Why

Institutions must exchange data with Dutch government systems (DUO BRON/ROD, OSO, Digikoppeling leerplicht), HE federation (SURFconext), and HR systems. These are integration adapters, not Scholiq schemas. Scholiq exposes its data in a mappable form, holds a job queue, and records every exchange in the audit trail. Wire protocols live entirely in **OpenConnector**.

## Requirements

### REQ-DEX-001 — DataMappingProfile schema

GIVEN a Scholiq instance with the data-exchange capability enabled,
WHEN the system initialises,
THEN `DataMappingProfile` MUST be persisted as an OpenRegister object (slug `data-mapping-profile`) with fields: `name`, `target`, `direction`, `sourceSchema`, `targetSchema`, `fieldMappings[]` (each entry: `scholiqField`, `targetField`, `transform: 'bsn-to-pseudonym'|'date-iso8601'|'cohort-to-brin'|null`), `validationProfile`, `active`, `lifecycle` (draft → active → archived), `tenant_id`.
AND seed profiles for BRON/ROD, OSO, and leerplicht MUST be included.

### REQ-DEX-002 — DataExchangeJob schema

GIVEN a Scholiq instance with the data-exchange capability enabled,
WHEN the system initialises,
THEN `DataExchangeJob` MUST be persisted as an OpenRegister object (slug `data-exchange-job`) with fields: `direction`, `target`, `mappingProfileId`, `scope`, `requestedBy`, `requestedAt`, `startedAt`, `finishedAt`, `result`, `connectorRunId`, `errorMessage`, `originFlagId`, `lifecycle`, `tenant_id`.
AND lifecycle states MUST be: `queued → running → succeeded | failed | partial`.
AND for `target === 'oso'` the lifecycle MUST additionally include `pending-parent-review` between `queued` and `running`.

### REQ-DEX-003 — Audit trail on every state transition (ADR-008)

GIVEN any `DataExchangeJob`,
WHEN it changes lifecycle state,
THEN an OR audit-trail entry MUST be emitted automatically via `x-openregister-lifecycle` (no parallel audit substrate).
AND the produced artefact (`result.artefactRef`) MUST be an OR file attachment retained for legally required periods.

### REQ-DEX-004 — Job-finished notification

GIVEN a `DataExchangeJob` that transitions to `succeeded`, `failed`, or `partial`,
WHEN the terminal state is reached,
THEN a `jobFinished` notification MUST be sent to `requestedBy` via `x-openregister-notifications` (idempotency-keyed to prevent duplicates on retries).

### REQ-DEX-005 — No wire protocols in Scholiq

GIVEN a `DataExchangeJob` in `running` state,
WHEN `DataExchangeRunHandler` executes,
THEN Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, Digikoppeling, SAML/OAuth attribute-release, or SCIM wire protocols.
AND the handler MUST delegate entirely to the named OpenConnector connection via `POST /apps/openconnector/api/sources/{name}/run`.
AND if OpenConnector is unavailable, the job MUST transition to `failed` with a descriptive `errorMessage`.

### REQ-DEX-006 — BSN privacy boundary

GIVEN a `DataExchangeJob` applying the `bsn-to-pseudonym` transform,
WHEN the payload is assembled,
THEN `LearnerProfile.eckId` MUST be used as the identity pseudonym.
AND `bsnEncrypted` MUST NOT be extracted, serialised, or transmitted to OpenConnector.

### REQ-DEX-007 — OSO parent-review gate

GIVEN a `DataExchangeJob` with `target === 'oso'`,
WHEN it is created (lifecycle: `queued`),
THEN the `DataExchangeRunGuard` MUST block the direct `queued → running` transition.
AND the job MUST enter `pending-parent-review` via the `pendingParentReview` transition before execution.

GIVEN a `DataExchangeJob` in `pending-parent-review`,
WHEN a user invokes the `approveDossier` transition,
THEN `OsoDossierReviewGuard` MUST verify the approving actor is listed in `LearnerProfile.parentIds`.
AND only after successful approval MUST the job move to `running` and the OpenConnector send proceed.

### REQ-DEX-008 — Leerplicht auto-queue (attendance TODO fulfilled)

GIVEN an `AttendanceThreshold` with `onCross.dataExchangeTarget` set,
WHEN a threshold crossing creates an `AttendanceFlag`,
THEN `AttendanceFlagCreationHandler` MUST create a `DataExchangeJob` (direction: export, target: the configured value, lifecycle: queued).
AND MUST set `AttendanceFlag.dataExchangeJobId` to the new job's UUID.
AND the `_dataExchangeTargetIntent` placeholder field MUST NOT be used.

### REQ-DEX-009 — Leerplicht report guard (attendance TODO fulfilled)

GIVEN an `AttendanceFlag` in `in-handling` with a `dataExchangeJobId` set,
WHEN the `report` transition (`in-handling → reported`) is attempted,
THEN `AttendanceFlagReportGuard` MUST allow the transition only if the linked `DataExchangeJob.lifecycle === 'succeeded'`.
AND if `dataExchangeJobId` is null (manual report, no exchange target configured), the guard MUST pass unconditionally.

### REQ-DEX-010 — Federated authentication out of scope

GIVEN any authentication flow involving DigiD, SURFconext, or eduID,
WHEN a user logs in,
THEN federated authentication MUST be handled entirely by Nextcloud auth-providers + OpenConnector.
AND Scholiq MUST only store the resulting pseudonymous identifiers (`eckId`, `schoolId`, `bsnEncrypted`) on `LearnerProfile` — which it already does.

### REQ-DEX-011 — Manifest pages (ADR-024)

GIVEN a Scholiq instance,
WHEN `src/manifest.json` is validated,
THEN the manifest MUST declare index+detail page pairs for `DataMappingProfiles` / `DataMappingProfileDetail` and `DataExchangeJobs` / `DataExchangeJobDetail`.
AND MUST declare custom pages `RequestExportModal` (component=RequestExportModal) and `OsoDossierReviewView` (component=OsoDossierReviewView).
AND `node tests/validate-manifest.js` MUST pass with 0 Ajv errors.

### REQ-DEX-012 — No PHP CRUD controllers

GIVEN the data-exchange feature,
WHEN data is read or written,
THEN all data access MUST flow through OpenRegister's declarative REST API.
AND no custom PHP CRUD controllers for `DataMappingProfile` or `DataExchangeJob` MUST be written.

### REQ-DEX-013 — RequestExportModal OpenConnector delegation notice

GIVEN the RequestExportModal is displayed,
WHEN a user views the export request form,
THEN the UI MUST display a notice making clear that Scholiq delegates wire-protocol execution to the named OpenConnector connection.
AND the OSO path MUST display a note that the job enters `pending-parent-review` before executing.

### REQ-DEX-014 — OsoDossierReviewView privacy

GIVEN the OsoDossierReviewView is displayed for a `pending-parent-review` OSO job,
WHEN a parent reviews the dossier,
THEN the view MUST display the learner's ECK iD.
AND MUST NOT display or transmit `bsnEncrypted`.
AND MUST include a note that the BSN is not shown or transmitted.

### REQ-DEX-015 — Calculated fields

GIVEN any `DataExchangeJob`,
WHEN the object is read,
THEN the calculated fields `durationSeconds` (derived from `startedAt` and `finishedAt`) and `successRate` (derived from `result.recordsAccepted` / `result.recordsProcessed`) MUST be available via `x-openregister-calculations` (no custom service).

## Acceptance Criteria

- GIVEN a `DataExchangeJob` `{direction:export, target:bron-rod, scope:<cohort>}`, WHEN it runs, THEN Scholiq builds the payload from the `DataMappingProfile` (using eckId, not bsnEncrypted), hands it to the OpenConnector `bron-rod` connection, and the job lifecycle becomes `succeeded` (or `partial` with a per-record validation report). Scholiq implements no Edukoppeling/StUF wire code.
- GIVEN an OSO export job, WHEN it is created, THEN the job enters `pending-parent-review`; only after `approveDossier` by a parent (verified via `OsoDossierReviewGuard`) does it move to `running` and the OpenConnector send proceed.
- GIVEN an `AttendanceThreshold` with `onCross.dataExchangeTarget: 'leerplicht'`, WHEN a flag is created, THEN a `DataExchangeJob` is automatically queued to the `leerplicht` target and `AttendanceFlag.dataExchangeJobId` is set.
- GIVEN an `AttendanceFlag` in `in-handling` with a `dataExchangeJobId`, WHEN `report` is attempted, THEN the `AttendanceFlagReportGuard` only allows the transition if the linked `DataExchangeJob` is `succeeded`.
- GIVEN any `DataExchangeJob`, WHEN it changes state, THEN an OR audit-trail entry is emitted. On terminal state, the `jobFinished` notification is sent to `requestedBy`.

## Standards

Edukoppeling / Digikoppeling / StUF (NL gov messaging — in OpenConnector); OSO standard (Edu-K); DUO BRON/ROD schemas; OOAPI 5.0 (HE); SAML 2.0 / OIDC for SURFconext attribute release; SCIM for HR-system sync; eIDAS / DigiD for federated auth (out of scope).

## Data Model

New: `DataMappingProfile`, `DataExchangeJob`. Consumes: every Scholiq schema as a source. Delegates to: OpenConnector connections (configured separately). ADR-031 exceptions: `DataExchangeRunHandler` (external-system bridge), `DataExchangeRunGuard`, `OsoDossierReviewGuard`.

## Out of Scope

- OpenConnector adapters for Edukoppeling/StUF/OSO-XML/Digikoppeling/SAML (openconnector#753).
- Federated authentication (DigiD / SURFconext / eduID — NC auth + OpenConnector).
- Real-time webhook ingestion (follow-up).
- Cross-tenant / SIVON federation.
