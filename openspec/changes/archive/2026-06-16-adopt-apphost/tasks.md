# Tasks: Scholiq Adopts OpenRegister AppHost

## 0. Baseline

- [x] 0.1 Captured the parity baseline of the legacy `GET /api/admin/health` shape (`openregister_connected`, `schemas_registered`, `audit_trail_events_24h`, `launchpad_installed`, `last_audit_pack_export`) from the deleted controller for the parity diff (see proposal + §3)
- [x] 0.2 Re-ran the health-route consumer search across `src/` — NO frontend code fetches `/api/admin/health`; the AdminHealth Vue page renders OR object-API widgets only, so re-pointing health to the public AppHost endpoint has no frontend consumer to migrate

## 1. Manifest observability block

- [x] 1.1 Added `observability` to `src/manifest.json`: `health.checks` = `{type:"database",severity:"critical"}` + `{type:"orAvailable",severity:"critical"}` + `{type:"appEnabled","app":"launchpad",severity:"degraded"}`; `statusCodePolicy:"adr006"`. **Deviation from original plan:** per the finish-task directive, ADDED three admin-only `objectCount` metrics (`scholiq_courses_total`, `scholiq_enrolments_total`, `scholiq_learner_profiles_total` over the `course`/`enrolment`/`learner-profile` slugs) — additive, scholiq had no metrics before
- [x] 1.2 Added the `deepLinks` block to the manifest (course / enrolment / learner-profile / credential URL templates) — replaces the hardcoded patterns in the deleted `DeepLinkRegistrationListener`
- [x] 1.3 Manifest types validated against the OR engine (`ObservabilityManifest`/`ManifestLoader`/`MetricDescriptor`): all check + metric kinds and the `register`/`schema` source fields are accepted; the slugs exist in `scholiq_register.json`

## 2. Wiring and deletions

- [x] 2.1 `Application.php`: added `Bootstrap::register($context, self::APP_ID, ['namespace'=>'OCA\\Scholiq','sectionName'=>'Scholiq','mcpProvider'=>ScholiqToolProvider::class])`; removed the bespoke deep-link listener registration and the hand-written MCP alias (now handled by Bootstrap); KEPT the ADR-031 domain event listeners
- [x] 2.2 `appinfo/routes.php`: re-pointed `health#index`→`/api/health` and added `metrics#index`→`/api/metrics` (engine generics); kept Scholiq's bespoke route names for `page#*`/`settings#*`/`preferences#*` so info.xml + `generateUrl` are unchanged. **Deviation:** did NOT switch the SPA shell to `Routes::standard()` — `page#index`/`page#catchAll` stay on the bespoke `PageController` because it provides role-aware dashboard initial state (`primaryRole`/`dashboardRole`/`dashboardRoles`) the generic dashboard controller does not (the role-aware-dashboards domain we keep)
- [x] 2.3 Deleted `lib/Controller/HealthController.php` and the `/api/admin/health` route entry — the placeholder fields and the obsolete ADR-031 exception comment went with it
- [x] 2.4 Deleted `PreferencesController.php` and `DeepLinkRegistrationListener.php` (now AppHost generics / manifest-driven). **Deviation:** KEPT `PageController`, `SettingsController`, `SettingsService`, `ActionAuthService` as physical classes — `PageController` for role-aware state (above); `SettingsService`/`SettingsController` re-pointed at the bespoke implementations after `Bootstrap::register()` because the bespoke register-import path calls OR `ConfigurationService::importFromApp(appId, data, version, force)`, a signature the generic settings service does not drive (aliasing would break `/api/settings/load` + the InitializeSettings repair step); `ActionAuthService` reduced to a one-line subclass of `GenericActionAuthService` (5 domain controllers type-hint the class name)
- [x] 2.5 Shrank `Repair/InitializeSettings.php`, `Repair/InitializeActions.php`, `Settings/AdminSettings.php`, `Sections/SettingsSection.php` to one-line subclass stubs extending the AppHost generics (`info.xml` `<repair-steps>`/`<settings>` and `#[AuthorizedAdminSetting(AdminSettings::class)]` references require the app-namespace class names)
- [x] 2.6 Swept references — no unit test, `@spec` tag, or docblock references the deleted `HealthController`/`PreferencesController`/`DeepLinkRegistrationListener`; the bespoke `SettingsControllerTest` still applies (SettingsController kept)

## 3. Verification

- [x] 3.1 Verified the generic controller contract by inspection against the OR `development` engine: `GenericHealthController::index()` is `#[PublicPage]` returning the standard `{status, app, version, checks}` shape under `adr006` policy; `GenericMetricsController::index()` is admin-only Prometheus text. Engine-driven from the manifest blocks
- [x] 3.2 Legacy `/api/admin/health` route deleted — the bespoke JSON shape (`openregister_connected`/`schemas_registered`/placeholder fields) is no longer served
- [x] 3.3 Parity result: the substantive health signals are preserved as declarative checks (`orAvailable` replaces the fake file-existence `openregister_connected`; `appEnabled:launchpad` replaces `launchpad_installed`); the two placeholder fields (`audit_trail_events_24h: 0`, `last_audit_pack_export: null`) are replaced by real `objectCount` metric gauges. Documented improvement: health is now public (ADR-006 K8s/uptime probe) + metrics endpoint is new. Settings/preferences round-trips preserved (bespoke SettingsController kept)
- [x] 3.4 PHPUnit green (86 passed, 3 skipped) standalone; `npm run build` green

## 4. Docs

- [x] 4.1 Updated `docs/Technical/api.md`: replaced the `GET /scholiq/api/admin/health` section with the public `/api/health` + admin `/api/metrics` contract; updated the API-surface intro
- [x] 4.2 Updated `docs/Technical/architecture.md`: dropped the HealthController row, added the AppHost adoption note (manifest descriptors, generic controllers, remaining stubs, real audit-pack metrics as a future descriptor); updated the directory tree + manifest section. Also touched `docs/Technical/specs.md`

## 5. Quality gates

- [x] 5.1 22/23 hydra gates green (stub-scan clean by deletion; route-auth + route-reachability green after the AppHost wiring — gate-5 made AppHost-aware in hydra to recognise engine-owned canonical routes). gate-22 manifest-validation FAILS only on schema lag: the published `@conduction/nextcloud-vue` manifest schema v2.7.0 does not yet declare the `observability` + `deepLinks` top-level keys (valid AppHost extensions consumed by the OR engine) — fix belongs in the nextcloud-vue schema repo
