---
kind: code
depends_on: []
---

# Proposal: adopt-shared-menu-pipeline

## Summary

Scholiq currently carries a **monolithic** `src/manifest.json` built and consumed
directly in `src/main.js` without a fragment pipeline or a `menu-layout.json`
separation. ADR-044 ("Menu architecture") mandates that every Conduction app build its
effective manifest through the shared `buildManifest(base, fragments, menuLayout)`
helper from `@conduction/nextcloud-vue`, drive navigation layout through a
data-only `src/menu-layout.json` file, and lift configuration/admin leaves into the
settings foldout via `menu-layout.json#settingsSection`.

This change introduces the two structural prerequisites for ADR-044 compliance in
scholiq:

1. **ADR-037 modular fragment pipeline** — adds `src/manifest.d/` as the fragment
   collection directory and updates `src/main.js` to use `require.context` to gather
   fragments, wiring them through `buildManifest`.
2. **ADR-044 `buildManifest` wiring** — replaces the direct `bundledManifest` import
   with a `buildManifest(base, fragments, menuLayout)` call, with `src/menu-layout.json`
   as the layout source.
3. **Settings foldout** — lifts the four integration/admin leaves
   (`DataExchange`, `XapiStatementsMenu`, `AssistantMenu`, `FeaturesRoadmapMenu`) that
   currently carry an ad-hoc `section: "settings"` or are footer-adjacent into the
   canonical `menu-layout.json#settingsSection` declarative list, so the settings
   foldout is controlled entirely by data rather than per-entry `section` flags.
4. **Hard invariant** — every pre-existing menu entry remains reachable (relocated,
   foldout, or nav) and every page stays routable (deep links and e2e routes
   unaffected).

## Motivation

- **ADR-044 compliance.** The shared `buildManifest` helper is the fleet's single
  source of truth for manifest assembly; inline assembly in `main.js` diverges from
  the canonical pattern and makes fleet-wide manifest tooling impossible.
- **ADR-037 compliance.** Without `src/manifest.d/` and `require.context` collection,
  per-feature fragments from future changes cannot be added without touching the
  monolith.
- **Settings foldout coherence.** The current approach scatters `section: "settings"`
  across individual menu entries; ADR-044 centralises the foldout list in
  `menu-layout.json#settingsSection` so the foldout is visible and maintainable in
  one place.
- **No dropped routes.** The refactor is purely structural; no `pages[]` entry, route,
  deep link, or reachable function is removed.

## Affected Projects

- [x] Project: scholiq

## Capabilities

- Modified: `navigation` (ADR-037 fragment pipeline + ADR-044 buildManifest wiring +
  settings foldout lift)
