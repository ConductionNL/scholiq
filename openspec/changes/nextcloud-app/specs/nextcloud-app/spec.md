---
slug: nextcloud-app
title: Nextcloud App Shell
status: planned
feature_tier: must
depends_on_adrs: [ADR-005, ADR-008]
created: 2026-05-20
updated: 2026-05-20
---

# Nextcloud App Shell — Formal Requirements

## Overview

The Nextcloud App Shell is the foundational layer for all Scholiq capabilities. It owns the PHP bootstrap, Tier-4 `CnAppRoot` manifest shell, OpenRegister runtime dependency guard, admin and user settings panels, `TenantSetting` and `UserSetting` schemas, and NL Design token wiring. Every other Scholiq change depends on this one completing first.

This spec reflects the revised architecture per ADR-022 (no parallel audit substrate), ADR-024 (manifest-driven shell), and ADR-031 (schema-declarative settings lifecycle). Requirements that appeared in the archived v1 spec (REQ-NA-007 `AuditTrail` service, REQ-NA-008 `AiFeatureRegistry`) are explicitly superseded; those patterns are forbidden on net-new code.

---

## Requirements

### REQ-NA-001 — OpenRegister dependency declaration and guard

The app MUST declare `openregister` and `openconnector` as hard `<dependency>` entries in `appinfo/info.xml`. Scholiq MUST NOT enable if either dependency is absent. At runtime, if a declared dependency becomes unavailable, the Vue SPA MUST render `NcEmptyContent` explaining the missing dependency; no other UI MUST render.

#### Scenario NA-001-A: Missing dependency blocks activation

```
GIVEN the Nextcloud admin attempts to enable Scholiq
  AND the OpenRegister app is not installed
WHEN Nextcloud evaluates app dependencies
THEN Nextcloud MUST block activation and display a dependency-missing error
  AND Scholiq MUST NOT register any routes or execute any application PHP
```

#### Scenario NA-001-B: Dependency missing at runtime

```
GIVEN OpenRegister is not installed or is disabled
WHEN Scholiq loads in the browser
THEN CnAppRoot MUST render NcEmptyContent explaining the dependency
  AND the NcEmptyContent MUST include the install link for OpenRegister
  AND no other Scholiq UI component MUST render
```

#### Scenario NA-001-C: Both dependencies present — normal load

```
GIVEN both openregister and openconnector are installed and enabled
WHEN Scholiq loads in the browser
THEN the full Scholiq UI MUST render with all manifest-declared menu items visible
  AND no dependency-missing empty state MUST appear
```

---

### REQ-NA-002 — Admin settings panel

A Nextcloud admin MUST be able to open a Scholiq settings panel under Settings → Administration → Scholiq and configure tenant-level settings from a single page: IdP entity, OpenConnector adapter endpoints (ROD, OSO, UWLR), and AI feature flag toggles.

#### Scenario NA-002-A: Admin configures tenant settings

```
GIVEN a Nextcloud admin opens Settings → Administration → Scholiq
WHEN the page loads
THEN they MUST be able to configure the tenant IdP, OpenConnector adapters for ROD/OSO/UWLR,
     and the AI feature flag toggle
  AND all configuration options MUST be visible and operable from one panel
```

#### Scenario NA-002-B: AI feature flag requires DPO acknowledgement

```
GIVEN a Nextcloud admin opens the Scholiq admin settings panel
  AND an AI feature TenantSetting object exists with category 'ai-feature' and lifecycle 'disabled'
WHEN the admin triggers the 'enable' lifecycle transition for that feature
THEN the system MUST invoke AiFeatureDpoAckGuard before persisting the transition
  AND if no DPO acknowledgement is recorded in IAppConfig for that feature
  THEN the transition MUST be rejected with a clear error message
  AND the TenantSetting lifecycle MUST remain 'disabled'
```

#### Scenario NA-002-C: Non-admin cannot access admin panel

```
GIVEN a non-admin Nextcloud user navigates directly to the Scholiq admin settings URL
WHEN Nextcloud evaluates the request
THEN the system MUST return HTTP 403
  AND the admin settings form MUST NOT render
```

---

### REQ-NA-003 — User personal settings panel

Every authenticated Nextcloud user MUST be able to open a Scholiq personal settings panel and set their notification preferences (instant vs digest) and default landing view (dashboard, courses, etc.).

#### Scenario NA-003-A: User saves notification preference

```
GIVEN an authenticated user opens the Scholiq personal settings panel
WHEN they change notification preference to 'digest' and save
THEN the preference MUST be persisted as a UserSetting object in OpenRegister
     with propertyName 'notification.preference' and propertyValue 'digest'
  AND subsequent notification dispatch MUST respect the saved preference
```

#### Scenario NA-003-B: User sets default landing view

