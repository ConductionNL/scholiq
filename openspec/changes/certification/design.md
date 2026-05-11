# Design — Certification

## 1. OpenRegister Schema — `scholiq-credential`

Maps to `Credential` entity in ARCHITECTURE.md §3.1 (W3C VC + Open Badges 3.0):

```json
{
  "title": "scholiq-credential",
  "properties": {
    "id":                  { "type": "string", "format": "uuid" },
    "learner_id":          { "type": "string" },
    "course_id":           { "type": ["string","null"], "format": "uuid" },
    "kind":                { "type": "string", "enum": ["diploma","certificate","badge","microcredential"] },
    "issued_at":           { "type": "string", "format": "date-time" },
    "expires_at":          { "type": ["string","null"], "format": "date-time" },
    "issuer_did":          { "type": "string" },
    "signature":           { "type": "string" },
    "openbadges3_payload": { "type": "object" },
    "revoked":             { "type": "boolean", "default": false },
    "revocation_reason":   { "type": ["string","null"] },
    "revoked_at":          { "type": ["string","null"], "format": "date-time" },
    "issued_by":           { "type": "string" },
    "source":              { "type": "string", "enum": ["auto","manual","migrated"] },
    "regulation_slug":     { "type": ["string","null"] },
    "tenant_id":           { "type": "string", "format": "uuid" },
    "renewal_enrolment_id":{ "type": ["string","null"], "format": "uuid" },
    "reminder_90_sent":    { "type": "boolean", "default": false },
    "reminder_60_sent":    { "type": "boolean", "default": false },
    "reminder_30_sent":    { "type": "boolean", "default": false },
    "verification_url":    { "type": "string", "format": "uri" },
    "created_at":          { "type": "string", "format": "date-time" },
    "updated_at":          { "type": "string", "format": "date-time" }
  },
  "required": ["learner_id","kind","issued_at","issuer_did","signature","openbadges3_payload","tenant_id"],
  "indexes": [
    ["learner_id","tenant_id","revoked"],
    ["course_id","tenant_id","revoked"],
    ["expires_at","revoked","tenant_id"],
    ["regulation_slug","tenant_id","revoked"]
  ]
}
```

The `reminder_*_sent` booleans ensure idempotent notification dispatch (same pattern as enrolment spec).

---

## 2. Open Badges 3.0 Payload Structure

Per the 1EdTech Open Badges 3.0 specification. The `openbadges3_payload` field contains:

```json
{
  "@context": [
    "https://www.w3.org/2018/credentials/v1",
    "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"
  ],
  "type": ["VerifiableCredential", "OpenBadgeCredential"],
  "id": "https://<nc-host>/index.php/apps/scholiq/api/credentials/<uuid>/verify",
  "issuer": {
    "id": "<issuer_did>",
    "type": "Profile",
    "name": "<tenant organisation name>"
  },
  "issuanceDate": "<issued_at>",
  "expirationDate": "<expires_at or null>",
  "credentialSubject": {
    "type": "AchievementSubject",
    "id": "urn:scholiq:learner:<learner_uuid>",
    "achievement": {
      "type": "Achievement",
      "id": "urn:scholiq:course:<course_uuid>",
      "name": "<course name>",
      "description": "<course description>",
      "criteria": { "narrative": "<completion criteria>" }
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

Note: learner identity uses `urn:scholiq:learner:<uuid>` — opaque, never BSN.

---

## 3. PHP Services

### 3.1 `CredentialIssuanceService`

```php
class CredentialIssuanceService
{
    public function issueForEnrolment(
        string $learnerId,
        string $courseId,
        string $enrolmentId,
        \DateTimeInterface $issuedAt,
        ?\DateTimeInterface $expiresAt,
        string $regulationSlug
    ): Credential

    public function buildOb3Payload(
        string $credentialId,
        string $learnerId,
        string $courseId,
        \DateTimeInterface $issuedAt,
        ?\DateTimeInterface $expiresAt
    ): array // OB3 JSON-LD array

