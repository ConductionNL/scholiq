---
slug: certification
title: Certification & Digital Credentials
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-003]   # TODO until ADRs land
created: 2026-05-11
---

# Certification & Digital Credentials

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes and scheduled expiry jobs — no `#### Scenario:` headings exist in this spec.

## Purpose
"Certification management" and "Credential management" are both top-10 canonical features (153 demand). Insight #10: "EDCI / Europass digital credentials open the diploma + microcredential market." Insight #11: "Dutch government spends €250M+ annually on employee training" — every euro requires a defensible record. Five `compliance-training` and `government-training` stories pivot on issuing, expiring, and renewing credentials.

## What
Certificate templates (visual + metadata); issuance triggered by course/exam completion; cryptographically verifiable EDCI / Europass issuance; Open Badges 3.0 issuance with verifiable URL/QR; expiry detection with tiered notifications (90/60/30 days); auto-enrol into renewal modules; delta-module enrol when content version changes; Bologna-compliant Diploma Supplement on degree award; portable transcript/edubadge for civil-service mobility.

## User Stories
- As a registrar, I want a Bologna-compliant Diploma Supplement to be generated automatically when a degree is awarded so the institution complies with EU rules.
- As a student, I want an edubadge for each completed module so I can share verifiable credentials with employers.
- As a foreign university registrar, I want a verification link on each transcript so I can confirm authenticity without contacting the issuing institution.
- As a compliance officer, I want any certification recorded with an expiry date to trigger tiered notifications at 90/60/30 days so renewals never lapse silently.
- As a learner who completed a recognised RADIO certification, I want it stored in a portable transcript with verifiable URL/QR so any ministry can validate it when I move.

## Acceptance Criteria
- GIVEN a learner completes a course with a defined certificate template, WHEN the final attempt passes, THEN the certificate is issued, signed, and pushed to the learner's wallet within 30 seconds.
- GIVEN a certification has an expiry date, WHEN the daily job runs, THEN learners + managers + compliance officers get tiered notifications at 90/60/30 days.
- GIVEN a regulation changes and the related course is marked as a new content version, WHEN the change is saved, THEN every previously certified learner is auto-enrolled in the delta module.
- GIVEN a degree is awarded, WHEN the registrar confirms, THEN a Bologna Diploma Supplement is generated and an EDCI credential is issued.

## Requirements

### Requirement: Issue EDCI/Europass and Open Badges 3.0 credentials
The system MUST issue EDCI / Europass credentials and Open Badges 3.0 with verifiable URLs.

### Requirement: Detect expiries on a daily schedule
The system MUST detect expiries on a daily schedule and dispatch tiered notifications.

### Requirement: Auto-enrol on renewal or content-version change
The system MUST auto-enrol learners in renewal or delta modules when triggered by expiry or content-version change.

## Standards
EDCI (Europass), Open Badges 3.0, E-Portfolio NL, Bologna Diploma Supplement, Schema.org `EducationalOccupationalCredential`.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Certificate`, `CertificateTemplate`, `CredentialIssuance`, `RenewalRule`, `ContentVersion`. All in OpenRegister.

## Out of Scope
- Blockchain-anchored credential storage (V2; verifiable URL + signed JSON is enough at MVP).
- Manual paper certificate printing (handed to docudesk if needed).
- Cross-institution badge wallet (handled by edubadges.nl federation).
