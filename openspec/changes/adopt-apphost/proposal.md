---
kind: code
---

# Proposal: Scholiq Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Scholiq carries the fleet's **most non-standard health endpoint** plus ~1,400 lines of drifted boilerplate that the AppHost (`apphost-observability-engine` + `apphost-boilerplate-controllers`) now provides centrally.

**Health is non-standard on every axis** (2026-06-12 fleet catalogue):

- **Wrong auth posture**: `GET /api/admin/health` is gated by `#[AuthorizedAdminSetting(AdminSettings::class)]`; ADR-006 requires a public health endpoint for K8s probes and uptime monitors.
- **Custom JSON shape**, always HTTP 200: `{openregister_connected, schemas_registered, audit_trail_events_24h, launchpad_installed, last_audit_pack_export}` instead of the fleet `{status, app, version, checks}` shape.
- **The OR check is fake**: `openregister_connected` and `schemas_registered` come from loading the *packaged* `lib/Settings/scholiq_register.json` file and counting `components.schemas`. A file-existence check only proves the app package shipped intact — it says nothing about whether OpenRegister is installed, enabled, or responding. It is effectively always `true` on any installed instance, healthy or not.
- **Two hardcoded placeholders are stub debt**: `audit_trail_events_24h` is a literal `0` and `last_audit_pack_export` a literal `null`, with "placeholder until OR provides instrumentation" comments. The stub-scan gate would flag these today.
- The controller carries a documented ADR-031 "external-system contract / observability" exception comment justifying the bespoke endpoint. With the AppHost engine, the justification is obsolete: every read it defends (IAppManager, OR availability) is now a declarative descriptor.

**Metrics**: scholiq has no metrics endpoint at all — an ADR-006 gap.

**Boilerplate**: PageController (SPA + catch-all), PreferencesController, SettingsController, SettingsService, ActionAuthService, InitializeSettings/InitializeActions repair steps, AdminSettings + SettingsSection, DeepLinkRegistrationListener, and the routes/Application plumbing are all near-verbatim copies of the petstore skeleton.

## Proposed Change

### Observability normalisation

Add an `observability` block to `src/manifest.json` and serve the standard endpoints through the AppHost generic controllers:

