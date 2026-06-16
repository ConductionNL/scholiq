# Design — Dashboard

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every KPI / aggregation / RAG status / 12-month trend the dashboard surfaces comes from a `x-openregister-widgets` declaration on a Scholiq schema (declared in the compliance-audit change for Regulation, in this change for LearnerProfile). The compliance-officer / learner / admin page split is a `CnDashboardPage` instance per role, declared in `src/manifest.json`; role detection is a thin `RoleSelector` lifecycle guard (ADR-031 exception — "Domain rule engines that operate above schema metadata: select which template applies").
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — schema-derived widgets, aggregations, calculations, MCP discovery (for LaunchPad deep links). No `ComplianceDashboardService`, no `RoleDetectionService` (the v1 one — replaced by a single-method selector), no app-local KPI computation.
>
> **Frontend (per [hydra ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md))** — three dashboard pages (`Dashboard` / `LearnerHome` / `AdminHealth`) live in `src/manifest.json`. The `/` route uses `CnAppRoot`'s **role-aware page resolver** which maps `LearnerProfile.role` (computed by `RoleSelector`) to one of those pages. No `DashboardRouter.vue`, no custom `$router.replace()` glue.

## 1. Schema patches on `lib/Settings/scholiq_register.json`

This change is mostly a manifest extension + widget declarations on existing schemas. The only schema patch in this change is on `LearnerProfile` (proposed in ARCHITECTURE.md §3.6 and not yet patched into the register file).

### 1.1 `LearnerProfile` (with role-derived calculations + widgets)

```jsonc
"LearnerProfile": {
  "slug": "learner-profile",
  "icon": "AccountOutline",
  "version": "0.1.0",
  "title": "LearnerProfile",
  "type": "object",
  "required": ["ncUserId", "tenant_id"],
  "properties": {
    "ncUserId":              { "type": "string" },
    "givenName":             { "type": "string" },
    "familyName":            { "type": "string" },
    "birthDate":             { "type": ["string","null"], "format": "date" },
    "bsnEncrypted":          { "type": ["string","null"] },
    "schoolId":              { "type": ["string","null"] },
    "eckId":                 { "type": ["string","null"] },
    "eduPersonAffiliation":  { "type": "array", "items": { "type": "string" } },
    "roles":                 { "type": "array", "items": { "type": "string", "enum": ["learner","instructor","hr","manager","compliance-officer","admin","mentor","principal","parent","inspector"] } },
    "parentIds":             { "type": "array", "items": { "type": "string" } },
    "managerId":             { "type": ["string","null"] },
    "department":            { "type": ["string","null"] },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "initialState": "active",
    "states": {
      "active":  { "description": "Profile is active for the learner." },
      "merged":  { "description": "Profile merged into another LearnerProfile (e.g. duplicate SchoolID/ECK iD reconciliation); retained for audit + back-reference resolution." },
      "deleted": { "description": "Soft-deleted on offboarding; retained per AVG retention class until purge window expires." }
    },
    "transitions": [
      { "name": "merge",  "from": "active", "to": "merged",  "audit_event_type": "learner.profile.merged",  "required": ["mergedInto"], "description": "Merges this profile into another LearnerProfile. The surviving profile is referenced via `mergedInto`." },
      { "name": "delete", "from": "active", "to": "deleted", "audit_event_type": "learner.profile.deleted", "description": "Soft-deletes the profile on offboarding. Purge after retention class expires." }
    ]
  },
  "x-openregister-calculations": {
    "primaryRole": {
      "type": "string",
      "materialise": true,
      "requires": "OCA\\Scholiq\\Lifecycle\\RoleSelector"
    }
  }
}
```

> The `active` initial state emits a `learner.profile.created` audit event on first save via OR's standard lifecycle-initial-emission contract — together with the two transitions above this satisfies the full event vocabulary ADR-008 §3 declares (`learner.profile.created`, `learner.profile.merged`, `learner.profile.deleted`). No app-local LearnerProfile mutation code is needed; OR's audit-trail abstraction handles persistence.

`RoleSelector` is a single-method PHP class that takes the full LearnerProfile (and an optional NC group check via `IGroupManager::isAdmin`) and returns the highest-priority role per the order `compliance-officer > hr > admin > manager > instructor > learner`. **This is the entire role-detection seam** — the rest of the dashboard logic reads `learnerProfile.primaryRole` straight from OR.

ADR-031 §"Domain rule engines that operate *above* schema metadata" explicitly permits this pattern: "a `WorkflowService` that *selects* which lifecycle template applies … the selector is in PHP; the lifecycle it selects is declarative". `RoleSelector` is the dashboard's analog — selector in PHP, the selected page (and its widgets) is declarative.

