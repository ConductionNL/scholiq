## Context

RIO (Register Instellingen en Opleidingen) is DUO's authoritative registry of educational institutions, programs, and their offerings in the Netherlands. It is the source of truth for program accreditation (CROHO for HBO/WO, CREBO for MBO, BRIN for PO/VO), and student registration systems (like BRON) depend on valid RIO-IDs to record enrolments.

Scholiq currently maintains a local `opleiding`/`programme` schema for curriculum management but has no connection to RIO. Institutions using scholiq must separately maintain RIO records in DUO's web portal or external spreadsheets, leading to:
- Data drift (changes in scholiq not reflected in RIO and vice versa)
- Compliance risk (programs offered without active accreditation)
- Double maintenance burden (rio-beheerders maintain both scholiq and DUO portal)
- Lost audit trail (no record of who changed what and when)

DUO publishes the RIO REST API (OpenAPI 3.0 spec) with endpoints for the four core entities (bestuur, aanbieder, opleiding, aangeboden-opleiding). Scholiq will become the system-of-record for institutions; RIO is the public registry. The synchronization pattern is:
- **Push** (scholiq → RIO): Triggered immediately when a rio-beheerder saves a change (via OpenRegister object-update webhook).
- **Pull** (RIO → scholiq): Scheduled daily at 03:00 UTC and on-demand via UI button; detects drift by comparing canonical hashes.
- **Conflict Resolution**: If both local and RIO versions have been modified since last sync, the rio-beheerder is presented with a side-by-side diff and can choose to push local, accept remote, or defer.

**Four core RIO entities:**
1. **onderwijsbestuur** (educational governance body): A juridical entity (usually a stichting or association) that operates one or more schools/institutions. Fields: name, DUO bestuursnummer (format `BV-12345`), KVK/RSIN, legal form, address, contact, funding status (bekostigd/niet), actief, rioStatus (synced/drift/local-only/remote-only), lastSyncedAt.

2. **onderwijsaanbieder** (educational provider/school): The actual institution (school, ROC, hogeschool, universiteit) that offers programs. One bestuur may have multiple aanbieders. Fields: ref to bestuur, name, aanbiedersnummer, BRIN (4-char for PO/VO), instellingscode (5-digit for HBO/WO), sector (po|vo|mbo|hbo|wo), aanbiedersType, vestigingen (array of location objects with vestigingsnummer, address, actief), rioStatus, lastSyncedAt.

