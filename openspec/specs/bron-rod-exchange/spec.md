---
slug: bron-rod-exchange
title: BRON / ROD Data Exchange (DUO)
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# BRON / ROD Data Exchange (DUO)

## Why
Insight #4 (critical): "BRON/ROD (DUO) integration is non-negotiable for school administration." Insight #13 (high): "Edukoppeling/UWLR/OSO is the gatekeeper for NL PO/VO/MBO procurement." Without ROD/BRON, a school's leveringsverplichting fails, bekostiging stops, and Scholiq cannot replace ParnasSys/Magister/SOMtoday in any tender.

## What
Outbound mutaties van leerlinggegevens to ROD (PO + VO) via OpenConnector's Edukoppeling adapter; scheduled batch runs; eHerkenning EH3 SSO to DUO Zakelijk; inline plain-text translation of DUO afkeurmeldingen with click-to-fix on the affected leerling; full audit log of every uitgewisselde mutatie for inspectie.

## User Stories
- As a school administrator, I want a scheduled job to send leerlingmutaties to ROD so I do not have to manually trigger the export every day.
- As a school administrator, I want DUO error codes translated to plain text and a click-to-fix on the affected leerling so I do not need to consult the DUO codetabel.
- As a school administrator, I want SSO with eHerkenning niveau EH3 to DUO Zakelijk so I do not relogin per session.
- As a DUO inspector, I want a complete audit log of all uitgewisselde mutaties so I can verify the school complies with leveringsverplichting.

## Acceptance Criteria
- GIVEN the daily ROD batch runs, WHEN the cron fires at the configured time, THEN every pending mutatie is sent via the OpenConnector Edukoppeling adapter and the result is logged.
- GIVEN ROD returns an afkeurmelding with code 0123, WHEN the school administrator opens the error queue, THEN the code is translated to plain Dutch text and a "fix on leerling X" button opens that pupil's record.
- GIVEN a school administrator opens DUO Zakelijk from Scholiq, WHEN their session is fresh, THEN eHerkenning EH3 SSO completes without re-prompting for credentials.
- GIVEN an inspector queries the audit log for a date range, WHEN they export, THEN every mutatie shows timestamp, leerling pseudonym, change set, and DUO response.

## Requirements
- The system MUST exchange data with ROD/BRON exclusively via the OpenConnector Edukoppeling adapter; no inline HTTP from Scholiq.
- The system MUST translate DUO afkeurmeldingen into plain text with deep links to the affected pupil record.
- The system MUST log every mutatie immutably for inspectie audit (append-only, signed).

## Standards
ROD (DUO), Edukoppeling, Digikoppeling, ROSA, eHerkenning EH3 (Logius), SchoolID + ECK iD pseudonymisation, AVG-Onderwijs.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `BronExchange`, `RodMutation`, `Afkeurmelding`, `RodErrorCodeMap`, `EdukoppelingMessageEnvelope`. All in OpenRegister; transport in OpenConnector.

## Out of Scope
- Bekostiging calculation itself (DUO owns it).
- HO RIO student data exchange (separate flow; handled by `enrolment` + Studielink).
- Vavo / particulier onderwijs flows (V2).
