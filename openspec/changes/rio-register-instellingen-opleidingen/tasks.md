## 1. Schema Registration & Seed Data

- [ ] 1.1 Define RIO schemas in `lib/Settings/rio_register.json` (OpenRegister format with `x-openregister.type: "application"`):
  - `Onderwijsbestuur` schema (properties: naam, bestuursnummer, kvkNummer, rsin, juridischeVorm, vestigingsadres, bezoekadres, contact, bekostigingsstatus, actief, rioStatus, lastSyncedAt)
  - `Onderwijsaanbieder` schema (properties: bestuur ref, naam, aanbiedersnummer, brin, instellingscode, sector, aanbiedersType, vestigingen array, rioStatus, lastSyncedAt)
  - `Opleiding` schema (properties: aanbieder ref, naam, internalCode, crohoCode, crebo, isatcode, isced, opleidingsniveau, studielast, taal, actief, aanvangsdatum, einddatum, erkenningsbesluit object, rioOpleidingId, rioStatus, lastSyncedAt)
  - `AangebodenOpleiding` schema (properties: opleiding ref, vestigingsnummer, modaliteit, cohortStart, cohortEinde, instroommomenten array, voertaal, actief, rioAangebodenId, rioStatus, lastSyncedAt)
  - `RioSyncEvent` schema (properties: eventType, entityType, entityRef, direction, triggeredBy, payload JSON, response JSON, success, errorCode, errorMessage, timestamp)

- [ ] 1.2 Create seed data in `lib/Settings/rio_register.json` with 3–5 realistic objects per schema:
  - 3 onderwijsbesturen (Stichting Onderwijs Gemeente Zwolle, ROC Drenthe, HAN Stichting) with realistic Dutch names, addresses, BN numbers
  - 3 aanbieders (PO, MBO, HBO) with BRIN/instellingscode, sector-appropriate vestigingen
  - 3 opleidingen (PO basis, MBO-4 verpleegkundige, HBO-bachelor informatica) with realistic CROHO/CREBO, erkenningsbesluiten
  - 4 aangeboden-opleidingen (PO-1 class, MBO-BOL, HBO-FT, HBO-PT) with varying modalities and cohorts

- [ ] 1.3 Update `app.php` manifest: register repair step to import `rio_register.json` via `ConfigurationService::importFromApp()` with version control and idempotency (match by slug).

## 2. Service Classes — Sync Orchestration

- [ ] 2.1 Create `src/Service/RioSyncService.php`:
  - `push(Object $entity): array` — Determine HTTP method (POST for new, PATCH for update) based on `rioStatus` and `rioId` presence; construct payload from entity; call openconnector REST client; on success: update `rioStatus='synced'`, `lastSyncedAt=now()`, save `rioId` if new; on failure: set `rioStatus='drift'`, log error in rio-sync-event, throw `RioSyncException`
  - `pull(string $bestuursnummer): void` — Fetch all bestuur/aanbieder/opleiding/aangeboden-opleiding records for the bestuursnummer from DUO RIO-API; compute canonical hashes; compare with local objects; for each diff: create rio-sync-event with type='conflict-detected'; notify rio-beheerder
  - `resolveConflict(Object $entity, string $action): void` — Action = 'push-local' or 'accept-remote'; execute accordingly; update rioStatus/lastSyncedAt; log conflict-resolved event

- [ ] 2.2 Create `src/Service/RioValidationService.php`:
  - `validateCroho(string $code): array` — Query DUO CROHO-register via openconnector; return {isValid: bool, status: string, officialName: string}
  - `validateCrebo(string $code, string $dossier): array` — Query SBB kwalificatiedossier-register; return {isValid: bool, status: string, officialName: string}
  - `validateBagAddress(string $street, string $number, string $postcode): array` — Query openconnector BAG service; return {found: bool, address: {...}, gemeente: string, coordinates: [lat,lng]}
  - `checkAccreditationValidity(Object $opleiding, string $cohortStart): bool` — Verify erkenningsbesluit.geldigVan <= cohortStart <= geldigTot; return true/false
  - `checkDeactivationSafety(Object $vestiging): array` — Check for active aangeboden-opleidingen and BRON enrolments; return {canDeactivate: bool, blockingRecords: [...]}

