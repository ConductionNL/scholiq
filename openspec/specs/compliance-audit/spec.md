---
slug: compliance-audit
title: Compliance Training & Audit
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-005]   # TODO until ADRs land
created: 2026-05-11
---

# Compliance Training & Audit

## Why
"Compliance management" is the **#1 canonical feature** (166 demand, 44 tenders, 17 competitors). NIS2 / Cyberbeveiligingswet compels Dutch boards to demonstrate cyber-awareness training of every member. AVG/BIO mandate annual refresher cycles. This spec is the primary purchasing reason for corporate, government, and HE buyers.

## What
Audience-targeted bulk-enrol of compliance modules (all employees / by department / by role); 10-minute video-based microlearning delivery with knowledge check; signed attestation capture (timestamp, IP, employee ID, regulation code, score); escalation to line manager + HR when a deadline passes; live coverage % per regulation with red/amber/green and 12-month trend; export of audit-ready ZIP per regulation and date range; immutable, append-only, digitally signed evidence log; board-cohort proof for NIS2.

## User Stories
- As a compliance officer, I want to bulk-enrol every active employee in the annual refresher with deadlines T-30, T-7, T-1 days so coverage is automatic.
- As a learner, I want my completion to be captured as a signed attestation with timestamp, IP, employee ID, regulation code, and score so audit evidence is unambiguous.
- As an auditor, I want to request evidence for AVG and receive a ZIP with CSV, PDF certificates, and attestation log per learner for the chosen date range.
- As a board member, I want to open the compliance dashboard and see live coverage % per regulation (BIO, AVG, NIS2, integriteit) with red/amber/green bands and 12-month trend.
- As an auditor, I want to see attendance, content version, certificate, and date for every board member of a NIS2 cyber-awareness training so I can verify Cyberbeveiligingswet conformity.

## Acceptance Criteria
- GIVEN a refresher campaign opens, WHEN the compliance officer picks the audience and deadline, THEN every active employee is enrolled and notifications fire at T-30, T-7, T-1.
- GIVEN a learner finishes the module, WHEN they tick the attestation checkbox and submit, THEN the system records timestamp, IP, employee ID, regulation code, and score.
- GIVEN an auditor requests AVG evidence for Q1, WHEN the export tool runs, THEN it produces a ZIP with CSV summary, PDF certificates, and attestation log per learner.
- GIVEN any training event happens, WHEN it is recorded, THEN the entry is append-only, digitally signed, and any tampering attempt produces an alert.

## Requirements

### Requirement: Capture attestations with full provenance
The system MUST capture attestations with timestamp, IP, employee ID, regulation code, and score.

### Requirement: Maintain an append-only signed evidence log
The system MUST maintain an append-only digitally signed evidence log; any mutation attempt MUST raise an alert.

### Requirement: Export audit-ready ZIP per regulation and date range
The system MUST export an audit-ready ZIP per regulation and date range.

## Standards
NIS2 / Cyberbeveiligingswet, AVG, AVG-Onderwijs, BIO/BIO2, Schema.org `EducationalOccupationalCredential`, ISO 27001 evidence patterns.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `ComplianceCampaign`, `Attestation`, `EvidenceLog`, `RegulationCoverage`. All in OpenRegister; signing key managed via Nextcloud key storage.

## Out of Scope
- Authoring of the regulation content itself (sourced from RADIO / Kennisnet / vendor library).
- Whistleblower / integrity-incident reporting (separate app: decidesk).
- SaaS-style multi-tenant compliance benchmarking (V2).
