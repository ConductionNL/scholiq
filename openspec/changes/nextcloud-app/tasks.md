# Tasks — Nextcloud App Shell

> Scope: PHP bootstrap (Cmi5LaunchTokenService + AiFeatureDpoAckGuard), Tier-4 `CnAppRoot` manifest shell, `TenantSetting` + `UserSetting` OR schemas with seed data, `ScholiqSettings.vue` settings page, and i18n scaffold. All behaviour that can be expressed declaratively is declared via `x-openregister-lifecycle` in `lib/Settings/scholiq_register.json` — no `AuditTrail` service, no `AiFeatureRegistry`, no custom Vue Router code.
>
> Spec: `openspec/changes/nextcloud-app/specs/nextcloud-app/spec.md`

## Deduplication Check

- [ ] Confirm no overlap with existing OpenRegister services before building custom code: `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`. Verify `@conduction/nextcloud-vue` does not already provide a settings panel component that covers `ScholiqSettings.vue`. Document findings inline here: **expected result: no overlap — `CnIndexPage` and `CnSettingsSection` are reused; no new ObjectService or custom CRUD is built**.

---

## Phase 1: PHP bootstrap

- [ ] **task-1** Create `appinfo/info.xml` with hard `<dependency>` entries for `openregister` and `openconnector`, NC min-version 33 max-version 34, PHP 8.3/8.4. Run `nc-app-checker` to validate manifest syntax. Add `@spec openspec/changes/nextcloud-app/tasks.md#task-1` to file header comment.

- [ ] **task-2** Create `appinfo/routes.php` with the 10-route wedge table from design §1.3. Specific routes MUST precede any wildcard `{slug}` routes per ADR-003. Add `@spec openspec/changes/nextcloud-app/tasks.md#task-2`.

- [ ] **task-3** Create `lib/AppInfo/Application.php`: DI bindings ONLY for `Cmi5LaunchTokenService` and `AiFeatureDpoAckGuard`. Register `PageController`. **Do NOT** add bindings for `AuditTrail`, `AuditedController`, `AiFeatureRegistry`, `NotificationService`, `OpenRegisterGuard`, `AdminSettings`, or `PersonalSettings` — forbidden per ADR-022 + ADR-031. Add `@spec` tag.

- [ ] **task-4** Create `lib/Service/Cmi5LaunchTokenService.php`: `mintLaunchToken(string $learnerId, string $lessonId, string $registrationId): string` returning RS256 JWT; private key in `OCP\Security\ICrypto` under `scholiq.cmi5.launch.private`; `exp = now + 8h`. Add `@spec openspec/changes/nextcloud-app/tasks.md#task-4`. Unit test: mock `ICrypto`, assert JWT claims present + `exp` ≤ now + 8h.

- [ ] **task-5** Create `lib/Controller/PageController.php`: `index()` renders `templates/main.php`; `manifest()` returns bundled `src/manifest.json` as `JSONResponse`. No `scholiq_config.guard_failed` flag — dependency guard is manifest-driven. Add `@spec` tag. Unit test: `manifest()` returns 200 with `Content-Type: application/json`.

- [ ] **task-6** Create `lib/Lifecycle/AiFeatureDpoAckGuard.php`: `check(TransitionContext $ctx): GuardResult` reads `IAppConfig::getValueBool('scholiq', 'dpo_ack.' . $ctx->getObject()->propertyName, false)`; returns `Reject(...)` when false, `Allow()` when true. Add `@spec openspec/changes/nextcloud-app/tasks.md#task-6`. Unit test: mock `IAppConfig` returning false → asserts Reject; returning true → asserts Allow.

- [ ] **task-7** Create `templates/main.php` injecting the JS bundle via `\OCP\Util::addScript` + shared chunks; no `scholiq_config` data attributes in the DOM (use `IInitialState::provideInitialState` for any PHP → Vue data transfer per ADR-004).

