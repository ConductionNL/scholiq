---
adr_id: ADR-008
title: Immutable audit trail as architectural foundation
status: accepted
category: architecture
date: 2026-05-11
accepted_at: 2026-05-11
deciders:
  - architecture-team
  - dpo
supersedes: []
depends_on: []
applies_to:
  - compliance-audit
  - certification
  - course-management
  - enrolment
  - assessment-engine
  - proctoring
  - dashboard
  - nextcloud-app
---

# ADR-008 — Immutable audit trail as architectural foundation

## Status
**accepted** (2026-05-11) — foundational. Every state-changing controller endpoint MUST emit an `audit_event` via `Scholiq\Service\AuditTrail::record()` in the same DB transaction as the projection-table write. PHPStan custom rule + integration test in `tests/Unit/AuditTrailEnforcementTest.php` (to land with the wedge implementation) enforce this. ADR-002 (xAPI statements) and ADR-005 (AI decisions) are specialisations of this pattern.

## Context

Scholiq's wedge (Phase 1, compliance-audit, per WEDGE-PLAN.md) is literally an audit trail: prove who completed which mandatory training, when, with what signed attestation, and export that evidence in a form that survives an external audit. Several critical user stories make this concrete:

- *"Maintain immutable evidence log"* (compliance-training, critical)
- *"Export audit pack per regulation"* (compliance-training, critical)
- *"Capture signed attestation per learner"* (compliance-training, critical)
- *"See coverage % per regulation in real time"* (compliance-training, critical)
- *"Detect upcoming certificate expiries"* (compliance-training, critical)

Beyond the wedge, three foundational requirements force audit-trail discipline into every Scholiq capability:

1. **AVG/GDPR Art. 5 + 30**: data minimisation + record-of-processing-activities. Every personal-data write must be attributable to a lawful basis and a responsible actor; deletions must be auditable.
2. **EU AI Act Art. 12 + 14** (per ADR-005): every high-risk AI decision must persist a decision audit log retained ≥ 10 years.
3. **NIS2 / Cyberbeveiligingswet**: incident-response evidence requires reconstructable event history for security-relevant events (failed logins, permission changes, encrypted-data access).

Brief insights (architecture):
> *"Nextcloud as education platform: strong privacy positioning. Self-hosted deployment means schools control their data."*
> *"High switching costs create both barrier and opportunity. Dutch schools face 12-18 month migration cycles when switching LVS/LMS."* (interpreted: easy data export = easy migration in)

We need a single audit-trail substrate that:
1. Underwrites compliance-audit's evidence export
2. Backs xAPI statement persistence (per ADR-002)
3. Backs AI Act decision audit (per ADR-005)
4. Backs AVG record-of-processing for every learner-data mutation
5. Backs NIS2 security-event logging
6. Is portable / queryable / readable by an auditor without Scholiq running

## Decision

**Every state mutation in Scholiq writes an append-only audit-trail entry into OpenRegister.** No exceptions. The audit trail is the source of truth for "what happened"; entity tables are projections of the audit trail's accepted state.

### Concretely

1. **One generic `audit_event` schema in OpenRegister** with these fields:

   | Field | Type | Notes |
   |---|---|---|
   | `id` | UUID | event id |
   | `tenant_id` | UUID | tenant separation |
   | `event_type` | string | controlled vocabulary (see §2) |
   | `actor_type` | enum | `user` / `system` / `external` / `ai-system` |
   | `actor_id` | string | NC user id / system identifier / external system slug |
   | `actor_ip` | string | for NIS2 evidence |
   | `subject_type` | string | the entity affected (course / enrolment / credential / …) |
   | `subject_id` | UUID | entity id |
   | `verb` | IRI | xAPI-aligned verb when applicable |
   | `before` | json | entity snapshot before mutation (null on create) |
   | `after` | json | entity snapshot after mutation (null on delete) |
   | `reason` | text | optional admin-supplied justification |
   | `lawful_basis` | enum | AVG Art. 6 basis when personal data is touched |
   | `correlation_id` | UUID | groups multi-step business operations |
   | `created_at` | timestamp | server-set, UTC |
   | `signature` | string | optional HMAC for tamper detection (see §5) |

