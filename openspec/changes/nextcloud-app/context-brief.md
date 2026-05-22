---
slug: nextcloud-app
title: Nextcloud App Shell
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-008, adr-011, adr-012]   # TODO until ADRs land
created: 2026-05-11
---

# Nextcloud App Shell

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer > Nextcloud-app-shell

**Rationale:** shell config  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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
- The system MUST declare `openregister` and `openconnector` as `<dependencies>` in `appinfo/info.xml` and refuse to bootstrap without them.
- The system MUST use Vue Router in hash mode for all navigation; custom hash routing or `$emit('navigate')` patterns are forbidden.
- The system MUST render `NcEmptyContent` for every empty list/state; raw "no data" strings are forbidden.
- The system MUST use the NL Design System double-fallback CSS pattern (`var(--cn-X, var(--color-X, fallback))`); hardcoded colours are forbidden.

## Standards
Nextcloud OCP (`IAppManager`, `IConfig`, `IUserSession`, `IRootFolder`, `IGroupManager`, `Calendar\IManager`, `Notification\IManager`, `Talk\IBroker`, `Activity\IManager`), NL Design System tokens, WCAG 2.1 AA.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `TenantSetting`, `UserSetting`. Other entities live in their respective specs.

## Out of Scope
- Authoring of business-domain features (every other spec owns its own domain).
- Custom Nextcloud theme — we consume the NL Design tokens, we do not ship a global theme.
- ExApp / sidecar architecture (PHP-only at MVP).
