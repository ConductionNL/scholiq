---
kind: code
---

## Why

Scholiq requires a standards-compliant Nextcloud app shell before any other capability can ship. The PHP bootstrap, `CnAppRoot` manifest shell, OpenRegister dependency guard, admin settings, user settings, and NL Design token wiring are shared infrastructure that every downstream change (`course-management`, `enrolment`, `certification`, `compliance-audit`, `dashboard`) depends on. Without this foundation landing first, there is nowhere to register routes, declare schemas, or surface tenant-level and user-level configuration.

This change supersedes the archived `nextcloud-app` change (2026-05-11). It aligns with the current ADR landscape: ADR-022 (apps consume OR abstractions — no `AuditTrail` service, no `AiFeatureRegistry` singleton), ADR-024 (app manifest — Tier-4 `CnAppRoot`, no `src/router/index.js`), and ADR-031 (schema-declarative business logic — settings state machines live in `x-openregister-lifecycle`, not in `AdminSettings.php`). The v1 artifacts proposed `AuditTrail`, `AuditedController`, and `AiFeatureRegistry` PHP services that are now explicitly forbidden on net-new Scholiq code; this spec does not ship them.

## What Changes

- Create `appinfo/info.xml` declaring hard `<dependency>` entries for `openregister` and `openconnector`, NC min-version 33, PHP 8.3/8.4. Nextcloud blocks Scholiq activation if either dependency is absent — no app-local guard needed.
- Create `appinfo/routes.php` with the wedge route table: page index, manifest endpoint, LRS statements (GET + POST), SCORM launch + API, cmi5 launch token, credential verify, audit-pack export, AI-feature dossier (10 routes total).
- Create `lib/AppInfo/Application.php` — DI bindings for `Cmi5LaunchTokenService` and lifecycle guards (`AiFeatureDpoAckGuard`) only. No `AuditTrail`, `AiFeatureRegistry`, `NotificationService`, `AdminSettings`, or `PersonalSettings` bindings — all replaced by OR abstractions per ADR-022 + ADR-031.
- Create `lib/Settings/scholiq_register.json` shell — introduces `TenantSetting` and `UserSetting` schemas with `x-openregister-lifecycle` and seed data. Downstream changes extend this file.
- Create `lib/Service/Cmi5LaunchTokenService.php` — RS256 JWT minting for cmi5 AU launches (cryptographic operation, legitimate PHP per ADR-031).
- Create `lib/Controller/PageController.php` — renders `templates/main.php` and exposes `GET /api/manifest` returning the bundled manifest blob (v0.1 — override hook deferred).
- Create `lib/Lifecycle/AiFeatureDpoAckGuard.php` — lifecycle guard asserting DPO acknowledgement before any AI feature transitions from `disabled` to `enabled` (ADR-005).
- Create `src/manifest.json` — Tier-4 `CnAppRoot` manifest: menu (Dashboard, Courses, Enrolments, Credentials, Compliance, Documentation, Settings), pages, `dependencies: ["openregister", "openconnector"]`, theme token references.
- Create `src/main.js` — 15 lines; `useAppManifest('scholiq', bundled)` + `CnAppRoot` with `customComponents: { ScholiqSettings }`. No `src/router/index.js` — `CnAppRoot` derives routes from `manifest.pages`.
- Create `src/views/ScholiqSettings.vue` — single custom settings page combining: default-register picker (`IAppConfig` key `default_register`), `TenantSetting` read/edit table (sourced from OR), credential-signing key widget.
- Create `webpack.config.js` — conditional vue alias, dedup aliases for `vue`/`pinia`/`@nextcloud/vue`, `DefinePlugin` for `appName`/`appVersion`, `splitChunks: { chunks: 'all' }` per ADR-004.
- Create `templates/main.php` — SPA bootstrap injecting the JS bundle; no `scholiq_config.guard_failed` flag.
- Create `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js` — all manifest `label`/`title` keys + `ScholiqSettings.vue` strings.
- Add `npm run check:manifest` script to `package.json` per ADR-024 §5.

## Capabilities

### New Capabilities

- `nextcloud-app`: Nextcloud app shell — PHP bootstrap (`Cmi5LaunchTokenService` + `AiFeatureDpoAckGuard`), Tier-4 `CnAppRoot` manifest shell, manifest-driven OpenRegister dependency guard, admin settings + user settings (via manifest `Settings` custom page), `TenantSetting` and `UserSetting` OR schemas, NL Design double-fallback CSS theming.

### Modified Capabilities

*(none — this is the foundational change; no existing Scholiq specs are modified)*

## Impact

- **`appinfo/info.xml`**: Nextcloud blocks Scholiq activation if `openregister` or `openconnector` are absent. `CnAppRoot` reads `manifest.dependencies` and renders the install-CTA `NcEmptyContent` empty state automatically when a dependency is missing at runtime — no `OpenRegisterGuard.php`.
- **`lib/Settings/scholiq_register.json`**: All downstream specs extend this file. Schema shapes landed here are BREAKING to change; additions are non-breaking. The `TenantSetting` lifecycle and `UserSetting` schema become the canonical admin/user preference store for all Scholiq capabilities.
- **`src/manifest.json`**: Single source of truth for all Scholiq navigation. Downstream specs declare their pages here; `CnAppRoot` derives hash-mode Vue Router entries from `manifest.pages`. No `src/router/index.js`.
- **`Cmi5LaunchTokenService`**: Every lesson launch in every downstream spec calls this service. Must land first.
- **`AiFeatureDpoAckGuard`**: Required before any AI feature spec can register an `AiFeature` schema seed (v0.1 has empty seed array per ADR-005).
- **i18n**: The `l10n/` directory structure must exist before any downstream spec adds translation keys. The `i18n-ci` check enforces parity between `en.json` and `nl.json`.
