---
slug: nextcloud-app
title: Nextcloud App Shell
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-008, adr-011, adr-012]   # TODO until ADRs land
created: 2026-05-11
retrofit_extensions:
  - REQ-005
  - REQ-006
  - REQ-007
  - REQ-008
  - REQ-009
---

# Nextcloud App Shell

## Why
Insight #19: "Nextcloud as education platform — strong privacy-first positioning (self-hosted = schools control data)." Insight #94: "OSS LMS leaders share dated UX" — being a true Nextcloud-native app is the structural differentiator. This spec defines the non-negotiable shell guardrails (settings dialog, OpenRegister dependency check, Vue Router, NL Design theming, NcEmptyContent fallback) that every other Scholiq spec relies on but none of them owns.

## What
Standard Nextcloud app shell: `appinfo/info.xml` declaring dependency on OpenRegister and OpenConnector; `NcAppSettingsDialog` for user-level settings (notification preferences, default view); `NcAdminSettings` for tenant-level settings (IdP selection, ROD/OSO connection config, AI-feature flags); a hard runtime dependency check on OpenRegister with an `NcEmptyContent` fallback if missing; Vue Router (hash mode) at `src/router/index.js`; NL Design theming via the double-fallback CSS pattern (`--cn-*` vars); `@conduction/nextcloud-vue` (`^0.1.0-beta.1`) as a peer dependency with the conditional webpack alias and dedup aliases as documented in the project rules.

## User Stories
- As a Nextcloud admin, I want Scholiq to refuse to install unless OpenRegister is present so I never end up with a broken UI.
- As a Nextcloud admin, I want a Scholiq admin-settings page where I configure the tenant IdP, the OpenConnector adapters for ROD/OSO/UWLR, and the AI feature flag toggle.
- As a user, I want a Scholiq personal-settings panel where I set my notification preferences (instant vs digest) and default landing dashboard.
- As a user, I want every navigation in Scholiq to be a real URL I can bookmark so deep links to a course, exam, or OPP work via Vue Router hash routes.
- As a user, when no data exists in a list, I want a friendly NcEmptyContent screen with a clear next action so I know what to do.

## Acceptance Criteria
- GIVEN OpenRegister is not installed or is disabled, WHEN Scholiq loads, THEN an `NcEmptyContent` screen explains the dependency and the install link, and no other UI renders.
- GIVEN a Nextcloud admin opens Settings → Administration → Scholiq, WHEN the page loads, THEN they can configure IdP, OpenConnector adapters, and AI feature flags from one panel.
- GIVEN a user navigates to `/index.php/apps/scholiq/#/courses/123`, WHEN the page loads, THEN Vue Router matches the route and the course detail view renders without manual reload.
- GIVEN a user opens a list with zero rows, WHEN the empty state renders, THEN it uses `NcEmptyContent` with title, illustration, and a primary action button.

## Requirements

### Requirement: Declare openregister + openconnector deps and refuse to bootstrap without them
The system MUST declare `openregister` and `openconnector` as `<dependencies>` in `appinfo/info.xml` and refuse to bootstrap without them.

### Requirement: Vue Router in hash mode for all navigation
The system MUST use Vue Router in hash mode for all navigation; custom hash routing or `$emit('navigate')` patterns are forbidden.

### Requirement: Render NcEmptyContent for every empty state
The system MUST render `NcEmptyContent` for every empty list/state; raw "no data" strings are forbidden.

### Requirement: Use NL Design System double-fallback CSS pattern
The system MUST use the NL Design System double-fallback CSS pattern (`var(--cn-X, var(--color-X, fallback))`); hardcoded colours are forbidden.

### Requirement: Expose app settings through a read/write Settings API
The system MUST expose the app's persisted settings (the keys managed by `SettingsService`, currently `register`) plus the derived metadata fields `openregisters` (whether OpenRegister is installed) and `isAdmin` (whether the current user is in the admin group) through a JSON Settings API. A GET request MUST return the merged settings + metadata; a POST request MUST persist only the known config keys present in the payload and return the updated merged settings. The frontend settings store and the personal/admin Settings views MUST read and write exclusively through this API.

#### Scenario: Reading current settings
- **WHEN** the frontend requests `GET /apps/scholiq/api/settings`
- **THEN** the response contains every managed config key, an `openregisters` boolean, and an `isAdmin` boolean

#### Scenario: Persisting a changed setting
- **WHEN** the frontend POSTs `{ register: "scholiq" }` to the Settings API
- **THEN** only the `register` config key is written and the response echoes the updated merged settings

#### Notes
- Unknown keys in a POST payload are silently ignored (only `CONFIG_KEYS` are written).
- `isAdmin` is `false` when there is no logged-in user.

