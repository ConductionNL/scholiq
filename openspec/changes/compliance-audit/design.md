# Design — Compliance Training & Audit (the wedge core)

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — regulation lifecycle, coverage % computation, RAG-status derivation, mandatory-enrolment aggregations, attestation-count aggregations, officer-alert-on-coverage-drop, compliance dashboard widgets — ALL fit `x-openregister-lifecycle` / `-aggregations` / `-calculations` / `-notifications` / `-widgets`. The immutable evidence log IS OR's audit trail; there is no separate evidence-log schema. The attestation signing is the only PHP seam (lifecycle guard). The audit-pack export is a single thin controller (legitimate PHP — ZIP document generation). **In-fleet references**: `decidesk/lib/Settings/decidesk_register.json` ActionItem schema demonstrates `x-openregister-calculations` (`isOverdue`, `daysLate`) and Meeting + Decision schemas demonstrate `x-openregister-notifications`. ADR-008 establishes that `Attestation` and all lifecycle events flow through OR's audit-trail abstraction.
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable, hash-chained, append-only, retention-aware), aggregation engine, lifecycle engine, notification engine, widget definitions, archival/destruction-workflow, RBAC, MCP discovery. **Critical: ADR-022 §"Anti-patterns" explicitly prohibits "Home-grown audit trails. An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects." Any `EvidenceLogService`, `HmacKeyService`, `AuditTrail::record()`, or `scholiq-audit-event` schema is the canonical anti-pattern; it is NOT in this change.**
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — the Compliance dashboard page is declared in `src/manifest.json` with widgets sourced from `x-openregister-widgets` on the Regulation schema. The audit-pack export is a manifest custom-page action wired to `AuditPackExportController`. No `ComplianceDashboard.vue`, no `RegulationListView.vue`, no `CampaignListView.vue`.

---

## 1. Schema patches on `lib/Settings/scholiq_register.json`

### 1.1 `Regulation`

