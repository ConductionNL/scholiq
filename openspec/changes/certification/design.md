# Design — Certification

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — Credential lifecycle (issued / revoked / expired), expiry detection (T-90 / T-30 / 0d), and expiry reminders fit `x-openregister-lifecycle` / `-calculations` / `-notifications`. The auto-issuance on Enrolment-completed is a schema-declared notification on Course that dispatches the Credential.issue transition. The signing operation is `CredentialSigningService` (legitimate PHP per ADR-031 — cryptographic).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable), notifications, lifecycle events, batch operations, relations, RBAC. No app-local notification service, no app-local TimedJob.

## 1. Schema patch on `lib/Settings/scholiq_register.json`

### 1.1 `Credential`

```jsonc
"Credential": {
  "slug": "credential",
  "icon": "CertificateOutline",
  "version": "0.1.0",
  "title": "Credential",
  "description": "Verifiable Credential (W3C VC + Open Badges 3.0 + EDCI ELM)",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["learnerId", "kind", "issuedAt", "issuerDid", "signature", "openbadges3Payload", "tenant_id"],
  "properties": {
    "learnerId":          { "type": "string" },
    "courseId":           { "type": ["string","null"], "format": "uuid" },
    "kind":               { "type": "string", "enum": ["diploma","certificate","badge","microcredential"] },
    "issuedAt":           { "type": "string", "format": "date-time" },
    "expiresAt":          { "type": ["string","null"], "format": "date-time" },
    "issuerDid":          { "type": "string" },
    "signature":          { "type": "string" },
    "openbadges3Payload": { "type": "object" },
    "edciPayload":        { "type": ["object","null"] },
    "revocationReason":   { "type": ["string","null"] },
    "issuedBy":           { "type": "string" },
    "source":             { "type": "string", "enum": ["auto","manual","migrated"] },
    "regulationSlug":     { "type": ["string","null"] },
    "renewalEnrolmentId": { "type": ["string","null"], "format": "uuid" },
    "verificationUrl":    { "type": "string", "format": "uri" },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "issued",
    "transitions": {
      "revoke":  { "from": "issued", "to": "revoked" },
      "expire":  { "from": "issued", "to": "expired" }
    }
  },
  "x-openregister-relations": {
    "learner": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "learnerId" },
    "course":  { "register": "scholiq", "schema": "Course",         "cardinality": "many-to-one", "joinOn": "courseId" }
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
    "isExpiringIn90Days": { "type": "boolean", "materialise": true, "expression": { "and": [ { "eq": [ { "prop": "lifecycle" }, "issued" ] }, { "neq": [ { "prop": "expiresAt" }, null ] }, { "lte": [ { "prop": "daysUntilExpiry" }, 90 ] }, { "gt":  [ { "prop": "daysUntilExpiry" }, 30 ] } ] } },
    "isExpiringIn30Days": { "type": "boolean", "materialise": true, "expression": { "and": [ { "eq": [ { "prop": "lifecycle" }, "issued" ] }, { "neq": [ { "prop": "expiresAt" }, null ] }, { "lte": [ { "prop": "daysUntilExpiry" }, 30 ] }, { "gt":  [ { "prop": "daysUntilExpiry" }, 0 ] } ] } },
    "isExpired":          { "type": "boolean", "materialise": true, "expression": { "and": [ { "eq": [ { "prop": "lifecycle" }, "issued" ] }, { "neq": [ { "prop": "expiresAt" }, null ] }, { "lte": [ { "prop": "daysUntilExpiry" }, 0 ] } ] } }
  },
  "x-openregister-notifications": {
    "issuedToLearner": {
      "trigger":   { "lifecycleEnter": "issued" },
      "channel":   "nc-notification",
      "subject":   "scholiq.credential.issued",
      "recipient": "@self.learnerId"
    },
    "expiryT90": {
      "trigger":   { "calculated": "isExpiringIn90Days", "eq": true },
      "channel":   "nc-notification",
      "subject":   "scholiq.credential.expiring.t90",
      "recipient": "@self.learnerId",
      "idempotencyKey": "expiryT90"
    },
    "expiryT30": {
      "trigger":   { "calculated": "isExpiringIn30Days", "eq": true },
      "channel":   "nc-notification",
      "subject":   "scholiq.credential.expiring.t30",
      "recipient": "@self.learnerId",
      "idempotencyKey": "expiryT30"
    },
    "expired": {
      "trigger":   { "calculated": "isExpired", "eq": true },
      "channel":   "nc-notification",
      "subject":   "scholiq.credential.expired",
      "recipient": "@self.learnerId",
      "idempotencyKey": "expired",
      "alsoDispatchLifecycle": "expire"
    }
  }
}
```

**What each block replaces:**

