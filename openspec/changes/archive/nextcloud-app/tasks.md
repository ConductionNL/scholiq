# Tasks — Nextcloud App Shell (manifest adoption per ADR-024)

> Scope: bring up the Scholiq Nextcloud app shell as a Tier-4 `CnAppRoot` consumer per [hydra ADR-024 §9](../../../../hydra/openspec/architecture/adr-024-app-manifest.md), consuming OR abstractions per [ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md), with all behaviour declared via schema metadata per [ADR-031](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md).

## Phase 1: PHP bootstrap (legitimate seams only)

- [ ] Create `appinfo/info.xml` with hard `<dependency>` entries for `openregister` and `openconnector`, NC min-version 33, PHP 8.3/8.4. Run `nc-app-checker` to validate manifest syntax.
- [ ] Create `appinfo/routes.php` with the wedge route table from design §1.2 (10 routes). No controller bodies in this change beyond the ones listed below.
- [ ] Create `lib/AppInfo/Application.php`: DI registrations for `Cmi5LaunchTokenService` and the lifecycle guards (`AiFeatureDpoAckGuard`, `CoursePublishGuard`); register the `Page`, `Cmi5Launch`, and `Lrs` controllers. **Do NOT** add bindings for `AuditTrail`, `AuditedController`, `AiFeatureRegistry`, `NotificationService`, `OpenRegisterGuard`, `AdminSettings`, `PersonalSettings`. These services are forbidden on net-new scholiq code per ADR-022 + ADR-031.
- [ ] Create `lib/Controller/PageController.php`: single `index()` action renders `templates/main.php`; `manifest()` action returns the bundled `src/manifest.json` as JSON (v0.1 — backend override hook deferred per design §2.5).
- [ ] Create `lib/Service/Cmi5LaunchTokenService.php`: `mintLaunchToken(learnerId, lessonId, registrationId)` returning RS256 JWT; key stored in `OCP\Security\ICrypto` under `scholiq.cmi5.launch.private`. Cryptographic operation, legitimate PHP per ADR-031 §"What apps SHOULD still write in PHP". Unit test mocks ICrypto, asserts token claims.
- [ ] Create `lib/Controller/Cmi5LaunchController.php`: `token($lessonId)` reads authenticated user via `IUserSession`, calls `Cmi5LaunchTokenService::mintLaunchToken`, returns JSON `{token, launchUrl}`. Auth + role guards inline (admin/instructor/learner enrolled in the lesson's course).
- [ ] Create `lib/Lifecycle/AiFeatureDpoAckGuard.php`: single `check(transitionContext)` method asserting the admin has acknowledged the DPO confirmation text in `IAppConfig` key `dpo_ack.<feature_slug>`. Legitimate PHP per ADR-031 §"PHP guards remain a legitimate seam". Unit test mocks `IAppConfig`.
- [ ] Create `lib/Settings/scholiq_register.json` shell — replace the placeholder `example` schema with the `AiFeature` schema (full per ARCHITECTURE.md §3.13: lifecycle disabled → enabled with `AiFeatureDpoAckGuard` precondition + `x-openregister-notifications` for transition audit). Empty seed array for v0.1. Downstream changes (course-management, enrolment, certification, compliance-audit, dashboard) extend this file.

## Phase 2: Frontend manifest (Tier-4 CnAppRoot)

- [ ] Scaffold `package.json`: pin `@conduction/nextcloud-vue` to a version supporting `useAppManifest` + `CnAppRoot`; add `"check:manifest": "node node_modules/@conduction/nextcloud-vue/scripts/validate-manifest.js src/manifest.json"` per ADR-024 §5.
- [ ] Configure `webpack.config.js` with conditional vue alias + dedup aliases for vue, pinia, @nextcloud/vue.
- [ ] Create `src/manifest.json` per design §2.1 — full Tier-4 menu + pages + dependencies + theme. `$schema` points at the published `app-manifest.schema.json` URL.
- [ ] Create `src/main.js` per design §2.2 — 15 lines, no app-local Vue Router code. Imports the bundled manifest, calls `useAppManifest('scholiq', bundled)`, renders `<CnAppRoot customComponents={ScholiqSettings} />`.
- [ ] Create `src/views/ScholiqSettings.vue` per design §3 — single settings page combining OpenRegister default-register picker + AiFeature read-only table + Credential signing key widget. Bound to the manifest's `Settings` custom page.
- [ ] Create `templates/main.php` injecting the JS bundle path; no `scholiq_config.guard_failed` flag (CnAppRoot resolves dependencies from manifest, not from PHP).
- [ ] **Do NOT** create `src/router/index.js` — `CnAppRoot` derives routes from `manifest.pages` per ADR-024 §8 (Tier 4).
- [ ] **Do NOT** create `src/components/OpenRegisterGuard.vue` — `CnAppRoot` renders the dependency-missing empty state automatically.

## Phase 3: Manifest validation

- [ ] Run `npm run check:manifest`; fix any schema errors. Add a CI job that runs `check:manifest` on every push.
- [ ] Add `tests/Integration/ManifestEndpointTest.php`: GET `/api/manifest` returns the bundled blob with `Content-Type: application/json` and validates against the schema.
- [ ] Add a Playwright smoke test: navigate to the app, assert `CnAppRoot` renders the 5 menu items in the correct order (Dashboard, Courses, Enrolments, Credentials, Compliance) — proves the manifest is the source of truth.

## Phase 4: i18n

- [ ] Create `l10n/en.json` and `l10n/nl.json` with translation keys for all manifest `label` / `title` values + `ScholiqSettings.vue` strings (per ADR-024 §6 — keys, not literal strings).
- [ ] Create `l10n/en.js` and `l10n/nl.js` for Vue consumption.
- [ ] Add `i18n-ci` GitHub Actions step that diffs en vs nl key sets and fails on missing keys.

## Phase 5: Quality gate

- [ ] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix all violations.
- [ ] Run `npm run lint` (ESLint); fix all violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Playwright accessibility check: run `axe-core` against the `Settings` page; assert zero critical violations (WCAG 2.1 AA — inherited from `CnAppRoot` + nextcloud-vue NL Design).

## Out of scope (covered elsewhere)

- Audit-trail substrate — does not exist; ADR-008 + ADR-022 say Scholiq consumes OR's audit trail. Every state-changing behaviour is declared via `x-openregister-lifecycle` / `-notifications` on the relevant schema in downstream change tasks.
- `AuditTrail` service / `AuditedController` base / `MissingAuditTrailRule` PHPStan rule — explicitly prohibited per ADR-022 + ADR-031.
- `AdminSettings.php` + `PersonalSettings.php` `OCP\Settings\ISettings` classes — replaced by the manifest's `Settings` custom page and the schema-driven `AiFeature` table.
- Audit event vocabulary enum (`AuditEventTypes::KNOWN`) — replaced by lifecycle / notification declarations on each schema; OR's vocabulary is canonical.
- Custom Vue Router code under `src/router/` — replaced by manifest-derived routing per ADR-024 Tier 4.
