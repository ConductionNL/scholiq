---
kind: code
depends_on: []
---

# Proposal: fix-dashboards-settings-notifications

## Summary

Fix four UX/architecture defects in the Scholiq app shell: (1) the home Dashboard renders three nested "Dashboard" headings (the hydra dashboard-antipattern), (2) there is no role-aware dashboard so docent/student/administrator all see the same admin KPI grid, (3) admin-level configuration (default register, AI-feature register, credential-signing key) is exposed in the per-user "User settings" dialog instead of the Nextcloud Admin panel, and (4) the navigation icons mix coloured `icon-category-*` glyphs with monochrome `icon-*` glyphs, producing an inconsistent multi-colour menu. The change also adds the missing per-user notification preferences and wires real Nextcloud notifications from the existing domain handlers.

## Motivation

- **Dashboard nesting** is a confirmed `hydra-gate-dashboard-antipattern` violation: the manifest `Dashboard` page (a `CnDashboardPage`) hosts a single widget titled "Dashboard" whose slot renders `ScholiqDashboard.vue`, which renders *another* `CnDashboardPage`. Users see "Dashboard / Dashboard / Dashboard" stacked.
- **Role-aware dashboards** are mandated by **ADR-009 §6**: "Docent, mentor, coordinator, and student dashboards live in `Studenten > Dashboards` as one role-aware component, **not four separate apps or menus** … the component re-renders for the active role." The app currently ships one admin-only KPI grid; a `learner` or `instructor` sees admin metrics that are irrelevant (and mostly empty) to them.
- **Settings placement**: the register picker, AI-feature table, and RS256 credential-signing key rotation are instance-wide admin concerns, but they live in the user-scoped settings dialog. Any user can open them. They belong behind `#[AuthorizedAdminSetting]` in the Admin panel (the app already ships an unwired `AdminSettings.php`). The freed user-settings surface should hold what a user *should* control: which notifications they receive.
- **Icon consistency**: ADR-003 requires NL Design tokens / Nextcloud CSS variables and a coherent visual system; the mixed icon families read as a bug.

## Affected Projects
- [ ] Project: `scholiq` — manifest dashboard page rework, role-aware Dashboards component, menu icon normalisation, Admin-settings registration + section move, per-user notification preferences, `INotifier` + handler wiring.

## Scope

### In Scope
- Remove the dashboard antipattern: inline the KPI + manage widgets directly into the manifest dashboard page (`config.widgets`/`layout`/`slots`), each slot → its own plain widget component; retire `ScholiqDashboard.vue`'s inner `CnDashboardPage`.
- One **role-aware Dashboards** component (ADR-009 §6): auto-selects the admin / teacher (instructor, manager) / student (learner) view from `user.primaryRole`, with a role switcher for users holding multiple roles. Single `Dashboards` menu item; root `/` lands the user on it.
- Move default-register + AI-features + credential-signing into the Nextcloud **Admin** settings panel (register `AdminSettings` + a new `Sections/SettingsSection`).
- Replace the per-user settings dialog content with a **notification-preferences panel** that consumes OpenRegister's existing override-only `/api/notification-preferences` endpoint (per-`(schema, notification)` per-user toggles that genuinely gate delivery via OR's dispatcher `preference-off` gate). No new scholiq backend — ADR-022 (apps consume OR abstractions).
- Verify the existing **declarative** `x-openregister-notifications` rules (10 verified-dialect blocks already in `scholiq_register.json`) actually fire and render via OpenRegister's `AnnotationNotificationDispatcher` + `AnnotationNotifier`, and add any missing rule for the four target events (grade posted, credential issued, attendance flag raised, course/lesson completion). Notifications stay declarative per ADR-031 — **no imperative `INotifier` in scholiq**.
- Normalise every manifest `menu[].icon` onto the monochrome `icon-*` family.

### Out of Scope
- The full ADR-009 six-menu IA migration (collapsing the ~12 current top-level English menus into Cursussen/Studenten/Examens/Praktijk/Aanleveringen/Beheer). Deferred to a dedicated IA change; this change only makes the dashboard piece ADR-009-compliant and leaves the other menus where they are.
- New notification *types* beyond the four existing handlers; digest/email channels.
- AI-feature lifecycle editing UI (remains read-only).

## Approach

Frontend changes are almost entirely declarative manifest edits plus a small number of Vue components (role-aware dashboard host, per-role widget bundles, notification-preferences panel). Backend adds one `Notifier` class, one settings `Section` class, an `appinfo/info.xml` `<settings>` registration, and notification-dispatch calls inside the four existing handlers gated by a per-user preference read. Details in design.md.

## New Dependencies
None. Uses existing `@conduction/nextcloud-vue` components, the existing Preferences API, and the Nextcloud `OCP\Notification` framework (already a platform dependency).

## Impact
- `src/manifest.json` (dashboard page, menu entries, menu icons, settings page slot).
- `src/views/` — retire `ScholiqDashboard.vue` inner page; add role-aware `ScholiqDashboards.vue` + teacher widget bundle; add `ScholiqNotificationSettings.vue`; move admin sections into `settings/AdminRoot.vue`.
- `appinfo/info.xml` — `<settings>` registration.
- `lib/Sections/SettingsSection.php` (new), `lib/Settings/AdminSettings.php` (already exists; wire up).
- `lib/Settings/scholiq_register.json` — verify / complete the declarative `x-openregister-notifications` rules (no imperative dispatch code; OpenRegister owns delivery + rendering).

## Cross-Project Dependencies
None. Self-contained within scholiq; consumes OpenRegister (data) and the Nextcloud notification framework, both already required.

## Risks

### Risk 1: primaryRole not available client-side for the dispatcher
**Severity:** Medium — **Mitigation:** the manifest already evaluates `visibleIf: user.primaryRole` (verified: the admin user correctly does not see the learner-gated "My learning"), so the shell exposes the role. The dispatcher reads the same source; if absent it falls back to the student view (least-privilege).

### Risk 2: Notification spam from bulk lifecycle events
**Severity:** Medium — **Mitigation:** OpenRegister's dispatcher already addresses each notification to the single affected recipient (kind:field), applies rate-limit + coalesce gates, and honors the override-only per-user preference (the panel this change adds). No broadcast.

### Risk 3: Moving admin settings breaks the existing in-app settings route
**Severity:** Low — **Mitigation:** the in-app settings page is repointed (not deleted) to the notification-preferences component; the admin sections move to the already-built `AdminRoot.vue` bundle behind the registered Admin section.

## Rollback Strategy
All changes are confined to scholiq and are revertable via `git revert` of the change commits. The manifest dashboard/menu edits, settings registration, and notifier registration are independent commits per phase, so any single phase can be reverted without affecting the others. No data migrations are introduced.

## Capabilities
- Modified: `dashboard` (antipattern fix + role-aware component, ADR-009 §6)
- Modified: `nextcloud-app` (settings split admin↔user, menu icon normalisation)
- Modified: `scholiq-notifications` (per-user preferences + real notification emission)
