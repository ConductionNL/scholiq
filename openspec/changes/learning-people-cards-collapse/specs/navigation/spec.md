---
status: proposed
---

# Navigation — Learning and People Groups Cards Collapse (ADR-044)

**Status:** proposed
**Scope:** scholiq
**Depends on:** adopt-shared-menu-pipeline

## Purpose

Apply ADR-044's cards-collapse rule to scholiq's **Learning** group (6 children:
`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) and
**People** group (4 children: `LearnerProfilesMenu`, `Enrolments`, `Attendance`,
`Credentials`). Each group collapses into a single top-level nav item linking to a
card-grid landing page; every former child leaf's page route is preserved and rendered
as a card. All existing deep links continue to resolve.

## ADDED Requirements

### Requirement: REQ-LPC-001 — The Learning group MUST collapse into a single nav item linking to a card-grid landing page

The `GroupLearning` menu entry MUST be changed from a `children[]`-bearing group to a
single nav item (no `children`) that links to a new `LearningCards` landing page
(`route: /learning`). The `LearningCards` page MUST render a card-grid layout with one
card per former child leaf, in the following order: Courses, Curriculum, Learning plans,
Assignments, Assessments, Grades. Each card MUST be labelled with the leaf's original
label, display the leaf's icon, and navigate to the leaf's existing route when
activated.

#### Scenario: Learning group collapses to a single nav item

- GIVEN the ADR-044 cards-collapse refactor is applied
- WHEN an authenticated user opens the scholiq app
- THEN the left navigation shows `GroupLearning` as a single item with label "Learning" and no expandable children
- AND activating the Learning item navigates to `/learning`

#### Scenario: LearningCards page renders all six former child leaves as cards

- GIVEN the user navigates to the `/learning` (LearningCards) landing page
- WHEN the page renders
- THEN a card-grid is displayed containing exactly six cards: Courses, Curriculum, Learning plans, Assignments, Assessments, Grades
- AND each card shows the leaf's original label and icon
- AND activating the Courses card navigates to the `Courses` route (`/courses`)
- AND activating the Grades card navigates to the `GradeEntries` route (`/grades/entries`)

### Requirement: REQ-LPC-002 — The People group MUST collapse into a single nav item linking to a card-grid landing page

The `GroupPeople` menu entry MUST be changed from a `children[]`-bearing group to a
single nav item (no `children`) that links to a new `PeopleCards` landing page
(`route: /people`). The `PeopleCards` page MUST render a card-grid layout with one card
per former child leaf, in the following order: Learners, Enrolments, Attendance,
Credentials. Each card MUST be labelled with the leaf's original label, display the
leaf's icon, and navigate to the leaf's existing route when activated.

#### Scenario: People group collapses to a single nav item

- GIVEN the ADR-044 cards-collapse refactor is applied
- WHEN an authenticated user opens the scholiq app
- THEN the left navigation shows `GroupPeople` as a single item with label "People" and no expandable children
- AND activating the People item navigates to `/people`

#### Scenario: PeopleCards page renders all four former child leaves as cards

- GIVEN the user navigates to the `/people` (PeopleCards) landing page
- WHEN the page renders
- THEN a card-grid is displayed containing exactly four cards: Learners, Enrolments, Attendance, Credentials
- AND each card shows the leaf's original label and icon
- AND activating the Learners card navigates to the `LearnerProfiles` route (`/learner-profiles`)
- AND activating the Credentials card navigates to the `Credentials` route (`/credentials`)

### Requirement: REQ-LPC-003 — INVARIANT: every former child leaf's page route MUST remain routable and every leaf MUST be reachable as a card or direct deep link

The cards-collapse MUST NOT remove any `pages[]` entry, rename any route, or prevent
direct deep-link navigation to any former child leaf. All ten former child leaf pages
(`Courses`, `Curriculum`/`Programmes`, `LearningPlans`, `Assignments`, `Assessments`,
`Grades`/`GradeEntries`, `LearnerProfiles`, `Enrolments`, `Attendance`/`AttendanceRecords`,
`Credentials`) MUST remain declared in `pages[]` with their existing routes unchanged.
Each MUST be reachable both by clicking its card on the landing page and by navigating
directly to its route (deep link).

#### Scenario: All former Learning leaf routes remain routable after collapse

- GIVEN the cards-collapse refactor is deployed
- WHEN a user navigates directly to `/courses`, `/curriculum/programmes`, `/learning-plans`, `/assignments`, `/assessments`, or `/grades/entries`
- THEN the corresponding page renders without error
- AND no redirect to a landing page or dashboard occurs for a direct deep-link navigation

#### Scenario: All former People leaf routes remain routable after collapse

- GIVEN the cards-collapse refactor is deployed
- WHEN a user navigates directly to `/learner-profiles`, `/enrolments`, `/attendance/records`, or `/credentials`
- THEN the corresponding page renders without error
- AND no redirect to a landing page or dashboard occurs for a direct deep-link navigation

#### Scenario: Card on a landing page navigates to the leaf's existing route

- GIVEN the user is on the LearningCards or PeopleCards landing page
- WHEN the user activates any card
- THEN the browser navigates to the same route the corresponding leaf used before the collapse
- AND the URL is unchanged compared to pre-collapse navigation to that leaf
