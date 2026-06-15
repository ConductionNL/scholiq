# Tasks: fix-dashboards-settings-notifications

> Status: COMPLETE — implemented in commit ad66878 (role-aware dashboards, settings
> split, notification prefs) and verified 2026-06-15. Design note: tasks 3–5 were
> collapsed into a single role-aware `src/views/ScholiqDashboards.vue` (one
> `CnDashboardPage`, in-component role switcher, server-resolved `primaryRole` via
> `DashboardRoleService` injected into `manifest.runtime` and read by the shell's
> `visibleIf` role gating) rather than a separate `DashboardDispatcher.vue` plus
> `src/views/widgets/teacher/*.vue` files; the teacher view is rendered through the
> dashboard slot templates over the shared OR stores. Behaviour matches every
> acceptance criterion. The dashboard capability spec is `@e2e exclude` (no
> `#### Scenario:` headings), so gate-19 has no scenario to back-reference.

## Implementation Tasks

### Task 1: Remove the dashboard-in-dashboard antipattern
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-use-conductionnextcloud-vue-dashboard-components`
- **files**: `src/manifest.json`, `src/views/ScholiqDashboards.vue`, `src/views/ScholiqDashboard.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN the dashboard route WHEN rendered THEN exactly one `CnDashboardPage` and one page heading appears (no triple "Dashboard")
  - GIVEN the manifest dashboard page WHEN read THEN KPI/manage tiles are declared directly in `config.widgets`/`layout`/`slots`, no single wrapper widget
- [x] Implement
- [x] Test

### Task 2: Normalise navigation icons to monochrome icon-*
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-consistent-monochrome-navigation-icons`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest `menu` array WHEN inspected THEN every `icon` is a monochrome `icon-*` class and none is `icon-category-*`
- [x] Implement
- [x] Test

### Task 3: Role-aware Dashboards component + single menu entry (ADR-009 §6)
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard`
- **files**: `src/views/ScholiqDashboards.vue`, `src/manifest.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN a learner WHEN opening Dashboards THEN the student view renders (no admin KPI grid)
  - GIVEN an instructor WHEN opening Dashboards THEN the teacher view renders
  - GIVEN a multi-role user WHEN using the in-component switcher THEN the same page re-renders the chosen role's view (no separate menu item per role)
- [x] Implement
- [x] Test

### Task 4: Teacher dashboard widgets
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard`
- **files**: `src/views/widgets/teacher/*.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN the teacher view WHEN rendered THEN it shows my courses, assignments to grade, sessions to mark, and my cohorts, each reading OpenRegister via the shared stores
- [x] Implement
- [x] Test

### Task 5: Root-route dispatcher by primaryRole
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard`
- **files**: `src/views/DashboardDispatcher.vue`, `src/manifest.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN any signed-in user WHEN opening the app root THEN they land on the dashboard view matching their resolved role
  - GIVEN an unresolved role WHEN opening the root THEN the student (least-privileged) view is shown
- [x] Implement
- [x] Test

### Task 6: Move register/AI/credential-signing to the Admin panel
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers`
- **files**: `appinfo/info.xml`, `lib/Sections/SettingsSection.php`, `lib/Settings/AdminSettings.php`, `src/views/settings/AdminRoot.vue`, `templates/settings/admin.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN opening Settings → Administration → Scholiq THEN the register picker, AI-features table, and signing-key rotation are present and functional
  - GIVEN a non-admin WHEN opening the per-user dialog THEN none of those admin controls are present
  - GIVEN the mutating settings endpoints WHEN called THEN they are guarded admin-only
- [x] Implement
- [x] Test

### Task 7: Per-user notification-preferences panel in user settings
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-per-user-notification-preferences-in-the-user-settings-dialog`
- **files**: `src/views/ScholiqNotificationSettings.vue`, `src/manifest.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN the per-user settings dialog WHEN opened THEN it loads overrides via `GET /apps/openregister/api/notification-preferences` and renders a toggle per Scholiq notification type
  - GIVEN a user toggling a type off WHEN saving THEN a `PUT /apps/openregister/api/notification-preferences` records the override (no scholiq-local store)
- [x] Implement
- [x] Test

### Task 8: Verify/complete declarative notifications for the four core events
- **spec_ref**: `openspec/changes/fix-dashboards-settings-notifications/specs/scholiq-notifications/spec.md#requirement-core-learner-events-must-emit-declarative-nc-notification-rules`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN grade-posted, credential-issued, attendance-flag, and completion events WHEN they fire THEN each delivers an `nc-notification` to the affected user via OpenRegister (verified dialect; no imperative `INotifier` in scholiq)
  - GIVEN a user who disabled a type WHEN the event fires THEN OpenRegister records `preference-off` and delivers nothing
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — backend change is config-only (register JSON + info.xml + Section class); cover the Section class and any guard changes
- UI changes (dashboards, settings panels) covered by Playwright browser tests; vitest for new Vue components
- All tests pass (`composer check:strict`, `npm run test`); hydra `dashboard-antipattern` + `nc-input-labels` + `modal-isolation` gates pass
- Feature documentation updated in `docs/` for the role-aware dashboards and settings split (ADR-010)
- English source i18n keys with Dutch (`nl`) translations for any new user-facing strings (ADR-007; English keys per house rule)
- `openspec validate` passes