### 1.2 Widgets on Enrolment (for the learner task list)

This change extends the `Enrolment` schema (already declared in the enrolment change) with a learner-scoped widget:

```jsonc
{
  "x-openregister-widgets": {
    "myMandatoryTraining": {
      "type": "task-list",
      "title": "scholiq.widget.learner.mandatoryTraining",
      "scope": "owned-by-actor",
      "filter": { "mandatory": true, "lifecycleIn": ["pending","active"], "learnerId": "@actor.id" },
      "sort": [ { "field": "dueDate", "order": "asc" } ],
      "columns": [
        { "field": "course.name",        "label": "scholiq.col.course"    },
        { "field": "regulationSlug",     "label": "scholiq.col.regulation"},
        { "field": "lifecycle",          "label": "scholiq.col.status"    },
        { "field": "dueDate",            "label": "scholiq.col.due"       },
        { "field": "daysRemaining",      "label": "scholiq.col.days"      },
        { "field": "ragStatus",          "label": "scholiq.col.rag"       }
      ],
      "actions": [
        { "id": "start", "manifestPage": "LessonPlayer", "presetField": "courseId" }
      ]
    }
  }
}
```

The learner dashboard's "Mijn verplichte trainingen" is this widget — no Vue view, no PHP controller, no API endpoint. `CnDashboardPage` resolves the widget from the schema and renders.

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/RoleSelector.php` | Domain rule selector | Single-method: `selectPrimaryRole(LearnerProfile $profile, IUser $user): string`. Returns the highest-priority role per the static priority map after checking `IGroupManager::isAdmin($user)`. Resolves `learnerProfile.primaryRole` for OR's `x-openregister-calculations.requires` extension. Legitimate per ADR-031 §"Domain rule engines that operate *above* schema metadata". |
| `lib/Controller/HealthController.php` | External-system contract / observability | `GET /api/admin/health` — returns OR connection status, schema-registration count, audit-events-last-24h count via OR query, launchpad installed flag. Single endpoint. The widget on the AdminHealth dashboard page consumes this response. Could in principle be expressed as schema widgets if OR-side instrumentation existed for these reads; in the meantime it's a thin observability endpoint. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `ComplianceDashboardService` (the original "KPI aggregation" service from v1) — its 17 fields were all reads off Regulation aggregations + calculations + widgets, already declared in the compliance-audit change.
- `RoleDetectionService` (v1 35-line class with priority-map sort) — replaced by `RoleSelector` calculation-helper which is the same logic but lives inside OR's calculation engine and stores the resolved role on the LearnerProfile.
- `DashboardController` (3 endpoints — compliance / learner / admin) — replaced by:
  - compliance: `Regulation.x-openregister-widgets.coverageGrid` (compliance-audit change).
  - learner: `Enrolment.x-openregister-widgets.myMandatoryTraining` (this change §1.2).
  - admin: `HealthController::index` (thin observability endpoint above).
- `DashboardRouter.vue` (the v1 invisible `$router.replace` component) — `CnAppRoot`'s page resolver reads `manifest.pages[].roleAware` and dispatches based on `learnerProfile.primaryRole` per ADR-024.
- 12-month trend computation — for v0.1 the dashboard surfaces only current-coverage % from the Regulation schema's calculations. The historical trend is an OR aggregation-history feature (v1+) — open an issue on `openregister` per ADR-031 §Exceptions if needed before v0.1 ships.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    { "id": "Dashboard",   "route": "/", "type": "dashboard", "title": "scholiq.page.dashboard.title",
      "roleAware": {
        "selectorSchema": "LearnerProfile",
        "selectorField":  "primaryRole",
        "byRole": {
          "compliance-officer": "Compliance",
          "hr":                 "Compliance",
          "admin":              "AdminHealth",
          "learner":            "LearnerHome",
          "manager":            "LearnerHome",
          "instructor":         "LearnerHome"
        },
        "fallback": "LearnerHome"
      }
    },
    { "id": "LearnerHome", "route": "/learner",      "type": "dashboard", "title": "scholiq.page.learner.title",
      "config": {
        "widgets": [
          { "id": "my-mandatory-training", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Enrolment", "widget": "myMandatoryTraining" } }
        ]
      } },
    { "id": "AdminHealth", "route": "/admin/health", "type": "dashboard", "title": "scholiq.page.admin.health.title",
      "config": {
        "widgets": [
          { "id": "health-stats", "type": "endpoint-stats", "url": "/api/admin/health" }
        ]
      } }
  ]
}
```

