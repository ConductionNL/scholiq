# Tasks — Dashboard

> Scope: a schema patch on `LearnerProfile` adding `primaryRole` calculation + `RoleSelector` PHP guard; a widget declaration on `Enrolment` for the learner task list; a manifest extension wiring three dashboard pages with `roleAware` dispatch. One PHP observability controller for the admin health page.

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add `LearnerProfile` schema per design §1.1 — including `x-openregister-calculations.primaryRole` with `requires: RoleSelector`. Reference: decidesk's ActionItem `requires:` pattern for the calculation->PHP-class binding.
- [ ] Add `x-openregister-widgets.myMandatoryTraining` to the existing `Enrolment` schema (added by the enrolment change) per design §1.2 — `scope: owned-by-actor`, filter on `learnerId=@actor.id`, sort by dueDate.
- [ ] Write a JSON-validation test that asserts both declarations parse and the widget reference resolves.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/RoleSelector.php`: single method `selectPrimaryRole(LearnerProfile $profile, IUser $user): string`. Logic: if `IGroupManager::isAdmin($user)` → return `admin`. Otherwise pick highest-priority role from `$profile->roles` using static priority map `[compliance-officer=5, hr=4, admin=3, manager=3, instructor=2, learner=1]`. Default `learner` if none. Legitimate per ADR-031 §"Domain rule engines that operate *above* schema metadata". Unit tests: mock each role scenario (admin override, multi-role priority, empty roles → learner fallback).
- [ ] Create `lib/Controller/HealthController.php`: `GET /api/admin/health` admin-only — returns `{ openregister_connected, schemas_registered, audit_trail_events_24h, launchpad_installed, last_audit_pack_export }`. The audit-trail events count is a single OR query (`count(audit_event where created_at >= now-24h)`). The last-export timestamp is a single OR query (`max(created_at) where event_type = compliance.audit_pack.exported`). Integration test: assert all 5 fields populated.
- [ ] Register the route in `appinfo/routes.php`.

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with three pages per design §3.1:
  - `Dashboard` (route `/`, `roleAware` block dispatching to Compliance / LearnerHome / AdminHealth based on `LearnerProfile.primaryRole`).
  - `LearnerHome` (dashboard widgets: `widget-ref` to `Enrolment.myMandatoryTraining`).
  - `AdminHealth` (dashboard widgets: `endpoint-stats` to `/api/admin/health`).
- [ ] Re-run `npm run check:manifest`.
- [ ] Verify the `Compliance` page (registered in the compliance-audit change) is the dispatch target for compliance-officer / hr roles — no change needed if compliance-audit landed first.
- [ ] **Do NOT** create `src/components/DashboardRouter.vue` — `CnAppRoot`'s `roleAware` page resolver replaces it per ADR-024.
- [ ] **Do NOT** create `src/views/ComplianceOfficerDashboard.vue`, `LearnerDashboard.vue`, `AdminDashboard.vue`, `RegulationKpiCard.vue` — `CnAppRoot`'s built-in dashboard renderer consumes the schema-declared widgets directly.
- [ ] **Do NOT** create `src/router/index.js` route entries.

## Phase 4: i18n keys

- [ ] Add translation keys for the new manifest labels + widget column labels: `scholiq.page.dashboard.title`, `scholiq.page.learner.title`, `scholiq.page.admin.health.title`, `scholiq.widget.learner.mandatoryTraining`, `scholiq.col.{course,regulation,status,due,days,rag}`, `scholiq.action.viewInLaunchPad`. Land in both `l10n/nl.json` and `l10n/en.json`.

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Integration test (PHPUnit + OR): seed a LearnerProfile with `roles=[hr, learner]` → trigger OR's calculation refresh → assert `primaryRole = hr`. Repeat for admin-via-group override.
- [ ] Playwright accessibility check: run axe-core on the Compliance + Learner + AdminHealth dashboard pages (rendered via `CnAppRoot`); assert zero critical WCAG 2.1 AA violations.
- [ ] Playwright integration test — compliance officer flow: authenticate as compliance-officer user → assert `/` redirects to Compliance dashboard → assert regulation-coverage widget renders with seeded data → click "Exporteer" action on a regulation card → assert AuditPackExportModal opens.
- [ ] Playwright integration test — learner flow: authenticate as learner → assert `/` redirects to LearnerHome → assert mandatory-training widget renders sorted by dueDate → click "Start" on a row → assert `LessonPlayer` opens for the correct lesson.
- [ ] Playwright integration test — admin flow: authenticate as admin → assert `/` redirects to AdminHealth → assert health widget renders all 5 fields.
