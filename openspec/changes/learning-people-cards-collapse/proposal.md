---
kind: code
depends_on:
  - adopt-shared-menu-pipeline
---

# Proposal: learning-people-cards-collapse

## Summary

Per ADR-044 ("Menu architecture") cards-collapse rule, scholiq's **Learning** group
(leaves: `Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`,
`Grades`) and **People** group (leaves: `LearnerProfilesMenu`, `Enrolments`,
`Attendance`, `Credentials`) each have more than two children and MUST collapse into a
single top-level menu item that links to a card-grid landing page — one card per former
leaf child.

This change declares:
1. **Learning** collapses from 6 child leaves into a single `GroupLearning` nav item
   pointing to a new `LearningCards` landing page (a card-grid with 6 cards: Courses,
   Curriculum, Learning plans, Assignments, Assessments, Grades).
2. **People** collapses from 4 child leaves into a single `GroupPeople` nav item
   pointing to a new `PeopleCards` landing page (a card-grid with 4 cards: Learners,
   Enrolments, Attendance, Credentials).
3. **Hard invariant** — every former child leaf's page stays routable (its existing
   route is preserved in `pages[]`), each leaf is rendered as a clickable card on its
   group's landing page, and every deep link continues to resolve.

**Depends on:** `adopt-shared-menu-pipeline` — this change requires the ADR-037
fragment pipeline and `buildManifest` wiring to be in place so that the cards-collapse
layout can be expressed as a `menu-layout.json` group-collapse declaration rather than
a monolithic `manifest.json` edit.

## Motivation

- **ADR-044 cards-collapse rule.** Groups with more than two children become
  unnavigable as collapsible trees; a card-grid landing page restores visual clarity
  and scan-ability.
- **Deep-link preservation.** The existing `pages[]` routes for all 10 leaf pages
  MUST be retained so that in-app navigation, bookmarks, and e2e tests continue to
  work without any URL changes.
- **Reachability.** Each former child leaf must be discoverable from the landing page
  card before a user can navigate directly; no capability is hidden.

## Affected Projects

- [x] Project: scholiq

## Capabilities

- Modified: `navigation` (ADR-044 cards-collapse for Learning and People groups)
