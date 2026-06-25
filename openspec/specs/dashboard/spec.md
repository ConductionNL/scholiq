---
slug: dashboard
title: Role-Aware Dashboards
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-012, adr-009]   # TODO until ADRs land
created: 2026-05-11
openspec_changes:
  - fix-dashboards-settings-notifications
---

# Role-Aware Dashboards

## Purpose

Present a single role-aware dashboard surface (ADR-009 §6): one component, reached from one `Dashboards` menu entry, that re-renders for the active user's resolved role (admin / teacher / student) with an in-component switcher for multi-role users, built exclusively on `@conduction/nextcloud-vue` dashboard primitives. Exactly one `CnDashboardPage` renders per route (no dashboard-in-dashboard nesting), and heavy cross-tenant analytics deep-link into launchpad rather than being reimplemented.

## Why
"Analytics dashboard" (#17, 39 demand) and "Student Analytics" (#18, 34 demand) score in the top 20 canonical features. Insight #16: OSS LMS leaders share dated UX — a modern Vue / NL-Design dashboard surface is the structural differentiator. Eight stories across six roles (mentor pattern view, manager team progress, board compliance %, board renewal report, principal Cito overview, parent grade digest, pupil grade-impact view, civil-servant RADIO progress) anchor this spec.

## What
Per-role landing dashboards composed via `@conduction/nextcloud-vue` primitives (CnDashboardPage, CnIndexPage) over Pinia stores reading OpenRegister + GraphQL: **teacher** (cohort distribution, soft-publish queue), **student/pupil** (next exams, grade impact, RADIO progress), **parent** (digest preferences, OPP signing tasks, sick reports), **HR/manager** (team learning progress, time-to-competence), **compliance officer / board** (live coverage % per regulation, NIS2 board proof, renewal status), **inspector / principal** (Cito overview per leerjaar, audit log access). Heavier analytics (cross-tenant trends) delegate to launchpad.

## User Stories
- As a mentor, I want a dashboard with absence patterns of my mentor class so I can spot a pupil with rising absence early.
- As a manager, I want one dashboard with each report row showing assigned, in-progress, completed, overdue counts plus a heat-map by skill area.
- As a board member, I want live coverage % per regulation (BIO, AVG, NIS2, integriteit) with red/amber/green bands and 12-month trend.
- As a school principal, I want to export an inspectie-ready overview of Cito results per leerjaar to demonstrate basisvaardigheden to the Onderwijsinspectie.
- As a pupil, I want to see each new grade together with its weight and impact on my period average so I understand what to focus on next.

## Acceptance Criteria
- GIVEN a user logs in, WHEN their role resolves, THEN the matching dashboard layout loads as the default route (no manual selection).
- GIVEN a board member opens the compliance dashboard, WHEN data loads, THEN coverage % per regulation renders with red/amber/green bands within 2 seconds.
- GIVEN a manager opens the team tab, WHEN the page loads, THEN every report row shows assigned/in-progress/completed/overdue counts plus a skill-area heat map.
- GIVEN heavier cross-tenant analytics are needed, WHEN the user requests them, THEN the dashboard deep-links into launchpad (single-sign-on session shared).

## Requirements

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

### Requirement: Delegate heavy analytics to launchpad via deep links
The system MUST delegate cross-tenant or heavy-aggregation analytics to launchpad via deep links rather than reimplementing.

#### Scenario: Heavy analytics deep-link to launchpad
<!-- @e2e exclude Cross-app deep-link into launchpad; launchpad is not provisioned in the scholiq e2e environment, so the target cannot be driven. The non-reimplementation guardrail is a structural review concern, not a scholiq DOM behaviour. -->
- **GIVEN** a user viewing a Scholiq dashboard that surfaces a cross-tenant or heavy-aggregation analytics affordance
- **WHEN** the user requests that analytics view
- **THEN** the dashboard deep-links into launchpad (shared single-sign-on session) rather than rendering a Scholiq-local cross-tenant aggregation

## Standards
NL Design System, WCAG 2.1 AA, Schema.org `Dataset` / `Observation`, Caliper Analytics for event source.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `RoleAssignment`, `DashboardPreference`. Reads from every other spec's entities via OpenRegister read-only views.

## Out of Scope
- Custom-report builder (V2; launchpad territory).
- Cross-tenant benchmarking / sectoraal vergelijken (launchpad + Specter feeds).
- Mobile-native apps (responsive Vue is MVP).
