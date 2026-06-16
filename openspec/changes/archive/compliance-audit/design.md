# Design — Compliance Audit (the wedge core)

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — coverage % per audience, attestation count, board-proof aggregations, officer-alert-on-coverage-drop, NIS2-due alerts, evidence-log queries — ALL fit `x-openregister-aggregations` / `x-openregister-calculations` / `x-openregister-notifications`. The "immutable evidence log" IS OR's audit trail; there is no separate evidence-log substrate. The audit-pack export is a single thin controller (legitimate PHP — document/ZIP generation).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable, hash-chained, retention-aware), aggregations engine, notifications, RBAC, archival, MCP discovery. **Critical: ADR-022 §"Anti-patterns" explicitly prohibits "Home-grown audit trails. An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects." The v1 `AttestationService` + `Scholiq\Service\AuditTrail::record()` + `HmacKeyService` + parallel `scholiq-audit-event` schema were the textbook example of this anti-pattern.**
>
> **Frontend (per [hydra ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md))** — `Compliance` dashboard page is declared in `src/manifest.json` with widgets sourced from `x-openregister-widgets` on the Regulation schema. The audit-pack export is a manifest action wired to a single PHP controller.

## 1. Schema patches on `lib/Settings/scholiq_register.json`

### 1.1 `Regulation`

```jsonc
"Regulation": {
  "slug": "regulation",
  "icon": "GavelOutline",
  "version": "0.1.0",
  "title": "Regulation",
  "description": "Compliance regulation (AVG, BIO, NIS2, etc.)",
  "type": "object",
  "x-openregister": {
    "active": true,
    "searchable": true
  },
  "required": ["slug", "name", "audienceScope", "tenant_id"],
  "properties": {
    "slug":                  { "type": "string", "pattern": "^[A-Z0-9_-]+$" },
    "name":                  { "type": "string" },
    "description":           { "type": ["string","null"] },
    "applicabilityCriteria": { "type": ["string","null"] },
    "audienceScope":         { "type": "string", "enum": ["all-employees","board","role-specific","department"] },
    "requiresAnnualRenewal": { "type": "boolean", "default": true },
    "renewalCycleMonths":    { "type": "integer", "default": 12 },
    "active":                { "type": "boolean", "default": true },
    "ragRedThreshold":       { "type": "number",  "default": 70  },
    "ragAmberThreshold":     { "type": "number",  "default": 90  },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish":  { "from": "draft",     "to": "published" },
      "archive":  { "from": "published", "to": "archived" }
    }
  },
  "x-openregister-aggregations": {
    "mandatoryEnrolledCount": {
      "metric": "count",
      "schema": "Enrolment",
      "filter": { "mandatory": true, "regulationSlug": "@self.slug", "lifecycleIn": ["active","completed","failed"] }
    },
    "mandatoryCompletedCount": {
      "metric": "count",
      "schema": "Enrolment",
      "filter": { "mandatory": true, "regulationSlug": "@self.slug", "lifecycle": "completed" }
    },
    "attestationCount": {
      "metric": "count",
      "schema": "Attestation",
      "filter": { "regulationSlug": "@self.slug", "lifecycle": "signed" }
    },
    "validCredentialCount": {
      "metric": "count_distinct",
      "schema": "Credential",
      "field": "learnerId",
      "filter": { "regulationSlug": "@self.slug", "lifecycle": "issued" }
    }
  },
  "x-openregister-calculations": {
    "coveragePercent": {
      "type": "number",
      "materialise": true,
      "expression": {
        "if": [
          { "gt": [ { "prop": "mandatoryEnrolledCount" }, 0 ] },
          { "mul": [ { "div": [ { "prop": "mandatoryCompletedCount" }, { "prop": "mandatoryEnrolledCount" } ] }, 100 ] },
          0
        ]
      }
    },
    "ragStatus": {
      "type": "string",
      "materialise": true,
      "expression": {
        "case": [
          { "when": { "lt":  [ { "prop": "coveragePercent" }, { "prop": "ragRedThreshold" }   ] }, "then": "red" },
          { "when": { "lt":  [ { "prop": "coveragePercent" }, { "prop": "ragAmberThreshold" } ] }, "then": "amber" },
          { "default": "green" }
        ]
      }
    }
  },
  "x-openregister-notifications": {
    "officerAlertOnCoverageDrop": {
      "trigger":   { "calculatedChange": "ragStatus", "to": "red" },
      "channel":   "nc-notification",
      "subject":   "scholiq.compliance.coverage.dropped",
      "recipientFromTenantRole": "compliance-officer"
    }
  },
  "x-openregister-widgets": {
    "coverageGrid": {
      "type": "regulation-coverage-grid",
      "title": "scholiq.widget.regulation.coverage",
      "props": {
        "metrics": ["coveragePercent","mandatoryEnrolledCount","mandatoryCompletedCount","attestationCount"],
        "rag":     ["ragStatus"],
        "actions": [
          { "id": "campaign",   "manifestPage": "BulkEnrol",       "presetField": "regulationSlug" },
          { "id": "exportPack", "manifestPage": "AuditPackExport", "presetField": "regulationSlug" }
        ]
      }
    },
    "boardProof": {
      "type": "stats-block",
      "title": "scholiq.widget.regulation.board",
      "filter": { "audienceScope": "board" },
      "props":  { "primary": "coveragePercent", "secondary": "validCredentialCount" }
    }
  }
}
```

