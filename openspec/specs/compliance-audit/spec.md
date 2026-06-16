---
slug: compliance-audit
title: Compliance Training & Audit
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-005]   # TODO until ADRs land
created: 2026-05-11
---

# Compliance Training & Audit

@e2e exclude Pure backend/data-model spec. Requirements define OpenRegister schema shapes, the signed evidence log, the coverage predicate, and ZIP export; the few scenarios are backend/lifecycle behaviours individually annotated `@e2e exclude`.

## Purpose

Deliver Scholiq's wedge promise: audience-targeted bulk-enrolment in mandatory compliance training, signed attestation capture with full provenance, an append-only signed evidence log, a live coverage % per regulation, and an audit-ready ZIP export per regulation and date range. Coverage counts a learner as covered when they hold a signed Attestation, a valid Credential, OR a verified external-training record for the regulation, and the audit pack carries each evidence class distinctly.

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

#### Scenario: Attestation captured with provenance
<!-- @e2e exclude Attestation signing is the AttestationSigningGuard lifecycle seam (HMAC over the canonical payload); verified by PHPUnit AttestationSigningGuardTest. No scholiq DOM surface. -->

- **GIVEN** a learner completing a mandatory compliance lesson
- **WHEN** the attestation is signed
- **THEN** the signed record carries timestamp, actor IP, employee/learner ID, regulation code, and score

### Requirement: Maintain an append-only signed evidence log
The system MUST maintain an append-only digitally signed evidence log; any mutation attempt MUST raise an alert.

#### Scenario: Evidence log is append-only
<!-- @e2e exclude Append-only + HMAC-chain semantics are OpenRegister audit-trail behaviour (AuditHashService); verified by reasoning + the audit-pack signature-verification artefact. No scholiq DOM surface. -->

- **GIVEN** a signed attestation in the evidence log
- **WHEN** a mutation of an existing entry is attempted
- **THEN** the append-only log rejects the change and the signature-verification report flags the break

### Requirement: Export audit-ready ZIP per regulation and date range
The system MUST export an audit-ready ZIP per regulation and date range.

#### Scenario: Audit pack exported for a regulation
<!-- @e2e exclude ZIP-stream backend artefact (AuditPackExportController::export) gated by the ADR-023 audit-pack.export action; no DOM surface to drive the download stream. -->

- **GIVEN** a regulation slug and a date range
- **WHEN** an authorized officer requests the audit pack
- **THEN** a ZIP streams containing the audit trail (ndjson + csv), manifest, signature-verification report, verwerkingsregister, and external-training artefacts

### Requirement: Coverage computation MUST include verified external training records

A learner MUST count as covered for a Regulation when they have a signed Attestation, OR a valid Credential, OR a `verified` ExternalTrainingRecord with a matching `regulationSlug` whose `validUntil` (when set) has not passed. The coverage view MUST show which evidence class covers each learner. `submitted` and `rejected` records MUST NOT affect coverage.

#### Scenario: Verified classroom training turns coverage green
<!-- @e2e exclude Coverage predicate (ExternalTrainingService::isLearnerCovered / coveringEvidenceClass) verified by PHPUnit ExternalTrainingServiceTest::testVerifiedExternalRecordCovers, exposed at runtime via externalTraining#learnerCoverage. No single drivable DOM scenario. -->

- **GIVEN** a Regulation `NIS2` whose coverage shows a board member as uncovered
- **AND** a `verified` ExternalTrainingRecord for that learner with `regulationSlug: NIS2` and no `validUntil`
- **WHEN** coverage is recomputed
- **THEN** the learner counts as covered
- **AND** the coverage view labels the evidence class as external training

#### Scenario: Expired external validity drops coverage
<!-- @e2e exclude Coverage-expiry predicate verified by PHPUnit ExternalTrainingServiceTest::testExpiredExternalRecordDoesNotCover. No scholiq DOM surface. -->

- **GIVEN** a learner covered solely by a `verified` ExternalTrainingRecord with `validUntil` in the past
- **WHEN** coverage is recomputed
- **THEN** the learner counts as uncovered for that Regulation

### Requirement: The audit-pack ZIP MUST include external training evidence as a separate class

The audit-pack export for a regulation and date range MUST include `external-training.csv` (record fields, submitter, verifier, evidence file references) and the evidence attachments for matching `verified` ExternalTrainingRecords, labelled separately from in-app attestation artefacts. Signed-attestation content and the append-only evidence-log semantics MUST remain untouched.

#### Scenario: Auditor sees both evidence classes distinctly
<!-- @e2e exclude Audit-pack ZIP artefact (AuditPackExportController::buildExternalTrainingCsv); the ZIP stream is a backend artefact verified by reasoning + the existing audit-pack export path. No DOM surface to drive. -->

- **GIVEN** a regulation with both in-app attestations and verified external records in the requested date range
- **WHEN** the audit-pack ZIP is produced
- **THEN** it contains the existing attestation artefacts unchanged
- **AND** `external-training.csv` plus the external evidence files in a separately-named folder

## Standards
NIS2 / Cyberbeveiligingswet, AVG, AVG-Onderwijs, BIO/BIO2, Schema.org `EducationalOccupationalCredential`, ISO 27001 evidence patterns.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `ComplianceCampaign`, `Attestation`, `EvidenceLog`, `RegulationCoverage`. All in OpenRegister; signing key managed via Nextcloud key storage.

## Out of Scope
- Authoring of the regulation content itself (sourced from RADIO / Kennisnet / vendor library).
- Whistleblower / integrity-incident reporting (separate app: decidesk).
- SaaS-style multi-tenant compliance benchmarking (V2).
