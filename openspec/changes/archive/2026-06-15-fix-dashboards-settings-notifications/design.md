# Design: fix-dashboards-settings-notifications

## Architecture Overview

Four coupled fixes to the Scholiq app shell. The shell is the v2 manifest renderer from `@conduction/nextcloud-vue` (`CnAppRoot` reading `src/manifest.json`), so most of the work is declarative manifest edits plus a few Vue components. No new scholiq backend services are introduced; backend work is limited to wiring the already-built admin settings into Nextcloud and confirming the declarative OpenRegister notification rules.

```
src/manifest.json ──► CnAppRoot (nextcloud-vue)
  pages[Dashboards] (type:dashboard, role-aware) ──► ScholiqDashboards.vue ──► one CnDashboardPage, per-role widget set
  pages[/] dispatcher ──► reads primaryRole ──► router.replace(role route)
  menu[*].icon ──► monochrome icon-* only
  pages[Settings] (type:settings, user dialog) ──► ScholiqNotificationSettings.vue ──► OR /api/notification-preferences

appinfo/info.xml <settings> ──► AdminSettings (IDelegatedSettings) + SettingsSection (IIconSection)
  settings.js ──► AdminRoot.vue ──► register picker + AI features + credential signing  (admin only)

lib/Settings/scholiq_register.json  x-openregister-notifications (declarative)
  └─► OpenRegister AnnotationNotificationDispatcher ──► nc-notification ──► AnnotationNotifier (renderer)
        └─► NotificationPreferenceService gate (override-only, per user)
```

## Declarative-vs-imperative decision (ADR-031)

This change touches two ADR-031-relevant behaviour classes: **dashboard widgets** and **notifications**. Both stay on the declarative path.

| Behaviour | Path | Rationale |
|-----------|------|-----------|
| Dashboard tiles (KPI cards, manage lists, per-role widgets) | **Declarative** — declared in the manifest `pages[Dashboards].config.widgets` / `layout` / `slots`; each slot resolves to a plain widget component that reads OpenRegister via the shared stores. | ADR-012 mandates `@conduction/nextcloud-vue` dashboard components; the v2 manifest is the canonical place to declare widgets (matches procest/shillinq). No service class. |
| Role resolution for the dashboard | **Declarative + shell-provided** — `primaryRole` is the OpenRegister-calculated field already materialised on `LearnerProfile` (via `RoleSelector`) and surfaced to the shell (the manifest already evaluates `visibleIf: user.primaryRole`). The component reads it; it does not recompute roles. | Reuses the existing calculated field; no new role logic. |
| Notification emission (grade posted, credential issued, attendance flag, completion) | **Declarative** — `x-openregister-notifications` rules in `scholiq_register.json` (verified dialect), dispatched + rendered by OpenRegister's `AnnotationNotificationDispatcher` / `AnnotationNotifier`. | ADR-031 default. 10 rules already exist in the verified dialect; scholiq must NOT add an imperative `INotifier`. |
| Per-user notification opt-out | **Declarative consumption** — the user-settings panel reads/writes OpenRegister's `/api/notification-preferences`; OR's dispatcher honors the override (`preference-off`). | ADR-022 (apps consume OR abstractions). No scholiq-local preference store. |

No imperative exception is claimed. The only imperative code added is framework glue (registering the existing `AdminSettings` class + a `SettingsSection`).

## API Design

No new scholiq endpoints. The user-settings panel consumes existing OpenRegister endpoints:

### `GET /apps/openregister/api/notification-preferences`
Returns the current per-`(schema, notification)` overrides for the signed-in user.

### `PUT /apps/openregister/api/notification-preferences`
**Request:**
```json
{ "schema": "credential", "notification": "issuedToLearner", "enabled": false }
```
Persists an override that the OpenRegister dispatcher honors as a delivery gate.

The admin register/AI pickers and key rotation continue to use the existing scholiq Settings API (`/apps/scholiq/api/settings`, `/apps/scholiq/api/settings/load`), now reachable only from the admin panel.

## Database Changes
None. Notification preference overrides are stored by OpenRegister in `oc_preferences` (per-user IConfig values) — no scholiq tables, no migrations.

