---
status: draft
---

# Compliance Training & Audit — External Evidence Delta

## Purpose

Extends compliance coverage and the audit-pack export to include verified external training records as a separately-labelled evidence class. Existing requirements (signed attestation capture, append-only evidence log, ZIP export) are unchanged.

## ADDED Requirements

### Requirement: Coverage computation MUST include verified external training records

A learner MUST count as covered for a Regulation when they have a signed Attestation, OR a valid Credential, OR a `verified` ExternalTrainingRecord with a matching `regulationSlug` whose `validUntil` (when set) has not passed. The coverage view MUST show which evidence class covers each learner. `submitted` and `rejected` records MUST NOT affect coverage.

#### Scenario: Verified classroom training turns coverage green

- **GIVEN** a Regulation `NIS2` whose coverage shows a board member as uncovered
- **AND** a `verified` ExternalTrainingRecord for that learner with `regulationSlug: NIS2` and no `validUntil`
- **WHEN** coverage is recomputed
- **THEN** the learner counts as covered
- **AND** the coverage view labels the evidence class as external training

#### Scenario: Expired external validity drops coverage

- **GIVEN** a learner covered solely by a `verified` ExternalTrainingRecord with `validUntil` in the past
- **WHEN** coverage is recomputed
- **THEN** the learner counts as uncovered for that Regulation

### Requirement: The audit-pack ZIP MUST include external training evidence as a separate class

The audit-pack export for a regulation and date range MUST include `external-training.csv` (record fields, submitter, verifier, evidence file references) and the evidence attachments for matching `verified` ExternalTrainingRecords, labelled separately from in-app attestation artefacts. Signed-attestation content and the append-only evidence-log semantics MUST remain untouched.

#### Scenario: Auditor sees both evidence classes distinctly

- **GIVEN** a regulation with both in-app attestations and verified external records in the requested date range
- **WHEN** the audit-pack ZIP is produced
- **THEN** it contains the existing attestation artefacts unchanged
- **AND** `external-training.csv` plus the external evidence files in a separately-named folder
