# Tasks: relocate-dataexchange-remove-assistant

<!-- Hydra cap: MAX 20 unindented `- [ ]` lines. This file has 6 tasks × 2 = 12. -->
<!-- Phase B, stacked on the unmerged Phase A change `nav-restructure-dashboards`. -->

## Implementation Tasks

### Task 1: Remove the inherited Assistant AI-chat surface
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/ai-surface/spec.md#requirement-req-sai-005-the-system-shall-not-present-an-inherited-assistant-ai-chat-surface`
- **files**: `src/manifest.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN inspected THEN no `menu[]` entry with `id:"AssistantMenu"` and no `pages[]` page with `id:"Assistant"` (`/assistant`, `type:"chat"`) remain
  - GIVEN `src/menu-layout.json#settingsSection` WHEN inspected THEN it no longer lists `AssistantMenu` (and its `_settingsSectionNote` earmark is updated to reflect the completed removal)
  - GIVEN the app shell rendered in a browser WHEN any page loads THEN nc-vue's floating "Open AI chat" FAB is no longer present
- [x] Implement
- [x] Test

### Task 2: Remove the DataExchange in-app settings-foldout entry
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page`
- **files**: `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `src/menu-layout.json#settingsSection` WHEN inspected THEN it no longer lists `DataExchange` (leaving `["XapiStatementsMenu","Rollover"]`)
  - GIVEN every data-exchange `pages[]` object WHEN inspected THEN all remain registered and routable (no page removed)
- [x] Implement
- [x] Test

### Task 3: Add the "Data exchange" section to the Admin Settings page
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page`
- **files**: `src/views/settings/DataExchangeSettingsSection.vue` (new), `src/views/settings/AdminRoot.vue`
- **acceptance_criteria**:
  - GIVEN a new `DataExchangeSettingsSection.vue` (an `NcSettingsSection`) WHEN rendered from `AdminRoot.vue` THEN it shows links to Data-exchange jobs and mapping profiles
  - GIVEN the Admin Settings mount has no vue-router WHEN a link is activated THEN it navigates via full navigation to `generateUrl('/apps/scholiq') + '#/data-exchange/jobs'` (and `#/data-exchange/mapping-profiles`), mirroring `ScholiqSettings.vue`'s "Manage AI features" affordance
  - GIVEN ADR-004 WHEN the component is authored THEN all strings use `t('scholiq', …)`, no DOM `data-*` reads, no inline modal
- [x] Implement
- [x] Test

### Task 4: Narrow ai-surface retained-pages to drop the Assistant page
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/ai-surface/spec.md#requirement-req-sai-003-the-system-shall-keep-the-ai-features-register-and-assistant-pages-routable`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `AiFeatures` (`/ai-features`) and `AiFeatureDetail` (`/ai-features/:id`) pages WHEN inspected THEN both remain unchanged and routable (governance register untouched)
  - GIVEN the Assistant page removed in Task 1 WHEN `/ai-features` and its detail deep links are followed THEN they still resolve (no regression to AiFeature governance)
- [x] Implement
- [x] Test

### Task 5: Update Gate-19 e2e route-smoke table
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page`
- **files**: `tests/e2e/pages.spec.ts`
- **acceptance_criteria**:
  - GIVEN the route table WHEN inspected THEN it contains no `Assistant`/`#/assistant` entry (currently absent — confirm it stays absent)
  - GIVEN the now-nav-less data-exchange pages WHEN the table is updated THEN it includes `{ name:'DataExchangeJobs', path:'#/data-exchange/jobs' }` and `{ name:'DataMappingProfiles', path:'#/data-exchange/mapping-profiles' }`, each carrying a `// @e2e data-exchange::…-page-remains-routable-via-deep-link` reference so the delta-spec scenarios are covered
- [x] Implement
- [x] Test

### Task 6: Add nl + en strings for the new admin-settings section
- **spec_ref**: `openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page`
- **files**: `l10n/nl.json`, `l10n/en.json`, `src/views/settings/DataExchangeSettingsSection.vue`
- **acceptance_criteria**:
  - GIVEN the new section labels ("Data exchange", the section description, "Data-exchange jobs", "Mapping profiles") WHEN added THEN Dutch (`nl_NL`) and English (`en_US`) strings exist for each (ADR-005/ADR-007)
  - GIVEN i18n keys WHEN authored THEN keys are the ENGLISH source string, never Dutch

- [x] Implement
- [x] Test

## Quality checklist

<!-- Reminders for the builder — plain bullets, NOT tracked checkboxes. -->

- Acceptance: no `AssistantMenu` menu entry / `Assistant` page / `AssistantMenu` foldout entry / chat FAB; `DataExchange` gone from `settingsSection` but every data-exchange page still routable; Admin Settings shows a "Data exchange" section linking to jobs + mapping-profiles.
- **No backend touched**: no OpenRegister schema / register / seed-data change, no `_registers.json` edit, no `DataExchangeRunGuard`/`DataExchangeRunHandler`/`OsoDossierReviewGuard`/lifecycle/OSO change, no OpenConnector change, no PHP change. ADR-001 (register-import) and ADR-031 (notification-dialect) are N/A; `migration.md` skipped (no schema/data impact).
- `AiFeature` EU AI Act governance register is NOT touched — Assistant ≠ AiFeature.
- ADR-004 for the new Vue: `NcSettingsSection` idiom, `t()` for all strings, no DOM `data-*` reads, no inline modal (none introduced).
- Full-navigation links only (no `this.$router` on the router-less Admin Settings mount).
- UI changes covered by Playwright; every added/modified delta-spec scenario carries an `@e2e` reference or `@e2e exclude <reason>` (Gate-19, diff-scoped).
- Dutch + English strings for all new user-facing labels; i18n keys are English source (ADR-005/ADR-007).
- `nc-immutable` cache-bust: bump `appinfo/info.xml` `<version>` so the rebuilt bundle is served.
- `openspec validate relocate-dataexchange-remove-assistant` passes.

## Verification

- [x] All tasks checked off
- [x] `openspec validate relocate-dataexchange-remove-assistant --strict` passes
- [x] Manual browser check: Assistant nav entry + chat FAB gone; Admin Settings "Data exchange" section links open the SPA pages; data-exchange deep links + OSO review still resolve
