# Design — Role-Aware Dashboards

> **Declarative-vs-imperative decision (per [hydra ADR-031](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every KPI widget, aggregation, RAG status band, task-list, and chart the dashboard surfaces comes from `x-openregister-widgets` / `x-openregister-aggregations` / `x-openregister-calculations` declarations on existing Scholiq schemas. Role detection is `x-openregister-calculations.primaryRole` on `LearnerProfile`, resolved by a single-method PHP `RoleSelector` guard (ADR-031 §"Domain rule engines that operate *above* schema metadata"). The nine dashboard pages live in `src/manifest.json` with a `roleAware` dispatcher on `/` — no `DashboardRouter.vue`, no custom `$router.replace()` glue.
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail, aggregations engine, calculations, notifications, RBAC, object mutation. No `DashboardController`, no `RoleDetectionService`, no `ComplianceDashboardService`, no `PreferenceController`. Every dashboard data point is a schema-declared widget or aggregation resolved by OR.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — nine dashboard pages declared in `src/manifest.json`; `CnAppRoot`'s role-aware page resolver dispatches based on `LearnerProfile.primaryRole`. `CnDashboardPage` is the layout primitive for every page — no custom layout components. Self-contained components (ADR-017): `CnDashboardPage` MUST NOT be wrapped in `NcAppContent`.

---

## 1. Schema additions and patches on `lib/Settings/scholiq_register.json`

### 1.1 `RoleAssignment` (new schema)

```jsonc
"RoleAssignment": {
  "slug": "role-assignment",
  "icon": "AccountKeyOutline",
  "version": "0.1.0",
  "title": "RoleAssignment",
  "description": "Per-user role mapping — the declarative source of truth for Scholiq role detection.",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Role",
    "active": true,
    "hardDelete": false,
    "searchable": true,
    "appendOnly": false
  },
  "required": ["ncUserId", "role", "tenant_id", "validFrom"],
  "properties": {
    "ncUserId":    { "type": "string",             "description": "Nextcloud user UID of the assigned user." },
    "role":        { "type": "string",             "enum": ["learner","instructor","mentor","manager","hr","compliance-officer","board-member","principal","parent","inspector","admin"] },
    "department":  { "type": ["string","null"],    "description": "Organisational unit for scoping (e.g. 'Compliance & Governance')." },
    "validFrom":   { "type": "string",             "format": "date" },
    "validUntil":  { "type": ["string","null"],    "format": "date", "description": "null = indefinite." },
    "tenant_id":   { "type": "string",             "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "active",
    "transitions": {
      "revoke":  { "from": "active",   "to": "revoked",  "audit_event_type": "role.assignment.revoked" },
      "restore": { "from": "revoked",  "to": "active",   "audit_event_type": "role.assignment.restored" }
    }
  },
  "x-openregister-relations": {
    "learnerProfile": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "ncUserId" }
  }
}
```

### 1.2 `DashboardPreference` (new schema)

```jsonc
"DashboardPreference": {
  "slug": "dashboard-preference",
  "icon": "ViewDashboardOutline",
  "version": "0.1.0",
  "title": "DashboardPreference",
  "description": "Per-user, per-role widget order and layout preferences.",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:PropertyValue",
    "active": true,
    "hardDelete": true,
    "searchable": false
  },
  "required": ["ncUserId", "roleContext", "tenant_id"],
  "properties": {
    "ncUserId":      { "type": "string",             "description": "Nextcloud user UID." },
    "roleContext":   { "type": "string",             "enum": ["learner","instructor","mentor","manager","hr","compliance-officer","board-member","principal","parent","inspector","admin"], "description": "Which role dashboard this preference applies to." },
    "widgetOrder":   { "type": "array",              "items": { "type": "string" }, "default": [], "description": "Ordered list of widget IDs — matches manifest page widget slots." },
    "pinnedWidgets": { "type": "array",              "items": { "type": "string" }, "default": [], "description": "Widget IDs that are always shown regardless of scroll position." },
    "layoutMode":    { "type": "string",             "enum": ["compact","expanded"], "default": "expanded" },
    "tenant_id":     { "type": "string",             "format": "uuid" }
  }
}
```

> Slug convention: `pref-{ncUserId}-{roleContext}` — guarantees one preference object per user per role. Stored and retrieved via standard OR object mutation; no custom preference endpoint needed.

### 1.3 `LearnerProfile` — patch: `primaryRole` calculation + `RoleAssignment` relation

```jsonc
// Additions to the existing LearnerProfile schema in scholiq_register.json:
{
  "x-openregister-calculations": {
    "primaryRole": {
      "type": "string",
      "materialise": true,
      "requires": "OCA\\Scholiq\\Lifecycle\\RoleSelector",
      "description": "Highest-priority resolved role for this learner. Computed by RoleSelector using the role priority map and IGroupManager::isAdmin check."
    }
  },
  "x-openregister-relations": {
    "roleAssignments": {
      "register": "scholiq",
      "schema": "RoleAssignment",
      "cardinality": "one-to-many",
      "joinOn": "ncUserId",
      "description": "All active RoleAssignment objects for this learner."
    }
  }
}
```

`RoleSelector` is a single-method PHP class that takes the full LearnerProfile (including its resolved `roleAssignments`) and an `IUser` reference, and returns the highest-priority role per the static priority map: `compliance-officer=6 > board-member=5 > principal=5 > hr=4 > manager=4 > mentor=3 > instructor=3 > parent=2 > learner=1`. If `IGroupManager::isAdmin($user)` is true, returns `admin` regardless of assignments. If no assignments exist, returns `learner`. This is the entire role-detection seam — the rest of the dashboard logic reads `LearnerProfile.primaryRole` straight from OR.

### 1.4 `Enrolment` — patch: `myMandatoryTraining` widget

```jsonc
// Addition to the existing Enrolment schema's x-openregister-widgets:
{
  "x-openregister-widgets": {
    "myMandatoryTraining": {
      "type": "task-list",
      "title": "scholiq.widget.learner.mandatoryTraining",
      "scope": "owned-by-actor",
      "filter": {
        "mandatory": true,
        "lifecycleIn": ["pending", "active"],
        "learnerId": "@actor.id"
      },
      "sort": [{ "field": "dueDate", "order": "asc" }],
      "columns": [
        { "field": "course.name",     "label": "scholiq.col.course" },
        { "field": "regulationSlug",  "label": "scholiq.col.regulation" },
        { "field": "lifecycle",       "label": "scholiq.col.status" },
        { "field": "dueDate",         "label": "scholiq.col.due" },
        { "field": "daysRemaining",   "label": "scholiq.col.days" },
        { "field": "ragStatus",       "label": "scholiq.col.rag" }
      ],
      "actions": [
        { "id": "start", "manifestPage": "LessonPlayer", "presetField": "courseId" }
      ]
    }
  }
}
```

---

## 2. Seed Data (per ADR-001 §"Seed data")

3–5 realistic Dutch objects per new schema, loaded via `importFromApp()` in `scholiq_register.json` `components.objects[]`.

### 2.1 `RoleAssignment` seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "RoleAssignment", "slug": "role-marie-janssen-co" },
    "ncUserId": "marie.janssen",
    "role": "compliance-officer",
    "department": "Compliance & Governance",
    "validFrom": "2026-01-01",
    "validUntil": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RoleAssignment", "slug": "role-piet-bakker-mgr" },
    "ncUserId": "piet.bakker",
    "role": "manager",
    "department": "Bedrijfsvoering",
    "validFrom": "2026-01-01",
    "validUntil": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RoleAssignment", "slug": "role-jan-de-vries-mentor" },
    "ncUserId": "jan.de.vries",
    "role": "mentor",
    "department": "Onderwijs",
    "validFrom": "2026-01-15",
    "validUntil": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RoleAssignment", "slug": "role-sophie-hendriks-board" },
    "ncUserId": "sophie.hendriks",
    "role": "board-member",
    "department": "Raad van Bestuur",
    "validFrom": "2026-01-01",
    "validUntil": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "RoleAssignment", "slug": "role-lars-vermeulen-learner" },
    "ncUserId": "lars.vermeulen",
    "role": "learner",
    "department": "Klantenservice",
    "validFrom": "2026-03-01",
    "validUntil": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 2.2 `DashboardPreference` seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "DashboardPreference", "slug": "pref-marie-janssen-co" },
    "ncUserId": "marie.janssen",
    "roleContext": "compliance-officer",
    "widgetOrder": ["coverageGrid", "overdueTable", "auditPackActions", "viewInMydash"],
    "pinnedWidgets": ["coverageGrid"],
    "layoutMode": "expanded",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "DashboardPreference", "slug": "pref-piet-bakker-mgr" },
    "ncUserId": "piet.bakker",
    "roleContext": "manager",
    "widgetOrder": ["teamProgress", "skillHeatmap", "overdueAlerts"],
    "pinnedWidgets": [],
    "layoutMode": "compact",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "DashboardPreference", "slug": "pref-jan-de-vries-mentor" },
    "ncUserId": "jan.de.vries",
    "roleContext": "mentor",
    "widgetOrder": ["absencePattern", "mentorClassList", "upcomingDeadlines"],
    "pinnedWidgets": ["absencePattern"],
    "layoutMode": "compact",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "DashboardPreference", "slug": "pref-sophie-hendriks-board" },
    "ncUserId": "sophie.hendriks",
    "roleContext": "board-member",
    "widgetOrder": ["complianceBands", "renewalStatus", "boardProof", "viewInMydash"],
    "pinnedWidgets": ["complianceBands"],
    "layoutMode": "expanded",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

