---
slug: nextcloud-app
title: Nextcloud App Shell
status: planned
feature_tier: must
depends_on_adrs: [ADR-005, ADR-008]
created: 2026-05-11
updated: 2026-05-11
---

# Nextcloud App Shell — Formal Requirements

## Overview

The Nextcloud App Shell is the foundational layer for all Scholiq capabilities. It owns the PHP bootstrap, Vue SPA entry point, OpenRegister dependency guard, settings dialogs, audit-trail service, and NL Design token wiring. Every other Scholiq spec depends on this spec completing first.

---

## Requirements

### REQ-NA-001 — Dependency declaration (ADR-008)

The app MUST declare `openregister` and `openconnector` as hard `<dependency>` entries in `appinfo/info.xml`. Scholiq MUST NOT enable if either dependency is absent or disabled.

#### Scenario NA-001-A: OpenRegister absent at install time
```
GIVEN the Nextcloud admin attempts to enable Scholiq
  AND the OpenRegister app is not installed
WHEN Nextcloud evaluates app dependencies
THEN Nextcloud MUST block activation and display a dependency-missing error
  AND Scholiq MUST NOT register any routes or run any PHP code
```

#### Scenario NA-001-B: OpenRegister disabled post-install
```
GIVEN Scholiq is enabled and a user navigates to the Scholiq URL
  AND OpenRegister has been disabled since install
WHEN the Scholiq main template loads
THEN the Vue SPA MUST render NcEmptyContent with title "OpenRegister is required"
  AND the NcEmptyContent MUST show an admin "Install OpenRegister" action button when the current user isAdmin()
  AND NO other UI component MUST render
```

---

### REQ-NA-002 — Vue Router in hash mode

The SPA MUST use Vue Router in hash mode (`createWebHashHistory`). All navigation between Scholiq views MUST be hash-route transitions.

#### Scenario NA-002-A: Deep-link to a course detail view
```
GIVEN a user navigates to /index.php/apps/scholiq/#/courses/abc123
WHEN the page loads
THEN Vue Router MUST match the route /courses/:id
  AND the course detail view MUST render without a full page reload
  AND the browser back button MUST navigate to the previous route within the SPA
```

#### Scenario NA-002-B: Programmatic navigation
```
GIVEN a user clicks a "View Enrolment" link inside the dashboard
WHEN the click handler fires
THEN the SPA MUST call $router.push('/enrolments/:id') using Vue Router
  AND the URL hash MUST update to reflect the new route
  AND no custom $emit('navigate') pattern or href with page reload SHALL be used
```

---

### REQ-NA-003 — NcEmptyContent for all empty states

Every list view and entity-detail view in Scholiq MUST use the `NcEmptyContent` component from `@nextcloud/vue` for zero-result and error states.

#### Scenario NA-003-A: Empty course list
```
GIVEN a user opens the Courses list view
  AND no Course objects exist in OpenRegister for this tenant
WHEN the list component renders
THEN it MUST render NcEmptyContent with a descriptive title, optional illustration, and a primary action button
  AND it MUST NOT render a plain "No data" string, an empty table, or a loading spinner indefinitely
```

#### Scenario NA-003-B: Network error fallback
```
GIVEN the OpenRegister API call from a list view fails with a non-2xx status
WHEN the component catches the error
THEN it MUST render NcEmptyContent with an error-state title and a "Retry" action button
  AND the error detail SHOULD appear in a collapsible technical-details section
```

---

### REQ-NA-004 — NL Design System theming (double-fallback pattern)

All CSS colour and spacing references in Scholiq MUST use the double-fallback CSS variable pattern: `var(--cn-X, var(--color-X, <literal-fallback>))`. Hardcoded hex or rgba colours MUST NOT appear in component stylesheets.

#### Scenario NA-004-A: Theming variable resolution
```
GIVEN the hosting NC instance ships an NL Design theme (Utrecht, Rijksoverheid, custom)
WHEN the Scholiq SPA renders
THEN all branded colours MUST resolve from the active NL Design token set
  AND the Scholiq UI MUST NOT show any hardcoded brand colours that conflict with the theme
```

#### Scenario NA-004-B: Fallback when no custom NL Design theme is active
```
GIVEN the NC instance has no custom NL Design theme active
WHEN the Scholiq SPA renders
THEN the CSS double-fallback chain MUST resolve to the stock Nextcloud color variables
  AND the UI MUST remain functional and not show unstyled/transparent elements
```

---

### REQ-NA-005 — NcAdminSettings panel

The system MUST register an `NcAdminSettings` panel under Settings → Administration → Scholiq accessible only to NC admin users.