## Nextcloud Integration
- Controllers: none new (admin pickers reuse `SettingsController`; preferences via OpenRegister).
- Services: none new.
- Settings: `OCA\Scholiq\Settings\AdminSettings` (existing, `IDelegatedSettings`) + new `OCA\Scholiq\Sections\SettingsSection` (`IIconSection`), registered in `appinfo/info.xml` `<settings>`.
- Events/Hooks: existing `GradeRollupHandler`, `CredentialIssuanceHandler`, `AttendanceFlagCreationHandler`, `XapiCompletionHandler` continue to dispatch OR transitions; OR's engine turns the declared rules into notifications. No changes to these handlers for notification emission.

## Security Considerations
- Moving register/AI/credential-signing into the admin panel is a **security improvement**: these instance-wide controls move from a user-reachable dialog to `#[AuthorizedAdminSetting(AdminSettings::class)]`-guarded surfaces. The mutating endpoints in `SettingsController`/`KeyAdminController` MUST carry the admin guard (verify during apply).
- The per-user notification-preferences panel only reads/writes the signed-in user's own overrides via OpenRegister, which scopes by the authenticated UID — no IDOR surface.
- Credential-signing key rotation stays admin-only; the RS256 key remains in Nextcloud's keystore.

## NL Design System
- Dashboards: `CnDashboardPage`, `CnWidgetWrapper`, existing `KpiCard` + manage widgets; teacher/student tiles reuse the same primitives. No hardcoded colours (ADR-003).
- User-settings panel: `NcSettingsSection` + `NcCheckboxRadioSwitch` toggles, `icon-*` monochrome nav. WCAG 2.1 AA: every `NcSelect` keeps its `inputLabel`/`aria-label-combobox` (hydra nc-input-labels gate); modals stay isolated.

## File Structure
```
src/
  manifest.json                         # dashboard page rework, single Dashboards menu, monochrome icons, settings slot repoint, root dispatcher
  views/
    ScholiqDashboards.vue               # NEW role-aware host (one CnDashboardPage; admin/teacher/student widget sets + role switcher)
    ScholiqDashboard.vue                # RETIRED inner CnDashboardPage wrapper (delete/replace)
    DashboardDispatcher.vue             # NEW tiny root-route redirector by primaryRole
    ScholiqNotificationSettings.vue     # NEW per-user notification-preferences panel (consumes OR /api/notification-preferences)
    ScholiqSettings.vue                 # REMOVED from user dialog; sections move to AdminRoot
    widgets/
      teacher/*.vue                     # NEW teacher tiles (my courses, to-grade, sessions-to-mark, my cohorts)
    settings/
      AdminRoot.vue                     # hosts register picker + AI features + credential signing (admin only)
  registry.js                           # register new components
appinfo/info.xml                        # <settings> registration
lib/Sections/SettingsSection.php        # NEW IIconSection
lib/Settings/scholiq_register.json      # verify/complete declarative x-openregister-notifications for the 4 core events
```

## Seed Data
No new OpenRegister schemas are introduced; existing seed data (`_registers.json`) is sufficient. To make the role-aware dashboards and notifications testable on install, verification relies on three users mapped to the `scholiq-{role}` NC groups (per `RoleSelector`):

- **admin** (NC admin group) → resolves to `admin` → admin overview dashboard.
- **teacher1** (group `scholiq-instructor`, with a `LearnerProfile.roles: ["instructor"]`) → teacher dashboard.
- **student1** (`LearnerProfile.roles: ["learner"]`, no group) → student dashboard.

Notification verification reuses existing seed Credential/Grade/AttendanceFlag objects: issuing a seed credential to `student1` should raise an `nc-notification` unless `student1` has toggled it off.

## Trade-offs
- **One role-aware component vs separate per-role menus**: ADR-009 §6 is binding and requires the single-component approach; it also avoids duplicating the dashboard menu surface and supports multi-role users. Chosen over separate menus (which would need a superseding ADR).
- **Consume OR notification-preferences vs build a scholiq preference store**: consuming OR's existing override gate is the only option that actually gates delivery (ADR-022) and avoids a non-functional parallel store; the verified dialect deliberately dropped per-rule `userPreferenceKey` in favour of this central gate.
- **Retire ScholiqDashboard.vue vs keep + un-nest**: retiring the inner-`CnDashboardPage` wrapper and declaring widgets on the manifest page matches the reference apps and is what the dashboard-antipattern gate checks; keeping the wrapper would re-introduce risk.
