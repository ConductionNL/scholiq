# Tasks — Certification

> Scope: one JSON patch on `lib/Settings/scholiq_register.json` adding the `Credential` schema with full lifecycle / calculations / notifications / relations. Three PHP files for cryptography + auto-issuance handler + public verify endpoint (all ADR-031 legitimate exceptions).

## Phase 1: Schema patch on `lib/Settings/scholiq_register.json`

- [ ] Add `Credential` schema per design §1.1 — lifecycle (`issued → revoked | expired`), relations (`learner` + `course`), calculations (`daysUntilExpiry`, `isExpiringIn90Days`, `isExpiringIn30Days`, `isExpired`), notifications (`issuedToLearner`, `expiryT90`, `expiryT30`, `expired` with `alsoDispatchLifecycle: expire`). Reference: decidesk schemas for lifecycle + calculation declarations.
- [ ] Write a JSON-validation test that asserts the schema parses against OR's schema-extension contract.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Service/KeyManagementService.php`: `generateTenantKeypair(tenantId)` generating RSA-2048 keypair via `openssl_pkey_new`; encrypts the private key via `ICrypto::encrypt` and stores under app-config key `scholiq.credential.signing.<tenantId>`; stores public key + fingerprint plain in app config for verification. Legitimate per ADR-031 — cryptographic operation. Unit test: mock `ICrypto`, assert keypair stored.
- [ ] Create `lib/Service/CredentialSigningService.php`: `buildOb3Payload(credentialId, learnerId, courseId, issuedAt, expiresAt)` returns the OB3 JSON-LD array per design §4; `signPayload(payload, tenantId)` returns RS256 compact JWS by calling `openssl_sign` on the canonicalised payload. Legitimate per ADR-031 — cryptographic + document-generation exception. Unit tests: assert OB3 `@context` array, `credentialSubject.id` is opaque UUID (not BSN), `proof.jws` is non-empty.
- [ ] Create `lib/Lifecycle/CredentialIssuanceHandler.php`: registered as an OR audit-event listener for `openregister.audit.enrolment.completed`. Reads the Enrolment's Course via OR relations; if `course.certificateTemplate` is set, calls `CredentialSigningService::buildOb3Payload` + `signPayload`, then `ObjectService::saveObject('Credential', $payload)` with `lifecycle=issued`. Integration test: trigger `enrolment.completed` event on a templated course → assert Credential created within 30s + `issuedToLearner` notification dispatched.
- [ ] Create `lib/Controller/CredentialVerifyController.php`: public `GET /api/credentials/{id}/verify` action; skip session middleware via `@NoCSRFRequired` + `@PublicPage` attributes; reads the Credential via OR; returns `{valid: lifecycle === 'issued' && !isExpired, issuedAt, expiresAt, issuerName}` — no personal data. Integration test: hit the URL with no session, assert correct payload + audit-trail entry `credential.verified` is written.
- [ ] Create `lib/Controller/KeyAdminController.php`: `POST /api/credentials/admin/generate-key` admin-only endpoint, delegates to `KeyManagementService::generateTenantKeypair`. Integration test: admin POST → assert keypair stored + non-admin POST → assert 403.
- [ ] Register `CredentialIssuanceHandler` in `Application.php` via OR's audit-event listener API.
- [ ] Append the new routes to `appinfo/routes.php`.

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with `CredentialDetail` page (type=detail, register=scholiq schema=Credential) and `CredentialVerify` page (type=custom, component=CredentialVerify, `public: true`). Re-run `npm run check:manifest`.
- [ ] Create `src/views/CredentialVerify.vue`: fetches `GET /api/credentials/:id/verify`, renders verification card with issuer name, achievement name, valid/invalid badge, QR code linking to the same URL. Register via `customComponents` on `CnAppRoot`. Playwright test: navigate to `/credentials/<uuid>/verify` without auth → assert valid result renders.
- [ ] Add a signing-key status widget to `ScholiqSettings.vue` (declared in the nextcloud-app change): reads `GET /api/credentials/admin/key-status`, shows "Key present" / "Not configured", offers "Generate key" button that calls `POST /api/credentials/admin/generate-key`. Playwright test: generate key, assert status flips.
- [ ] **Do NOT** create `src/router/index.js` entries, `src/stores/credentialStore.js`, or `src/views/CredentialListView.vue` / `CredentialDetailView.vue` — `CnAppRoot` built-in renderers cover them.

## Phase 4: Audit-event vocabulary — none

- [ ] **Do NOT** add `credential.expiry.reminder.sent` / `credential.expired` to a Scholiq-side `AuditEventTypes::KNOWN`. OR's lifecycle + notification engines emit these event types automatically.

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Integration test (PHPUnit + OR): seed a Credential with `expiresAt = today + 30`, trigger OR's calculation-refresh tick, assert `isExpiringIn30Days` becomes true AND the `expiryT30` notification dispatches exactly once.
- [ ] Integration test (PHPUnit + OR): seed a Credential with `expiresAt = today - 1`, trigger OR's calculation-refresh tick, assert `isExpired` becomes true AND the `expired` notification dispatches AND the schema-declared `expire` lifecycle transition fires (Credential.lifecycle becomes `expired`).
- [ ] Playwright integration test: full round-trip — enrol learner in a templated Course → simulate course completion via xAPI → assert Credential auto-issued within 30s → navigate to verify URL without auth → assert `{valid: true}` response with QR code.