| v1 element | Replaced by |
|---|---|
| `CredentialController` CRUD | `CnAppRoot` index/detail page bound to `register=scholiq schema=Credential` |
| `CredentialIssuanceService::issueForEnrolment` orchestration | A schema-declared notification on `Course`'s linked Enrolment.lifecycle-enter-completed that triggers a Credential save (see §3). The OB3 payload assembly + signing IS the legitimate PHP that remains. |
| `ExpiryDetectionService` | `x-openregister-calculations` for `isExpiringIn90Days` / `isExpiringIn30Days` / `isExpired` + `x-openregister-notifications` for the three reminder dispatches |
| `EnrolmentCompletedCredentialListener` | Schema-declared rule on Course that fires the Credential save (per §3) |
| `CredentialExpiryJob` TimedJob | `x-openregister-notifications` triggered by calculated fields (ADR-031 §"Background jobs that walk an object queue" case (1)) |
| `reminder_*_sent` boolean fields | Removed. OR's `idempotencyKey` tracks dispatch |
| `autoEnrolForRenewal` orchestration | A schema-declared rule on Credential.lifecycle-enter-expired that creates a renewal Enrolment via OR's batch endpoint, gated on `Course.renewalCourseSlug` being set |

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Service/CredentialSigningService.php` | Cryptographic operation | RS256 / linked-data signature for OB3 + EDCI payloads. Reads tenant private key via `OCP\Security\ICrypto::decrypt()`; signs a canonicalised payload. ADR-031 §"What apps SHOULD still write in PHP" explicitly includes "Document/PDF/document-template generation with app-specific templates" + cryptographic operations belong under the same exception class. |
| `lib/Service/KeyManagementService.php` | Cryptographic operation | `generateTenantKeypair(tenantId)` generating RSA-2048 keypair; stores private key via `ICrypto::encrypt`. Cryptographic operation, legitimate PHP. |
| `lib/Lifecycle/CredentialIssuanceHandler.php` | Lifecycle handler | Listens for the OR `enrolment.completed` audit event; when `course.certificateTemplate` is set, calls `CredentialSigningService::buildAndSignOb3` and writes a new Credential object via OR. Single-method handler. |
| `lib/Controller/CredentialVerifyController.php` | External-system contract | Public `GET /api/credentials/{id}/verify` endpoint (no auth, no session middleware). Returns `{valid, issuedAt, expiresAt, issuerName}` — no personal data. The verification surface for external auditors / employers. |
| `lib/Controller/KeyAdminController.php` | Wraps KeyManagementService | Admin-only `POST /api/credentials/admin/generate-key` action. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `CredentialController` (CRUD) — `CnAppRoot` index/detail page covers it.
- `ExpiryDetectionService` — replaced by `x-openregister-calculations`.
- `CredentialExpiryJob` (TimedJob) — replaced by `x-openregister-notifications` (case 1 of ADR-031's background-job decision tree).
- `EnrolmentCompletedCredentialListener` (the v1 listener service) — collapsed into `CredentialIssuanceHandler` lifecycle handler.
- `autoEnrolForRenewal` service method — replaced by a Credential schema-declared post-expiry rule.

---

## 3. Auto-issuance flow

The Enrolment → Credential pipeline is declared, not coded:

1. `Enrolment.lifecycle: active → completed` (declared in enrolment change).
2. OR's audit-trail event `enrolment.completed` fires.
3. `CredentialIssuanceHandler` PHP listener (Phase 2 task below) is wired as an OR audit-event listener via `IEventDispatcher::addListener('openregister.audit.enrolment.completed', ...)`. The handler:
   - Reads the Enrolment's Course via OR relations.
   - If `course.certificateTemplate` is set, calls `CredentialSigningService::buildAndSignOb3` to produce the OB3 payload + RS256 signature.
   - Calls `ObjectService::saveObject('Credential', $payload)` with `lifecycle=issued` (which emits `credential.issued` audit + the `issuedToLearner` notification automatically).
4. If `course.renewalCourseSlug` is set, a future Credential lifecycle event (`isExpired=true → expire transition`) creates a renewal Enrolment via OR's batch endpoint. The renewal-Enrolment id is stored back on the original Credential as `renewalEnrolmentId`.

No `CredentialIssuanceService::issueForEnrolment` orchestrator. The single legitimate PHP is the signing primitive + the thin handler that wires audit-event → schema save.

## 4. Open Badges 3.0 payload assembly

The OB3 JSON-LD structure documented in v1 is unchanged (per the spec.md REQ that doesn't go away). `CredentialSigningService::buildOb3Payload(credentialId, learnerId, courseId, issuedAt, expiresAt)` is called by `CredentialIssuanceHandler` and produces:

```json
{
  "@context": [
    "https://www.w3.org/2018/credentials/v1",
    "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"
  ],
  "type": ["VerifiableCredential", "OpenBadgeCredential"],
  "id": "https://<nc-host>/index.php/apps/scholiq/api/credentials/<uuid>/verify",
  "issuer": { "id": "<issuer_did>", "type": "Profile", "name": "<tenant organisation name>" },
  "issuanceDate": "<issuedAt>",
  "expirationDate": "<expiresAt or null>",
  "credentialSubject": {
    "type": "AchievementSubject",
    "id": "urn:scholiq:learner:<learner_uuid>",
    "achievement": { "type": "Achievement", "id": "urn:scholiq:course:<course_uuid>", "name": "<course name>", "description": "<course description>", "criteria": { "narrative": "<completion criteria>" } }
  },
  "proof": { "type": "RsaSignature2018", "created": "<signed_at>", "verificationMethod": "<issuer_did>#keys-1", "proofPurpose": "assertionMethod", "jws": "<RS256 compact JWS>" }
}
```

Learner identity uses `urn:scholiq:learner:<uuid>` — opaque, never BSN.

---

## 5. Frontend — `CnAppRoot` consumption

### 5.1 Manifest extension

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    { "id": "CredentialDetail", "route": "/credentials/:id",        "type": "detail", "config": { "register": "scholiq", "schema": "Credential" } },
    { "id": "CredentialVerify", "route": "/credentials/:id/verify", "type": "custom", "config": { "component": "CredentialVerify", "public": true } }
  ]
}
```

