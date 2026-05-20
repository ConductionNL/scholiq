---
slug: dashboard
title: Role-Aware Dashboards
status: implemented
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-20
updated: 2026-05-20
wedge_scope: Phase 1 — ComplianceBoard, LearnerHome, AdminHealth; remaining role views deferred to Phase 2
---

# Dashboard — Formal Requirements

## Overview

The dashboard is the default landing view for all authenticated Scholiq users. Role detection MUST be automatic: the system resolves `LearnerProfile.primaryRole` (calculated by `RoleSelector` from `RoleAssignment` objects) and routes the user to the appropriate per-role dashboard page without any user interaction. Nine dashboard pages are declared in `src/manifest.json`; Phase 1 implements three fully (`ComplianceBoard`, `LearnerHome`, `AdminHealth`) and stubs the remaining six with stable routes. All dashboard data is consumed from existing schemas via `x-openregister-widgets` / `x-openregister-aggregations` / `x-openregister-calculations` — no custom backend analytics. Cross-tenant and heavy-aggregation analytics are delegated to MyDash via deep link.

---

## Requirements

### REQ-DA-001 — Automatic role-aware routing

The system MUST resolve the authenticated user's primary role from `LearnerProfile.primaryRole` (derived from `RoleAssignment` objects via `RoleSelector`) and navigate to the matching dashboard page without user interaction, within the initial page render (no secondary redirect).

#### Scenario DA-001-A: Compliance officer lands on ComplianceBoard
```
GIVEN a user with a RoleAssignment of role='compliance-officer' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST resolve LearnerProfile.primaryRole = 'compliance-officer'
  AND route to /compliance without user interaction
  AND ComplianceBoard dashboard MUST render as the default view
```

#### Scenario DA-001-B: Board member lands on ComplianceBoard
```
GIVEN a user with a RoleAssignment of role='board-member' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST resolve LearnerProfile.primaryRole = 'board-member'
  AND route to /compliance
  AND ComplianceBoard dashboard MUST render
```

#### Scenario DA-001-C: Learner lands on LearnerHome
```
GIVEN a user with a RoleAssignment of role='learner' only authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST resolve LearnerProfile.primaryRole = 'learner'
  AND route to /learner
  AND LearnerHome dashboard MUST render showing the learner's mandatory training list
```

#### Scenario DA-001-D: Admin lands on AdminHealth
```
GIVEN a user with NC admin rights (IGroupManager::isAdmin() = true) authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN RoleSelector MUST return 'admin' regardless of RoleAssignment records
  AND CnAppRoot MUST route to /admin/health
  AND AdminHealth dashboard MUST render with app health status
```

#### Scenario DA-001-E: No RoleAssignment defaults to learner
```
GIVEN an authenticated NC user has no RoleAssignment record in OpenRegister
WHEN CnAppRoot evaluates LearnerProfile.primaryRole
THEN the system MUST default to role 'learner' and route to /learner
  AND the LearnerHome dashboard MUST display a 'Maak uw profiel aan' banner
```

#### Scenario DA-001-F: Multi-role user gets highest-priority role
```
GIVEN a user has RoleAssignment records for both 'learner' and 'manager'
WHEN CnAppRoot evaluates LearnerProfile.primaryRole
THEN RoleSelector MUST return 'manager' (priority 4 > learner priority 1)
  AND the user MUST be routed to /manager/team
```

---

### REQ-DA-002 — ComplianceBoard: coverage % per regulation with RAG bands

The ComplianceBoard dashboard MUST display one coverage KPI per active regulation showing the regulation name, coverage % (from `Regulation.coveragePercent`), and a red/amber/green band. All KPI data MUST load within 2 seconds.

#### Scenario DA-002-A: Coverage KPIs render within 2 seconds
```
GIVEN a board member or compliance-officer navigates to /compliance
WHEN the dashboard data loads from x-openregister-widgets.coverageGrid on Regulation
THEN all regulation KPI cards MUST be rendered with coverage % values within 2 seconds
  AND red/amber/green band indicators MUST reflect ragRedThreshold and ragAmberThreshold
```

#### Scenario DA-002-B: Red band triggers visual alert
```
GIVEN a regulation has coveragePercent < ragRedThreshold (default 70)
WHEN the ComplianceBoard renders
THEN the regulation KPI card MUST render with a red visual indicator
  AND a warning label MUST appear signalling the coverage shortfall
  AND WCAG 2.1 AA MUST be met (color is NOT the sole indicator — icon or text also present)
```

#### Scenario DA-002-C: Green band indicates full compliance
```
GIVEN a regulation has coveragePercent >= ragAmberThreshold (default 90)
WHEN the ComplianceBoard renders
THEN the regulation KPI card MUST render with a green visual indicator
  AND no warning label MUST appear
```

