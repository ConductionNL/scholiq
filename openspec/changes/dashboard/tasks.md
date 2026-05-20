# Tasks — Role-Aware Dashboards

> Scope: two new schemas (`RoleAssignment`, `DashboardPreference`); patches on `LearnerProfile` (primaryRole calculation + RoleAssignment relation) and `Enrolment` (myMandatoryTraining widget); one PHP calculation helper (`RoleSelector`); one PHP observability controller (`HealthController`); nine manifest dashboard pages with a `roleAware` root dispatcher. No custom Vue dashboard views — `CnAppRoot` + `CnDashboardPage` cover all layout needs.

---

## Phase 0: Deduplication Check

- [ ] Search `openspec/specs/` and `openregister/lib/Service/` for any existing role-detection or dashboard-preference service that overlaps with `RoleSelector` or `DashboardPreference`. Document findings (even if "no overlap found") in a comment on this task before proceeding.
- [ ] Confirm `CnDashboardPage`, `CnStatsBlock`, `CnChartWidget`, and `CnTableWidget` exist in `@conduction/nextcloud-vue` at the installed version — no custom equivalents required.

---

## Phase 1: Schema additions and patches on `lib/Settings/scholiq_register.json`

- [ ] Add `RoleAssignment` schema per design §1.1 — fields: `ncUserId`, `role` (enum: 11 values), `department`, `validFrom`, `validUntil`, `tenant_id`; `x-openregister-lifecycle` with `revoke` / `restore` transitions; `x-openregister-relations` pointer to `LearnerProfile`. Verify slug is `role-assignment`.
- [ ] Add `DashboardPreference` schema per design §1.2 — fields: `ncUserId`, `roleContext` (enum: 11 values), `widgetOrder` (array), `pinnedWidgets` (array), `layoutMode` (compact/expanded), `tenant_id`. No lifecycle needed (plain CRUD). Verify slug is `dashboard-preference`.
- [ ] Patch `LearnerProfile` schema to add `x-openregister-calculations.primaryRole` with `requires: OCA\\Scholiq\\Lifecycle\\RoleSelector` and `materialise: true`; add `x-openregister-relations.roleAssignments` pointing to `RoleAssignment` schema via `ncUserId`.
- [ ] Patch `Enrolment` schema to add `x-openregister-widgets.myMandatoryTraining` per design §1.4 — `scope: owned-by-actor`, filter on mandatory + active/pending + learnerId, sort by dueDate asc, columns: course.name / regulationSlug / lifecycle / dueDate / daysRemaining / ragStatus, action: LessonPlayer start.
- [ ] Add 5 seed `RoleAssignment` objects per design §2.1 (Dutch names: marie.janssen compliance-officer, piet.bakker manager, jan.de.vries mentor, sophie.hendriks board-member, lars.vermeulen learner). Slugs: `role-{ncUserId}-{role-short}`.
- [ ] Add 4 seed `DashboardPreference` objects per design §2.2 (marie.janssen, piet.bakker, jan.de.vries, sophie.hendriks). Slugs: `pref-{ncUserId}-{roleContext-short}`.
- [ ] Write a JSON-validation test (PHPUnit or Jest schema-validator) asserting both new schemas parse against the OR schema validator and that the `primaryRole` calculation reference and `myMandatoryTraining` widget reference resolve.

---

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/RoleSelector.php` — single public method `selectPrimaryRole(array $roleAssignments, IUser $user): string`. Logic: if `IGroupManager::isAdmin($user)` → return `'admin'`. Otherwise iterate `$roleAssignments` and return the highest-priority role per static map `['compliance-officer' => 6, 'board-member' => 5, 'principal' => 5, 'hr' => 4, 'manager' => 4, 'mentor' => 3, 'instructor' => 3, 'parent' => 2, 'learner' => 1]`. Default `'learner'` if array is empty or all invalid. Legitimate per ADR-031 §"Domain rule engines that operate above schema metadata".
  - Unit tests: cover admin override, multi-role priority (manager > learner), empty-roles → learner fallback, each of the 11 role values.
- [ ] Create `lib/Controller/HealthController.php` — `GET /api/admin/health` (admin-only, `#[AuthorizedAdminSetting]`). Returns JSON: `{ openregister_connected: bool, schemas_registered: int, audit_trail_events_24h: int, mydash_installed: bool, last_audit_pack_export: string|null }`. OR queries: (1) count schemas via `RegisterService::countSchemas()`; (2) count audit events via OR audit-trail API `count(created_at >= now-24h)`; (3) max `created_at` where `event_type = compliance.audit_pack.exported`; (4) `IAppManager::isInstalled('mydash')`. Legitimate per ADR-031 §"External-system contract / observability".
  - Integration test: assert all 5 fields populate when OR is connected; assert `openregister_connected: false` when OR is unreachable.
- [ ] Register route in `appinfo/routes.php`:
  ```php
  ['name' => 'health#index', 'url' => '/api/admin/health', 'verb' => 'GET'],
  ```
  Verify `hydra-gate-route-auth` passes (method must carry `#[AuthorizedAdminSetting(Application::APP_ID)]`).

