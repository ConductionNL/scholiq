# Tasks — Certification & Digital Credentials

> Scope: five JSON schema patches on `lib/Settings/scholiq_register.json` (`CertificateTemplate`, `Certificate`, `CredentialIssuance`, `RenewalRule`, `ContentVersion`) with full lifecycle / calculations / notifications / relations declarations. Five PHP files for cryptography + issuance handler + delta-enrolment handler + public verify endpoint + key admin endpoint (all ADR-031 legitimate exceptions). One Vue component for public verify page. Seed data for all five schemas.

---

## Phase 0: Deduplication check

- [ ] Search `openspec/specs/` and `openregister/lib/Service/` for any existing `Certificate`, `Credential`, `CertificateTemplate`, `RenewalRule`, or `ContentVersion` schemas and services that overlap with this change. Document findings (or "no overlap found") in a comment on this task before proceeding. Confirmed: the archived `openspec/changes/archive/certification/` artifacts used a `Credential` schema (not `Certificate`); the new entity names (`Certificate`, `CertificateTemplate`, `CredentialIssuance`, `RenewalRule`, `ContentVersion`) are distinct from any currently-active schemas in `lib/Settings/scholiq_register.json`.

---

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add `CertificateTemplate` schema per design §1.1 — lifecycle (`draft → active → archived`), relation to `Course`, fields: `name`, `kind`, `issuerName`, `issuerDid`, `courseId`, `validityPeriodMonths`, `backgroundImagePath`, `badgeImagePath`, `renewalCourseSlug`, `deltaCourseSlug`, `edciEnabled`, `bolognaSupplement`, `tenant_id`. Reference: decidesk schemas for lifecycle declarations.
- [ ] Add `Certificate` schema per design §1.2 — lifecycle (`issued → revoked | expired`), calculations (`daysUntilExpiry`, `isExpiringIn90Days`, `isExpiringIn60Days`, `isExpiringIn30Days`, `isExpired`), notifications (`issuedToLearner`, `expiryT90`, `expiryT60`, `expiryT30`, `expired` with `alsoDispatchLifecycle: expire`), relations (`learner`, `course`, `template`), widgets (`expiryRiskGrid`).
- [ ] Add `CredentialIssuance` schema per design §1.3 — `appendOnly: true`, relations to `Certificate` and `CertificateTemplate`, fields: `certificateId`, `learnerId`, `courseId`, `templateId`, `issuedVia`, `ob3SignatureRef`, `edciSignatureRef`, `enrolmentId`, `issuedByUserId`.
- [ ] Add `RenewalRule` schema per design §1.4 — lifecycle (`active ↔ inactive`), relation to `CertificateTemplate`, fields: `templateId`, `triggerType`, `expiryThresholdDays`, `renewalCourseSlug`, `deltaCourseSlug`, `autoEnrol`, `notifyLearner`, `notifyManager`, `notifyComplianceOfficer`.
- [ ] Add `ContentVersion` schema per design §1.5 — lifecycle (`draft → published → archived`), notification `deltaEnrolOnPublish` (triggers `DeltaEnrolmentHandler` side-effect, notifies compliance-officer), relation to `Course`, fields: `courseId`, `version`, `changedAt`, `deltaScope`, `affectsCredentialKinds`.
- [ ] Add seed objects for all five schemas per design §10: 4 × `CertificateTemplate`, 4 × `Certificate`, 3 × `CredentialIssuance`, 3 × `RenewalRule`, 3 × `ContentVersion`. Use Dutch values, fictional but realistic (real Dutch municipality names, valid UUIDs, correct `did:web:` URIs).
- [ ] Write a JSON-validation test that asserts each of the five schemas parses against OR's schema-extension contract (lifecycle field present, required fields valid, enum values correct).

