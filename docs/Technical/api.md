# Scholiq, API Reference

Scholiq exposes two categories of API surface:

1. **OpenRegister object API**, all CRUD and lifecycle operations on the 9 schemas go through OR's REST API. Scholiq does not wrap these with its own controllers.
2. **Scholiq-specific endpoints**, thin PHP controllers for operations OR cannot yet express declaratively: public credential verification, audit-pack ZIP export, admin key management, and health diagnostics.

Base URL (local dev): `http://localhost:8080/index.php/apps`

---

## OpenRegister object API

Scholiq uses the standard OR objects API. The register slug is `scholiq`.

### List objects

```
GET /openregister/api/objects?register=scholiq&schema={schema-slug}
```

**Example, list all published courses:**

```
GET /openregister/api/objects?register=scholiq&schema=course&lifecycle=published
```

**Example, list mandatory enrolments for a learner:**

```
GET /openregister/api/objects?register=scholiq&schema=enrolment&learnerId=alice&mandatory=true
```

Response: standard OR paginated JSON list.

### Get single object

```
GET /openregister/api/objects/{uuid}
```

### Create object

```
POST /openregister/api/objects
Content-Type: application/json

{
  "register": "scholiq",
  "schema": "course",
  "object": {
    "code": "NIS2-2026",
    "name": "NIS2 Awareness Training",
    "level": "corporate",
    "language": "nl",
    "mandatoryTraining": true,
    "regulationSlug": "NIS2",
    "tenant_id": "{tenant-uuid}"
  }
}
```

### Update object

```
PUT /openregister/api/objects/{uuid}
Content-Type: application/json
```

### Lifecycle transition

```
POST /openregister/api/objects/{uuid}/transition
Content-Type: application/json

{
  "transition": "publish"
}
```

**Example, publish a course:**

```
POST /openregister/api/objects/{course-uuid}/transition
{ "transition": "publish" }
```

If the `CoursePublishGuard` check fails (no published lessons), the response is `422 Unprocessable Entity` with an error message.

**Example, sign an attestation:**

```
POST /openregister/api/objects/{attestation-uuid}/transition
{ "transition": "sign" }
```

`AttestationSigningGuard` validates the xAPI completion record and sets `signature` and `signingKeyId` on the object.

### Import register configuration

Used on initial setup (see openregister#1487 workaround):

```
POST /openregister/api/configurations/import
Content-Type: application/json

(body: contents of lib/Settings/scholiq_register.json)
```

---

## Scholiq-specific endpoints

All endpoints below require an authenticated Nextcloud session unless marked `@PublicPage`.

### GET `/scholiq/api/credentials/{id}/verify`

**Public (no auth required).**

Verifies a credential by ID. Returns validity status and the Open Badges 3.0 payload. Writes a `credential.verified` audit entry via OR.

**Response 200, valid credential:**

```json
{
  "valid": true,
  "credential": {
    "id": "{uuid}",
    "learnerId": "{opaque-uuid}",
    "kind": "certificate",
    "issuedAt": "2026-01-15T10:00:00Z",
    "expiresAt": "2027-01-15T10:00:00Z",
    "expiryStatus": "valid",
    "lifecycle": "issued",
    "isOpenBadgesV3Signed": true,
    "openbadges3Payload": { ... }
  }
}
```

**Response 200, revoked credential:**

```json
{
  "valid": false,
  "credential": {
    "id": "{uuid}",
    "lifecycle": "revoked",
    "revocationReason": "Employee offboarded"
  }
}
```

**Response 404:** Credential not found.

---

### GET `/scholiq/api/admin/health`

**Auth required. Admin-only.**

Returns observability data for the AdminHealth dashboard widget.

**Response 200:**

```json
{
  "openregisterConnected": true,
  "schemaCount": 9,
  "auditEventsLast24h": 142,
  "mydashInstalled": false,
  "lastAuditPackExport": "2026-05-10T08:30:00Z"
}
```

---

### POST `/scholiq/api/compliance/export`

**Auth required. Compliance-officer or admin role.**

Streams an audit-pack ZIP for a given regulation and date range. The export itself is recorded in the OR audit trail.

**Request:**

```
POST /scholiq/api/compliance/export
Content-Type: application/json

{
  "regulationSlug": "NIS2",
  "dateFrom": "2026-01-01",
  "dateTo": "2026-05-12"
}
```

**Response:** `200 OK` with `Content-Type: application/zip` streaming a ZIP file containing:

| File | Contents |
|---|---|
| `audit-trail.ndjson` | OR audit-trail entries for the regulation and period, one JSON object per line |
| `audit-trail.csv` | Same entries in CSV format |
| `manifest.json` | Export metadata: regulation, date range, export timestamp, record counts |
| `signature-verification.txt` | Instructions for offline HMAC/RS256 signature verification |

---

### GET `/scholiq/api/admin/keys`

**Auth required. Admin-only.**

Lists signing key metadata (key ID, created at, algorithm). Does not expose private key material.

**Response 200:**

```json
{
  "keys": [
    {
      "id": "tenant-key-v1",
      "algorithm": "RS256",
      "createdAt": "2026-01-01T00:00:00Z",
      "active": true
    }
  ]
}
```

---

### POST `/scholiq/api/admin/keys/generate`

**Auth required. Admin-only.**

Generates a new RSA key pair and stores it via `ICrypto`. Equivalent to `occ scholiq:keys:generate`.

**Request:** No body required.

**Response 200:**

```json
{
  "keyId": "tenant-key-v2",
  "algorithm": "RS256",
  "createdAt": "2026-05-12T09:00:00Z"
}
```

---

### GET/POST `/scholiq/api/settings`

**Auth required.**

User and admin settings endpoints backed by `SettingsController`.

`GET /scholiq/api/settings`, returns current user's Scholiq preferences.
`POST /scholiq/api/settings`, updates preferences.

**User preference keys:**

| Key | Type | Default | Description |
|---|---|---|---|
| `notify_assignments` | boolean | `true` | Receive enrolment activation/completion notifications |
| `notify_due_dates` | boolean | `true` | Receive T-30/T-7/T-1 due-date reminders |
| `default_view` | string | `learner` | Landing page after login |

---

## Newman collection

A Postman / Newman collection for the scholiq API is in development. See `openspec/changes/nextcloud-app/` for the collection scaffold. To run:

```bash
newman run openspec/changes/nextcloud-app/scholiq-api.postman_collection.json \
  --env-var "baseUrl=http://localhost:8080" \
  --env-var "ncUser=admin" \
  --env-var "ncPassword=admin"
```
