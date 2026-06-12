# Tasks: Scholiq Adopts OpenRegister AppHost

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl` the legacy `GET /apps/scholiq/api/admin/health` JSON (as admin), plus settings index, preferences round-trip, SPA page, manifest endpoint responses; store as fixtures for parity diff
- [ ] 0.2 Re-run the health-route consumer search across `src/` (`grep -rn "admin/health" src/` + fetch/axios/generateUrl sweep): confirm the 2026-06-12 finding that NO frontend code fetches `/api/admin/health` (ScholiqAdminHealth.vue renders a placeholder + KpiSchemasWidget via OR object API only). If a consumer has appeared since, STOP and re-adjudicate per proposal (thin `$extra` admin status route vs. migrating the consumer to the standard shape)

## 1. Manifest observability block

- [ ] 1.1 Add `observability` to `src/manifest.json`: `health.checks` = `{type:"database"}` + `{type:"orAvailable"}` + `{type:"appEnabled","app":"launchpad","severity":"degraded"}`; `statusCodePolicy: "adr006"`; NO metrics descriptors (implicit `scholiq_info`/`scholiq_up` only)
- [ ] 1.2 Add the `deepLinks` block to the manifest (patterns currently hardcoded in `DeepLinkRegistrationListener`)
- [ ] 1.3 Validate via ManifestService diagnostics — no errors

## 2. Wiring and deletions

- [ ] 2.1 `Application.php`: add `AppHost\Bootstrap::register($context, self::APP_ID, $options)`; remove boilerplate registrations; KEEP the 8 ADR-031 domain event listeners and the `IMcpToolProvider::scholiq` alias
- [ ] 2.2 `appinfo/routes.php`: `return \OCA\OpenRegister\AppHost\Routes::standard($extra)` with `$extra` = `page#manifest`, credentialVerify, keyAdmin ×2, auditPackExport, qtiImport, actionMatrix ×2; preserve scholiq's `page#index`/`page#catchAll`/`settings#*`/`preferences#*` route names exactly (info.xml navigation + frontend `generateUrl` calls depend on them); catch-all stays LAST
- [ ] 2.3 Delete `lib/Controller/HealthController.php` and the `/api/admin/health` route entry — the two hardcoded placeholder fields (`audit_trail_events_24h`, `last_audit_pack_export`) and the obsolete ADR-031 exception comment go with it
- [ ] 2.4 Delete `PageController.php`, `PreferencesController.php`, `SettingsController.php`, `SettingsService.php`, `ActionAuthService.php`, `DeepLinkRegistrationListener.php`; alias their app-namespace class names to the AppHost generics (manifest endpoint: thin stub only if `Routes::standard()`/generics don't cover it)
- [ ] 2.5 Shrink `Repair/InitializeSettings.php`, `Repair/InitializeActions.php`, `Settings/AdminSettings.php`, `Sections/SettingsSection.php` to one-line subclass stubs extending the generics (info.xml `<repair-steps>`/`<settings>` and the `#[AuthorizedAdminSetting(AdminSettings::class)]` references in KeyAdmin/ActionMatrix/AuditPackExport controllers require app-namespace classes)
- [ ] 2.6 Sweep remaining references (unit tests, `@spec` tags, docblocks) to the deleted classes

## 3. Verification

- [ ] 3.1 OR AppHost Newman contract collection green against scholiq: `GET /api/health` public, standard `{status, app, version, checks}` shape, 200 healthy / degraded on launchpad-disabled / 503 on OR-unavailable; `GET /api/metrics` admin-only, Prometheus text with `scholiq_info` + `scholiq_up`
- [ ] 3.2 Confirm the legacy `/api/admin/health` no longer serves the custom JSON shape (no `openregister_connected`/`schemas_registered`/placeholder fields anywhere in the response)
- [ ] 3.3 Parity diff vs 0.1 fixtures for the surviving boilerplate endpoints: settings index/create/load, preferences get/set (existing `pref_*` keys still resolve), SPA page + deep-link catch-all, manifest endpoint — byte-/shape-compatible
- [ ] 3.4 Existing scholiq e2e suite green (incl. `pages.spec.ts` AdminHealth page render) + unit tests green

## 4. Docs

- [ ] 4.1 Update `docs/Technical/api.md`: replace the `GET /scholiq/api/admin/health` section with the standard public `/api/health` + admin `/api/metrics` contract
- [ ] 4.2 Update `docs/Technical/architecture.md`: drop the HealthController row, describe AppHost adoption (manifest descriptors, generic controllers, remaining stubs); note that real audit-pack metrics land later as a `provider`/`objectCount` descriptor once the audit-pack feature records events

## 5. Quality gates

- [ ] 5.1 `composer check:strict` green; 18 hydra gates green (stub-scan now clean by deletion, route-auth/route-reachability re-checked after the routes.php rewrite); gate-22 manifest validation green on the new `observability` + `deepLinks` blocks; `@spec` tags updated