---

## 3. Reuse Analysis (per ADR-001 §"Deduplication check")

| Existing service / component | Reused? | How |
|---|---|---|
| `ObjectService` (OR) | Yes | All `RoleAssignment` + `DashboardPreference` CRUD — no custom controllers |
| `CnDashboardPage` (`@conduction/nextcloud-vue`) | Yes | Layout primitive for every role-specific dashboard page |
| `CnStatsBlock`, `CnChartWidget`, `CnTableWidget` | Yes | Widget primitives within `CnDashboardPage` — no custom chart or KPI components |
| `CnObjectSidebar` (audit tab) | Yes | Audit-trail tab on `RoleAssignment` detail — no custom audit UI |
| OR `x-openregister-widgets` | Yes | All dashboard data points are schema-declared widgets |
| OR `x-openregister-aggregations` | Yes | Coverage %, team counts, overdue counts — all aggregations declared on existing schemas (Regulation, Enrolment) |
| OR `x-openregister-calculations` | Yes | `primaryRole` on `LearnerProfile`, `ragStatus` / `daysRemaining` / `isOverdue` on `Enrolment` (declared in enrolment change) |
| `IGroupManager::isAdmin` (NC OCP) | Yes | `RoleSelector` admin override check |
| `IAppManager::isInstalled` (NC OCP) | Yes | `CnAppRoot` resolves manifest `appInstalled: mydash` visibility — no app-local PHP check |
| `CnAppRoot` manifest `roleAware` resolver (ADR-024) | Yes | Routes `/` to the correct dashboard page — no `DashboardRouter.vue` |