- [ ] 2.3 Create `src/Service/RioCircuitBreakerService.php`:
  - `enqueue(Object $entity): void` — Add push-sync to queue with timestamp, status='pending'
  - `process(): void` — Throttle queue to max 90 req/min; call RioSyncService.push() for each queued item; track success/failure; on 5 consecutive failures: activate circuit-breaker, pause processing, schedule 5-min retry, emit 'rio.circuit-breaker.activated' event
  - `recover(): void` — Called after circuit-breaker timeout; resume queue processing; emit 'rio.circuit-breaker.recovered' event
  - `getQueueStatus(): array` — Return {pending: int, processing: int, completed: int, failed: int, circuitBreakerActive: bool, estimatedCompletionTime: string}

## 3. Database & OpenRegister Integration

- [ ] 3.1 Create migration/repair step to register the 5 RIO schemas via `RegisterService::saveRegister()` with correct property types (string, integer, object, array, boolean), required flags, and descriptions from ADR-000.

- [ ] 3.2 Create migration to extend existing `opleiding`/`programme` schemas with optional RIO-specific fields:
  - `rioOpleidingId` (string, optional, immutable after first sync)
  - `rioStatus` (enum: local-only|synced|drift|remote-only)
  - `lastSyncedAt` (datetime, optional, auto-updated on sync)
  - Ensure backward-compatibility: existing rows get null/default values, no breaking changes

- [ ] 3.3 Configure OpenRegister object-update event subscriptions: whenever bestuur/aanbieder/opleiding/aangeboden-opleiding is saved, emit webhook event → `RioSyncService.push()` triggered asynchronously.

## 4. OpenConnector Integration

- [ ] 4.1 Configure openconnector REST data sources for DUO RIO-API:
  - Source 1: RIO-API preproduction endpoint (for test)
  - Source 2: RIO-API production endpoint (for live)
  - Both use PKIoverheid certificate from hydra secrets (configure via hydra ADR spec)
  - Add rate-limiter per openconnector ADR-007: 100 req/min per certificate; queue requests at 90 req/min

- [ ] 4.2 Configure openconnector REST sources for validation endpoints:
  - CROHO-register API (croho.nl)
  - SBB kwalificatiedossier-register (kwalificatiesmbo.nl)
  - BAG lookup service (via Dutch government API gateway)

## 5. Backend Routes & Controllers

- [ ] 5.1 Create `src/Controller/RioController.php`:
  - `GET /api/rio/sync/pull` — Trigger on-demand pull-sync; call `RioSyncService.pull()`; return 202 Accepted with queue status
  - `GET /api/rio/conflicts` — Fetch all rio-sync-events with type='conflict-detected'; return paginated list with entity details
  - `POST /api/rio/conflicts/{eventId}/resolve` — Payload: {action: 'push-local'|'accept-remote'}; call `RioSyncService.resolveConflict()`; return 200 OK or error
  - `GET /api/rio/queue-status` — Return circuit-breaker queue status from `RioCircuitBreakerService.getQueueStatus()`
  - `GET /api/rio/audit-log` — Fetch rio-sync-events, filterable by entityType/eventType/dateRange; return paginated

- [ ] 5.2 Create public GraphQL resolver at `GET /api/public/rio`:
  - Query root: `{ opleiding(...): [Opleiding], aangeboden_opleiding(...): [AangebodenOpleiding], aanbieder(...): [Aanbieder] }`
  - Filters: sector, actief, language (taal), cohort date range
  - Return only public fields (no besluitnummer, no PDF URLs, no contact details beyond sector/name)
  - Rate-limit by IP (100 req/min)

