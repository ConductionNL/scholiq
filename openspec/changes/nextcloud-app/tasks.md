# Tasks — Nextcloud App Shell

## Phase 1: OpenRegister schema

- [ ] Create `openregister/schemas/scholiq-audit-event.json` with `append_only: true` and all 17 fields; add indexes on (tenant_id, event_type, created_at), (subject_id, created_at), (actor_id, created_at), (correlation_id). Verify that OpenRegister rejects UPDATE attempts on append_only schemas.

## Phase 2: PHP bootstrap

- [ ] Create `appinfo/info.xml` with hard `<dependency>` entries for `openregister` and `openconnector`, NC min-version 33, PHP 8.3/8.4. Run `nc-app-checker` to validate manifest syntax.
- [ ] Create `lib/AppInfo/Application.php`: DI registrations for `AuditTrail`, `AiFeatureRegistry`, `NotificationService`, `OpenRegisterGuard`; route declarations for page, admin-settings, user-settings, ai-feature-dossier endpoints.
- [ ] Create `lib/Bootstrap/AuditEventTypes.php` with the full controlled vocabulary (26 event types covering enrolment, credential, attestation, compliance, AI Act, course, security, xAPI, settings). Add `assertKnown()` static method that throws `\InvalidArgumentException` on unknown types.
- [ ] Create `lib/Bootstrap/AiFeatures.php` with zero `register()` calls (empty in v0.1).
- [ ] Create `Scholiq\Service\AuditTrail` implementing `AuditTrailInterface`; wire `ObjectService` injection for OpenRegister persistence; write unit test asserting that a `record()` call with unknown event_type throws `\InvalidArgumentException`.
- [ ] Create `Scholiq\Service\AiFeatureRegistry` with `all()` and `register()` methods; unit test confirms `all()` returns empty array in v0.1 bootstrap.
- [ ] Create `Scholiq\Service\OpenRegisterGuard` using `IAppManager::isInstalled('openregister')`; unit test mocks IAppManager for true/false cases.
- [ ] Create `Scholiq\Controllers\AuditedController` base class with the `afterController` lifecycle hook; write PHPStan custom rule `MissingAuditTrailRule` using `phpstan/phpstan-src` extension API.
- [ ] Create `lib/Settings/AdminSettings.php` implementing `OCP\Settings\ISettings`; expose admin settings fields per design §4.1; controller endpoint `POST /api/settings/admin` persists via `IAppConfig`; audit event `settings.admin.saved`; integration test covers all 5 admin config keys.
- [ ] Create `lib/Settings/PersonalSettings.php` implementing `OCP\Settings\ISettings`; expose personal settings fields per design §4.2; controller endpoint `POST /api/settings/user` persists via `IConfig::setUserValue`; audit event `settings.user.saved`; unit test for each key.

## Phase 3: Vue SPA

- [ ] Scaffold `package.json` pinning `@conduction/nextcloud-vue ^0.1.0-beta.1`; configure `webpack.config.js` with conditional vue alias + dedup aliases for vue, pinia, @nextcloud/vue.
- [ ] Create `src/main.js` (Vue 3-compatible with Vue 2.7 Options API): register Vue Router, Pinia, `@nextcloud/l10n`; mount to `#content`; read `window.scholiq_config.guard_failed` to branch to guard vs router view.
- [ ] Create `src/router/index.js` with `createWebHashHistory`, base routes (/ redirect to /dashboard), stub routes for downstream specs (lazy-loaded, comment-marked); write Playwright smoke test: navigate to `/#/` and assert redirect to `/#/dashboard`.
- [ ] Create `src/components/OpenRegisterGuard.vue` using `NcEmptyContent`; show "Install OpenRegister" action button conditionally when `isAdmin` is true; write Playwright test: navigate to app with guard_failed=true, assert NcEmptyContent renders and action button is visible for admin.
- [ ] Create `templates/main.php` injecting `scholiq_config` JSON into `window`; includes built JS bundle; unit test for PHP `PageController::index()` confirms it calls `OpenRegisterGuard::isInstalled()`.
- [ ] Create `src/views/AdminSettings.vue` micro-SPA for admin settings panel; binds to `POST /api/settings/admin`; add `templates/admin-settings.php` bootstrap; Playwright test: toggle cmi5_enabled, save, reload, assert persisted.
- [ ] Create `src/views/PersonalSettings.vue` for personal settings panel; binds to `POST /api/settings/user`; Playwright test: set items_per_page to 50, save, reload, assert 50.

## Phase 4: i18n

- [ ] Create `l10n/en.json` and `l10n/nl.json` with all UI strings from admin settings, personal settings, guard component, empty states; create `l10n/en.js` and `l10n/nl.js` for Vue. Add `i18n-ci` GitHub Actions step that diffs en vs nl key sets and fails on missing keys.

## Phase 5: Quality gate

- [ ] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan including custom rule); fix all violations before marking tasks complete.
- [ ] Add `tests/Unit/AuditTrailEnforcementTest.php`: asserts that the PHPStan rule fires on a synthetic controller missing `AuditTrail::record()`.
- [ ] Add `tests/Integration/AppBootstrapTest.php`: boots the full DI container in a test NC instance, asserts all services resolve without error.
- [ ] Run `npm run lint` (ESLint) on all Vue files; fix all violations.
- [ ] Playwright accessibility check: run `axe-core` against the admin settings page and personal settings page; assert zero critical violations (WCAG 2.1 AA).
