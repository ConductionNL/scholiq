# Design: relocate-dataexchange-remove-assistant

## Context

This is **Phase B** of the two-phase Scholiq navigation cleanup, stacked on the unmerged Phase A change `nav-restructure-dashboards` (which dissolved the Insight group, added domain dashboards, moved Features & roadmap to the footer and Rollover to the settings foldout). Phase B does two independent, surgical UI-only cleanups and touches no backend.

Current state, verified in the working tree:

- **Assistant** — `src/manifest.json` `menu[]` has an `AssistantMenu` entry (`label:"Assistant"`, `route:"Assistant"`, `icon:"icon-comment"`, order 92) and `pages[]` has an `Assistant` page (`route:"/assistant"`, `type:"chat"`, no component — rendered by nc-vue `defaultPageTypes`). `src/menu-layout.json#settingsSection` is `["DataExchange","XapiStatementsMenu","AssistantMenu","Rollover"]`. There is **no scholiq lib/PHP** code for the assistant — it is pure manifest + nc-vue. nc-vue's `CnAppRoot` renders the floating "Open AI chat" FAB because a `type:"chat"` page exists.
- **Data-exchange** — `menu[]` has a `DataExchange` entry (`label:"Data exchange"`, `route:"DataExchangeJobs"`, `section:"settings"`, order 93) surfaced in the foldout via `settingsSection`. Its backend (`DataExchangeJob` + `DataMappingProfile` OR schemas, `DataExchangeRunGuard`/`DataExchangeRunHandler`, `OsoDossierReviewGuard`, OSO parent-review lifecycle gate, OpenConnector delegation) and its six `pages[]` objects (`DataExchangeJobs`, `DataExchangeJobDetail`, `DataMappingProfiles`, `DataMappingProfileDetail`, `RequestExportModal`, `OsoDossierReviewView`) are all present.
- The **Admin Settings** page is mounted by `lib/Settings/AdminSettings.php` (an AppHost `GenericAdminSettings` stub) + `src/settings.js` (`new Vue({ render: h => h(AdminRoot) }).$mount('#scholiq-settings')`) and renders `src/views/settings/AdminRoot.vue` → `CnVersionInfoCard` + `ScholiqSettings` + `ActionAuthMatrix`. `ScholiqSettings.vue` already establishes the "link out to an in-app SPA page" pattern with its "Manage AI features" `NcButton`.

## Why this is `kind: code` (mixed-spec rationale)

The dominant surface is a **frontend Vue edit** to the admin-settings page (`AdminRoot.vue` + a new section component). The two manifest / `menu-layout.json` config edits are tightly-coupled nav glue that ship in the **same webpack build and the same reviewable unit** as the Vue edit — they are not an independent config-only change. Declaring `kind: code` keeps the whole cleanup under the code-change quality gates (Gate-19 e2e, i18n, ADR-004) rather than splitting a single reviewable diff.

## Goals / Non-Goals

**Goals:**
- Remove the inherited Assistant chat surface (menu entry, chat page, foldout entry, FAB) with zero backend impact.
- Relocate the data-exchange entry point into the Admin Settings page while keeping the full backend and every data-exchange page routable.
- New admin-settings strings ship in nl + en (ADR-007).

**Non-Goals:**
- **No OpenRegister schema / register / seed-data change**, **no `_registers.json` edit**, **no lifecycle / guard change**, **no OSO change**, **no PHP change**. The data-exchange backend is untouched.
- Not touching the EU AI Act `AiFeature` governance register (a separate concern — ai-surface REQ-SAI-002/003/004). AiFeature ≠ Assistant.
- Not restating Phase A scope (Insight dissolution, dashboards, footer/settings relocations).

## Decisions

### Decision 1: Remove the Assistant surface declaratively; let nc-vue drop the FAB
Remove the `AssistantMenu` `menu[]` entry, the `Assistant` `pages[]` object, and `AssistantMenu` from `settingsSection`. Because the FAB is rendered by nc-vue `CnAppRoot` purely off the presence of a `type:"chat"` page, deleting that page removes the FAB with no scholiq-side code. **Alternative considered:** hiding the entry via `visibleIf` — rejected because it leaves the chat page (and FAB) live and is not a real removal.

### Decision 2: New data-exchange section is a dedicated child component under `src/views/settings/`
Add `src/views/settings/DataExchangeSettingsSection.vue` (an `NcSettingsSection`) and render it from `AdminRoot.vue` alongside the existing children. **Alternative considered:** inline `NcSettingsSection` markup directly in `AdminRoot.vue` — rejected for testability/isolation; a dedicated component keeps `AdminRoot.vue` a thin composer and matches the `ScholiqSettings.vue` sectioning idiom. (ADR-004 modal-isolation is N/A — no modal/dialog is introduced.)

### Decision 3: Link out via full navigation, not the in-app router
The `AdminRoot` mount has **no in-app vue-router**, so the section links out with full navigation using the hash-form SPA URL, e.g. `window.location.href = generateUrl('/apps/scholiq') + '#/data-exchange/jobs'` (and `#/data-exchange/mapping-profiles`). This mirrors `ScholiqSettings.vue`'s "Manage AI features" fallback path. **Alternative considered:** `this.$router.push(...)` — rejected because there is no router on this mount; it would throw.

### Decision 4: ADR-004 idioms for the new Vue
The new section uses `NcSettingsSection` + `NcButton`, wraps every user-facing string in `t('scholiq', …)`, reads no DOM `data-*` attributes (nothing server-provided is needed — the links are static SPA routes), and introduces no inline modal/dialog. New strings ("Data exchange", the section description, and the two button labels) are English source keys with nl + en translations (ADR-007).

### Decision 5: Keep Gate-19 route-smoke honest for the now-nav-less pages
The data-exchange pages lose their nav entry, so their reachability is now only via the Admin Settings links / deep links. Add `#/data-exchange/jobs` and `#/data-exchange/mapping-profiles` to `tests/e2e/pages.spec.ts` (with `// @e2e data-exchange::…` references) so a dropped page id fails CI. Confirm no `Assistant` / `#/assistant` route is present in that table (it currently is not).

## Risks / Trade-offs

- [The data-exchange section links to a route that a later refactor renames] → the Gate-19 route-smoke entries catch a broken deep link; the section labels/URLs are the single source in one small component.
- [Removing the `type:"chat"` page does not clear the FAB] → verify in-browser at apply; the FAB is nc-vue-owned with no scholiq render path, so this is a Low risk.
- [Admin Settings section not covered by route-smoke] → accepted; the NC-settings-framework render is outside the SPA harness and is verified in-browser at apply (scenario annotated `@e2e exclude`).

## Migration Plan

**No database, OpenRegister schema, or data migration is required** — the change is frontend manifest / `menu-layout.json` / Vue only, with no backend or schema impact (`migration.md` is skipped for this reason; ADR-001 register-import and ADR-031 notification-dialect are N/A because no register or notification definition changes). Deploy is a normal webpack build + app version bump for cache-bust. **Rollback:** revert the single frontend commit — no state to undo.

## Open Questions

None.
