# Design: scholiq-nav-grouping

## Context

`src/manifest.json` `menu[]` currently holds 19 flat top-level entries. The
manifest-v2 schema (`nextcloud-vue/src/schemas/app-manifest-v2.schema.json`)
natively supports **one level** of nesting: a `menuItem` may carry a `children[]`
array of `menuItemLeaf` entries, and `CnAppNav` "reads `manifest.menu[]`; sorts by
`order`; filters by `permission`; one level of `children[]`." A parent group entry
is a `menuItem` with `label` + `icon` + `children[]` and (optionally) `open: true`
for initial expansion; it carries **no `route`** so it acts purely as a collapsible
section header rather than a navigable page. This is the app's real and only nav
mechanism — there is no `menu-layout.json` and no `manifest.d/` fragment dir to
reuse (ADR-037: keep the canonical nav layout where the app already keeps it).

## Key decisions

1. **Use native `children[]` nesting, not a flat re-order with captions.** The
   schema also offers `type: "caption"` dividers, but those are flat, non-collapsible
   labels. `children[]` gives a true collapsible group, which is what 19 entries
   need. Decision: parent groups via `children[]`.

2. **Parent groups have no `route`.** A group is a container, not a destination.
   Omitting `route` (allowed — `menuItem` requires only `id` + `label`) means
   clicking the group toggles its children rather than navigating. The former
   top-level destinations remain reachable as children.

3. **`open: true` on all three groups.** With only three groups and ten visible
   leaves, defaulting to expanded keeps everything one glance away while still
   giving visual grouping. (Reviewers may later collapse low-traffic groups; out of
   scope here.)

4. **Preserve `visibleIf` and `section` on moved leaves.** Compliance keeps its
   `compliance-officer | hr` gate; App health keeps its `admin` gate. App health was
   `section: "settings"` as a top-level entry; as a child of **Insight** it moves
   into the main list (children inherit the group's main placement) but keeps its
   `visibleIf` admin gate, so non-admins still never see it. This is an intentional,
   minor placement change — App health is an insight surface, and grouping it under
   Insight is clearer than burying it in the gear foldout.

5. **Leave sibling-owned + footer entries flat.** AI features, Assistant (→
   `scholiq-merge-ai-surfaces`), Data exchange, xAPI statements (→
   `scholiq-integration-to-settings`), Documentation, and Features & roadmap stay
   exactly as they are (their `section`, `order`, `visibleIf`, `href` untouched), so
   this change neither collides with the sibling changes nor disturbs the footer.

## Proposed grouping (id → group)

| Group (parent) | Child menu id | Child label | Route (unchanged) |
|----------------|---------------|-------------|-------------------|
| **Learning** (`GroupLearning`) | `Courses` | Courses | `Courses` → `/courses` |
| | `Curriculum` | Curriculum | `Programmes` → `/curriculum/programmes` |
| | `LearningPlans` | Learning plans | `LearningPlans` → `/learning-plans` |
| | `Assignments` | Assignments | `Assignments` → `/assignments` |
| | `Assessments` | Assessments | `Assessments` → `/assessments` |
| | `Grades` | Grades | `GradeEntries` → `/grades/entries` |
| **People** (`GroupPeople`) | `LearnerProfilesMenu` | Learners | `LearnerProfiles` → `/learner-profiles` |
| | `Enrolments` | Enrolments | `Enrolments` → `/enrolments` |
| | `Attendance` | Attendance | `AttendanceRecords` → `/attendance/records` |
| | `Credentials` | Credentials | `Credentials` → `/credentials` |
| **Insight** (`GroupInsight`) | `Dashboard` | Dashboards | `Dashboard` → `/` |
| | `AdminHealthMenu` | App health (admin-gated) | `AdminHealth` → `/admin/health` |
| | `Compliance` | Compliance (officer/hr-gated) | `Compliance` → `/compliance` |

### Left flat (untouched by this change)

| Menu id | Label | Owner / reason |
|---------|-------|----------------|
| `DataExchange` | Data exchange | → `scholiq-integration-to-settings` |
| `XapiStatementsMenu` | xAPI statements | → `scholiq-integration-to-settings` |
| `AiFeaturesMenu` | AI features | → `scholiq-merge-ai-surfaces` |
| `AssistantMenu` | Assistant | → `scholiq-merge-ai-surfaces` |
| `Documentation` | Documentation | footer (`href`, external) |
| `FeaturesRoadmapMenu` | Features & roadmap | footer |

## Alternatives considered

- **Flat `type: "caption"` dividers** — rejected: non-collapsible, still a long
  list; `children[]` is the schema's intended grouping primitive.
- **A new `src/menu-layout.json`** — rejected: the app has no relocations file and
  the grouping is fully expressible inline in `manifest.json`; introducing a second
  nav source would duplicate the mechanism (ADR-012).
- **Folding App health into the settings gear foldout instead of Insight** —
  rejected: it is an observability surface; Insight is the clearer home, and the
  admin `visibleIf` already restricts visibility.

## Migration / rollout

Single declarative edit to `src/manifest.json` `menu[]`. No data migration, no
repair step, no backend change. The manifest is validated at build/runtime by
`validateManifest`; the nested shape is a first-class schema construct, so existing
validation covers it. Pages remain routable, so any bookmarked/deep-linked route
continues to resolve regardless of menu shape. Revert = `git revert` of the single
commit.

## Risks

### Risk 1: A consumer relies on a moved leaf's top-level `order`/`section`
**Severity:** Low — **Mitigation:** `order`/`section` on a menu entry only drive
nav placement, not routing; nothing in `pages[]` or routes references them. Child
ordering is set explicitly within each group.

### Risk 2: App health placement change surprises admins
**Severity:** Low — **Mitigation:** the `admin` `visibleIf` gate is preserved, so
the audience is unchanged; only its location (settings foldout → Insight group)
moves, which is documented and intentional.
