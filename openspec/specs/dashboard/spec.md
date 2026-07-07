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

Present per-role dashboard surfaces (ADR-009 §6) as **three group-gated menu items** — Administration / Teaching / My learning — each routing to the shared dashboard component in its role view (admin / teacher / student); menu visibility follows `scholiq-{role}` Nextcloud group membership (admins see all three) and there is no in-page role switcher. Built exclusively on `@conduction/nextcloud-vue` dashboard primitives. Exactly one `CnDashboardPage` renders per route (no dashboard-in-dashboard nesting), and heavy cross-tenant analytics deep-link into launchpad rather than being reimplemented.

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

### Requirement: Per-role group-gated dashboard menu items
The system MUST present a **separate top-level dashboard menu item per role** — Administration (admin), Teaching (teacher), My learning (student) — per ADR-009 §6. Each item MUST be visible only to users whose resolved dashboard-view set includes that role, derived server-side from `scholiq-{role}` Nextcloud group membership (with the NC admin group short-circuiting to all three); an admin therefore sees all three items, and the view set always includes `student` so every Scholiq user reaches their own My-learning view. The system MUST NOT render an in-page role switcher. Each menu item routes to the shared dashboard component rendering that role's view (exactly one `CnDashboardPage` per route). The application root route MUST land each user on the dashboard view matching their resolved `primaryRole`; when the role cannot be resolved the system MUST fall back to the least-privileged (student) view.

#### Scenario: Learner sees only the My learning item and lands on it
- **GIVEN** a signed-in user in the `scholiq-student` group only
- **WHEN** they open the Scholiq app
- **THEN** the navigation shows a single **My learning** dashboard item (no Administration or Teaching item)
- **AND** the app root lands on the student dashboard (my enrolments, my grades, due assignments, mandatory training)
<!-- @e2e exclude Group-gated nav visibility requires provisioning a `scholiq-student`-only Nextcloud user and logging in as them; the scholiq e2e harness runs a single admin session and cannot switch group membership per test. Verified live instead. -->

#### Scenario: Instructor sees Teaching + My learning
- **GIVEN** a signed-in user in the `scholiq-teacher` group
- **WHEN** they open the navigation
- **THEN** a **Teaching** item and a **My learning** item are shown (no Administration item)
- **AND** the Teaching item renders the teacher dashboard (my courses, assignments to grade, sessions to mark, my cohorts)
<!-- @e2e exclude Requires a `scholiq-teacher` group member session (multi-user / per-test group membership) that the single-admin scholiq e2e harness cannot provision. Verified live instead. -->

#### Scenario: Admin sees all three dashboard items
- **GIVEN** a signed-in user in the Nextcloud admin group
- **WHEN** they open the navigation
- **THEN** **Administration**, **Teaching** and **My learning** items are all shown
- **AND** each routes to its own dashboard view without any in-page role switcher
<!-- @e2e exclude Asserts three group-gated nav items are simultaneously visible to an NC-admin-group member; the admin short-circuit is a server-side group resolution not reproducible as a pure scholiq DOM flow in the current e2e harness. Verified live instead. -->

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
