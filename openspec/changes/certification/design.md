# Design — Certification & Digital Credentials

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — Certificate lifecycle (issued / revoked / expired), expiry detection (T-90 / T-60 / T-30), tiered reminder dispatch, delta-module trigger on ContentVersion bump, and auto-enrolment on RenewalRule match all fit `x-openregister-lifecycle` / `-calculations` / `-notifications`. The OB3 + EDCI payload assembly and RS256 signing are `CertificateSigningService` (legitimate PHP per ADR-031 — cryptographic + document-generation). The issuance handler is a thin lifecycle listener (legitimate PHP — lifecycle handler exception). The public verify endpoint is a thin controller (legitimate PHP — external-system contract).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable, hash-chained, retention-aware per ADR-008), lifecycle engine, calculation engine, notifications engine, relations, RBAC, archival. No app-local `ExpiryDetectionService`, no `CredentialExpiryJob` TimedJob, no `NotificationService` wrapper.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — index + detail pages for CertificateTemplate and Certificate are manifest-declared `CnAppRoot` pages. The verify view is a single `customComponents` Vue file with `public: true`. No `useCredentialStore`, no `CertificateListView.vue`.

---

## Reuse Analysis

| Capability needed | OR abstraction / shared component | Reuse decision |
|---|---|---|
| Certificate CRUD (list, detail, create, edit) | `CnAppRoot` index + detail pages + `ObjectService` | Consumed — no custom controller or list view |
| CertificateTemplate CRUD | `CnAppRoot` index + detail pages | Consumed |
| Expiry state (`daysUntilExpiry`, `isExpired`) | `x-openregister-calculations` | Consumed — no `ExpiryDetectionService` |
| Tiered reminders | `x-openregister-notifications` with `idempotencyKey` | Consumed — no `CredentialExpiryJob` TimedJob |
| Credential audit trail | OR's audit-trail abstraction (ADR-008 / ADR-022) | Consumed — no parallel audit substrate |
| Retention (10 years for credentials, per ADR-008) | OR's archival-destruction-workflow abstraction | Consumed |
| Relations Certificate ↔ LearnerProfile / Course | `x-openregister-relations` | Consumed — no link tables |
| Dashboard widgets (credential count, expiry risk) | `x-openregister-widgets` | Consumed — no custom widget components |
| Key storage for signing | `OCP\Security\ICrypto` (Nextcloud core) | Used directly — no app-local key table |

No overlap found with existing OpenRegister services (`ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`) beyond the intended consumption pattern above.

---

## 1. Schema patches on `lib/Settings/scholiq_register.json`

### 1.1 `CertificateTemplate`

```jsonc
"CertificateTemplate": {
  "slug": "certificate-template",
  "icon": "CertificateOutline",
  "version": "0.1.0",
  "title": "Certificaatsjabloon",
  "description": "Template voor certificaat- en credential-uitgifte per cursus of opleiding",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["name", "kind", "issuerName", "issuerDid", "tenant_id"],
  "properties": {
    "name":                 { "type": "string", "description": "Naam van het sjabloon" },
    "kind":                 { "type": "string", "enum": ["diploma","certificate","badge","microcredential"] },
    "issuerName":           { "type": "string", "description": "Naam van de uitgevende instelling" },
    "issuerDid":            { "type": "string", "description": "DID URI van de uitgever (bijv. did:web:example.nl)" },
    "courseId":             { "type": ["string","null"], "format": "uuid" },
    "validityPeriodMonths": { "type": ["integer","null"], "description": "Geldigheidsduur in maanden (null = onbeperkt)" },
    "backgroundImagePath":  { "type": ["string","null"], "description": "Pad naar achtergrondafbeelding in nc:files" },
    "badgeImagePath":       { "type": ["string","null"], "description": "Pad naar badge-afbeelding (OB3 image)" },
    "renewalCourseSlug":    { "type": ["string","null"], "description": "Slug van de verlengingscursus bij verlopen" },
    "deltaCourseSlug":      { "type": ["string","null"], "description": "Slug van de deltamodule bij inhoudswijziging" },
    "edciEnabled":          { "type": "boolean", "default": false, "description": "EDCI/Europass ELM payload meegeven bij uitgifte" },
    "bolognaSupplement":    { "type": "boolean", "default": false, "description": "Bologna Diploma Supplement genereren bij award (kind=diploma)" },
    "tenant_id":            { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "activate":  { "from": "draft",   "to": "active" },
      "deactivate":{ "from": "active",  "to": "draft" },
      "archive":   { "from": "active",  "to": "archived" }
    }
  },
  "x-openregister-relations": {
    "course": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "courseId" }
  }
}
```

### 1.2 `Certificate`

