## REQ-RIO-001: RIO Core Schemas & CRUD Management

The system MUST register and manage four RIO-kern entities as first-class OpenRegister schemas: `onderwijsbestuur`, `onderwijsaanbieder`, `opleiding`, `aangeboden-opleiding`. Each MUST support full CRUD operations (create, read, update, delete) via the UI, with role-based authorization and audit logging.

### REQ-RIO-001-A: Schema Registration & Seed Data

GIVEN the scholiq repair step runs on first install  
WHEN the app loads OpenRegister configuration  
THEN the system MUST register all four RIO schemas with correct property types, required flags, and descriptions matching ADR-000  
AND MUST load seed data: 3–5 realistic objects per schema with Dutch values, matched by slug for idempotency.

### REQ-RIO-001-B: Create New Entity with Role Enforcement

GIVEN a rio-beheerder user  
WHEN they navigate to `/admin/rio/besturen` and click "Voeg bestuur toe"  
THEN the system MUST display an auto-generated form with required fields (naam, bestuursnummer, juridischeVorm) and optional fields (kvkNummer, rsin, contact, etc.)  
AND MUST auto-assign `rioStatus = 'local-only'` and `lastSyncedAt = null` on save.

GIVEN a user without rio-beheerder role  
WHEN they attempt to access `/admin/rio/besturen` or click edit on any RIO entity  
THEN the system MUST return HTTP 403 Forbidden  
AND MUST log the attempt in audit trail with action "UNAUTHORIZED_ACCESS".

### REQ-RIO-001-C: Detail View with Linked References

GIVEN an existing onderwijsaanbieder record  
WHEN a user opens its detail view at `/admin/rio/aanbieders/{id}`  
THEN the system MUST display all fields (naam, bestuursnummer, sector, vestigingen array)  
AND MUST show a linked-object preview of the ref'd onderwijsbestuur (name + bestuursnummer)  
AND MUST show a read-only list of all connected opleiding records (count badge, paginated list).

### REQ-RIO-001-D: Deactivation with Safety Checks

GIVEN an onderwijsaanbieder with active aangeboden-opleidingen  
WHEN a rio-beheerder attempts to set `actief = false`  
THEN the system MUST block the action  
AND MUST show a message listing affected aangeboden-opleidingen: "Kan aanbieder niet deactiveren — volgende aanbiedingen zijn nog actief: [list]"  
AND MUST provide a link to deactivate those offerings first.

## REQ-RIO-002: Bi-Directional Synchronization with DUO RIO-API

The system MUST support both push (scholiq → RIO) and pull (RIO → scholiq) synchronization via the official DUO RIO REST API. Push is event-driven (immediate); pull is scheduled daily and on-demand.

### REQ-RIO-002-A: Push on Object Update (Event-Driven)

GIVEN a rio-beheerder saves a new opleiding  
WHEN the OpenRegister object-update event fires  
THEN the system MUST call `RioSyncService.push(opleiding)` within 30 seconds  
AND MUST POST the opleiding JSON to the DUO RIO-API endpoint `/v1/opleidingen`  
AND MUST on success: save the returned `rioOpleidingId`, set `rioStatus = 'synced'`, set `lastSyncedAt = now()`  
AND MUST create a rio-sync-event record with eventType='create', direction='push', success=true, payload=[sent], response=[received].

### REQ-RIO-002-B: Push Modification (PATCH)

GIVEN an existing synchronized opleiding with `rioStatus = 'synced'`  
WHEN a rio-beheerder changes the `taal` field and saves  
THEN the system MUST detect the change, set `rioStatus = 'drift'` locally  
AND MUST call `RioSyncService.push(opleiding)` with HTTP PATCH to `/v1/opleidingen/{rioOpleidingId}`  
AND MUST on success: set `rioStatus = 'synced'`, update `lastSyncedAt`  
AND MUST log the push action in rio-sync-event with eventType='update', direction='push', payload=[old+new], response=[rio-response].

### REQ-RIO-002-C: Pull Daily Schedule

GIVEN the scheduled job at 03:00 UTC  
WHEN this time is reached  
THEN the system MUST call `RioSyncService.pull()` for each active bestuursnummer  
AND MUST fetch all bestuur/aanbieder/opleiding/aangeboden-opleiding records from DUO for that bestuursnummer via GET `/v1/besturen`, `/v1/aanbieders`, `/v1/opleidingen`, `/v1/aangeboden-opleidingen`  
AND MUST create rio-sync-event records with eventType='fetch', direction='pull', timestamp=now(), success=true/false.

