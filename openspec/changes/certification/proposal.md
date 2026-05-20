## Why

Certification and credential management are top-10 canonical features (153 demand) that close the compliance-training loop. Dutch government spends €250M+ annually on employee training — every euro requires a defensible record with a signed, verifiable credential. Insight #10: "EDCI / Europass digital credentials open the diploma and microcredential market." Insight #11: government training spend demands auditable issuance, expiry detection and renewal mechanics. Five `compliance-training` and `government-training` stories pivot on issuing, expiring and renewing credentials; without this spec the compliance-audit wedge cannot prove "employee X completed NIS2 board training, credential valid until date Z, renewed before lapse."

## What Changes

- Add OpenRegister schema `scholiq-certificate-template` (`CertificateTemplate` entity) with fields: name, kind, issuerName, issuerDid, courseId, validityPeriodMonths, backgroundImagePath, badgeImagePath, renewalCourseSlug, deltaCourseSlug, tenant_id.
- Add OpenRegister schema `scholiq-certificate` (`Certificate` entity) with fields: learnerId, templateId, courseId, kind, issuedAt, expiresAt, issuerDid, openbadges3Payload, edciPayload, revocationReason, source, verificationUrl, regulationSlug, renewalCertificateId, tenant_id; lifecycle: `issued → revoked | expired`.
- Add OpenRegister schema `scholiq-credential-issuance` (`CredentialIssuance` entity) recording the full issuance event with: certificateId, learnerId, courseId, templateId, issuedVia (auto/manual/diploma), ob3SignatureRef, edciSignatureRef, tenant_id. Append-only per ADR-008.
- Add OpenRegister schema `scholiq-renewal-rule` (`RenewalRule` entity) declaring renewal and delta-module enrolment triggers: templateId, triggerType (expiry/content-version), expiryThresholdDays, deltaCourseSlug, autoEnrol, tenant_id.
- Add OpenRegister schema `scholiq-content-version` (`ContentVersion` entity) tracking course content version bumps that trigger delta-module enrolment: courseId, version, changedAt, deltaScope, affectsCredentialKinds, tenant_id.
- Add `lib/Service/CertificateSigningService.php`: builds Open Badges 3.0 JSON-LD + EDCI ELM payload, RS256-signs via `OCP\Security\ICrypto`; legitimate PHP per ADR-031 — cryptographic + document-generation exception.
- Add `lib/Service/KeyManagementService.php`: generates per-tenant RSA-2048 keypair, stores private key via `ICrypto::encrypt`; legitimate PHP per ADR-031 — cryptographic exception.
- Add `lib/Lifecycle/CertificateIssuanceHandler.php`: OR audit-event listener for `enrolment.completed`; reads template from Course, calls `CertificateSigningService`, writes Certificate via OR; legitimate PHP per ADR-031 — lifecycle handler exception.
- Add `lib/Controller/CertificateVerifyController.php`: unauthenticated `GET /api/certificates/{id}/verify` returning `{valid, issuedAt, expiresAt, issuerName}` + QR data; legitimate PHP per ADR-031 — external-system contract (public endpoint, no session middleware).
- Add `lib/Controller/KeyAdminController.php`: admin-only `POST /api/certificates/admin/generate-key`, delegates to `KeyManagementService`.
- Expiry detection (T-90/T-60/T-30), tiered notifications and auto-enrolment triggers are schema-declared via `x-openregister-calculations` + `x-openregister-notifications` — no `ExpiryDetectionService`, no `CredentialExpiryJob` TimedJob per ADR-031.
- Delta-module enrolment on `ContentVersion` is a schema-declared notification on the `ContentVersion` lifecycle — no per-app notification service.
- All Certificate state changes emit audit events per ADR-008 via OR's declarative lifecycle engine.

## Capabilities

### New Capabilities

- `certification`: CertificateTemplate management, Certificate issuance (Open Badges 3.0 + EDCI ELM), tiered expiry notifications (T-90/T-60/T-30), auto-enrolment in renewal modules on expiry, delta-module auto-enrolment on ContentVersion bump, Bologna Diploma Supplement EDCI payload on degree award, public verification URL with QR code.

### Modified Capabilities

(none — course-management and enrolment changes already landed)

## Impact

- **`CertificateTemplate` schema**: the template defines badge image, background, validity period, renewal course and delta course. Admin must configure a template before auto-issuance fires. The template is the gating object — no template, no certificate.
- **`CertificateSigningService`**: signing requires a per-tenant keypair in `OCP\Security\ICrypto`. Key generation is exposed in admin settings (nextcloud-app spec). The verification URL is public — `CertificateVerifyController::verify()` must bypass NC session auth via `@NoCSRFRequired` + `@PublicPage`.
- **`CertificateIssuanceHandler`**: bridge between enrolment and certification. Listens for OR's `enrolment.completed` audit event. Ordering: certification spec depends on enrolment spec landing first.
- **`ContentVersion` + `RenewalRule` delta-module flow**: when a course's content version is bumped, `ContentVersion.lifecycle: draft → published` fires a schema-declared notification that reads `RenewalRule.deltaCourseSlug` and auto-enrols holders of active certificates. No app-local orchestration service.
- **Bologna Diploma Supplement**: delivered as an EDCI-payload field populated with the ELM JSON on `kind=diploma` certificates. Full DID management and linked-data proof suite deferred to Phase 3.
- **Compliance-audit dependency**: the compliance-audit spec reads Certificates to confirm "attestation + certificate issued" for the evidence pack. Certificate issuance must complete within 30 seconds of `enrolment.completed` (REQ-CERT-001-A).
- **Wedge scope**: Decentralized Identifier management deferred to Phase 3. Cross-institution edubadges.nl federation (SURF) deferred to V1. Paper certificate printing delegated to DocuDesk if needed. Blockchain anchoring deferred to Enterprise/V2.