```jsonc
"Certificate": {
  "slug": "certificate",
  "icon": "SchoolOutline",
  "version": "0.1.0",
  "title": "Certificaat",
  "description": "Verifieerbare credential (W3C VC + Open Badges 3.0 + EDCI ELM)",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["learnerId", "templateId", "kind", "issuedAt", "issuerDid", "openbadges3Payload", "tenant_id"],
  "properties": {
    "learnerId":             { "type": "string", "description": "Opaque UUID van de lerende (nooit BSN)" },
    "templateId":            { "type": "string", "format": "uuid" },
    "courseId":              { "type": ["string","null"], "format": "uuid" },
    "kind":                  { "type": "string", "enum": ["diploma","certificate","badge","microcredential"] },
    "issuedAt":              { "type": "string", "format": "date-time" },
    "expiresAt":             { "type": ["string","null"], "format": "date-time" },
    "issuerDid":             { "type": "string" },
    "openbadges3Payload":    { "type": "object", "description": "Gesigneerde Open Badges 3.0 JSON-LD assertion" },
    "edciPayload":           { "type": ["object","null"], "description": "EDCI ELM JSON payload (null bij badge/microcredential zonder EDCI)" },
    "revocationReason":      { "type": ["string","null"] },
    "source":                { "type": "string", "enum": ["auto","manual","migrated"], "default": "auto" },
    "verificationUrl":       { "type": "string", "format": "uri" },
    "regulationSlug":        { "type": ["string","null"], "description": "Koppeling aan regulering voor compliance-audit" },
    "renewalCertificateId":  { "type": ["string","null"], "format": "uuid", "description": "ID van het verlengende certificaat" },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "issued",
    "transitions": {
      "revoke": { "from": "issued",  "to": "revoked" },
      "expire": { "from": "issued",  "to": "expired" }
    }
  },
  "x-openregister-relations": {
    "learner":   { "register": "scholiq", "schema": "LearnerProfile",    "cardinality": "many-to-one", "joinOn": "learnerId" },
    "course":    { "register": "scholiq", "schema": "Course",            "cardinality": "many-to-one", "joinOn": "courseId" },
    "template":  { "register": "scholiq", "schema": "CertificateTemplate","cardinality": "many-to-one", "joinOn": "templateId" }
  },
  "x-openregister-calculations": {
    "daysUntilExpiry": {
      "type": "integer",
      "materialise": true,
      "expression": {
        "if": [
          { "eq": [ { "prop": "expiresAt" }, null ] },
          null,
          { "dateDiff": [ { "prop": "expiresAt" }, "@now", "days" ] }
        ]
      }
    },
    "isExpiringIn90Days": {
      "type": "boolean", "materialise": true,
      "expression": { "and": [
        { "eq":  [ { "prop": "lifecycle" }, "issued" ] },
        { "neq": [ { "prop": "expiresAt" }, null ] },
        { "lte": [ { "prop": "daysUntilExpiry" }, 90 ] },
        { "gt":  [ { "prop": "daysUntilExpiry" }, 60 ] }
      ]}
    },
    "isExpiringIn60Days": {
      "type": "boolean", "materialise": true,
      "expression": { "and": [
        { "eq":  [ { "prop": "lifecycle" }, "issued" ] },
        { "neq": [ { "prop": "expiresAt" }, null ] },
        { "lte": [ { "prop": "daysUntilExpiry" }, 60 ] },
        { "gt":  [ { "prop": "daysUntilExpiry" }, 30 ] }
      ]}
    },
    "isExpiringIn30Days": {
      "type": "boolean", "materialise": true,
      "expression": { "and": [
        { "eq":  [ { "prop": "lifecycle" }, "issued" ] },
        { "neq": [ { "prop": "expiresAt" }, null ] },
        { "lte": [ { "prop": "daysUntilExpiry" }, 30 ] },
        { "gt":  [ { "prop": "daysUntilExpiry" }, 0 ] }
      ]}
    },
    "isExpired": {
      "type": "boolean", "materialise": true,
      "expression": { "and": [
        { "eq":  [ { "prop": "lifecycle" }, "issued" ] },
        { "neq": [ { "prop": "expiresAt" }, null ] },
        { "lte": [ { "prop": "daysUntilExpiry" }, 0 ] }
      ]}
    }
  },
  "x-openregister-notifications": {
    "issuedToLearner": {
      "trigger":   { "lifecycleEnter": "issued" },
      "channel":   "nc-notification",
      "subject":   "scholiq.certificate.issued",
      "recipient": "@self.learnerId"
    },
    "expiryT90": {
      "trigger":        { "calculated": "isExpiringIn90Days", "eq": true },
      "channel":        "nc-notification",
      "subject":        "scholiq.certificate.expiring.t90",
      "recipient":      "@self.learnerId",
      "idempotencyKey": "expiryT90"
    },
    "expiryT60": {
      "trigger":        { "calculated": "isExpiringIn60Days", "eq": true },
      "channel":        "nc-notification",
      "subject":        "scholiq.certificate.expiring.t60",
      "recipient":      "@self.learnerId",
      "idempotencyKey": "expiryT60"
    },
    "expiryT30": {
      "trigger":        { "calculated": "isExpiringIn30Days", "eq": true },
      "channel":        "nc-notification",
      "subject":        "scholiq.certificate.expiring.t30",
      "recipient":      "@self.learnerId",
      "idempotencyKey": "expiryT30",
      "alsoDispatchLifecycle": null
    },
    "expired": {
      "trigger":               { "calculated": "isExpired", "eq": true },
      "channel":               "nc-notification",
      "subject":               "scholiq.certificate.expired",
      "recipient":             "@self.learnerId",
      "idempotencyKey":        "expired",
      "alsoDispatchLifecycle": "expire"
    }
  },
  "x-openregister-widgets": {
    "expiryRiskGrid": {
      "type": "stats-block",
      "title": "scholiq.widget.certificate.expiry",
      "props": {
        "metrics": ["daysUntilExpiry","isExpiringIn30Days","isExpired"],
        "actions": [
          { "id": "viewVerify", "manifestPage": "CertificateVerify" }
        ]
      }
    }
  }
}
```