### REQ-RIO-002-D: Pull On-Demand via UI Button

GIVEN a rio-beheerder viewing the `/admin/rio/reconciliation` page  
WHEN they click "Synchroniseer nu met RIO"  
THEN the system MUST show a loading indicator  
AND MUST call `RioSyncService.pull()` immediately  
AND MUST redirect to the reconciliation view, highlighting newly detected drift or conflicts  
AND MUST show a toast notification "Synchronisatie voltooid: X nieuwe drifts gedetecteerd".

### REQ-RIO-002-E: Push Failure Handling

GIVEN a push-sync call to DUO RIO-API  
WHEN the API returns an error (4xx/5xx or timeout)  
THEN the system MUST NOT modify the local object's rioStatus or lastSyncedAt  
AND MUST log the error in rio-sync-event with success=false, errorCode=[http-code], errorMessage=[response-body]  
AND MUST notify the rio-beheerder: "RIO-synchronisatie mislukt voor [entity]: [error message]. Handeling vereist."  
AND MUST retry the push after 5 minutes (up to 3 retries with exponential backoff) — visible in the UI or log.

## REQ-RIO-003: Drift Detection via Canonical Hashing

The system MUST detect differences between local and RIO state using canonical hashing: compute a deterministic hash of each entity's property values (sorted keys, normalized whitespace), compare local hash vs. RIO hash, and flag mismatches as drift.

### REQ-RIO-003-A: Hash Computation & Comparison

GIVEN a pull-sync fetches a remote opleiding record from RIO  
WHEN the system compares it to the local version  
THEN the system MUST compute `local_hash = hash(canonical_json(local_object))`  
AND `remote_hash = hash(canonical_json(rio_response))`  
AND `if local_hash !== remote_hash: flag as drift`.

### REQ-RIO-003-B: Drift Logging

GIVEN drift is detected on an opleiding  
WHEN the pull-sync completes  
THEN the system MUST create a rio-sync-event with eventType='conflict-detected', direction='pull', payload=[local], response=[remote], and mark the local opleiding with a transient `_drift_detected_at = now()` in-memory flag (not persisted).

### REQ-RIO-003-C: Drift Visibility

GIVEN drift has been detected after a pull-sync  
WHEN the rio-beheerder opens `/admin/rio/reconciliation`  
THEN the system MUST list all drift records (opleiding, aanbieder, etc.) with a diff-view showing local vs. remote values for each changed field  
AND MUST include timestamps of last modification on both sides.

## REQ-RIO-004: Conflict Resolution UI

When both local and remote versions of an entity have been modified since the last sync, the system MUST present a non-destructive conflict resolution workflow.

### REQ-RIO-004-A: Conflict Detection & Flagging

GIVEN a pull-sync detects that both local and remote versions of an opleiding have been modified since lastSyncedAt  
WHEN this is detected  
THEN the system MUST create a rio-sync-event with eventType='conflict-detected'  
AND MUST mark the opleiding with a `_conflict_flag` in-memory indicator  
AND MUST NOT auto-overwrite either version.

### REQ-RIO-004-B: Side-by-Side Diff View

GIVEN conflicts are flagged  
WHEN the rio-beheerder opens `/admin/rio/reconciliation` and clicks on a conflict record  
THEN the system MUST display a comparison view with:
- **Local column**: Entity's current local values
- **Remote column**: Entity's current RIO values
- **Diff highlighting**: Field-by-field, changed fields highlighted (different background color)
- **Metadata**: Timestamp of last modification on each side, user who last modified local version (from audit trail).

### REQ-RIO-004-C: Resolution Action — Push Local

GIVEN the rio-beheerder views a conflict and clicks "Push scholiq-values naar RIO"  
WHEN this is confirmed  
THEN the system MUST call `RioSyncService.push()` with the local values  
AND MUST on success: set the local `rioStatus = 'synced'`, update `lastSyncedAt`  
AND MUST create a rio-sync-event with eventType='conflict-resolved', action='push-local'  
AND MUST update the conflict record in the UI to show "Resolved — scholiq values pushed".

