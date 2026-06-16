# Design — Data Exchange: DataMappingProfile, DataExchangeJob + OpenConnector delegation

## 1. Architectural decisions

### 1.1 Scholiq implements NO wire protocols

Edukoppeling/StUF (BRON/ROD), OSO-XML (PO→VO transfer), Digikoppeling (leerplicht), SAML attribute release (SURFconext), SCIM-ish HR sync — all of these are OpenConnector source/target configurations. Scholiq holds a job queue and field-mapping declarations. The `DataExchangeJob` delegates to a named OpenConnector connection (`target` field) via OpenConnector's REST API (`POST /apps/openconnector/api/sources/{name}/run`). If OpenConnector is not installed, the job fails with a clear `errorMessage`.

This is the ADR-031 "external-system bridge" exception: `DataExchangeRunHandler` is a single-responsibility listener that orchestrates the OpenConnector call. No protocol code lives in Scholiq.

### 1.2 BSN never leaves Scholiq plain-text

The `bsn-to-pseudonym` transform in `DataExchangeRunHandler.applyTransform()` reads `LearnerProfile.eckId` and returns it instead of the raw `bsnEncrypted`. The field `bsnEncrypted` is never extracted, never serialised into the payload, and never handed to OpenConnector. The BSN — if needed by OpenConnector for BRON/ROD — must be decrypted and handled entirely within OpenConnector's own infrastructure.

### 1.3 OSO parent-review gate

OSO dossiers must not leave Scholiq without a parent's explicit approval. The lifecycle path for `target === 'oso'` is:
`queued → pending-parent-review → running → succeeded | failed | partial`

- `DataExchangeRunGuard` blocks `queued → running` directly for OSO (returns `false` when `from === 'queued'` and `target === 'oso'`).
- The `pendingParentReview` transition moves the job to `pending-parent-review`.
- `OsoDossierReviewGuard` verifies the approving actor is in `LearnerProfile.parentIds` before allowing `approveDossier` (`pending-parent-review → running`).
- For all other targets `DataExchangeRunGuard` returns `true` unconditionally.

### 1.4 Leerplicht auto-queue (attendance TODO fulfilled)

`AttendanceFlagCreationHandler` now calls `queueDataExchangeJob()` when `onCross.dataExchangeTarget` is set. The job is saved first (`queued` state), its UUID is stored in `AttendanceFlag.dataExchangeJobId`. The `_dataExchangeTargetIntent` placeholder field is removed.

`AttendanceFlagReportGuard.check()` now queries the linked `DataExchangeJob` and returns `false` unless it is in `succeeded` state. When no job is linked (manual report, no `dataExchangeTarget` configured), the guard passes unconditionally.

### 1.5 Federated authentication is OUT of scope

DigiD, SURFconext, and eduID *login* are Nextcloud auth-provider + OpenConnector concerns. Scholiq only stores the resulting pseudonymous identifiers (`eckId`, `schoolId`, `bsnEncrypted`) on `LearnerProfile`, which it already does. No authentication handshake code lives here.

### 1.6 OpenConnector API assumption

Scholiq calls `POST /index.php/apps/openconnector/api/sources/{name}/run` (internal loopback via `IClientService + IURLGenerator`). Expected response: `{ runId, status, recordsProcessed, recordsAccepted, recordsRejected, validationReport, artefactRef }`. If the path changes in OpenConnector, update `DataExchangeRunHandler::OPENCONNECTOR_RUN_PATH`. The constant and this document serve as the integration contract until openconnector#753 stabilises the API.

## 2. Schemas

### 2.1 DataMappingProfile (slug `data-mapping-profile`)

| field | type | notes |
|---|---|---|
| name | string | required — human-readable label |
| target | string | required — OpenConnector connection name (bron-rod / oso / leerplicht / surfconext / hr / custom) |
| direction | enum | export \| import \| sync |
| sourceSchema | string | Scholiq schema slug being mapped |
| targetSchema | string | external schema identifier (free-form, e.g. 'DUO:LeerlingV2') |
| fieldMappings | array | `{scholiqField, targetField, transform: 'bsn-to-pseudonym'|'date-iso8601'|'cohort-to-brin'|null}[]` |
| validationProfile | string\|null | named external-schema validation profile |
| active | boolean | default true |
| lifecycle | string | draft → active → archived |
| tenant_id | uuid | required |

Ships with `x-openregister-seed` profiles for BRON/ROD, OSO, and leerplicht. HR is admin-configured.

### 2.2 DataExchangeJob (slug `data-exchange-job`)

| field | type | notes |
|---|---|---|
| direction | enum | export \| import \| sync |
| target | string | required — named OpenConnector connection |
| mappingProfileId | uuid\|null | which DataMappingProfile to use (null = raw objects) |
| scope | object | `{schema, filters, cohortId, period}` |
| requestedBy | string | NC user ID of requester |
| requestedAt | datetime | required — ISO 8601 |
| startedAt / finishedAt | datetime\|null | set by DataExchangeRunHandler |
| result | object\|null | `{recordsProcessed, recordsAccepted, recordsRejected, validationReport, artefactRef}` |
| connectorRunId | string\|null | OpenConnector run ID |
| errorMessage | string\|null | human-readable failure reason |
| originFlagId | uuid\|null | AttendanceFlag that auto-queued this job |
| lifecycle | string | see below |
| tenant_id | uuid | required |