- **Health** (`GET /api/health`, public, ADR-006 `statusCodePolicy: adr006`):
  - `{"type": "database"}`
  - `{"type": "orAvailable"}` — a *real* OR check (resolves OR's ObjectService at runtime), replacing the file-based `openregister_connected`/`schemas_registered` theatre. Call-out: this is strictly stronger; the old check could report green with OR disabled.
  - `{"type": "appEnabled", "app": "launchpad", "severity": "degraded"}` — replaces `launchpad_installed` (a missing LaunchPad degrades, never errors).
- **Dropped outright**: `audit_trail_events_24h` and `last_audit_pack_export`. They never carried data (hardcoded `0`/`null`). Real audit-pack observability belongs in a future `provider` or `objectCount` metric descriptor once the audit-pack feature actually records events — tracked as a note in the spec, not faked in the response.
- **Metrics** (`GET /api/metrics`, admin, Prometheus text): no descriptors declared — scholiq gets the implicit `scholiq_info` / `scholiq_up` for free, closing the no-metrics gap with zero code.
- **Delete** `lib/Controller/HealthController.php` and the `/api/admin/health` route. The ADR-031 exception comment dies with it.

### UI-consumer adjudication (investigated 2026-06-12)

The custom JSON shape has **no frontend consumer**. `src/views/ScholiqAdminHealth.vue` (the AdminHealth manifest page at `/admin/health`) renders a static placeholder `<div>` for its `health-stats` widget and a `KpiSchemasWidget` that counts `AiFeature` objects through the OR object API via `KpiCard` — neither fetches `/api/admin/health`. `tests/e2e/pages.spec.ts` only navigates the *page* route. The sole references are documentation (`docs/Technical/api.md`, `docs/Technical/architecture.md`).

**Adjudication**: delete the endpoint with no `$extra` admin-status replacement — there is nothing to keep compatible with. The AdminHealth page's placeholder widget MAY later be pointed at the new public `/api/health` (standard shape) as a follow-up; it is not part of this change. Docs are updated to describe the standard endpoints. Task 0.2 re-verifies the consumer search at implementation time as a guard.

### Boilerplate adoption

Wire `AppHost\Bootstrap::register()` + `Routes::standard($extra)` and delete/stub the local copies with endpoint-level parity (URLs, route names, response shapes, `pref_`-namespaced preference keys unchanged):

| Local file (lines) | Disposition |
|---|---|
| `lib/Controller/HealthController.php` (122) | **Delete** (observability engine; route removed) |
| `lib/Controller/PageController.php` (115) | **Delete**; alias `page#index`/`page#catchAll` to `GenericDashboardController`. Scholiq's route names are `page#*` (not `dashboard#*`) — the alias/`$extra` wiring MUST preserve them so info.xml navigation keeps working. The `page#manifest` ADR-024 endpoint stays as an `$extra` route (thin stub or generic if AppHost provides one — adjudicate in tasks) |
| `lib/Controller/PreferencesController.php` (156) | **Delete**; alias to `GenericPreferencesController` (existing `pref_*` user keys keep resolving) |
| `lib/Controller/SettingsController.php` (105) | **Delete**; alias to `GenericSettingsController` |
| `lib/Service/SettingsService.php` (208) | **Delete**; alias to `AppHostSettingsService` (config key set: `register`) |
| `lib/Service/ActionAuthService.php` (259) | **Delete**; alias to `GenericActionAuthService` (actions.seed.json unchanged) |
| `lib/Repair/InitializeSettings.php` (106) / `InitializeActions.php` (144) | Shrink to one-line subclass stubs extending the generics (info.xml `<repair-steps>` requires app-namespace classes; repair-step-not-migration constraint preserved) |
| `lib/Settings/AdminSettings.php` (114) + `lib/Sections/SettingsSection.php` (88) | Shrink to subclass stubs of `GenericAdminSettings`/`GenericSettingsSection`. The `AdminSettings::class` reference MUST keep existing — scholiq's domain controllers (KeyAdminController, ActionMatrixController, AuditPackExportController) use `#[AuthorizedAdminSetting(AdminSettings::class)]` |
| `lib/Listener/DeepLinkRegistrationListener.php` (64) | **Delete**; patterns move to the manifest `deepLinks` block, `GenericDeepLinkRegistrationListener` registered by Bootstrap |
| `appinfo/routes.php` | `Routes::standard($extra)` with `$extra` = manifest, credentialVerify, keyAdmin ×2, auditPackExport, qtiImport, actionMatrix ×2 |
| `lib/AppInfo/Application.php` (183) | Boilerplate registrations replaced by `Bootstrap::register()`; the 8 ADR-031 domain event listeners and the MCP provider alias **stay** — scholiq does not become a shell app |

Domain controllers/services (CredentialVerify, KeyAdmin, AuditPackExport, QtiImport, ActionMatrix, the lifecycle handlers and crypto services) are untouched.

## Impact

- **Deleted**: ~1,030 lines of boilerplate PHP; **shrunk to stubs**: 4 classes (~450 → ~60 lines); **added**: one manifest `observability` block + `deepLinks` block, alias wiring.
- **Behavioural deltas (all intentional, all improvements)**: health URL moves `/api/admin/health` → `/api/health`, auth admin → public, shape custom → standard, fake OR check → real, two stub fields dropped, new admin metrics endpoint appears.
- **Docs**: `docs/Technical/api.md` + `architecture.md` rewritten for the standard endpoints.
- **Risk**: parity drift in the aliased boilerplate — mitigated by the OR AppHost Newman contract collection plus scholiq's existing e2e suite (pages, settings, preferences) staying green.

## Dependencies

Chained: `apphost-observability-engine`, `apphost-boilerplate-controllers` (both in openregister). ADR-040 defines the manifest block; ADR-006 defines the endpoint contract this change finally brings scholiq into.