### REQ-RIO-004-D: Resolution Action — Accept Remote

GIVEN the rio-beheerder clicks "Accepteer RIO-waarden"  
WHEN this is confirmed  
THEN the system MUST overwrite the local object's properties with the remote values (from the last pull-sync response)  
AND MUST set `rioStatus = 'synced'`, update `lastSyncedAt`  
AND MUST create a rio-sync-event with eventType='conflict-resolved', action='accept-remote'  
AND MUST show "Resolved — RIO values accepted".

### REQ-RIO-004-E: Resolution Action — Defer for Manual Review

GIVEN the rio-beheerder clicks "Later handmatig oplossen"  
WHEN this action is selected  
THEN the system MUST mark the conflict as `status='deferred'` in the rio-sync-event  
AND MUST keep both local and remote versions in an in-memory staging area for later review  
AND MUST remove the conflict from the "active" list in the UI but keep it accessible in a "Deferred conflicts" section.

## REQ-RIO-005: Erkenningsbesluiten (OCW Accreditation Decisions)

The system MUST enforce that HBO/WO/MBO programs have valid OCW accreditation decisions, and prevent program offerings without valid accreditation.

### REQ-RIO-005-A: Decision Entry & Validation

GIVEN a rio-beheerder creating a new HBO-bachelor opleiding  
WHEN they attempt to save without an erkenningsbesluit  
THEN the system MUST block the save with error "Erkenningsbesluit ontbreekt — voeg eerst een besluit toe aan de opleiding"  
AND MUST display a form field for decision details: besluitnummer, datumBesluit, geldigVan, geldigTot, PDF file upload.

GIVEN the rio-beheerder fills in decision details and uploads a PDF  
WHEN they save  
THEN the system MUST:
- Validate decision date format (YYYY-MM-DD)
- Validate geldigVan <= geldigTot
- Store PDF via `FileService` with classificatie='besluit-ocw'
- Save decision metadata to the opleiding.erkenningsbesluit object
- Create an audit trail entry linking the PDF to the opleiding.

### REQ-RIO-005-B: Validity Gate on Offering Creation

GIVEN an opleiding with erkenningsbesluit (geldigVan: 2024-03-15, geldigTot: 2029-03-14)  
WHEN a rio-beheerder attempts to create an aangeboden-opleiding with cohortStart='2030-09'  
THEN the system MUST block the action with error "Erkenningsbesluit verlopen voor deze cohort — geldig tot 2029-03-14"  
AND MUST prevent the aangeboden-opleiding from being created.

GIVEN cohortStart='2028-06'  
WHEN this is within the decision validity window (geldigVan <= cohortStart <= geldigTot)  
THEN the system MUST allow the aangeboden-opleiding to be created.

### REQ-RIO-005-C: Expiry Monitoring & Alerts

GIVEN a daily batch job runs  
WHEN an opleiding's erkenningsbesluit is found to expire:
- Within 60 days → notify rio-beheerder with level 'info': "Erkenningsbesluit voor [program] verloopt over 60 dagen — plan verlengingsaanvraag"
- Within 30 days → level 'warning': "30 dagen tot verlopen erkenningsbesluit"
- Within 14 days → level 'urgent': "14 dagen tot verlopen erkenningsbesluit"
- Already expired → level 'error': "Erkenningsbesluit voor [program] is vervallen"

THEN each notification includes a link to the opleiding detail page to review/extend the decision.

### REQ-RIO-005-D: Dashboard Red Flag for Expired Decisions

GIVEN a rio-beheerder views the RIO dashboard (`/admin/rio/overview`)  
WHEN one or more programs have expired accreditation  
THEN the system MUST highlight each program in red with a "Erkenning vervallen" badge  
AND MUST show a summary card: "3 programma's hebben verlopen erkenningen — actie vereist".

## REQ-RIO-006: CROHO/CREBO Code Validation

The system MUST validate CROHO codes (HBO/WO) and CREBO codes (MBO) in real-time against DUO's official registers, warn on inactive codes, and prevent offering inactive programs.

### REQ-RIO-006-A: Real-Time CROHO Validation