---

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with nine pages per design §5.1:
  - `Dashboard` (route `/`) with `roleAware` block mapping all 11 role values to named pages (`ComplianceBoard`, `ManagerTeam`, `MentorAbsence`, `PrincipalCito`, `TeacherCohort`, `ParentDigest`, `LearnerHome`, `AdminHealth`) and `fallback: LearnerHome`.
  - `ComplianceBoard` (route `/compliance`) with `coverageGrid` + `overdueTable` widget-refs and `viewInMydash` action with `visibleIf: { appInstalled: mydash }`.
  - `LearnerHome` (route `/learner`) with `myMandatoryTraining` widget-ref to `Enrolment`.
  - `AdminHealth` (route `/admin/health`) with `endpoint-stats` widget pointing to `/api/admin/health`.
  - `ManagerTeam` (route `/manager/team`) with `stub: true` + `viewInMydash` action.
  - `MentorAbsence` (route `/mentor/absence`) with `stub: true`.
  - `PrincipalCito` (route `/principal/cito`) with `stub: true`.
  - `TeacherCohort` (route `/teacher/cohort`) with `stub: true`.
  - `ParentDigest` (route `/parent/digest`) with `stub: true`.
- [ ] Run `npm run check:manifest` — must pass with zero schema errors.
- [ ] Verify the `Compliance` page declared in the compliance-audit change is the dispatch target for `compliance-officer` / `board-member` / `hr` roles — update `byRole` mapping if the compliance-audit change named it differently.
- [ ] **Do NOT** create `src/components/DashboardRouter.vue` — `CnAppRoot`'s `roleAware` resolver replaces it (ADR-024).
- [ ] **Do NOT** create `src/views/ComplianceOfficerDashboard.vue`, `LearnerDashboard.vue`, `AdminDashboard.vue`, `RegulationKpiCard.vue`, or any custom chart component — `CnAppRoot`'s built-in dashboard renderer consumes schema-declared widgets.
- [ ] **Do NOT** add `DashboardPreference` route to `src/router/index.js` — preferences are read/written as plain OR objects via `ObjectService`, not routed pages.
- [ ] Verify `CnDashboardPage` is NOT wrapped in `NcAppContent` on any page (ADR-017 self-contained component rule).

---

## Phase 4: i18n keys

- [ ] Add translation keys to both `l10n/nl.json` and `l10n/en.json`:
  - Page titles: `scholiq.page.dashboard.title`, `scholiq.page.compliance.title`, `scholiq.page.learner.title`, `scholiq.page.admin.health.title`, `scholiq.page.manager.team.title`, `scholiq.page.mentor.absence.title`, `scholiq.page.principal.cito.title`, `scholiq.page.teacher.cohort.title`, `scholiq.page.parent.digest.title`
  - Widget titles: `scholiq.widget.learner.mandatoryTraining`
  - Column labels: `scholiq.col.course`, `scholiq.col.regulation`, `scholiq.col.status`, `scholiq.col.due`, `scholiq.col.days`, `scholiq.col.rag`
  - Actions: `scholiq.action.viewInMydash`
  - Stub message: `scholiq.stub.phase2`
  - Empty state: `scholiq.empty.noMandatoryTrainings`
  - Profile prompt: `scholiq.banner.createProfile`

---

## Phase 5: Seed data generation

- [ ] Verify the 5 `RoleAssignment` seed objects (design §2.1) are present in `lib/Settings/scholiq_register.json` under `components.objects[]` with correct `@self` envelopes (`register: scholiq`, `schema: RoleAssignment`, unique slugs).
- [ ] Verify the 4 `DashboardPreference` seed objects (design §2.2) are present with `@self` envelopes and correct `roleContext` values.
- [ ] Confirm seed import is idempotent: re-running `importFromApp()` MUST NOT create duplicates (matched by slug per ADR-001).
- [ ] Run a manual or integration-test import on a fresh install and verify all seeds appear in the OR object list for each schema.

---

## Phase 6: Quality gates

- [ ] Run `composer check:strict`; fix all PHPStan + PHPCS violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Run `hydra-gates` (full suite); fix any Hydra gate failures — specifically:
  - `hydra-gate-route-auth`: `HealthController::index` MUST carry `#[AuthorizedAdminSetting]`.
  - `hydra-gate-spdx`: `RoleSelector.php` + `HealthController.php` MUST have correct SPDX + copyright headers.
  - `hydra-gate-forbidden-patterns`: no `var_dump` / `die` / `error_log` in new PHP files.
  - `hydra-gate-stub-scan`: stubs in PHP MUST NOT use empty `run()` or placeholder comment patterns.
- [ ] Integration test (PHPUnit + OR): seed a `LearnerProfile` with `RoleAssignment` records for `['hr', 'learner']` → trigger OR calculation refresh → assert `primaryRole = 'hr'` (priority 4 > 1). Repeat for admin-group override.
- [ ] Integration test: call `GET /api/admin/health` as admin → assert all 5 fields populated. Call as non-admin → assert 403.
- [ ] Playwright integration test — compliance-officer flow: authenticate as compliance-officer → assert `/` routes to `/compliance` → assert `coverageGrid` widget renders with seeded regulation data → assert "View in MyDash" action visible (if mydash seeded as installed) or hidden (if not).
- [ ] Playwright integration test — learner flow: authenticate as learner → assert `/` routes to `/learner` → assert `myMandatoryTraining` widget renders rows sorted by dueDate → click "Start" on a row → assert LessonPlayer opens.
- [ ] Playwright integration test — admin flow: authenticate as admin → assert `/` routes to `/admin/health` → assert all 5 health fields visible in the widget.
- [ ] Playwright integration test — stub pages: authenticate as mentor → assert `/` routes to `/mentor/absence` → assert `CnEmptyState` "Komt binnenkort" message renders and no 404/error occurs.
- [ ] Playwright accessibility check: run axe-core on `ComplianceBoard`, `LearnerHome`, and `AdminHealth` pages; assert zero critical WCAG 2.1 AA violations. Verify RAG colour bands also show non-colour indicators (text or icon) per REQ-DA-002-B.