### 1.3 `CredentialIssuance`

Append-only issuance event record. One row per issuance event, providing full audit lineage (per ADR-008). Distinct from `Certificate` (which holds the current credential state) — `CredentialIssuance` is the immutable fact that an issuance happened.

```jsonc
"CredentialIssuance": {
  "slug": "credential-issuance",
  "icon": "FileDocumentCheckOutline",
  "version": "0.1.0",
  "title": "Uitgifterecord",
  "description": "Append-only record van een certificaat-uitgiftegebeurtenis per lerende",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["certificateId", "learnerId", "templateId", "issuedVia", "tenant_id"],
  "properties": {
    "certificateId":      { "type": "string", "format": "uuid" },
    "learnerId":          { "type": "string" },
    "courseId":           { "type": ["string","null"], "format": "uuid" },
    "templateId":         { "type": "string", "format": "uuid" },
    "issuedVia":          { "type": "string", "enum": ["auto","manual","diploma-award","migration"] },
    "ob3SignatureRef":     { "type": "string",  "description": "JWS compact signature reference" },
    "edciSignatureRef":    { "type": ["string","null"], "description": "EDCI ELM signature reference (null if EDCI not enabled)" },
    "enrolmentId":        { "type": ["string","null"], "format": "uuid" },
    "issuedByUserId":     { "type": ["string","null"], "description": "Nextcloud user ID van handmatige uitgever" },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-relations": {
    "certificate": { "register": "scholiq", "schema": "Certificate",        "cardinality": "many-to-one", "joinOn": "certificateId" },
    "template":    { "register": "scholiq", "schema": "CertificateTemplate","cardinality": "many-to-one", "joinOn": "templateId" }
  }
}
```

### 1.4 `RenewalRule`

Declares when and how renewal or delta-module enrolment is triggered for certificates based on a given template.

```jsonc
"RenewalRule": {
  "slug": "renewal-rule",
  "icon": "RotateRightVariant",
  "version": "0.1.0",
  "title": "Verlengingsregel",
  "description": "Configuratie voor automatische verlenging of delta-module inschrijving",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["templateId", "triggerType", "tenant_id"],
  "properties": {
    "templateId":             { "type": "string", "format": "uuid" },
    "triggerType":            { "type": "string", "enum": ["expiry","content-version"], "description": "Wat triggert de verlenging" },
    "expiryThresholdDays":    { "type": ["integer","null"], "default": 30, "description": "Dagen vóór verlopen waarop auto-inschrijving start" },
    "renewalCourseSlug":      { "type": ["string","null"], "description": "Slug van de verlengingscursus" },
    "deltaCourseSlug":        { "type": ["string","null"], "description": "Slug van de deltamodule bij inhoudswijziging" },
    "autoEnrol":              { "type": "boolean", "default": true },
    "notifyLearner":          { "type": "boolean", "default": true },
    "notifyManager":          { "type": "boolean", "default": false },
    "notifyComplianceOfficer":{ "type": "boolean", "default": false },
    "tenant_id":              { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "active",
    "transitions": {
      "deactivate": { "from": "active",    "to": "inactive" },
      "activate":   { "from": "inactive",  "to": "active" }
    }
  },
  "x-openregister-relations": {
    "template": { "register": "scholiq", "schema": "CertificateTemplate", "cardinality": "many-to-one", "joinOn": "templateId" }
  }
}
```

### 1.5 `ContentVersion`

Tracks course content version bumps. When a new version is published, all certificate holders matching the affected `kind` values in `affectsCredentialKinds` are auto-enrolled in the delta module declared on the associated `RenewalRule`.

```jsonc
"ContentVersion": {
  "slug": "content-version",
  "icon": "SourceBranchPlus",
  "version": "0.1.0",
  "title": "Inhoudsversie",
  "description": "Versieregistratie van cursusinhoud die delta-module inschrijvingen triggert",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["courseId", "version", "changedAt", "tenant_id"],
  "properties": {
    "courseId":               { "type": "string", "format": "uuid" },
    "version":                { "type": "string", "description": "Versie-aanduiding (bijv. '2026-Q2', '3.1')" },
    "changedAt":              { "type": "string", "format": "date-time" },
    "deltaScope":             { "type": ["string","null"], "description": "Vrije beschrijving van wat is veranderd (voor de lerende)" },
    "affectsCredentialKinds": {
      "type": "array",
      "items": { "type": "string", "enum": ["diploma","certificate","badge","microcredential"] },
      "default": ["certificate","diploma"],
      "description": "Welke certificaatsoorten worden geraakt door deze inhoudswijziging"
    },
    "tenant_id":              { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published" },
      "archive": { "from": "published", "to": "archived" }
    }
  },
  "x-openregister-notifications": {
    "deltaEnrolOnPublish": {
      "trigger":   { "lifecycleEnter": "published" },
      "channel":   "nc-notification",
      "subject":   "scholiq.content-version.published",
      "recipientFromTenantRole": "compliance-officer",
      "idempotencyKey": "deltaEnrolOnPublish",
      "sideEffect": "OCA\\Scholiq\\Lifecycle\\DeltaEnrolmentHandler"
    }
  },
  "x-openregister-relations": {
    "course": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "courseId" }
  }
}
```