---

### REQ-DA-003 — ComplianceBoard: 12-month compliance trend delegation

The ComplianceBoard MUST include a "View in MyDash" action that navigates to the MyDash Scholiq analytics surface. The dashboard MUST NOT re-implement any cross-tenant or historical-trend analytics that MyDash provides.

#### Scenario DA-003-A: "View in MyDash" visible when MyDash is installed
```
GIVEN MyDash is installed on the NC instance
WHEN a compliance-officer or board-member views the ComplianceBoard
THEN a "View in MyDash" action MUST be visible
  AND clicking it MUST navigate to /index.php/apps/mydash/#/scholiq-analytics?tenant={tenantId}
  AND the NC session MUST be shared (no re-login)
```

#### Scenario DA-003-B: "View in MyDash" hidden when MyDash is not installed
```
GIVEN MyDash is NOT installed on the NC instance
WHEN the ComplianceBoard renders
THEN the "View in MyDash" action MUST NOT render
  AND no error MUST be thrown
```

---

### REQ-DA-004 — LearnerHome: mandatory training task list

The LearnerHome dashboard MUST display a task list of mandatory Enrolments for the authenticated learner, sorted by `dueDate` ascending. Each row MUST show: course name, regulation slug, status badge (`ragStatus`), `dueDate`, `daysRemaining`, and a "Start / Hervatten" action button.

#### Scenario DA-004-A: Learner sees mandatory training list sorted by due date
```
GIVEN a learner has 3 mandatory Enrolments (1 completed, 1 due in 5 days, 1 overdue)
WHEN they navigate to /learner
THEN the task list MUST show all 3 Enrolments sorted by dueDate ascending
  AND the overdue Enrolment MUST display ragStatus='red' with an 'Achterstallig' badge
  AND the 5-day Enrolment MUST display ragStatus='amber' with a 'Binnenkort' badge
  AND the completed Enrolment MUST display a green 'Voltooid' badge
```

#### Scenario DA-004-B: Learner starts a course from the dashboard
```
GIVEN a learner clicks "Start" on a mandatory Enrolment row
WHEN the action fires
THEN the manifest action MUST navigate to the LessonPlayer page for the first uncompleted lesson
  AND the LessonPlayer MUST render that lesson's content
```

#### Scenario DA-004-C: Empty state when learner has no mandatory enrolments
```
GIVEN a learner has no mandatory Enrolments in state pending or active
WHEN they navigate to /learner
THEN the dashboard MUST render a CnEmptyState with message 'Geen verplichte trainingen'
  AND no error MUST be thrown
```

---

### REQ-DA-005 — AdminHealth: app observability endpoint

The AdminHealth dashboard MUST display the output of `GET /api/admin/health` showing: OpenRegister connection status, number of registered schemas, audit-trail event count in the last 24 hours, MyDash installation status, and timestamp of the last audit-pack export.

#### Scenario DA-005-A: AdminHealth renders all 5 health fields
```
GIVEN an admin navigates to /admin/health
WHEN the endpoint-stats widget fetches GET /api/admin/health
THEN all 5 fields MUST render: openregister_connected, schemas_registered,
  audit_trail_events_24h, mydash_installed, last_audit_pack_export
  AND the response MUST be received within 2 seconds
```

#### Scenario DA-005-B: AdminHealth shows OpenRegister disconnected
```
GIVEN OpenRegister is not reachable (connection error)
WHEN the AdminHealth dashboard renders
THEN openregister_connected MUST be false
  AND a warning indicator MUST be shown
  AND no unhandled exception MUST surface to the user
```

---

### REQ-DA-006 — Mentor absence-pattern dashboard (Phase 2 stub in Phase 1)

The MentorAbsence dashboard page MUST exist as a declared manifest route (`/mentor/absence`) in Phase 1 with a "Komt binnenkort" (`CnEmptyState`) placeholder. The full absence-pattern widget is implemented in Phase 2 using the Attendance schema.

#### Scenario DA-006-A: Mentor routed to MentorAbsence page
```
GIVEN a user with RoleAssignment role='mentor' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST route to /mentor/absence
  AND in Phase 1 the page MUST render a CnEmptyState with 'Komt binnenkort' message
  AND no 404 or unhandled error MUST occur
```

---

### REQ-DA-007 — Principal Cito overview & export (Phase 2 stub in Phase 1)

The PrincipalCito dashboard page MUST exist as a declared manifest route (`/principal/cito`) in Phase 1 with a "Komt binnenkort" placeholder. Phase 2 implements the full Cito-results widget and inspectie-ready export per leerjaar.