**What each block replaces:**

| v1 element | Replaced by |
|---|---|
| `ComplianceController::regulations*` CRUD | `CnAppRoot` index/detail page bound to Regulation |
| `CoverageComputationService::computeCoverage` | `x-openregister-aggregations` + `x-openregister-calculations` |
| Manual APCu caching | OR's aggregation engine handles cache invalidation; tied to the source schemas' audit events |
| Cache invalidation listener on `xapi.statement.received` | OR's engine recomputes calculations on dependent source-schema events |
| RAG-status threshold computation | `x-openregister-calculations.ragStatus` with per-regulation `ragRedThreshold` / `ragAmberThreshold` fields |
| Officer-alert dispatch | `x-openregister-notifications.officerAlertOnCoverageDrop` with `calculatedChange` trigger |
| Dashboard widget definition | `x-openregister-widgets.coverageGrid` consumed by `CnDashboardPage` |
| Board-proof report | `x-openregister-widgets.boardProof` with audienceScope filter |

### 1.2 `Attestation`

```jsonc
"Attestation": {
  "slug": "attestation",
  "icon": "FileCertificateOutline",
  "version": "0.1.0",
  "title": "Attestation",
  "description": "Signed learner attestation that mandatory training was completed and understood",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["learnerId", "lessonId", "courseId", "regulationSlug", "tenant_id"],
  "properties": {
    "learnerId":         { "type": "string" },
    "lessonId":          { "type": "string", "format": "uuid" },
    "courseId":          { "type": "string", "format": "uuid" },
    "regulationSlug":    { "type": "string" },
    "actorIp":           { "type": ["string","null"] },
    "employeeId":        { "type": ["string","null"] },
    "score":             { "type": ["number","null"] },
    "xapiStatementId":   { "type": ["string","null"], "format": "uuid" },
    "signature":         { "type": "string" },
    "keyRotationId":     { "type": "string" },
    "tenant_id":         { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "drafted",
    "transitions": {
      "sign":   { "from": "drafted", "to": "signed", "requires": "OCA\\Scholiq\\Lifecycle\\AttestationSigningGuard" },
      "revoke": { "from": "signed",  "to": "revoked" }
    }
  },
  "x-openregister-relations": {
    "learner": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "learnerId" },
    "course":  { "register": "scholiq", "schema": "Course",         "cardinality": "many-to-one", "joinOn": "courseId" },
    "lesson":  { "register": "scholiq", "schema": "Lesson",         "cardinality": "many-to-one", "joinOn": "lessonId" }
  }
}
```

The `appendOnly: true` consumes OR's append-only abstraction (ADR-022). The `AttestationSigningGuard` is the only behaviour-PHP — it verifies the xAPI completion exists for `(learnerId, lessonId)` AND computes the HMAC signature using the **OR audit-trail-managed tenant key** (per ADR-022, hash-chain keys live in OR, not in a Scholiq `HmacKeyService`).

**The "immutable evidence log" UI surface is OR's audit-trail tab on the Regulation detail page** — filtered to `event_type IN (attestation.signed, attestation.revoked, credential.issued, credential.revoked, credential.expired, enrolment.completed)`. No `scholiq-audit-event` schema, no `Scholiq\Service\AuditTrail`, no `AuditedController`. OR is the evidence-log substrate.

### 1.3 No `ComplianceCampaign` schema

The v1 design had a `scholiq-compliance-campaign` schema with its own status enum and bulk_job_id field. That's an unnecessary wrapper: a campaign IS a set of Enrolment objects with the same `regulationSlug` + `bulkJobId`. The `BulkEnrolModal` (declared in the enrolment change) already produces those — Compliance's "Campaign" view in the manifest is a saved query, not a separate schema.