- [ ] 5.3 Add authorization checks in all routes:
  - `rio-beheerder` role required for push/pull/resolve operations
  - Multi-bestuur scope enforcement (users can only manage their authorized besturen)
  - Audit log every access to `/api/rio/*` routes

## 6. Background Jobs & Scheduled Tasks

- [ ] 6.1 Create scheduled job `src/Job/RioPullSyncJob.php`:
  - Cron schedule: Daily at 03:00 UTC
  - Fetch list of active bestuursnummers from local database
  - For each: call `RioSyncService.pull(bestuursnummer)`
  - Create rio-sync-event records for each pull action
  - On drift detected: create notifications to rio-beheerders; log to audit trail

- [ ] 6.2 Create scheduled job `src/Job/RioCohortGenerationJob.php`:
  - Cron schedule: 1 May each year
  - Scan all active aangeboden-opleidingen with actief=true
  - For each: create a "proposed new cohort" record with status='voorgesteld', cohortStart/cohortEinde shifted forward one year
  - Create notification to rio-beheerder per bestuur: "[N] new cohorts proposed for review"

- [ ] 6.3 Create scheduled job `src/Job/RioAccreditationCheckJob.php`:
  - Cron schedule: Daily at 02:00 UTC (before pull-sync)
  - Check all opleiding records with erkenningsbesluit for expiry
  - Notify rio-beheerder at 60, 30, 14 days before expiry
  - If already expired AND active aangeboden-opleidingen exist: create urgent notification, mark aangeboden-opleidingen as at-risk (red dashboard badge)

- [ ] 6.4 Create scheduled job `src/Job/RioCodeRetirementCheckJob.php`:
  - Cron schedule: Daily at 02:30 UTC (after accreditation check, before pull-sync)
  - For all opleiding records with CROHO/CREBO codes: call validation service to check current status in DUO registers
  - If code has retired: notify rio-beheerder, set `_code_retired_flag` on opleiding, block new inschrijvingen

## 7. Frontend Routes & Pages

- [ ] 7.1 Create `/admin/rio/besturen` — List/detail page for onderwijsbestuur:
  - List view: CnIndexPage + CnDataTable, columns: naam, bestuursnummer, sector-count, actief, lastSyncedAt, rioStatus (badge)
  - Filters: sector, rioStatus, actief
  - Detail view: CnDetailPage, form fields: naam, bestuursnummer (read-only after sync), kvkNummer, rsin, juridischeVorm, addresses, contact, bekostigingsstatus, actief; right sidebar: linked aanbieders (count + list), sync history (rio-sync-events), audit trail

- [ ] 7.2 Create `/admin/rio/aanbieders` — List/detail page for onderwijsaanbieder:
  - List: Filter by bestuursnummer (if multi-bestuur scoped), columns: naam, bestuur, sector, location-count, rioStatus, lastSyncedAt
  - Detail: Form for naam, sector, aanbiedersType; vestigingen sub-table (add/edit/deactivate), linked opleiding count, audit trail

- [ ] 7.3 Create `/admin/rio/opleidingen` — List/detail page for opleiding:
  - List: Columns: naam, sector, opleidingsniveau, CROHO/CREBO code, erkenningsbesluit-status (badge: valid/expiring-60d/expiring-30d/expired/none), rioStatus, lastSyncedAt
  - Filters: sector, code-validity-status, accreditation-expiry
  - Detail: Form for alle properties; erkenningsbesluit section (besluitnummer, dates, PDF upload/download), aangeboden-opleiding sub-list (count + link to offerings page), code-validation result (if CROHO/CREBO), audit trail

- [ ] 7.4 Create `/admin/rio/aangeboden-opleidingen` — List/detail for aangeboden-opleiding:
  - List: Columns: opleiding-name, vestiging, modaliteit, cohortStart, cohortEinde, voertaal, enrolment-count, rioStatus, lastSyncedAt
  - Filters: sector, modaliteit, cohort-date-range, status (active/ended/proposed)
  - Detail: Form for vestiging (read-only, selected at creation), modaliteit, cohort dates, instroommomenten, voertaal, actief; deactivation safeguard (check for active enrolments); audit trail

