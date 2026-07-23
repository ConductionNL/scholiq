# Design: nav-restructure-dashboards

## Architecture Overview

Scholiq's left navigation is assembled in three declarative layers and rendered by `CnAppNav`:

1. **`src/manifest.json`** — base `menu[]` + `pages[]` (ADR-037: never hand-add feature content here; base *page removal* is allowed).
2. **`src/manifest.d/*.json`** — feature fragments merged over the base (ADR-037).
3. **`src/menu-layout.json`** — the canonical layout applied *after* all fragments merge (ADR-044): `relocations` (sourceId → targetGroupId, `{}` lifts to top level), `removals` (leaf ids retired from nav; their pages stay routable), `settingsSection` (ids lifted into the NC settings foldout).

Page components resolve through `src/registry.js` (ADR-036: `kind:"page"` entries keyed by the manifest page `component` string). This change touches all three nav layers plus the registry and adds two `.vue` page components; it makes **no backend, schema, register, or seed-data change** (Scholiq is a thin client owning no tables).

Post-change top-level nav:

```
Dashboard        (route /, ScholiqDashboards — role-aware, was hidden under Insight)
Learning         (navigable → /learning LearningDashboard; collapsible: Courses, Curriculum, Learning plans, Assignments, Assessments, Grades)
People           (navigable → /people PeopleDashboard; collapsible: Learners, Enrolments, Attendance, Credentials)
Compliance       (route /compliance, ScholiqCompliance — was under Insight; role-gated)
… (Phase-B items untouched here)
footer:   Documentation, Features & roadmap
settings: DataExchange, XapiStatementsMenu, AssistantMenu, School-year rollover
```

`GroupInsight` is emptied (its three children: `Dashboard` → relocated top level, `Compliance` → relocated top level, `AdminHealthMenu` → removed) and therefore disappears.

## ADR compliance & amendments

### ADR-009 amendment (information architecture) — REQUIRED by this change

ADR-009 is **binding for every PR that introduces or moves a top-level menu** and states a new top-level surface "requires a superseding ADR." This change restructures the top level, so it amends ADR-009 as follows, and the ADR file itself is updated as an implementation task:

- **§1 (six fixed menus):** the live app ships an English top level (`Learning`/`People`/`Insight`/…) rather than ADR-009's Dutch six-menu model (Cursussen/Studenten/Examens/Praktijk/Aanleveringen/Beheer). This change amends the *live* IA to a **dashboard-first** top level: `Dashboard` and `Compliance` are promoted to top level (from the dissolved `Insight` group), and `Learning`/`People` remain top-level but become navigable domain dashboards. No net-new seventh destination is invented — `Dashboard`/`Compliance` were already present one level down under `Insight`.
- **§6 (role-aware dashboards):** unchanged for `ScholiqDashboards` (still one role-aware `CnDashboardPage` at `/`), now correctly surfaced as a top-level `Dashboard` item. Two **domain** dashboards (`LearningDashboard`, `PeopleDashboard`) are added as the landing pages for the `Learning`/`People` groups — each a single `CnDashboardPage`, not a per-role split.
- **§5 (adapters/config live in settings):** reinforced — `School-year rollover` moves into the settings foldout; `Features & roadmap` moves to the footer beside `Documentation` (fleet convention: pipelinq/opencatalogi/docudesk).

The ADR-009 §7 placement table is written in Dutch menu vocabulary; the update task annotates it for the English live menu rather than rewriting every slug (see Open Questions).

### ADR-044 supersession (learning-people cards-collapse)

ADR-044 / the `learning-people-cards-collapse` change made `Learning`/`People` collapse to tile-grid landing pages (`LearningCards`/`PeopleCards`) with their leaves removed from nav. **This change supersedes that cards-collapse decision** (its REQ-LPC-001/REQ-LPC-002): the leaves return as collapsible sub-children and the landing pages become `CnDashboardPage` domain dashboards. `CnAppNav` (`../nextcloud-vue/src/components/CnAppNav/CnAppNav.vue`) already renders a parent that is simultaneously navigable (`:to`) and collapsible (`:allow-collapse` when it has visible children), so no `CnAppNav` change is needed. REQ-LPC-003 (all leaf routes remain routable) is preserved and re-asserted by the `navigation` INVARIANT requirement.

### ADR-037 (modular fragments)

Feature content is edited via `src/manifest.d/*.json`, not `src/manifest.json`. The two fragments are renamed `learning-cards.json` → `learning-dashboard.json` and `people-cards.json` → `people-dashboard.json`, repointing `GroupLearning`/`GroupPeople` `route` and the page `component`. The **only** base-`manifest.json` edit is the `AdminHealth` page + `AdminHealthMenu` menu *removal* — permitted because base *page removal* is a base-manifest edit, whereas base *feature additions* are not.

### Mixed-spec rationale (kind: code)

Declared **`kind: code`**. The change's primary surface is frontend code: two new `CnDashboardPage` domain-dashboard `.vue` components + `registry.js` wiring + retiring three view files. The accompanying declarative config edits (`menu-layout.json` relocations/removals/settingsSection, the two `manifest.d` fragments, and the single base-manifest `AdminHealth` page removal) are tightly-coupled nav glue that points the navigation at those new components and ships in the same webpack build as one reviewable unit — hence one `code` change, not a split.

## API Design

None. This change introduces or modifies no API endpoint.

## Database Changes

None. Scholiq owns no database tables; this change adds none.

## Nextcloud Integration

