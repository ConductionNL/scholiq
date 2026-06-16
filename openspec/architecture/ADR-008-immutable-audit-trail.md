---
adr_id: ADR-008
title: Audit trail consumed from OpenRegister; behaviour declared via x-openregister-lifecycle / -notifications
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
references:
  - hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md
  - hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md
---

# ADR-008 — Audit trail consumed from OpenRegister

## Status
**accepted** (2026-05-11) — foundational. Scholiq's audit-trail surface (compliance "immutable evidence log", credential history, enrolment history, AI Act decision log, NIS2 security event log) **is** OpenRegister's audit-trail abstraction. Scholiq does not maintain a parallel substrate. Every state-changing behaviour is declared via `x-openregister-lifecycle` / `x-openregister-notifications` in `lib/Settings/scholiq_register.json`, which makes OR emit the audit entries automatically. ADR-002 (xAPI statements) and ADR-005 (AI decisions) are specialisations of this same audit trail.

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

### Why this ADR was rewritten (2026-05-11)

The first version of this ADR specified a Scholiq-owned audit-trail substrate: a `scholiq-audit-event` schema, a `Scholiq\Service\AuditTrail::record()` service entry point, a `Scholiq\Controllers\AuditedController` base class with a `afterController` lifecycle hook + a PHPStan custom rule (`MissingAuditTrailRule`). That entire pattern violates two company-wide ADRs:

- **ADR-022** (apps consume OR abstractions) — audit-trail-immutable is the **first row** of the OR-abstractions table. Building a parallel substrate is the canonical anti-pattern ADR-022 was written to prevent. ("Home-grown audit trails. An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects.")
- **ADR-031** (schema-declarative business logic over service classes) — the `AuditedController` + `AuditTrail::record()` pair is glue code for behaviour that fits `x-openregister-lifecycle` and `x-openregister-notifications` exactly. New `lib/Service/AuditTrail.php` is the textbook example of "custom service class for behaviour the schema engine can express declaratively".

The rewritten pattern below produces the **same compliance evidence** (immutable, hash-chained, append-only, retention-aware, exportable per regulation) with **zero app-local audit substrate** and **zero `AuditedController` enforcement plumbing**.

## Decision

**Scholiq's audit trail IS OpenRegister's audit trail.** No parallel `scholiq-audit-event` schema. No `AuditTrail::record()` service method. No `AuditedController` base class. No `MissingAuditTrailRule` PHPStan rule.

### Concretely

1. **OR owns the substrate.** OpenRegister already ships an append-only, hash-chained, retention-aware, RBAC-respecting audit trail per ADR-022. Scholiq consumes it. The audit-event schema, the append-only guarantee, the hash chain, the export format — all live in OR.

2. **Scholiq declares WHAT to audit via schema metadata.** Every state-changing behaviour is declared on the relevant Scholiq schema in `lib/Settings/scholiq_register.json`:

   - **State transitions** — `x-openregister-lifecycle` on the schema. OR's lifecycle engine emits an audit entry on every transition automatically, capturing `before`, `after`, actor, IP, lawful basis, and correlation id. Example: `Enrolment.lifecycle: pending → active` emits `enrolment.activated`.
   - **Notification dispatch** — `x-openregister-notifications` on the schema. OR's notification engine writes a notification audit entry on every dispatch.
   - **Aggregation computation** — `x-openregister-aggregations` on the schema. Reads against aggregations are recorded by OR's query layer (configurable per tenant).
   - **Calculated field reads** — `x-openregister-calculations` on the schema. Reads against materialised calculations are recorded by OR's query layer.

   This list updates as OR adds extensions; the OR team owns the schema-extension contract (ADR-031).

