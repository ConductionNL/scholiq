---
slug: opp-cycle
title: OPP Cycle (Passend Onderwijs)
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-005]   # TODO until ADRs land
created: 2026-05-11
---

# OPP Cycle (Passend Onderwijs)

## Why
The Wet Passend Onderwijs makes the **Ontwikkelingsperspectief (OPP)** mandatory for every pupil with extra ondersteuningsbehoeften. ParnasSys owns ~65% of the PO market (insight #7) but the OPP UI is widely criticised. Critical-priority story `Create OPP from sector template` plus three high/medium ones (parent DigiD signing, quarterly evaluation reminder, link Cito results) make this a flagship PO spec.

## What
Template-driven OPP creation from samenwerkingsverband templates with the wettelijk verplichte velden; parent digital signing via DigiD; quarterly evaluation reminders; auto-linking of Cito results to the relevant OPP doel as evidence; group-level trend reports; export of inspectie-ready overviews.

## User Stories
- As an internal counsellor, I want to create an OPP from a samenwerkingsverband template so I do not start from a blank document and meet the wettelijke verplichte velden.
- As a parent, I want to digitally sign the OPP via DigiD so I do not need to come to school for the handtekening.
- As an internal counsellor, I want automatic reminders for quarterly OPP evaluations so no pupil-OPP slips through unevaluated.
- As a zorgcoördinator, I want Cito results to auto-attach to the relevant OPP doel so progress evidence is co-located with the goal.
- As a school principal, I want to export an inspectie-ready overview of Cito results per leerjaar to demonstrate basisvaardigheden to the Onderwijsinspectie.

## Acceptance Criteria
- GIVEN a samenwerkingsverband template is registered, WHEN the IB-er starts a new OPP, THEN every wettelijk verplicht veld is pre-populated as required-with-validation.
- GIVEN a parent receives an OPP signing request, WHEN they open the link and authenticate via DigiD, THEN their signature is recorded with timestamp, BSN-pseudonym, and document hash.
- GIVEN an OPP is active, WHEN the next quarterly evaluation date approaches, THEN the IB-er and group teacher receive reminders T-14 and T-7 days.
- GIVEN a Cito result is imported and linked to a leerlijn, WHEN the leerlijn matches an OPP doel, THEN the result auto-attaches as evidence on that doel.

## Requirements
- The system MUST allow OPP creation only from a registered samenwerkingsverband template.
- The system MUST record parent signatures with DigiD assurance level Substantial or High and store an audit-grade signature record.
- The system MUST generate quarterly evaluation reminders without manual intervention.

## Standards
Wet Passend Onderwijs, AVG-Onderwijs, DigiD (Logius), SchoolID + ECK iD pseudonymisation, Handreiking Ontwikkelingsperspectief (Steunpunt Passend Onderwijs).

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `OPP`, `OPPDoel`, `OPPTemplate`, `EvaluationCycle`, `ParentSignature`. All in OpenRegister.

## Out of Scope
- Diagnostic test administration itself — handled by `assessment-engine` / external Cito tooling.
- Samenwerkingsverband-level case management (separate spec/app).
- Digital handover to municipality jeugdzorg (V2; Suwinet boundary).
