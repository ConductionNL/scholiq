## Why

"Analytics dashboard" (#17, 39 demand) and "Student Analytics" (#18, 34 demand) score in the top 20 canonical features. OSS LMS leaders share dated, role-agnostic UX — a modern Vue / NL-Design role-aware dashboard surface is the structural differentiator (Insight #16). Eight user stories across six roles anchor this spec: mentor (absence-pattern view), manager (team heat-map), board member (live compliance % per regulation), school principal (Cito export), pupil (grade-impact view + RADIO progress), and parent (grade digest). Without role-aware dashboards, every authenticated user lands on the same generic screen with no actionable signal — the app fails its "open Scholiq and immediately know what to do" adoption criterion across all buyer personas.

## What Changes

- Add OpenRegister schema `scholiq-role-assignment` — the declarative source of truth for per-user role mappings (`ncUserId`, `role`, `tenant_id`, `validFrom`, `validUntil`). Replaces any implicit role-from-NC-group logic.
- Add OpenRegister schema `scholiq-dashboard-preference` — per-user, per-role widget order and layout mode preference store. No custom preference API required; uses standard OR object mutation.
- Patch `LearnerProfile` schema to add `x-openregister-calculations.primaryRole` (backed by `RoleSelector`) and an `x-openregister-relations` pointer to `RoleAssignment`.
- Patch `Enrolment` schema to add `x-openregister-widgets.myMandatoryTraining` — the learner task-list widget consumed by `LearnerHome` and `PupilGrade` dashboard pages.
- Add `lib/Lifecycle/RoleSelector.php` — single-method PHP calculation helper that applies the role priority map (`compliance-officer=6 > board-member=5 > principal=5 > hr=4 > manager=4 > mentor=3 > instructor=3 > parent=2 > learner=1`) and returns the highest-priority role for the authenticated user.
- Add `lib/Controller/HealthController.php` — thin observability endpoint (`GET /api/admin/health`) for the admin health widget; returns OR connection status, schema count, audit-trail events in last 24 h, and mydash installed flag.
- Extend `src/manifest.json` with nine role-specific dashboard pages (`ComplianceBoard`, `ManagerTeam`, `MentorAbsence`, `PrincipalCito`, `PupilGrade`, `ParentDigest`, `TeacherCohort`, `LearnerHome`, `AdminHealth`) plus a `roleAware` dispatcher on the root `/` route that reads `LearnerProfile.primaryRole`.
- Wire `CnDashboardPage` from `@conduction/nextcloud-vue` as the layout primitive for every role-specific view — no custom layout components.
- Add a "View in MyDash" manifest-level action on `ComplianceBoard` and `ManagerTeam` pages — rendered only when `mydash` is installed (`IAppManager::isInstalled`).

## Capabilities

### New Capabilities

- `dashboard`: Role-aware landing dashboard — automatic role resolution from `RoleAssignment` + `LearnerProfile.primaryRole`, nine per-role default views, `DashboardPreference` persistence, MyDash deep-link delegation for heavy analytics, admin health observability stub.

### Modified Capabilities

- `LearnerProfile`: gains `primaryRole` calculated field (backed by `RoleSelector`) and `x-openregister-relations` pointer to `RoleAssignment`.
- `Enrolment`: gains `x-openregister-widgets.myMandatoryTraining` declaration for the learner/pupil mandatory-training task list.

## Impact

- **`RoleAssignment` schema**: declarative role source. If no `RoleAssignment` exists for the authenticated NC user, `RoleSelector` defaults to `learner` role and the dashboard shows a "Maak uw profiel aan" banner. RBAC: tenants read their own; admins write.
- **`DashboardPreference` schema**: each user gets at most one `DashboardPreference` per role context (enforced by slug uniqueness `pref-{ncUserId}-{role}`). OR's standard object mutation stores and retrieves preferences — no custom endpoint.
- **`RoleSelector`**: executed server-side by OR's `x-openregister-calculations` engine. The sole PHP seam the dashboard introduces. The returned `primaryRole` is materialised on `LearnerProfile`, readable by `CnAppRoot`'s page resolver without a round-trip.
- **Manifest `roleAware` dispatcher on `/`**: `CnAppRoot` reads `LearnerProfile.primaryRole` and navigates to the matching page. No `DashboardRouter.vue`, no `$router.replace()` glue.
- **MyDash delegation**: cross-tenant analytics, cohort benchmarking, and sector vergelijken MUST NOT be built in Scholiq. `ComplianceBoard` and `ManagerTeam` expose a manifest-level deep link (`/apps/mydash/#/scholiq-analytics?tenant=@actor.tenantId`) conditional on `appInstalled: mydash`. `CnAppRoot` resolves `IAppManager` automatically.
- **Phase 1 scope**: `ComplianceBoard` (roles: compliance-officer, board-member, hr), `LearnerHome` (learner), and `AdminHealth` (admin) ship fully in Phase 1. The remaining six role pages are declared as manifest pages with stable routes but render as "Komt binnenkort" stubs in Phase 1. Full implementation for these pages is Phase 2.
- **No AI in Phase 1**: the dashboard introduces no AI/ML features. `AiFeature` schema remains an empty seed array per ADR-005.