**No overlap found** with `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`, or any existing Vue component that would need to be extended vs consumed.

---

## 4. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/RoleSelector.php` | Domain rule selector | Single method `selectPrimaryRole(LearnerProfile $profile, IUser $user): string`. Returns highest-priority role using static priority map after `IGroupManager::isAdmin` check. Legitimate per ADR-031 §"Domain rule engines that operate *above* schema metadata". |
| `lib/Controller/HealthController.php` | External-system contract / observability | `GET /api/admin/health` — returns OR connection status, schema count, audit-trail events last 24 h, mydash installed flag. Thin observability endpoint; no OR abstraction exists for cross-system health probing. Legitimate per ADR-031 §"External-system contract / observability". |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `DashboardController` — every dashboard data point is a schema-declared widget or aggregation.
- `RoleDetectionService` / `ComplianceDashboardService` / `PreferenceController` — OR's calculation engine + standard object mutation covers all these.
- `DashboardRouter.vue` — `CnAppRoot`'s `roleAware` page resolver handles dispatch per ADR-024.
- Any custom KPI card component — `CnStatsBlock` and `CnChartWidget` cover all shapes.

---

## 5. Frontend — `CnAppRoot` manifest extension

### 5.1 Manifest pages

```jsonc
{
  "pages": [
    {
      "id": "Dashboard",
      "route": "/",
      "type": "dashboard",
      "title": "scholiq.page.dashboard.title",
      "roleAware": {
        "selectorSchema": "LearnerProfile",
        "selectorField":  "primaryRole",
        "byRole": {
          "compliance-officer": "ComplianceBoard",
          "board-member":       "ComplianceBoard",
          "hr":                 "ComplianceBoard",
          "manager":            "ManagerTeam",
          "mentor":             "MentorAbsence",
          "principal":          "PrincipalCito",
          "instructor":         "TeacherCohort",
          "parent":             "ParentDigest",
          "learner":            "LearnerHome",
          "admin":              "AdminHealth"
        },
        "fallback": "LearnerHome"
      }
    },
    {
      "id": "ComplianceBoard",
      "route": "/compliance",
      "type": "dashboard",
      "title": "scholiq.page.compliance.title",
      "config": {
        "widgets": [
          { "id": "coverage-grid", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Regulation", "widget": "coverageGrid" } },
          { "id": "overdue-table", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Enrolment",  "widget": "overdueByRegulation" } }
        ]
      },
      "actions": [
        { "id": "viewInMydash",
          "label": "scholiq.action.viewInMydash",
          "visibleIf": { "appInstalled": "mydash" },
          "href": "/index.php/apps/mydash/#/scholiq-analytics?tenant=@actor.tenantId" }
      ]
    },
    {
      "id": "LearnerHome",
      "route": "/learner",
      "type": "dashboard",
      "title": "scholiq.page.learner.title",
      "config": {
        "widgets": [
          { "id": "my-mandatory-training", "type": "widget-ref",
            "ref": { "register": "scholiq", "schema": "Enrolment", "widget": "myMandatoryTraining" } }
        ]
      }
    },
    {
      "id": "AdminHealth",
      "route": "/admin/health",
      "type": "dashboard",
      "title": "scholiq.page.admin.health.title",
      "config": {
        "widgets": [
          { "id": "health-stats", "type": "endpoint-stats", "url": "/api/admin/health" }
        ]
      }
    },
    {
      "id": "ManagerTeam",
      "route": "/manager/team",
      "type": "dashboard",
      "title": "scholiq.page.manager.team.title",
      "config": { "stub": true, "stubMessage": "scholiq.stub.phase2" },
      "actions": [
        { "id": "viewInMydash",
          "label": "scholiq.action.viewInMydash",
          "visibleIf": { "appInstalled": "mydash" },
          "href": "/index.php/apps/mydash/#/scholiq-team?tenant=@actor.tenantId" }
      ]
    },
    {
      "id": "MentorAbsence",
      "route": "/mentor/absence",
      "type": "dashboard",
      "title": "scholiq.page.mentor.absence.title",
      "config": { "stub": true, "stubMessage": "scholiq.stub.phase2" }
    },
    {
      "id": "PrincipalCito",
      "route": "/principal/cito",
      "type": "dashboard",
      "title": "scholiq.page.principal.cito.title",
      "config": { "stub": true, "stubMessage": "scholiq.stub.phase2" }
    },
    {
      "id": "TeacherCohort",
      "route": "/teacher/cohort",
      "type": "dashboard",
      "title": "scholiq.page.teacher.cohort.title",
      "config": { "stub": true, "stubMessage": "scholiq.stub.phase2" }
    },
    {
      "id": "ParentDigest",
      "route": "/parent/digest",
      "type": "dashboard",
      "title": "scholiq.page.parent.digest.title",
      "config": { "stub": true, "stubMessage": "scholiq.stub.phase2" }
    }
  ]
}
```

