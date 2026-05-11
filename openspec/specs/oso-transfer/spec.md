---
slug: oso-transfer
title: OSO Transfer Dossier (PO → VO)
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# OSO Transfer Dossier (PO → VO)

## Why
Critical-priority story `Secure transfer via OSO standard` plus three high-priority stories (compose dossier from existing data, parent reviews before transfer, VO mentor imports OSO into LAS) anchor this. OSO is mandated by Edu-K and Edukoppeling; without it Scholiq can't be the LAS at either end of the brugklas transfer. Combined with `bron-rod-exchange`, OSO is the second NL gatekeeper integration that decides PO/VO procurement viability.

## What
Auto-composition of the OSO overstapdossier from leerlingdossier, OPP, and Cito results; parent review screen showing exactly which fields will be transferred with a 14-day objection window; OSO-certified secure transfer via the openconnector OSO adapter; on the receiving VO side, import into the LAS that surfaces previously known ondersteuningsbehoeften from day one.

## User Stories
- As a school administrator, I want the OSO overstapdossier to be auto-composed from the leerlingdossier, OPP, and Cito results so I do not retype anything.
- As a parent, I want to see exactly which data will be sent to the new VO school so I can object before the dossier is transferred.
- As a school administrator, I want to transfer the OSO file via the certified OSO connection so the data exchange meets Edu-K standards and is encrypted end-to-end.
- As a VO mentor, I want the imported OSO to surface previously known ondersteuningsbehoeften so I can prepare the brugklaspupil from day one.

## Acceptance Criteria
- GIVEN a pupil leaves PO group 8, WHEN the school administrator clicks "Compose OSO", THEN the dossier is built from leerlingdossier + OPP + Cito with no manual retyping.
- GIVEN a parent receives the OSO review link, WHEN they open it and authenticate via DigiD, THEN they see every field that will be sent and have a 14-day objection window before transfer.
- GIVEN the parent has not objected after 14 days, WHEN the administrator clicks "Send", THEN the dossier transfers via the OpenConnector OSO adapter with end-to-end Edu-K encryption.
- GIVEN a VO mentor opens a brugklaspupil for the first time, WHEN the OSO is imported, THEN ondersteuningsbehoeften from the OPP are surfaced on the dashboard.

## Requirements
- The system MUST build the OSO dossier automatically from existing OpenRegister data (leerlingdossier + OPP + Cito).
- The system MUST give the parent at least 14 days to object before transfer fires.
- The system MUST transfer exclusively via the OpenConnector OSO adapter; never inline HTTP.

## Standards
OSO (Overstapservice Onderwijs), Edu-K, Edukoppeling, Digikoppeling, AVG-Onderwijs, DigiD (parent identification), SchoolID + ECK iD pseudonymisation.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `OsoDossier`, `OsoFieldSelection`, `OsoTransfer`, `ParentObjection`. All in OpenRegister; transport in OpenConnector.

## Out of Scope
- VO → MBO / VO → HO transfers (different standards; future spec).
- Cross-board jeugdzorg dossier handover (Suwinet — out of scope).
- International school transfers (handled by EDCI credential + transcript).
