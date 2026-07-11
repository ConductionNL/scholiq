## 1. ActionMatrixController

- [ ] 1.1 Create `tests/Unit/Controller/ActionMatrixControllerTest.php`. Mock `ActionAuthService` and
      `IGroupManager`; assert `getMatrix()` (`lib/Controller/ActionMatrixController.php:78`) returns the
      seeded defaults from `SEED_PATH` (`lib/actions.seed.json`) when no override is stored.
- [ ] 1.2 Assert `setMatrix()` (line 117) persists a valid matrix payload and a subsequent `getMatrix()`
      call round-trips it.
- [ ] 1.3 Assert `setMatrix()` rejects a malformed payload (missing action key / non-array value) with a
      4xx `JSONResponse` and leaves the previously-stored matrix unchanged.

## 2. KeyAdminController

- [ ] 2.1 Create `tests/Unit/Controller/KeyAdminControllerTest.php`. Mock `KeyManagementService`,
      `IAppConfig`, `IConfig`, `IUserSession`; assert `generateKey()`
      (`lib/Controller/KeyAdminController.php:103`) with no existing key returns 201 and a body that
      contains no private-key material (only the shape `KeyManagementService::generateTenantKeypair()`
      is documented to return).
- [ ] 2.2 Assert `generateKey()` on an existing key without `confirm=true` returns 400 and does NOT call
      `generateTenantKeypair()` (rotation-confirmation gate at line 126-133).
- [ ] 2.3 Assert `generateKey()` within the throttle window (line 136-141) returns 429 and does not
      rotate.
- [ ] 2.4 Assert `generateKey()` with a `tenantId` that does not match the caller's server-resolved bound
      tenant (line 114-121) returns 403 and does not call the key-management service.
- [ ] 2.5 Assert `keyStatus()` (line 175) returns `{configured: false}` shape when no key exists and the
      fingerprint/publicKey shape when one does, without ever including private-key material.

## 3. AuditPackExportController

- [ ] 3.1 Create `tests/Unit/Controller/AuditPackExportControllerTest.php`. Mock `IUserSession`, `IConfig`,
      `objectService`, `auditTrailMapper`; assert `export()`
      (`lib/Controller/AuditPackExportController.php`, per its own docblock lines 33-55) scopes every
      query it issues (`auditTrailMapper->findAll`, `objectService->findAll`) to the caller's resolved
      `tenant_id`, using the same assertion style as
      `openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md` tasks 1.3/1.4/2.3/2.4 (two tenants,
      assert only the caller's own tenant's data is present in the produced ZIP).
- [ ] 3.2 Assert a date range with no matching entries produces a ZIP containing header-only CSVs (not an
      error response) — covering the `empty($rows) === true` branch at line ~540.
- [ ] 3.3 Assert `buildExternalTrainingCsv()`'s output (line 519 signature) is exercised through a real
      `export()` call end-to-end, not only indirectly via `ExternalTrainingServiceTest`.

## 4. Traceability and quality gates

- [ ] 4.1 Add `@spec openspec/changes/controller-test-coverage-security-critical/tasks.md#task-N` docblock
      tags to the three new test files, matching the app's existing `@spec` convention.
- [ ] 4.2 Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) on the three new test files and fix any
      pre-existing warnings encountered in them (per CLAUDE.md).
- [ ] 4.3 Run `openspec validate controller-test-coverage-security-critical --strict` and resolve any
      errors.

## 5. Deferred (tracked, not implemented here)

- [ ] 5.1 File a follow-up GitHub issue for controller-level tests on `ExternalTrainingController`,
      `PageController`, `QtiImportController` (also zero-coverage at HEAD, per the proposal's evidence).
