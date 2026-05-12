---
slug: compliance-audit
title: Compliance Training & Audit
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-11
updated: 2026-05-11
wedge_scope: Phase 1 — regulation tracking, attestation capture, coverage %, audit-pack export, immutable log
---

# Compliance Training & Audit — Formal Requirements

## Overview

Compliance-audit is the wedge core. It reads from all other specs (Courses, Enrolments, xAPI statements, Credentials) and produces two primary artefacts: a live coverage % per regulation, and an export-on-demand audit-pack ZIP that must survive external auditor scrutiny. The immutable evidence log is governed by ADR-008; attestations are signed at write time using HMAC-SHA256. The EU AI Act gate (ADR-005) applies to any future AI analysis of coverage data; Phase 1 has none.

---

## Requirements

### REQ-CA-001 — Regulation tracking

The system MUST support creating and managing `Regulation` objects in OpenRegister. Each Regulation MUST have: id, slug (e.g. "NIS2", "AVG", "BIO"), name, description, applicability_criteria (free text), audience_scope (enum: all-employees/board/role-specific), requires_annual_renewal (bool), renewal_cycle_months (int), active (bool), tenant_id.

#### Scenario CA-001-A: Compliance officer creates a NIS2 regulation
```
GIVEN a compliance officer is authenticated with role 'admin' or 'hr'
WHEN they POST /api/compliance/regulations with {slug:'NIS2', name:'NIS2 Cyberbeveiligingswet', audience_scope:'board', requires_annual_renewal:true, renewal_cycle_months:12}
THEN the system MUST create a Regulation object in OpenRegister
  AND return HTTP 201 with the created Regulation including UUID
  AND emit audit event 'compliance.regulation.published' per ADR-008
```

#### Scenario CA-001-B: Regulation links to mandatory lessons
```
GIVEN a Regulation with slug='NIS2' exists
  AND a Lesson has mandatory_training=true and regulation_slug='NIS2'
WHEN GET /api/compliance/regulations/NIS2/courses is called
THEN the response MUST return all Courses containing at least one such Lesson
  AND the coverage % endpoint MUST use this set as the denominator's course list
```

---

### REQ-CA-002 — Signed attestation capture (ADR-008)

The system MUST capture a signed attestation when a learner completes a mandatory lesson and clicks the attestation checkbox. The attestation MUST be append-only in OpenRegister and MUST be HMAC-signed at write time per ADR-008 §5.

#### Scenario CA-002-A: Learner submits attestation after viewing mandatory content
```
GIVEN a learner has a cmi5.completed xAPI statement for a lesson with mandatory_training=true, regulation_slug='AVG'
WHEN the learner clicks the attestation checkbox and submits
THEN the frontend MUST POST an Attestation object to OpenRegister via `ObjectService::saveObject('scholiq-attestation', { learnerId, lessonId, courseId, regulationSlug, score, lifecycleState: 'signed' })` (no AttestationService PHP class)
AND the `AttestationSigningGuard` (declared as the `x-openregister-lifecycle.transitions.sign.requires` on the Attestation schema) MUST validate the precondition (matching cmi5.completed statement exists for the learner+lesson)
AND the guard MUST compute Attestation.signature = HMAC-SHA256(OpenRegister's current tenant key from the audit-trail abstraction, canonicalized payload minus signature)
AND the Attestation MUST persist in OpenRegister as an `appendOnly: true` object (per ADR-022 — consumed from OR, not an app-local append-only store)
AND an OR audit-trail entry MUST be emitted automatically by the lifecycle transition with `event_type = attestation.signed` and `subject_id = the Attestation UUID` (per ADR-008's "consume OR audit-trail" rule)
```

#### Scenario CA-002-B: Attestation without prior xAPI completion is rejected
```
GIVEN a learner navigates to the attestation checkbox
  AND no cmi5.completed xAPI statement exists for the associated lesson in the LRS
WHEN the learner attempts to submit the attestation
THEN the system MUST return HTTP 422 with error "Course content must be completed before attestation"
  AND MUST NOT create any Attestation object
```

#### Scenario CA-002-C: Tampered attestation detected by auditor
```
GIVEN an Attestation has been created with a valid HMAC signature
  AND the raw Attestation record in OpenRegister has been tampered with (field modified externally)
WHEN the admin runs the signature verification check (GET /api/compliance/audit/verify-trail)
THEN the system MUST report the first broken-signature attestation with its id and timestamp
  AND MUST return HTTP 200 with {status:'compromised', first_broken_at, event_id}
```

---

### REQ-CA-003 — Coverage % computation (ADR-002 + ADR-008)