3. **Event-type vocabulary lives in OR.** OR's audit trail uses a stable controlled vocabulary. Scholiq schemas pick the right `event_type` value when they declare lifecycle transitions:

   - `enrolment.activated`, `enrolment.completed`, `enrolment.withdrawn` (declared as transition outcomes on Enrolment.lifecycle)
   - `credential.issued`, `credential.revoked`, `credential.expired`, `credential.verified` (declared on Credential.lifecycle)
   - `attestation.signed`, `attestation.revoked` (declared on Attestation.lifecycle)
   - `course.published`, `course.archived` (declared on Course.lifecycle)
   - `learner.profile.created`, `learner.profile.merged`, `learner.profile.deleted` (declared on LearnerProfile.lifecycle — note: deletion is an audit row, not a table-row removal; OR's archival/destruction-workflow abstraction handles the soft-delete + retention)
   - `compliance.audit_pack.exported`, `compliance.regulation.published` (emitted by the AuditPackExportController + by the Regulation.lifecycle publish transition)
   - `xapi.statement.received` (declared on the XapiStatement schema — see ADR-002)
   - `ai.decision.recorded`, `ai.feature.flag.toggled` (declared on the relevant target object + the AiFeature.lifecycle — see ADR-005)
   - `security.login.failed`, `security.role.changed`, `security.config.changed` (emitted by NC's auth subsystem + OR's RBAC + tenant-config change events; Scholiq merely surfaces them — NIS2)

   New event types arrive by adding a transition / notification to a schema in `scholiq_register.json` — never by adding a value to a Scholiq-side enum.

4. **Audit trail is the source of truth for derived state.** Aggregations (coverage %) read OR's audit trail directly via `x-openregister-aggregations` (e.g. count distinct learners with verb=`completed` for lessons in regulation X). The aggregation IS the projection; the audit trail IS the source. There is no separate "completion table" to drift out of sync. This is the same architectural property the v1 ADR aimed for; the difference is that OR's engine performs the projection, not Scholiq.

5. **Tamper detection comes from OR.** The hash-chain + per-tenant signing key + verification UI all live in OR's audit-trail abstraction. Scholiq exposes a "verify audit trail" admin action that delegates to OR's verification endpoint. No app-local `HmacKeyService`, no app-local `key_rotation_id` field on a Scholiq schema, no app-local `ComplianceHmacRotationJob`.

6. **Export format is OR's audit-trail query export.** The compliance audit-pack export is a thin PHP controller (`AuditPackExportController`, legitimate per ADR-031 — document generation) that:
   - Queries OR's audit-trail API filtered by `event_type IN (attestation.*, credential.*, enrolment.*, compliance.*, xapi.statement.received)` for the requested period + tenant.
   - Queries `Regulation` + `Attestation` objects in scope.
   - Pipes the result into a ZIP containing `audit-trail.ndjson`, `audit-trail.csv`, `manifest.json`, `signature-verification.txt`.
   - The signature verification report is rendered from OR's response — Scholiq does no signing or verification itself.

7. **Retention is OR-managed.** Retention class is declared per schema; OR enforces it.
   - Default: 7 years (AVG general accounting period).
   - Compliance schemas (`Attestation`, `Credential`): 10 years (declared via OR's archival/destruction-workflow abstraction).
   - AI decisions on high-risk features: 10 years (per ADR-005).
   - Security events: 1 year (per NIS2 baseline) — set on the schemas that emit them.

### What this enables for v0.1

- The compliance-audit "immutable evidence log" UI is a query over OR's audit-trail API filtered by event_type — a thin `CnObjectSidebar` audit-trail tab consuming the existing OR endpoint.
- The "audit pack export per regulation" is a single PHP controller method that calls OR's query API + pipes through ZipArchive (≈ 50 LOC).
- "See coverage % per regulation in real time" is `x-openregister-aggregations` declared on the `Regulation` schema — fed automatically by `xapi.statement.received` audit entries. No `CoverageComputationService`.
- xAPI LRS endpoint (per ADR-002) writes to the `XapiStatement` schema; OR's `x-openregister-lifecycle` on that schema produces the `xapi.statement.received` audit entry automatically.
- Compliance officer sees a single, consistent "what happened, when, by whom, with what justification" trail across every Scholiq surface — because every Scholiq surface writes through OR.

## Consequences

### Positive
- Compliance evidence is *the* core data, not a side artifact — same as v1 of this ADR aimed for, now with zero app-local plumbing.
- No drift between operational state and audit log — they're the same source.
- Migration / data-portability story is trivial: export OR's audit trail; replay it elsewhere. Scholiq adds zero proprietary substrate.
- AI Act + AVG + NIS2 compliance share a single substrate (OR's audit trail); no per-regulation parallel infrastructure.
- Hash-chain integrity, retention-aware purge, RBAC on audit reads, MCP discovery, GraphQL exposure — all inherited from OR by virtue of consuming the abstraction. Each improvement to OR's audit trail lands in Scholiq with no per-app work.
- Cross-app uniformity: a "submitted → adopted" event in scholiq (enrolment.completed) looks identical to one in decidesk (motion.adopted) — same audit format, same RBAC hooks, same MCP exposure.

### Negative / risks
- Scholiq depends on OR's audit-trail abstraction being feature-complete for the wedge. Mitigated by the abstraction being already-stable (operating policy per ADR-022 — `decidesk/lib/Settings/decidesk_register.json` is the working reference).
- App authors must learn OR's audit-trail contract instead of writing local code. Mitigated by ADR-031's reference example: every Scholiq schema follows the same declarative shape decidesk uses.
- A future Scholiq need not currently expressible by OR's audit-trail abstraction will require an OR change. Mitigated by exception path in ADR-031 §"Exceptions" — open an issue against `openregister`, document in `design.md`, use a service in the interim with a sunset date.

## Alternatives considered

- **Build a Scholiq-local audit trail (the v1 ADR-008 pattern).** Rejected per ADR-022 + ADR-031 — duplication of OR's audit-trail abstraction. The `scholiq-audit-event` schema, the `AuditTrail::record()` service, the `AuditedController` base class are the textbook anti-patterns.
- **Use only `OCP\Activity\IManager` for activity-stream entries.** Rejected: too loose a schema; not append-only at storage level; designed for UI activity feeds, not legal evidence.
- **Use only OpenRegister's per-table audit log.** Rejected as a primary substrate, but accepted *as the OR abstraction Scholiq consumes here* — per-table audit captures CRUD; cross-entity business operations are captured via the `correlation_id` field OR's audit trail provides.
- **Use a separate event-sourcing framework** (Prooph, Broadway, etc.). Rejected: framework lock-in; OpenRegister already provides 80% of what event-sourcing needs (object store + relations + audit + lifecycle + replay).
- **Defer audit-trail discipline to "later".** Rejected: this is the *substance* of the compliance-audit wedge.

## Implementation notes

- Every Scholiq schema in `lib/Settings/scholiq_register.json` that mutates declares `x-openregister-lifecycle` for state changes; the lifecycle engine writes the audit entry.
- Notification dispatch (T-30 reminders, completion alerts, compliance-officer alerts) is `x-openregister-notifications` on the relevant schema; the notification engine writes the audit entry.
- The only PHP that touches the audit trail is `AuditPackExportController` (legitimate per ADR-031 — document generation). It reads OR's audit-trail query API; it does not write.
- The `CnObjectSidebar` audit-trail tab is the existing reusable component from `@conduction/nextcloud-vue`; it consumes OR's audit-trail API directly. Scholiq does not register a custom tab.
- Migration path: when Scholiq v0.1 has been live a while and a v0.2 schema change is needed, projections are *rebuilt* from OR's audit trail, not migrated in place.

## Verification

A Scholiq code change is audit-trail-compliant if:
- Every state-changing behaviour is declared as `x-openregister-lifecycle` / `x-openregister-notifications` / `x-openregister-aggregations` on a schema in `lib/Settings/scholiq_register.json`.
- No new file matches `lib/Service/*AuditTrail*.php`.
- No new class extends an `AuditedController` base.
- No new schema is named `scholiq-audit-event` or `*_audit_event` (a parallel audit substrate).
- The audit-pack export controller's only PHP responsibility is querying OR's audit-trail API and packaging the response.

Automated checks (Hydra reviewer / mechanical gates):
- Reviewer flags any new `lib/Service/*` class whose name matches `*AuditTrail*` or `*AuditedController*` — those are ADR-031 / ADR-022 anti-patterns on net-new code.
- Reviewer flags any new schema entry whose `slug` matches `*-audit-event` (parallel substrate).

## References

- AVG/GDPR Art. 5 (data-minimisation) + Art. 30 (record-of-processing-activities)
- EU AI Act Art. 12 (record-keeping) + Art. 14 (human oversight)
- NIS2 Directive Art. 21 (incident-response evidence)
- Brief insights: "Maintain immutable evidence log", "Export audit pack per regulation", "Capture signed attestation per learner" (compliance-training, critical)
- **Hydra ADR-022** — apps consume OR abstractions (audit trail is the first row of the abstractions table).
- **Hydra ADR-031** — schema-declarative business logic over service classes.
- Companion ADRs: ADR-002 (xAPI statements are an instance of OR audit-trail entries), ADR-005 (AI decisions are an instance of OR audit-trail entries).
- Working reference: `decidesk/lib/Settings/decidesk_register.json` — Meeting / Motion / Amendment lifecycles, Meeting + Decision notifications, ActionItem aggregations + calculations.