GIVEN a rio-beheerder creates a new HBO-bachelor opleiding and enters crohoCode='39029'  
WHEN they leave the field or click "Controleer CROHO"  
THEN the system MUST call `RioValidationService.validateCroho('39029')` asynchronously  
AND MUST query DUO's CROHO-register API via openconnector  
AND IF the code is valid AND active:
  - Display the official program name from CROHO: "Applied Computer Science (Informatica)"
  - Set a green checkmark
THEN IF the code is invalid or inactive:
  - Display a warning: "CROHO-code 39029 is niet actief. Huidige status: [inactive/delisted]"
  - Suggest alternatives: "Vergelijkbare programma's: [list]"
  - BLOCK offering creation with this code (warning is non-blocking for edit, blocking for new offering).

### REQ-RIO-006-B: CREBO Validation for MBO

GIVEN a rio-beheerder enters crebo='99531|0000' for an MBO-4 program  
WHEN the field is validated  
THEN the system MUST call `RioValidationService.validateCrebo('99531', '0000')` (qualification code + dossier number)  
AND MUST query SBB kwalificatiedossier-register via openconnector  
AND IF valid: show official qualification name ("Verpleegkundige")  
AND IF inactive/retired: show warning with transition guidance.

### REQ-RIO-006-C: Batch Check on Daily Pull

GIVEN the scheduled daily pull-sync at 03:00  
WHEN it fetches all remote opleiding records for the bestuursnummer  
THEN for each record with a CROHO/CREBO code, the system MUST re-validate against current DUO registers  
AND IF a code has become inactive since the last check:
  - Mark the opleiding with `_code_retired_flag`
  - Create a rio-sync-event with eventType='code-retired', payload=[old-code], response=[dio-status]
  - Notify rio-beheerder: "Kwalificatie CREBO 99531 is vervallen — [program] kan niet meer aangeboden worden"
  - BLOCK new inschrijvingen (existing inschrijvingen retain rights).

## REQ-RIO-007: Vestiging (Location) & BRIN Management

The system MUST support multi-location operations with BRIN volgnummering for PO/VO, BAG address validation, and safe deactivation.

### REQ-RIO-007-A: Location Creation with BRIN Suggestion

GIVEN a rio-beheerder opens the vestigingen-tab on an onderwijsaanbieder (PO sector)  
WHEN they click "Voeg vestiging toe"  
THEN the system MUST:
- Display a form for vestigingsgegevens (naam, bezoekadres, contactperson)
- Auto-calculate next available BRIN volgnummer: scan existing vestigingen, suggest next (e.g., if 00 and 01 exist, suggest 02)
- REQUIRE BAG address validation: user enters street/number/postcode, system calls openconnector BAG-lookup, validates address exists, returns municipality/official address form
- ON SUCCESS: create vestiging with the suggested volgnummer, set actief=true, mark rioStatus='local-only'.

### REQ-RIO-007-B: Address Validation Against BAG

GIVEN address input for a new vestiging: "Gasthuisplein 2a, 8011 ZA, Zwolle"  
WHEN validated  
THEN the system MUST:
- Call openconnector BAG-lookup service with street/number/postcode
- Validate the address exists in BAG
- Return: official address form, gemeente code, coordinates
- IF address not found: reject with "Adres niet gevonden in BAG — controleer postcode en huisnummer"
- IF ambiguous: show multiple matches, user selects one
- SAVE the BAG-validated address data.

### REQ-RIO-007-C: Location Deactivation Safety Gate

GIVEN a rio-beheerder attempts to set vestiging.actief=false  
WHEN the system checks conditions  
THEN IF there are active aangeboden-opleidingen on this location:
  - BLOCK deactivation with message "Kan vestiging niet deactiveren — volgende aanbiedingen draaien nog: [list]"
  - Link to the aangeboden-opleiding records
  - Suggest: "Pas deze aanbiedingen aan naar een andere vestiging voordat je deze sluit"
THEN IF there are active enrolment records (BRON) on this location:
  - BLOCK deactivation with message "Nog [N] leerlingen ingeschreven op deze vestiging"
  - Show list of students, suggest transition to another location.

## REQ-RIO-008: Program Offering & Cohort Management

The system MUST support multiple offerings per program (varying by location, modality, cohort) and semi-automated cohort-year generation.

### REQ-RIO-008-A: Offering Creation with Modality & Cohort

