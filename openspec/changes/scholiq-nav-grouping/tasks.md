# Tasks — Scholiq Navigation Grouping

## Phase 0: Deduplication Check (ADR-012)

- [ ] 0.1 Confirm scholiq has **no** `src/menu-layout.json` and **no** `src/manifest.d/` fragment directory — the only nav source is `src/manifest.json` `menu[]` (verified 2026-06-15). Do not introduce a second nav source.
- [ ] 0.2 Confirm the manifest-v2 schema's `menuItem.children[]` (one level) is the native grouping primitive and that `CnAppNav` renders it. Do not add a custom group component.
- [ ] 0.3 Confirm the leaves owned by sibling changes are NOT touched here: AI features + Assistant belong to `scholiq-merge-ai-surfaces`; Data exchange + xAPI statements belong to `scholiq-integration-to-settings`. Footer entries (Documentation, Features & roadmap) stay as-is.

## Phase 1: Introduce parent groups

- [ ] 1.1 In `src/manifest.json` `menu[]`, add three parent entries with `label` + `icon` + `order` + `children: []` and `open: true`, and **no `route`**: `GroupLearning` (Learning), `GroupPeople` (People), `GroupInsight` (Insight). Assign group `order` 10 / 20 / 30.
- [ ] 1.2 Choose monochrome `icon-*` glyphs for the three groups consistent with the existing menu (e.g. `icon-folder` / `icon-contacts` / `icon-projects`).

## Phase 2: Nest the in-scope leaves

- [ ] 2.1 Move `Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades` into `GroupLearning.children` (in that order), preserving each leaf's `id`, `label`, `icon`, and `route`; set sequential child `order`.
- [ ] 2.2 Move `LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials` into `GroupPeople.children`, preserving each leaf's fields and route.
- [ ] 2.3 Move `Dashboard`, `AdminHealthMenu`, `Compliance` into `GroupInsight.children`, preserving each leaf's fields and route, and **preserving** the `visibleIf` admin gate on `AdminHealthMenu` and the `compliance-officer | hr` gate on `Compliance`.
- [ ] 2.4 Remove the moved leaves' obsolete top-level `order` and (where present) `section` keys; child placement is governed by group + child `order`.

## Phase 3: Leave the rest flat

- [ ] 3.1 Verify `DataExchange`, `XapiStatementsMenu`, `AiFeaturesMenu`, `AssistantMenu`, `Documentation`, `FeaturesRoadmapMenu` remain unchanged (same `section`, `order`, `visibleIf`, `href`).

## Phase 4: Verify

- [ ] 4.1 `pages[]` is unchanged — every page id/route from the prior manifest still exists; no route was renamed or removed.
- [ ] 4.2 Manifest passes `validateManifest` (build + runtime); the nested `children[]` shape validates against `app-manifest-v2.schema.json`.
- [ ] 4.3 Browser-verify: the three groups render collapsible in `CnAppNav`; each child navigates to its existing route; admin-gated App health and officer/hr-gated Compliance still respect their `visibleIf`; deep-linking a child route directly still resolves.
- [ ] 4.4 `cd scholiq && openspec validate scholiq-nav-grouping --strict` passes.