2. **Append-only, enforced at three layers**:
   - OpenRegister schema flag `append_only: true` (rejects UPDATE / DELETE)
   - Database-level: `audit_event` table has REVOKE UPDATE / DELETE for the application user; only INSERT + SELECT
   - Backup retention: pgdump preserves history; restore is a fresh insert, never an UPDATE

3. **Event-type vocabulary** is controlled. Bootstrap-time registry at `lib/Bootstrap/AuditEventTypes.php`. Examples:
   - `enrolment.created`, `enrolment.completed`, `enrolment.withdrawn`
   - `credential.issued`, `credential.revoked`, `credential.expired`, `credential.verified`
   - `attestation.signed`, `attestation.revoked`
   - `course.published`, `course.archived`
   - `learner.profile.created`, `learner.profile.merged`, `learner.profile.deleted` (deletion = audit row, not table row removal)
   - `compliance.audit_pack.exported`, `compliance.regulation.published`
   - `xapi.statement.received` (per ADR-002)
   - `ai.decision.recorded`, `ai.feature.flag.toggled` (per ADR-005)
   - `security.login.failed`, `security.role.changed`, `security.config.changed` (NIS2)

   New event types are added via PR; PHPStan rule fails the build if `AuditTrail::record()` is called with an unknown event_type.

4. **The audit trail is the source of truth for derived state**:
   - "What's the current enrolment status of learner X in course Y?" = SELECT * FROM enrolment WHERE …  → that row is a *projection* materialised from the latest enrolment.* event. The projection table exists for query speed; the audit trail is canonical.
   - Compliance coverage % is computed by scanning `xapi.statement.received` events with verb in {`completed`, `passed`} filtered by activity tag = "mandatory-training-X" — there is no separate "completed table" to drift out of sync.

5. **Tamper detection (optional but recommended for high-risk tenants)**:
   - Per-tenant HMAC key (rotated annually).
   - Every event signed at write time: `signature = HMAC-SHA256(key, canonicalized_event_minus_signature)`.
   - Chain integrity (optional, configurable per tenant): each event signs `previous_signature || event_body`, creating a hash chain. Compromise of any past event invalidates all subsequent signatures.
   - Verification UI in admin settings: "verify last N days" returns first-broken-event timestamp or "all clean".

6. **Export format**:
   - Audit pack export is a ZIP containing:
     - `audit-trail.ndjson` — newline-delimited JSON, one event per line
     - `audit-trail.csv` — flat CSV for spreadsheet consumption
     - `manifest.json` — export metadata (tenant_id, period, filter criteria, event count, signature_status)
     - `signature-verification.txt` — verification report
   - For compliance regulations, the export filter is regulation_slug → relevant event types.
   - Auditor can verify the pack offline given the public verification key.

7. **Retention**:
   - Default: 7 years (AVG general accounting period).
   - Compliance events (`compliance.*`, `attestation.*`, `credential.*`): 10 years.
   - AI Act high-risk decisions (`ai.decision.recorded` for risk_level=high): 10 years (per ADR-005).
   - Security events (`security.*`): 1 year (per NIS2 baseline).
   - Configurable per tenant up to the maximum allowed by AVG retention policy.

### What this enables for v0.1

- The compliance-audit "immutable evidence log" UI is a straight `SELECT * FROM audit_event WHERE event_type LIKE 'attestation.%' OR event_type LIKE 'credential.%' ORDER BY created_at DESC`.
- The "audit pack export per regulation" is server-side filtering + ZIP packaging.
- "See coverage % per regulation in real time" is a windowed count over the same source.
- xAPI LRS endpoint (per ADR-002) writes `xapi.statement.received` events; query the audit trail to derive learner completion state.
- Compliance officer sees a single, consistent "what happened, when, by whom, with what justification" trail across every Scholiq surface.

## Consequences