**What each block replaces:**

| v1 / anti-pattern element | Replaced by |
|---|---|
| `CredentialIssuanceService::issueForEnrolment` orchestration | `CertificateIssuanceHandler` PHP listener (thin; legitimate per ADR-031) + `CertificateSigningService` for signing |
| `ExpiryDetectionService` | `x-openregister-calculations` (`daysUntilExpiry`, `isExpiring*`, `isExpired`) |
| `CredentialExpiryJob` TimedJob | `x-openregister-notifications` triggered by calculated fields (ADR-031 §"Background jobs" case 1) |
| `reminder_*_sent` boolean fields | Removed — OR's `idempotencyKey` tracks dispatch |
| `autoEnrolForRenewal` service | Schema-declared notification on `Certificate.lifecycle-enter-expired` → renewal Enrolment via OR batch endpoint, gated on `RenewalRule.renewalCourseSlug` |
| Per-app `EnrolmentCompletedCredentialListener` | `CertificateIssuanceHandler` (single-method OR audit-event listener) |
| `CertificateController` CRUD | `CnAppRoot` index/detail page bound to `register=scholiq schema=Certificate` |

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Service/KeyManagementService.php` | Cryptographic operation | `generateTenantKeypair(tenantId)` generating RSA-2048 via `openssl_pkey_new`; private key encrypted via `ICrypto::encrypt`, stored under app-config key `scholiq.certificate.signing.<tenantId>`; public key + fingerprint stored plain for verification. Legitimate — cryptographic. |
| `lib/Service/CertificateSigningService.php` | Cryptographic + document generation | `buildOb3Payload(...)` returns OB3 JSON-LD array per §4; `buildEdciPayload(...)` returns EDCI ELM JSON; `signPayload(payload, tenantId)` returns RS256 compact JWS via `openssl_sign` on canonicalised payload. Legitimate — cryptographic + document-gen. |
| `lib/Lifecycle/CertificateIssuanceHandler.php` | Lifecycle handler | Registered as OR audit-event listener for `openregister.audit.enrolment.completed`. Reads Enrolment's Course via OR relations; if `course.certificateTemplate` is set, calls `CertificateSigningService::buildOb3Payload` + `signPayload` + (if `edciEnabled`) `buildEdciPayload`, then `ObjectService::saveObject('Certificate', ...)` + `ObjectService::saveObject('CredentialIssuance', ...)`. Single-method. |
| `lib/Lifecycle/DeltaEnrolmentHandler.php` | Lifecycle handler | Side-effect of `ContentVersion.lifecycle-enter-published` notification. Queries `Certificate` objects for `courseId` + `lifecyle=issued` matching `affectsCredentialKinds`; for each, reads `RenewalRule.deltaCourseSlug` and calls OR's batch-enrol endpoint. Legitimate — external-state orchestration not expressible as pure schema metadata. |
| `lib/Controller/CertificateVerifyController.php` | External-system contract | Public `GET /api/certificates/{id}/verify`; `@NoCSRFRequired` + `@PublicPage`; reads Certificate via OR; returns `{valid, issuedAt, expiresAt, issuerName, qrData}` — no personal data. External auditors + employers use this. |
| `lib/Controller/KeyAdminController.php` | Wraps KeyManagementService | Admin-only `POST /api/certificates/admin/generate-key`. |

**Explicitly NOT in this change (ADR-031 + ADR-022 anti-patterns):**
- `CertificateController` (CRUD) — `CnAppRoot` index/detail covers it.
- `ExpiryDetectionService` — replaced by `x-openregister-calculations`.
- `CredentialExpiryJob` TimedJob — replaced by `x-openregister-notifications` (ADR-031 case 1).
- `CertificateNotificationService` — replaced by `x-openregister-notifications`.
- `AutoRenewalService` — the expiry notification's `alsoDispatchLifecycle: expire` + `RenewalRule` schema handle this.

---

## 3. Auto-issuance flow (Enrolment → Certificate)

```
Learner completes cmi5 AU
  → AU posts cmi5.completed to /api/lrs/statements (LrsController, course-management)
    → OR saves XapiStatement; emits xapi.statement.received
    → XapiCompletionHandler (enrolment change) advances Enrolment.lifecycle → completed
    → OR emits enrolment.completed audit entry

