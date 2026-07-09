---
kind: code
---

# Proposal: relocate-dataexchange-remove-assistant

## Summary

Phase B of the two-phase Scholiq navigation cleanup: two independent, surgical UI-only cleanups. (1) Remove the inherited generic **Assistant** AI-chat surface — the `AssistantMenu` menu entry, the `Assistant` chat page, its settings-foldout entry, and the nc-vue "Open AI chat" floating action button that exists solely because a `type:"chat"` page is declared. (2) **Relocate** the data-exchange entry point out of the in-app settings foldout and into the Nextcloud **Admin Settings** page, while keeping the entire data-exchange backend, register schemas, lifecycle guards, OSO gate and OpenConnector delegation fully intact and every data-exchange page routable. This is nav/UI glue only — no backend deletion, no schema change, no PHP change.

## Motivation

Every Conduction app inherits a generic AI Assistant chat companion. In Scholiq this chat is not part of the education scope (ADR-009), duplicates capability that belongs to the EU AI Act governance register, and clutters the navigation with a floating chat FAB. It is removed here.

Separately, data-exchange is **core** Scholiq education (ADR-009 §3/§4: OSO / DUO-BRON / RIO aanleveringen), not inherited plumbing. Its backend is shared by both OSO and generic delivery flows and must stay. But its current entry point — a gear-foldout leaf in the in-app left nav — sits awkwardly beside genuine navigation destinations. Moving only the entry point into the Nextcloud Admin Settings page (where adapter/config surfaces belong, mirroring the existing "Manage AI features" affordance) aligns data-exchange with the fleet IA convention without touching a single line of its backend.

Doing this now, stacked on the unmerged Phase A nav restructure (`nav-restructure-dashboards`), keeps the whole navigation cleanup reviewable as consecutive webpack units before anything else lands.

## Affected Projects

- [ ] Project: `scholiq` — remove the Assistant menu entry + chat page + settings-foldout entry (drops the nc-vue chat FAB); remove the `DataExchange` in-app settings-foldout entry and add a "Data exchange" section to the Admin Settings page linking out to the still-routable data-exchange SPA pages. Frontend/nav-glue only.

No other apps-extra project is touched. No OpenRegister, OpenConnector, schema, register, seed-data, lifecycle, guard, or PHP change is required.

## Scope

### In Scope

1. **Remove the inherited Assistant chat.**
   - Remove the `AssistantMenu` entry (`label:"Assistant"`, `route:"Assistant"`, `icon:"icon-comment"`, order 92) from `src/manifest.json` `menu[]`.
   - Remove the `Assistant` page (`route:"/assistant"`, `type:"chat"`, no component — rendered by nc-vue `defaultPageTypes`) from `src/manifest.json` `pages[]`.
   - Remove `AssistantMenu` from `src/menu-layout.json#settingsSection`.
   - Removing the `type:"chat"` page removes the floating "Open AI chat" FAB that nc-vue's `CnAppRoot` renders (verified in-browser at apply).
2. **Relocate the data-exchange entry point into the Admin Settings page — keep the entire backend + OSO.**
   - Remove `DataExchange` from `src/menu-layout.json#settingsSection` (drops the in-app left-nav gear-foldout entry).
   - Add a **"Data exchange"** section to the Admin Settings page mounted by `lib/Settings/AdminSettings.php` + `src/settings.js` (`src/views/settings/AdminRoot.vue`), surfacing entry points to Data-exchange **jobs** (`#/data-exchange/jobs`) and **mapping profiles** (`#/data-exchange/mapping-profiles`) as links that open the still-routable in-app SPA pages, mirroring `ScholiqSettings.vue`'s "Manage AI features" affordance.
   - All data-exchange manifest pages (`DataExchangeJobs`, `DataExchangeJobDetail`, `DataMappingProfiles`, `DataMappingProfileDetail`, `RequestExportModal`, `OsoDossierReviewView`) stay registered and routable (deep links + e2e).

### Out of Scope

- Any change to the data-exchange **backend**: `DataExchangeJob` / `DataMappingProfile` register schemas, `DataExchangeRunGuard` / `DataExchangeRunHandler`, `OsoDossierReviewGuard`, the OSO parent-review lifecycle gate, and OpenConnector delegation all stay intact. No `_registers.json` edit, no seed-data change, no PHP change, no migration.
- The **AiFeature** EU AI Act governance register and its pages (`AiFeatures`, `AiFeatureDetail`) — a separate concern (ai-surface REQ-SAI-002/003/004), NOT touched by this change.
- Phase A scope (`nav-restructure-dashboards`): Insight dissolution, domain dashboards, Features & roadmap → footer, Rollover → settings. Not restated here.

## Approach

Pure declarative manifest + `menu-layout.json` edits for both removals, plus one Vue edit to add a data-exchange section to the admin settings page. The admin settings mount has **no in-app vue-router**, so the new section links out via full navigation (hash-form SPA URL), never by embedding the router pages. See design.md.

## New Dependencies

None.

## Impact

- `src/manifest.json` — remove one `menu[]` entry and one `pages[]` entry.
- `src/menu-layout.json` — remove `AssistantMenu` and `DataExchange` from `settingsSection`.
- `src/views/settings/AdminRoot.vue` (+ a small child section component) — add the "Data exchange" section.
- `tests/e2e/pages.spec.ts` — Gate-19 route table: ensure no `Assistant`/`#/assistant` route; add the now-nav-less data-exchange (and AI-features) deep-link routes so their continued reachability is asserted.
- New user-facing strings in the admin-settings section require nl+en translations (ADR-007).

## Cross-Project Dependencies

None. Single project (`scholiq`), no API, no shared schema, no data migration.

## Risks

### Risk 1: Data-exchange deep links break if a page id is dropped by mistake
- **Severity**: Medium
- **Mitigation**: The change removes only the `DataExchange` nav leaf id from `settingsSection`; every data-exchange `pages[]` object stays. Gate-19 route-smoke adds the two data-exchange deep-link routes so a dropped page fails CI.

### Risk 2: The removed `type:"chat"` page does not actually clear the FAB
- **Severity**: Low
- **Mitigation**: The FAB is rendered by nc-vue purely off the presence of a `type:"chat"` page; verified in-browser at apply. No scholiq code renders it.

## Rollback Strategy

Revert the single frontend commit (manifest + menu-layout + AdminRoot section + e2e table). No data, schema, or backend state is touched, so rollback is a pure code revert with no migration to undo.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `ai-surface`: the inherited Assistant AI-chat nav entry, chat page and FAB are removed (the AiFeature governance requirements REQ-SAI-002/003/004 are unaffected; REQ-SAI-003 drops the Assistant page from its retained-pages set).
- `data-exchange`: the frontend entry point relocates from the in-app settings foldout to the Nextcloud Admin Settings page; all data-exchange pages stay routable and the backend is unchanged.