### Positive
- Compliance evidence is *the* core data, not a side artifact.
- No drift between operational state and audit log — they're the same source.
- Migration / data-portability story is trivial: export the audit trail; replay it elsewhere.
- AI Act + AVG + NIS2 compliance share a single substrate; no per-regulation parallel infrastructure.
- A future Scholiq2-rewrite or migration to another stack is far cheaper than the LVS-switching-costs the incumbents inflict on schools (insight #4) — Scholiq's data is not a hostage.
- Differentiator vs incumbents: Magister/SOMtoday/ParnasSys treat audit logs as DBA-only diagnostics; Scholiq treats them as the buyer-visible product surface.

### Negative / risks
- Storage cost grows linearly with activity. Mitigation: partitioning by year + month; cold-storage tier after retention threshold.
- Write amplification — every business mutation produces both projection-table write + audit-event write. Mitigation: transactional (both in one DB transaction); OpenRegister handles this idempotently.
- Schema rigour: every mutation path needs the audit-trail call. Mitigation: PHPStan rule + base controller hook + transactional middleware that fails the request if no audit event was recorded for state-changing endpoints.
- HMAC key management adds operational complexity. Mitigation: optional per tenant; keys live in NC's `OCP\Security\ICrypto` keyring, rotated annually with a dual-signing transition window.

## Alternatives considered

- **Use only OCP\Activity\IManager** for activity-stream entries. Rejected: too loose a schema; not append-only at storage level; designed for UI activity feeds, not legal evidence.
- **Use only OpenRegister's per-table audit log**. Rejected: per-table audit captures CRUD but not cross-entity business operations (a "credential issued because attestation signed because course completed because lessons watched" chain needs a correlation_id semantic OR has to be reassembled from four tables).
- **Use a separate event-sourcing framework** (Prooph, Broadway, etc.). Rejected: framework lock-in; OpenRegister already provides 80% of what event-sourcing needs (object store + relations + audit).
- **Defer audit-trail discipline to "later"**. Rejected: this is the *substance* of the compliance-audit wedge. Retrofitting append-only semantics into a mutable-state codebase is a 10x more painful migration.

## Implementation notes

- `Scholiq\Service\AuditTrail::record(string $eventType, array $payload): string` is the single entry point. Returns the new event id.
- Base controller `Scholiq\Controllers\AuditedController` extends `OCP\AppFramework\Controller` and hooks the request lifecycle: any 2xx response from a state-changing endpoint that didn't call `AuditTrail::record()` triggers a 500 in dev, a Sentry warning in production.
- Projection-table writes happen inside a single DB transaction with the audit-event insert. If the audit-event insert fails, the projection write rolls back.
- Migration path: when Scholiq v0.1 has been live a while and a v0.2 schema change is needed, projections are *rebuilt* from the audit trail, not migrated in place.
- OpenRegister schema: `scholiq-audit-event.json` with `append_only: true`, indexed on (tenant_id, event_type, created_at), (subject_id, created_at), (actor_id, created_at), (correlation_id).
- `CnAuditTrailTab` component for `@conduction/nextcloud-vue` — a reusable audit-trail panel for the CnObjectSidebar (it's already in the 5 standard tabs per skill guardrail; this ADR specifies its data shape).

## Verification

A code change is audit-trail-compliant if:
- Every state-changing controller endpoint emits at least one audit event.
- The event's `event_type` is registered in `AuditEventTypes`.
- `before` and `after` are populated for updates; `after` only for creates; `before` only for deletes.
- `lawful_basis` is set when `subject_type` references personal data.
- The audit event id is returned (or referenced) in API responses for traceability.
- The new event type, if added in this PR, has a comment explaining its retention class.

Automated checks:
- PHPStan custom rule: `AuditedController::*` endpoints that return 2xx without `AuditTrail::record()` call fail the build.
- Unit test: schema flag `append_only: true` rejects an UPDATE attempt at OpenRegister level.
- Integration test: `audit-pack export` ZIP contains a valid `manifest.json` and ndjson with ≥ N events for a known-good test fixture.

## References

- AVG/GDPR Art. 5 (data-minimisation) + Art. 30 (record-of-processing-activities)
- EU AI Act Art. 12 (record-keeping) + Art. 14 (human oversight)
- NIS2 Directive Art. 21 (incident-response evidence)
- Brief insights: "Maintain immutable evidence log", "Export audit pack per regulation", "Capture signed attestation per learner" (compliance-training, critical)
- Companion ADRs: ADR-002 (xAPI statements are an instance of audit events), ADR-005 (AI decisions are an instance of audit events)
- Pattern reference: event sourcing in OpenRegister — see openregister/docs/ADR-XXX (data model ADR) if/when published