---

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Service/KeyManagementService.php`: `generateTenantKeypair(string $tenantId): array` — calls `openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])`, encrypts private key via `OCP\Security\ICrypto::encrypt()`, stores under app-config key `scholiq.certificate.signing.<tenantId>`; stores public key PEM + SHA-256 fingerprint plain in app-config for verification. Legitimate per ADR-031 — cryptographic operation. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`. Unit test: mock `ICrypto`, assert private key encrypted + public key + fingerprint stored; assert non-admin call path not reachable from this service.
- [ ] Create `lib/Service/CertificateSigningService.php`:
  - `buildOb3Payload(string $certId, string $learnerId, string $courseId, \DateTimeImmutable $issuedAt, ?\DateTimeImmutable $expiresAt, array $templateData): array` — returns OB3 JSON-LD array per design §5.
  - `buildEdciPayload(string $certId, array $templateData, bool $bolognaSupplement): array` — returns EDCI ELM JSON with `type: EuropassCredential`, `title`, optional `diplomaSupplement` flag.
  - `signPayload(array $payload, string $tenantId): string` — reads private key via `ICrypto::decrypt()`, calls `openssl_sign` on canonicalised JSON (JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES), returns RS256 compact JWS.
  - Legitimate per ADR-031 — cryptographic + document-generation exception. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`.
  - Unit tests: assert OB3 `@context` array contains both required URIs; `credentialSubject.id` starts with `urn:scholiq:learner:` (not BSN); `proof.jws` non-empty after sign; EDCI payload includes `diplomaSupplement: true` when flag set.
- [ ] Create `lib/Lifecycle/CertificateIssuanceHandler.php`: registered as OR audit-event listener for `openregister.audit.enrolment.completed`. Reads Enrolment → Course via OR relations (`ObjectService::getRelated`); reads `Course.certificateTemplateId`; if template present and `lifecycle=active`: calls `CertificateSigningService::buildOb3Payload` + `signPayload` (+ `buildEdciPayload` if `edciEnabled`), then `ObjectService::saveObject('Certificate', [..., 'lifecycle' => 'issued'])` and `ObjectService::saveObject('CredentialIssuance', ['issuedVia' => 'auto', ...])`. No orchestration service. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`. Integration test: dispatch `openregister.audit.enrolment.completed` event on a course with active template → assert `Certificate` created within 30 s + `issuedToLearner` notification dispatched + `CredentialIssuance` created.
- [ ] Create `lib/Lifecycle/DeltaEnrolmentHandler.php`: side-effect handler called by `ContentVersion.x-openregister-notifications.deltaEnrolOnPublish`. Queries `Certificate` WHERE `courseId=X AND lifecycle=issued AND kind IN affectsCredentialKinds`; for each, reads `RenewalRule` WHERE `templateId=Y AND triggerType=content-version AND lifecycle=active AND autoEnrol=true`; calls OR batch-enrol endpoint for `RenewalRule.deltaCourseSlug` with `source=system, mandatory=true`. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`. Integration test: publish a `ContentVersion` with two active certificate holders → assert two delta-module `Enrolment` objects created; revoked/expired certificate holders NOT enrolled (REQ-CERT-005-B).
- [ ] Create `lib/Controller/CertificateVerifyController.php`: `@NoCSRFRequired` + `@PublicPage`; `GET /api/certificates/{id}/verify`; reads `Certificate` via `ObjectService::getObject`; returns `JsonResponse({valid: lifecycle==='issued' && !isExpired, issuedAt, expiresAt, issuerName, qrData})` — no personal data; writes `credential.verified` audit entry via OR's audit-trail API directly. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`. Integration test: GET without session → assert 200 + correct payload + audit entry; GET on revoked cert → assert `{valid: false, revocationReason}`.
- [ ] Create `lib/Controller/KeyAdminController.php`: `@NoCSRFRequired` + `@AdminRequired`; `POST /api/certificates/admin/generate-key`; delegates to `KeyManagementService::generateTenantKeypair($tenantId)`; returns 201 + public key fingerprint. PHPDoc: `@spec openspec/changes/certification/tasks.md#phase-2`. Integration test: admin POST → 201 + keypair stored; non-admin POST → 403.
- [ ] Register `CertificateIssuanceHandler` in `Application.php` via OR's audit-event listener API (`$dispatcher->addListener('openregister.audit.enrolment.completed', ...)`).
- [ ] Register `DeltaEnrolmentHandler` in `Application.php` as the side-effect handler referenced by `ContentVersion.notifications.deltaEnrolOnPublish.sideEffect`.
- [ ] Append new routes to `appinfo/routes.php`:
  - `GET /api/certificates/{id}/verify` → `CertificateVerifyController::verify`
  - `POST /api/certificates/admin/generate-key` → `KeyAdminController::generateKey`
  - Ensure specific routes are declared BEFORE any wildcard `{slug}` routes per ADR-003.