CertificateIssuanceHandler fires (OR audit-event listener):
  1. Reads Enrolment → Course via OR relations
  2. Reads Course.certificateTemplate → CertificateTemplate object
  3. If template active:
       - CertificateSigningService.buildOb3Payload(learnerId, courseId, issuedAt, expiresAt)
       - CertificateSigningService.signPayload(payload, tenantId) → RS256 JWS
       - If template.edciEnabled: buildEdciPayload(...)
       - ObjectService.saveObject('Certificate', {lifecycle: 'issued', openbadges3Payload, edciPayload, ...})
         → OR emits credential.issued audit; issuedToLearner notification fires automatically
       - ObjectService.saveObject('CredentialIssuance', {certificateId, issuedVia: 'auto', ...})
  4. If template not active or absent → no Certificate created

≤ 30 seconds from enrolment.completed to Certificate saved (REQ-CERT-001-A).
```

---

## 4. Delta-module flow (ContentVersion → auto-enrolment)

```
Instructor bumps course content version:
  → POST /api/openregister/scholiq/ContentVersion (lifecycle=draft)
  → PATCH .../transition/publish
    → OR emits content-version.published audit entry
    → deltaEnrolOnPublish notification fires:
        → DeltaEnrolmentHandler runs:
            1. Query Certificate WHERE courseId=X AND lifecycle=issued
               AND kind IN affectsCredentialKinds
            2. For each Certificate: read RenewalRule WHERE templateId matches AND triggerType=content-version
            3. If RenewalRule.autoEnrol=true AND RenewalRule.deltaCourseSlug set:
               → POST /api/openregister/scholiq/Enrolment (bulk batch endpoint)
                 with {learnerId, courseSlug: deltaCourseSlug, source: 'system', mandatory: true}
            4. Notify compliance-officer via nc-notification
