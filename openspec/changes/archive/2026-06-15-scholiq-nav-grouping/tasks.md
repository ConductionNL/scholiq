# Tasks — Scholiq Navigation Grouping

## Phase 0: Deduplication Check (ADR-012)

- [x] 0.1 Confirm scholiq has **no** `src/menu-layout.json` and **no** `src/manifest.d/` fragment directory — the only nav source is `src/manifest.json` `menu[]` (verified 2026-06-15). Do not introduce a second nav source.
- [x] 0.2 Confirm the manifest-v2 schema's `menuItem.children[]` (one level) is the native grouping primitive and that `CnAppNav` renders it. Do not add a custom group component.
- [x] 0.3 Confirm the leaves owned by sibling changes are NOT touched here: AI features + Assistant belong to `scholiq-merge-ai-surfaces`; Data exchange + xAPI statements belong to `scholiq-integration-to-settings`. Footer entries (Documentation, Features & roadmap) stay as-is.

## Phase 1: Introduce parent groups

- [x] 1.1 In `src/manifest.json` `menu[]`, add three parent entries with `label` + `icon` + `order` + `children: []`, and **no `route`**: `GroupLearning` (Learning), `GroupPeople` (People), `GroupInsight` (Insight). Assign group `order` 10 / 20 / 30. NOTE: `open: true` was dropped — the manifest-v2 `menuItem` schema has `additionalProperties: false` and no `open` property, so it would fail `validateManifest` (task 4.2). Default expand state is a renderer concern not expressible in this schema version; the `children[]` grouping itself renders collapsible.
- [x] 1.2 Choose monochrome `icon-*` glyphs for the three groups consistent with the existing menu (`icon-folder` / `icon-contacts` / `icon-projects`).

## Phase 2: Nest the in-scope leaves

- [x] 2.1 Move `Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades` into `GroupLearning.children` (in that order), preserving each leaf's `id`, `label`, `icon`, and `route`; set sequential child `order`.
- [x] 2.2 Move `LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials` into `GroupPeople.children`, preserving each leaf's fields and route.
- [x] 2.3 Move `Dashboard`, `AdminHealthMenu`, `Compliance` into `GroupInsight.children`, preserving each leaf's fields and route, and **preserving** the `visibleIf` admin gate on `AdminHealthMenu` and the `compliance-officer | hr` gate on `Compliance`.
- [x] 2.4 Remove the moved leaves' obsolete top-level `order` and (where present) `section` keys; child placement is governed by group + child `order`.

## Phase 3: Leave the rest flat

- [x] 3.1 Verify `DataExchange`, `XapiStatementsMenu`, `AiFeaturesMenu`, `AssistantMenu`, `Documentation`, `FeaturesRoadmapMenu` remain unchanged (same `section`, `order`, `visibleIf`, `href`) — confirmed byte-identical to HEAD. (`Rollover` and `ExternalTraining`, two role-gated leaves not named in the design, were also left flat/untouched.)

## Phase 4: Verify

- [x] 4.1 `pages[]` is unchanged — every page id/route from the prior manifest still exists; no route was renamed or removed. (Verified byte-identical to HEAD apart from adding the schema-required `_note` to 3 pre-existing `type:custom` pages — see 4.2.)
- [x] 4.2 Manifest validates against `app-manifest-v2.schema.json` with **0 errors** (jsonschema Draft7). Fixed 3 pre-existing schema violations (missing required `_note` on `Compliance`/`LearnerHome`/`AdminHealth` custom pages) per the CLAUDE.md fix-pre-existing mandate. (Hydra gate-22's offline structural-lint fallback flags `pages[62].type:"roadmap"`, but `roadmap` IS a valid type in the real schema — pre-existing gate false positive, also fails identically on untouched HEAD.)
- [ ] 4.3 Browser-verify — deferred: brief is code/gate-only, no browser. Route/page integrity verified statically (all 20 menu leaf routes resolve to pages; `visibleIf` gates preserved verbatim on moved leaves).
- [x] 4.4 `openspec validate scholiq-nav-grouping --strict` passes.