GIVEN a rio-beheerder opens an opleiding and clicks "Voeg aangeboden-opleiding toe"  
WHEN they select:
- Vestiging: "Emmen Campus" (vestigingsnummer 01)
- Modaliteit: "BOL"
- Cohort: "2026-09" → "2028-09"
THEN the system MUST:
- Check that the opleiding has a valid erkenningsbesluit for cohortStart=2026-09
- Create the aangeboden-opleiding record with rioStatus='local-only', lastSyncedAt=null
- Auto-assign instroommomenten=[2026-09-01] (standard intake date for cohort start month)
- Trigger push-sync to RIO (via webhook); on success: update rioStatus='synced', save rioAangebodenId.

### REQ-RIO-008-B: Cohort Year Generation (Annual, Semi-Automated)

GIVEN the scheduled job runs on 1 May each year  
WHEN it scans all active aangeboden-opleidingen  
THEN for each active record, the system MUST:
- Create a "proposed new cohort" aangeboden-opleiding with:
  - Same vestiging, modaliteit, voertaal as the prior cohort
  - cohortStart = last_cohort_start + 1 year
  - cohortEinde = last_cohort_einde + 1 year
  - Status: 'voorgesteld' (proposed, not yet active)
  - rioStatus='local-only' (awaiting rio-beheerder confirmation)
- Create a notification: "Nieuwe cohort voorgesteld voor [program] [modality] [location] — controleer details en bevestig"
- Show the proposed cohort in a "Pending cohorts" view in the UI.

GIVEN the rio-beheerder reviews the proposed cohort in the UI  
WHEN they click "Bevestig cohort" (optionally with edits)  
THEN the system MUST:
- Persist the aangeboden-opleiding with actief=true
- Trigger push-sync to RIO
- Move it from "Pending" to "Active" cohorts.

### REQ-RIO-008-C: Cohort Inactivity Cleanup

GIVEN a scheduled job checks aangeboden-opleidingen with cohortEinde < today  
WHEN an ended cohort has no active enrolments (BRON records)  
THEN the system MUST:
- Flag the aangeboden-opleiding as eligible for deactivation
- Create a notification to rio-beheerder: "Cohort [program] [modality] [location] [cohortStart-cohortEinde] kan worden inactief gezet"
- On rio-beheerder confirmation: set actief=false, trigger delete-push to RIO (PATCH endpoint with deletion flag).

## REQ-RIO-009: API Rate-Limiting & Circuit-Breaker

The system MUST respect DUO's 100 req/min rate limit, queue pushes efficiently, and activate a circuit-breaker on repeated failures.

### REQ-RIO-009-A: Queue & Throttling

