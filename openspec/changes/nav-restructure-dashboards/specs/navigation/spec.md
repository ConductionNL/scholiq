---
status: proposed
---

# Navigation — Dashboard-first IA restructure (amends ADR-009, supersedes ADR-044 cards-collapse)

**Status:** proposed
**Scope:** scholiq
**Supersedes:** learning-people-cards-collapse (REQ-LPC-001, REQ-LPC-002 cards-collapse behaviour)
**Amends:** ADR-009 (§1 top-level set, §5 adapters-in-settings, §6 dashboards placement)

## Purpose

Phase A information-architecture restructure of Scholiq's left navigation: the app opens on a dashboard, the `Insight` group is dissolved into a top-level `Dashboard` and top-level `Compliance`, `Learning` and `People` become navigable domain dashboards whose former leaves return as collapsible sub-children, `App health` stops being an in-app surface, `Features & roadmap` drops to the footer, and `School-year rollover` moves into the Settings foldout. Nav is driven declaratively by `src/menu-layout.json` (ADR-044 post-merge layout) over `src/manifest.json` + `src/manifest.d/*.json` (ADR-037). This spec supersedes the ADR-044 cards-collapse behaviour that turned `Learning`/`People` into tile-grid landing pages.

## ADDED Requirements

### Requirement: App health is not an in-app navigation surface
The system MUST NOT expose an `App health` page or menu entry inside the Scholiq app. The `AdminHealthMenu` menu entry and the `AdminHealth` page (route `/admin/health`) MUST be removed from `src/manifest.json`, and the `ScholiqAdminHealth` component and its registry entry MUST be removed. Schema/data-health MUST be governed from OpenRegister's admin Data-health settings form instead; Scholiq MUST NOT duplicate it.

#### Scenario: No App-health menu entry renders
- **GIVEN** an authenticated admin user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** no `App health` menu entry is present in the navigation, in the settings foldout, or in the footer

#### Scenario: The App-health route no longer resolves in-app
<!-- @e2e exclude Negative-route/absence assertion — verified by the manifest unit test (no `AdminHealth` page id, no `/admin/health` route) and the registry unit test (no `ScholiqAdminHealth` entry), not a positive DOM behaviour. -->
- **GIVEN** the App-health removal is deployed
- **WHEN** the manifest `pages[]` and `menu[]` and `src/registry.js` are inspected
- **THEN** there is no `AdminHealth` page, no `AdminHealthMenu` menu entry, and no `ScholiqAdminHealth` registry entry or import

### Requirement: The app opens on a top-level Dashboard and the Insight group is dissolved
The system MUST land each user on the role-aware `Dashboard` page (route `/`, component `ScholiqDashboards`) at app root, MUST surface `Dashboard` as a top-level navigation item, and MUST surface `Compliance` as a top-level navigation item. The `GroupInsight` group MUST NOT appear as a navigation group once emptied; the relocation MUST be expressed in `src/menu-layout.json#relocations` (`Dashboard` and `Compliance` → top level).

#### Scenario: Dashboard and Compliance are top-level items
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Dashboard` appears as a top-level navigation item and `Compliance` appears as a top-level navigation item (subject to its existing role gating)
- **AND** no `Insight` group is shown

#### Scenario: App root lands on the dashboard
- **GIVEN** an authenticated user
- **WHEN** they open the Scholiq app root
- **THEN** the role-aware `ScholiqDashboards` page renders and the active navigation item is the top-level `Dashboard`

### Requirement: Learning is a navigable domain dashboard with collapsible sub-children
The `GroupLearning` menu entry MUST be a parent that is both navigable (a `route` landing on the `LearningDashboard` domain dashboard, `/learning`) and collapsible over its six child leaves. The six leaves (`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) MUST be removed from `src/menu-layout.json#removals` so they render as sub-items, and the `learning-dashboard.json` fragment MUST repoint `GroupLearning.route` at the `LearningDashboard` page. The former `LearningCards` tile-grid landing MUST be retired.

#### Scenario: Learning parent is navigable and expandable
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Learning` shows a disclosure control and, when activated as a link, navigates to `/learning` (the `LearningDashboard`)
- **AND** expanding `Learning` reveals its six sub-items: Courses, Curriculum, Learning plans, Assignments, Assessments, Grades

#### Scenario: Learning sub-items navigate to their existing routes
- **GIVEN** the `Learning` group is expanded
- **WHEN** the user activates the `Courses` sub-item
- **THEN** the browser navigates to the `Courses` route (`/courses`)
- **AND** activating the `Grades` sub-item navigates to the `GradeEntries` route

### Requirement: People is a navigable domain dashboard with collapsible sub-children
The `GroupPeople` menu entry MUST be a parent that is both navigable (a `route` landing on the `PeopleDashboard` domain dashboard, `/people`) and collapsible over its four child leaves. The four leaves (`LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials`) MUST be removed from `src/menu-layout.json#removals` so they render as sub-items, and the `people-dashboard.json` fragment MUST repoint `GroupPeople.route` at the `PeopleDashboard` page. The former `PeopleCards` tile-grid landing MUST be retired.