Lifecycle states:
- `queued` — initial
- `pending-parent-review` — OSO only: dossier awaiting parent approval
- `running` — delegated to OpenConnector
- `succeeded` / `partial` / `failed` — terminal

`x-openregister-calculations`: `durationSeconds`, `successRate`.
`x-openregister-notifications`: `jobFinished` (on succeeded/failed/partial, idempotency-keyed).
`x-openregister-relations`: mappingProfile → DataMappingProfile, originFlag → AttendanceFlag.

## 3. PHP — ADR-031 legitimate exceptions

### 3.1 DataExchangeRunHandler (lib/Listener/DataExchangeRunHandler.php)

Single-responsibility: orchestrate the OpenConnector call.

Algorithm:
1. Filter to `register=scholiq, schema=data-exchange-job, to=running`.
2. Record `startedAt`.
3. Load DataMappingProfile (if `mappingProfileId` set).
4. Query source objects via `ObjectService::findAll(scope)`.
5. Apply `fieldMappings`:
   - `bsn-to-pseudonym` → return `object['eckId']` (never `bsnEncrypted`)
   - `date-iso8601` → `date('Y-m-d', strtotime($value))`
   - `cohort-to-brin` → fetch `Cohort.brinNumber` for the cohortId value
   - `null` → passthrough
6. POST payload to OpenConnector REST API.
7. If null response → save job as `failed` with error message.
8. Record `connectorRunId`, `result`, `finishedAt`; set lifecycle to `succeeded` / `partial` / `failed`.

Registered in `Application.php` for `ObjectTransitionedEvent`.

### 3.2 DataExchangeRunGuard (lib/Lifecycle/DataExchangeRunGuard.php)

`check(array &$transitionContext): bool`
- If `object.target === 'oso'` AND `from === 'queued'`: return `false`.
- Otherwise: return `true`.

Not registered in `Application.php` — OR resolves guards by class name from the schema `requires:` string.

### 3.3 OsoDossierReviewGuard (lib/Lifecycle/OsoDossierReviewGuard.php)

`check(array &$transitionContext): bool`
1. Read `actor` from context.
2. Resolve `learnerId` from `object.scope.filters.learnerId`.
3. Fetch `LearnerProfile` for the learner.
4. Return `in_array(actor, parentIds, true)`.

Not registered in `Application.php` — OR resolves by class name.

### 3.4 AttendanceFlagCreationHandler (updated)

On threshold crossing, when `onCross.dataExchangeTarget` is set:
- Call `queueDataExchangeJob(target, learnerId, windowStart, windowEnd, tenantId)`.
- Save the `DataExchangeJob` (`direction: export, lifecycle: queued`).
- Set `AttendanceFlag.dataExchangeJobId` to the returned UUID.
- Remove the `_dataExchangeTargetIntent` placeholder.

### 3.5 AttendanceFlagReportGuard (updated)

- If `flag.dataExchangeJobId === null`: return `true` (manual report, no job).
- Else: fetch `DataExchangeJob`, return `true` only if `lifecycle === 'succeeded'`.

## 4. Frontend

### 4.1 Manifest pages

| id | route | type | notes |
|---|---|---|---|
| DataMappingProfiles | /data-exchange/mapping-profiles | index | schema=DataMappingProfile |
| DataMappingProfileDetail | /data-exchange/mapping-profiles/:id | detail | schema=DataMappingProfile |
| DataExchangeJobs | /data-exchange/jobs | index | schema=DataExchangeJob |
| DataExchangeJobDetail | /data-exchange/jobs/:id | detail | schema=DataExchangeJob |
| RequestExportModal | /data-exchange/request | custom | component=RequestExportModal |
| OsoDossierReviewView | /data-exchange/jobs/:id/oso-review | custom | component=OsoDossierReviewView |

Nav menu: "Data Exchange", route=DataExchangeJobs, order=60.

### 4.2 RequestExportModal.vue

- Pick direction + target (known targets + custom) + mapping profile (filtered) + scope (schema + cohortId + period).
- Explicit delegation notice: "Scholiq delegates wire-protocol execution to the named OpenConnector connection."
- OSO-specific note: job enters `pending-parent-review` before executing.
- Submit → POST `DataExchangeJob` in `queued` state.
- Poll lifecycle + result every 3 seconds until terminal state.
- Options API, no custom Pinia module.

### 4.3 OsoDossierReviewView.vue

- Load `DataExchangeJob` by route `:id`.
- Load `LearnerProfile`, `GradeEntry[]`, `AttendanceRecord[]` for the learner (from `scope.filters.learnerId`).
- Render learner info (ECK iD shown, BSN not shown/transmitted — privacy note displayed).
- Grades table + attendance table (read-only).
- When `pending-parent-review`: Approve (approveDossier transition) + Reject (fail transition + reason) buttons.
- Options API, no custom Pinia module.

## 5. Out of scope

- Wire protocols (all in OpenConnector — openconnector#753).
- Federated authentication handshake (Nextcloud auth-provider + OpenConnector).
- Real-time webhook ingestion.
- Cross-tenant / SIVON federation.
- `OsoDossierComposer` as a separate service — payload assembly inline in `DataExchangeRunHandler` for simplicity; can be extracted later.