---

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with pages per design §6.1: `CertificateTemplateList` (index), `CertificateTemplateDetail` (detail), `CertificateList` (index), `CertificateDetail` (detail with `auditTrail` tab), `CertificateVerify` (custom, `public: true`), `RenewalRuleList` (index), `ContentVersionList` (index). Re-run `npm run check:manifest` — must pass.
- [ ] Create `src/views/CertificateVerify.vue`: fetches `GET /api/certificates/:id/verify` (public endpoint), renders NL Design System verification card with: issuer name, achievement name, valid/invalid badge, issued date, expiry date (or "geen vervaldatum"), and QR code (using `qrcode` or NL DS QR component) linking to the same URL. Register via `customComponents` on `CnAppRoot`. Playwright test: navigate to `/certificates/<uuid>/verify` without auth → assert valid result renders, issuerName visible, QR code present.
- [ ] Add a signing-key status widget to `ScholiqSettings.vue` (nextcloud-app change): reads `GET /api/certificates/admin/key-status` (add this endpoint to `KeyAdminController`), shows "Sleutel aanwezig (fingerprint: ...)" / "Niet geconfigureerd", offers "Genereer sleutel" button calling `POST /api/certificates/admin/generate-key`. Playwright test: generate key → assert status flips to "Sleutel aanwezig".
- [ ] **Do NOT** create `src/router/index.js` entries, `src/stores/certificateStore.js`, `src/views/CertificateListView.vue`, or `src/views/CertificateDetailView.vue` — `CnAppRoot` built-in renderers cover these.

---

## Phase 4: Audit-event vocabulary — none

- [ ] **Do NOT** add `credential.expiry.reminder.sent`, `credential.expired`, or `content-version.published` to a Scholiq-side `AuditEventTypes::KNOWN` enum or constant class. OR's lifecycle and notification engines emit these event types automatically via the schema declarations. Verify: grep the codebase for `AuditEventTypes` after this change — must not contain certification event types.

---

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all static analysis violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass with all new pages registered.
- [ ] Integration test (PHPUnit + OR): seed a `Certificate` with `expiresAt = today + 90`, trigger OR's calculation-refresh tick → assert `isExpiringIn90Days=true` AND `expiryT90` notification dispatched exactly once (idempotency re-trigger → no second dispatch).
- [ ] Integration test (PHPUnit + OR): seed a `Certificate` with `expiresAt = today - 1`, trigger OR's calculation-refresh tick → assert `isExpired=true` AND `expired` notification dispatched AND `Certificate.lifecycle` transitions to `expired` (via `alsoDispatchLifecycle: expire`) AND `credential.expired` audit event emitted.
- [ ] Integration test (PHPUnit + OR): publish a `ContentVersion` for course X where 3 learners hold `lifecycle=issued` Certificates AND 1 holds a `lifecycle=revoked` Certificate → assert 3 delta-module Enrolments created, 0 for the revoked holder (REQ-CERT-005-B).
- [ ] Playwright integration test: full round-trip — enrol learner in a course with active `CertificateTemplate` → simulate course completion via xAPI (`cmi5.completed`) → assert `Certificate` auto-issued within 30 s (poll OR API) → navigate to `/certificates/<id>/verify` without auth → assert `{valid: true}` response renders with QR code. Covers REQ-CERT-001-A + REQ-CERT-002-B.
- [ ] Playwright integration test: admin generates signing key via settings page → assert key status widget shows fingerprint → issue a Certificate → assert `openbadges3Payload.proof.jws` non-empty. Covers REQ-CERT-010-A + REQ-CERT-002-A.