- [ ] 7.5 Create `/admin/rio/reconciliation` — Conflict detection & resolution UI:
  - Header: "Synchroniseer nu" button (triggers pull-sync); "X conflicts detected" message
  - Main section: List of drift/conflict records
  - Conflict card (expandable):
    - Left column: "Scholiq state" — entity properties
    - Right column: "RIO state" — fetched entity properties
    - Diff highlight: changed fields colored yellow/orange
    - Metadata: Timestamp + user of last scholiq modification (from audit trail), timestamp of last RIO modification
    - Action buttons: "Push scholiq values", "Accept RIO values", "Defer for manual review"
  - "Deferred conflicts" section: list conflicts marked for manual review
  - Filter: entityType, detection-date range

- [ ] 7.6 Create `/admin/rio/sync-log` — Read-only audit trail view:
  - CnDataTable with columns: Timestamp, Entity (linked), Event type, Direction, Triggered by (user|schedule|webhook), Status (✓/✗), Error message (if failed)
  - Filters: entityType, eventType, success/failure, date range
  - Detail row: Expandable JSON viewer showing payload + response

- [ ] 7.7 Create `/admin/rio/overview` — Dashboard for rio-beheerders:
  - KPI cards: "Total programs", "Sync status: [X% synced, Y% drift, Z% local-only]", "Pending conflicts", "Pending cohorts", "Expired accreditations"
  - Chart: Sync events over time (line chart, 7-day rolling)
  - Alert banner: Circuit-breaker status, if active
  - Quick-action cards: "Review conflicts", "Approve cohorts", "Extend accreditations"
  - Multi-bestuur view (admin only): Stats per bestuur

## 8. Frontend Components & Dialogs

- [ ] 8.1 Create custom Vue component `CnRioDiffView.vue`:
  - Props: `localEntity`, `remoteEntity`, `schema` (to know which fields to display)
  - Display: Two-column layout, side-by-side values, diff highlighting for changed fields, metadata (timestamps, last-modified-by)

- [ ] 8.2 Create dialog `RioConflictResolutionDialog.vue`:
  - Show conflict record + diff view
  - Radio buttons: "Push local", "Accept remote", "Defer"
  - Confirmation modal before action
  - On success: Close dialog, update list view, refresh sync status badge

- [ ] 8.3 Create dialog `RioErkenningsbesluitDialog.vue`:
  - Form fields: besluitnummer, datumBesluit, geldigVan, geldigTot
  - File upload for PDF (via FileService)
  - Validation: dates must be valid, geldigVan <= geldigTot
  - On save: Attach PDF via docudesk with classificatie='besluit-ocw'

- [ ] 8.4 Create dialog `RioNewLocationDialog.vue`:
  - Vestiging form: naam, bezoekadres (with BAG validation), contactperson
  - Auto-suggest next BRIN volgnummer for PO/VO aanbieders
  - On BAG lookup: Call validation service, show address matches if ambiguous, user selects one
  - On save: Create vestiging with validated address data

- [ ] 8.5 Create component `RioQueueStatus.vue`:
  - Show current queue status (pending/processing/completed/failed counts)
  - Progress bar + ETA
  - Circuit-breaker indicator (if active, show red banner with "Retry in X seconds")

## 9. Authorization & Role-Based Access

- [ ] 9.1 Define new role `rio-beheerder` with permissions:
  - read:rio-data (view all RIO entities)
  - write:rio-data (create/update RIO entities for authorized besturen)
  - resolve:rio-conflicts (resolve sync conflicts)
  - trigger:rio-sync (trigger on-demand pull-sync)
  - Optional scope: per-bestuursnummer (user can manage multiple besturen if authorized)