- Controllers: none changed.
- Services: none changed.
- Mappers/Entities: none (thin client; data via OpenRegister `ObjectService` through existing widgets).
- Events/Hooks: none.
- Frontend: `CnAppNav`, `CnDashboardPage`, `NcSelect` from `@conduction/nextcloud-vue` / `@nextcloud/vue`; page dispatch via `src/registry.js` (ADR-036); role/initial-state via `@nextcloud/initial-state` (`loadState`) as in `ScholiqDashboards.vue` (IInitialState pattern, ADR-004).

## Security Considerations

No new endpoint, no new data access, no auth surface added. Existing role gating is preserved: `Compliance` keeps its `visibleIf` (compliance-officer/hr) and `Rollover` keeps its admin `visibleIf`. Removing the admin-only `AdminHealth` page **narrows** surface area. The new domain dashboards read the same OpenRegister-backed object counts the existing widgets already read; no additional data is exposed. No security impact beyond surface reduction.

## NL Design System

- `CnDashboardPage` grid + KPI tiles reuse NL Design tokens via `@conduction/nextcloud-vue` (no hardcoded colours).
- Monochrome `icon-*` nav icons only (nextcloud-app spec: "Consistent monochrome navigation icons"); the retained `Learning`/`People` group icons (`icon-folder`/`icon-contacts`) are unchanged.
- ADR-004 for any new Vue: modals in their own files, `NcSelect` with `inputLabel`, no DOM data-attribute reads, `IInitialState`/`loadState` pattern. (No modal or `NcSelect` is added by the two dashboards, which mirror `ScholiqDashboards`.)
- WCAG 2.1 AA: collapsible-and-navigable parents keep both affordances keyboard-operable via `CnAppNav`.

## File Structure

```
src/
  manifest.json                         (M) remove AdminHealthMenu menu entry + AdminHealth page
  menu-layout.json                      (M) relocations {Dashboard,Compliance→top}; drop 10 leaf ids from removals;
                                            add Rollover to + remove FeaturesRoadmapMenu from settingsSection
  registry.js                           (M) add LearningDashboard, PeopleDashboard; remove ScholiqAdminHealth,
                                            LearningCards, PeopleCards entries + imports
  manifest.d/
    learning-dashboard.json             (R) renamed from learning-cards.json; GroupLearning.route + page.component → LearningDashboard
    people-dashboard.json               (R) renamed from people-cards.json; GroupPeople.route + page.component → PeopleDashboard
  views/
    LearningDashboard.vue               (A) single CnDashboardPage — learning KPIs + manage-lists
    PeopleDashboard.vue                 (A) single CnDashboardPage — people KPIs + manage-lists
    ScholiqAdminHealth.vue              (D) deleted
  components/
    learning/LearningCards.vue          (D) retired
    people/PeopleCards.vue              (D) retired
openspec/architecture/
  adr-009-information-architecture.md   (M) amend: dashboard-first top level; Insight dissolved; Learning/People
                                            navigable domain dashboards; rollover in settings; features in footer
tests/e2e/
  pages.spec.ts                         (M) remove { name: 'AdminHealth', path: '#/admin/health' }
  (new/updated Gate-19 e2e)             (A/M) restructured nav + two domain dashboards; deepLinks assertion
```

## Seed Data

Not applicable. This change introduces no OpenRegister schema, register, or entity — there is **no seed-data section, no `_registers.json` entry, and no declarative-vs-imperative behaviour section**. ADR-001 / ADR-031 (declarative register config, notification dialect) are **N/A**: no register is created or modified, so downstream apply MUST NOT invent register work. The two new dashboards read counts of *existing* schemas (course/enrolment/learner-profile/credential/etc.) through the widgets already shipping in `src/views/widgets/*`.

## Migration Plan

No data or schema migration (the `migration` artifact is skipped — its `skipWhen` "no database tables, columns, OpenRegister schema definitions, or data transformations" holds). Deployment is a single webpack build. Rollback = revert the change branch and rebuild; zero data impact because no register/schema/OpenRegister-side change occurs.

## Trade-offs

- **Navigable-and-collapsible parent vs. separate landing item.** Chosen: `CnAppNav`'s built-in dual affordance (`:to` + `:allow-collapse`) so `Learning`/`People` are one item that both lands on a dashboard and expands to leaves — no extra "overview" child, no `CnAppNav` change. Alternative (a synthetic "Overview" leaf) rejected as redundant navigation.
- **Domain dashboards vs. keeping ADR-044 card grids.** Chosen: `CnDashboardPage` domain dashboards (KPIs + manage-lists) reusing existing widgets, matching the `ScholiqDashboards` pattern and giving the landing page information value. Card grids added a hop without data.
- **Amend ADR-009 in-place vs. new superseding ADR.** Chosen: amend ADR-009 (dashboard-first, Insight dissolved) with a design-doc note + ADR-file update task, because no genuinely new top-level destination is added (Dashboard/Compliance already existed under Insight). A full superseding ADR would be heavier than the change warrants; recorded as a judgement.
- **Single base-manifest edit for App-health removal.** Accepted as the ADR-037-permitted exception (base page *removal*), rather than an awkward fragment that "un-declares" a base page.

## Open Questions

- ADR-009 §7's placement table uses Dutch menu vocabulary (Cursussen/Studenten/…) that never matched the live English menu. The ADR-update task annotates the amendment against the English live menu rather than rewriting all twenty slug rows; whether product wants the full table re-expressed in English is deferred to the ADR-update reviewer.