---

## Phase 2: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] **task-8** Create `lib/Settings/scholiq_register.json` shell with the `TenantSetting` schema per design §2.1: required fields `propertyName`, `propertyValue`, `category`; `isSecret` boolean; `x-openregister-lifecycle` with transitions `disabled → enabled` and `enabled → disabled`; `x-openregister: { active: true, searchable: true }`. Mark with `x-openregister.type: "application"`.

- [ ] **task-9** Add `UserSetting` schema to `lib/Settings/scholiq_register.json` per design §2.2: required fields `userId`, `propertyName`, `propertyValue`, `category`; `x-openregister: { active: true, searchable: false }`. No lifecycle — user preferences have no approval/guard workflow.

- [ ] **task-10** Add seed data to `lib/Settings/scholiq_register.json` under `components.objects[]` per design §3: 5 `TenantSetting` objects and 5 `UserSetting` objects using the `@self` envelope format with Dutch-language values. Verify objects are idempotently re-imported by slug using `ObjectService::searchObjects`. **Do NOT** include real API keys, real URIs, or high-entropy placeholder values — use `YOUR_API_KEY_HERE` / nil UUID patterns per opsx safe-values rule.

- [ ] **task-11** Wire schema registration in a `IRepairStep` at install time: call `ConfigurationService::importFromApp('scholiq', $data, '0.1.0', false)`. Verify `force: false` skips re-import if version matches (idempotency). Add `@spec openspec/changes/nextcloud-app/tasks.md#task-11`.

---

## Phase 3: Frontend manifest (Tier-4 CnAppRoot)

- [ ] **task-12** Scaffold `package.json`: pin `@conduction/nextcloud-vue` to `^0.1.0-beta.1` (minimum version supporting `useAppManifest` + `CnAppRoot`); add `"check:manifest": "node node_modules/@conduction/nextcloud-vue/scripts/validate-manifest.js src/manifest.json"` per ADR-024 §5.

- [ ] **task-13** Create `webpack.config.js` per design §4.5: conditional vue alias + dedup aliases, `DefinePlugin` for `appName`/`appVersion`, `splitChunks: { chunks: 'all' }` with stable chunk filenames `scholiq-shared-nc-vue.js` and `scholiq-shared-vendor.js`. **MUST NOT** override `devtool` to `inline-source-map` per ADR-004.

- [ ] **task-14** Create `src/manifest.json` per design §4.1: full Tier-4 menu (7 items) + pages (6 pages) + `dependencies: ["openregister", "openconnector"]` + theme token references. Set `$schema` to the published `app-manifest.schema.json` URL. Run `npm run check:manifest`; fix any schema errors.

- [ ] **task-15** Create `src/main.js` per design §4.2: 15 lines; `import bundled from './manifest.json'`; `useAppManifest('scholiq', bundled)`; render `<CnAppRoot customComponents={{ ScholiqSettings }} />`. **Do NOT** create `src/router/index.js` — `CnAppRoot` derives routes from manifest (Tier 4 per ADR-024).

- [ ] **task-16** Create `src/views/ScholiqSettings.vue` per design §4.3: three sections (default-register picker, TenantSetting table, credential signing key widget). Use `inputLabel` prop on all `NcSelect` elements (never manual `<label>`). All modals/dialogs in their own `.vue` files under `src/modals/` or `src/dialogs/`. Every `await store.action()` in a `try/catch` with user-facing error feedback. No `window.confirm()` / `window.alert()`.

- [ ] **task-17** Add CI job `check:manifest` in `.github/workflows/` that runs `npm run check:manifest` on every push targeting `main` or a PR branch. Build MUST fail on schema errors.

---

## Phase 4: Seed data generation task

