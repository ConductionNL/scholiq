---
status: proposed
---

# Nextcloud App Shell — Navigation Grouping

**Status:** proposed
**Scope:** scholiq
**Tier:** 1 (app shell / information architecture)
**Depends on:** none

## Purpose

Replace scholiq's flat 19-entry left navigation with three top-level collapsible
groups — **Learning**, **People**, **Insight** — that nest the related leaves,
using the manifest-v2 schema's native one-level `children[]` nesting in
`src/manifest.json`. Pure IA: no schema, route, controller, or page change; every
page stays routable. Per ADR-037 (canonical nav layout lives in the app's existing
config — here `manifest.json`, since scholiq has no `menu-layout.json`) and ADR-012
(no duplicate nav source, no overlap with sibling changes).

## ADDED Requirements

### Requirement: REQ-SNG-001 — The system SHALL group the in-scope navigation leaves under three top-level parent groups

The system SHALL render the scholiq navigation as exactly three top-level parent
groups in `src/manifest.json` `menu[]` — **Learning**, **People**, and **Insight** —
each a `menuItem` with a `label`, an `icon`, and a `children[]` array, and SHALL nest
the in-scope leaves as children: **Learning** = Courses, Curriculum, Learning plans,
Assignments, Assessments, Grades; **People** = Learners, Enrolments, Attendance,
Credentials; **Insight** = Dashboards, App health, Compliance. The grouping SHALL use
the schema's native one-level `children[]` mechanism only.

#### Scenario: Three groups with the correct children

- **GIVEN** the rendered scholiq navigation
- **WHEN** an authorised user opens the app
- **THEN** the top-level menu shows the parent groups Learning, People, and Insight
- **AND** Learning contains Courses, Curriculum, Learning plans, Assignments, Assessments, Grades
- **AND** People contains Learners, Enrolments, Attendance, Credentials
- **AND** Insight contains Dashboards, App health, Compliance

### Requirement: REQ-SNG-002 — Group parents SHALL be containers without a route

Each of the three parent group entries SHALL omit the `route` key so that activating
a group toggles the visibility of its `children[]` rather than navigating, and SHALL
NOT be registered as a `pages[]` entry. The former top-level destinations SHALL
remain reachable as the child entries' routes.

#### Scenario: Activating a group toggles its children

- **GIVEN** a collapsed Learning group
- **WHEN** the user activates the Learning group header
- **THEN** its child entries (Courses, Curriculum, …) expand
- **AND** no navigation away from the current route occurs

## MODIFIED Requirements

### Requirement: REQ-SNG-003 — All pages SHALL remain routable after the nav is grouped

The system SHALL preserve every existing `pages[]` entry and its route unchanged when
the navigation is grouped; moving a menu leaf under a parent group SHALL change only
its placement in `menu[]` and SHALL NOT rename, remove, or repath any page. Each
grouped child SHALL navigate to the same route it navigated to as a flat top-level
entry (e.g. Curriculum → `Programmes`/`/curriculum/programmes`, Grades →
`GradeEntries`/`/grades/entries`, Dashboards → `Dashboard`/`/`), and direct
deep-linking of any of those routes SHALL continue to resolve.

#### Scenario: Grouped child navigates to its original route

- **GIVEN** the grouped navigation
- **WHEN** the user activates Grades under the Learning group
- **THEN** the app navigates to the `GradeEntries` route at `/grades/entries`

#### Scenario: Deep link to a grouped leaf's route still resolves

- **GIVEN** the grouped navigation
- **WHEN** a user opens `/curriculum/programmes` directly by URL
- **THEN** the Programmes page renders exactly as before the grouping change

### Requirement: REQ-SNG-004 — Role-gating on moved leaves SHALL be preserved

The system SHALL preserve the `visibleIf` conditions on every leaf that is moved into
a group: the App health child SHALL keep its `user.primaryRole == admin` gate and the
Compliance child SHALL keep its `user.primaryRole in [compliance-officer, hr]` gate,
so that grouping does not widen the audience of any gated entry.

#### Scenario: Non-admin does not see App health inside Insight

- **GIVEN** a signed-in user whose primary role is not `admin`
- **WHEN** they open the Insight group
- **THEN** the App health child is not present
- **AND** the Dashboards child is present

#### Scenario: Non-officer does not see Compliance inside Insight

- **GIVEN** a signed-in user whose primary role is neither `compliance-officer` nor `hr`
- **WHEN** they open the Insight group
- **THEN** the Compliance child is not present

### Requirement: REQ-SNG-005 — Sibling-owned and footer entries SHALL be left flat and untouched

The system SHALL leave the navigation entries owned by sibling changes and the footer
entries exactly as they are: AI features and Assistant (owned by
`scholiq-merge-ai-surfaces`), Data exchange and xAPI statements (owned by
`scholiq-integration-to-settings`), and the Documentation and Features & roadmap
footer entries SHALL retain their existing `section`, `order`, `visibleIf`, and `href`
values and SHALL NOT be nested under the new groups by this change.

#### Scenario: Sibling-owned entries are unchanged

- **WHEN** the grouped manifest is compared with the prior manifest
- **THEN** the AI features, Assistant, Data exchange, and xAPI statements entries are byte-identical to their prior definitions
- **AND** the Documentation and Features & roadmap footer entries are unchanged