```
GIVEN an authenticated user sets default_view to 'courses' in the personal settings panel
WHEN they next open the Scholiq SPA
THEN the manifest-driven router MUST navigate to the Courses page on initial load
  AND no manual view selection MUST be required
```

#### Scenario NA-003-C: Settings persist across sessions

```
GIVEN a user saved their notification preference in a previous session
WHEN they log in again and open Scholiq
THEN the personal settings panel MUST display the previously saved preference
  AND the preference MUST not have reset to a default value
```

---

### REQ-NA-004 — Vue Router hash-mode navigation (bookmarkable URLs)

Every navigation within Scholiq MUST be a hash-route transition so that deep links to any course, exam, or enrolment page can be bookmarked and shared. `CnAppRoot` derives routes from `manifest.pages`; no `src/router/index.js` SHALL be created.

#### Scenario NA-004-A: Deep link to course detail view

```
GIVEN a user navigates to /index.php/apps/scholiq/#/courses/123
WHEN the page loads
THEN CnAppRoot MUST match the hash route /courses/:id
  AND the course detail view MUST render without a full page reload
  AND the browser back button MUST navigate to the previous hash route within the SPA
```

#### Scenario NA-004-B: Manifest-declared pages resolve as hash routes

```
GIVEN the src/manifest.json declares a page with route '/enrolments'
WHEN a user navigates to /index.php/apps/scholiq/#/enrolments
THEN the Enrolments index page MUST render
  AND no custom $emit('navigate') pattern or href-with-reload SHALL be in use
```

#### Scenario NA-004-C: Unknown hash route shows NcEmptyContent

```
GIVEN a user navigates to a hash route not declared in the manifest (e.g. /#/unknown)
WHEN CnAppRoot attempts to resolve the route
THEN it MUST render NcEmptyContent with a 'Page not found' message
  AND a navigation link back to the Dashboard MUST be provided
```

---

### REQ-NA-005 — NcEmptyContent for all empty and error states

Every list view and entity-detail view in Scholiq MUST use `NcEmptyContent` from `@conduction/nextcloud-vue` for zero-result states and API error states. Raw "no data" strings, empty `<table>` bodies, and indefinite loading spinners are forbidden.

#### Scenario NA-005-A: Empty list view

```
GIVEN a user opens a list with zero rows
WHEN the empty state renders
THEN it MUST use NcEmptyContent with a descriptive title, an illustration, and a primary action button
  AND it MUST NOT render a plain 'No data' string, an empty table, or a loading spinner indefinitely
```

#### Scenario NA-005-B: API error fallback

```
GIVEN an OpenRegister API call from a list view fails with a non-2xx status
WHEN the component catches the error
THEN it MUST render NcEmptyContent with an error-state title and a 'Retry' action button
  AND the error detail SHOULD appear in a collapsible technical-details section
```

---

### REQ-NA-006 — NL Design System double-fallback CSS

All CSS colour and spacing references in Scholiq MUST use the double-fallback CSS variable pattern: `var(--cn-X, var(--color-X, <literal-fallback>))`. Hardcoded hex or `rgba()` colour values MUST NOT appear in component `<style>` blocks.

#### Scenario NA-006-A: NL Design theme resolves correctly

```
GIVEN the hosting Nextcloud instance has an active NL Design token set (Utrecht, Rijksoverheid, or custom)
WHEN the Scholiq SPA renders
THEN all branded colours MUST resolve from the active NL Design token set via the --cn-* variables
  AND no Scholiq-hardcoded brand colours MUST be visible that conflict with the active theme
```

#### Scenario NA-006-B: Fallback when no NL Design theme is active

```
GIVEN the Nextcloud instance has no custom NL Design theme active
WHEN the Scholiq SPA renders
THEN the double-fallback chain MUST resolve to Nextcloud's stock --color-* variables
  AND the UI MUST remain fully functional and not display unstyled or transparent elements
```

#### Scenario NA-006-C: Accessibility — colour is not the sole signal

```
GIVEN any Scholiq UI component conveys status information (error, warning, success)
WHEN the component renders
THEN colour MUST NOT be the sole visual means of conveying that information
  AND an icon, label, or pattern MUST accompany the colour signal (WCAG 2.1 AA 1.4.1)
```

---

### REQ-NA-007 — `@spec` PHPDoc traceability

Every PHP class and public method introduced by this change MUST carry a `@spec` PHPDoc tag linking to this change per ADR-003: `@spec openspec/changes/nextcloud-app/tasks.md#task-N`.

#### Scenario NA-007-A: New PHP file has @spec tag

```
GIVEN a developer submits a PR introducing lib/Service/Cmi5LaunchTokenService.php
WHEN the Hydra reviewer scans the file
THEN every public method MUST include a @spec PHPDoc tag referencing a task in this change's tasks.md
  AND the file-level docblock MUST include a @spec tag
```
