---
status: proposed
---

# Scholiq AppHost Adoption

## Purpose

Scholiq's observability and app-plumbing run on the OpenRegister AppHost: a standard public health endpoint and admin metrics endpoint driven by manifest descriptors (replacing the non-standard admin-gated custom-JSON health endpoint with its fake OR check and hardcoded placeholders), and the boilerplate controllers/services/repair-steps served by the AppHost generics with endpoint-level parity.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## ADDED Requirements

### Requirement: Standard Public Health Endpoint

Scholiq SHALL serve `GET /apps/scholiq/api/health` publicly (no authentication, ADR-006) through the AppHost GenericHealthController, executing the manifest descriptors `database`, `orAvailable`, and `appEnabled(launchpad, severity: degraded)`, in the standard `{status, app, version, checks}` shape under `statusCodePolicy: adr006`.

#### Scenario: Healthy instance

- **GIVEN** a running instance with the database reachable, OpenRegister enabled and resolvable, and LaunchPad enabled
- **WHEN** `GET /apps/scholiq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "ok"` and `checks.database = "ok"`, `checks.or = "ok"`, `checks.launchpad = "ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: LaunchPad missing degrades but does not error

- **GIVEN** a running instance with LaunchPad not enabled
- **WHEN** `GET /apps/scholiq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "degraded"` and `checks.launchpad` reporting failure, because the check carries `severity: degraded`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: OpenRegister unavailable is a real failure

- **GIVEN** a running instance with OpenRegister disabled
- **WHEN** `GET /apps/scholiq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status = "error"` and `checks.or` reporting failure — unlike the legacy file-based `openregister_connected` check, which reported `true` whenever the packaged register JSON existed regardless of OR's actual state
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Admin Metrics Endpoint

Scholiq SHALL serve `GET /apps/scholiq/api/metrics` admin-only through the AppHost GenericMetricsController in Prometheus text exposition format 0.0.4, emitting the implicit `scholiq_info` and `scholiq_up` metrics plus three additive `objectCount` gauges declared in the manifest `observability.metrics` block: `scholiq_courses_total`, `scholiq_enrolments_total`, and `scholiq_learner_profiles_total` (over the `course`, `enrolment`, and `learner-profile` register slugs). Scholiq had no metrics endpoint before; these gauges replace the deleted health endpoint's hardcoded placeholder counters with live values.

#### Scenario: Admin scrape

- **GIVEN** a running instance
- **WHEN** `GET /apps/scholiq/api/metrics` is called by an admin
- **THEN** the response MUST be Prometheus text containing `scholiq_info`, `scholiq_up 1`, and the three `scholiq_*_total` gauges, each with `# HELP` / `# TYPE` lines
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin rejected

- **GIVEN** a running instance
- **WHEN** `GET /apps/scholiq/api/metrics` is called by a non-admin user or anonymously
- **THEN** the request MUST be rejected (not HTTP 200 with metrics)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Legacy Admin Health Endpoint Removed

Scholiq SHALL delete `lib/Controller/HealthController.php` and the `GET /api/admin/health` route. The legacy custom JSON shape — including the file-existence-based `openregister_connected`/`schemas_registered` fields and the hardcoded placeholder fields `audit_trail_events_24h` (`0`) and `last_audit_pack_export` (`null`) — SHALL NOT be served by any endpoint. Real audit-pack metrics are deferred to a future `provider` or `objectCount` descriptor once the audit-pack feature records events; no `$extra` admin status endpoint is kept because the consumer inventory (2026-06-12, re-verified in task 0.2) found no frontend consumer of the legacy shape.

#### Scenario: Legacy shape gone

- **GIVEN** the adopted app
- **WHEN** `GET /apps/scholiq/api/admin/health` is requested as an admin
- **THEN** the response MUST NOT contain any of the legacy fields `openregister_connected`, `schemas_registered`, `audit_trail_events_24h`, `launchpad_installed`, `last_audit_pack_export` (the route no longer exists; the SPA catch-all or a 404 answers instead)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Served by AppHost Generics with Parity

Scholiq SHALL serve its per-user preferences, action authorization, repair-step initialisation, admin settings panel, admin settings section, and deep-link registration through the AppHost generic classes (via `Bootstrap::register()` aliases and one-line subclass stubs where NC requires app-namespace classes), preserving today's route names (`page#index`, `page#catchAll`, `settings#*`, `preferences#*`), URLs, response shapes, and preference key namespace. Two boilerplate units SHALL remain physical and bespoke: the SPA `PageController` (it supplies role-aware dashboard initial state — `primaryRole`/`dashboardRole`/`dashboardRoles` — the generic dashboard controller does not), and `SettingsController`/`SettingsService` (the register-import path calls OpenRegister `ConfigurationService::importFromApp(appId, data, version, force)`, a signature the generic settings service does not drive; these are re-pointed at the bespoke classes after `Bootstrap::register()`). The bespoke routes array is kept rather than `Routes::standard($extra)` so these two units stay wired. Domain controllers (credentialVerify, keyAdmin, auditPackExport, qtiImport, actionMatrix) and the ADR-031 domain event listeners SHALL remain untouched, and the `AdminSettings` class name SHALL keep resolving for their `#[AuthorizedAdminSetting(AdminSettings::class)]` gates.

#### Scenario: SPA shell and deep links still render

- **GIVEN** a logged-in user
- **WHEN** they open `/apps/scholiq/` and a deep link such as `/apps/scholiq/admin/health`
- **THEN** the Vue SPA MUST render the requested manifest page exactly as before adoption

#### Scenario: Preferences round-trip unchanged

- **GIVEN** a logged-in user with a preference previously written by the deleted local controller (stored as `pref_<key>`)
- **WHEN** they `GET /apps/scholiq/api/preferences/{key}` and `PUT` a new value
- **THEN** the stored value MUST be returned and updated in the same `{value}` shape and key namespace as before adoption
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Settings endpoints parity

- **GIVEN** an admin
- **WHEN** they call `GET /api/settings`, `POST /api/settings`, and `POST /api/settings/load`
- **THEN** the responses MUST match the pre-adoption shapes (config keys incl. `register`, `openregisters`, `isAdmin`; load re-imports `scholiq_register.json`), still gated by `#[AuthorizedAdminSetting]`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