GIVEN a rio-beheerder bulk-imports 200 new cohorts  
WHEN the push-sync job processes them  
THEN the system MUST:
- Queue all 200 pushes
- Throttle to max 90 req/min (10% headroom below DUO's 100 req/min limit)
- Show a progress bar in the UI: "Processing 200 uploads — 45 complete, 155 remaining, ETA 2 minutes"
- Complete all pushes within ~2.5 minutes.

### REQ-RIO-009-B: Circuit-Breaker Activation

GIVEN 5 consecutive API errors (timeout or 5xx) from DUO RIO-API  
WHEN the circuit-breaker threshold is hit  
THEN the system MUST:
- PAUSE all pending pushes (queue remains, not discarded)
- Show a banner in the UI (top, red): "RIO-API tijdelijk onbereikbaar — synchronisatie onderbroken. Opnieuw proberen over [5 minutes]"
- Log the event in rio-sync-event with eventType='circuit-breaker-activated'
- Automatically retry after 5 minutes; on success, resume queue processing.

## REQ-RIO-010: Sync Audit Trail

All synchronization actions MUST be logged in the `rio-sync-event` immutable schema for compliance, debugging, and reconciliation.

### REQ-RIO-010-A: Event Logging

Every synchronization action MUST create a rio-sync-event record with:
- **eventType**: create|update|delete|fetch|conflict-detected|conflict-resolved|circuit-breaker-activated|code-retired
- **entityType**: bestuur|aanbieder|opleiding|aangeboden-opleiding
- **entityRef**: OpenRegister reference to the entity involved
- **direction**: push|pull (or NA for circuit-breaker)
- **triggeredBy**: user|schedule|webhook
- **payload**: JSON of data sent to RIO-API
- **response**: JSON response from RIO-API (or error response)
- **success**: bool
- **errorCode**: HTTP status or internal code (if success=false)
- **errorMessage**: Human-readable error description
- **timestamp**: ISO 8601 timestamp

### REQ-RIO-010-B: Audit Trail UI

GIVEN the rio-beheerder opens `/admin/rio/sync-log`  
WHEN the page loads  
THEN the system MUST display a searchable, filterable table of rio-sync-events:
- **Filters**: entityType, eventType, success/failure, date range
- **Columns**: Timestamp, Entity (linked preview), Event, Direction, User, Status (green checkmark / red X)
- **Detail view**: Clicking a row shows full payload/response JSON in a code viewer, with syntax highlighting.

## REQ-RIO-011: Public Read-Only API

The system MUST expose a public read-only GraphQL endpoint for marketing/website integrations, with no sensitive data.

### REQ-RIO-011-A: GraphQL Endpoint for Public Data

The system MUST expose `/api/public/rio` as a public GraphQL query endpoint returning:
- opleiding: {name, internalCode, sector, opleidingsniveau, taal, aanvangsdatum, einddatum}
- aangeboden-opleiding: {modaliteit, cohortStart, cohortEinde, voertaal}
- aanbieder: {name, sector}
- NO decision PDFs, NO bestuursnummer, NO KVK/RSIN, NO contact details beyond sector/name.

### REQ-RIO-011-B: Public API Rate-Limit

GIVEN a public request from IP 203.0.113.45  
WHEN the endpoint is called  
THEN the system MUST enforce rate-limiting per IP: 100 req/min  
AND MUST return HTTP 429 (Too Many Requests) when exceeded  
AND MUST set cache headers: `Cache-Control: public, max-age=3600` (1 hour).

### REQ-RIO-011-C: Authentication Not Required

GIVEN a public request without any authentication token  
WHEN the request hits `/api/public/rio`  
THEN the system MUST accept it  
AND MUST return only public fields (no auth error).

## REQ-RIO-012: Multi-Bestuur & Role Scoping

The system MUST support multi-bestuur organizations where users are authorized for specific besturen and can only manage their own.

### REQ-RIO-012-A: Role Scoping

GIVEN a user with role `rio-beheerder` scoped to bestuursnummer `BV-00456`  
WHEN they access `/admin/rio/aanbieders`  
THEN the system MUST filter to show only aanbieders where `ref.bestuursnummer = BV-00456`  
AND MUST show "Filtered to: ROC Drenthe (BV-00456)" in the header  
AND MUST prevent create/edit operations for aanbieders from other besturen.

### REQ-RIO-012-B: Cross-Bestuur Reporting (Admin Only)

GIVEN a super-admin user  
WHEN they access a dashboard showing all besturen  
THEN the system MUST show aggregated stats (total programs, total locations, sync status, pending conflicts) per bestuur  
AND MUST track this access in audit trail.

## REQ-RIO-013: Notifications & Alerts

The system MUST send timely notifications to rio-beheerders for sync failures, compliance warnings, and required actions.

### REQ-RIO-013-A: Notification Types

The system MUST support notifications for:
- **Sync Failure**: API error during push/pull → "RIO-sync mislukt voor [entity]: [error]"
- **Conflict Detected**: Drift found → "Conflict in RIO-sync for [entity] — review in reconciliation view"
- **Accreditation Expiry**: 60/30/14 days before expiry → "Erkenningsbesluit [program] verloopt over [N] dagen"
- **Code Retired**: CROHO/CREBO no longer active → "Kwalificatie [code] is vervallen — [program] kan niet meer aangeboden worden"
- **Circuit-Breaker**: API unavailable → "RIO-API onbereikbaar — pushes paused, retry in 5 min"
- **Pending Cohorts**: New cohorts proposed → "Nieuwe cohort voorgesteld — bekijk en bevestig"

### REQ-RIO-013-B: Notification Delivery

Notifications MUST be delivered via scholiq's `NotificationService`:
- In-app badge on `/admin/rio` menu item (count of unread alerts)
- Nextcloud notification panel
- Email (if configured for rio-beheerder role)
- Optionally: Slack/Teams webhook (if integrated in the institution's scholiq instance).