#### Scenario DA-007-A: Principal routed to PrincipalCito page
```
GIVEN a user with RoleAssignment role='principal' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST route to /principal/cito
  AND in Phase 1 the page MUST render a CnEmptyState with 'Komt binnenkort' message
  AND no 404 or unhandled error MUST occur
```

---

### REQ-DA-008 — Manager team progress dashboard (Phase 2 stub in Phase 1)

The ManagerTeam dashboard page MUST exist as a declared manifest route (`/manager/team`) in Phase 1 with a "Komt binnenkort" placeholder. Phase 2 implements assigned/in-progress/completed/overdue counts per report row and a skill-area heat map from Enrolment + LearnerProfile aggregations.

#### Scenario DA-008-A: Manager routed to ManagerTeam page
```
GIVEN a user with RoleAssignment role='manager' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN CnAppRoot MUST route to /manager/team
  AND in Phase 1 the page MUST render a CnEmptyState with 'Komt binnenkort' message
```

#### Scenario DA-008-B: Phase 2 — manager sees team rows with counts and heat map
```
GIVEN Phase 2 is implemented and a manager has 5 direct reports with enrolments
WHEN the manager opens the ManagerTeam dashboard
THEN every report row MUST show: assigned, in-progress, completed, overdue counts
  AND a skill-area heat map MUST render per the Enrolment aggregation widget
```

---

### REQ-DA-009 — Pupil/parent phase-2 views (stubs in Phase 1)

The PupilGrade (route `/learner/grades`) and ParentDigest (`/parent/digest`) dashboard pages MUST exist as declared manifest routes in Phase 1 with "Komt binnenkort" placeholders. Phase 2 implements grade-impact view (weight + impact on period average) for pupils and grade digest + OPP signing tasks for parents.

#### Scenario DA-009-A: Pupil grade-impact view stub
```
GIVEN Phase 1 and a user with LearnerProfile indicating a K-12 pupil context
WHEN they navigate to /index.php/apps/scholiq/ and primaryRole resolves to 'learner'
THEN the LearnerHome dashboard MUST render (Phase 1)
  AND in Phase 2 PupilGrade MUST render each grade with weight and period-average impact
```

---

### REQ-DA-010 — @conduction/nextcloud-vue component compliance

The dashboard MUST use `CnDashboardPage` from `@conduction/nextcloud-vue` as the layout primitive for every role-specific dashboard page. Custom layout equivalents MUST NOT be created. `CnDashboardPage` MUST NOT be wrapped in `NcAppContent` (ADR-017 self-contained component rule).

#### Scenario DA-010-A: CnDashboardPage is the root layout
```
GIVEN any dashboard page renders (ComplianceBoard, LearnerHome, AdminHealth, or stubs)
WHEN the Vue component tree is inspected
THEN the root layout component MUST be CnDashboardPage from @conduction/nextcloud-vue
  AND no wrapping NcAppContent or custom full-page div MUST enclose it
```

#### Scenario DA-010-B: No custom KPI card or chart components
```
GIVEN the ComplianceBoard renders
WHEN the component tree is inspected
THEN no custom RegulationKpiCard.vue, CompliancePieChart.vue, or equivalent MUST exist
  AND all KPI and chart elements MUST be CnStatsBlock, CnChartWidget, or CnTableWidget
```

---

### REQ-DA-011 — DashboardPreference persistence

The system MUST persist per-user, per-role widget order and layout preferences as `DashboardPreference` objects in OpenRegister. Preference reads and writes MUST use standard OR object mutation — no custom preference API endpoint.

#### Scenario DA-011-A: Widget reorder is persisted
```
GIVEN a compliance-officer reorders widgets on the ComplianceBoard via GridStack drag-drop
WHEN the user releases the widget
THEN CnDashboardPage MUST write the new widgetOrder to the DashboardPreference object
  AND on next login the widgets MUST appear in the saved order
```

#### Scenario DA-011-B: Default order applied when no preference exists
```
GIVEN a user has no DashboardPreference object for their roleContext
WHEN their dashboard renders
THEN the default widget order declared in the manifest page config MUST be applied
  AND no error MUST be thrown
```

---

### REQ-DA-012 — No AI features in Phase 1 (ADR-005 safeguard)

The dashboard spec MUST NOT introduce any AI/ML feature. Coverage % and role resolution are deterministic computations. No AI predictions of completion likelihood, dropout risk, or recommended interventions are surfaced in Phase 1.

#### Scenario DA-012-A: No AI transparency banners on Phase 1 dashboard
```
GIVEN any Phase 1 dashboard page renders (ComplianceBoard, LearnerHome, AdminHealth)
WHEN the page is fully rendered
THEN NO CnAiTransparencyBanner component MUST render
  AND the AiFeature schema seed array MUST remain empty after this change is applied
```
