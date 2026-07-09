---
status: proposed
---

# Dashboard — Learning and People domain dashboards

**Status:** proposed
**Scope:** scholiq
**Amends:** ADR-009 §6 (dashboards), by adding two domain dashboards alongside the role-aware dashboard

## Purpose

Add two domain dashboards — `LearningDashboard` and `PeopleDashboard` — as the landing pages for the `Learning` and `People` navigable groups (see the `navigation` capability). Each is a single `CnDashboardPage` following the existing `src/views/ScholiqDashboards.vue` + `src/views/widgets/*` pattern (KPI tiles + manage-list widgets), replacing the ADR-044 tile-grid card pages. They MUST NOT reintroduce the dashboard-in-dashboard antipattern already forbidden by this spec's "Use @conduction/nextcloud-vue dashboard components" requirement.

## ADDED Requirements

### Requirement: Learning domain dashboard
The system MUST provide a `LearningDashboard` page (component `LearningDashboard`, route `/learning`) rendered as exactly one `CnDashboardPage`. It MUST surface the learning domain's KPIs (courses, curriculum/programmes, assignments, assessments, grades) as KPI tiles and MUST offer manage-list entry points into the learning leaves (e.g. courses, assignments). It MUST reuse existing `src/views/widgets/*` components (KPI cards, `ManageListWidget`) rather than custom equivalents, and MUST NOT be rendered as a widget inside another dashboard.

#### Scenario: Learning dashboard renders one CnDashboardPage with learning KPIs
- **GIVEN** an authenticated user navigates to `/learning`
- **WHEN** the `LearningDashboard` renders
- **THEN** exactly one `CnDashboardPage` is present and its heading appears once
- **AND** learning-domain KPI tiles (e.g. courses, assignments, assessments, grades) are shown as distinct widgets

#### Scenario: Learning dashboard manage-lists link into the learning leaves
- **GIVEN** the user is on the `LearningDashboard`
- **WHEN** they use a manage-list entry point (e.g. the courses list)
- **THEN** the browser navigates to the corresponding learning leaf route (e.g. `/courses`)

#### Scenario: Learning dashboard is not a nested dashboard
<!-- @e2e exclude Structural/anti-pattern assertion — enforced by the hydra dashboard-antipattern gate and the manifest/component unit tests (LearningDashboard is a page component, never referenced as a widget slot on another dashboard); not a positive DOM behaviour distinct from the single-CnDashboardPage scenario above. -->
- **GIVEN** the `LearningDashboard` component tree
- **WHEN** it is inspected
- **THEN** it renders a single `CnDashboardPage` and no widget it hosts renders a nested `CnDashboardPage`

### Requirement: People domain dashboard
The system MUST provide a `PeopleDashboard` page (component `PeopleDashboard`, route `/people`) rendered as exactly one `CnDashboardPage`. It MUST surface the people domain's KPIs (learners, enrolments, attendance, credentials) as KPI tiles and MUST offer manage-list entry points into the people leaves (e.g. learners, enrolments). It MUST reuse existing `src/views/widgets/*` components rather than custom equivalents, and MUST NOT be rendered as a widget inside another dashboard.

#### Scenario: People dashboard renders one CnDashboardPage with people KPIs
- **GIVEN** an authenticated user navigates to `/people`
- **WHEN** the `PeopleDashboard` renders
- **THEN** exactly one `CnDashboardPage` is present and its heading appears once
- **AND** people-domain KPI tiles (e.g. learners, enrolments, attendance, credentials) are shown as distinct widgets

#### Scenario: People dashboard manage-lists link into the people leaves
- **GIVEN** the user is on the `PeopleDashboard`
- **WHEN** they use a manage-list entry point (e.g. the learners list)
- **THEN** the browser navigates to the corresponding people leaf route (e.g. `/learner-profiles`)

## Non-Functional Requirements

- **Performance:** Each domain dashboard MUST lazy-read its KPI counts through the existing widget data path (OpenRegister object counts) without blocking initial nav render.
- **Accessibility:** Both dashboards MUST meet WCAG 2.1 AA; KPI tiles MUST expose accessible labels.
- **Internationalization:** All KPI and manage-list labels MUST be provided in Dutch (`nl_NL`) and English (`en_US`) (ADR-005/ADR-007).

## Acceptance Criteria

- [ ] `LearningDashboard` renders one `CnDashboardPage` with learning KPIs + manage-lists at `/learning`.
- [ ] `PeopleDashboard` renders one `CnDashboardPage` with people KPIs + manage-lists at `/people`.
- [ ] Neither dashboard is referenced as a widget slot on another dashboard (no dashboard-in-dashboard).
- [ ] Both reuse existing `src/views/widgets/*` components; no custom dashboard equivalents introduced.

## Notes

- Follows `src/views/ScholiqDashboards.vue` as the reference implementation (single `CnDashboardPage`, `widgets`/`layout` arrays, `#widget-<id>` slots mapping to KPI/manage widgets).
- Replaces the ADR-044 `LearningCards.vue` / `PeopleCards.vue` tile-grid landing pages, which are retired by this change.
