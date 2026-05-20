---
slug: compliance-audit
title: Compliance Training & Audit
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-20
updated: 2026-05-20
wedge_scope: Phase 1 — regulation tracking, signed attestation capture, live coverage %, audit-pack ZIP export, immutable evidence log, NIS2 board-cohort proof
---

# Compliance Training & Audit — Formal Requirements

## Overview

Compliance-audit is the Phase 1 wedge core. It consumes `Enrolment`, `XapiStatement`, `Credential`, and `LearnerProfile` objects from peer specs and produces two primary artefacts: live coverage % per regulation (with RAG status), and an on-demand audit-pack ZIP that must survive external auditor scrutiny. The immutable evidence log is governed by ADR-008 (OR's audit trail is the substrate). Attestations are HMAC-signed at write time via a lifecycle guard. EU AI Act (ADR-005) applies to any future AI analysis of coverage data — Phase 1 has none.

**Demand signal**: compliance management #1 canonical feature (score 166, 44 tenders, 17 competitors). Primary purchasing driver for corporate, government, and HE buyers under NIS2 / Cyberbeveiligingswet, AVG, and BIO mandates.

---

## Requirements

### REQ-CA-001 — Regulation tracking

The system MUST support creating and managing `Regulation` objects in OpenRegister. Each Regulation MUST have: slug (e.g. "NIS2", "AVG", "BIO", "INTEGRITEIT"), name, description, applicabilityCriteria (free text), audienceScope (enum: all-employees / board / role-specific / department), requiresAnnualRenewal (bool), renewalCycleMonths (int), ragRedThreshold (number), ragAmberThreshold (number), active (bool), tenant_id.

#### Scenario CA-001-A: Compliance officer creates a NIS2 regulation
```
GIVEN a compliance officer is authenticated with role 'admin' or 'compliance-officer'
WHEN they create a Regulation via the CnAppRoot form with:
  { slug:'NIS2', name:'NIS2 Cyberbeveiligingswet', audienceScope:'board',
    requiresAnnualRenewal:true, renewalCycleMonths:12,
    ragRedThreshold:80, ragAmberThreshold:95 }
THEN the system MUST create a Regulation object in OpenRegister with lifecycle='draft'
  AND return the created Regulation including its UUID
  AND OR's audit trail MUST contain a 'regulation.created' entry for the object
```

#### Scenario CA-001-B: Compliance officer publishes a draft regulation
```
GIVEN a Regulation with lifecycle='draft' exists
WHEN the compliance officer triggers the 'publish' lifecycle transition
THEN the Regulation MUST transition to lifecycle='published'
  AND OR's audit trail MUST emit event_type='compliance.regulation.published'
    with actor, timestamp, before/after snapshot
  AND the regulation MUST become visible in coverage aggregations
```

#### Scenario CA-001-C: Regulation links to mandatory lessons via regulationSlug
```
GIVEN a Regulation with slug='AVG' is published
  AND one or more Lesson objects have mandatory_training=true and regulation_slug='AVG'
WHEN the compliance dashboard queries Regulation coverage for 'AVG'
THEN the aggregation MUST use those Lessons' parent Courses as the scope
  AND the coverage % denominator MUST count only Enrolments with regulationSlug='AVG' AND mandatory=true
```

---

### REQ-CA-002 — Bulk-enrolment campaign for regulation (T-30/T-7/T-1)

The system MUST support orchestrating a bulk-enrolment campaign tied to a regulation by reusing the Enrolment change's `BulkEnrolModal`. The compliance officer selects regulation, audience, course section, and due date; the system creates individual Enrolments with `mandatory=true`, `regulationSlug=<slug>`, and the `BulkEnrolModal`'s T-30/T-7/T-1 notification schedule (declared via `x-openregister-notifications` on `Enrolment`).

#### Scenario CA-002-A: Compliance officer bulk-enrols all employees in annual AVG refresher
```
GIVEN the AVG regulation is published and a mandatory AVG course section exists
WHEN the compliance officer opens the BulkEnrolModal from the Compliance dashboard
  AND selects audience={nc_group_id:'all-employees'}, course_section_id=<uuid>,
      mandatory:true, due_date:'2026-11-30', regulationSlug:'AVG'
  AND submits the bulk-enrolment
THEN the system MUST create individual Enrolment objects for every active employee
  AND each Enrolment MUST carry mandatory=true, regulationSlug='AVG', source='bulk',
      dueDate='2026-11-30', bulkJobId=<shared uuid>
  AND OR's notification engine MUST schedule T-30, T-7, T-1 reminders per enrolment
    via x-openregister-notifications on the Enrolment schema (enrolment change)
  AND the compliance officer MUST receive a 'cohort_enrolment_done' notification
    when all Enrolments are created
```

#### Scenario CA-002-B: Campaign view on Compliance dashboard is a saved query
```
GIVEN multiple bulk-enrolment jobs have been submitted for the AVG regulation
WHEN the compliance officer views the "Campaigns" section on the Compliance dashboard
THEN the view MUST render a list of Enrolment groups filtered by regulationSlug='AVG'
    AND source='bulk', grouped by bulkJobId — sourced directly from OR's REST API
  AND NO separate ComplianceCampaign schema objects MUST exist in OR
```

---

### REQ-CA-003 — Signed attestation capture (ADR-002 + ADR-008)

The system MUST capture a signed attestation when a learner completes a mandatory lesson and clicks the attestation checkbox. The attestation MUST be append-only in OpenRegister and MUST be HMAC-SHA256 signed at write time via `AttestationSigningGuard` (per ADR-008). The signing key MUST be managed by OR's audit-trail tenant-key API — not by a Scholiq `HmacKeyService`.

#### Scenario CA-003-A: Learner submits attestation after completing mandatory content
```
GIVEN a learner has received a cmi5.completed xAPI statement for a lesson
    with mandatory_training=true AND regulation_slug='AVG'
WHEN the learner ticks the attestation checkbox and clicks "Onderteken attestatie"
THEN the frontend MUST POST an Attestation object to OR with
    { learnerId, lessonId, courseId, regulationSlug:'AVG', actorIp, employeeId, score,
      xapiStatementId, lifecycle:'drafted' }
  AND MUST immediately PATCH .../transition/sign to trigger the AttestationSigningGuard
  AND the guard MUST verify: xAPI completed/passed statement exists for (learnerId, lessonId)
  AND the guard MUST compute: signature = HMAC-SHA256(OR's current tenant key, canonical payload)
  AND the Attestation MUST be persisted as appendOnly:true (no subsequent UPDATE or DELETE allowed)
  AND OR MUST emit audit entry event_type='attestation.signed' with subject_id=<attestation UUID>
    per ADR-008's "consume OR audit-trail" rule
```

#### Scenario CA-003-B: Attestation without prior xAPI completion is rejected
```
GIVEN a learner navigates to the attestation checkbox
  AND NO cmi5.completed xAPI statement exists for the associated lesson in the XapiStatement schema
WHEN the learner attempts to submit the attestation
THEN the AttestationSigningGuard MUST return a rejection
  AND OR MUST reject the transition with HTTP 422
  AND the UI MUST display: "De training moet eerst worden voltooid voordat een attestatie kan worden ondertekend."
  AND NO Attestation object MUST be persisted in OR
```

#### Scenario CA-003-C: Tampered attestation detected by auditor
```
GIVEN an Attestation with lifecycle='signed' exists in OR
  AND the raw Attestation record has been externally tampered with (field modified outside lifecycle)
WHEN the audit pack for that regulation is exported
THEN signature-verification.txt MUST report the verification failure
  AND manifest.json MUST contain signature_status:'compromised'
    with the id of the first broken-signature attestation and its timestamp
  AND the export MUST still complete with HTTP 200 (reporting, not blocking)
```

#### Scenario CA-003-D: Attestation payload fields required by auditors
```
GIVEN an Attestation with lifecycle='signed' exists
WHEN an auditor queries OR for the attestation
THEN the response MUST include: learnerId, lessonId, courseId, regulationSlug,
    actorIp, employeeId, score, xapiStatementId, signature, keyRotationId, created (timestamp)
  AND learnerId MUST be an opaque internal UUID — never a BSN (per ADR-002 §Implementation notes)
```

---

### REQ-CA-004 — Live coverage % per regulation with RAG bands (ADR-002 + ADR-008)

The system MUST compute live coverage % per regulation for the tenant. Coverage % = `(mandatoryCompletedCount / mandatoryEnrolledCount) × 100`. The computation MUST use `x-openregister-aggregations` on the `Regulation` schema (sourcing from `Enrolment` and xAPI-derived state) — NOT a separate "completions" table. Per-regulation RAG thresholds MUST be configurable.

#### Scenario CA-004-A: Coverage % returned within 2 seconds for up to 500 enrolments
```
GIVEN a regulation 'AVG' has 200 mandatory Enrolments (120 completed, 80 active)
WHEN the Compliance dashboard loads the coverageGrid widget
THEN OR's aggregation engine MUST return:
    { coveragePercent: 60.0, mandatoryEnrolledCount: 200, mandatoryCompletedCount: 120 }
  AND the response MUST arrive within 2 seconds
  AND ragStatus MUST be 'red' (60.0 < ragRedThreshold default 70)
```

#### Scenario CA-004-B: RAG status bands follow per-regulation thresholds
```
GIVEN NIS2 has ragRedThreshold=80, ragAmberThreshold=95
  AND coveragePercent = 83.0
WHEN the dashboard renders the NIS2 row
THEN ragStatus MUST be 'amber' (83.0 >= 80 BUT < 95)
  AND the UI MUST render an amber colour band for NIS2
  AND a green band for any regulation where coveragePercent >= ragAmberThreshold
```

#### Scenario CA-004-C: Coverage % reflects real-time xAPI completions (cache invalidation)
```
GIVEN AVG coverage was last computed at 80% (160 of 200 completed)
  AND 10 more employees complete the course and cmi5.completed statements land in the LRS
WHEN the Compliance dashboard reloads
THEN the response MUST reflect 170 completions (85% coverage, ragStatus='amber')
  AND the stale cached value MUST have been invalidated by the xapi.statement.received audit event
    triggering OR's aggregation-recalculation on the dependent Enrolment schema
```

#### Scenario CA-004-D: Officer alert fires when coverage drops to red
```
GIVEN a regulation has ragStatus='amber'
  AND 5 enrolments are withdrawn causing coveragePercent to drop below ragRedThreshold
WHEN OR's calculation engine recomputes ragStatus to 'red'
THEN the x-openregister-notifications.officerAlertOnCoverageDrop trigger MUST fire
  AND OR MUST dispatch a nc-notification to every user with the 'compliance-officer' tenant role
  AND OR's audit trail MUST record a notification dispatch entry
```

---

### REQ-CA-005 — Audit-pack export (ADR-008 §6)

The system MUST provide an on-demand export producing a ZIP per ADR-008 §6 containing: `audit-trail.ndjson`, `audit-trail.csv`, `manifest.json`, `signature-verification.txt`. The export MUST be filterable by regulation_slug + date range.

#### Scenario CA-005-A: Compliance officer exports AVG audit pack for Q1
```
GIVEN a compliance officer opens AuditPackExportModal
  AND selects regulation='AVG', date_from='2026-01-01', date_to='2026-03-31'
  AND clicks "Exporteer auditpakket"
WHEN POST /api/compliance/audit/export is called with {regulationSlug:'AVG', dateFrom, dateTo}
THEN the system MUST return HTTP 200 with Content-Type: application/zip
  AND the ZIP MUST contain audit-trail.ndjson with all attestation.*, credential.*,
    enrolment.completed, compliance.* events for AVG-tagged content in the date range
  AND the ZIP MUST contain audit-trail.csv (flat representation of the same events)
  AND the ZIP MUST contain manifest.json with:
    { tenant_id, period:{from,to}, regulation_slug:'AVG',
      event_count:<n>, signature_status:<ok|compromised>,
      export_timestamp, verification_key_fingerprint }
  AND the ZIP MUST contain signature-verification.txt with the HMAC chain result
    per tenant key rotation period (sourced from OR's verification endpoint)
  AND OR MUST emit audit entry event_type='compliance.audit_pack.exported'
```

#### Scenario CA-005-B: Audit pack is verifiable offline
```
GIVEN a downloaded audit pack ZIP from Scenario CA-005-A
WHEN an auditor inspects signature-verification.txt
THEN the file MUST contain: the public verification key fingerprint,
    the hash-chain verification result (valid/compromised),
    the number of events verified, and the date of export
  AND the auditor MUST be able to confirm that no event was deleted or modified after recording
    by verifying the hash chain using OR's published verification algorithm
```

#### Scenario CA-005-C: Export with zero events in range returns valid empty pack
```
GIVEN a regulation 'INTEGRITEIT' with lifecycle='draft' has no audit events in 2026-Q1
WHEN POST /api/compliance/audit/export is called for INTEGRITEIT in that date range
THEN the system MUST return HTTP 200 with a valid ZIP
  AND manifest.json MUST contain event_count:0 and signature_status:'ok'
  AND audit-trail.ndjson MUST be present but empty
```

---

### REQ-CA-006 — NIS2 board-cohort proof (wedge success criterion)

The system MUST provide a board-cohort proof view showing attendance, content version, certificate, and date for every board member of a NIS2 cyber-awareness training. This view MUST be filterable by period and exportable via the standard audit-pack export.

#### Scenario CA-006-A: Auditor verifies NIS2 board-training conformity
```
GIVEN the NIS2 regulation is configured with audienceScope='board'
  AND the 'board' NC group contains 8 members
  AND all 8 have completed the NIS2 course and have lifecycle='signed' Attestations
WHEN the boardProof widget renders on the Compliance dashboard
THEN the widget MUST display:
    { coveragePercent: 100.0, validCredentialCount: 8 }
  AND the Regulation detail page's audit-trail tab MUST list per-member rows showing:
    learnerId, attestation date, score, credential_id for each of the 8 board members
```

#### Scenario CA-006-B: Incomplete board coverage shown as red
```
GIVEN NIS2 audienceScope='board', 12 board members enrolled, only 9 have signed attestations
WHEN the boardProof widget renders
THEN coveragePercent MUST be 75.0
  AND ragStatus MUST be 'red' (75.0 < ragRedThreshold=80 for NIS2)
  AND the widget MUST render a red RAG band
```

---

### REQ-CA-007 — Immutable evidence log integrity (ADR-008)

The system MUST enforce that Attestation objects cannot be modified or deleted after signing. Any mutation attempt MUST be blocked at the OR schema level (`appendOnly: true`) and MUST be surfaced in the audit pack.

#### Scenario CA-007-A: Attempted modification of a signed attestation is rejected
```
GIVEN an Attestation with lifecycle='signed' and id=<uuid> exists in OR
WHEN any code path attempts to UPDATE or DELETE the Attestation object
THEN OR MUST reject the call (HTTP 405 Method Not Allowed or schema violation)
    because the Attestation schema declares appendOnly:true — consumed from OR per ADR-022
  AND the Attestation record MUST remain unchanged
  AND OR's audit trail MUST record a tamper-attempt entry
  AND on the next audit-pack export, signature-verification.txt MUST flag any integrity violation
```

#### Scenario CA-007-B: Revocation is permitted only via lifecycle transition
```
GIVEN an Attestation with lifecycle='signed' needs to be invalidated (e.g. employee left)
WHEN a compliance officer triggers the 'revoke' lifecycle transition
THEN OR MUST transition the Attestation to lifecycle='revoked'
  AND MUST emit event_type='attestation.revoked' in the audit trail
  AND the original signed Attestation data MUST remain readable (append-only: the revoke
      transition adds a new audit row, it does NOT delete the signed record)
  AND the attestationCount aggregation on the relevant Regulation MUST DECREMENT
      because the filter is lifecycle='signed' and 'revoked' is excluded
```

---

### REQ-CA-008 — No AI features in Phase 1 (ADR-005 safeguard)

The compliance-audit spec MUST NOT introduce any AI/ML feature in Phase 1. All coverage computation, export generation, and attestation capture MUST use deterministic rule-based logic. Any AI coverage risk classification or AI-driven anomaly detection is deferred to Enterprise tier with mandatory ADR-005 gating.

#### Scenario CA-008-A: AiFeature registry is empty after compliance-audit install
```
GIVEN the compliance-audit change is fully installed in v0.1
WHEN the AiFeature schema is queried in OR
THEN it MUST return zero AiFeature objects with riskLevel='high-risk' or any ai-compliance slug
  AND no AI-scoring logic MUST be reachable from any compliance-audit controller or lifecycle guard
```

---

### REQ-CA-009 — Regulation retention (ADR-008 §7)

Attestation and Credential objects linked to compliance regulations MUST have a 10-year retention class. Security events MUST have a 1-year retention class. Retention MUST be declared on the schema and enforced by OR's archival/destruction-workflow abstraction — NOT by a Scholiq-side retention service.

#### Scenario CA-009-A: Attestation retention declared on schema
```
GIVEN the Attestation schema is imported into OR via scholiq_register.json
WHEN OR's archival service evaluates the retention class for Attestation objects
THEN the retention class MUST be 10 years (consistent with AVG general accounting + AI Act Art. 12)
  AND OR MUST NOT purge any Attestation object before its 10-year retention window expires
  AND any destruction outside the retention window MUST require an OR archival-workflow trigger
    with an audit entry — not a direct DELETE from any Scholiq code path
```
