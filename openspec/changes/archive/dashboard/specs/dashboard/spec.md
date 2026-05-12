---
slug: dashboard
title: Role-Aware Dashboards
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-008]
created: 2026-05-11
updated: 2026-05-11
wedge_scope: Phase 1 — Compliance Officer and Learner views only; K-12 role views deferred to Phase 2
---

# Dashboard — Formal Requirements

## Overview

The dashboard is the default landing view for all authenticated Scholiq users. Role detection MUST be automatic: a compliance officer lands on the compliance KPI view; a learner lands on their mandatory training task list; an admin lands on the app health view. No manual role selection is required. All dashboard views consume existing API endpoints from prior specs; the dashboard spec introduces no new OpenRegister schemas. Cross-tenant or heavy-aggregation analytics are delegated to MyDash via deep-link per ARCHITECTURE.md §5.

---

## Requirements

### REQ-DA-001 — Automatic role detection

The system MUST resolve the authenticated user's primary role from `LearnerProfile.roles` in OpenRegister and inject the resolved role into `window.scholiq_config.user_role` at server-render time. The Vue SPA MUST redirect to the appropriate default dashboard route without user interaction and within the initial page render.

#### Scenario DA-001-A: Compliance officer lands on compliance dashboard
```
GIVEN a user with LearnerProfile.roles containing 'compliance-officer' or 'hr' authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN the PHP controller MUST inject user_role='compliance-officer' into window.scholiq_config
  AND DashboardRouter.vue MUST redirect to /#/dashboard/compliance without user interaction
  AND the ComplianceOfficerDashboard MUST render as the default view
```

#### Scenario DA-001-B: Learner lands on task-list dashboard
```
GIVEN a user with LearnerProfile.roles = ['learner'] only authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN the system MUST redirect to /#/dashboard/learner
  AND LearnerDashboard.vue MUST render showing the learner's mandatory training list
```

#### Scenario DA-001-C: Admin lands on health dashboard
```
GIVEN a user with NC admin rights (IGroupManager::isAdmin()) authenticates
WHEN they navigate to /index.php/apps/scholiq/
THEN the system MUST redirect to /#/dashboard/admin
  AND AdminDashboard.vue MUST render with app health status
```

#### Scenario DA-001-D: No LearnerProfile → default to learner role
```
GIVEN an authenticated NC user has no LearnerProfile in OpenRegister
WHEN DashboardRouter.vue evaluates user_role
THEN it MUST default to 'learner' dashboard
  AND the dashboard MUST show a "Complete your profile" banner prompting profile creation
```

---

### REQ-DA-002 — Compliance Officer dashboard KPI cards

The Compliance Officer dashboard MUST display one KPI card per active regulation showing: regulation name, coverage % (from `compliance-audit/enrolment-coverage-percent`), RAG status band (red/amber/green), enrolled count, completed count, overdue count.

#### Scenario DA-002-A: KPI cards render within 2 seconds
```
GIVEN a compliance officer navigates to /#/dashboard/compliance
WHEN the dashboard data loads from GET /api/dashboard/compliance
THEN all KPI cards MUST be rendered and showing coverage % within 2 seconds
  AND apexcharts radialBar gauges MUST reflect the current coverage values
```

#### Scenario DA-002-B: Red RAG triggers visual alert
```
GIVEN a regulation has coverage_percent < 70 (rag_status='red')
WHEN the compliance officer views the dashboard
THEN the regulation KPI card MUST render with a red border and a warning icon
  AND a "Campaign overdue" label MUST appear beneath the gauge
```

---

### REQ-DA-003 — Coverage table

The Compliance Officer dashboard MUST include a sortable table listing each regulation with columns: regulation name, enrolled, completed, overdue, coverage %, last campaign date, "Create campaign" action, "Export audit pack" action.

#### Scenario DA-003-A: Coverage table is sortable by coverage %
```
GIVEN 3 regulations exist with coverage % of 45%, 80%, and 100%
WHEN the compliance officer clicks the "Coverage %" column header
THEN the table MUST sort ascending by coverage % (45%, 80%, 100%)
  AND clicking again MUST sort descending
```