- [ ] **task-18** Verify seed data in `lib/Settings/scholiq_register.json` is loaded correctly: after fresh install, call `GET /api/openregister/scholiq/TenantSetting` and assert 5 objects returned; call `GET /api/openregister/scholiq/UserSetting` and assert 5 objects returned. Add integration test that re-runs `importFromApp` with `force: false` and asserts object count does not increase (idempotency).

---

## Phase 5: i18n

- [ ] **task-19** Create `l10n/en.json` and `l10n/nl.json` with keys for all manifest `label`/`title` values (7 menu labels + 6 page titles) and all `ScholiqSettings.vue` user-facing strings. Every Dutch key in `nl.json` MUST have an English counterpart in `en.json`. Run diff; assert zero missing keys.

- [ ] **task-20** Create `l10n/en.js` and `l10n/nl.js` (Vue-side wrappers) generated from the JSON files.

- [ ] **task-21** Add `i18n-ci` GitHub Actions step: `git diff --name-only l10n/en.json l10n/nl.json` key diff; fails CI on any key present in `en.json` but missing in `nl.json`.

---

## Phase 6: Quality gate

- [ ] **task-22** Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix all violations. Verify every PHP class/method introduced by this change carries `@spec openspec/changes/nextcloud-app/tasks.md#task-N` tags (ADR-003).

- [ ] **task-23** Run `npm run lint` (ESLint); fix all violations. Verify no hardcoded hex/rgba colour values in any `<style>` block — all colours via `var(--cn-X, var(--color-X, fallback))` pattern.

- [ ] **task-24** Run `npm run check:manifest`; MUST pass with zero schema errors.

- [ ] **task-25** Integration test: `GET /api/manifest` returns `Content-Type: application/json`, HTTP 200, and the response validates against `app-manifest.schema.json`. Confirms `PageController::manifest()` serves the bundled blob correctly (REQ-NA-004).

- [ ] **task-26** Integration test (PHPUnit + OR): install Scholiq with OpenRegister present → assert full UI renders; disable OpenRegister → assert `NcEmptyContent` dependency-missing state shown, no other Scholiq component rendered (REQ-NA-001-B).

- [ ] **task-27** Integration test: `AiFeatureDpoAckGuard` fires on `TenantSetting` lifecycle transition for `category: ai-feature` — attempt `enable` without DPO ack → assert OR rejects transition with 422; set DPO ack in `IAppConfig` → attempt again → assert transition succeeds and `security.config.changed` audit entry emitted by OR (REQ-NA-002-B).

- [ ] **task-28** Playwright smoke test: navigate to the app, assert `CnAppRoot` renders 5 menu items in correct order (Dashboard, Courses, Enrolments, Credentials, Compliance); navigate to `/#/courses` → assert Courses index renders; navigate to `/#/does-not-exist` → assert `NcEmptyContent` 404 state shown (REQ-NA-004 + REQ-NA-005).

- [ ] **task-29** Playwright accessibility check: run `axe-core` against the `Settings` page (served via `/#/settings`); assert zero critical violations (WCAG 2.1 AA). Confirm all `NcSelect` elements have visible labels via `inputLabel` prop (REQ-NA-006-C).

---

## Out of scope (covered in other changes)

- `AuditTrail` service / `AuditedController` / `MissingAuditTrailRule` — explicitly forbidden per ADR-022 + ADR-031.
- `AiFeatureRegistry` singleton — replaced by `AiFeature` schema seeds + OR lifecycle; declared in downstream AI-feature specs.
- `Course`, `Enrolment`, `Credential`, `LearnerProfile`, `XapiStatement`, `AiFeature` schemas — downstream changes add these to `scholiq_register.json`.
- Dashboard and Compliance page widgets — `dashboard` and `compliance-audit` changes extend `src/manifest.json`.
- Credential signing key rotation endpoint — `certification` change.
- LRS controller (`Cmi5LaunchController`, `LrsController`, `ScormController`) bodies — `course-management` change.
- UWLR / ROD / OSO OpenConnector adapter configuration — `data-exchange` change.
