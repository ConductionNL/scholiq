# Scholiq, Admin Guide

This guide covers installation, register bootstrapping, signing key configuration, and troubleshooting for Nextcloud administrators.

---

## Requirements

| Dependency | Minimum version |
|---|---|
| Nextcloud | 28 (Hub 28) |
| PHP | 8.1 |
| OpenRegister | latest |
| OpenConnector | latest |

Both OpenRegister and OpenConnector must be installed and enabled before enabling scholiq.

---

## Installation

### 1. Enable the app

```bash
docker exec nextcloud php occ app:enable scholiq
```

Or via the Nextcloud App Store: search for **Scholiq**, click **Download and enable**.

### 2. Register the scholiq register (manual workaround)

Auto-bootstrap of the register on `app:enable` is blocked by openregister#1487, tracked in scholiq#35. Until that is resolved, you must import the register manually after every fresh install.

**Option A, OCC command:**

```bash
docker exec nextcloud php occ openregister:register:import \
  /var/www/html/custom_apps/scholiq/lib/Settings/scholiq_register.json
```

**Option B, OpenRegister admin UI:**

1. Open Nextcloud as admin.
2. Go to **OpenRegister** > **Registers** > **Import**.
3. Upload `lib/Settings/scholiq_register.json` from the scholiq app directory.
4. Confirm the import. All 9 schemas (Course, Lesson, XapiStatement, Enrolment, Regulation, Attestation, Credential, LearnerProfile, AiFeature) are created.

### 3. Verify the schemas loaded

Open **OpenRegister** > **Schemas** and confirm you see all 9 scholiq schemas listed.

If any are missing, re-run the import. If the import fails, check the OpenRegister version, scholiq requires `openregister ^v0.2.10`.

---

## Configure tenant signing keys

Scholiq uses RSA key pairs for two purposes:

- **Credential signing**, `CredentialSigningService` RS256-signs Open Badges 3.0 assertions.
- **Attestation HMAC**, `AttestationSigningGuard` uses the tenant key for HMAC-SHA256.

Keys are stored via Nextcloud's `ICrypto` interface (encrypted at rest).

### Generate a key pair via OCC

```bash
docker exec nextcloud php occ scholiq:keys:generate
```

This generates a new RSA-2048 key pair and stores it in `IAppConfig` under the `scholiq` namespace with key ID `tenant-key-v1`.

### Generate a key pair via the admin panel

1. Go to Nextcloud **Settings** > **Scholiq** (admin section).
2. Under **Signing Keys**, click **Generate new key pair**.
3. The key ID is displayed. Copy it for audit records.

### Rotate keys

Key rotation does not invalidate existing signatures, each signed object stores the `signingKeyId` used at signing time.

```bash
docker exec nextcloud php occ scholiq:keys:generate --rotate
```

Or use the **Rotate key** action in the admin panel. The old key is retained for verification; only new signatures use the new key.

---

## Per-Regulation RAG thresholds

Each Regulation can have its own coverage thresholds. Defaults at creation time:

- `ragAmberThreshold`: 90 (% below which status is amber)
- `ragRedThreshold`: 70 (% below which status is red)

To change thresholds for a specific Regulation:

1. Open **Compliance** > **Regulations** > select the regulation.
2. Edit `ragAmberThreshold` and `ragRedThreshold` fields.
3. Save. `coveragePercent` and `ragStatus` recalculate immediately.

The `officerAlertOnCoverageDrop` notification fires when `ragStatus` transitions to `red`. If thresholds are too strict, lower `ragRedThreshold` to reduce alert noise.

---

## Public credential-verify endpoint

Credentials have a public (unauthenticated) verification endpoint served by `CredentialVerifyController`:

```
GET /index.php/apps/scholiq/credentials/{id}/verify
```

This returns:
- Whether the credential exists and is in `issued` state
- The Open Badges 3.0 JSON-LD assertion
- The expiry status
- Revocation status

The `verificationUrl` field on each Credential object contains the canonical public URL. This URL is embedded in the OB3 assertion payload and can be shared externally.

No Nextcloud login is required to access this endpoint.

---

## Troubleshooting

### Schema not loading after install

**Symptom:** Courses / Enrolments pages show "schema not found" or empty lists.

**Cause:** The scholiq register was not imported (see openregister#1487).

**Fix:**
```bash
docker exec nextcloud php occ openregister:register:import \
  /var/www/html/custom_apps/scholiq/lib/Settings/scholiq_register.json
```

### Notifications not firing

**Symptom:** Learners do not receive due-date reminders or completion notifications.

**Check 1, NC cron:** Nextcloud's background job runner must be active. Check:
```bash
docker exec nextcloud php occ background-job:run --list
```

**Check 2, OR notification worker:** OpenRegister's notification dispatch depends on its own cron hook. Verify OR's background jobs are not stalled:
```bash
docker exec nextcloud php occ background-job:run OCA\\OpenRegister\\BackgroundJob\\NotificationJob
```

**Check 3, User preferences:** Notifications are gated by user preference keys (`notify_assignments`, `notify_due_dates`). Check that these are not disabled in the user's Scholiq settings.

### Attestation signing fails

**Symptom:** Clicking **Sign** on an attestation returns an error.

**Common causes:**

1. **No matching xAPI completion statement.** The `AttestationSigningGuard` requires a `cmi5.completed` XapiStatement for the learner + lesson. Check that the Lesson Player reported completion:
   ```bash
   # Query OR for xAPI statements for the learner and lesson
   curl -u admin:admin "http://localhost:8080/index.php/apps/openregister/api/objects?register=scholiq&schema=xapi-statement&actor.name=<userId>&lessonId=<lessonId>"
   ```

2. **Signing key not configured.** Run `occ scholiq:keys:generate` if no key exists.

3. **OR version mismatch.** `AttestationSigningGuard` requires `openregister ^v0.2.10`. Check version:
   ```bash
   docker exec nextcloud php occ app:list | grep openregister
   ```

### Signature verification fails on credential verify

**Symptom:** The public verify endpoint returns `invalid_signature`.

**Cause:** The tenant's RSA key was rotated or re-generated without preserving the old key. Each Credential stores the `signingKeyId`; OR's `ICrypto` must still hold the corresponding private key.

**Fix:** Do not delete old keys. Use `--rotate` (not `--regenerate`) when rotating. If keys are lost, credentials must be re-issued manually.

### App UI is blank after enabling

The `js/` build output is not committed. Run the frontend build:

```bash
cd /var/www/html/custom_apps/scholiq
npm install && npm run build
```

Then reload Nextcloud.

### "App directory name mismatch" when cloning from source

Nextcloud requires the app directory name to match `<id>scholiq</id>` in `appinfo/info.xml`. If the repo was cloned as `nextcloud-scholiq`:

```bash
make dev-link   # creates apps-extra/scholiq -> nextcloud-scholiq symlink
```

Then re-enable the app.
