---
slug: certification
title: Certification & Digital Credentials
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-11
updated: 2026-05-11
wedge_scope: Phase 1 — Open Badges 3.0 issuance + expiry detection; EDCI/Europass deferred to Phase 3
---

# Certification — Formal Requirements

## Overview

Certification captures the verified outcome of a learner completing mandatory compliance training: an Open Badges 3.0 credential signed with the tenant's key, a public verification URL, expiry detection with tiered reminders, and auto-enrolment in renewal modules. All credential state changes emit audit events per ADR-008. The EU AI Act gate (ADR-005) applies if future AI-driven credential revocation is added; Phase 1 has no AI.

---

## Requirements

### REQ-CE-001 — Credential issuance on enrolment completion

The system MUST automatically issue a `Credential` when an Enrolment transitions to `status='completed'` and the associated Course has a `certificate_template` configured. Issuance MUST complete within 30 seconds of the enrolment.completed event.

#### Scenario CE-001-A: Certificate issued within 30 seconds of completion
```
GIVEN a learner's Enrolment status transitions to 'completed' (via EnrolmentCompletionListener)
  AND the Course has certificate_template configured
WHEN EnrolmentCompletedCredentialListener processes the enrolment.completed event
THEN the system MUST create a Credential object in OpenRegister within 30 seconds
  AND the Credential.kind MUST be 'certificate'
  AND the Credential.issued_at MUST equal the enrolment.completed_at timestamp
  AND an audit event 'credential.issued' MUST be emitted per ADR-008
  AND a 'credential_issued' notification MUST be dispatched to the learner
```

#### Scenario CE-001-B: No certificate issued when no template configured
```
GIVEN a learner's Enrolment transitions to 'completed'
  AND the Course has NO certificate_template configured
WHEN EnrolmentCompletedCredentialListener processes the event
THEN the system MUST NOT create a Credential object
  AND MUST NOT emit a 'credential.issued' audit event
```

---

### REQ-CE-002 — Open Badges 3.0 signed assertion (ADR-002 cross-reference)

The system MUST sign each issued Credential as an Open Badges 3.0 assertion using the tenant's RS256 keypair stored in `OCP\Security\ICrypto`. The signed assertion MUST be stored in `Credential.openbadges3_payload`.

#### Scenario CE-002-A: Issued credential contains valid OB3 assertion
```
GIVEN a Credential is issued for learner X completing NIS2 course Y
WHEN the credential is retrieved via GET /api/credentials/{id}
THEN the response MUST include openbadges3_payload as a valid JSON-LD object
  AND openbadges3_payload['@context'] MUST include 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json'
  AND openbadges3_payload['credentialSubject']['id'] MUST be the learner's opaque UUID (not BSN)
  AND openbadges3_payload['proof'] MUST be a non-empty linked-data signature object
```

#### Scenario CE-002-B: Credential verification via public URL
```
GIVEN a valid Credential has been issued with id=abc123
WHEN an unauthenticated external party calls GET /api/credentials/abc123/verify
THEN the system MUST return HTTP 200 with {valid: true, issued_at, expires_at, issuer_name}
  AND MUST NOT require NC session authentication for this endpoint
  AND MUST NOT return any personal data beyond the credential metadata
```

#### Scenario CE-002-C: Revoked credential returns invalid on verify
```
GIVEN a Credential has been revoked with revoked=true and a revocation_reason
WHEN GET /api/credentials/{id}/verify is called
THEN the response MUST return {valid: false, revoked_at, revocation_reason}
  AND the HTTP status MUST be 200 (not 4xx — revocation is a valid state, not an error)
```

---

### REQ-CE-003 — Expiry detection and tiered notifications

The system MUST detect Credentials with an `expires_at` date and dispatch `credential_expiring` notifications to the learner and their manager at T-90, T-60, and T-30 days before expiry. Each threshold notification MUST be dispatched at most once per Credential per threshold.