If a future feature needs per-campaign metadata that can't be expressed on Enrolment (e.g. campaign-level cancel-all action), revisit and add the schema then.

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/AttestationSigningGuard.php` | Lifecycle guard | Verifies xAPI completion exists for `(learnerId, lessonId)` AND computes HMAC signature via OR's tenant-key API. Single-method. Called by OR's lifecycle engine when `Attestation.lifecycle: drafted → signed` fires. |
| `lib/Controller/AuditPackExportController.php` | Document generation | Streams a ZIP containing `audit-trail.ndjson` + `audit-trail.csv` + `manifest.json` + `signature-verification.txt`. The ndjson/csv content is OR's audit-trail-query response, filtered by event_type + regulation + period; the signature-verification report is OR's verification-endpoint response. Single legitimate PHP per ADR-031 §"Document/PDF/document-template generation". |

**Explicitly NOT in this change** (ADR-031 + ADR-022 anti-patterns):
- `AttestationService::capture` — replaced by `Attestation.lifecycle: drafted → signed` with the signing guard. The Vue component creates the drafted object then dispatches the `sign` transition through OR's REST API.
- `CoverageComputationService` — replaced by `Regulation.x-openregister-aggregations` + `-calculations`.
- `AuditPackExportService` (the original 2-pass query + ZIP code) — collapsed into the thin controller; OR's audit-trail-query handles the heavy work.
- `HmacKeyService` — OR's audit-trail tenant-key API replaces it per ADR-022.
- `ComplianceHmacRotationJob` (TimedJob) — OR's audit-trail abstraction owns key rotation.
- `EvidenceLogService` — OR's audit trail IS the evidence log.
- `ComplianceController` (the 11-endpoint controller) — every endpoint reduces to either `CnAppRoot` index/detail of Regulation/Attestation, or the single audit-pack export action.
- `AttestationController` — replaced by Attestation index/detail page + the schema-declared signing-transition action.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    { "id": "RegulationDetail", "route": "/compliance/regulations/:slug", "type": "detail",
      "config": { "register": "scholiq", "schema": "Regulation", "tabs": ["details", "auditTrail"] } },
    { "id": "AuditPackExport", "route": "/compliance/export",             "type": "custom",
      "config": { "component": "AuditPackExportModal" } },
    { "id": "Compliance", "route": "/compliance", "type": "dashboard", "title": "scholiq.page.compliance.title",
      "config": {
        "widgets": [
          { "id": "regulation-coverage", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Regulation", "widget": "coverageGrid" } },
          { "id": "board-proof", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Regulation", "widget": "boardProof" } }
        ]
      } }
  ]
}
```

The Compliance dashboard's widgets are `widget-ref` entries pointing at `Regulation.x-openregister-widgets`. **This is the canonical declarative-widget pattern**: the schema declares the widget definition once; every consumer (this dashboard, LaunchPad, the regulation detail page) reads the same definition.

### 3.2 `AuditPackExportModal.vue`

Modal: regulation dropdown (sources from `GET /api/openregister/scholiq/Regulation?lifecycle=published`), date-from + date-to pickers, "Export" button that POSTs `/api/compliance/audit/export` with `{regulationSlug, dateFrom, dateTo}`. Server streams the ZIP back as `Content-Disposition: attachment`.

### 3.3 `AttestationView` (embedded in `LessonPlayer`)

After a `cmi5.completed` event arrives from the AU, the Vue `LessonPlayer` (declared in course-management change) renders an inline attestation card with:
- Checkbox "Ik verklaar dat ik de training heb voltooid en begrepen"
- "Onderteken attestatie" button

On submit:
1. Browser POSTs `POST /api/openregister/scholiq/Attestation` with `{learnerId, lessonId, courseId, regulationSlug, xapiStatementId}` (lifecycle defaults to `drafted`).
2. Browser POSTs `PATCH /api/openregister/scholiq/Attestation/:id/transition/sign`.
3. `AttestationSigningGuard` fires; if xAPI completion is missing OR signing fails, the transition is rejected with a 422 surface.
4. On success: OR emits `attestation.signed` audit; the schema-declared notification (none in v0.1; future feature can add one) fires.

No `/api/attestations` Scholiq controller. The Vue view talks to OR directly; the guard runs server-side inside OR's lifecycle engine.

### 3.4 No app-local store, no app-local Vue Router code

Per ADR-031 + ADR-024: no `useComplianceStore`, no `ComplianceDashboard.vue`, no `RegulationListView.vue`, no `RegulationDetailView.vue`, no `CampaignListView.vue`. `CnAppRoot`'s built-in dashboard / index / detail renderers consume the schema-declared widgets and pages.

---

## 4. Attestation Flow (end-to-end, declarative)

