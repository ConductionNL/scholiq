# Tasks — Dashboard

## Phase 1: Role detection

- [ ] Create `Scholiq\Service\RoleDetectionService`: implement `resolveUserRole(ncUserId)` — check IGroupManager::isAdmin() first; then query `scholiq-learner-profile` via ObjectService for matching nc_user_id; apply role-priority sort (compliance-officer > hr > admin > instructor > learner); return highest-priority role; default 'learner' if no profile. Unit tests: mock ObjectService + IGroupManager for each role scenario including admin override and missing profile.
- [ ] Extend `Scholiq\Controllers\PageController::index()`: inject `RoleDetectionService`; resolve role; add `user_role`, `mydash_installed` (IAppManager::isInstalled('mydash')), `tenant_id`, `is_admin` fields to `window.scholiq_config` JSON. Unit test confirms all 5 config fields present in template output.

## Phase 2: PHP controller

- [ ] Create `Scholiq\Controllers\DashboardController` extending `AuditedController`: implement `GET /api/dashboard/compliance` — iterate active regulations, call CoverageComputationService::computeCoverage() for each, fetch active campaign count and recent exports from audit trail; return JSON per design §2.1 (including trend_12m stub that returns 12 identical values in v0.1, real trend in v1). `GET /api/dashboard/learner` — query Enrolments for authenticated learner where mandatory=true, join with Course names, compute days_remaining, assign RAG per threshold; return JSON per design §2.2. `GET /api/dashboard/admin` — query app health data; return JSON per design §2.3. Integration tests for each endpoint with seeded data.

## Phase 3: Vue router + DashboardRouter

- [ ] Create `src/components/DashboardRouter.vue`: reads `window.scholiq_config.user_role`; uses role→route map; calls `$router.replace()` synchronously in `created()` hook; renders nothing. Unit test (Vitest): mount with each user_role, assert $router.replace called with correct path.
- [ ] Update `src/router/index.js` to add: `{ path: '/', component: DashboardRouter }`, `{ path: '/dashboard/compliance', lazy-load ComplianceOfficerDashboard }`, `{ path: '/dashboard/learner', lazy-load LearnerDashboard }`, `{ path: '/dashboard/admin', lazy-load AdminDashboard }`. Playwright test: load app with user_role='compliance-officer' in window config, assert URL changes to /#/dashboard/compliance without manual navigation.

## Phase 4: Compliance Officer dashboard

- [ ] Create `src/views/ComplianceOfficerDashboard.vue` using `CnDashboardPage` layout: fetch `GET /api/dashboard/compliance` on mount; render `RegulationKpiCard` per regulation; render coverage table (CnDataTable with sortable columns); "View in MyDash" button (v-if mydash_installed); "Refresh" action. Playwright test: load dashboard with seeded 2 regulations, assert 2 KPI cards render with correct coverage values and RAG classes.
- [ ] Create `src/components/RegulationKpiCard.vue`: apexcharts `radialBar` with series=[coverage_percent]; RAG class binding (rag--red/amber/green) using NC token CSS vars; enrolled/completed/overdue count display; "Campagne" + "Exporteer" action buttons emitting `create-campaign` and `export-audit` events. Unit test: render with coverage_percent=45, assert rag--red class applied; render with coverage_percent=91, assert rag--green.
- [ ] Wire BulkEnrolmentModal and AuditPackExportModal into ComplianceOfficerDashboard: `create-campaign` event from RegulationKpiCard opens BulkEnrolmentModal with pre-filled regulation_slug; `export-audit` event opens AuditPackExportModal with pre-filled regulation_slug. Playwright test: click "Campagne" on NIS2 card, assert BulkEnrolmentModal opens with regulation_slug pre-filled.

## Phase 5: Learner dashboard

- [ ] Create `src/views/LearnerDashboard.vue` using `CnIndexPage` layout: fetch `GET /api/dashboard/learner` on mount; render CnDataTable with mandatory enrolments sorted by due_date ascending; status badge (red=overdue, amber=due-soon≤7d, green=completed); "Start/Resume" button navigates to /#/courses/:courseId/lessons/:firstLessonId; profile-incomplete banner if scholiq_config.user_role === 'learner' AND no LearnerProfile. NcEmptyContent if zero mandatory enrolments. Playwright test: load as learner user, assert mandatory training rows render with correct status badges.

## Phase 6: Admin dashboard stub

- [ ] Create `src/views/AdminDashboard.vue`: fetch `GET /api/dashboard/admin` on mount; show health widget with OR connection status, schemas registered count, audit events 24h count, link to admin settings. Playwright test: load as admin, assert health widget renders.

## Phase 7: 12-month trend (v0.1 stub)

- [ ] In DashboardController::compliance(): for trend_12m in v0.1, return an array of 12 values where months before Scholiq installation return 0 and the current month returns the live coverage_percent. Comment the code as "stub — real month-by-month computation lands in V1 with audit-trail query per month." Unit test: assert trend_12m has exactly 12 elements.

## Phase 8: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Playwright accessibility check: run axe-core on ComplianceOfficerDashboard and LearnerDashboard; assert zero critical WCAG 2.1 AA violations.
- [ ] Playwright integration test — full wedge smoke test: authenticate as compliance-officer → assert compliance dashboard loads → verify 2 regulation cards render → click "Exporteer" → assert AuditPackExportModal opens → cancel → click "View in MyDash" button (if mydash installed) → assert navigation.
- [ ] Playwright integration test — learner: authenticate as learner → assert learner dashboard loads → assert mandatory enrolment appears → click "Start" → assert LessonPlayer renders.