- [ ] 9.2 Implement `PropertyRbacHandler` for RIO schemas:
  - `bestuursnummer` field: read-only after first sync (immutable)
  - `rioStatus`, `lastSyncedAt`: read-only (auto-managed by system)
  - `rioOpleidingId`, `rioAangebodenId`: read-only (DUO-assigned)

- [ ] 9.3 Add authorization checks in all RIO controllers + routes:
  - Require `rio-beheerder` role
  - Filter besturen list to user's authorized besturen (if scoped)
  - Prevent cross-bestuur access

## 10. Notifications & Alerts

- [ ] 10.1 Create notification templates using scholiq's `NotificationService`:
  - "RIO sync failed: [entity] — [error message]" (level: error)
  - "RIO conflict detected: [entity] — review in reconciliation view" (level: warning)
  - "Accreditation expires in [N] days: [program]" (level: info/warning/error per N value)
  - "Code retired: [CROHO/CREBO] [program] — update required" (level: error)
  - "Circuit-breaker activated: RIO-API unavailable" (level: error)
  - "New cohorts proposed: [N] items for review" (level: info)

- [ ] 10.2 Wire notifications to events:
  - RioSyncService push failure → send sync-failed notification
  - rio-sync-event conflict-detected → send conflict notification
  - AccreditationCheckJob expiry detection → send accreditation notification
  - CodeRetirementCheckJob detection → send code-retired notification
  - CircuitBreakerService activation/recovery → send circuit-breaker notification
  - CohortGenerationJob completion → send pending-cohorts notification

- [ ] 10.3 Add notification delivery channels:
  - In-app badge on `/admin/rio` menu item (unread count)
  - Nextcloud notification panel
  - Email digest (configurable: daily|immediate)
  - Optional webhooks for Slack/Teams (if integrated)

## 11. Testing

- [ ] 11.1 Unit tests for `RioSyncService`:
  - Test push with new entity (rioStatus: local-only → synced)
  - Test push with updated entity (rioStatus: synced → synced, lastSyncedAt updated)
  - Test push with API error (rioStatus: local-only → drift, error logged)
  - Test pull with no drift (hash comparison, no conflict)
  - Test pull with drift (conflict detected, logged, notification sent)
  - Test conflict resolution (push-local, accept-remote, defer)

- [ ] 11.2 Unit tests for `RioValidationService`:
  - Test CROHO validation (valid code, invalid code, code with status=inactive)
  - Test CREBO validation (valid code, retired code)
  - Test BAG address validation (found, not found, ambiguous)
  - Test accreditation validity check (within range, before range, after range)
  - Test deactivation safety (no blocking records, with blocking records)

- [ ] 11.3 Unit tests for `RioCircuitBreakerService`:
  - Test queue enqueue/process (throttle to 90 req/min)
  - Test circuit-breaker activation (5 consecutive failures)
  - Test circuit-breaker recovery (auto-resume after 5 min)
  - Test queue status reporting

- [ ] 11.4 Integration tests (with mocked DUO RIO-API via Guzzle mock):
  - Test end-to-end push workflow (create opleiding → webhook trigger → push → rioId saved)
  - Test end-to-end pull workflow (pull → drift detection → conflict notification → resolution)
  - Test conflict resolution UI → backend → RioSyncService interaction
  - Test multi-bestuur scoping (user can only see/manage their besturen)

- [ ] 11.5 Functional tests (browser-driven):
  - Test creating a new opleiding with erkenningsbesluit PDF upload
  - Test creating a vestiging with BAG address validation
  - Test conflict resolution UI (side-by-side diff, resolution action)
  - Test on-demand pull-sync trigger → conflict list populated → resolution applied
  - Test cohort-generation pending notification → approval → cohort created + pushed
  - Test accreditation expiry warnings (60d, 30d, 14d, expired)

## 12. Documentation & Migration

