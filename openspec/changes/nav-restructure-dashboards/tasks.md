# Tasks: nav-restructure-dashboards

<!-- Hydra cap: MAX 20 unindented `- [ ]` lines. This file has 8 tasks × 2 = 16. -->

## Implementation Tasks

### Task 1: Remove the App-health surface
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-app-health-is-not-an-in-app-navigation-surface`
- **files**: `src/manifest.json`, `src/registry.js`, `src/views/ScholiqAdminHealth.vue`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN inspected THEN no `AdminHealthMenu` menu entry and no `AdminHealth` page (`/admin/health`) remain
  - GIVEN `src/registry.js` WHEN inspected THEN the `ScholiqAdminHealth` entry and its import are gone and `src/views/ScholiqAdminHealth.vue` is deleted
- [x] Implement
- [x] Test

### Task 2: Dissolve the Insight group — Dashboard and Compliance to top level
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-the-app-opens-on-a-top-level-dashboard-and-the-insight-group-is-dissolved`
- **files**: `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `menu-layout.json#relocations` WHEN `Dashboard` and `Compliance` are lifted to top level THEN the emptied `GroupInsight` no longer renders
  - GIVEN app root WHEN opened THEN `ScholiqDashboards` renders and top-level `Dashboard` is active
- [x] Implement
- [x] Test

### Task 3: Learning navigable domain dashboard + collapsible sub-children
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard`
- **files**: `src/views/LearningDashboard.vue`, `src/manifest.d/learning-dashboard.json` (renamed from `learning-cards.json`), `src/menu-layout.json`, `src/registry.js`, `src/components/learning/LearningCards.vue`
- **acceptance_criteria**:
  - GIVEN `/learning` WHEN rendered THEN exactly one `CnDashboardPage` shows learning KPIs + manage-lists (pattern per `src/views/ScholiqDashboards.vue`)
  - GIVEN the nav WHEN `Learning` renders THEN it is navigable to `/learning` AND expands to its six leaves (removed from `menu-layout.json#removals`); `LearningCards.vue` is retired and `LearningDashboard` registered
- [x] Implement
- [x] Test

### Task 4: People navigable domain dashboard + collapsible sub-children
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard`
- **files**: `src/views/PeopleDashboard.vue`, `src/manifest.d/people-dashboard.json` (renamed from `people-cards.json`), `src/menu-layout.json`, `src/registry.js`, `src/components/people/PeopleCards.vue`
- **acceptance_criteria**:
  - GIVEN `/people` WHEN rendered THEN exactly one `CnDashboardPage` shows people KPIs + manage-lists
  - GIVEN the nav WHEN `People` renders THEN it is navigable to `/people` AND expands to its four leaves (removed from `menu-layout.json#removals`); `PeopleCards.vue` is retired and `PeopleDashboard` registered
- [x] Implement
- [x] Test

### Task 5: Features & roadmap to footer; School-year rollover to settings foldout
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-features-roadmap-lives-in-the-footer-beside-documentation`
- **files**: `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `menu-layout.json#settingsSection` WHEN `FeaturesRoadmapMenu` is removed THEN it renders in the footer beside `Documentation`
  - GIVEN `menu-layout.json#settingsSection` WHEN `Rollover` is added THEN `School-year rollover` renders in the settings foldout and not as a top-level item
- [x] Implement
- [x] Test

### Task 6: Update Gate-19 e2e coverage and verify deep links
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-invariant-all-retained-routes-and-deep-links-remain-reachable-after-the-restructure`
- **files**: `tests/e2e/pages.spec.ts`, `tests/e2e/spec-coverage/nextcloud-app.spec.ts`, `tests/e2e/spec-coverage/dashboard.spec.ts`
- **acceptance_criteria**:
  - GIVEN `tests/e2e/pages.spec.ts` WHEN updated THEN `{ name: 'AdminHealth', path: '#/admin/health' }` is removed and no test references a removed route
  - GIVEN the restructured nav + two domain dashboards WHEN e2e runs THEN each added/modified spec scenario is referenced by a Playwright `@e2e` marker (or carries `@e2e exclude`), AND the four manifest `deepLinks` (course/enrolment/learner-profile/credential) resolve
- [x] Implement
- [x] Test

### Task 7: Amend ADR-009 and record the ADR-044 supersession
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-the-app-opens-on-a-top-level-dashboard-and-the-insight-group-is-dissolved`
- **files**: `openspec/architecture/adr-009-information-architecture.md`
- **acceptance_criteria**:
  - GIVEN ADR-009 WHEN amended THEN it documents the dashboard-first top level (Insight dissolved into top-level Dashboard + Compliance), Learning/People as navigable domain dashboards, rollover in settings, and Features & roadmap in the footer
  - GIVEN the ADR WHEN updated THEN it records that this change supersedes ADR-044's cards-collapse (REQ-LPC-001/002) while preserving REQ-LPC-003 route reachability
- [x] Implement
- [x] Test

### Task 8: i18n strings for the new dashboards and nav labels
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard`
- **files**: `l10n/nl.json`, `l10n/en.json`, `src/views/LearningDashboard.vue`, `src/views/PeopleDashboard.vue`
- **acceptance_criteria**:
  - GIVEN new KPI/manage-list labels in `LearningDashboard.vue`/`PeopleDashboard.vue` WHEN added THEN Dutch (`nl_NL`) and English (`en_US`) strings exist for each (ADR-005/ADR-007)
  - GIVEN i18n keys WHEN authored THEN keys are the ENGLISH source string, never Dutch
- [x] Implement
- [x] Test

## Quality checklist

<!-- Reminders for the builder — plain bullets, NOT tracked checkboxes. -->

- Acceptance criteria: `Dashboard`/`Compliance` top-level, `Insight` gone; `Learning`/`People` navigable-and-collapsible landing on `LearningDashboard`/`PeopleDashboard`; App-health surface removed; `Features & roadmap` in footer; `School-year rollover` in settings foldout; all retained routes + 4 deepLinks resolve.
- No dashboard-in-dashboard: each new page renders exactly one `CnDashboardPage` and is never referenced as a widget slot on another dashboard (hydra dashboard-antipattern gate).
- ADR-036: `LearningDashboard`/`PeopleDashboard` registered as `kind:"page"` in `src/registry.js`; removed entries (`ScholiqAdminHealth`, `LearningCards`, `PeopleCards`) and their imports gone.
- ADR-037: feature edits via `src/manifest.d/*.json`; only the App-health base *page removal* touches `src/manifest.json`.
- ADR-004 for any new Vue: `IInitialState`/`loadState` (no DOM data-attribute reads); `NcSelect` with `inputLabel` if used; modals in own files.
- No OpenRegister schema/register/seed-data change; no `_registers.json` edit; no backend/PHP change (ADR-001/ADR-031 N/A).
- UI changes covered by Playwright browser tests; new/changed spec scenarios carry `@e2e` markers or `@e2e exclude` (Gate-19, diff-scoped).
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for new user-facing strings; i18n keys are English source (ADR-005/ADR-007).
- Feature documentation updated in `docs/` if user-facing (ADR-010).
- `openspec validate nav-restructure-dashboards` passes.
