# Test Plan: nav-restructure-dashboards

## Test Cases

### TC-1: Dashboard and Compliance are top-level; Insight gone
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-the-app-opens-on-a-top-level-dashboard-and-the-insight-group-is-dissolved`
- **type**: functional
- **persona**: n/a
- **preconditions**: authenticated admin; app deployed
- **steps**: open the Scholiq app; inspect the left navigation
- **expected result**: `Dashboard` and `Compliance` appear as top-level items; no `Insight` group; app root renders `ScholiqDashboards`
- **test command**: /test-functional

### TC-2: App-health surface fully removed
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-app-health-is-not-an-in-app-navigation-surface`
- **type**: regression
- **persona**: n/a
- **preconditions**: app deployed; `tests/e2e/pages.spec.ts` updated
- **steps**: inspect nav for `App health`; inspect manifest `pages[]`/`menu[]` and `src/registry.js`; run the manifest + registry unit tests
- **expected result**: no `App health` menu entry; no `AdminHealth` page or `/admin/health` route; no `ScholiqAdminHealth` registry entry/import; no e2e references the removed route
- **test command**: /test-regression

### TC-3: Learning parent is navigable and expandable with domain dashboard
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard`
- **type**: functional
- **persona**: n/a
- **preconditions**: authenticated user; app deployed
- **steps**: open the app; activate `Learning` as a link; expand `Learning`; activate the `Courses` sub-item
- **expected result**: `/learning` renders exactly one `CnDashboardPage` with learning KPIs + manage-lists; the six sub-items appear; `Courses` navigates to `/courses`
- **test command**: /test-functional

### TC-4: People parent is navigable and expandable with domain dashboard
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard`
- **type**: functional
- **persona**: n/a
- **preconditions**: authenticated user; app deployed
- **steps**: open the app; activate `People` as a link; expand `People`; activate the `Learners` sub-item
- **expected result**: `/people` renders exactly one `CnDashboardPage` with people KPIs + manage-lists; the four sub-items appear; `Learners` navigates to `/learner-profiles`
- **test command**: /test-functional

### TC-5: No dashboard-in-dashboard on the domain dashboards
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard`
- **type**: regression
- **persona**: n/a
- **preconditions**: app deployed
- **steps**: render `/learning` and `/people`; inspect the component tree; run the hydra dashboard-antipattern gate
- **expected result**: exactly one `CnDashboardPage` per route; neither `LearningDashboard` nor `PeopleDashboard` is referenced as a widget slot on another dashboard; the antipattern gate passes
- **test command**: /test-regression

### TC-6: Features & roadmap in footer; School-year rollover in settings foldout
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-features-roadmap-lives-in-the-footer-beside-documentation`
- **type**: functional
- **persona**: n/a
- **preconditions**: authenticated admin; app deployed
- **steps**: open the app; inspect the footer; open the settings foldout
- **expected result**: `Features & roadmap` is in the footer beside `Documentation` (not in the foldout); `School-year rollover` is in the settings foldout (not a top-level item)
- **test command**: /test-functional

### TC-7: Retained routes and deep links remain reachable
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-invariant-all-retained-routes-and-deep-links-remain-reachable-after-the-restructure`
- **type**: regression
- **persona**: n/a
- **preconditions**: app deployed
- **steps**: navigate directly to `/courses`, `/enrolments`, `/credentials` and other retained leaf routes; resolve each of the four `deepLinks` urlTemplates
- **expected result**: every retained leaf route renders without error or redirect; every `deepLinks` urlTemplate targets a route still in `pages[]`; none targets `/admin/health`
- **test command**: /test-regression

### TC-8: Navigable-and-collapsible parent is keyboard accessible
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/navigation/spec.md#requirement-learning-is-a-navigable-domain-dashboard-with-collapsible-sub-children`
- **type**: accessibility
- **persona**: n/a
- **preconditions**: app deployed
- **steps**: keyboard-navigate to `Learning`/`People`; operate the disclosure control and the link affordance via keyboard; check ARIA on the disclosure
- **expected result**: both the link and the expand/collapse affordances are keyboard-operable with correct ARIA (WCAG 2.1 AA), per `CnAppNav`
- **test command**: /test-accessibility

### TC-9: New dashboard strings are localized (nl_NL + en_US)
- **spec_ref**: `openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard`
- **type**: functional
- **persona**: n/a
- **preconditions**: translation sources updated
- **steps**: switch UI locale to Dutch, then English; open `/learning` and `/people`
- **expected result**: all KPI/manage-list labels render translated in both locales; i18n keys are the English source strings
- **test command**: /test-functional

## Coverage Summary

| Requirement | Covered by |
|---|---|
| navigation: App health is not an in-app navigation surface | TC-2 |
| navigation: Top-level Dashboard and Insight dissolved | TC-1 |
| navigation: Learning navigable domain dashboard w/ sub-children | TC-3, TC-8 |
| navigation: People navigable domain dashboard w/ sub-children | TC-4, TC-8 |
| navigation: Features & roadmap in footer | TC-6 |
| navigation: School-year rollover in settings foldout | TC-6 |
| navigation: INVARIANT retained routes + deep links reachable | TC-7 |
| dashboard: Learning domain dashboard | TC-3, TC-5, TC-9 |
| dashboard: People domain dashboard | TC-4, TC-5, TC-9 |

All spec requirements are covered.

## Out of Scope

- Phase-B feature removal (Assistant / AI features / data-exchange) — separate change, tested there.
- The internal role-switching behaviour of `ScholiqDashboards` — unchanged by this change and covered by the existing `tests/e2e/spec-coverage/dashboard.spec.ts`.
- OpenRegister's admin Data-health settings form itself — pre-existing and owned by OpenRegister.
