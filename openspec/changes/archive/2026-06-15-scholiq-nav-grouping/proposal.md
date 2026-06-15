---
kind: code
depends_on: []
---

# Proposal: scholiq-nav-grouping

kind: IA / manifest-grouping (ADR-037 modular config + canonical nav layout, ADR-012 deduplication)

## Summary

Scholiq's left navigation is **19 flat top-level entries** with no grouping:
Dashboards, Courses, Enrolments, Credentials, Curriculum, Grades, Assignments,
Assessments, Compliance, App health, Documentation, Features & roadmap, Learning
plans, Attendance, Data exchange, Learners, xAPI statements, AI features,
Assistant. That is an unscannable wall of items that buries the app's structure.

This change introduces three top-level **parent groups** in `src/manifest.json`'s
`menu[]` and nests the related leaves under them, using the manifest-v2 schema's
native one-level `children[]` nesting (the same mechanism the schema documents for
`CnAppNav`). The proposed groups:

- **Learning** — Courses, Curriculum, Learning plans, Assignments, Assessments, Grades
- **People** — Learners, Enrolments, Attendance, Credentials
- **Insight** — Dashboards, App health, Compliance

It is a **pure IA / manifest grouping change**: no schema is added, removed, or
altered; no controller or route changes; every page stays routable at its existing
route (deep links unaffected). Only the `menu[]` array shape changes — the `pages[]`
array is untouched.

**Depends on:** none. Self-contained within scholiq.

## Deduplication rationale (ADR-012)

This change does **not** duplicate any existing or sibling capability:

- It does **not** introduce a `src/menu-layout.json`. Scholiq has no such file
  today (verified: `src/` contains only `manifest.json`, no `menu-layout.json`, no
  `manifest.d/` fragments). The app's actual nav mechanism is the single
  `src/manifest.json` `menu[]` array, so the grouping is expressed there — reusing
  the app's existing mechanism, not adding a parallel one (ADR-037: canonical nav
  layout lives where the app already keeps it).
- It does **not** touch the leaves that **sibling changes** own. **`scholiq-merge-ai-surfaces`** is the canonical home for AI features + Assistant; **`scholiq-integration-to-settings`** is the canonical home for Data exchange + xAPI statements. This change leaves all four of those entries, plus Documentation and Features & roadmap (footer entries), exactly where they are so it does not collide with or pre-empt those changes.
- It does **not** re-implement the role-gating already declared on entries
  (`visibleIf` on Compliance / App health) — those conditions are preserved verbatim on the moved leaves.

## Out of scope

- AI features + Assistant regrouping → `scholiq-merge-ai-surfaces`.
- Data exchange + xAPI statements relocation → `scholiq-integration-to-settings`.
- Footer entries (Documentation, Features & roadmap) and the settings-section
  entries' placement — left as-is.
- Any schema, route, controller, or page change.

## Impact

- `src/manifest.json` — `menu[]` array only: add three parent group entries, nest
  the ten in-scope leaves as `children[]`, drop their top-level `route`/`order` in
  favour of group + child ordering. `pages[]` unchanged.

## Capabilities

- Modified: `nextcloud-app` (navigation information architecture — flat → grouped).
