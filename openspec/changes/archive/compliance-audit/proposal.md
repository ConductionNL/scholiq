## Why

Compliance-audit is the primary purchasing reason for the Phase 1 wedge (WEDGE-PLAN.md §"The wedge: Compliance-Audit"). "Compliance management" is the #1 canonical feature across all 354 features (166 demand, 44 tenders, 17 competitors). Dutch NIS2 / Cyberbeveiligingswet, AVG, and BIO2 create regulatory mandates that drive direct, time-boxed purchasing: organisations must prove board training or face fines. The immutable evidence log (ADR-008), the coverage % computation over xAPI statements (ADR-002), and the audit-pack export are all designed to survive an external auditor's scrutiny without manual reconstruction. Without this spec, the wedge has no deliverable for the buyer.

## What Changes

- Add OpenRegister schema `scholiq-regulation` tracking regulation name, slug, applicability criteria, audience scope, and active status.
- Add OpenRegister schema `scholiq-attestation` (append-only per ADR-008) capturing: learner_id, lesson_id, course_id, regulation_slug, timestamp, actor_ip, employee_id, score, signature (HMAC-signed at write time per ADR-008 §5).
- Add OpenRegister schema `scholiq-compliance-campaign` for bulk-enrolment campaigns linked to a regulation.
- Add `Scholiq\Controllers\ComplianceController` (regulations CRUD, campaign management, coverage % query, audit-pack export).
- Add `Scholiq\Controllers\AttestationController` (create attestation, list for audit-pack).
- Add `Scholiq\Service\CoverageComputationService`: queries xAPI statements + Enrolments to compute live coverage % per regulation.
- Add `Scholiq\Service\AuditPackExportService`: builds a ZIP per ADR-008 §6 (audit-trail.ndjson, audit-trail.csv, manifest.json, signature-verification.txt) filtered by regulation + date range.
- Add `Scholiq\Service\AttestationService`: captures signed attestation at lesson completion; HMAC-signs the attestation per ADR-008 §5 tamper detection.
- Add Vue views: `ComplianceDashboard`, `RegulationListView`, `CampaignListView`, `AttestationView`, `AuditPackExportModal`.
- All mutations emit audit events per ADR-008; attestation schema itself is append-only with HMAC signature.

## Capabilities

### New Capabilities

- `compliance-audit`: Regulation tracking, coverage % per regulation, immutable attestation capture, audit-pack ZIP export, tamper-detection via HMAC chain, NIS2/AVG/BIO2 campaign management.

### Modified Capabilities

(none — all prerequisite specs already landed)

## Impact

- **`scholiq-attestation` schema**: append-only per ADR-008. OpenRegister MUST enforce `append_only: true` before this spec lands. Every attestation carries an HMAC signature using the tenant's per-rotation key stored in `OCP\Security\ICrypto`.
- **`CoverageComputationService`**: reads xAPI statements from `scholiq-xapi-statement` (course-management spec) with verb in {completed, passed} and cross-references with Enrolments (enrolment spec) to compute coverage numerator/denominator. Must be fast enough to return in ≤ 2s for dashboards (REQ-CA-005-A); add caching layer or materialised view strategy.
- **`AuditPackExportService`**: produces the ADR-008 §6 ZIP format exactly. The `manifest.json` must include signature_status (HMAC chain verification result). This is the artefact that must survive an external auditor's scrutiny.
- **`AttestationService`**: the attestation checkbox in the learner UI triggers this service. The signature MUST be computed at write time; if the HMAC key is unavailable, the attestation MUST fail (not silently proceed unsigned).
- **Compliance-audit is the consumer of all other specs**: it reads Courses (mandatory_training tag), Enrolments (denominator), xAPI statements (numerator), Credentials (evidence), and Attestations (captured proof). Ordering dependency: this spec MUST land last among the 5 non-dashboard specs.
