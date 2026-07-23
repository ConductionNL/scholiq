---
kind: code
depends_on: []
---

# Proposal: controller-test-coverage-security-critical

## Why

Six of Scholiq's eight `lib/Controller/*.php` classes have **zero** dedicated PHPUnit test file — verified
by matching each controller basename against `tests/Unit/Controller/*Test.php` / `tests/unit/Controller/*Test.php`
at HEAD (2026-07-07): only `CredentialVerifyController` (`tests/Unit/Controller/CredentialVerifyControllerTest.php`)
and `SettingsController` (`tests/unit/Controller/SettingsControllerTest.php`) have one. The other six —
`ActionMatrixController`, `AuditPackExportController`, `ExternalTrainingController`, `KeyAdminController`,
`PageController`, `QtiImportController` — have none. `tests/Unit/Service/*Test.php` covers the *service*
layer these controllers call into (`RolloverServiceTest`, `ExternalTrainingServiceTest`, etc.), but the
service tests call the service directly — they never exercise the controller method itself, so the
auth-attribute wiring, request-parameter handling, and response-shape/status-code logic that live in the
controller are the "phantom green" gap: the pipeline reports the app tested, but this glue code is not.

This proposal scopes to the three controllers where an untested bug has the highest blast radius:

- **`ActionMatrixController`** (`lib/Controller/ActionMatrixController.php`, 174 lines) — the admin-only
  read/write API for the ADR-023 action-authorization matrix that every other controller in the app
  (`ActionAuthService::requireAction()`) consults to decide who may call what. Both endpoints rely solely
  on `#[AuthorizedAdminSetting]` at the middleware layer per the class's own docblock (lines 5-8) — there is
  no test proving that attribute is present, that a malformed matrix write is rejected, or that
  `SEED_PATH` (`lib/actions.seed.json`) fallback behaves correctly. Zero test coverage of the file that
  gates every other authorization decision in the app.
- **`KeyAdminController`** (`lib/Controller/KeyAdminController.php`, 257 lines) — admin-only RSA-2048
  keypair generation/rotation for Open Badges 3.0 credential signing (wraps `KeyManagementService`, per its
  own docblock lines 5-10, ADR-031). A key-rotation bug (e.g. rotating without invalidating
  previously-issued credential verification, or leaking the private key in a response) is
  security-critical and undetected by any controller-level test today.
- **`AuditPackExportController`** (`lib/Controller/AuditPackExportController.php`, 766 lines — the largest
  controller in the app) — streams the ADR-008 compliance audit-pack ZIP (audit trail, verwerkingsregister,
  external-training evidence) and is the controller the sibling `fix-cross-tenant-idor-planid-lookups`
  change (in this same `openspec/changes/`) cites as the *correct* tenant-scoping pattern
  (`export()` lines 33-55) that the other four controllers were missing. That reference implementation
  itself has no controller-level test asserting the tenant-scoping it models for the rest of the app —
  only its private helper `buildExternalTrainingCsv()` is indirectly exercised via
  `ExternalTrainingServiceTest`, not via a request through `export()`.

`ExternalTrainingController`, `PageController`, and `QtiImportController` are left out of scope (quality
over quantity per the sweep brief) — noted below as deferred, not silently dropped.

## What Changes

- Add `tests/Unit/Controller/ActionMatrixControllerTest.php` covering: matrix read returns the seeded
  defaults when no override is stored; matrix write persists and round-trips; a malformed write payload is
  rejected with 4xx and does not corrupt the stored matrix.
- Add `tests/Unit/Controller/KeyAdminControllerTest.php` covering: key generation produces a keypair without
  ever including the private key material in the JSON response; rotation invalidates the previous key
  status as reported by the status endpoint; both endpoints are reachable only through the controller
  (not asserting NC's own middleware, but asserting the controller body's own behaviour is correct given a
  mocked authorized caller).
- Add `tests/Unit/Controller/AuditPackExportControllerTest.php` covering: `export()` scopes the ZIP contents
  to the caller's own `tenant_id` (mirroring the assertion style used in the sibling
  `fix-cross-tenant-idor-planid-lookups` change's tests 1.3/1.4/2.3/2.4); a date range with no matching
  entries returns a ZIP with header-only CSVs rather than an error; `buildExternalTrainingCsv()` output is
  exercised via a real `export()` call, not only via the service-level test.
- No production code behavior changes are required by this proposal — it is test-only. BREAKING: none.

## Deferred (not in scope, tracked here to avoid re-discovery)

`ExternalTrainingController`, `PageController`, `QtiImportController` also have zero controller-level test
files; a follow-up change should cover them using the same pattern established here.
