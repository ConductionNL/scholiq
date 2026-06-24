# Tasks: learning-people-cards-collapse

## 0. Prerequisites

- [ ] 0.1 Confirm `adopt-shared-menu-pipeline` is merged and `buildManifest` + `src/menu-layout.json` are in place before starting this change.

## 1. Collapse the Learning group in menu-layout.json

- [ ] 1.1 In `src/menu-layout.json`, add a `groupCollapse` entry for `GroupLearning` pointing to route `LearningCards` (`/learning`).
- [ ] 1.2 Verify the effective manifest produced by `buildManifest` shows `GroupLearning` as a single nav item with no `children[]` and `route: "LearningCards"`.
- [ ] 1.3 Confirm that the six former child leaf entries (`Courses`, `Curriculum`, `LearningPlans`, `Assignments`, `Assessments`, `Grades`) are no longer rendered as nav children but remain in `pages[]` with routes unchanged.

## 2. Collapse the People group in menu-layout.json

- [ ] 2.1 In `src/menu-layout.json`, add a `groupCollapse` entry for `GroupPeople` pointing to route `PeopleCards` (`/people`).
- [ ] 2.2 Verify the effective manifest shows `GroupPeople` as a single nav item with no `children[]` and `route: "PeopleCards"`.
- [ ] 2.3 Confirm that the four former child leaf entries (`LearnerProfilesMenu`, `Enrolments`, `Attendance`, `Credentials`) are no longer rendered as nav children but remain in `pages[]` with routes unchanged.

## 3. Add landing pages to pages[]

- [ ] 3.1 Add a `LearningCards` page entry to `src/manifest.json` `pages[]`:
  - `"id": "LearningCards"`, `"route": "/learning"`, `"type": "card-grid"` (or equivalent ADR-044 landing-page type)
  - Config: cards for Courses, Curriculum, Learning plans, Assignments, Assessments, Grades — each referencing its leaf's label, icon, and route.
- [ ] 3.2 Add a `PeopleCards` page entry to `src/manifest.json` `pages[]`:
  - `"id": "PeopleCards"`, `"route": "/people"`, `"type": "card-grid"`
  - Config: cards for Learners, Enrolments, Attendance, Credentials — each referencing its leaf's label, icon, and route.
- [ ] 3.3 Ensure neither landing page route (`/learning`, `/people`) conflicts with any existing route in `pages[]`.

## 4. Verify the hard invariant — no route or reachable entry dropped

- [ ] 4.1 Assert all 10 former child leaf pages are still declared in `pages[]` with their original routes (`/courses`, `/curriculum/programmes`, `/learning-plans`, `/assignments`, `/assessments`, `/grades/entries`, `/learner-profiles`, `/enrolments`, `/attendance/records`, `/credentials`).
- [ ] 4.2 Manually verify (or via Playwright) that navigating directly to each of those routes renders the expected page without redirect.
- [ ] 4.3 Verify that clicking each card on `LearningCards` and `PeopleCards` landing pages navigates to the correct leaf route.

## 5. Quality gates

- [ ] 5.1 `openspec validate learning-people-cards-collapse --type change --strict` passes with no errors.
- [ ] 5.2 `composer check:strict` passes (no new PHP violations).
- [ ] 5.3 Webpack build succeeds with the two new landing-page routes registered in the router.