### 5.2 No custom Vue dashboard views in Phase 1

All Phase-1 dashboard surfaces (`ComplianceBoard`, `LearnerHome`, `AdminHealth`) resolve to schema-declared widgets via `widget-ref` or `endpoint-stats` — `CnAppRoot`'s built-in dashboard renderer covers them. No `ComplianceOfficerDashboard.vue`, `LearnerDashboard.vue`, `AdminDashboard.vue`, or `RegulationKpiCard.vue` is created.

Phase-2 pages (`ManagerTeam`, `MentorAbsence`, `PrincipalCito`, `TeacherCohort`, `ParentDigest`) are declared with `stub: true` so their routes are reachable (ADR-029 route-reachability gate passes) and render a "Komt binnenkort" (`CnEmptyState`) message. When a Phase-2 change lands, it replaces `stub: true` with the real widget config — no router changes required.

### 5.3 DashboardPreference wiring

`CnDashboardPage` reads `DashboardPreference` via OR's standard object lookup (`GET /api/objects/dashboard-preference?ncUserId={uid}&roleContext={role}`) and applies `widgetOrder` + `pinnedWidgets` + `layoutMode` to GridStack's layout. Saves user drag-drop changes via `PATCH /api/objects/dashboard-preference/{id}`. No custom preference controller.

