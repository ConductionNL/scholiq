## Why

Scholiq requires a standards-compliant Nextcloud app shell before any other capability can ship: the PHP bootstrap, Vue Router, NL Design tokens, OpenRegister dependency check, and settings dialogs are shared infrastructure that all 5 downstream specs (`course-management`, `enrolment`, `certification`, `compliance-audit`, `dashboard`) depend on. Without this foundation landing first, every other spec has nowhere to register routes, emit audit events, or surface settings.

## What Changes

- Add `appinfo/info.xml` declaring hard dependencies on `openregister` and `openconnector`, minimum NC Hub 33, PHP 8.3/8.4.
- Add `lib/AppInfo/Application.php` registering services: `AuditTrail`, `AiFeatureRegistry`, `NotificationService`, route definitions, event-dispatcher listeners.
- Add `NcAdminSettings` panel (`AdminSettingsController` + `admin-settings.vue`) covering: OpenRegister register selection, AI Act high-risk feature toggles, notification defaults.
- Add `NcUserSettings` panel (`UserSettingsController` + `user-settings.vue`) covering: default view, items per page, default sort, notification preferences.
- Add Vue 2 + Vue Router (hash mode) SPA entry point: `src/main.js`, `src/router/index.js`, `src/App.vue`.
- Add `NcEmptyContent` guard component that renders when OpenRegister is absent; blocks all other routes.
- Wire `@conduction/nextcloud-vue ^0.1.0-beta.1` with the conditional webpack alias + dedup aliases (per `webpack-vue.md`).
- Add `Scholiq\Service\AuditTrail` and `Scholiq\Bootstrap\AuditEventTypes` as foundational services per ADR-008.
- Add `Scholiq\Service\AiFeatureRegistry` skeleton (empty in v0.1) per ADR-005.
- Add `OCP\AppFramework\Http\TemplateResponse` main template `templates/main.php` that boots the SPA.
- Add `l10n/nl.js` and `l10n/en.js` stubs; `OCP\IL10N` wiring for NL + EN.
- Add `OCP\Activity\IManager` bridge in base controller for audit-trail tab data.
- Add `OCP\BackgroundJob\TimedJob` registrations (stubs; actual logic in downstream specs).

## Capabilities

### New Capabilities

- `nextcloud-app`: Nextcloud app shell — PHP bootstrap, Vue Router SPA, OpenRegister dependency check, NcAdminSettings, NcUserSettings, AuditTrail service, AiFeatureRegistry skeleton, NL Design theming.

### Modified Capabilities

(none — this is the first change; no existing specs are modified)

## Impact

- **appinfo/info.xml**: defines the install-time dependency contract; if OpenRegister or OpenConnector are absent, NC will refuse to enable the app.
- **lib/AppInfo/Application.php**: the DI container wiring file; all downstream specs extend the services registered here.
- **AuditTrail service**: every downstream spec calls `AuditTrail::record()`; this spec must land first so downstream PRs can depend on it.
- **AiFeatureRegistry**: Phase 3+ AI features (proctoring, adaptive learning) depend on this skeleton being in place per ADR-005.
- **Vue Router**: all frontend routes from all 5 downstream specs are declared in `src/router/index.js`; a well-structured router scaffold here prevents route conflicts later.
- **@conduction/nextcloud-vue**: version pin + webpack alias must be consistent across all specs; set here, inherited by all others.
- **i18n**: `l10n/` directory structure must exist before any downstream spec adds translation keys.
