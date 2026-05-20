# Design — Enrolment (Extended: Studielink · Onboarding Templates · Prerequisite Enforcement)

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — `OnboardingTemplate` and `EnrolmentRule` lifecycle, milestone scheduling calculations, and trigger-based Enrolment creation all fit `x-openregister-lifecycle` / `-calculations` / `-notifications`. Three PHP files land as ADR-031 legitimate seams: `PrerequisiteCheckGuard` (cross-schema pre-condition query), `StudielinkEnrolmentHandler` (external-system bridge — Edukoppeling intake via OpenConnector), and `OnboardingTemplateApplicator` (audit-event handler that schedules milestone-day Enrolments). No controllers, no services, no TimedJobs.
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — lifecycle engine, calculations, notifications, relations, RBAC, audit trail (enrolment.activated / enrolment.completed / enrolment.withdrawn — already declared in Phase 1 Enrolment schema), batch import for bulk-enrol, MCP discovery. No app-local audit substrate.
>
> **Frontend (per [hydra ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md))** — `EnrolmentRules` index + detail served by `CnAppRoot` manifest renderers. `OnboardingTemplates` index + detail served by `CnAppRoot`. `TeamBulkEnrolModal` is a `customComponents` entry talking directly to OR's batch endpoint. No bespoke CRUD Vue files.

---

## 1. Schema patches on `lib/Settings/scholiq_register.json`

### 1.1 `OnboardingTemplate` (new schema)

```jsonc
"OnboardingTemplate": {
  "slug": "onboarding-template",
  "icon": "CalendarCheckOutline",
  "version": "0.1.0",
  "title": "OnboardingTemplate",
  "description": "30-60-90 onboarding plan: milestone-day → course list, applied on hire.",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["name", "roleSlug", "milestones", "tenant_id"],
  "properties": {
    "name":          { "type": "string" },
    "description":   { "type": ["string", "null"] },
    "roleSlug":      { "type": "string",
                       "description": "NC role slug this template targets (e.g. 'medewerker', 'teamleider')" },
    "departmentSlug":{ "type": ["string", "null"],
                       "description": "Optional: restrict to one department slug" },
    "milestones":    {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["day", "courseIds"],
        "properties": {
          "day":       { "type": "integer", "enum": [1, 30, 60, 90] },
          "label":     { "type": ["string", "null"] },
          "courseIds": { "type": "array", "items": { "type": "string", "format": "uuid" } },
          "mandatory": { "type": "boolean", "default": true }
        }
      }
    },
    "lifecycle":     { "type": "string", "enum": ["draft", "active", "archived"], "default": "draft" },
    "tenant_id":     { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "activate": { "from": "draft",   "to": "active" },
      "archive":  { "from": ["draft", "active"], "to": "archived" }
    }
  },
  "x-openregister-relations": {
    "enrolmentRules": {
      "register": "scholiq", "schema": "EnrolmentRule",
      "cardinality": "one-to-many", "joinOn": "onboardingTemplateId"
    }
  },
  "x-openregister-calculations": {
    "milestoneCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "prop": "milestones" } }
    },
    "totalCourseSlots": {
      "type": "integer",
      "materialise": true,
      "expression": {
        "sum": {
          "map": { "prop": "milestones", "extract": { "count": { "prop": "courseIds" } } }
        }
      }
    }
  }
}
```

### 1.2 `EnrolmentRule` (new schema)