#### Scenario: People parent is navigable and expandable
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `People` shows a disclosure control and, when activated as a link, navigates to `/people` (the `PeopleDashboard`)
- **AND** expanding `People` reveals its four sub-items: Learners, Enrolments, Attendance, Credentials

#### Scenario: People sub-items navigate to their existing routes
- **GIVEN** the `People` group is expanded
- **WHEN** the user activates the `Learners` sub-item
- **THEN** the browser navigates to the `LearnerProfiles` route
- **AND** activating the `Credentials` sub-item navigates to the `Credentials` route (`/credentials`)

### Requirement: Features & roadmap lives in the footer beside Documentation
The `FeaturesRoadmapMenu` entry MUST be removed from `src/menu-layout.json#settingsSection` so it falls back to its base-manifest `section:"footer"` placement beside `Documentation`, matching the fleet convention (pipelinq/opencatalogi/docudesk).

#### Scenario: Features & roadmap renders in the footer
- **GIVEN** an authenticated user opens the Scholiq app
- **WHEN** the left navigation renders
- **THEN** `Features & roadmap` appears in the footer section next to `Documentation`
- **AND** it is not present in the settings foldout

### Requirement: School-year rollover lives in the Settings foldout
The `Rollover` entry (route `RolloverWizard`, admin-gated) MUST be added to `src/menu-layout.json#settingsSection` so it renders inside the Nextcloud settings foldout (gear icon) rather than as a top-level item.

#### Scenario: Rollover renders in the settings foldout for an admin
- **GIVEN** an authenticated admin user opens the Scholiq app
- **WHEN** they open the settings foldout
- **THEN** `School-year rollover` appears inside the foldout
- **AND** it is not present as a top-level navigation item

### Requirement: INVARIANT — all retained routes and deep links remain reachable after the restructure
The restructure MUST NOT remove or rename any retained `pages[]` route, and MUST NOT leave any manifest `deepLinks` entry pointing at a removed route. All ten former Learning/People leaf pages MUST remain declared and directly deep-linkable, and the four `deepLinks` entries (`course`, `enrolment`, `learner-profile`, `credential`) MUST resolve. Only the `AdminHealth` route is removed by this change; no other route may 404 as a result.

#### Scenario: Former leaf routes remain directly deep-linkable
- **GIVEN** the restructure is deployed
- **WHEN** a user navigates directly to `/courses`, `/enrolments`, `/credentials`, or any other retained leaf route
- **THEN** the corresponding page renders without error and without redirect to a landing page or dashboard

#### Scenario: Manifest deep links reference no removed route
<!-- @e2e exclude Static manifest assertion — the four deepLinks urlTemplates are checked by tests/validate-manifest.js / the manifest unit test against pages[]; not a runtime DOM behaviour. -->
- **GIVEN** the restructure is deployed
- **WHEN** the manifest `deepLinks` array is inspected
- **THEN** every `urlTemplate` targets a route that still exists in `pages[]`
- **AND** none targets the removed `AdminHealth` (`/admin/health`) route

## Non-Functional Requirements

- **Performance:** The nav restructure is declarative-layout + two `CnDashboardPage` components and MUST NOT add a network round-trip to render the left navigation.
- **Accessibility:** Collapsible parents MUST expose an accessible disclosure control (WCAG 2.1 AA); a parent that is both a link and a disclosure MUST keep both affordances keyboard-operable, per `CnAppNav`.
- **Internationalization:** All navigation labels and new dashboard strings MUST be provided in Dutch (`nl_NL`) and English (`en_US`) (ADR-005/ADR-007).

## Acceptance Criteria

- [ ] `Dashboard` and `Compliance` are top-level; `Insight` group is gone.
- [ ] `Learning`/`People` are navigable parents with their leaves as collapsible sub-items landing on domain dashboards.
- [ ] `App health` menu + page + `ScholiqAdminHealth` registry entry are removed; no `/admin/health` route.
- [ ] `Features & roadmap` is in the footer; `School-year rollover` is in the settings foldout.
- [ ] All retained leaf routes and the four `deepLinks` resolve; no removed route referenced in e2e.

## Notes

- Supersedes ADR-044 cards-collapse (REQ-LPC-001/002): `Learning`/`People` are no longer tile-grid card pages but navigable domain dashboards with real sub-children. `CnAppNav` (`../nextcloud-vue/src/components/CnAppNav/CnAppNav.vue`) already renders a parent that is simultaneously navigable (`:to`) and collapsible (`:allow-collapse` when it has visible children).
- Amends ADR-009: dashboard-first landing; `Insight` dissolved into top-level `Dashboard` + `Compliance`; `Learning`/`People` navigable domain dashboards; adapters/config + rollover in the settings foldout; `Features & roadmap` in the footer. The ADR file is updated as an implementation task.