```

---

## 5. Open Badges 3.0 payload structure

```json
{
  "@context": [
    "https://www.w3.org/2018/credentials/v1",
    "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"
  ],
  "type": ["VerifiableCredential", "OpenBadgeCredential"],
  "id": "https://<nc-host>/index.php/apps/scholiq/api/certificates/<uuid>/verify",
  "issuer": {
    "id": "<issuer_did>",
    "type": "Profile",
    "name": "<tenant organisation name>"
  },
  "issuanceDate": "<issuedAt>",
  "expirationDate": "<expiresAt or omitted>",
  "credentialSubject": {
    "type": "AchievementSubject",
    "id": "urn:scholiq:learner:<learner_uuid>",
    "achievement": {
      "type": "Achievement",
      "id": "urn:scholiq:course:<course_uuid>",
      "name": "<course name>",
      "description": "<course description>",
      "criteria": { "narrative": "<completion criteria text from template>" }
    }
  },
  "proof": {
    "type": "RsaSignature2018",
    "created": "<signed_at>",
    "verificationMethod": "<issuer_did>#keys-1",
    "proofPurpose": "assertionMethod",
    "jws": "<RS256 compact JWS>"
  }
}
```

Learner identity uses `urn:scholiq:learner:<uuid>` — opaque UUID, never BSN.

---

## 6. Frontend — `CnAppRoot` consumption

### 6.1 Manifest extension

```jsonc
{
  "pages": [
    { "id": "CertificateTemplateList",   "route": "/certificates/templates",          "type": "index",  "config": { "register": "scholiq", "schema": "CertificateTemplate" } },
    { "id": "CertificateTemplateDetail", "route": "/certificates/templates/:id",       "type": "detail", "config": { "register": "scholiq", "schema": "CertificateTemplate" } },
    { "id": "CertificateList",           "route": "/certificates",                     "type": "index",  "config": { "register": "scholiq", "schema": "Certificate" } },
    { "id": "CertificateDetail",         "route": "/certificates/:id",                 "type": "detail", "config": { "register": "scholiq", "schema": "Certificate", "tabs": ["details","auditTrail"] } },
    { "id": "CertificateVerify",         "route": "/certificates/:id/verify",          "type": "custom", "config": { "component": "CertificateVerify", "public": true } },
    { "id": "RenewalRuleList",           "route": "/certificates/renewal-rules",       "type": "index",  "config": { "register": "scholiq", "schema": "RenewalRule" } },
    { "id": "ContentVersionList",        "route": "/certificates/content-versions",    "type": "index",  "config": { "register": "scholiq", "schema": "ContentVersion" } }
  ]
}
```

### 6.2 `CertificateVerify.vue`

Single `customComponents` Vue file. `public: true` tells `CnAppRoot` to skip auth gating. Fetches `GET /api/certificates/{id}/verify` (public PHP controller), renders an NL Design System verification card with issuer name, achievement name, valid/invalid badge, issue + expiry date, QR code linking to the same URL.

### 6.3 No app-local store, no app-local router code

Per ADR-031 + ADR-024: no `useCertificateStore`, no `CertificateListView.vue`. The signing-key admin widget lives in `ScholiqSettings.vue` (nextcloud-app change), not here.

---

## 7. Audit events emitted (declaratively)

| Trigger | event_type | Declared in |
|---|---|---|
| Certificate save (lifecycle=issued) | `credential.issued` | OR default audit + `issuedToLearner` notification |
| Certificate `issued → revoked` | `credential.revoked` | `Certificate.x-openregister-lifecycle` |
| Certificate `issued → expired` (auto via calculated field) | `credential.expired` | `Certificate.x-openregister-notifications.expired` + `alsoDispatchLifecycle: expire` |
| Public verify hit | `credential.verified` | `CertificateVerifyController` writes via OR audit-trail directly (read-only event) |
| ContentVersion `draft → published` | `content-version.published` | `ContentVersion.x-openregister-lifecycle` |
| Delta enrolment created | `enrolment.created` | Enrolment schema (enrolment change) |

No `AuditEventTypes::KNOWN` enum. No `credential.expiry.reminder.sent` event — OR's notification engine with `idempotencyKey` covers it.

---

## 8. Integration points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schema lifecycle / calculations / notifications / widgets + REST + audit + relations + batch | Every Certificate operation |
| OCP\Security\ICrypto | `encrypt` / `decrypt` | Tenant signing key storage |
| Enrolment change | OR `enrolment.completed` audit event | Listener trigger for `CertificateIssuanceHandler` |
| Course-management change | `Course.certificateTemplate` + `Course.certificateTemplateId` | Gates auto-issuance |
| Compliance-audit change | Reads `Certificate` objects via OR | Audit-pack evidence |
| @conduction/nextcloud-vue | `CnAppRoot` + `customComponents` | Frontend shell + `CertificateVerify` registration |

---

## 9. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Certificate state machine (issued / revoked / expired) | declarative | lifecycle |
| CertificateTemplate state machine (draft / active / archived) | declarative | lifecycle |
| RenewalRule state machine (active / inactive) | declarative | lifecycle |
| ContentVersion state machine (draft / published / archived) | declarative | lifecycle |
| Expiry detection (T-90 / T-60 / T-30 / expired) | declarative | calculation |
| Expiry reminder dispatch | declarative | notification |
| Issued-to-learner notification | declarative | notification |
| Auto-expire lifecycle transition on `isExpired=true` | declarative | notification + `alsoDispatchLifecycle` |
| Delta-enrolment notification to compliance-officer on ContentVersion publish | declarative | notification |
| Audit entries on every transition | declarative (OR) | consumed via ADR-022 |
| Certificate CRUD UI | declarative (`CnAppRoot` + OR REST) | consumed via ADR-024 |
| Certificate ↔ Learner / Course / Template relations | declarative | relation |
| RS256 / OB3 / EDCI payload assembly + signing | imperative (PHP) | cryptographic + document-generation exception |
| Tenant keypair management | imperative (PHP) | cryptographic exception |
| Auto-issuance on `enrolment.completed` | imperative (PHP) | lifecycle handler exception |
| Delta-enrolment orchestration on `ContentVersion.published` | imperative (PHP) | lifecycle handler exception (external-state orchestration across schemas) |
| Public verify endpoint | imperative (PHP) | external-system contract exception |

---

## 10. Seed data

Seed objects per schema for dev/test (per ADR-001 seed-data requirements). Dutch values, fictional but realistic.

### CertificateTemplate (4 objects)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "CertificateTemplate", "slug": "nis2-board-certificate" },
    "name": "NIS2 Bestuurscertificaat",
    "kind": "certificate",
    "issuerName": "Gemeente Westland",
    "issuerDid": "did:web:scholiq.westland.nl",
    "courseId": null,
    "validityPeriodMonths": 12,
    "backgroundImagePath": "/Scholiq/templates/westland-cert-bg.png",
    "badgeImagePath": "/Scholiq/templates/nis2-badge.svg",
    "renewalCourseSlug": "nis2-herhaling",
    "deltaCourseSlug": "nis2-delta",
    "edciEnabled": false,
    "bolognaSupplement": false,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CertificateTemplate", "slug": "avg-medewerker-badge" },
    "name": "AVG Basisopleiding Badge",
    "kind": "badge",
    "issuerName": "Provincie Noord-Holland",
    "issuerDid": "did:web:scholiq.noord-holland.nl",
    "courseId": null,
    "validityPeriodMonths": 24,
    "backgroundImagePath": null,
    "badgeImagePath": "/Scholiq/templates/avg-badge.svg",
    "renewalCourseSlug": "avg-opfriscursus",
    "deltaCourseSlug": null,
    "edciEnabled": false,
    "bolognaSupplement": false,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CertificateTemplate", "slug": "radio-vaarbewijs-microcredential" },
    "name": "RADIO Vaarbewijs Microcredential",
    "kind": "microcredential",
    "issuerName": "Rijkswaterstaat Academy",
    "issuerDid": "did:web:academy.rijkswaterstaat.nl",
    "courseId": null,
    "validityPeriodMonths": 36,
    "backgroundImagePath": "/Scholiq/templates/rws-cert-bg.png",
    "badgeImagePath": "/Scholiq/templates/radio-badge.svg",
    "renewalCourseSlug": "radio-verlenging",
    "deltaCourseSlug": "radio-delta-module",
    "edciEnabled": true,
    "bolognaSupplement": false,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CertificateTemplate", "slug": "bestuurskunde-diploma" },
    "name": "Bachelor Bestuurskunde Diploma",
    "kind": "diploma",
    "issuerName": "Hogeschool van Amsterdam",
    "issuerDid": "did:web:hva.nl",
    "courseId": null,
    "validityPeriodMonths": null,
    "backgroundImagePath": "/Scholiq/templates/hva-diploma-bg.png",
    "badgeImagePath": "/Scholiq/templates/hva-badge.svg",
    "renewalCourseSlug": null,
    "deltaCourseSlug": null,
    "edciEnabled": true,
    "bolognaSupplement": true,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000002"
  }
]
```