- [ ] 12.1 Create admin guide section: "RIO Synchronization" at `docs/Technical/rio-sync-admin.md`
  - Overview of RIO architecture + scholiq's role
  - Getting started: Enable rio-beheerder role, configure bestuursnummer(s)
  - CROHO/CREBO code validation workflow
  - Erkenningsbesluit management (upload, renewal, expiry monitoring)
  - Push/pull sync mechanics, conflict resolution workflow
  - Rate-limiting & circuit-breaker behavior
  - Troubleshooting: Common errors, API logs, audit trail inspection

- [ ] 12.2 Create user guide: "Managing RIO Data" at `docs/user-guide/rio-besturen-aanbieders.md`
  - Walkthrough: Create a new onderwijsbestuur → add aanbieders → add vestigingen → add opleidingen → add offerings
  - BAG address validation, CROHO/CREBO validation, erkenningsbesluit upload
  - Cohort management & generation
  - Conflict resolution workflow (with screenshots)

- [ ] 12.3 Update migration docs for institutions migrating from external RIO tools:
  - Bulk-import workflow: CSV → field mapping → preview → import as local-only
  - Post-import: Review & push each program individually to RIO
  - Reference mapping file for repeated imports

## 13. Build & Verification

- [ ] 13.1 Run hydra quality gates (SPDX headers, semantic auth, etc.) over new PHP files.

- [ ] 13.2 Run existing test suite: `phpunit`, `npm test` (Vue components).

- [ ] 13.3 Smoke test: Start scholiq app, navigate to `/admin/rio/besturen`, verify list loads with seed data.

- [ ] 13.4 Functional smoke test: Create a new opleiding with erkenningsbesluit PDF, trigger push-sync, verify rioStatus='synced'.

- [ ] 13.5 Fix any pre-commit hook failures (linting, formatting, security checks).

## 14. Deduplication Check

- [ ] 14.1 Search `openspec/specs/` and `openregister/lib/Service/` for existing sync/replication capabilities:
  - `ObjectService`: Already handles CRUD; NOT duplicated (we use it).
  - `SyncService` (if exists): Check if generic enough for RIO-specific push/pull logic. Finding: [result]
  - External API integration patterns: Check for existing patterns. Finding: [result]
  - Conflict detection: Check for existing conflict-resolution patterns. Finding: [result]
  - Document findings in PR description.

## Completion Criteria

- [ ] All 4 RIO schemas registered in OpenRegister with seed data
- [ ] RioSyncService, RioValidationService, RioCircuitBreakerService implemented
- [ ] Push workflow: create local entity → webhook triggers push → rioStatus updated
- [ ] Pull workflow: daily 03:00 sync → drift detected → conflict notifications sent
- [ ] Conflict resolution: Side-by-side diff view → user chooses action → entity updated
- [ ] CROHO/CREBO validation: Real-time lookup + code-retirement monitoring
- [ ] Erkenningsbesluit management: Upload → validity gate on offering creation → expiry monitoring
- [ ] Vestiging management: BAG address validation, BRIN volgnummer suggestion, deactivation safety gate
- [ ] Frontend routes: All 7 RIO pages (besturen, aanbieders, opleidingen, aangeboden-opleidingen, reconciliation, sync-log, overview) functional
- [ ] Public GraphQL API: `/api/public/rio` returning public fields, rate-limited by IP
- [ ] Background jobs: 4 scheduled jobs (daily pull, annual cohort-gen, daily accreditation-check, daily code-retirement-check) running
- [ ] Notifications: 6 notification types sent to rio-beheerders on relevant events
- [ ] Role-based access: rio-beheerder role + multi-bestuur scoping working
- [ ] Audit trail: rio-sync-events immutable log complete
- [ ] Tests passing: Unit tests (sync, validation, circuit-breaker), integration tests (mocked RIO-API), functional tests (UI workflows)
- [ ] Hydra gates: All PHP files pass SPDX, semantic-auth, route-auth, no-admin-idor gates
- [ ] Documentation: Admin guide + user guide + migration guide complete
- [ ] App loads without errors; seed data visible in `/admin/rio/besturen`
