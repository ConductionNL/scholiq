---
slug: certification
title: Certification & Digital Credentials
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-20
updated: 2026-05-20
wedge_scope: Phase 1 — Open Badges 3.0 issuance + expiry detection + delta-module auto-enrolment; EDCI ELM proof suite and Bologna PDF deferred to Phase 2/3
---

# Certification — Formal Requirements

## Overview

Certification captures the verified outcome of a learner completing mandatory compliance training or a degree programme: an Open Badges 3.0 credential signed with the tenant's RS256 key, a public verification URL + QR code, expiry detection with tiered reminders at T-90/T-60/T-30, auto-enrolment in renewal modules on expiry, and delta-module auto-enrolment when course content is versioned. EDCI/Europass ELM payloads are generated for `edciEnabled` templates. Bologna Diploma Supplement is generated for `kind=diploma` certificates when `bolognaSupplement=true` on the template. All credential state changes emit audit events per ADR-008. The EU AI Act gate (ADR-005) applies if future AI-driven credential revocation is added; Phase 1 has no AI.

---

## Requirements

### REQ-CERT-001 — Automatic certificate issuance on enrolment completion

The system MUST automatically issue a `Certificate` when an `Enrolment` transitions to `lifecycle=completed` and the associated `Course` has a `CertificateTemplate` configured with `lifecycle=active`. Issuance MUST complete within 30 seconds of the `enrolment.completed` audit event.

#### Scenario CERT-001-A: Certificate issued within 30 seconds of enrolment completion

```
GIVEN a learner's Enrolment lifecycle transitions to 'completed'
  AND the Course has a CertificateTemplate with lifecycle=active
WHEN CertificateIssuanceHandler processes the enrolment.completed audit event
THEN the system MUST create a Certificate object in OpenRegister with lifecycle=issued within 30 seconds
  AND Certificate.kind MUST match CertificateTemplate.kind
  AND Certificate.issuedAt MUST be within 5 seconds of the enrolment.completed timestamp
  AND Certificate.openbadges3Payload MUST be a valid JSON-LD OB3 assertion
  AND an audit event 'credential.issued' MUST be emitted per ADR-008
  AND a 'credential_issued' nc-notification MUST be dispatched to the learner
  AND a CredentialIssuance object MUST be created with issuedVia='auto'
```

#### Scenario CERT-001-B: No certificate issued when no active template is configured

```
GIVEN a learner's Enrolment transitions to 'completed'
  AND the Course has no CertificateTemplate configured (or template lifecycle=draft/archived)
WHEN CertificateIssuanceHandler processes the event
THEN the system MUST NOT create a Certificate object
  AND MUST NOT emit a 'credential.issued' audit event
  AND MUST NOT dispatch a certificate notification
```

#### Scenario CERT-001-C: CredentialIssuance record created for every issuance

```
GIVEN a Certificate is successfully issued (auto or manual)
WHEN the Certificate is saved with lifecycle=issued
THEN a CredentialIssuance object MUST be created in OpenRegister
  AND CredentialIssuance.certificateId MUST reference the new Certificate
  AND CredentialIssuance.ob3SignatureRef MUST be non-empty
  AND CredentialIssuance is append-only (no UPDATE or DELETE permitted)
```

---

### REQ-CERT-002 — Open Badges 3.0 signed assertion

The system MUST sign each issued `Certificate` as an Open Badges 3.0 assertion using the tenant's RS256 keypair stored in `OCP\Security\ICrypto`. The signed assertion MUST be stored in `Certificate.openbadges3Payload`. Learner identity in the assertion MUST use an opaque UUID — never a BSN or other direct personal identifier.

#### Scenario CERT-002-A: Issued certificate contains valid OB3 assertion

```
GIVEN a Certificate is issued for learner X completing NIS2 course Y
WHEN the certificate is retrieved via OR's GET /api/openregister/scholiq/Certificate/{id}
THEN the response MUST include openbadges3Payload as a valid JSON-LD object
  AND openbadges3Payload['@context'] MUST include 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json'
  AND openbadges3Payload['credentialSubject']['id'] MUST be 'urn:scholiq:learner:<uuid>' (opaque, not BSN)
  AND openbadges3Payload['proof']['jws'] MUST be a non-empty RS256 compact JWS string
  AND openbadges3Payload['proof']['type'] MUST be 'RsaSignature2018'
```

#### Scenario CERT-002-B: Certificate verification via public URL (unauthenticated)

```
GIVEN a valid Certificate has been issued with id=cert-uuid-123
WHEN an unauthenticated external party calls GET /api/certificates/cert-uuid-123/verify
THEN the system MUST return HTTP 200 with {valid: true, issuedAt, expiresAt, issuerName, qrData}
  AND MUST NOT require NC session authentication for this endpoint
  AND MUST NOT return personal data (no learnerName, no BSN, no email)
  AND an audit event 'credential.verified' MUST be written via OR's audit-trail API
```

#### Scenario CERT-002-C: Revoked certificate returns invalid on verify

