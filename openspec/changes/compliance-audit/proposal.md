## Why

Compliance-audit is the **#1 canonical feature** and primary purchasing reason for the Phase 1 wedge (demand score 166, 44 tenders, 17 competitors). NIS2 / Cyberbeveiligingswet compels Dutch boards to prove cyber-awareness training for every member. AVG and BIO mandate annual refresher cycles with evidence that survives external auditor scrutiny. Without this spec the wedge has no deliverable for the corporate, government, and HE buyers who are Scholiq's target customers.

The spec rests on two architectural decisions already accepted:
- **ADR-008** — Scholiq's immutable evidence log IS OpenRegister's append-only audit trail. No parallel `scholiq-audit-event` schema; no `HmacKeyService`; no `AuditedController`. OR owns the hash-chain, signing keys, and retention.
- **ADR-002** — Coverage % is derived from xAPI statements (verb=`completed`/`passed`) stored in the `XapiStatement` schema via OR's audit trail, not from a separate "completions" table.

## What Changes

- Add OpenRegister schema `Regulation` to `lib/Settings/scholiq_register.json` with: slug, name, description, applicabilityCriteria, audienceScope (enum), requiresAnnualRenewal, renewalCycleMonths, ragRedThreshold, ragAmberThreshold, tenant_id. Lifecycle: `draft → published → archived`. Aggregations: `mandatoryEnrolledCount`, `mandatoryCompletedCount`, `attestationCount`, `validCredentialCount`. Calculations: `coveragePercent`, `ragStatus`. Notifications: `officerAlertOnCoverageDrop` (calculatedChange trigger). Widgets: `coverageGrid` (coverage + RAG grid), `boardProof` (NIS2 board-cohort stats block).
- Add OpenRegister schema `Attestation` to `lib/Settings/scholiq_register.json` with: learnerId, lessonId, courseId, regulationSlug, actorIp, employeeId, score, xapiStatementId, signature, keyRotationId, tenant_id. `appendOnly: true`. Lifecycle: `drafted → signed → revoked` with `AttestationSigningGuard` precondition on the sign transition.
- Add `lib/Lifecycle/AttestationSigningGuard.php` — single-method lifecycle guard: (a) verifies xAPI completion exists for `(learnerId, lessonId)` in the `XapiStatement` schema; (b) computes HMAC-SHA256 signature via OR's audit-trail tenant-key API; (c) sets `signature` + `keyRotationId` on the transition payload. ADR-031 legitimate exception (lifecycle guard + cryptographic operation).
- Add `lib/Controller/AuditPackExportController.php` — streams a ZIP containing `audit-trail.ndjson`, `audit-trail.csv`, `manifest.json`, `signature-verification.txt` filtered by regulation_slug + date range. Queries OR's audit-trail API; no local signing. ADR-031 legitimate exception (document generation).
- Extend `src/manifest.json` with: `RegulationDetail` page (detail, register=scholiq, schema=Regulation, auditTrail tab), `AuditPackExport` page (custom, component=AuditPackExportModal), `Compliance` dashboard page (widget-refs to `Regulation.x-openregister-widgets.coverageGrid` + `boardProof`).
- Add `src/views/AuditPackExportModal.vue` — regulation dropdown + date-range pickers + export button. Registered via `customComponents` on `CnAppRoot`.
- Extend `src/views/LessonPlayer.vue` (course-management change) — after `cmi5.completed` from the AU, render an inline attestation card when `mandatoryTraining=true` AND `regulationSlug` is set.

## Capabilities

### New Capabilities

- `compliance-audit`: Regulation lifecycle tracking (draft → published → archived), live coverage % per regulation with red/amber/green RAG bands and 12-month trend via `x-openregister-aggregations` + `-calculations`, signed attestation capture (append-only, HMAC-signed at write time via signing guard), officer alert on coverage drop, compliance dashboard with declarative widgets, audit-pack ZIP export (ndjson + csv + manifest + signature verification), NIS2 board-cohort proof, T-30/T-7/T-1 bulk-enrolment campaign trigger via existing Enrolment bulk-enrol flow.

### Modified Capabilities

- `course-management/lesson-player` — extended with inline attestation view rendered after cmi5.completed (conditioned on `mandatoryTraining=true` AND `regulationSlug` set).

## Impact

- **`Regulation` schema**: the `x-openregister-aggregations` block reads `Enrolment` + `Attestation` + `Credential` schemas — all must be present (enrolment and certification changes are prerequisites). The `officerAlertOnCoverageDrop` notification fires when `ragStatus` calculates to `"red"` via a `calculatedChange` trigger.
- **`Attestation` schema**: `appendOnly: true` consumes OR's append-only abstraction (ADR-022). The `AttestationSigningGuard` is the **only** PHP that touches the signing path; it reads OR's tenant-key API (no app-local `HmacKeyService`, no `key_rotation_id` field on a separate signing table).
- **No `ComplianceCampaign` schema**: a campaign IS a set of Enrolments sharing the same `regulationSlug` + `bulkJobId`. The compliance dashboard's "Campaign" view is a saved query over Enrolments from the existing `BulkEnrolModal` (declared in the enrolment change). This will only be revisited if a future feature needs per-campaign metadata that cannot be expressed on Enrolment.
- **No `EvidenceLog` schema**: the immutable evidence log IS OR's audit trail (ADR-008 §3). The `CnObjectSidebar` audit-trail tab on the Regulation detail page, filtered to `event_type IN (attestation.signed, attestation.revoked, credential.issued, enrolment.completed)`, IS the evidence log surface.
- **Ordering dependency**: this spec MUST land after `nextcloud-app`, `course-management`, `enrolment`, and `certification` changes — it reads from all of them.
- **`AuditPackExportController`**: the only Scholiq PHP that reads OR's audit-trail API. It does not write; it does not sign. Streams response directly from OR's query + verification endpoints.