```
Learner watches cmi5 AU
  → AU posts cmi5.completed to /api/lrs/statements (LrsController)
    → OR saves XapiStatement (appendOnly schema, audits as xapi.statement.received)
    → XapiCompletionHandler (enrolment change) dispatches Enrolment.complete
    → OR emits enrolment.completed audit
    → CredentialIssuanceHandler (certification change) writes Credential

Learner sees AttestationView inside LessonPlayer
  → Checkbox + button
  → POST /api/openregister/scholiq/Attestation (lifecycle=drafted)
  → PATCH .../transition/sign
    → AttestationSigningGuard runs server-side:
       - Verify xAPI completed statement exists for (learnerId, lessonId)
       - Compute HMAC signature via OR's tenant-key API
       - Set the schema's signature + keyRotationId fields on the transition payload
    → OR saves transition, emits attestation.signed audit
    → Attestation now visible in the Regulation detail page's audit-trail tab

Compliance officer → opens Compliance dashboard (manifest page)
  → CnDashboardPage renders the regulation-coverage widget from Regulation.x-openregister-widgets
  → OR computes coveragePercent + ragStatus on-the-fly via the calculation engine
  → Widget renders red/amber/green per regulation

Auditor requests export → opens AuditPackExportModal
  → POST /api/compliance/audit/export
  → AuditPackExportController calls OR's audit-trail-query API filtered by
    event_type IN (attestation.*, credential.*, enrolment.*, compliance.*,
                   xapi.statement.received), regulationSlug, period
  → Renders ndjson + csv + manifest.json + signature-verification.txt
    (signature verification report sourced from OR's verification endpoint)
  → ZIP download
```

No `AttestationService`, no `CoverageComputationService`, no `EvidenceLogService`, no `AuditTrail`. The wedge core IS the schema metadata + OR's engine + two short PHP files.

---

## 5. Audit Events Emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| Attestation transition `drafted → signed` | `attestation.signed` | `Attestation.x-openregister-lifecycle` |
| Attestation transition `signed → revoked` | `attestation.revoked` | `Attestation.x-openregister-lifecycle` |
| Regulation transition `draft → published` | `compliance.regulation.published` | `Regulation.x-openregister-lifecycle` |
| Audit-pack export submitted | `compliance.audit_pack.exported` | OR audit on `AuditPackExportController`'s OR-query call |
| Coverage % transitions to red | `compliance.coverage.dropped` | `Regulation.x-openregister-notifications.officerAlertOnCoverageDrop` (notification engine writes audit entry on dispatch) |

---

## 6. Caching — none in app

The v1 design had APCu coverage % caching + a `ComplianceCoverageWarmJob` TimedJob. Both are removed: OR's aggregation engine handles cache invalidation tied to source-schema audit events. If performance turns out to be inadequate (REQ-CA-003-A: ≤ 2s dashboard), that's an OR-side optimisation — open an issue against `openregister` per ADR-031 §Exceptions; do not add an app-local cache layer.

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schemas + lifecycle + aggregations + calculations + notifications + widgets + audit trail + audit-trail tenant-key API | Every wedge operation |
| OpenRegister archival | OR's archival-destruction-workflow abstraction | Retention class per schema (Attestation, Credential, Enrolment → 10y; security events → 1y) |
| Enrolment change | `Enrolment` schema | Aggregation source for `mandatoryEnrolledCount` / `mandatoryCompletedCount` |
| Course-management change | `XapiStatement` schema, `Lesson` schema | Pre-condition for attestation signing |
| Certification change | `Credential` schema | Aggregation source for `validCredentialCount` |
| @conduction/nextcloud-vue | `CnAppRoot` + `CnDashboardPage` + `customComponents` | Frontend shell + widgets + audit-pack export modal |

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Regulation state machine (draft → published → archived) | declarative | lifecycle |
| Coverage % per regulation | declarative | calculation (on top of aggregations) |
| RAG status per regulation | declarative | calculation |
| Mandatory enrolled count | declarative | aggregation |
| Mandatory completed count | declarative | aggregation |
| Attestation count | declarative | aggregation |
| Valid-credential count (board proof) | declarative | aggregation |
| Officer alert on coverage drop | declarative | notification |
| Compliance dashboard widgets | declarative | widgets |
| Attestation state machine (drafted → signed → revoked) | declarative | lifecycle |
| Attestation immutability | declarative (OR appendOnly) | (consumed via ADR-022) |
| Audit trail / evidence log | declarative (OR) | (consumed via ADR-022 — first row) |
| HMAC key management + rotation | declarative (OR tenant-key API) | (consumed via ADR-022) |
| Attestation signing precondition + HMAC compute | imperative (PHP) | "Lifecycle guards" + "Cryptographic" exception |
| Audit-pack ZIP export | imperative (PHP) | "Document generation" exception |
| Coverage cache | none (OR's aggregation engine) | n/a |

---

## 9. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| Global multi-jurisdiction compliance packs | Enterprise |
| Automated GDPR DPIA worksheet generation | V1 |
| Whistleblower / integrity incident reporting | decidesk (separate app) |
| SaaS multi-tenant benchmarking | Enterprise/V2 |
| AI-driven coverage risk classification | Enterprise + ADR-005 gate |
| Regulation content authoring | External (RADIO / Kennisnet / vendor library) |
| Per-campaign metadata schema | V1 — only land it when a feature genuinely needs it |
