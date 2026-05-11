# Tasks — Certification

## Phase 1: OpenRegister schema

- [ ] Create `openregister/schemas/scholiq-credential.json` with all fields including OB3 payload object, reminder_*_sent booleans, source enum, regulation_slug, verification_url, revoked + revocation_reason. Add indexes on (learner_id, tenant_id, revoked), (expires_at, revoked, tenant_id), (regulation_slug, tenant_id, revoked). Write unit test verifying schema accepts a valid OB3 payload object.

## Phase 2: Signing key setup

- [ ] Create `Scholiq\Service\KeyManagementService`: `generateTenantKeypair(tenantId)` generating RSA-2048 keypair; private key stored via `OCP\Security\ICrypto::encrypt()` under key `scholiq.credential.signing.<tenantId>`; public key stored in app config for verification. Add admin API endpoint `POST /api/credentials/admin/generate-key` (admin only). Unit test mocks ICrypto, asserts keypair stored.

## Phase 3: PHP services

- [ ] Create `Scholiq\Service\CredentialIssuanceService`: implement `buildOb3Payload()` building valid OB3 JSON-LD with correct @context, type, issuer, issuanceDate, credentialSubject (learner opaque UUID), achievement; implement `signPayload()` using openssl_sign + ICrypto private-key retrieval (RS256, compact JWS); implement `issueForEnrolment()` orchestrating payload build + sign + ObjectService::saveObject. Unit tests: assert OB3 context array, assert credentialSubject.id is UUID (not BSN), assert proof.jws is non-empty string.
- [ ] Create `Scholiq\Service\ExpiryDetectionService`: `getCredentialsExpiringSoon()` queries OpenRegister for credentials matching expires_at range + revoked=false; `markExpired()` updates credential status; `autoEnrolForRenewal()` delegates to BulkEnrolmentService for single-user auto-enrol. Unit tests: test threshold computation for T-90/T-60/T-30.
- [ ] Create `Scholiq\EventListener\EnrolmentCompletedCredentialListener`: subscribe to `scholiq.enrolment.completed` event; check course.certificate_template; call CredentialIssuanceService; emit 'credential.issued' audit event; dispatch 'credential_issued' notification. Integration test: trigger enrolment.completed event with templated course, assert Credential created within 30 seconds and notification dispatched.

## Phase 4: Background job

- [ ] Create `Scholiq\BackgroundJob\CredentialExpiryJob` extending TimedJob (interval 86400s): query expiring credentials per tenant; dispatch T-90/T-60/T-30 notifications with idempotency guard (reminder_*_sent booleans); call autoEnrolForRenewal at T-30 if renewal_course_id set; mark expired when expires_at <= today. Register job in Application.php. Integration test: seed Credential with expires_at=T-30, run job, assert reminder_30_sent=true and renewal Enrolment created.

## Phase 5: PHP controller

- [ ] Create `Scholiq\Controllers\CredentialController` extending `AuditedController`: list (with filters learner_id, course_id, revoked, expires_before, regulation_slug), show (authenticated), manual issue POST (admin/hr only, emit 'credential.issued'), revoke PATCH (admin/hr only, emit 'credential.revoked'), public verify GET (bypass session auth, return {valid, issued_at, expires_at, issuer_name} only — no personal data). Integration test: full lifecycle (issue → verify → revoke → verify shows invalid).

## Phase 6: Audit event types

- [ ] Add `credential.expiry.reminder.sent`, `credential.expired` to `AuditEventTypes::KNOWN`. PHPStan build must pass.

## Phase 7: Vue frontend

- [ ] Add route entries to `src/router/index.js` for /credentials, /credentials/:id, /credentials/:id/verify.
- [ ] Create `src/stores/credentialStore.js` using `createObjectStore('/api/credentials')`. Vitest tests.
- [ ] Create `src/views/CredentialListView.vue` using CnDataTable; columns: learner, course, kind, issued_at, expires_at with colour coding (red < 30d, amber < 90d, green otherwise), status badge. Filters: regulation_slug, revoked. NcEmptyContent for zero results.
- [ ] Create `src/views/CredentialDetailView.vue` CnDetailPage + CnObjectSidebar; OB3 payload rendered as formatted JSON in Details tab; Audit Trail tab; Revoke action (admin/hr only) with reason capture dialog.
- [ ] Create `src/views/CredentialVerifyView.vue` (public route, no auth guard): show verification result card with issuer name, achievement, valid/invalid status, QR code linking to the verify URL. Playwright test: navigate to /credentials/:id/verify without auth session, assert valid result renders.
- [ ] Add signing-key status widget to AdminSettings.vue: show key present/missing state; "Generate Key" button calls POST /api/credentials/admin/generate-key. Playwright test: generate key, assert status changes to "Key present".

## Phase 8: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Playwright integration test: full round-trip — enrol learner → simulate course completion → assert Credential auto-issued → navigate to verify URL → assert {valid:true} response.
