---
slug: absence-leerplicht
title: Absence & Leerplicht
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# Absence & Leerplicht

## Why
Leerplichtwet article 21a obliges every NL school to report ongeoorloofd verzuim of 16 lesuren in 4 weeks to the leerplichtambtenaar. Critical-priority story `Auto-track 16-uur leerplicht threshold` plus three high/medium ones (DigiD-authenticated sick reporting, mentor pattern dashboard, 18+ self-reporting) make this a flagship VO spec — and a bridge to municipal handhaving via Digikoppeling.

## What
Sick reporting via DigiD-authenticated parent app (or pupil app for 18+); auto-tracking of ongeoorloofd absentie per pupil against the 16-uur threshold with pre-warning; mentor dashboard surfacing absence patterns at a glance; one-click report-to-leerplicht when the threshold is crossed; full audit trail for inspectie.

## User Stories
- As a parent, I want to report my child sick in the school app authenticated via DigiD so the school knows it is really me and accepts the melding without a phone call.
- As a school administrator, I want the system to track ongeoorloofd absentie per pupil against the 16-lesuur threshold so I receive a warning before I am required to report to leerplicht.
- As a mentor, I want a dashboard with absence patterns of my mentor class so I can spot a pupil with rising absence early.
- As a pupil aged 18 or older, I want to report my own absences so my parents do not need to do it for me.

## Acceptance Criteria
- GIVEN a parent opens the sick-reporting flow, WHEN they authenticate via DigiD Substantial, THEN the melding is recorded with parent BSN-pseudonym, child pseudonym, timestamp, and reason.
- GIVEN a pupil's running 4-week ongeoorloofd absentie reaches 12 lesuren, WHEN the daily job runs, THEN the school administrator and mentor get a "approaching 16-uur" warning.
- GIVEN the 16-uur threshold is crossed, WHEN the administrator confirms the report, THEN a one-click submit to leerplichtambtenaar fires via Digikoppeling.
- GIVEN a mentor opens the class dashboard, WHEN the page loads, THEN every pupil row shows a 30-day absence sparkline with red/amber/green banding.

## Requirements
- The system MUST authenticate parent and 18+ pupil sick reports via DigiD assurance level Substantial or higher.
- The system MUST compute a rolling 4-week ongeoorloofd-absentie window per pupil and pre-warn at 12 lesuren.
- The system MUST submit the leerplicht melding via the OpenConnector Digikoppeling adapter; never inline HTTP.

## Standards
Leerplichtwet (art. 21a), DigiD (Logius), Digikoppeling, AVG-Onderwijs, SchoolID + ECK iD pseudonymisation, Schema.org `Action`.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `AbsenceRecord`, `SickReport`, `LeerplichtThresholdState`, `LeerplichtSubmission`. All in OpenRegister; transport in OpenConnector.

## Out of Scope
- Handhaving workflow at the municipality (RMC owns it).
- HBO/WO presentie-registratie (not leerplicht).
- Long-term ziekteverzuim doctor-verklaring upload (V2).