### Certificate (4 objects)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "Certificate", "slug": "cert-janssen-nis2-2025" },
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000011",
    "templateId": "a1b2c3d4-tmpl-0001-0001-000000000001",
    "courseId": "a1b2c3d4-crs-0001-0001-000000000001",
    "kind": "certificate",
    "issuedAt": "2025-06-15T10:30:00Z",
    "expiresAt": "2026-06-15T10:30:00Z",
    "issuerDid": "did:web:scholiq.westland.nl",
    "openbadges3Payload": { "@context": ["https://www.w3.org/2018/credentials/v1","https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"], "type": ["VerifiableCredential","OpenBadgeCredential"] },
    "edciPayload": null,
    "revocationReason": null,
    "source": "auto",
    "verificationUrl": "https://westland.scholiq.nl/api/certificates/cert-janssen-nis2-2025/verify",
    "regulationSlug": "NIS2",
    "renewalCertificateId": null,
    "lifecycle": "issued",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Certificate", "slug": "badge-bakker-avg-2025" },
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000022",
    "templateId": "a1b2c3d4-tmpl-0002-0002-000000000002",
    "courseId": "a1b2c3d4-crs-0002-0002-000000000002",
    "kind": "badge",
    "issuedAt": "2025-09-01T08:00:00Z",
    "expiresAt": "2027-09-01T08:00:00Z",
    "issuerDid": "did:web:scholiq.noord-holland.nl",
    "openbadges3Payload": { "@context": ["https://www.w3.org/2018/credentials/v1","https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"], "type": ["VerifiableCredential","OpenBadgeCredential"] },
    "edciPayload": null,
    "revocationReason": null,
    "source": "auto",
    "verificationUrl": "https://nh.scholiq.nl/api/certificates/badge-bakker-avg-2025/verify",
    "regulationSlug": "AVG",
    "renewalCertificateId": null,
    "lifecycle": "issued",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Certificate", "slug": "cert-devries-radio-revoked" },
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000033",
    "templateId": "a1b2c3d4-tmpl-0003-0003-000000000003",
    "courseId": "a1b2c3d4-crs-0003-0003-000000000003",
    "kind": "microcredential",
    "issuedAt": "2024-03-10T14:00:00Z",
    "expiresAt": "2027-03-10T14:00:00Z",
    "issuerDid": "did:web:academy.rijkswaterstaat.nl",
    "openbadges3Payload": { "@context": ["https://www.w3.org/2018/credentials/v1","https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"], "type": ["VerifiableCredential","OpenBadgeCredential"] },
    "edciPayload": { "type": "EuropassCredential", "title": { "nl": "RADIO Vaarbewijs" } },
    "revocationReason": "Medewerker uit dienst per 2026-01-01",
    "source": "auto",
    "verificationUrl": "https://rws.scholiq.nl/api/certificates/cert-devries-radio-revoked/verify",
    "regulationSlug": null,
    "renewalCertificateId": null,
    "lifecycle": "revoked",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Certificate", "slug": "diploma-smit-bestuurskunde-2026" },
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000044",
    "templateId": "a1b2c3d4-tmpl-0004-0004-000000000004",
    "courseId": "a1b2c3d4-crs-0004-0004-000000000004",
    "kind": "diploma",
    "issuedAt": "2026-02-01T09:00:00Z",
    "expiresAt": null,
    "issuerDid": "did:web:hva.nl",
    "openbadges3Payload": { "@context": ["https://www.w3.org/2018/credentials/v1","https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"], "type": ["VerifiableCredential","OpenBadgeCredential"] },
    "edciPayload": { "type": "EuropassCredential", "title": { "nl": "Bachelor Bestuurskunde" }, "diplomaSupplement": true },
    "revocationReason": null,
    "source": "manual",
    "verificationUrl": "https://hva.scholiq.nl/api/certificates/diploma-smit-bestuurskunde-2026/verify",
    "regulationSlug": null,
    "renewalCertificateId": null,
    "lifecycle": "issued",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000002"
  }
]
```

### CredentialIssuance (3 objects)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "CredentialIssuance", "slug": "issuance-janssen-nis2-auto" },
    "certificateId": "a1b2c3d4-cert-0001-0001-000000000001",
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000011",
    "courseId": "a1b2c3d4-crs-0001-0001-000000000001",
    "templateId": "a1b2c3d4-tmpl-0001-0001-000000000001",
    "issuedVia": "auto",
    "ob3SignatureRef": "eyJhbGciOiJSUzI1NiJ9.stub.sig",
    "edciSignatureRef": null,
    "enrolmentId": "a1b2c3d4-enr-0001-0001-000000000001",
    "issuedByUserId": null,
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CredentialIssuance", "slug": "issuance-smit-diploma-manual" },
    "certificateId": "a1b2c3d4-cert-0004-0004-000000000004",
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000044",
    "courseId": "a1b2c3d4-crs-0004-0004-000000000004",
    "templateId": "a1b2c3d4-tmpl-0004-0004-000000000004",
    "issuedVia": "diploma-award",
    "ob3SignatureRef": "eyJhbGciOiJSUzI1NiJ9.stub2.sig",
    "edciSignatureRef": "edci-sig-stub-hva-2026",
    "enrolmentId": null,
    "issuedByUserId": "registrar.hva",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000002"
  },
  {
    "@self": { "register": "scholiq", "schema": "CredentialIssuance", "slug": "issuance-bakker-avg-auto" },
    "certificateId": "a1b2c3d4-cert-0002-0002-000000000002",
    "learnerId": "urn:scholiq:learner:e1f2a3b4-0000-0000-0000-000000000022",
    "courseId": "a1b2c3d4-crs-0002-0002-000000000002",
    "templateId": "a1b2c3d4-tmpl-0002-0002-000000000002",
    "issuedVia": "auto",
    "ob3SignatureRef": "eyJhbGciOiJSUzI1NiJ9.stub3.sig",
    "edciSignatureRef": null,
    "enrolmentId": "a1b2c3d4-enr-0002-0002-000000000002",
    "issuedByUserId": null,
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  }
]
```