#### Scenario DA-003-B: Create campaign action from table
```
GIVEN the compliance officer clicks "Create campaign" in the NIS2 row
WHEN the action is triggered
THEN the BulkEnrolmentModal MUST open pre-filled with regulation_slug='NIS2'
```

---

### REQ-DA-004 — Audit-pack download from dashboard

The Compliance Officer dashboard MUST provide a per-regulation "Export audit pack" button that triggers the audit-pack export per `compliance-audit/audit-pack-export`.

#### Scenario DA-004-A: Audit pack downloaded from dashboard
```
GIVEN a compliance officer clicks "Export audit pack" for AVG on the dashboard
WHEN the action triggers
THEN the AuditPackExportModal MUST open with regulation_slug='AVG' pre-selected
  AND on confirm, POST /api/compliance/audit/export MUST be called
  AND the resulting ZIP MUST be downloaded to the user's browser
```

---

### REQ-DA-005 — Learner mandatory training list

The Learner dashboard MUST display a list of mandatory Enrolments for the authenticated learner, sorted by due_date ascending. Each row MUST show: course name, regulation slug, status badge, due_date (red if overdue, amber if ≤ 7 days), progress indicator, and "Start / Resume" action button.

#### Scenario DA-005-A: Learner sees their mandatory training list
```
GIVEN a learner has 3 mandatory Enrolments (1 completed, 1 due in 5 days, 1 overdue)
WHEN they view the LearnerDashboard
THEN the list MUST show all 3 Enrolments sorted by due_date ascending
  AND the overdue Enrolment MUST show a red "Overdue" badge
  AND the 5-day Enrolment MUST show an amber "Due soon" badge
  AND the completed Enrolment MUST show a green "Completed" badge with the credential verify URL
```

#### Scenario DA-005-B: Learner navigates to course from dashboard
```
GIVEN a learner clicks "Start" on a mandatory Enrolment
WHEN the action fires
THEN the router MUST navigate to /#/courses/:courseId/lessons/:firstLessonId
  AND the LessonPlayer MUST render the first uncompleted lesson
```

---

### REQ-DA-006 — Delegation to MyDash for heavy analytics

The Compliance Officer dashboard MUST include a visible "View in MyDash" button that navigates to the MyDash app's Scholiq analytics surface. The dashboard MUST NOT re-implement analytics that MyDash provides.

#### Scenario DA-006-A: "View in MyDash" button navigates to MyDash
```
GIVEN MyDash is installed and the user has MyDash access
WHEN the compliance officer clicks "View in MyDash" on the Scholiq dashboard
THEN the browser MUST navigate to /index.php/apps/mydash/#/scholiq-analytics
  AND the NC session MUST be shared (no re-login required)
```

#### Scenario DA-006-B: "View in MyDash" button hidden when MyDash not installed
```
GIVEN MyDash is NOT installed on this NC instance
WHEN the compliance officer views the dashboard
THEN the "View in MyDash" button MUST NOT render
  AND no error MUST be thrown
```

---

### REQ-DA-007 — @conduction/nextcloud-vue layout components (ADR compliance)

The dashboard MUST use `CnDashboardPage` and `CnIndexPage` from `@conduction/nextcloud-vue` as layout primitives. Custom layout equivalents MUST NOT be created.

#### Scenario DA-007-A: CnDashboardPage used as compliance dashboard layout
```
GIVEN the ComplianceOfficerDashboard renders
WHEN the component tree is inspected
THEN the root layout component MUST be CnDashboardPage from @conduction/nextcloud-vue
  AND no custom full-page layout div MUST wrap the dashboard content
```

---

### REQ-DA-008 — No AI features in Phase 1 (ADR-005 safeguard)

The dashboard spec MUST NOT introduce any AI/ML feature. Coverage % is computed deterministically (compliance-audit spec); no AI predictions of completion likelihood, dropout risk, or recommended interventions are surfaced in Phase 1.

#### Scenario DA-008-A: No AI transparency banners on wedge dashboard
```
GIVEN the compliance officer views the dashboard
WHEN the page renders
THEN NO CnAiTransparencyBanner component MUST render
  AND AiFeatureRegistry::all() MUST return an empty array after dashboard install
```