```jsonc
"EnrolmentRule": {
  "slug": "enrolment-rule",
  "icon": "AutorenewOutline",
  "version": "0.1.0",
  "title": "EnrolmentRule",
  "description": "Declarative trigger → auto-enrolment rule (hire / Studielink / certificate expiry).",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["name", "triggerEvent", "courseIds", "tenant_id"],
  "properties": {
    "name":                  { "type": "string" },
    "description":           { "type": ["string", "null"] },
    "triggerEvent":          { "type": "string",
                               "enum": ["hire", "studielink-intake", "certificate-expiry", "cohort-activate"] },
    "audienceType":          { "type": "string",
                               "enum": ["all", "role", "department", "cohort", "studielink-programme"],
                               "default": "all" },
    "audienceValue":         { "type": ["string", "null"],
                               "description": "Role slug, department slug, Cohort UUID, or OOAPI programme-id" },
    "courseIds":             { "type": "array", "items": { "type": "string", "format": "uuid" } },
    "mandatory":             { "type": "boolean", "default": true },
    "dueDays":               { "type": ["integer", "null"],
                               "description": "Enrolment due_date = trigger date + dueDays. Null = no due date." },
    "onboardingTemplateId":  { "type": ["string", "null"], "format": "uuid" },
    "lifecycle":             { "type": "string", "enum": ["draft", "active", "archived"], "default": "draft" },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "activate": { "from": "draft",   "to": "active" },
      "archive":  { "from": ["draft", "active"], "to": "archived" }
    }
  },
  "x-openregister-relations": {
    "onboardingTemplate": {
      "register": "scholiq", "schema": "OnboardingTemplate",
      "cardinality": "many-to-one", "joinOn": "onboardingTemplateId"
    }
  }
}
```

### 1.3 `Enrolment` patch (extend Phase 1 schema — backward-compatible)

Four optional fields added to the existing `Enrolment` schema. All nullable/optional — no migration step required.

```jsonc
// Additions to existing Enrolment.properties:
"prerequisitesMet":     { "type": ["boolean", "null"],
                          "description": "null = not yet evaluated; true = passed; false = blocked" },
"onboardingTemplateId": { "type": ["string", "null"], "format": "uuid",
                          "description": "OnboardingTemplate that created this Enrolment, if any" },
"onboardingMilestoneDay": { "type": ["integer", "null"],
                             "description": "Milestone day (1/30/60/90) from the OnboardingTemplate" },
"lmsProvisionedAt":     { "type": ["string", "null"], "format": "date-time",
                          "description": "Timestamp when the LMS account was provisioned (HE Studielink flow)" }
```

New `activate` transition precondition added to `Enrolment.x-openregister-lifecycle.transitions`:

```jsonc
"activate": {
  "from": "pending",
  "to": "active",
  "requires": "OCA\\Scholiq\\Lifecycle\\PrerequisiteCheckGuard"
}
```

New relation added to `Enrolment.x-openregister-relations`:

```jsonc
"onboardingTemplate": {
  "register": "scholiq", "schema": "OnboardingTemplate",
  "cardinality": "many-to-one", "joinOn": "onboardingTemplateId"
}
```

---