The system MUST compute live coverage % per regulation for a given tenant. Coverage % = (number of employees with a valid Credential or a completed Enrolment + attestation for the regulation's courses) / (total mandatory Enrolments for those courses). The computation MUST rely on xAPI statement data (ADR-002) and Enrolment records, not a separate "completed" table.

#### Scenario CA-003-A: Coverage % returned within 2 seconds
```
GIVEN 200 employees are enrolled in an AVG mandatory course with 120 completions
WHEN GET /api/compliance/coverage?regulation_slug=AVG is called
THEN the system MUST return a response within 2 seconds
  AND the response MUST include {regulation_slug:'AVG', enrolled:200, completed:120, coverage_percent:60.0, rag_status:'amber'}
```

#### Scenario CA-003-B: RAG status bands
```
GIVEN a regulation coverage % is computed
WHEN coverage_percent >= 90 → rag_status='green'
     coverage_percent >= 70 → rag_status='amber'
     coverage_percent < 70  → rag_status='red'
THEN the response MUST include the correct rag_status
  AND the dashboard MUST render the corresponding colour band
```

#### Scenario CA-003-C: Coverage % reflects real-time xAPI completions
```
GIVEN coverage was last computed at 80% (160 of 200 completed)
  AND 10 more employees complete the course and their cmi5.completed statements land in the LRS
WHEN GET /api/compliance/coverage?regulation_slug=AVG is called again
THEN the response MUST reflect 170 completions (85% coverage)
  AND the stale cache MUST have been invalidated by the xapi.statement.received audit event
```

---

### REQ-CA-004 — Audit-pack export (ADR-008 §6)

The system MUST provide an on-demand export generating a ZIP per ADR-008 §6 containing: `audit-trail.ndjson`, `audit-trail.csv`, `manifest.json`, `signature-verification.txt`. The export MUST be filterable by regulation_slug + date range.

#### Scenario CA-004-A: Compliance officer exports AVG audit pack
```
GIVEN a compliance officer calls POST /api/compliance/audit/export with {regulation_slug:'AVG', date_from:'2026-01-01', date_to:'2026-12-31'}
WHEN the export service processes the request
THEN the system MUST return HTTP 200 with Content-Type: application/zip
  AND the ZIP MUST contain audit-trail.ndjson with all attestation.*, credential.*, enrolment.* events for AVG-tagged content in the date range
  AND the ZIP MUST contain audit-trail.csv (flat CSV of the same events)
  AND the ZIP MUST contain manifest.json with: tenant_id, period, regulation_slug, event_count, signature_status, export_timestamp
  AND the ZIP MUST contain signature-verification.txt with HMAC chain result per tenant key rotation period
  AND an audit event 'compliance.audit_pack.exported' MUST be emitted per ADR-008
```

#### Scenario CA-004-B: Audit pack is verifiable offline
```
GIVEN a downloaded audit pack ZIP from Scenario CA-004-A
WHEN an auditor uses the provided verification script or inspects signature-verification.txt
THEN the auditor MUST be able to confirm that no event was deleted or modified after recording
  AND the manifest.json MUST contain the public verification key fingerprint
```

---

### REQ-CA-005 — Campaign management for bulk-enrolment

The system MUST support creating a `ComplianceCampaign` that orchestrates: regulation selection, audience definition, course section selection, due_date, and notification schedule. Creating a campaign MUST trigger bulk-enrolment via `enrolment/enrolment-bulk-create`.

#### Scenario CA-005-A: Compliance officer creates annual NIS2 campaign
```
GIVEN a compliance officer creates a campaign: {regulation_slug:'NIS2', audience:{nc_group_id:'all-board'}, course_section_id:<uuid>, due_date:'2026-11-01', notification_days:[30,7,1]}
WHEN POST /api/compliance/campaigns is called
THEN the system MUST create a ComplianceCampaign object
  AND MUST trigger BulkEnrolmentService with audience, course_section_id, mandatory=true, due_date
  AND the campaign MUST track the bulk_job_id for status polling
  AND an audit event 'compliance.campaign.created' MUST be emitted
```

---

### REQ-CA-006 — NIS2 board-training proof (wedge success criterion)

The system MUST support generating a board-cohort proof report: a filtered view of the audit-pack showing only learners in a configured "board" audience group, for a specific regulation and period. This report MUST be exportable as a named PDF or ZIP.

#### Scenario CA-006-A: Auditor verifies NIS2 board proof
```
GIVEN the NIS2 regulation is configured with audience_scope='board' and the board NC group contains 12 users
WHEN GET /api/compliance/regulations/NIS2/board-proof?period_year=2026 is called
THEN the response MUST return a summary: {board_members:12, certified:12, not_certified:0, coverage_percent:100}
  AND include per-member rows: {name, enrolment_date, completion_date, attestation_signed, credential_id, credential_verify_url}
```

---

### REQ-CA-007 — No AI features in Phase 1 (ADR-005 safeguard)

The compliance-audit spec MUST NOT introduce any AI/ML feature. All coverage computation, export, and attestation capture MUST be deterministic rule-based logic. No AI risk classification of learner compliance status is permitted in Phase 1.

#### Scenario CA-007-A: AI registry empty after compliance-audit install
```
GIVEN the compliance-audit spec is fully installed in v0.1
WHEN AiFeatureRegistry::all() is called
THEN it MUST return an empty array (no AI features registered)
```

---

### REQ-CA-008 — Immutable evidence log integrity (ADR-008)

The system MUST enforce that attestation and audit-event records cannot be modified or deleted. Any attempt MUST be blocked at the OpenRegister schema level (append_only: true) and at the database level (REVOKE UPDATE/DELETE for the application user).

#### Scenario CA-008-A: Attempted modification of attestation is rejected
```
GIVEN an Attestation record exists with id=abc123
WHEN any code path attempts to call `ObjectService::updateObject('scholiq-attestation', 'abc123', ...)` or `ObjectService::deleteObject('scholiq-attestation', 'abc123')`
THEN OpenRegister MUST reject the call (HTTP 405 or schema violation) because the Attestation schema declares `appendOnly: true` — consumed from OR per ADR-022, not enforced by an app-local guard
  AND the Attestation record MUST remain unchanged
  AND OR's audit-trail abstraction MUST emit a `security.config.changed` entry per ADR-008 if the attempt comes from outside the lifecycle-driven flow
```
