## Why

Scholiq manages student enrolments and educational program portfolios, but lacks integration with RIO (Register Instellingen en Opleidingen), the nationwide register of educational institutions and their offerings maintained by DUO. Without RIO synchronization, educational institutions cannot register new programs, cannot update location structures or accreditation status, and risk offering courses that lack official recognition — a legal compliance violation. RIO registration is mandatory for MBO, HBO, and WO institutions, and core infrastructure for PO/VO (BRIN administration). Currently, institutions maintain RIO registration separately via DUO's portal or external spreadsheets, creating data drift, duplicate maintenance, and compliance risk.

This spec adds RIO synchronization to scholiq, enabling institutions to manage their educational program portfolio (institutions, programs, locations, program offerings, cohorts) in one place, with automatic push/pull sync to DUO's official RIO registry. Drift detection and reconciliation workflows keep local and remote data aligned.

## What Changes

**New Capabilities:**

- **RIO Core Schema Management**: First-class support for the four RIO core entities (`onderwijsbestuur`, `onderwijsaanbieder`, `opleiding`, `aangeboden-opleiding`) as OpenRegister schemas with full CRUD UI, role-based access control, and audit logging.

- **Bi-Directional Synchronization**: Push new/updated programs to RIO immediately (event-driven via OpenRegister change hooks); pull RIO state daily at 03:00 UTC and on-demand to detect and reconcile drift.

- **CROHO/CREBO Validation**: Real-time validation of program codes (CROHO for HBO/WO, CREBO for MBO) against DUO's official registers; auto-population of official program names and warnings for inactive or expired codes.

- **Accreditation Decision Management**: Per-program storage of OCW erkenningsbesluiten (approval decisions) with decision number, decision date, validity period, and PDF attachment via docudesk. Programs cannot be offered without valid accreditation.

- **Facility Structure & BRIN Numbering**: Full vestiging (location) management with automatic next-available BRIN volgnummer suggestion for PO/VO, address validation against BAG, and deactivation safeguards.

- **Program Offering & Cohort Management**: Support for multiple offerings per program (program + location + modality + cohort), with semi-automated cohort-year generation and inactivity cleanup. Tracks instroom (enrollment periods) per cohort.

- **Drift Detection & Reconciliation UI**: Automated daily pull-sync detects mismatches between local and RIO state. Dedicated reconciliation view shows side-by-side diffs with conflict markers; rio-beheerder chooses per-record: push local to RIO, accept RIO remote, or flag for manual resolution.

- **Sync Audit Trail**: Complete event log (`rio-sync-event` schema) of all push, pull, conflict, and resolution actions with payloads, responses, and error details.

- **API Rate-Limiting & Circuit-Breaker**: Respect DUO's 100 req/min rate limit with in-queue throttling (90 req/min headroom). Circuit-breaker pauses pushes on repeated API failures and auto-recovers.

**Modified Capabilities:**

- Existing `opleiding` and `programme` schemas (from scholiq base) extended with RIO-specific fields (`rioOpleidingId`, `rioStatus`, `lastSyncedAt`) but not replaced. Backward-compatible via optional fields and migration step.

- Authorization model extended with `rio-beheerder` role (manages RIO sync actions) and optional scope refinement (per-bestuur or per-aanbieder for large organizations).

## Impact

- **New schemas** (`onderwijsbestuur`, `onderwijsaanbieder`, `opleiding`, `aangeboden-opleiding`, `rio-sync-event`) registered in scholiq; MUST include 3-5 realistic seed objects per schema per ADR-001.

- **Extended schemas**: Existing `programme`/`opleiding` objects gain 3 RIO-tracking fields (optional, non-breaking).

- **New background job**: Scheduled daily RIO pull-sync at 03:00, with on-demand trigger available in UI.

- **OpenConnector integration**: REST client for DUO RIO-API (preproduction and production endpoints); PKIoverheid certificate via hydra secrets management.

- **docudesk integration**: Erkenningsbesluit PDFs stored with `classificatie: 'besluit-ocw'`.

- **Frontend routes** added:
  - `/admin/rio/besturen` — bestuur list/detail/edit
  - `/admin/rio/aanbieders` — aanbieder list/detail/edit
  - `/admin/rio/opleidingen` — opleiding list/detail/edit
  - `/admin/rio/aangeboden-opleidingen` — offering list/detail/edit
  - `/admin/rio/reconciliation` — drift detection and conflict resolution UI
  - `/admin/rio/sync-log` — audit trail view (read-only)
  - Public `/api/public/rio/*` — read-only GraphQL for marketing/website integration

- **Database migrations**: Schema registration via repair step; no destructive changes to existing tables.

- **Webhook subscriptions**: OpenRegister object-update events trigger immediate RIO push for bestuur/aanbieder/opleiding/aangeboden-opleiding.

- **Notifications**: Alert rio-beheerder on: sync failures, conflicts detected, accreditation expiry (60/30/14 days), inactive code warnings, rate-limit backoff, circuit-breaker activation.