## 2. PHP files — ADR-031 legitimate exceptions only

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/PrerequisiteCheckGuard.php` | Lifecycle guard | Cross-schema pre-condition: queries OR for completed Enrolments for each prerequisite course before allowing `activate` transition. Cannot be expressed declaratively. Returns structured `{blocked: true, missing: [{courseId, title}]}` on failure. |
| `lib/Lifecycle/StudielinkEnrolmentHandler.php` | External-system bridge | Receives `openconnector.studielink.intake.received` event from OR's event bus (published by OpenConnector's Edukoppeling adapter). Idempotently upserts `LearnerProfile`, creates `Enrolment` with `source=studielink`, dispatches `lms.account.provision` background job (must complete within 60 s SLA). Single entry point; no service class. |
| `lib/Lifecycle/OnboardingTemplateApplicator.php` | Audit-event handler | Receives `learner.profile.created` audit event. Queries active `EnrolmentRule` objects with `triggerEvent=hire` whose `audienceType/audienceValue` matches the new LearnerProfile's `roleSlug`. For each matched rule: if `onboardingTemplateId` is set, resolves milestones and creates Enrolment objects with `onboardingMilestoneDay` and calculated `dueDate = hireDate + milestoneDay`. No service class; single method. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `EnrolmentRuleController` — `CnAppRoot` index/detail pages cover list/show; lifecycle transitions are OR REST calls.
- `OnboardingTemplateController` — same as above.
- `EnrolmentRuleEvaluationService` — declarative lifecycle on `EnrolmentRule` + event handlers above replace it.
- `StudielinkSyncJob` (`TimedJob`) — event-driven handler replaces polling.
- `EnrolmentPrerequisiteService` — `PrerequisiteCheckGuard` is the only legitimate PHP surface.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest additions to `src/manifest.json`

```jsonc
{
  "pages": [
    { "id": "EnrolmentRules",        "route": "/enrolments/rules",      "type": "index",  "config": { "register": "scholiq", "schema": "EnrolmentRule" } },
    { "id": "EnrolmentRuleDetail",   "route": "/enrolments/rules/:id",  "type": "detail", "config": { "register": "scholiq", "schema": "EnrolmentRule" } },
    { "id": "OnboardingTemplates",   "route": "/onboarding/templates",  "type": "index",  "config": { "register": "scholiq", "schema": "OnboardingTemplate" } },
    { "id": "OnboardingTemplateDetail", "route": "/onboarding/templates/:id", "type": "detail", "config": { "register": "scholiq", "schema": "OnboardingTemplate" } },
    { "id": "TeamBulkEnrol",         "route": "/enrolments/team-bulk", "type": "custom", "config": { "component": "TeamBulkEnrolModal" } }
  ]
}
```

### 3.2 `TeamBulkEnrolModal.vue`

Custom Vue component registered via `customComponents` on `CnAppRoot`. Multi-step modal for line managers:

1. **Audience picker** — fetches the current user's direct reports via NC OCS `/ocs/v2.php/cloud/users?groupId=<manager-group>`; renders a multi-select list. Also supports NC group selector and CSV upload (parsed browser-side).
2. **Course + config** — Course picker (`GET /api/openregister/scholiq/Course?lifecycle=published`), `mandatory` toggle, shared `dueDate` picker, optional `regulationSlug`.
3. **Preview + submit** — shows selected learners × course × deadline summary. On confirm: POSTs directly to OR's batch endpoint `POST /api/openregister/scholiq/Enrolment/batch` with body `{objects: [{learnerId, courseId, mandatory, dueDate, source: "manager", managerId: <currentUser>, bulkJobId: <uuid>}, ...]}`.
4. **Progress polling** — polls `GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid>` every 2 s; renders a team progress bar (`X / N enrolled`). Stops polling when count matches expected.

No Scholiq backend controller. No custom Pinia store. No Vue Router entries beyond the manifest page declaration.

### 3.3 No app-local store, no bespoke CRUD views

Per ADR-031 + ADR-024: `CnAppRoot`'s built-in index/detail renderers cover `EnrolmentRule` and `OnboardingTemplate` CRUD. No `src/stores/enrolmentRuleStore.js` or `src/views/OnboardingTemplateListView.vue`.

---

## 4. Seed data — Dutch example objects

### 4.1 `OnboardingTemplate` seeds

```jsonc
[
  {
    "name": "Medewerker Onboarding — Standaard",
    "description": "30-60-90 dag onboarding voor nieuwe medewerkers in alle afdelingen.",
    "roleSlug": "medewerker",
    "departmentSlug": null,
    "milestones": [
      { "day": 1,  "label": "Dag 1 — Oriëntatie",          "courseIds": ["<uuid-arbo>", "<uuid-avg-intro>"], "mandatory": true },
      { "day": 30, "label": "Dag 30 — Kernprocessen",       "courseIds": ["<uuid-kernprocessen>"],           "mandatory": true },
      { "day": 60, "label": "Dag 60 — Verdieping",          "courseIds": ["<uuid-verdieping>"],              "mandatory": false },
      { "day": 90, "label": "Dag 90 — Eindtoets & Borging", "courseIds": ["<uuid-eindtoets>"],               "mandatory": true }
    ],
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "name": "ICT-medewerker Onboarding",
    "description": "Technische onboarding voor ICT-medewerkers inclusief BIO2-bewustzijn.",
    "roleSlug": "ict-medewerker",
    "departmentSlug": "ict",
    "milestones": [
      { "day": 1,  "label": "Dag 1 — BIO2 Basis",           "courseIds": ["<uuid-bio2>"],                    "mandatory": true },
      { "day": 30, "label": "Dag 30 — Informatiebeveiliging","courseIds": ["<uuid-infosec>"],                 "mandatory": true },
      { "day": 90, "label": "Dag 90 — NIS2 Praktijk",        "courseIds": ["<uuid-nis2>"],                    "mandatory": true }
    ],
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "name": "Teamleider Onboarding",
    "description": "Leiderschapsonboarding voor nieuwe teamleiders.",
    "roleSlug": "teamleider",
    "departmentSlug": null,
    "milestones": [
      { "day": 1,  "label": "Dag 1 — Inleiding leidinggeven","courseIds": ["<uuid-leidinggeven>"],            "mandatory": true },
      { "day": 30, "label": "Dag 30 — Teamontwikkeling",     "courseIds": ["<uuid-teamontwikkeling>"],        "mandatory": true },
      { "day": 60, "label": "Dag 60 — Gesprekstechnieken",   "courseIds": ["<uuid-gesprekken>"],              "mandatory": false },
      { "day": 90, "label": "Dag 90 — HR-processen",         "courseIds": ["<uuid-hr-processen>"],            "mandatory": true }
    ],
    "lifecycle": "draft",
    "tenant_id": "<tenant-uuid>"
  }
]
```

### 4.2 `EnrolmentRule` seeds

```jsonc
[
  {
    "name": "Auto-inschrijving nieuwe medewerker",
    "description": "Schrijft iedere nieuwe medewerker automatisch in op de standaard onboarding bij aanmaken account.",
    "triggerEvent": "hire",
    "audienceType": "role",
    "audienceValue": "medewerker",
    "courseIds": [],
    "mandatory": true,
    "dueDays": 90,
    "onboardingTemplateId": "<uuid-template-medewerker>",
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "name": "Studielink HO inschrijving — Bedrijfskunde",
    "description": "Verwerkt inkomende Studielink-intakes voor de opleiding Bedrijfskunde.",
    "triggerEvent": "studielink-intake",
    "audienceType": "studielink-programme",
    "audienceValue": "croho-34401",
    "courseIds": ["<uuid-oriëntatie-bedrijfskunde>", "<uuid-studievaardigheden>"],
    "mandatory": true,
    "dueDays": 14,
    "onboardingTemplateId": null,
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "name": "Hernieuwd bij verlopen AVG-certificaat",
    "description": "Schrijft medewerkers in op de AVG-herhalingscursus 30 dagen voor certificaatverloopdatum.",
    "triggerEvent": "certificate-expiry",
    "audienceType": "all",
    "audienceValue": null,
    "courseIds": ["<uuid-avg-herhalingscursus>"],
    "mandatory": true,
    "dueDays": 30,
    "onboardingTemplateId": null,
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "name": "Cohortactivering — Instroom 2026",
    "description": "Schrijft alle leden van het cohort in op het oriëntatieprogramma bij activering van het cohort.",
    "triggerEvent": "cohort-activate",
    "audienceType": "cohort",
    "audienceValue": "<uuid-cohort-instroom-2026>",
    "courseIds": ["<uuid-oriëntatie-ho>"],
    "mandatory": true,
    "dueDays": 7,
    "onboardingTemplateId": null,
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  }
]
```

### 4.3 Extended `Enrolment` seed examples (new fields only)

```jsonc
[
  {
    "learnerId": "jan.de.vries@hogeschool.nl",
    "courseId": "<uuid-oriëntatie-bedrijfskunde>",
    "mandatory": true,
    "dueDate": "2026-06-03",
    "source": "studielink",
    "prerequisitesMet": true,
    "onboardingTemplateId": null,
    "onboardingMilestoneDay": null,
    "lmsProvisionedAt": "2026-05-20T09:00:42Z",
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "learnerId": "fatima.el-amrani@gemeente.nl",
    "courseId": "<uuid-avg-intro>",
    "mandatory": true,
    "dueDate": "2026-06-20",
    "source": "system",
    "prerequisitesMet": true,
    "onboardingTemplateId": "<uuid-template-medewerker>",
    "onboardingMilestoneDay": 1,
    "lmsProvisionedAt": null,
    "lifecycle": "active",
    "tenant_id": "<tenant-uuid>"
  },
  {
    "learnerId": "henk.bakker@corporatie.nl",
    "courseId": "<uuid-verdieping>",
    "mandatory": false,
    "dueDate": "2026-07-19",
    "source": "system",
    "prerequisitesMet": null,
    "onboardingTemplateId": "<uuid-template-medewerker>",
    "onboardingMilestoneDay": 60,
    "lmsProvisionedAt": null,
    "lifecycle": "pending",
    "tenant_id": "<tenant-uuid>"
  }
]
```

---

## 5. Audit events emitted (declaratively)

| Trigger | event_type | Declared in |
|---|---|---|
| Studielink intake received | `enrolment.created` (source=studielink) | `StudielinkEnrolmentHandler` → OR default save audit |
| Enrolment `pending → active` (prerequisite passed) | `enrolment.activated` | `Enrolment.x-openregister-lifecycle` (Phase 1) |
| Enrolment blocked by prerequisite | `enrolment.prerequisite.blocked` | OR lifecycle engine (guard returns false) |
| LMS provisioned | `enrolment.lms.provisioned` | `StudielinkEnrolmentHandler` writes `lmsProvisionedAt` field → OR save audit |
| Onboarding template applied (N Enrolments created) | `enrolment.created` × N (source=system, onboardingMilestoneDay set) | `OnboardingTemplateApplicator` → OR default save audit |
| EnrolmentRule activated | `enrolment-rule.activated` | `EnrolmentRule.x-openregister-lifecycle` |

No `AuditEventTypes::KNOWN`, no `Scholiq\Service\AuditTrail::record()`.

---

## 6. Integration points

| System | Interface | Purpose |
|---|---|---|
| OpenConnector (Edukoppeling adapter) | OR event bus: `openconnector.studielink.intake.received` | Delivers parsed Studielink intake payload; Scholiq does NOT make outbound HTTP to DUO |
| OpenRegister | Schema lifecycle / calculations / relations + REST + audit + batch import | All Enrolment, OnboardingTemplate, EnrolmentRule operations |
| `IGroupManager` (native NC) | NC OCS API `/ocs/v2.php/cloud/users?groupId=...` | Manager's direct-report list for `TeamBulkEnrolModal` audience resolution |
| `@conduction/nextcloud-vue` | `CnAppRoot` + `customComponents` | `TeamBulkEnrolModal` registration + index/detail renderers |
| Certification change | Listens to OR's `enrolment.completed` audit event | Auto-issues Credential (declared in certification schema — no Scholiq listener here) |
| Course-management change | `Course.prerequisiteCourseIds` field | `PrerequisiteCheckGuard` queries this field to build the missing-prerequisites list |
| OOAPI 5.0 (Phase 3) | OpenConnector OOAPI adapter | Studielink programme-id → Course mapping; deferred to Phase 3 |

---

## 7. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| OnboardingTemplate state machine | declarative | lifecycle |
| EnrolmentRule state machine | declarative | lifecycle |
| Enrolment prerequisitesMet check | imperative (PHP guard) | "Lifecycle guards" — cross-schema query |
| Studielink intake → Enrolment creation | imperative (PHP handler) | "External-system bridge" |
| Hire event → Onboarding milestone Enrolments | imperative (PHP handler) | "Audit-event handler" |
| LMS account provisioning | imperative (background job via OR dispatcher) | "External-system bridge" |
| OnboardingTemplate.milestoneCount | declarative | calculation |
| Audit entries on all transitions | declarative (OR) | consumed via ADR-022 |
| Bulk-enrolment (team) | declarative (OR REST batch + Vue modal) | consumed via ADR-022 |
| T-30 / T-7 / T-1 reminders | declarative (Phase 1 notifications) | notification (unchanged) |

---

## 8. Scope exclusions

| Excluded | Deferred to |
|---|---|
| Payment processing for paid enrolments | Enterprise spec; routes to billing system |
| Waitlist auto-promotion | V1 enhancement |
| Cross-institution credit transfer | Phase 3 (oso-transfer / EDCI) |
| OOAPI 5.0 programme-code → Course mapping | Phase 3 (OpenConnector OOAPI adapter) |
| DigiD / eHerkenning identity verification for Studielink | OpenConnector identity adapter (identity-federation spec) |
| NC group provisioning from Cohort.learnerIds on EnrolmentRule trigger | Deferred — NCGroupSyncListener out of scope for this change |