3. **opleiding** (program/qualification): An educational program offered at the provider level. Fields: ref to aanbieder, name, internalCode (institution's own code), CROHO (HBO/WO), CREBO (MBO), ISCED-2011 level, studielast, language, taal (nl|en|mixed), actief, aanvangsdatum (start date), einddatum (end date, null if no end), erkenningsbesluit (decision object: besluitnummer, datumBesluit, geldigVan, geldigTot, pdfUrl), rioOpleidingId (DUO-assigned), rioStatus, lastSyncedAt.

4. **aangeboden-opleiding** (program offering): The concrete expression of how a program is offered: program + location + modality + cohort. Fields: ref to opleiding, vestigingsnummer, modaliteit (bol|bbl|voltijd|deeltijd|duaal|afstand), cohortStart (yyyy-mm), cohortEinde, instroommomenten (dates per year), voertaal (language of instruction), actief, rioAangebodenId, rioStatus, lastSyncedAt.

5. **rio-sync-event** (audit log): Immutable record of every sync action. Fields: eventType (create|update|delete|fetch|conflict-detected|conflict-resolved), entityType, entityRef, direction (push|pull), triggeredBy (user|schedule|webhook), payload, response, success (bool), errorCode, errorMessage, timestamp.

**Integration Points:**
- **openconnector**: REST client for DUO RIO-API with rate-limiter per openconnector ADR-007.
- **openregister**: Schemas + object CRUD + audit trail + webhooks (for triggering pushes).
- **docudesk**: Storage of erkenningsbesluiten PDFs with clasificatie `besluit-ocw`.
- **hydra**: PKIoverheid certificate management via shared spec.
- **scholiq-bron-aanlevering** (sibling spec): Uses RIO vestigingsnummers and aangeboden-opleiding IDs in BRON submissions; validation ensures no orphaned references.

**Constraints & Standards:**
- RIO-PvE (Programma van Eisen) published by edustandaard.nl; DUO updates annually.
- CROHO register: publiek via croho.nl; CDHO approval required for new HBO/WO programs.
- CREBO + qualificatie-dossiers: SBB (Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven); valid codes required for MBO.
- BAG (Basisregistratie Adressen): Address validation for vestigingen.
- NVAO & CDHO: Accreditation bodies; OCW issues erkenningsbesluiten.
- Rate limits: DUO RIO-API 100 req/min per certificate (public spec).
- WHW, WEB, WPO, WVO: Legal basis for program registration.

## Goals / Non-Goals

**Goals:**
- Scholiq becomes the system-of-record for RIO data; DUO portal becomes read-only for reference.
- Push-sync: All local changes propagate to RIO within minutes (event-driven).
- Pull-sync: Daily automated check at 03:00 UTC; on-demand button in UI.
- Drift detection: Identify divergences between scholiq and RIO; prevent silent data corruption.
- Conflict resolution: Non-destructive workflows; rio-beheerder always has visibility and choice.
- Code validation: Real-time CROHO/CREBO lookups; warn on inactive or expired codes.
- Accreditation enforcement: Programs cannot be offered without valid OCW decision for the cohort start date.
- Multi-bestuur support: Users can manage multiple besturen (scoped by role); audit trail per bestuur.
- Public read-only API: Marketing/website teams can fetch current program list via GraphQL; no sensitive fields.

**Non-Goals:**
- Full BRON integration (handled by sibling spec).
- Translation of RIO data or content authoring for marketing pages (out-of-scope; marketing uses public API).
- Custom notification channels (use scholiq's standard NotificationService).
- Real-time RIO data streaming (daily + on-demand is sufficient for compliance).
- Support for legacy UWLR (XML-based) exchange protocol (RIO-API REST/JSON only).

## Decisions

**D1: Push Strategy — Event-Driven on Object-Update**
Whenever a rio-beheerder saves a bestuur/aanbieder/opleiding/aangeboden-opleiding object in scholiq, OpenRegister's object-update webhook triggers an immediate RIO-API POST/PATCH. This ensures minimal delay between scholiq and RIO state. Alternative (polling every N minutes) rejected: slower, higher API load, worse UX.

**D2: Pull Strategy — Scheduled Daily + On-Demand Button**
Daily pull at 03:00 UTC (low traffic) plus manual "Sync with RIO now" button in the reconciliation view. Offers a balance between freshness and API cost. Alternative (real-time pull on every view) rejected: excessive API usage, not necessary for compliance.

**D3: Drift Detection via Canonical Hash**
Each entity gets a `canonical_hash = hash(sorted_json_string)` computed on both local and remote state. Byte-level comparison avoids false-positives from array-element ordering or whitespace normalization. Simpler and more reliable than field-by-field comparison.

**D4: Conflict Resolution — Always Requires Operator Choice**
When both local and remote versions have been modified since last sync, the system never auto-overwrites. Instead, it flags the conflict in `rio-sync-event` and presents the rio-beheerder with a reconciliation UI showing both versions with timestamps and diff highlight. User must explicitly choose: "push local", "accept remote", or "defer for manual resolution". This prevents silent data loss.

**D5: Modality & Cohort — Separate Aangeboden-Opleiding per Combination**
A single opleiding (e.g., "MBO Verpleegkundige niv 4") can be offered in multiple ways: BOL on location A cohort 2026-09, BBL on location B cohort 2026-09, voltijd on location A cohort 2027-09, etc. Each combination is a separate `aangeboden-opleiding` record with its own RIO-ID, rioStatus, and sync history. Simplifies cohort generation and allows fine-grained drift detection.

**D6: Cohort Year Generation — Semi-Automated with Manual Review**
On 1 May each year, a scheduled job creates "proposed new cohort" records for all active aangeboden-opleidingen, copying from the prior cohort but with cohortStart/cohortEinde shifted forward one year and status set to "voorgesteld" (proposed). The rio-beheerder reviews proposed cohorts, makes adjustments (e.g., new location, changed modality), and confirms; confirmation creates the new cohort and triggers a push-sync. This avoids duplicate-burden (auto-creating exact copies) while preventing manual overhead for hundreds of cohorts.

**D7: Erkenningsbesluit as Validation Gate**
For HBO/WO/MBO programs, a program cannot have an active aangeboden-opleiding unless it has a valid erkenningsbesluit with `geldigVan <= cohort-start-date <= geldigTot`. The UI enforces this: attempt to create an offering without a valid decision shows an error with a link to the opleiding detail page to add a decision. This ensures compliance with accreditation requirements.

**D8: Rate-Limiting via OpenConnector Circuit-Breaker**
The openconnector ADR-007 rate-limiter is wired to scholiq's RIO client. When scholiq hits the DUO API rate limit (100 req/min), openconnector throttles the queue to 90 req/min (10% headroom), and scholiq's circuit-breaker pattern detects 5 consecutive errors (timeout/5xx) and pauses all pushes for 5 minutes, showing a banner in the UI. Circuit-breaker state is logged in `rio-sync-event` for audit.

**D9: Vestiging Deactivation Safeguards**
A location (vestiging) can be marked inactive only if:
1. No active enrolments (BRON records) reference this location, AND
2. No active aangeboden-opleidingen reference this location.
The UI checks both and blocks deactivation with a list of affected students/offerings, suggesting transitions to another location. This prevents orphaning data.

**D10: Seed Data — Real Dutch Examples**
Seed objects use realistic Dutch values: real street names, valid postcodes (pattern `[1-9][0-9]{3}[A-Z]{2}`), plausible bestuursnummers (`BV-12345`), and actual sector/modality combinations. Three example besturen (PO association, MBO ROC, HBO hogeschool) with diverse program portfolios.

## Risks / Trade-Offs

- **API Availability**: If DUO RIO-API is down, pushes queue and retry; pull-sync fails silently with a log entry (no exception to user). Mitigation: circuit-breaker banner + notifications to rio-beheerder.

- **Accreditation Expiry**: The system checks accreditation validity during offering creation and daily via a batch job. Programs with expiring accreditation (60/30/14 day warnings) notify rio-beheerder. But if a decision expires while scholiq is offline, the offering remains active locally until the next pull-sync—minimal risk window (1–2 days). Mitigation: monitor NotificationService; rio-beheerder can manually deactivate offerings.

- **CROHO/CREBO Code Retirement**: If a code becomes inactive in DUO's register (qualification retired, program delisted), the daily pull-sync flags the local record with a `verlopen-kwalificatie` marker and prevents new inschrijvingen. Existing inschrijvingen retain rights (no data loss). Mitigation: rio-beheerder is notified and can plan transition to a new qualification.

- **Multi-Tenant Sync**: If a bestuur has multiple tenants (e.g., a large rooster with regional offices), each tenant has independent RIO-sync jobs. Collision risk: two tenants both push changes to the same entity. Mitigation: RIO-API is authoritative per bestuursnummer; last-write-wins. Audit trail logs which tenant pushed when. Recommendation: use bestuur-level scoping to assign one tenant per bestuur if possible.

- **BRIN Volgnummer Collision**: When suggesting the next available BRIN volgnummer for a new location, the system reads the aanbieder's current vestigingen list. If two rio-beheerders simultaneously create new locations, both might get the same number. Mitigation: RIO-API enforces uniqueness and rejects duplicates; scholiq's push-sync fails with a "volgnummer taken" error; rio-beheerder is prompted to increment and retry. Audit log captures the conflict.

## Migration Plan

1. **Register RIO schemas** via repair step: bestuur, aanbieder, opleiding, aangeboden-opleiding, rio-sync-event. Include seed data (3–5 objects per schema, realistic Dutch values).

2. **Extend existing opleiding/programme schema** (backward-compatible): Add optional fields `rioOpleidingId`, `rioStatus`, `lastSyncedAt`. Existing rows get null values; no data loss.

3. **Configure openconnector** as REST data source for DUO RIO-API (preproduction and production endpoints separately); wire PKIoverheid certificate via hydra secrets.

4. **Implement RIO sync client** in scholiq:
   - `RioSyncService` (push/pull/conflict-detect methods)
   - `RioValidationService` (CROHO/CREBO lookups, accreditation checks, BAG address validation)
   - `RioCircuitBreakerService` (rate-limit + retry logic)
   - Event listeners for OpenRegister object-update webhooks → trigger push-sync

5. **Frontend routes & components**:
   - `/admin/rio/besturen`, `/admin/rio/aanbieders`, `/admin/rio/opleidingen`, `/admin/rio/aangeboden-opleidingen` — standard CRUD list/detail pages (auto-generated via CnDetailPage + CnIndexPage)
   - `/admin/rio/reconciliation` — custom component to display drift conflicts with side-by-side diffs
   - `/admin/rio/sync-log` — read-only audit trail table (searchable, filterable by entityType/eventType/timestamp)

6. **Public GraphQL endpoint** `/api/public/rio/*` — read-only schema subset (opleiding name/code, aangeboden-opleiding modal/cohort/vestiging, no address details, no decision PDFs). Rate-limit by IP (100 req/min). Cache headers: 1 hour.

7. **Background jobs**:
   - Daily pull-sync at 03:00 UTC
   - Cohort-generation on 1 May each year
   - Accreditation-expiry check (daily)
   - CROHO/CREBO-code-retirement check (daily)

8. **Notifications**:
   - Sync failure (API error, 5xx, timeout)
   - Conflict detected (diff found during pull-sync)
   - Accreditation expiry (60/30/14 days)
   - Code retired (CROHO/CREBO inactive)
   - Circuit-breaker activated/recovered

9. **Database migrations**: None required for existing data; RIO tables are new.

10. **Build & test**:
    - Unit tests: sync logic, validation, conflict detection
    - Integration tests: mock RIO-API responses (Guzzle mock), verify push/pull/conflict behavior
    - Functional tests: UI workflow (create opleiding → auto-assign rioStatus "local-only" → push → verify rioStatus "synced")

## Seed Data

**Onderwijsbestuur (Educational Governance Body)**

1. **Stichting Onderwijs Gemeente Zwolle**
   - naam: "Stichting Onderwijs Gemeente Zwolle"
   - bestuursnummer: "BV-00123"
   - kvkNummer: "12345678"
   - rsin: "123456789"
   - juridischeVorm: "stichting"
   - vestigingsadres: {straat: "Westmeadenlaan", huisnummer: "1", postcode: "8011 BZ", plaats: "Zwolle", land: "NL"}
   - contact: {email: "bestuur@zwolle-onderwijs.nl", telefoon: "038-1234567", website: "https://zwolle-onderwijs.nl"}
   - bekostigingsstatus: "bekostigd"
   - actief: true
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-20T14:30:00Z"

2. **ROC Drenthe**
   - naam: "ROC Drenthe"
   - bestuursnummer: "BV-00456"
   - kvkNummer: "23456789"
   - rsin: "234567890"
   - juridischeVorm: "stichting"
   - vestigingsadres: {straat: "Pechelbulk", huisnummer: "45", postcode: "9405 TA", plaats: "Assen", land: "NL"}
   - contact: {email: "info@roc-drenthe.nl", telefoon: "050-3456789", website: "https://roc-drenthe.nl"}
   - bekostigingsstatus: "bekostigd"
   - actief: true
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-19T03:15:00Z"

3. **Hogeschool Arnhem Nijmegen Stichting**
   - naam: "Hogeschool Arnhem Nijmegen Stichting"
   - bestuursnummer: "BV-00789"
   - kvkNummer: "34567890"
   - rsin: "345678901"
   - juridischeVorm: "stichting"
   - vestigingsadres: {straat: "Rijnstraat", huisnummer: "200", postcode: "6811 ER", plaats: "Arnhem", land: "NL"}
   - contact: {email: "bestuur@han.nl", telefoon: "026-2222222", website: "https://www.han.nl"}
   - bekostigingsstatus: "bekostigd"
   - actief: true
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-21T09:45:00Z"

**Onderwijsaanbieder (Educational Provider)**

1. **Montessori Basisschool De Kiem** (PO)
   - bestuur: ref to "Stichting Onderwijs Gemeente Zwolle"
   - naam: "Montessori Basisschool De Kiem"
   - aanbiedersnummer: "AB-00001"
   - brin: "29KS"
   - sector: "po"
   - aanbiedersType: "bekostigde-instelling-po"
   - vestigingen: [{vestigingsnummer: "00", naam: "Hoofdvestiging", bezoekadres: {straat: "Gasthuisplein", huisnummer: "2a", postcode: "8011 ZA", plaats: "Zwolle", land: "NL"}, actief: true}]
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-20T14:30:00Z"

2. **ROC Drenthe locatie Emmen** (MBO)
   - bestuur: ref to "ROC Drenthe"
   - naam: "ROC Drenthe locatie Emmen"
   - aanbiedersnummer: "AB-00002"
   - brin: "19SK"
   - sector: "mbo"
   - aanbiedersType: "bekostigde-instelling-mbo"
   - vestigingen: [{vestigingsnummer: "01", naam: "Emmen Campus", bezoekadres: {straat: "Ellermanstraat", huisnummer: "1", postcode: "7812 ER", plaats: "Emmen", land: "NL"}, actief: true}]
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-19T03:15:00Z"

3. **HAN Bachelor Informatica** (HBO)
   - bestuur: ref to "Hogeschool Arnhem Nijmegen Stichting"
   - naam: "Hogeschool Arnhem Nijmegen"
   - aanbiedersnummer: "AB-00003"
   - instellingscode: "13039"
   - sector: "hbo"
   - aanbiedersType: "bekostigde-instelling-hbo"
   - vestigingen: [{vestigingsnummer: "00", naam: "Arnhem Campus", bezoekadres: {straat: "Rijnstraat", huisnummer: "200", postcode: "6811 ER", plaats: "Arnhem", land: "NL"}, actief: true}, {vestigingsnummer: "01", naam: "Nijmegen Campus", bezoekadres: {straat: "Hospitalplein", huisnummer: "1", postcode: "6525 GC", plaats: "Nijmegen", land: "NL"}, actief: true}]
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-21T09:45:00Z"

**Opleiding (Program)**

1. **Verpleegkundige MBO-4**
   - aanbieder: ref to "ROC Drenthe locatie Emmen"
   - naam: "Verpleegkundige niveau 4"
   - internalCode: "VPK-MBO4-2026"
   - crebo: "99531|0000"
   - isced: "3531"
   - opleidingsniveau: "mbo-4"
   - studielast: "6000"
   - taal: "nl"
   - actief: true
   - aanvangsdatum: "2026-09-01"
   - einddatum: null
   - erkenningsbesluit: {besluitnummer: "CDHO/2024-VPK-001", datumBesluit: "2024-03-15", geldigVan: "2024-03-15", geldigTot: "2029-03-14", pdfUrl: "https://docudesk.localhost/files/besluit-vpk-2024.pdf"}
   - rioOpleidingId: "RIO-OP-123456"
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-19T03:15:00Z"

2. **Informatica HBO-Bachelor**
   - aanbieder: ref to "HAN Bachelor Informatica"
   - naam: "Applied Computer Science (Informatica)"
   - internalCode: "HAN-CS-BA-2026"
   - crohoCode: "39029"
   - isced: "0615"
   - opleidingsniveau: "hbo-bachelor"
   - studielast: "180"
   - taal: "en"
   - actief: true
   - aanvangsdatum: "2026-09-01"
   - einddatum: null
   - erkenningsbesluit: {besluitnummer: "NVAO-HAN/2023-CS-BA-001", datumBesluit: "2023-06-20", geldigVan: "2023-06-20", geldigTot: "2029-06-19", pdfUrl: "https://docudesk.localhost/files/accreditation-nvao-2023.pdf"}
   - rioOpleidingId: "RIO-OP-654321"
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-21T09:45:00Z"

3. **Basisonderwijs** (PO)
   - aanbieder: ref to "Montessori Basisschool De Kiem"
   - naam: "Basisonderwijs"
   - internalCode: "KIEM-PO-BASE"
   - isced: "0111"
   - opleidingsniveau: "po-basis"
   - taal: "nl"
   - actief: true
   - aanvangsdatum: "2026-08-17"
   - einddatum: null
   - erkenningsbesluit: null (PO does not require OCW decision)
   - rioStatus: "local-only"
   - lastSyncedAt: null

**Aangeboden-Opleiding (Program Offering)**

1. **Verpleegkundige MBO-4, BOL, 2026-cohort**
   - opleiding: ref to "Verpleegkundige MBO-4"
   - vestigingsnummer: "01"
   - modaliteit: "bol"
   - cohortStart: "2026-09"
   - cohortEinde: "2028-09"
   - instroommomenten: ["2026-09-01"]
   - voertaal: "nl"
   - actief: true
   - rioAangebodenId: "RIO-AAN-111111"
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-19T03:15:00Z"

2. **Informatica HBO-Bachelor, Arnhem, 2026-cohort**
   - opleiding: ref to "Informatica HBO-Bachelor"
   - vestigingsnummer: "00"
   - modaliteit: "voltijd"
   - cohortStart: "2026-09"
   - cohortEinde: "2029-06"
   - instroommomenten: ["2026-09-01"]
   - voertaal: "en"
   - actief: true
   - rioAangebodenId: "RIO-AAN-222222"
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-21T09:45:00Z"

3. **Informatica HBO-Bachelor, Nijmegen, 2026-cohort**
   - opleiding: ref to "Informatica HBO-Bachelor"
   - vestigingsnummer: "01"
   - modaliteit: "deeltijd"
   - cohortStart: "2026-09"
   - cohortEinde: "2029-06"
   - instroommomenten: ["2026-09-01"]
   - voertaal: "en"
   - actief: true
   - rioAangebodenId: "RIO-AAN-333333"
   - rioStatus: "synced"
   - lastSyncedAt: "2026-05-21T09:45:00Z"

4. **Basisonderwijs groep 3, Zwolle, 2026-cohort**
   - opleiding: ref to "Basisonderwijs"
   - vestigingsnummer: "00"
   - modaliteit: "voltijd"
   - cohortStart: "2026-08"
   - cohortEinde: "2027-07"
   - instroommomenten: ["2026-08-17"]
   - voertaal: "nl"
   - actief: true
   - rioStatus: "local-only"
   - lastSyncedAt: null

## Reuse Analysis

This change leverages the following platform-provided capabilities (ADR-001 data-layer):

- **OpenRegister CRUD**: All RIO entity operations use `ObjectService.saveObject()`, `deleteObject()`, `findAll()` — no custom entity/mapper code needed.
- **Standard forms & dialogs**: List/detail pages use `CnIndexPage`, `CnDetailPage`, `CnDetailGrid` — auto-generated from schema.
- **Audit trail**: `AuditTrailService` automatically tracks all changes to RIO objects; no custom logging.
- **Authorization**: Role-based access control via `AuthorizationService` and `PropertyRbacHandler` (rio-beheerder role).
- **Webhooks**: `WebhookService` enables external integrations; scholiq subscribes to object-update events.
- **Notifications**: `NotificationService` for sync alerts and compliance warnings.
- **File management**: `FileService` for erkenningsbesluit PDF uploads/downloads.
- **Search/filtering**: `IndexService` + `CnFilterBar` for finding besturen/aanbieders/opleidingen.
- **Import/export**: `ImportService` + `ExportService` for CSV bulk-import of RIO data during migration.

Custom code required:
- **RioSyncService**: Push/pull/conflict-detect orchestration against DUO REST API.
- **RioValidationService**: CROHO/CREBO/BAG real-time lookups via openconnector.
- **RioCircuitBreakerService**: Rate-limit + retry wrapper.
- **Reconciliation UI component**: Side-by-side diff display (not a standard CRUD operation).
- **Background jobs**: Scheduled pull-sync, cohort-generation, accreditation-expiry checks.
- **Public GraphQL endpoint**: Custom resolver for read-only API.

## Open Questions

None — all decisions documented above with clear implementation path.
