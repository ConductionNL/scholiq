## Why

Scholiq institutions must exchange data with Dutch government systems:
- **DUO BRON/ROD** (`leveringsverplichting` — annual pupil + enrolment data)
- **OSO** transfer dossier (PO→VO pupil handover)
- **Leerplicht** notification to municipality (Digikoppeling, triggered by an `AttendanceFlag`)
- **SURFconext** attribute release (higher education identity)
- **HR-system** sync (corporate mandatory-training audience)

These are non-negotiable for the relevant buyer segments. However they are **integration adapters**, not Scholiq data models. Scholiq's job is to (a) expose its objects in a mappable form, (b) hold a small job queue so an administrator can request and monitor an exchange, and (c) record every exchange in the audit trail. The **wire protocols** (Edukoppeling/StUF, OSO-XML, Digikoppeling, SAML attribute release, SCIM-ish HR) live entirely in **OpenConnector source/target configurations** — referenced by name only.

The `attendance` spec left two TODOs deferred to this spec:
- `AttendanceFlagCreationHandler`: when `onCross.dataExchangeTarget` is set, create a `DataExchangeJob` and set `dataExchangeJobId` on the flag.
- `AttendanceFlagReportGuard`: tighten the `report` guard to verify the linked `DataExchangeJob` has succeeded.

This change delivers both, completing the leerplicht-threshold→report pipeline.

## What Changes

### New Schemas (2) — `lib/Settings/scholiq_register.json` (33 → 35)

- **DataMappingProfile** (slug `data-mapping-profile`) — declares how a Scholiq schema maps to an external target: `name`, `target`, `direction`, `sourceSchema`, `targetSchema`, `fieldMappings[]` (scholiqField, targetField, transform), `validationProfile`, `active`, lifecycle (draft → active → archived). Ships with seed profiles for BRON/ROD, OSO, and leerplicht.
- **DataExchangeJob** (slug `data-exchange-job`) — a job request: `direction`, `target`, `mappingProfileId`, `scope` (schema, filters, cohortId, period), `requestedBy`, `requestedAt`, `startedAt`/`finishedAt`, `result` (counts, validationReport, artefactRef), `connectorRunId`, `errorMessage`, `originFlagId`; lifecycle `queued → running → succeeded | failed | partial`; OSO target adds `pending-parent-review` (queued → pending-parent-review → running → …). Notifications: `jobFinished` on succeeded/failed/partial.

### New PHP (3 new + 2 updated, ADR-031 legitimate exceptions only)

- `lib/Listener/DataExchangeRunHandler.php` — `IEventListener<ObjectTransitionedEvent>`, schema=data-exchange-job, to=running. Loads mapping profile, queries source objects, applies transforms (bsn-to-pseudonym/date-iso8601/cohort-to-brin), calls OpenConnector via REST API, records result. No wire protocols.
- `lib/Lifecycle/DataExchangeRunGuard.php` — blocks `queued → running` for OSO target (must go via pending-parent-review → approveDossier first).
- `lib/Lifecycle/OsoDossierReviewGuard.php` — verifies the approving actor is in the learner's `LearnerProfile.parentIds` before allowing `approveDossier`.
- `lib/Lifecycle/AttendanceFlagCreationHandler.php` — TODO fulfilled: when `onCross.dataExchangeTarget` is set, creates a `DataExchangeJob` (queued) and sets `dataExchangeJobId` on the flag. Removes the `_dataExchangeTargetIntent` placeholder.
- `lib/Lifecycle/AttendanceFlagReportGuard.php` — TODO fulfilled: `report` now requires `dataExchangeJobId` to be set AND the linked `DataExchangeJob` to be in `succeeded` state. Passes through if no job is linked (manual report).

### New Frontend

- Manifest pages: `DataMappingProfiles` / `DataMappingProfileDetail` (index+detail), `DataExchangeJobs` / `DataExchangeJobDetail` (index+detail), `RequestExportModal` (custom), `OsoDossierReviewView` (custom).
- Nav menu: "Data Exchange" (order 60, route=DataExchangeJobs).
- `src/views/RequestExportModal.vue` — pick target + mapping profile + scope, queue a `DataExchangeJob`, poll lifecycle/result. Explicit UI note that Scholiq delegates wire-protocol execution to OpenConnector.
- `src/views/OsoDossierReviewView.vue` — for OSO jobs in `pending-parent-review`: renders learner + grade + attendance data for parent review; Approve (approveDossier) and Reject (fail) buttons. Read-only for other states.
- `src/main.js` — imports and registers both new components.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages and both custom views (plain-English keys, both languages).

## Capabilities

### New Capabilities

- `data-exchange`: DataMappingProfile + DataExchangeJob schemas; DataExchangeRunHandler PHP listener exception; DataExchangeRunGuard + OsoDossierReviewGuard lifecycle guards; manifest pages + 2 custom Vue views; l10n en+nl. Count 33 → 35.

### Updated Capabilities

- `attendance`: AttendanceFlagCreationHandler TODO fulfilled (DataExchangeJob creation); AttendanceFlagReportGuard TODO fulfilled (DataExchangeJob succeeded check).

### Out of Scope

- Wire protocols (Edukoppeling/StUF/OSO-XML/Digikoppeling/SAML) — OpenConnector adapters, see openconnector#753.
- Federated authentication (DigiD/SURFconext/eduID) — Nextcloud auth-provider + OpenConnector; Scholiq only persists the resulting identifiers on `LearnerProfile`.
- Real-time webhook ingestion (follow-up).
- Cross-tenant / SIVON federation.