### Requirement: Configure default register and AI features via OpenRegister-backed pickers
The admin settings surface MUST let an administrator pick a default OpenRegister register and review the configured AI features. The register options MUST be loaded from OpenRegister's `/apps/openregister/api/registers` endpoint; the AI feature list MUST be read from the Scholiq Settings API response (`aiFeatures`). Selecting a default register MUST persist it via the Settings API. Loading failures MUST be caught and logged without breaking the settings panel.

#### Scenario: Loading the register picker
- **WHEN** the admin settings view is created
- **THEN** it fetches the register list and the AI-feature list in parallel and populates the picker options

#### Scenario: Saving the default register
- **WHEN** the admin selects a register in the picker
- **THEN** the chosen register slug is POSTed to the Settings API as `default_register`

#### Notes
- A fetch failure on either list logs to console and leaves the relevant list empty rather than throwing.

### Requirement: Allow the credential signing key to be rotated from settings
The admin settings surface MUST provide an action that rotates the tenant's RS256 credential signing key and surface a success/failure message to the user.

#### Scenario: Rotating the signing key
- **WHEN** the admin triggers the rotate-signing-key action
- **THEN** the backend re-import/key endpoint is called and a localized success or failure message is shown

#### Notes
- Observed: the rotate action POSTs to `/apps/scholiq/api/settings/load` (the config re-import route) rather than a dedicated key-rotation endpoint. This is documented as observed behavior, not endorsed — a dedicated rotation endpoint is a future tightening.

### Requirement: Provide a configurable generic OpenRegister object store initialised at boot
The frontend MUST initialise a generic Pinia object store at application boot, configuring it with the OpenRegister object and schema base URLs. The store MUST allow registering named object types (type → schema + register) and fetching objects of a registered type with arbitrary query params, returning an empty array (and warning) for unregistered types and on fetch failure. Boot initialisation MUST also trigger the initial settings fetch.

#### Scenario: Booting the stores
@e2e exclude Pure JS/Pinia store initialization — observable only by instrumenting Vue internals, not by DOM assertions. Covered by unit tests.
- **WHEN** `initializeStores()` runs
- **THEN** the object store is configured with the OR object/schema base URLs and the settings store performs its initial fetch

#### Scenario: Fetching an unregistered type
@e2e exclude Pure JS/Pinia store behavior — no DOM change occurs for an unregistered type warning. Covered by unit tests.
- **WHEN** `fetchObjects` is called for a type that was never registered
- **THEN** it warns and returns an empty array without issuing a request

#### Notes
- Fetch failures are caught, logged, and surface as an empty array — callers never see a rejected promise.

### Requirement: Serve a read-only admin health endpoint and the bundled app manifest
The system MUST expose an admin-only health endpoint reporting OpenRegister connectivity, the count of registered schemas, a 24-hour audit-trail event count, whether LaunchPad is installed, and the last audit-pack export timestamp. The system MUST also serve the bundled `src/manifest.json` blob unchanged via a manifest endpoint (ADR-024 §4).

#### Scenario: Reading health diagnostics
@e2e exclude Admin-only backend API endpoint — returns JSON with no corresponding UI page that renders the health fields. Covered by PHPUnit/Newman API tests.
- **WHEN** an admin requests the health endpoint
- **THEN** the response contains `openregister_connected`, `schemas_registered`, `audit_trail_events_24h`, `launchpad_installed`, and `last_audit_pack_export`

#### Scenario: Serving the manifest
@e2e exclude Backend JSON-passthrough endpoint — returns the raw manifest blob with no UI rendering. The manifest content is exercised indirectly by all SPA navigation tests.
- **WHEN** the frontend requests the manifest endpoint
- **THEN** the bundled `src/manifest.json` is returned as JSON

#### Notes
- Observed: `audit_trail_events_24h` returns `0` and `last_audit_pack_export` returns `null` in v0.1 — placeholders pending an OpenRegister audit-event query API. `openregister_connected` is derived from the presence of the bundled register manifest file, not a live connection probe.

## Standards
Nextcloud OCP (`IAppManager`, `IConfig`, `IUserSession`, `IRootFolder`, `IGroupManager`, `Calendar\IManager`, `Notification\IManager`, `Talk\IBroker`, `Activity\IManager`), NL Design System tokens, WCAG 2.1 AA.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `TenantSetting`, `UserSetting`. Other entities live in their respective specs.

## Out of Scope
- Authoring of business-domain features (every other spec owns its own domain).
- Custom Nextcloud theme — we consume the NL Design tokens, we do not ship a global theme.
- ExApp / sidecar architecture (PHP-only at MVP).