```
GIVEN a Certificate has lifecycle=revoked with a revocationReason set
WHEN GET /api/certificates/{id}/verify is called
THEN the response MUST return {valid: false, revokedAt, revocationReason}
  AND the HTTP status MUST be 200 (revocation is a valid state, not an error)
  AND personal data MUST NOT appear in the response
```

---

### REQ-CERT-003 — EDCI/Europass ELM payload on edciEnabled templates

The system MUST populate `Certificate.edciPayload` with a valid EDCI ELM JSON object when the CertificateTemplate has `edciEnabled=true`. The payload MUST conform to the Europass Learning Model (ELM) vocabulary.

#### Scenario CERT-003-A: EDCI payload generated for edci-enabled template

```
GIVEN a CertificateTemplate has edciEnabled=true
  AND a learner completes the associated course
WHEN CertificateIssuanceHandler issues the Certificate
THEN Certificate.edciPayload MUST be a non-null JSON object
  AND edciPayload['type'] MUST include 'EuropassCredential'
  AND edciPayload MUST include a 'title' field with a language-tagged string (e.g. {'nl': '...'})
  AND CredentialIssuance.edciSignatureRef MUST be non-empty
```

#### Scenario CERT-003-B: Bologna Diploma Supplement flag present for diploma award

```
GIVEN a CertificateTemplate has bolognaSupplement=true AND kind=diploma
  AND a registrar manually issues a diploma via the admin interface
WHEN the Certificate is saved with kind=diploma
THEN Certificate.edciPayload MUST include {'diplomaSupplement': true}
  AND an audit event 'credential.issued' MUST include issuedVia='diploma-award' in the CredentialIssuance
```

---

### REQ-CERT-004 — Expiry detection and tiered notifications

The system MUST detect `Certificate` objects with a non-null `expiresAt` date and dispatch `certificate_expiring` nc-notifications to the learner at T-90, T-60, and T-30 days before expiry. Each threshold notification MUST be dispatched at most once per Certificate per threshold. When a Certificate becomes expired (`daysUntilExpiry ≤ 0`), the lifecycle MUST automatically transition to `expired` and a final expiry notification MUST be dispatched.

#### Scenario CERT-004-A: T-90 expiry reminder dispatched exactly once

```
GIVEN a Certificate has expiresAt exactly 90 days in the future
  AND lifecycle=issued
WHEN OR's calculation engine evaluates isExpiringIn90Days=true for this Certificate
THEN the system MUST dispatch a 'scholiq.certificate.expiring.t90' nc-notification to the learner
  AND the notification MUST be dispatched at most once (idempotencyKey='expiryT90')
  AND an audit entry for the notification dispatch MUST be written via OR's notification engine
```

#### Scenario CERT-004-B: T-30 reminder triggers auto-enrolment in renewal module

```
GIVEN a Certificate has expiresAt exactly 30 days in the future
  AND lifecycle=issued
  AND a RenewalRule exists for the Certificate's templateId with triggerType=expiry
    AND RenewalRule.autoEnrol=true AND RenewalRule.renewalCourseSlug set
WHEN OR's calculation engine evaluates isExpiringIn30Days=true
THEN a 'scholiq.certificate.expiring.t30' notification MUST be dispatched to the learner
  AND the system MUST auto-enrol the learner in RenewalRule.renewalCourseSlug
    with source='system', mandatory=true, dueDate=Certificate.expiresAt
  AND an 'enrolment.created' audit event MUST be emitted for the renewal enrolment
```

#### Scenario CERT-004-C: Expired certificate transitions lifecycle automatically

```
GIVEN a Certificate has expiresAt in the past (daysUntilExpiry ≤ 0)
  AND lifecycle=issued
WHEN OR's calculation engine evaluates isExpired=true
THEN the Certificate.lifecycle MUST transition to 'expired' (via alsoDispatchLifecycle: expire)
  AND a 'credential.expired' audit event MUST be emitted per ADR-008
  AND a 'scholiq.certificate.expired' nc-notification MUST be dispatched to the learner
  AND the Certificate MUST remain in OpenRegister (not deleted) for audit purposes
```

---

### REQ-CERT-005 — Delta-module auto-enrolment on content version change

The system MUST automatically enrol all learners holding an active `Certificate` for a course in the associated delta module when a new `ContentVersion` is published for that course, if a `RenewalRule` with `triggerType=content-version` exists for the certificate template.

#### Scenario CERT-005-A: Delta module auto-enrolment triggered on ContentVersion publish

```
GIVEN a Course has active Certificates held by learners A, B and C
  AND a RenewalRule exists for the Certificate's templateId
    with triggerType=content-version AND deltaCourseSlug='radio-delta-module' AND autoEnrol=true
WHEN a new ContentVersion for the Course transitions to lifecycle=published
  AND ContentVersion.affectsCredentialKinds includes the Certificate.kind
THEN the system MUST create Enrolment objects for learners A, B and C in 'radio-delta-module'
  AND each Enrolment MUST have source='system', mandatory=true
  AND a compliance-officer nc-notification MUST be dispatched for the version change
  AND 'enrolment.created' audit events MUST be emitted for each auto-enrolment
```