---

## 6. Audit Events Emitted

| Event type | Schema | Trigger |
|---|---|---|
| `role.assignment.revoked` | `RoleAssignment` | `revoke` lifecycle transition |
| `role.assignment.restored` | `RoleAssignment` | `restore` lifecycle transition |

Dashboard reads (widget data fetches) are not audited by default. The `HealthController` endpoint is read-only and emits no audit events.

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `x-openregister-widgets` on Regulation + Enrolment | `ComplianceBoard` + `LearnerHome` widget data |
| OpenRegister | `x-openregister-calculations.primaryRole` on LearnerProfile | Role-aware page dispatch |
| OpenRegister | `x-openregister-aggregations` on Regulation | Coverage % per regulation (compliance-audit change) |
| OpenRegister | Object CRUD | `RoleAssignment` + `DashboardPreference` persistence |
| `OCP\IGroupManager` | `isAdmin($user)` | `RoleSelector` admin override |
| `OCP\IAppManager` | `isInstalled('mydash')` | `CnAppRoot` resolves manifest `appInstalled` visibility |
| Compliance-audit change | `Regulation.x-openregister-widgets.coverageGrid` | `ComplianceBoard` widgets |
| Enrolment change | `Enrolment.x-openregister-calculations` (ragStatus, daysRemaining) | `LearnerHome` task-list row colouring |
| Course-management change | `Course` schema | Lesson-start action target from learner task list |
| `@conduction/nextcloud-vue` | `CnAppRoot` + `CnDashboardPage` + role-aware page resolver | Frontend shell + layout |
| MyDash | Deep link via manifest action | Heavy analytics delegation |

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Compliance board widgets (coverageGrid, overdueTable) | declarative | `x-openregister-widgets` on Regulation + Enrolment |
| Learner mandatory-training task list | declarative | `x-openregister-widgets.myMandatoryTraining` on Enrolment |
| Coverage % per regulation | declarative | `x-openregister-calculations.coveragePercent` on Regulation |
| RAG status per enrolment | declarative | `x-openregister-calculations.ragStatus` on Enrolment |
| Role-aware page dispatch | declarative | manifest `roleAware` block (ADR-024) |
| MyDash link visibility | declarative | manifest `visibleIf.appInstalled` (ADR-024) |
| DashboardPreference persistence | declarative | standard OR object mutation |
| RoleAssignment lifecycle (revoke/restore) | declarative | `x-openregister-lifecycle` on RoleAssignment |
| Role priority resolution + admin override | imperative (PHP) | `RoleSelector` — "Domain rule selector" ADR-031 exception |
| App health observability | imperative (PHP) | `HealthController` — "External-system contract / observability" ADR-031 exception |
| 12-month compliance trend | out of scope v0.1 | OR-side aggregation-history extension (open issue on openregister) |

---

## 9. Phase 2+ Deferred Dashboard Views

| Page | Primary role | Trigger data source | Deferred to |
|---|---|---|---|
| `MentorAbsence` | mentor | Attendance schema (absence-pattern widget) | Phase 2 |
| `ManagerTeam` | manager | Enrolment + LearnerProfile (team heat-map widget) | Phase 2 |
| `PrincipalCito` | principal | Assessment schema (Cito results per leerjaar) | Phase 2 |
| `ParentDigest` | parent | Grading + Enrolment schemas (grade digest widget) | Phase 2 |
| `TeacherCohort` | instructor | Enrolment + Course schemas (cohort distribution) | Phase 2 |
| AI post-market monitoring | compliance-officer | `AiFeature.x-openregister-widgets` (ADR-005) | Enterprise |
| Cross-tenant benchmarking | all | mydash + Specter feeds | Enterprise |