```jsonc
"Regulation": {
  "slug": "regulation",
  "icon": "GavelOutline",
  "version": "0.1.0",
  "title": "Regulation",
  "description": "Compliance regulation for mandatory training (AVG, BIO, NIS2, Integriteit, etc.)",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:DefinedTerm",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["slug", "name", "audienceScope", "tenant_id"],
  "properties": {
    "slug":                  { "type": "string", "pattern": "^[A-Z0-9_-]+$",
                               "description": "Short machine-readable code, e.g. NIS2, AVG, BIO" },
    "name":                  { "type": "string",
                               "description": "Full display name, e.g. NIS2 Cyberbeveiligingswet" },
    "description":           { "type": ["string","null"] },
    "applicabilityCriteria": { "type": ["string","null"],
                               "description": "Free-text description of which employees / roles are in scope" },
    "audienceScope":         { "type": "string",
                               "enum": ["all-employees","board","role-specific","department"] },
    "requiresAnnualRenewal": { "type": "boolean", "default": true },
    "renewalCycleMonths":    { "type": "integer", "default": 12 },
    "active":                { "type": "boolean", "default": true },
    "ragRedThreshold":       { "type": "number",  "default": 70,
                               "description": "Coverage % below which ragStatus = red" },
    "ragAmberThreshold":     { "type": "number",  "default": 90,
                               "description": "Coverage % below which ragStatus = amber (above red)" },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published" },
      "archive": { "from": "published", "to": "archived"  }
    }
  },
  "x-openregister-aggregations": {
    "mandatoryEnrolledCount": {
      "metric": "count",
      "schema": "Enrolment",
      "filter": {
        "mandatory": true,
        "regulationSlug": "@self.slug",
        "lifecycleIn": ["active","completed","failed"]
      }
    },
    "mandatoryCompletedCount": {
      "metric": "count",
      "schema": "Enrolment",
      "filter": {
        "mandatory": true,
        "regulationSlug": "@self.slug",
        "lifecycle": "completed"
      }
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
          { "mul": [
              { "div": [ { "prop": "mandatoryCompletedCount" }, { "prop": "mandatoryEnrolledCount" } ] },
              100
            ]
          },
          0
        ]
      }
    },
    "ragStatus": {
      "type": "string",
      "materialise": true,
      "expression": {
        "case": [
          { "when": { "lt":  [ { "prop": "coveragePercent" }, { "prop": "ragRedThreshold"   } ] }, "then": "red"   },
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
| `ComplianceController::regulations*` CRUD | `CnAppRoot` index/detail page bound to `register=scholiq schema=Regulation` |
| `CoverageComputationService::computeCoverage` | `x-openregister-aggregations` + `x-openregister-calculations` |
| APCu caching + `ComplianceCoverageWarmJob` | OR's aggregation engine handles cache invalidation on source-schema audit events |
| Cache invalidation listener on `xapi.statement.received` | OR's engine recomputes calculations on dependent schema events automatically |
| RAG-status threshold computation | `x-openregister-calculations.ragStatus` using per-regulation threshold fields |
| Officer-alert dispatch | `x-openregister-notifications.officerAlertOnCoverageDrop` with `calculatedChange` trigger |
| Dashboard widget rendering | `x-openregister-widgets.coverageGrid` + `boardProof` consumed by `CnDashboardPage` |

---

### 1.2 `Attestation`

```jsonc
"Attestation": {
  "slug": "attestation",
  "icon": "FileCertificateOutline",
  "version": "0.1.0",
  "title": "Attestatie",
  "description": "Signed learner attestation that mandatory training was completed and understood (Schema.org EducationalOccupationalCredential evidence)",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:EducationalOccupationalCredential",
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["learnerId", "lessonId", "courseId", "regulationSlug", "tenant_id"],
  "properties": {
    "learnerId":       { "type": "string",
                         "description": "NC user UUID — opaque internal identifier, never BSN" },
    "lessonId":        { "type": "string", "format": "uuid" },
    "courseId":        { "type": "string", "format": "uuid" },
    "regulationSlug":  { "type": "string",
                         "description": "Matches Regulation.slug, e.g. NIS2, AVG, BIO" },
    "actorIp":         { "type": ["string","null"],
                         "description": "Client IP captured at attestation time for evidence" },
    "employeeId":      { "type": ["string","null"],
                         "description": "HR employee ID for audit evidence; never BSN" },
    "score":           { "type": ["number","null"],
                         "description": "Knowledge-check score 0–100 if applicable" },
    "xapiStatementId": { "type": ["string","null"], "format": "uuid",
                         "description": "Links to the XapiStatement that triggered the attestation" },
    "signature":       { "type": "string",
                         "description": "HMAC-SHA256 of canonical payload, set by AttestationSigningGuard" },
    "keyRotationId":   { "type": "string",
                         "description": "OR tenant-key rotation period identifier; set by AttestationSigningGuard" },
    "tenant_id":       { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "drafted",
    "transitions": {
      "sign":   { "from": "drafted", "to": "signed",  "requires": "OCA\\Scholiq\\Lifecycle\\AttestationSigningGuard" },
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

The `appendOnly: true` field consumes OR's append-only abstraction (ADR-022 §"Audit trail (immutable)"). The `AttestationSigningGuard` is the only PHP — it verifies the xAPI completion exists for `(learnerId, lessonId)` AND computes the HMAC signature using OR's audit-trail tenant-key API; Scholiq does **not** own a `HmacKeyService` or a `key_rotation_id` table.

**The immutable evidence-log surface IS OR's audit-trail tab** on the Regulation detail page, filtered to `event_type IN (attestation.signed, attestation.revoked, credential.issued, credential.revoked, enrolment.completed, xapi.statement.received)`. No `EvidenceLog` schema, no `EvidenceLogService`, no `scholiq-audit-event` schema. OR is the substrate; ADR-008 is the contract.

---

### 1.3 No `ComplianceCampaign` schema

The context-brief mentions `ComplianceCampaign` as a named entity, and so does the original data-model note in `docs/ARCHITECTURE.md`. That wrapper schema is NOT added in this change.

**Why**: a campaign IS a set of Enrolment objects sharing the same `regulationSlug` + `bulkJobId`, produced by the `BulkEnrolModal` declared in the enrolment change. Adding a `ComplianceCampaign` schema now would create a schema whose only content is a pointer to those enrolments — redundant state with the enrolment objects themselves. Per ADR-031 §Exceptions ("No fit exists — write the service [or schema] and reference the gap"), the right path is to defer: if a future feature needs per-campaign metadata (e.g., campaign-level cancel-all, campaign notes) that cannot be expressed on Enrolment, add the schema in a dedicated follow-up change with a concrete feature motivating it.

**In the meantime**, the Compliance dashboard's "Campaign" view in the manifest is a saved query: `GET /api/openregister/scholiq/Enrolment?regulationSlug=<slug>&source=bulk` grouped by `bulkJobId`.

---

### 1.4 No `EvidenceLog` schema

The context-brief mentions `EvidenceLog` as a named entity. That schema is NOT added in this change.

**Why**: per ADR-008 §3, "Scholiq's audit trail IS OpenRegister's audit trail. No parallel `scholiq-audit-event` schema." An `EvidenceLog` schema would be the textbook parallel-audit-substrate anti-pattern forbidden by ADR-022 §"Anti-patterns — Home-grown audit trails." The immutable evidence log surface is consumed from OR's audit-trail API. No new schema needed.

---

### 1.5 Seed data

Seed objects are loaded via `lib/Settings/scholiq_register.json` components.objects[] with the `@self` envelope per ADR-001. All Dutch-realistic values; fictional but distinguishable from real data.

```jsonc
// -- Regulation seed objects --

{
  "@self": { "register": "scholiq", "schema": "Regulation", "slug": "reg-avg-2026" },
  "slug": "AVG",
  "name": "Algemene Verordening Gegevensbescherming (AVG)",
  "description": "Jaarlijkse privacybewustzijningstraining verplicht voor alle medewerkers op grond van de AVG.",
  "applicabilityCriteria": "Alle medewerkers met toegang tot persoonsgegevens, inclusief zzp'ers met langlopend contract.",
  "audienceScope": "all-employees",
  "requiresAnnualRenewal": true,
  "renewalCycleMonths": 12,
  "active": true,
  "ragRedThreshold": 70,
  "ragAmberThreshold": 90,
  "lifecycle": "published",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},
{
  "@self": { "register": "scholiq", "schema": "Regulation", "slug": "reg-nis2-2026" },
  "slug": "NIS2",
  "name": "NIS2 Cyberbeveiligingswet",
  "description": "Verplichte cyberawarenesstraining voor bestuurders conform de Cyberbeveiligingswet (implementatie NIS2-richtlijn).",
  "applicabilityCriteria": "Leden van het bestuur / directie conform art. 26 Cyberbeveiligingswet.",
  "audienceScope": "board",
  "requiresAnnualRenewal": true,
  "renewalCycleMonths": 12,
  "active": true,
  "ragRedThreshold": 80,
  "ragAmberThreshold": 95,
  "lifecycle": "published",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},
{
  "@self": { "register": "scholiq", "schema": "Regulation", "slug": "reg-bio-2026" },
  "slug": "BIO",
  "name": "Baseline Informatiebeveiliging Overheid (BIO 2.0)",
  "description": "Informatiebeveiligingsbewustzijn conform BIO 2.0 voor medewerkers van overheidsorganisaties.",
  "applicabilityCriteria": "Alle medewerkers van de overheidsorganisatie die toegang hebben tot vertrouwelijke informatiesystemen.",
  "audienceScope": "all-employees",
  "requiresAnnualRenewal": true,
  "renewalCycleMonths": 12,
  "active": true,
  "ragRedThreshold": 75,
  "ragAmberThreshold": 90,
  "lifecycle": "published",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},
{
  "@self": { "register": "scholiq", "schema": "Regulation", "slug": "reg-integriteit-2026" },
  "slug": "INTEGRITEIT",
  "name": "Integriteitsbeleid Rijksoverheid",
  "description": "Verplichte integriteitsmodule voor rijksambtenaren conform de Gedragscode Integriteit Rijk.",
  "applicabilityCriteria": "Alle rijksambtenaren en inhuurkrachten met een aanstelling langer dan 3 maanden.",
  "audienceScope": "all-employees",
  "requiresAnnualRenewal": true,
  "renewalCycleMonths": 24,
  "active": true,
  "ragRedThreshold": 70,
  "ragAmberThreshold": 85,
  "lifecycle": "draft",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},

// -- Attestation seed objects --

{
  "@self": { "register": "scholiq", "schema": "Attestation", "slug": "attest-avg-001" },
  "learnerId": "nc-user-jan-de-vries",
  "lessonId": "00000000-1111-0000-0000-000000000001",
  "courseId": "00000000-2222-0000-0000-000000000001",
  "regulationSlug": "AVG",
  "actorIp": "10.10.20.45",
  "employeeId": "EMP-2024-00142",
  "score": 88,
  "xapiStatementId": "00000000-3333-0000-0000-000000000001",
  "signature": "a3f8c2e1d94b7056e8c1220f3a4b5c6d7e8f9012345abcdef0123456789abcd",
  "keyRotationId": "kr-2026-q1",
  "lifecycle": "signed",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},
{
  "@self": { "register": "scholiq", "schema": "Attestation", "slug": "attest-nis2-001" },
  "learnerId": "nc-user-petra-van-dijk",
  "lessonId": "00000000-1111-0000-0000-000000000002",
  "courseId": "00000000-2222-0000-0000-000000000002",
  "regulationSlug": "NIS2",
  "actorIp": "192.168.1.101",
  "employeeId": "DIR-2022-00008",
  "score": 95,
  "xapiStatementId": "00000000-3333-0000-0000-000000000002",
  "signature": "b7d3e9f2a01c45678901234567890abcdef01234567890abcdef012345678901",
  "keyRotationId": "kr-2026-q1",
  "lifecycle": "signed",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
},
{
  "@self": { "register": "scholiq", "schema": "Attestation", "slug": "attest-bio-001" },
  "learnerId": "nc-user-ahmed-el-ouali",
  "lessonId": "00000000-1111-0000-0000-000000000003",
  "courseId": "00000000-2222-0000-0000-000000000003",
  "regulationSlug": "BIO",
  "actorIp": "172.16.5.33",
  "employeeId": "EMP-2023-00291",
  "score": 76,
  "xapiStatementId": "00000000-3333-0000-0000-000000000003",
  "signature": "c9e5a1b0234567890123456789abcdef01234567890abcdef01234567890abc",
  "keyRotationId": "kr-2026-q1",
  "lifecycle": "signed",
  "tenant_id": "00000000-0000-0000-0000-000000000001"
}
```

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/AttestationSigningGuard.php` | Lifecycle guard + cryptographic operation | Verifies xAPI completion for `(learnerId, lessonId)` AND computes HMAC-SHA256 signature via OR's tenant-key API. Single `check($transitionContext): Result` method. Called by OR's lifecycle engine when `Attestation.lifecycle: drafted → signed` fires. Legitimate per ADR-031 §"Lifecycle guards" and §"Cryptographic operations that OR cannot perform in schema metadata". |
| `lib/Controller/AuditPackExportController.php` | Document generation | `POST /api/compliance/audit/export`: queries OR's audit-trail API filtered by `event_type IN (attestation.*, credential.*, enrolment.*, compliance.*, xapi.statement.received)` + regulationSlug + period; calls OR's verification endpoint for signature status; builds 4-file ZIP (`audit-trail.ndjson`, `audit-trail.csv`, `manifest.json`, `signature-verification.txt`); streams as `Content-Disposition: attachment`. Legitimate per ADR-031 §"Document/PDF/document-template generation". |

**Explicitly NOT in this change** (ADR-031 + ADR-022 anti-patterns):

| Excluded | Why excluded |
|---|---|
| `AttestationService::capture` | Replaced by `Attestation.lifecycle: drafted → signed` with signing guard |
| `CoverageComputationService` | Replaced by `Regulation.x-openregister-aggregations` + `-calculations` |
| `EvidenceLogService` | OR's audit trail IS the evidence log (ADR-008) |
| `HmacKeyService` | OR's audit-trail tenant-key API replaces it (ADR-022) |
| `ComplianceHmacRotationJob` | OR's audit-trail abstraction owns key rotation (ADR-022) |
| `AttestationController` | Attestation index/detail via `CnAppRoot` + schema-declared signing transition |
| `ComplianceController` (multi-endpoint) | Every endpoint reduces to `CnAppRoot` index/detail of Regulation/Attestation or the single audit-pack export action |
| `AiFeatureRegistry` / any AI-scoring logic | Phase 1 has zero AI features (ADR-005) |

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension (`src/manifest.json`)

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    {
      "id": "RegulationDetail",
      "route": "/compliance/regulations/:slug",
      "type": "detail",
      "config": {
        "register": "scholiq",
        "schema": "Regulation",
        "tabs": ["details", "auditTrail"]
      }
    },
    {
      "id": "AuditPackExport",
      "route": "/compliance/export",
      "type": "custom",
      "config": { "component": "AuditPackExportModal" }
    },
    {
      "id": "Compliance",
      "route": "/compliance",
      "type": "dashboard",
      "title": "scholiq.page.compliance.title",
      "config": {
        "widgets": [
          {
            "id": "regulation-coverage",
            "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Regulation", "widget": "coverageGrid" }
          },
          {
            "id": "board-proof",
            "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Regulation", "widget": "boardProof" }
          }
        ]
      }
    }
  ]
}
```

The Compliance dashboard's widgets are `widget-ref` entries pointing at `Regulation.x-openregister-widgets`. This is the canonical declarative-widget pattern: the schema declares the definition once; every consumer (Compliance dashboard, MyDash integration, Regulation detail page) reads the same definition.

### 3.2 `AuditPackExportModal.vue`

Single custom Vue component registered via `customComponents` on `CnAppRoot`. UI:
- Regulation dropdown (sources from `GET /api/openregister/scholiq/Regulation?lifecycle=published`)
- Date-from + date-to pickers
- "Exporteer auditpakket" button

On submit: POSTs `/api/compliance/audit/export` with `{regulationSlug, dateFrom, dateTo}`. Server streams the ZIP back as `Content-Disposition: attachment`. Uses OR REST directly; no Scholiq backend pass-through.

### 3.3 Attestation view (inline in `LessonPlayer`)

After a `cmi5.completed` event from the AU AND the lesson has `mandatoryTraining=true` AND `regulationSlug` is set, the Vue `LessonPlayer` (declared in course-management change) renders an inline attestation card:

- Checkbox: "Ik verklaar dat ik de training heb voltooid en begrepen, en dat ik de inhoud zal toepassen conform het beleid van mijn organisatie."
- Button: "Onderteken attestatie"

On submit:
1. Browser POSTs `POST /api/openregister/scholiq/Attestation` with `{learnerId, lessonId, courseId, regulationSlug, actorIp (browser), score, xapiStatementId, lifecycle: 'drafted'}`.
2. Browser POSTs `PATCH /api/openregister/scholiq/Attestation/:id/transition/sign`.
3. `AttestationSigningGuard` fires server-side; if the xAPI completion is missing, transitions are rejected with HTTP 422 rendered inline.
4. On success: OR emits `attestation.signed` audit entry; the component shows the attestation id and a link to the issued Credential (if the certification change fired).

No `/api/attestations` Scholiq controller. The Vue component talks directly to OR's REST API.

### 3.4 No app-local store, no app-local Vue Router code

Per ADR-031 + ADR-024: no `useComplianceStore`, no `ComplianceDashboard.vue`, no `RegulationListView.vue`, no `RegulationDetailView.vue`, no `CampaignListView.vue`. `CnAppRoot`'s built-in dashboard / index / detail renderers consume the schema-declared widgets and pages.

---

## 4. Attestation flow (end-to-end, declarative)

```
Learner watches cmi5 AU
  → AU posts cmi5.completed to /api/lrs/statements (LrsController, course-management change)
    → OR saves XapiStatement (appendOnly schema, audits as xapi.statement.received)
    → XapiCompletionHandler (enrolment change) dispatches Enrolment.complete transition
    → OR emits enrolment.completed audit → Regulation aggregation cache invalidated

Learner sees attestation card in LessonPlayer
  → Checkbox + "Onderteken attestatie" button
  → POST /api/openregister/scholiq/Attestation (lifecycle=drafted)
  → PATCH .../transition/sign
    → AttestationSigningGuard (server-side, called by OR lifecycle engine):
       ① Query OR: XapiStatement exists for (learnerId, lessonId) with verb=completed/passed? → reject with 422 if not
       ② Call OR audit-trail tenant-key API: get current key + keyRotationId
       ③ HMAC-SHA256(key, canonical(Attestation payload minus signature))
       ④ Set transition payload.signature + payload.keyRotationId
    → OR saves transition, emits attestation.signed audit entry
    → Regulation.attestationCount aggregation increments
    → Regulation.coveragePercent recalculates; if drops to red → officerAlertOnCoverageDrop fires

Compliance officer opens /compliance
  → CnDashboardPage renders coverageGrid widget from Regulation.x-openregister-widgets
  → OR computes coveragePercent + ragStatus via calculation engine
  → Widget renders regulation rows with red/amber/green band and action buttons

Auditor requests export
  → Opens AuditPackExportModal → selects AVG + date range → clicks Export
  → POST /api/compliance/audit/export
  → AuditPackExportController:
       Query OR audit-trail API: event_type IN (attestation.*, credential.*, enrolment.*,
         compliance.*, xapi.statement.received), regulationSlug=AVG, period=[from,to]
       Call OR verification endpoint for signature status
       Build ZIP: audit-trail.ndjson + audit-trail.csv + manifest.json + signature-verification.txt
  → ZIP download (Content-Disposition: attachment)
  → OR audit-trail emits compliance.audit_pack.exported entry
```

---

## 5. Audit events emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| Attestation `drafted → signed` | `attestation.signed` | `Attestation.x-openregister-lifecycle` |
| Attestation `signed → revoked` | `attestation.revoked` | `Attestation.x-openregister-lifecycle` |
| Regulation `draft → published` | `compliance.regulation.published` | `Regulation.x-openregister-lifecycle` |
| Regulation `published → archived` | `compliance.regulation.archived` | `Regulation.x-openregister-lifecycle` |
| Coverage drops to red | `notification.dispatched` (+ `scholiq.compliance.coverage.dropped` subject) | `Regulation.x-openregister-notifications` |
| Audit pack downloaded | `compliance.audit_pack.exported` | OR audit on the `AuditPackExportController`'s OR-query call |

No `AuditEventTypes::KNOWN` constant class. No `Scholiq\Service\AuditTrail::record()`.

---

## 6. Integration points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schemas + lifecycle + aggregations + calculations + notifications + widgets + audit trail + audit-trail tenant-key API + archival | Every wedge operation |
| OpenRegister archival | OR archival-destruction-workflow abstraction | `Attestation` + `Credential`: 10-year retention; security events: 1 year (per ADR-008 §7) |
| `Enrolment` schema (enrolment change) | `mandatoryEnrolledCount` + `mandatoryCompletedCount` aggregation sources | Coverage % denominator/numerator |
| `XapiStatement` schema (course-management change) | `AttestationSigningGuard` pre-condition check + `xapi.statement.received` audit events | Attestation signing pre-condition |
| `Credential` schema (certification change) | `validCredentialCount` aggregation source | Board-proof panel |
| `@conduction/nextcloud-vue` | `CnAppRoot` + `CnDashboardPage` + `customComponents` | Frontend shell + widgets + modal registration |

---

## 7. Reuse analysis (per ADR-001 §"Deduplication check")

| OR/shared capability | Consumed | Confirmed via |
|---|---|---|
| Append-only object storage (`appendOnly: true`) | Yes — `Attestation` schema | ADR-022 §"Audit trail (immutable)" |
| Audit trail hash chain + verification | Yes — `AuditPackExportController` calls OR verification endpoint | ADR-008 §5 |
| Aggregation engine | Yes — `mandatoryEnrolledCount`, `mandatoryCompletedCount`, `attestationCount`, `validCredentialCount` | ADR-031 `x-openregister-aggregations` |
| Calculation engine | Yes — `coveragePercent`, `ragStatus` | ADR-031 `x-openregister-calculations` |
| Notification engine | Yes — `officerAlertOnCoverageDrop` | ADR-031 `x-openregister-notifications` |
| Widget engine | Yes — `coverageGrid`, `boardProof` | ADR-031 `x-openregister-widgets` |
| Tenant-key API for HMAC | Yes — `AttestationSigningGuard` reads OR's tenant key | ADR-008 §5, ADR-022 |
| `CnDashboardPage` + `CnObjectSidebar` | Yes — manifest-declared dashboard + audit-trail tab | ADR-001 §"DO NOT REBUILD" |

No overlap with existing OR services that would require a deduplication exception. `AuditPackExportController` generates a ZIP document — the only OR service that approaches this is `ExportService` (CSV/JSON), but it does not produce regulation-filtered audit-trail ZIPs. The controller is a legitimate ADR-031 §"Document generation" exception.

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Regulation state machine (`draft → published → archived`) | declarative | lifecycle |
| Coverage % per regulation | declarative | calculation (on top of aggregations) |
| RAG status (red/amber/green) per regulation | declarative | calculation |
| Mandatory enrolled count | declarative | aggregation |
| Mandatory completed count | declarative | aggregation |
| Attestation count | declarative | aggregation |
| Valid credential count (board proof) | declarative | aggregation |
| Officer alert on coverage drop | declarative | notification |
| Compliance dashboard widgets | declarative | widgets |
| Attestation state machine (`drafted → signed → revoked`) | declarative | lifecycle |
| Attestation immutability (append-only) | declarative (OR `appendOnly`) | consumed via ADR-022 |
| Audit trail / evidence log | declarative (OR) | consumed via ADR-022, ADR-008 |
| HMAC key management + rotation | declarative (OR tenant-key API) | consumed via ADR-022 |
| Attestation signing pre-condition + HMAC compute | imperative (PHP) | "Lifecycle guards" + cryptographic exception |
| Audit-pack ZIP export | imperative (PHP) | "Document generation" exception |
| Coverage cache | none (OR aggregation engine) | n/a |
| AI risk classification of coverage data | NOT in scope (Phase 1 — ADR-005) | n/a |

---

## 9. Wedge scope exclusions

| Excluded | Deferred to |
|---|---|
| `ComplianceCampaign` schema with per-campaign metadata | V1 — only land when a feature genuinely needs metadata beyond Enrolment fields |
| `EvidenceLog` schema | Never — OR's audit trail is the evidence log (ADR-008) |
| Global multi-jurisdiction compliance packs | Enterprise |
| Automated GDPR DPIA worksheet generation | V1 |
| Whistleblower / integrity incident reporting | decidesk (separate app) |
| SaaS multi-tenant compliance benchmarking | Enterprise / V2 |
| AI-driven coverage risk classification | Enterprise + ADR-005 gate |
| Regulation content authoring | External (RADIO / Kennisnet / vendor library) |
| Whistleblowing pipeline | decidesk |