#### Scenario CERT-005-B: Delta enrolment skipped for revoked/expired certificates

```
GIVEN a ContentVersion for course X is published
  AND learner D holds a Certificate for course X with lifecycle=revoked
  AND learner E holds a Certificate for course X with lifecycle=expired
WHEN DeltaEnrolmentHandler processes the content-version.published event
THEN the system MUST NOT create delta-module Enrolments for learners D and E
  AND only learners with lifecycle=issued certificates MUST be enrolled
```

---

### REQ-CERT-006 — Manual certificate issuance by admin/registrar

The system MUST allow a user with admin or hr role to manually issue a `Certificate` for a learner via OR's standard object creation endpoint with `source=manual`. Manual issuance MUST also create a `CredentialIssuance` record with `issuedVia=manual`.

#### Scenario CERT-006-A: Registrar manually issues a diploma certificate

```
GIVEN a registrar with admin role is authenticated
WHEN they POST a new Certificate via /api/openregister/scholiq/Certificate
  with {learnerId, templateId, kind: 'diploma', source: 'manual', issuedAt, expiresAt: null}
THEN the system MUST create a signed Certificate with lifecycle=issued
  AND a CredentialIssuance with issuedVia='diploma-award' MUST be created
  AND a 'credential.issued' audit event MUST be emitted with the registrar's userId as actor
  AND an issuedToLearner nc-notification MUST be dispatched to the learner
```

---

### REQ-CERT-007 — Certificate revocation

The system MUST support revoking a `Certificate` by transitioning its lifecycle to `revoked` with a `revocationReason`. Revoked Certificates MUST remain in OpenRegister and MUST NOT be deleted. The public verify endpoint MUST return `{valid: false}` for revoked certificates.

#### Scenario CERT-007-A: Admin revokes a certificate

```
GIVEN an admin user sends a lifecycle transition PATCH to Certificate lifecycle → revoked
  with revocationReason='Medewerker uit dienst'
WHEN the request is processed
THEN Certificate.lifecycle MUST be 'revoked' with revocationReason persisted
  AND a 'credential.revoked' audit event MUST be emitted with before/after snapshots per ADR-008
  AND GET /api/certificates/{id}/verify MUST return {valid: false, revocationReason}
  AND the Certificate object MUST remain in OpenRegister (no hard delete)
```

---

### REQ-CERT-008 — Certificate listing for compliance-audit consumption

The system MUST expose `Certificate` objects queryable via OR's standard query API, filterable by: `learnerId`, `courseId`, `kind`, `regulationSlug`, `lifecycle`, and date ranges on `issuedAt` / `expiresAt`. The compliance-audit spec uses this to enumerate valid certificates when building audit-pack evidence.

#### Scenario CERT-008-A: Compliance officer queries certificates for a regulation

```
GIVEN multiple Certificates exist for different regulations and lifecycle states
WHEN the compliance-audit spec queries OR's Certificate API
  with filter {regulationSlug: 'NIS2', lifecycle: 'issued'}
THEN the response MUST return only Certificates tagged NIS2 with lifecycle=issued
  AND each Certificate MUST include learnerId, issuedAt, expiresAt, verificationUrl
  AND revoked and expired certificates with regulationSlug=NIS2 MUST NOT appear
```

---

### REQ-CERT-009 — No AI features in Phase 1 (ADR-005 safeguard)

The certification spec MUST NOT introduce any AI/ML feature that influences certificate issuance or revocation decisions. All issuance and revocation in Phase 1 MUST be rule-based (enrolment completion event, content version change) or human-initiated (manual issuance, admin revocation).

#### Scenario CERT-009-A: AiFeature seed remains empty after certification install

```
GIVEN the certification spec is fully installed in v0.1
WHEN the AiFeature schema in scholiq_register.json is inspected
THEN it MUST declare zero AiFeature seed objects related to certification
  AND no AI-assisted credential issuance or revocation logic MUST be present in any PHP file
```

---

### REQ-CERT-010 — Tenant signing key management

The system MUST allow an admin to generate a per-tenant RS256 keypair for signing certificates. The private key MUST be stored encrypted via `OCP\Security\ICrypto`. The admin interface MUST expose key status (present / not configured) and a "Generate key" action.

#### Scenario CERT-010-A: Admin generates a signing key

```
GIVEN no signing key is configured for the tenant
WHEN an admin calls POST /api/certificates/admin/generate-key
THEN the system MUST generate an RSA-2048 keypair
  AND store the private key encrypted via ICrypto under app-config key 'scholiq.certificate.signing.<tenantId>'
  AND store the public key + fingerprint in plain app-config for verification
  AND return HTTP 201 with the public key fingerprint
  AND a 'security.config.changed' audit event MUST be emitted
```

#### Scenario CERT-010-B: Non-admin cannot generate a signing key

```
GIVEN a user with learner role is authenticated
WHEN they call POST /api/certificates/admin/generate-key
THEN the system MUST return HTTP 403
  AND no keypair MUST be generated or stored
```
