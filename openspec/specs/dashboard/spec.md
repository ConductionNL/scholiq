---
slug: dashboard
title: Role-Aware Dashboards
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-012]   # TODO until ADRs land
created: 2026-05-11
---

# Role-Aware Dashboards

@e2e exclude Pure backend/data-model spec. All requirements define role resolution and dashboard component usage — no `#### Scenario:` headings exist in this spec.

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
The system MUST present a different default dashboard per resolved role (teacher / student / parent / HR / compliance / inspector / mentor / manager).

### Requirement: Use @conduction/nextcloud-vue dashboard components
The system MUST use `@conduction/nextcloud-vue` dashboard components (CnDashboardPage et al.) — no custom equivalents.

### Requirement: Delegate heavy analytics to launchpad via deep links
The system MUST delegate cross-tenant or heavy-aggregation analytics to launchpad via deep links rather than reimplementing.

## Standards
NL Design System, WCAG 2.1 AA, Schema.org `Dataset` / `Observation`, Caliper Analytics for event source.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `RoleAssignment`, `DashboardPreference`. Reads from every other spec's entities via OpenRegister read-only views.

## Out of Scope
- Custom-report builder (V2; launchpad territory).
- Cross-tenant benchmarking / sectoraal vergelijken (launchpad + Specter feeds).
- Mobile-native apps (responsive Vue is MVP).
