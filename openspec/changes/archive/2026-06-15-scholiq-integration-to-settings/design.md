# Design — scholiq-integration-to-settings

## Context

scholiq's `src/manifest.json` is a single bundled manifest (pre-ADR-037 layout: no
`src/manifest.d/` fragments, no `src/menu-layout.json`). Settings-area grouping is
done with a per-entry `"section": "settings"` flag on the `menu[]` item — already
used by `AdminHealthMenu`, `XapiStatementsMenu`, `AiFeaturesMenu`, and
`AssistantMenu`. There is one `type: "settings"` page (`Settings`, route
`/settings`, section `scholiq` → `ScholiqSettings`) but the integrations entries do
**not** route into it; they are independent pages surfaced via the gear group.

The IA defect: **Data exchange** is a top-level primary-nav entry at the same
altitude as learner-facing domain data, and **xAPI statements** (a read-only
learning-record integration log) is a lone settings entry. The docudesk model puts
config / integrations behind a Settings group while keeping the underlying pages
routable for deep links.

## Key decisions

1. **Reuse the existing `section: "settings"` grouping** rather than introduce a new
   Settings sub-page or component (ADR-022 / ADR-012: do not build new UI machinery
   when the manifest already groups entries). The move is `section`/`order` metadata
   on the `menu[]` entry only.
2. **Relocate `DataExchange`, retain its route.** Set `section: "settings"`; keep
   `route: "DataExchangeJobs"`. The Data exchange landing (jobs index) and its
   mapping-profile, job-detail, export-request, and OSO-review pages all stay
   declared in `pages[]` — only the menu entry's altitude changes.
3. **Order the integrations cluster coherently.** Data exchange + xAPI statements sit
   adjacent under Settings (Data exchange before xAPI: exchange jobs, then the xAPI
   statement log it produces). xAPI keeps `readOnly` index semantics.
4. **Compliance stays top-level.** It is officer-facing transactional/audit work
   (signed attestations, audit-pack export), not configuration. Explicitly excluded
   to avoid hiding a workflow surface behind the gear.
5. **Pages remain routable (the load-bearing invariant).** No `pages[]` entry is
   removed or repointed; all `/data-exchange/...` and `/xapi-statements/...` deep
   links and `@route`-bound detail pages resolve exactly as before.

## Exact menu entries touched

| menu id | label | route | before | after | rationale |
|---|---|---|---|---|---|
| `DataExchange` | Data exchange | `DataExchangeJobs` | primary nav, `order: 60`, no `section` | Settings group, `section: "settings"`, ordered before xAPI | integration plumbing (mapping profiles / jobs / OSO review), not learner data |
| `XapiStatementsMenu` | xAPI statements | `XapiStatements` | `section: "settings"`, `order: 93` | `section: "settings"`, ordered immediately after Data exchange | read-only learning-record integration log; grouped with Data exchange |

## Pages kept routable (NOT moved, NOT deleted)

Data exchange: `DataMappingProfiles` (`/data-exchange/mapping-profiles`),
`DataMappingProfileDetail`, `DataExchangeJobs` (`/data-exchange/jobs`),
`DataExchangeJobDetail`, `RequestExportModal` (`/data-exchange/request`),
`OsoDossierReviewView` (`/data-exchange/jobs/:id/oso-review`).
xAPI: `XapiStatements` (`/xapi-statements`), `XapiStatementDetail`.

## Entries deliberately NOT touched (genuine transactional surface)

`Dashboard`, `Courses`, `Enrolments`, `Credentials`, `Curriculum`, `Grades`,
`Assignments`, `Assessments`, `LearningPlans`, `Attendance`, `LearnerProfilesMenu`,
and — crucially — **`Compliance`** (officer/audit work, gated to
`compliance-officer`/`hr`). These stay in the primary navigation.

## Alternatives considered

- **Route Data exchange into the existing `type:"settings"` page as a new section
  slot.** Rejected: that page renders a single `ScholiqSettings` component; bolting
  index/detail data pages into a settings-section slot would break the existing
  routable index/detail UX and add UI machinery for no benefit (ADR-012). The gear
  `section: "settings"` grouping already achieves "lives under Settings" with zero
  new components.
- **Delete the Data exchange menu entry entirely (admin-only API surface).**
  Rejected: integrators legitimately need a navigable entry; hiding it would force
  URL-typing. Relocating under the gear keeps it discoverable but de-prioritised.
- **Also move Compliance under Settings.** Rejected: it is transactional/audit work,
  not configuration.

## Migration / rollout

Pure menu metadata edit in `src/manifest.json`; no data, schema, route, or backend
migration. Ships in one commit; revertable via `git revert`.

## Risks

- Deep link breakage — mitigated: no `pages[]` change; regression check asserts
  `/data-exchange/jobs`, `/data-exchange/jobs/:id/oso-review`, and `/xapi-statements`
  still resolve.
- Compliance mis-classification — mitigated: explicitly excluded and called out.