    public function signPayload(array $payload, string $tenantId): string // RS256 JWS
}
```

`signPayload()` retrieves the tenant's RSA private key from `OCP\Security\ICrypto::decrypt()` (key stored under `scholiq.credential.signing.<tenantId>`). Signs using `openssl_sign()` with `OPENSSL_ALGO_SHA256`. The `jws` is a compact detached JWS (RFC 7797).

### 3.2 `ExpiryDetectionService`

```php
class ExpiryDetectionService
{
    public function getCredentialsExpiringSoon(int $days, string $tenantId): array
    public function markExpired(string $credentialId): void
    public function autoEnrolForRenewal(string $credentialId): ?string // returns new Enrolment id
}
```

`autoEnrolForRenewal()` calls `BulkEnrolmentService` to create a single Enrolment with `source='system'`, `mandatory=true`, `due_date=credential.expires_at`. Requires `course.renewal_course_id` to be set on the parent course.

### 3.3 `EnrolmentCompletedCredentialListener`

Registered in `Application.php` via `IEventDispatcher::addListener('scholiq.enrolment.completed', ...)`.

```php
public function handle(EnrolmentCompletedEvent $event): void
{
    $course = $this->objectService->getObject('scholiq-course', $event->getCourseId());
    if (empty($course['certificate_template'])) {
        return; // no template → no credential
    }
    $expiresAt = $this->computeExpiry($course);
    $credential = $this->issuanceService->issueForEnrolment(
        $event->getLearnerId(),
        $event->getCourseId(),
        $event->getEnrolmentId(),
        new \DateTimeImmutable(),
        $expiresAt,
        $course['regulation_slug'] ?? ''
    );
    $this->auditTrail->record('credential.issued', [
        'subject_type' => 'credential',
        'subject_id'   => $credential->getId(),
        'after'        => $credential->toArray(),
        'lawful_basis' => 'contract',
    ]);
    $this->notificationService->dispatchEnrolmentNotification('credential_issued', $event->getLearnerId(), [...]);
}
```

---

## 4. PHP Controllers

### 4.1 `CredentialController`

Routes:
```
GET    /api/credentials               → list (filters: learner_id, course_id, revoked, expires_before, regulation_slug)
POST   /api/credentials               → manual issue
GET    /api/credentials/{id}          → show (authenticated)
PATCH  /api/credentials/{id}          → revoke (admin/hr only)
GET    /api/credentials/{id}/verify   → public verification (no auth required)
```

`verify` endpoint uses `skipCSRFCheck` + no session middleware. Returns only {valid, issued_at, expires_at, issuer_name} — no personal data.

---

## 5. Background Job

### `CredentialExpiryJob`

Extends `TimedJob`, interval 86400s. Runs after `EnrolmentDueReminderJob` in same daily window.

Algorithm:
1. Query credentials with `expires_at BETWEEN now AND now+90d AND revoked=false AND tenant_id=<each-tenant>`.
2. For each, check T-90/T-60/T-30 thresholds against `expires_at - today`.
3. Dispatch `credential_expiring` notification per threshold (idempotency via reminder_*_sent).
4. At T-30: call `autoEnrolForRenewal()` if `course.renewal_course_id` is set.
5. For credentials where `expires_at <= today`: emit `credential.expired`, dispatch expired notification.

---

## 6. Vue Frontend

### 6.1 Route additions

```js
{ path: '/credentials',             component: () => import('../views/CredentialListView.vue')   },
{ path: '/credentials/:id',         component: () => import('../views/CredentialDetailView.vue') },
{ path: '/credentials/:id/verify',  component: () => import('../views/CredentialVerifyView.vue') },
```

### 6.2 Key components

- **`CredentialListView.vue`**: CnDataTable; columns: learner, course, kind badge, issued_at, expires_at (amber/red if < 90/30 days), verified badge. Filters: regulation_slug, revoked toggle.
- **`CredentialDetailView.vue`**: CnDetailPage + CnObjectSidebar. Tabs: Details (OB3 payload formatted), Audit Trail. Actions: Revoke (admin/hr only) — opens reason capture dialog.
- **`CredentialVerifyView.vue`**: public, no auth. Shows verification result card. Embeds QR code pointing to the verify URL.

---

## 7. Admin Settings Addition

Add to `AdminSettings.vue`:
- Tenant signing key status: "Key present / Not configured". Action: "Generate new key" (calls `POST /api/credentials/admin/generate-key`).
- `certificate_template_default` setting (string — relative nc:files path to default PDF template).
- Certificate expiry defaults per regulation (JSON editor).

---

## 8. Audit Events Emitted

| Action | event_type | lawful_basis |
|---|---|---|
| Auto-issuance on enrolment complete | `credential.issued` | contract |
| Manual issuance | `credential.issued` | contract |
| Revocation | `credential.revoked` | contract |
| Expiry reminder dispatched | `credential.expiry.reminder.sent` | contract |
| Credential expired (past due) | `credential.expired` | contract |

Add `credential.expiry.reminder.sent`, `credential.expired` to `AuditEventTypes::KNOWN`.

---

## 9. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `ObjectService` | Persist Credential objects |
| OCP\Security\ICrypto | `ICrypto::encrypt/decrypt` | Tenant signing key storage |
| EventDispatcher | `IEventDispatcher` | Listen for `scholiq.enrolment.completed` |
| OCP\Notification\IManager | — | Credential issued / expiry notifications |
| AuditTrail | `Scholiq\Service\AuditTrail` | All credential mutation events |
| Enrolment spec | `BulkEnrolmentService` | Auto-enrol in renewal module at T-30 |
| Compliance-audit spec | `GET /api/credentials` | Enumerate valid credentials for audit-pack |

---

## 10. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| EDCI/Europass ELM encoding | Phase 3 |
| DID (Decentralized Identifier) management | Phase 3 |
| Bologna Diploma Supplement | Phase 2 (HE) |
| Blockchain-anchored credential anchoring | Enterprise/Phase 4 |
| edubadges.nl federation (SURF) | V1 |
| Certificate Template Designer UI | V1 |
| Manual paper certificate printing → DocuDesk | Optional/V1 |