### RenewalRule (3 objects)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "RenewalRule", "slug": "renewal-nis2-expiry" },
    "templateId": "a1b2c3d4-tmpl-0001-0001-000000000001",
    "triggerType": "expiry",
    "expiryThresholdDays": 30,
    "renewalCourseSlug": "nis2-herhaling",
    "deltaCourseSlug": null,
    "autoEnrol": true,
    "notifyLearner": true,
    "notifyManager": true,
    "notifyComplianceOfficer": true,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RenewalRule", "slug": "renewal-radio-content-version" },
    "templateId": "a1b2c3d4-tmpl-0003-0003-000000000003",
    "triggerType": "content-version",
    "expiryThresholdDays": null,
    "renewalCourseSlug": null,
    "deltaCourseSlug": "radio-delta-module",
    "autoEnrol": true,
    "notifyLearner": true,
    "notifyManager": false,
    "notifyComplianceOfficer": true,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RenewalRule", "slug": "renewal-avg-expiry" },
    "templateId": "a1b2c3d4-tmpl-0002-0002-000000000002",
    "triggerType": "expiry",
    "expiryThresholdDays": 60,
    "renewalCourseSlug": "avg-opfriscursus",
    "deltaCourseSlug": null,
    "autoEnrol": true,
    "notifyLearner": true,
    "notifyManager": false,
    "notifyComplianceOfficer": false,
    "lifecycle": "active",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  }
]
```

### ContentVersion (3 objects)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "ContentVersion", "slug": "content-version-nis2-2026q1" },
    "courseId": "a1b2c3d4-crs-0001-0001-000000000001",
    "version": "2026-Q1",
    "changedAt": "2026-01-15T09:00:00Z",
    "deltaScope": "Bijgewerkte artikelen 21 en 23 Cyberbeveiligingswet; nieuwe meldplicht toegevoegd",
    "affectsCredentialKinds": ["certificate","diploma"],
    "lifecycle": "published",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "ContentVersion", "slug": "content-version-radio-2025q3" },
    "courseId": "a1b2c3d4-crs-0003-0003-000000000003",
    "version": "2025-Q3",
    "changedAt": "2025-09-01T00:00:00Z",
    "deltaScope": "Nieuwe vaarregels binnenvaart 2025; bijgewerkte toetsvragen module 3",
    "affectsCredentialKinds": ["microcredential"],
    "lifecycle": "published",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "ContentVersion", "slug": "content-version-avg-2026q2-draft" },
    "courseId": "a1b2c3d4-crs-0002-0002-000000000002",
    "version": "2026-Q2",
    "changedAt": "2026-05-01T12:00:00Z",
    "deltaScope": "Uitbreiding verwerkersovereenkomst-module; nieuw onderdeel AI-toepassingen onder AVG",
    "affectsCredentialKinds": ["badge","certificate"],
    "lifecycle": "draft",
    "tenant_id": "a1b2c3d4-0001-0001-0001-000000000001"
  }
]
```

---

## 11. Wedge scope exclusions

| Excluded | Deferred to |
|---|---|
| Full Decentralized Identifier (DID) document management | Phase 3 |
| EDCI ELM linked-data proof suite (beyond payload JSON) | Phase 3 |
| Bologna Diploma Supplement PDF generation | Phase 2 (HE context) |
| Blockchain-anchored credential anchoring | Enterprise/Phase 4 |
| Cross-institution edubadges.nl federation (SURF) | V1 |
| Certificate Template Designer UI (visual editor) | V1 |
| Manual paper certificate printing → DocuDesk | Optional/V1 |
| E-Portfolio NL portability feed | Phase 2 |
| Schema.org `EducationalOccupationalCredential` microdata in verify page | V1 |