#### Scenario NA-005-A: Admin configures OpenRegister register
```
GIVEN a NC admin opens Settings → Administration → Scholiq
WHEN the panel loads
THEN it MUST show a dropdown to select the OpenRegister register used by Scholiq
  AND saving the selection MUST persist via IAppConfig under key 'default_register'
  AND a success notification MUST confirm the save
```

#### Scenario NA-005-B: Admin enables AI Act feature flag (ADR-005)
```
GIVEN a NC admin opens the Scholiq admin panel
  AND an AI feature (e.g. adaptive-learning) is registered in AiFeatureRegistry
WHEN the admin toggles the feature flag ON
THEN the UI MUST present a CE acknowledgement modal before persisting the change
  AND accepting the modal MUST write an audit-trail entry with event_type 'ai.feature.flag.toggled' per ADR-008
  AND the feature flag MUST be stored in IAppConfig
```

#### Scenario NA-005-C: Non-admin user cannot access admin panel
```
GIVEN a non-admin user navigates directly to the admin settings URL
WHEN Nextcloud evaluates the request
THEN the system MUST return HTTP 403 and MUST NOT render the admin settings form
```

---

### REQ-NA-006 — NcUserSettings panel

The system MUST register a personal settings panel under Settings → Personal → Scholiq for every authenticated NC user.

#### Scenario NA-006-A: User saves notification preferences
```
GIVEN an authenticated user opens Settings → Personal → Scholiq
WHEN they toggle "Notify me when compliance training is due" to off and save
THEN the preference MUST persist via OCP\IConfig::setUserValue('scholiq', 'notify_compliance_renewal', '0')
  AND subsequent notification dispatch MUST respect the preference
```

#### Scenario NA-006-B: User sets default view
```
GIVEN an authenticated user sets default_view to 'cards'
WHEN they next open the Scholiq SPA
THEN the landing route MUST render the cards view without requiring manual selection
```

---

### REQ-NA-007 — AuditTrail service (ADR-008)

The system MUST provide `Scholiq\Service\AuditTrail` as a registered DI service. Every state-changing controller endpoint MUST call `AuditTrail::record(string $eventType, array $payload)` within the same DB transaction as the projection-table write.

#### Scenario NA-007-A: Audit record on state change
```
GIVEN any state-changing endpoint (POST/PUT/PATCH/DELETE) is called
WHEN the controller processes the request successfully (2xx)
THEN AuditTrail::record() MUST have been called with a registered event_type from AuditEventTypes
  AND the audit event MUST be persisted as an append-only OpenRegister object
  AND the audit event id MUST be referenced in the API response
```

#### Scenario NA-007-B: Missing audit call fails the build
```
GIVEN a developer submits a PR with a state-changing controller that omits AuditTrail::record()
WHEN the PHPStan custom rule runs in CI
THEN the build MUST fail with a rule violation naming the controller method
```

---

### REQ-NA-008 — AiFeatureRegistry skeleton (ADR-005)

The system MUST provide `Scholiq\Service\AiFeatureRegistry` as a registered DI service. In v0.1 the registry MUST be empty (no features registered). The registry interface MUST be stable so Phase 3 features can register without changing the call site.

#### Scenario NA-008-A: Empty registry in v0.1
```
GIVEN Scholiq v0.1 is installed
WHEN AiFeatureRegistry::all() is called
THEN it MUST return an empty array
  AND no AI feature toggles MUST appear in the admin settings panel
```

---

### REQ-NA-009 — i18n: NL + EN minimum (applies_to: all specs)

The system MUST ship translation keys for both `nl` and `en` locales. Every user-facing string MUST be wrapped in `$this->l->t()` (PHP) or `t('scholiq', '...')` (Vue). Untranslated hardcoded strings MUST NOT appear in UI components.

#### Scenario NA-009-A: Dutch locale renders NL strings
```
GIVEN a user's NC account locale is set to 'nl'
WHEN any Scholiq view renders
THEN all UI labels, button texts, and error messages MUST be in Dutch
```

#### Scenario NA-009-B: Fallback to English
```
GIVEN a user's NC account locale is neither 'nl' nor a known locale
WHEN any Scholiq view renders
THEN all UI labels MUST fall back to English
```

---

### REQ-NA-010 — WCAG 2.1 AA accessibility baseline

All Scholiq UI components MUST pass WCAG 2.1 Level AA contrast and keyboard-navigation requirements. @conduction/nextcloud-vue components inherit this; custom components MUST be individually verified.

#### Scenario NA-010-A: Keyboard navigation through settings dialog
```
GIVEN a user opens NcAdminSettings using only keyboard
WHEN they Tab through the panel
THEN every interactive element MUST receive a visible focus ring
  AND all form controls MUST be operable via keyboard alone
  AND no keyboard trap MUST exist
```