The `roleAware` block on `Dashboard` tells `CnAppRoot` to read the authenticated user's `LearnerProfile.primaryRole` and dispatch to the named page. **There is no app-local `DashboardRouter.vue`** — the dispatch lives inside `CnAppRoot`'s page resolver per ADR-024.

The compliance officer's `Compliance` page (with `coverageGrid` + `boardProof` widgets) is declared in the compliance-audit change. This change does not re-declare it.

### 3.2 No custom Vue views

Every dashboard surface in v0.1 is a `widget-ref` consuming a schema-declared widget — no `ComplianceOfficerDashboard.vue`, no `LearnerDashboard.vue`, no `AdminDashboard.vue`, no `RegulationKpiCard.vue`. `CnAppRoot`'s built-in dashboard renderer + widget resolver covers them.

If a widget shape genuinely doesn't fit a built-in type (the closed-enum types per ADR-024 §10), the right move is to land a new widget type in `@conduction/nextcloud-vue` via a library-side openspec change — not to write a custom Vue file in scholiq.

### 3.3 LaunchPad deep link

Per `feedback_launchpad-no-or-dependency.md`, LaunchPad is a BI surface that consumes OR data via runtime GraphQL only. The Compliance dashboard's "View in LaunchPad" link (when LaunchPad is installed) is a manifest-level action:

```jsonc
{
  "actions": [
    { "id": "viewInLaunchPad", "label": "scholiq.action.viewInLaunchPad",
      "visibleIf": { "appInstalled": "launchpad" },
      "href": "/index.php/apps/launchpad/#/scholiq-analytics?tenant=@actor.tenantId" }
  ]
}
```

`CnAppRoot` resolves `appInstalled` via NC's `IAppManager` automatically; no app-local PHP check needed.

---

## 4. Audit Events Emitted

None unique to this change. Every dashboard read is just a query against schemas declared in other changes (Regulation, Enrolment, Credential). OR's audit-trail records query events according to its own retention policy.

The `HealthController::index` endpoint does not emit audit events (read-only observability).

---

## 5. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schemas + widgets + aggregations + calculations + audit-trail query | Every dashboard widget |
| OCP\IGroupManager | `IGroupManager::isAdmin` | `RoleSelector` consults this for the admin override |
| OCP\IAppManager | `IAppManager::isInstalled('launchpad')` | `CnAppRoot` resolves the `appInstalled` visibility for the LaunchPad action |
| Compliance-audit change | `Regulation.x-openregister-widgets` | Compliance officer dashboard widgets |
| Enrolment change | `Enrolment.x-openregister-calculations` (ragStatus, daysRemaining) | Learner task-list widget data |
| Course-management change | `Course` schema | Lesson-start action target |
| Certification change | `Credential` schema | Future v1+ learner-view "My certificates" widget |
| @conduction/nextcloud-vue | `CnAppRoot` + `CnDashboardPage` + role-aware page resolver | Frontend shell |
| LaunchPad | Deep link via manifest action | Heavy analytics delegation |

---

## 6. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Compliance officer dashboard widgets | declarative | widgets (declared on Regulation) |
| Learner task list (mandatory training) | declarative | widgets (declared on Enrolment) |
| RAG status per regulation | declarative | calculation |
| Coverage % per regulation | declarative | calculation |
| Role-aware page dispatch | declarative (manifest `roleAware`) | (consumed via ADR-024) |
| LaunchPad visibility | declarative (manifest `visibleIf.appInstalled`) | (consumed via ADR-024) |
| Role selection (priority map + admin override) | imperative (PHP) | "Domain rule selector" exception |
| App health observability | imperative (PHP) | "External-system contract / observability" exception |
| 12-month coverage trend | out of scope v0.1 — pending OR-side aggregation-history extension | — |

---

## 7. Wedge Scope Exclusions (Phase 2+)

| Excluded | Role | Deferred to |
|---|---|---|
| Mentor absence-pattern dashboard | mentor | Phase 2 (declared as a future widget on Attendance schema) |
| Manager team heat-map + skill-area map | manager | V1 (declared as a future widget on Enrolment + LearnerProfile) |
| Principal Cito results overview | principal | Phase 2 |
| Parent grade digest + OPP signing tasks | parent | Phase 2 |
| Pupil grade-impact view | learner (K-12) | Phase 2 |
| Instructor cohort distribution + soft-publish queue | instructor | V1 |
| AI Act post-market monitoring dashboard | compliance-officer | Enterprise + ADR-005 (declared as widgets on AiFeature) |
| Cross-tenant benchmarking | compliance-officer | Enterprise + launchpad |
| 12-month coverage trend (historical) | all roles | OR-side feature request — open issue on openregister |
