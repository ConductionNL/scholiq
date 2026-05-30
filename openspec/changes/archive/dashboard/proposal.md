## Why

The dashboard is the compliance officer's daily instrument panel. For the wedge it serves one primary persona: the compliance officer (or board member) who opens Scholiq each morning to see "what % of mandatory training is current" per regulation, which employees are overdue, and to download the audit pack on demand. Without a role-aware dashboard that surfaces these KPIs at-a-glance, the compliance-audit spec has no user-facing entry point and the wedge fails its "compliance officer demo" success criterion (WEDGE-PLAN.md §Success criteria). The dashboard spec also enforces the role-detection rule: different roles MUST land on different default views automatically, with no manual selection.

## What Changes

- Add `Scholiq\Controllers\DashboardController` providing aggregated KPI data endpoint `GET /api/dashboard/compliance` (coverage % per regulation, overdue counts, campaign status, recent audit-pack exports).
- Add role-detection logic in `PageController::index()` (or a `RoleDetectionService`) that reads the user's `LearnerProfile.roles` from OpenRegister and sets `window.scholiq_config.user_role` for the Vue router to branch on.
- Add Vue `DashboardRouter.vue` that reads `userRole` and redirects to the appropriate default view without user interaction.
- Add `ComplianceOfficerDashboard.vue` (the wedge's primary view): regulation KPI cards with apexcharts `radialBar` gauges, overdue-employee table, campaign status summary, audit-pack download button.
- Add `LearnerDashboard.vue` (slim): learner's mandatory training list — what's due, what's completed, what's overdue. No analytics charts; just a task list with status badges.
- Add `AdminDashboard.vue` (stub for Phase 1): shows app health (OpenRegister connected, no active errors); links to admin settings.
- Wire `@conduction/nextcloud-vue` `CnDashboardPage` and `CnIndexPage` components as the layout primitives per ARCHITECTURE.md §4 (OCP interfaces + shared lib dependency).
- All dashboard reads from OpenRegister via the existing API surface of the other 4 specs; no new schemas introduced by this spec.

## Capabilities

### New Capabilities

- `dashboard`: Role-aware landing dashboard — automatic role detection, Compliance Officer KPI view, Learner mandatory-training task list, Admin health stub.

### Modified Capabilities

(none — all 5 prerequisite specs already landed)

## Impact

- **`RoleDetectionService`**: reads `LearnerProfile.roles` from OpenRegister. If no LearnerProfile exists for the authenticated NC user, defaults to `learner` role and shows a "Complete your profile" prompt in the dashboard header.
- **`DashboardController`**: aggregates data from multiple schemas in one request (compliance coverage, overdue enrolments, recent campaigns). This aggregation may be slow if done naively; the service must use cached coverage data from `CoverageComputationService`.
- **`DashboardRouter.vue`**: the auto-redirect on role detection MUST complete within the initial page render (no secondary redirect); uses `window.scholiq_config.user_role` injected by PHP, not a secondary async call.
- **launchpad delegation**: heavy analytics (cross-tenant trends, advanced cohort comparisons) are NOT implemented in Scholiq. The Compliance Officer dashboard MUST include a "View in LaunchPad" deep link button for heavier analytics, satisfying REQ-DA-006.
- **Wedge scope**: mentor absence-pattern view, manager team heat-map, principal Cito export, parent grade digest, and pupil grade-impact view are all deferred to Phase 2 (K-12 context) or V1 (corporate). Only compliance-officer and learner dashboard views ship in Phase 1.
