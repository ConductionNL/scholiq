## Why

Certification closes the compliance-training loop: an attestation signature (captured in the compliance-audit spec) is the evidence; the credential issued here is the artefact that a learner can share and an auditor can verify. For the wedge, certification focuses on Open Badges 3.0 issuance with verifiable URLs — not full EDCI/Europass signing (that lands Phase 3). The wedge credential proves "employee X completed NIS2 board training on date Y, expires date Z." Expiry detection with tiered notifications (90/60/30 days) and auto-enrolment in renewal modules are the two mechanics that make the compliance lifecycle self-sustaining without manual HR intervention.

## What Changes

- Add OpenRegister schema `scholiq-credential` (`Credential` entity per ARCHITECTURE.md §3.1) with fields: id, learner_id, course_id, kind (certificate/badge/microcredential), issued_at, expires_at, issuer_did, signature (Open Badges 3.0 signed assertion), openbadges3_payload, revoked, revocation_reason, tenant_id.
- Add `Scholiq\Controllers\CredentialController` (list, show, issue, revoke, verify).
- Add `Scholiq\Service\CredentialIssuanceService`: builds Open Badges 3.0 assertion JSON, signs it using the tenant's signing key via `OCP\Security\ICrypto`.
- Add `Scholiq\Service\ExpiryDetectionService` scanning Credentials for upcoming expiry.
- Add `OCP\BackgroundJob\TimedJob` `CredentialExpiryJob` running daily; dispatches tiered notifications at T-90, T-60, T-30.
- Add `Scholiq\EventListener\EnrolmentCompletedCredentialListener`: listens for `enrolment.completed` event and triggers credential issuance if a certificate template is defined for the course.
- Add public verification endpoint `GET /api/credentials/{id}/verify` (unauthenticated) that returns the credential's validity status.
- Add Vue views: `CredentialListView`, `CredentialDetailView`, `CredentialVerifyView`.
- All mutations emit audit events per ADR-008.

## Capabilities

### New Capabilities

- `certification`: Credential entity CRUD, Open Badges 3.0 issuance, expiry detection + T-90/T-60/T-30 notifications, auto-enrol in renewal modules, public verification URL.

### Modified Capabilities

(none — enrolment and course-management already landed)

## Impact

- **`CredentialIssuanceService`**: signing requires a per-tenant keypair stored in `OCP\Security\ICrypto`. Key generation + storage must be documented in admin settings (nextcloud-app spec). The verification URL is public — no NC auth — so `CredentialController::verify()` must bypass NC session auth.
- **`EnrolmentCompletedCredentialListener`**: this is the bridge between enrolment and certification. The enrolment spec emits `scholiq.enrolment.completed`; this listener subscribes and triggers issuance. Ordering: certification spec depends on enrolment spec landing first.
- **`CredentialExpiryJob`**: adds two new audit event types (`credential.expired`, `credential.expiry.reminder.sent`) to `AuditEventTypes::KNOWN`.
- **Wedge scope**: full EDCI/Europass signing with DID + linked-data proof is deferred to Phase 3. Open Badges 3.0 (W3C VC aligned) is the v0.1 standard. Bologna Diploma Supplement is deferred to Phase 2 (HE context).
- **Compliance-audit dependency**: the compliance-audit spec reads Credentials to confirm "attestation + credential issued" for the evidence pack. Credential issuance must happen within 30 seconds of enrolment completion (REQ-CE-001-A).