#### Scenario CE-003-A: T-90 expiry reminder dispatched
```
GIVEN a Credential has expires_at exactly 90 days in the future and revoked=false
WHEN the daily CredentialExpiryJob runs
THEN the system MUST dispatch a 'credential_expiring' notification to the learner
  AND MUST record reminder_90_sent=true on the Credential to prevent repeat dispatch
  AND MUST emit audit event 'credential.expiry.reminder.sent' per ADR-008
```

#### Scenario CE-003-B: T-30 reminder triggers auto-enrolment in renewal module
```
GIVEN a Credential has expires_at exactly 30 days in the future
  AND the Course has a renewal_course_id configured
WHEN the daily CredentialExpiryJob processes the T-30 threshold
THEN in addition to the notification, the system MUST auto-enrol the learner in the renewal_course_id's default section with mandatory=true, source='system', due_date=expires_at
  AND emit 'enrolment.created' audit event for the auto-enrolment per ADR-008
```

#### Scenario CE-003-C: Expired credential status updated
```
GIVEN a Credential has expires_at in the past and revoked=false
WHEN the daily CredentialExpiryJob runs
THEN the system MUST emit audit event 'credential.expired' per ADR-008
  AND dispatch 'credential_expiring' (expired variant) notification to learner and manager
  AND the credential MUST remain in OpenRegister (not deleted) for audit purposes
```

---

### REQ-CE-004 — Manual credential issuance

The system MUST allow an admin or hr role user to manually issue a Credential for a learner via `POST /api/credentials` with fields: learner_id, course_id, kind, expires_at (optional), reason.

#### Scenario CE-004-A: Admin manually issues credential
```
GIVEN a compliance officer authenticates as admin
WHEN they POST /api/credentials with {learner_id, course_id, kind:'certificate', reason:'external-course-completion'}
THEN the system MUST create a signed Credential
  AND emit 'credential.issued' audit event with lawful_basis and reason fields per ADR-008
  AND return HTTP 201 with the created Credential
```

---

### REQ-CE-005 — Credential revocation

The system MUST support revoking a Credential via `PATCH /api/credentials/{id}` with `{revoked: true, revocation_reason: '...'}`. Revoked Credentials MUST remain in OpenRegister; they are NOT deleted.

#### Scenario CE-005-A: Admin revokes a credential
```
GIVEN an admin user sends PATCH /api/credentials/{id} with {revoked:true, revocation_reason:'employee left'}
WHEN the request is processed
THEN Credential.revoked MUST be true with revocation_reason persisted
  AND audit event 'credential.revoked' MUST be emitted with before/after snapshots per ADR-008
  AND a 'credential_expiring' notification (revoked variant) MUST be dispatched to the learner
  AND GET /api/credentials/{id}/verify MUST return {valid:false}
```

---

### REQ-CE-006 — Credential listing (compliance officer view)

The system MUST provide `GET /api/credentials` with filters: learner_id, course_id, kind, expires_before, revoked, regulation_slug (via course link). The compliance-audit spec uses this endpoint to enumerate valid credentials when building audit-pack evidence.

#### Scenario CE-006-A: Compliance officer queries credentials for a regulation
```
GIVEN multiple credentials exist for different regulations
WHEN a compliance officer calls GET /api/credentials?regulation_slug=NIS2&revoked=false
THEN the response MUST return only Credentials for courses tagged NIS2 where revoked=false
  AND each Credential MUST include learner_id, issued_at, expires_at, and verification URL
```

---

### REQ-CE-007 — No AI features in Phase 1 (ADR-005 safeguard)

The certification spec MUST NOT introduce any AI/ML feature that influences credential issuance or revocation decisions. All issuance and revocation in Phase 1 MUST be rule-based (enrolment completion event) or human-initiated.

#### Scenario CE-007-A: AI feature registry remains empty after certification install
```
GIVEN the certification spec is fully installed in v0.1
WHEN AiFeatureRegistry::all() is called
THEN it MUST return an empty array (no AI features registered by certification)
```
