---
slug: data-exchange
title: Data Exchange — Export/Import Jobs to External Registries
status: implemented
feature_tier: should
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [bron-rod-duo, oso-po-vo, leerplicht-digikoppeling, surfconext-attributes, hr-system]
replaces: openspec/specs/data-exchange/spec.md
---

# Data Exchange

## Why

Institutions must exchange data with Dutch government systems (DUO BRON/ROD, OSO, Digikoppeling leerplicht), HE federation (SURFconext), and HR systems. These are integration adapters, not Scholiq schemas. Scholiq exposes its data in a mappable form, holds a job queue, and records every exchange in the audit trail. Wire protocols live entirely in **OpenConnector**.

## Added Requirements

### Schemas

- The system MUST persist `DataMappingProfile` as an OpenRegister object (slug `data-mapping-profile`) with `fieldMappings[]` containing `scholiqField`, `targetField`, and an optional `transform` (`bsn-to-pseudonym` | `date-iso8601` | `cohort-to-brin` | null). Ships with seed profiles for BRON/ROD, OSO, and leerplicht.
- The system MUST persist `DataExchangeJob` as an OpenRegister object (slug `data-exchange-job`) with lifecycle: `queued → running → succeeded | failed | partial`. For `target === 'oso'`, the lifecycle MUST include an intermediate `pending-parent-review` state: `queued → pending-parent-review → running → …`.
- The `DataExchangeJob.result.artefactRef` MUST be an OR file attachment reference, retained for legally-required periods (ADR-008 audit trail).
- Every `DataExchangeJob` state transition MUST emit an OR audit-trail entry.
- The `jobFinished` notification MUST be sent to `requestedBy` when the job reaches `succeeded`, `failed`, or `partial` (idempotency-keyed to prevent duplicates on retries).

### Wire-Protocol Boundary

- Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, Digikoppeling, SAML/OAuth attribute-release, or SCIM wire protocols. These MUST be OpenConnector source/target configurations referenced by the `target` field. (Adapter issues: openconnector#753.)
- The `DataExchangeRunHandler` MUST call OpenConnector via REST API (`POST /apps/openconnector/api/sources/{name}/run`). If OpenConnector is not available, the job MUST move to `failed` with a clear `errorMessage` — e.g. "OpenConnector connection 'bron-rod' not found or returned an error."

### BSN / Privacy

- The `bsn-to-pseudonym` transform MUST use `LearnerProfile.eckId` as the identity pseudonym and MUST NOT extract or transmit `bsnEncrypted` in the payload. The raw BSN MUST NOT leave Scholiq.

### OSO Parent-Review Gate

- For `target === 'oso'`, the job MUST enter `pending-parent-review` state before execution. The `DataExchangeRunGuard` MUST block the `queued → running` direct transition for OSO.
- The `approveDossier` transition (`pending-parent-review → running`) MUST be guarded by `OsoDossierReviewGuard`, which MUST verify the approving actor is listed in the learner's `LearnerProfile.parentIds`.
- The OSO dossier MUST NOT be transmitted until a parent approval is recorded.

### Federated Authentication

- Federated authentication (DigiD / SURFconext / eduID login) is OUT of scope. It is a Nextcloud auth-provider + OpenConnector concern. Scholiq only stores the resulting pseudonymous identifiers (`eckId`, `schoolId`, `bsnEncrypted`) on `LearnerProfile` — which it already does.

### Attendance Integration (TODOs fulfilled)

- When an `AttendanceThreshold` with `onCross.dataExchangeTarget` is crossed, `AttendanceFlagCreationHandler` MUST create a `DataExchangeJob` (direction: export, target: that value, lifecycle: queued) and set `AttendanceFlag.dataExchangeJobId` to its UUID. The `_dataExchangeTargetIntent` placeholder MUST NOT be used.
- The `AttendanceFlagReportGuard` MUST allow the `in-handling → reported` transition only when: (a) `dataExchangeJobId` is null (manual report), OR (b) the linked `DataExchangeJob` is in `succeeded` state.

### Frontend

- The manifest MUST declare `DataMappingProfiles` / `DataMappingProfileDetail` and `DataExchangeJobs` / `DataExchangeJobDetail` index+detail page pairs.
- The manifest MUST declare `RequestExportModal` (custom, component=RequestExportModal) and `OsoDossierReviewView` (custom, component=OsoDossierReviewView) custom pages.
- `validate-manifest.js` MUST pass (0 Ajv errors).
- The `RequestExportModal` MUST display a notice making clear that Scholiq delegates wire-protocol execution to the named OpenConnector connection.
- The `OsoDossierReviewView` MUST display the learner's ECK iD and MUST NOT display `bsnEncrypted`. It MUST include a note that BSN is not shown or transmitted.
- No PHP CRUD controllers: all data access is through OpenRegister's declarative REST API.

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
