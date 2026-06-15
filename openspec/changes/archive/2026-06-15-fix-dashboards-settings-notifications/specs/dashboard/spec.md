---
status: draft
---

# Role-Aware Dashboards

## Purpose

Make the dashboard implementation match the long-standing "Role-Aware Dashboards" spec and ADR-009 §6: one role-aware dashboard component that re-renders for the active role, reached from a single `Dashboards` menu item — not separate per-role menus and not a single admin-only KPI grid. Remove the `CnDashboardPage`-in-`CnDashboardPage` nesting that produces three stacked "Dashboard" headings (the hydra dashboard-antipattern).

## MODIFIED Requirements

### Requirement: Per-resolved-role default dashboard
The system MUST present a different default dashboard per resolved role (teacher / student / parent / HR / compliance / inspector / mentor / manager) through **one role-aware component reached from a single `Dashboards` menu entry**, per ADR-009 §6. The component MUST select the view from the active user's resolved `primaryRole` (administrator → admin overview, instructor/manager → teacher view, learner → student view), MUST offer an in-component role switcher to users who hold more than one role, and MUST NOT expose a separate top-level menu item per role. The application root route MUST land each user on the dashboard view matching their resolved role; when the role cannot be resolved the system MUST fall back to the least-privileged (student) view.

#### Scenario: Learner lands on the student dashboard
- **GIVEN** a signed-in user whose resolved `primaryRole` is `learner`
- **WHEN** they open the Scholiq app root
- **THEN** the single Dashboards view renders the student dashboard (my enrolments, my grades, due assignments, mandatory training)
- **AND** no admin KPI grid and no second/third "Dashboard" heading is shown

#### Scenario: Instructor sees the teacher dashboard
- **GIVEN** a signed-in user whose resolved `primaryRole` is `instructor`
- **WHEN** they open the Dashboards menu entry
- **THEN** the teacher dashboard renders (my courses, assignments to grade, sessions to mark, my cohorts)

#### Scenario: Multi-role user switches view
- **GIVEN** a user who is both `instructor` and `admin`
- **WHEN** they use the in-component role switcher
- **THEN** the same Dashboards page re-renders the chosen role's view without navigating to a different menu item

### Requirement: Use @conduction/nextcloud-vue dashboard components
The system MUST use `@conduction/nextcloud-vue` dashboard components (`CnDashboardPage` et al.) — no custom equivalents. A `type: "dashboard"` manifest page MUST declare its tiles directly in `config.widgets` / `config.layout` / `slots`, each slot resolving to a plain widget component (KPI card, list, chart). A dashboard page or any widget component it hosts MUST NOT render a nested `CnDashboardPage` (the dashboard-in-dashboard antipattern); exactly one `CnDashboardPage` MUST render per dashboard route.

#### Scenario: Single CnDashboardPage per route
- **GIVEN** the Scholiq dashboard route is rendered
- **WHEN** the component tree is inspected
- **THEN** exactly one `CnDashboardPage` is present and the page heading appears once

#### Scenario: Widgets declared on the manifest page
- **GIVEN** the manifest `Dashboards` page
- **WHEN** its `config` is read
- **THEN** each KPI / manage tile is a distinct entry in `config.widgets` with a matching `slots["widget-<id>"]` mapping to its own widget component, and there is no single wrapper widget that re-renders the whole dashboard
