---
status: proposed
---

# Navigation — Shared Menu Pipeline Adoption (ADR-044)

**Status:** proposed
**Scope:** scholiq
**Depends on:** none

## Purpose

Bring scholiq into ADR-044 compliance by introducing the ADR-037 modular fragment
pipeline (`src/manifest.d/` + `require.context` collection) and wiring manifest
assembly through the shared `buildManifest(base, fragments, menuLayout)` helper from
`@conduction/nextcloud-vue`, with navigation layout data in `src/menu-layout.json`.
Lifts configuration/admin leaves into the settings foldout via
`menu-layout.json#settingsSection`. The hard invariant: every pre-existing menu entry
remains reachable and every page stays routable.

## ADDED Requirements

### Requirement: REQ-AMP-001 — scholiq MUST introduce the ADR-037 modular fragment pipeline

Per ADR-037, scholiq MUST add a `src/manifest.d/` directory as the fragment
collection root and MUST update `src/main.js` to gather fragment files via
`require.context('./manifest.d', true, /\.json$/)` (or equivalent webpack import
pattern), producing a `fragments` array passed to `buildManifest`. The base manifest
(`src/manifest.json`) MUST remain the canonical source of observability, deepLinks, and
pages; fragment files extend the menu only. No fragment file may duplicate a key
present in the base manifest.

#### Scenario: Fragment pipeline collects manifest.d files

- GIVEN `src/manifest.d/` exists and contains one or more `.json` fragment files
- WHEN the scholiq bundle is built
- THEN `require.context` (or equivalent) collects every fragment in `manifest.d/`
- AND the collected fragments are forwarded to `buildManifest` as the `fragments` argument
- AND the resulting effective manifest contains the merged menu from base + fragments

#### Scenario: Empty fragment directory is safe

- GIVEN `src/manifest.d/` exists but contains no fragment files
- WHEN the scholiq bundle is built
- THEN `buildManifest` is called with an empty `fragments` array
- AND the effective manifest is identical to the base manifest menu

### Requirement: REQ-AMP-002 — scholiq MUST build its effective manifest via the shared buildManifest helper

Per ADR-044, scholiq MUST NOT inline its own manifest assembly logic in `src/main.js`.
Instead it MUST call `buildManifest(base, fragments, menuLayout)` imported from
`@conduction/nextcloud-vue`, where `base` is the parsed `src/manifest.json`, `fragments`
is the array collected by the ADR-037 pipeline (REQ-AMP-001), and `menuLayout` is the
parsed `src/menu-layout.json`. The return value of `buildManifest` MUST be the manifest
object passed to the Vue root as the `manifest` prop and used by `routesFromManifest`.

#### Scenario: buildManifest is called at bootstrap

- GIVEN `src/main.js` imports `buildManifest` from `@conduction/nextcloud-vue`
- WHEN the app boots
- THEN `buildManifest(base, fragments, menuLayout)` is called exactly once
- AND the result is assigned to the manifest variable used by the Vue root and router
- AND no second manifest assembly path exists in the file

#### Scenario: menu-layout.json controls relocations

- GIVEN `src/menu-layout.json` contains a `relocations` entry moving a menu item
- WHEN the app boots and calls `buildManifest`
- THEN the effective manifest reflects the relocation declared in `menu-layout.json`
- AND the base `src/manifest.json` `menu[]` array is not modified to express the relocation

### Requirement: REQ-AMP-003 — scholiq's configuration/admin leaves MUST be lifted into the settings foldout via menu-layout.json#settingsSection

Per ADR-044, leaves that belong to the settings foldout MUST be declared in
`menu-layout.json#settingsSection` rather than through ad-hoc `section: "settings"`
flags scattered across individual menu entries. scholiq MUST declare the following
leaf ids in `settingsSection`: `DataExchange`, `XapiStatementsMenu`, `AssistantMenu`,
and `FeaturesRoadmapMenu`. After this change, `section: "settings"` flags on
individual `menu[]` entries for these leaves MAY be removed in favour of the
`settingsSection` list; the foldout content MUST be equivalent.

#### Scenario: Settings foldout contains the declared leaves

- GIVEN `menu-layout.json#settingsSection` lists `DataExchange`, `XapiStatementsMenu`, `AssistantMenu`, `FeaturesRoadmapMenu`
- WHEN the app renders the settings foldout
- THEN all four leaves appear in the foldout
- AND no other primary-nav entry appears in the foldout unless also declared in `settingsSection`

#### Scenario: Admin-gated leaf respects existing visibleIf in foldout

- GIVEN `AdminHealthMenu` carries `visibleIf: { "user.primaryRole": { "eq": "admin" } }`
- WHEN a non-admin user opens the settings foldout
- THEN `AdminHealthMenu` is not visible in the foldout
- AND all non-gated foldout leaves remain visible

### Requirement: REQ-AMP-004 — INVARIANT: every pre-existing menu entry MUST remain reachable and every page MUST stay routable after the pipeline refactor

The refactor MUST NOT drop, hide, or reroute any pre-existing menu entry or page route
(ADR-044 hard invariant). Every leaf that was reachable before the refactor (whether in
primary nav, footer, or settings foldout) MUST remain reachable after it. Every
`pages[]` entry and its route MUST survive unchanged in the effective manifest produced
by `buildManifest`, so that existing deep links, bookmarks, and e2e test routes continue
to resolve.

#### Scenario: All pre-existing routes resolve after refactor

- GIVEN the effective manifest produced by `buildManifest` after the refactor
- WHEN the vue-router is initialised from `routesFromManifest(effectiveManifest)`
- THEN every route that existed before the refactor is present in the router
- AND navigating to `/courses`, `/enrolments`, `/attendance/records`, `/grades/entries`, `/learning-plans`, `/assessments`, `/credentials`, `/learner-profiles`, `/data-exchange/jobs`, `/xapi-statements`, `/structure/rollover`, and `/` all resolve to their respective page components

#### Scenario: Deep link to a page survives the pipeline refactor

- GIVEN the refactor is deployed
- WHEN a user opens `/apps/scholiq/#/courses/some-uuid` directly by URL
- THEN the Course detail page renders for `some-uuid`
- AND no 404 or redirect-to-dashboard occurs