### 5.2 `CredentialVerify.vue`

Single `customComponents` Vue file. `public: true` flag tells `CnAppRoot` to skip auth gating. Fetches `GET /api/credentials/{id}/verify` (public PHP controller), renders an NL Design verification card + QR code.

### 5.3 No app-local store, no app-local Vue Router code

Per ADR-031 + ADR-024: no `useCredentialStore`, no `CredentialListView.vue`. `CnAppRoot`'s built-in renderers cover list/detail. The signing-key admin widget lives inside the existing `ScholiqSettings.vue` (declared in the nextcloud-app change), not in a per-spec settings file.

---

## 6. Audit Events Emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| Credential save | `credential.issued` | OR default save audit + `issuedToLearner` notification |
| Credential transition `issued → revoked` | `credential.revoked` | `Credential.x-openregister-lifecycle` |
| Credential transition `issued → expired` | `credential.expired` | `Credential.x-openregister-lifecycle` + `expired` notification |
| Public verify hit | `credential.verified` | `CredentialVerifyController` writes via OR audit-trail directly (lookup-only event; no state change) |

No `AuditEventTypes::KNOWN`, no `credential.expiry.reminder.sent` event (replaced by OR's notification-dispatched event with `idempotencyKey`).

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schema lifecycle / calculations / notifications + REST + audit + relations + batch | Every Credential operation |
| OCP\Security\ICrypto | Encrypt/decrypt | Tenant signing key storage |
| Enrolment change | OR `enrolment.completed` audit event | Listener trigger for `CredentialIssuanceHandler` |
| Course-management change | `Course.certificateTemplate` + `Course.renewalCourseSlug` | Gates auto-issuance + renewal auto-enrol |
| Compliance-audit change | Reads `Credential` objects via OR | Audit-pack evidence |
| @conduction/nextcloud-vue | `CnAppRoot` + `customComponents` | Frontend shell + `CredentialVerify` registration |

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Credential state machine (issued / revoked / expired) | declarative | lifecycle |
| Expiry detection (T-90 / T-30 / 0d) | declarative | calculation |
| Expiry reminder dispatch | declarative | notification |
| Issued-to-learner notification | declarative | notification |
| Audit entries on every transition | declarative (OR) | (consumed via ADR-022) |
| Credential CRUD UI | declarative (CnAppRoot + OR REST) | (consumed via ADR-024) |
| Credential ↔ Learner / Course relations | declarative | relation |
| RS256 / OB3 / EDCI payload assembly + signing | imperative (PHP) | "Document generation" + cryptographic exception |
| Tenant keypair management | imperative (PHP) | Cryptographic exception |
| Auto-issuance on Enrolment.completed | imperative (PHP) | "Lifecycle handler" exception |
| Public verify endpoint | imperative (PHP) | "External-system contract" exception |

---

## 9. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| EDCI/Europass ELM encoding | Phase 3 |
| DID (Decentralized Identifier) management | Phase 3 |
| Bologna Diploma Supplement | Phase 2 (HE) |
| Blockchain-anchored credential anchoring | Enterprise/Phase 4 |
| edubadges.nl federation (SURF) | V1 |
| Certificate Template Designer UI | V1 |
| Manual paper certificate printing → DocuDesk | Optional/V1 |
