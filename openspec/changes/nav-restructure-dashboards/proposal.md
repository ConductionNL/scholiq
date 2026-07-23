---
kind: code
---

# Proposal: nav-restructure-dashboards

## Summary

Phase A of a two-phase Scholiq navigation cleanup: restructure the information architecture so the app opens on a dashboard and the top level reads as clear destinations rather than a mixed "Insight" bucket. App-health stops being an in-app surface (it is managed from OpenRegister's admin Data-health form), the `Insight` group is dissolved into a top-level **Dashboard** and top-level **Compliance**, the **Learning** and **People** groups become navigable domain dashboards whose former leaves return as collapsible sub-items, **Features & roadmap** drops to the footer next to Documentation, and **School-year rollover** moves into the Settings foldout. This is a frontend/nav-glue change only; feature *removal* (Assistant, AI features, data-exchange) is a separate Phase B change and is explicitly out of scope here.

## Motivation

Scholiq's current top level mixes true destinations (`Learning`, `People`) with an `Insight` group that hides the primary dashboard behind a click, an admin-only `App health` page that duplicates OpenRegister's own Data-health settings, and `Compliance` buried two levels deep. ADR-044's cards-collapse turned `Learning`/`People` into tile-grid landing pages that add a hop without adding information. Meanwhile `Features & roadmap` sits inline instead of in the footer where every other Conduction app (pipelinq, opencatalogi, docudesk) keeps it, and `School-year rollover` is a top-level item despite being an admin maintenance action. The net effect is a navigation surface that neither matches the ADR-009 dashboard-first intent (§6) nor the fleet-wide footer/settings conventions. Doing it now, ahead of Phase B feature removal, keeps the IA change reviewable as one webpack unit before feature deletions land.

## Affected Projects

- [ ] Project: `scholiq` — nav restructure (menu-layout relocations/removals/settingsSection), two new `CnDashboardPage` domain dashboards, base-manifest App-health page/menu removal, two `manifest.d` fragment renames, registry wiring, retirement of three view/component files, ADR-009 amendment + ADR-044 supersession.

No other apps-extra project is touched. Schema/data-health governance moves to OpenRegister's existing admin Data-health settings form — no OpenRegister code change is required by this change.

## Scope

### In Scope

1. **App-health removed** — remove the `AdminHealthMenu` menu entry and the `AdminHealth` page (route `/admin/health`) from `src/manifest.json`; drop the `ScholiqAdminHealth` registry entry and its import from `src/registry.js`; delete `src/views/ScholiqAdminHealth.vue`. Schema/data-health is managed from OpenRegister's admin Data-health settings form instead.
2. **Dashboard-first landing; dissolve Insight** — keep the existing `Dashboard` page (route `/`, component `ScholiqDashboards`) and surface a top-level `Dashboard` nav item plus a top-level `Compliance` item via `src/menu-layout.json` relocations; the emptied `GroupInsight` shell disappears.
3. **Learning becomes a domain dashboard** — drop the six Learning leaves (`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) from `menu-layout.json#removals` so they render as collapsible sub-items, and repoint `GroupLearning` to land on a new `LearningDashboard.vue` (`CnDashboardPage`) instead of `LearningCards`. Rename fragment `learning-cards.json` → `learning-dashboard.json`, register the new component, retire `LearningCards.vue`.
4. **People — identical treatment** — drop the four People leaves (`LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials`) from `#removals`; new `PeopleDashboard.vue`; fragment `people-cards.json` → `people-dashboard.json`; retire `PeopleCards.vue`.
5. **Features & roadmap → footer** — remove `FeaturesRoadmapMenu` from `menu-layout.json#settingsSection` so it falls back beside `Documentation` (it already carries `section:"footer"` in the base manifest).
6. **School-year rollover → Settings foldout** — add `Rollover` (route `RolloverWizard`, already admin-gated) to `menu-layout.json#settingsSection`.

### Out of Scope

- Feature removal (Assistant / `AssistantMenu`, AI features / `AiFeature`, data-exchange / `DataExchange`) — that is Phase B, a separate change.
- Any OpenRegister schema, register, or seed-data change; any `_registers.json` edit; any backend/PHP change. Scholiq owns no tables and this change adds none.
- The role-aware `ScholiqDashboards` internals (role switcher, per-role widgets) — unchanged; only its top-level nav placement changes.
- Building the OpenRegister admin Data-health settings form (it already exists; this change only stops duplicating it).

## Approach

Nav is driven declaratively by `src/menu-layout.json` (ADR-044 post-merge layout) over base `src/manifest.json` + `src/manifest.d/*.json` fragments (ADR-037). Points 2/3/4/5/6 are `menu-layout.json` edits (relocations, `#removals` deletions, `settingsSection` add/remove) plus two fragment renames that repoint `GroupLearning`/`GroupPeople` `route` at the new dashboard pages. Point 1 is the only base-`manifest.json` edit (page/menu removal is allowed; feature *additions* are not). The two new domain dashboards follow the existing `src/views/ScholiqDashboards.vue` + `src/views/widgets/*` pattern — a single `CnDashboardPage` per page (no dashboard-in-dashboard), KPI tiles + manage-list widgets. `CnAppNav` already renders a parent that is both navigable (`:to`) and collapsible (`:allow-collapse` when it has visible children), so a group with a `route` + un-removed children works natively. Details in design.md.

## New Dependencies

None. Reuses `@conduction/nextcloud-vue` (`CnDashboardPage`, `CnAppNav`) and existing Scholiq KPI/manage widgets.

## Impact

- `src/manifest.json` — remove `AdminHealthMenu` menu entry + `AdminHealth` page.
- `src/menu-layout.json` — relocations (`Dashboard`, `Compliance` → top level), remove 10 leaf ids from `#removals`, add `Rollover` to and remove `FeaturesRoadmapMenu` from `settingsSection`.
- `src/manifest.d/learning-cards.json` → `learning-dashboard.json`; `src/manifest.d/people-cards.json` → `people-dashboard.json` (repoint group `route`, new page `component`).
- `src/registry.js` — add `LearningDashboard`/`PeopleDashboard`; remove `ScholiqAdminHealth`, `LearningCards`, `PeopleCards` entries + imports.
- New: `src/views/LearningDashboard.vue`, `src/views/PeopleDashboard.vue`.
- Deleted: `src/views/ScholiqAdminHealth.vue`, `src/components/learning/LearningCards.vue`, `src/components/people/PeopleCards.vue`.
- `openspec/architecture/adr-009-information-architecture.md` — amended (dashboard-first top level, Insight dissolved, Learning/People navigable domain dashboards, Rollover in settings, Features&roadmap in footer).
- `tests/e2e/pages.spec.ts` — remove the `AdminHealth` (`#/admin/health`) entry; new/updated Gate-19 e2e for the restructured nav + two domain dashboards; verify manifest `deepLinks` (4 entries) reference no removed route.

## Cross-Project Dependencies

None. Data-health governance already lives in OpenRegister's admin settings; this change simply stops shadowing it in-app and requires no coordinated OpenRegister edit.

## Risks

### Risk 1: Contradicting ADR-009 without amending it

**Severity:** Medium — **Mitigation:** ADR-009 is binding for every PR that moves a top-level menu and requires a superseding ADR to add a top-level surface. This change restructures the top level, so design.md carries an explicit ADR-009 amendment/supersession note and tasks.md includes updating the ADR file itself; ADR-044's cards-collapse decision is explicitly superseded and noted.

### Risk 2: Deep links or e2e still reference the removed App-health route

**Severity:** Medium — **Mitigation:** grep-verified references (`src/menu-layout.json`, `src/manifest.json`, `src/registry.js`, `tests/e2e/pages.spec.ts`, `openspec/coverage-report.json`); tasks.md includes removing the `AdminHealth` e2e entry and asserting the 4 `deepLinks` (course/enrolment/learner-profile/credential) touch no removed route.

### Risk 3: Domain-dashboard reintroduces the dashboard-in-dashboard antipattern

**Severity:** Low — **Mitigation:** both new pages are plain `CnDashboardPage` hosts (never rendered as a widget on another dashboard); the existing `dashboard` spec invariant and the hydra dashboard-antipattern gate are covered by tasks + test-plan.

## Rollback Strategy

Pure frontend change shipped in one webpack build. Revert the change branch (restores `manifest.json`, `menu-layout.json`, the two fragments, `registry.js`, and the three deleted files) and rebuild; no data migration, no register/schema change, and no OpenRegister-side change means rollback is a code revert with zero data impact.

## Open Questions

- The ADR-009 §7 mapping and §1 six-menu model are written in Dutch (Cursussen/Studenten/Examens/…), but the live manifest ships an English top level (Learning/People/Insight/…). This change amends the *live* IA; whether the ADR-009 mapping table is also re-expressed in the English menu vocabulary or only annotated is a judgement recorded for the ADR-update task.
