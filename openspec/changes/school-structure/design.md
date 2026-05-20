# Design — School Structure (Phase 2 Foundation)

> **Declarative-vs-imperative decision (per ADR-031)** — lifecycle state machines, relations, calculations, and aggregations for Programme, CurriculumPlan, Cohort, Session, and Material all fit `x-openregister-*` declarations in `scholiq_register.json`. Two PHP guards are legitimately imperative: `ProgrammePublishGuard` (cross-schema pre-condition: verify CurriculumPlan is published and has ≥1 required course before Programme can publish) and `CohortMembershipGuard` (single-field pre-condition: verify learnerIds is non-empty before Cohort activates). No controllers, no services.
>
> **OR abstractions consumed (per ADR-022)** — lifecycle engine (`x-openregister-lifecycle`), relations (`x-openregister-relations`), calculations (`x-openregister-calculations`), RBAC, audit trail, archival/retention, MCP discovery. The CohortTimetable Vue view talks directly to OR's REST API via `generateUrl` — no custom PHP controller.
>
> **Frontend (per ADR-024)** — all CRUD for Programme, CurriculumPlan, Cohort, Session, Material is served by `CnAppRoot`'s built-in index/detail renderers via `src/manifest.json`. The only genuinely-custom UI is the `CohortTimetable` view (date-grouped session list with inline materials and assignment counts), which a manifest index page cannot express.

---

## 1. New schemas in `lib/Settings/scholiq_register.json`

### 1.1 `Programme`

```jsonc
"Programme": {
  "slug": "programme",
  "icon": "SchoolOutline",
  "required": ["name", "level", "tenant_id"],
  "properties": {
    "name":                string,           // e.g. "HBO-V bachelor", "vmbo-tl examenjaar"
    "code":                string|null,      // institution-specific programme code
    "level":               enum[po,vo,mbo,hbo,wo,corporate],
    "description":         string|null,
    "curriculumPlanId":    uuid|null,        // FK → CurriculumPlan
    "courseIds":           uuid[],           // courses included in this programme
    "credentialTemplateId": uuid|null,       // credential to issue on completion
    "lifecycle":           enum[draft,published,archived],
    "tenant_id":           uuid
  },
  "x-openregister-lifecycle": {
    "states": ["draft","published","archived"],
    "transitions": [
      { "from": "draft",      "to": "published", "requires": "OCA\\Scholiq\\Lifecycle\\ProgrammePublishGuard" },
      { "from": "published",  "to": "archived" },
      { "from": "archived",   "to": "published" }
    ]
  },
  "x-openregister-relations": {
    "curriculumPlan": { "schema": "CurriculumPlan", "joinOn": "curriculumPlanId" },
    "courses":        { "schema": "Course", "cardinality": "many-to-many", "joinOn": "courseIds" }
  },
  "x-openregister-calculations": {
    "courseCount": { "count": "courseIds" }
  }
}
```

**ProgrammePublishGuard** (ADR-031 lifecycle guard): calls `ObjectService::findAll(['register'=>'scholiq','schema'=>'CurriculumPlan','filters'=>['uuid'=>$curriculumPlanId,'lifecycle'=>'published'],'limit'=>1])`. Also checks `requiredCourseIds` is non-empty. Returns `false` (blocks transition) if either condition fails. Registered in the schema via `requires` FQCN — no `Application.php` entry needed.

### 1.2 `CurriculumPlan`

```jsonc
"CurriculumPlan": {
  "slug": "curriculum-plan",
  "icon": "ClipboardTextOutline",
  "required": ["name", "kind", "formula", "tenant_id"],
  "properties": {
    "name":             string,
    "kind":             enum[pta,oer,opleidingsplan,training-curriculum,generic],
    "requiredCourseIds": uuid[],
    "electiveCourseIds": uuid[],
    "components": [
      {
        "componentId": string,                              // stable ID (kolom slug)
        "label":       string,                             // e.g. "Toets periode 1"
        "weight":      number,                             // relative weight (e.g. 3 = 3×)
        "period":      string|integer,                     // period identifier
        "kind":        enum[assignment,assessment,participation]
      }
    ],
    "formula":      enum[weighted-average,last-attempt,best-of-n,all-must-pass],
    "gradeScaleId": uuid|null,
    "passRules":    [{ "componentId": string|null, "minValue": number }],
    "periods": [
      { "periodId": string, "label": string, "startDate": date, "endDate": date }
    ],
    "lifecycle":    enum[draft,published,archived],
    "tenant_id":    uuid
  },
  "x-openregister-lifecycle": {
    "states": ["draft","published","archived"],
    "transitions": [
      { "from": "draft",     "to": "published" },
      { "from": "published", "to": "archived" },
      { "from": "archived",  "to": "published" }
    ]
  }
}
```

The `components` + `formula` shape is the canonical interface for the `grading` spec. FinalGrade computation reads `CurriculumPlan.components` (for weights) and `CurriculumPlan.formula` (for roll-up strategy). Dutch PTA: kolommen with weegfactor = `components[].weight` per period; SE-formula = `formula: weighted-average`.

### 1.3 `Cohort`

```jsonc
"Cohort": {
  "slug": "cohort",
  "icon": "AccountGroupOutline",
  "required": ["name", "period", "academicYear", "tenant_id"],
  "properties": {
    "name":         string,           // e.g. "4VWO-A", "HBO-V jaar 2 groep B"
    "programmeId":  uuid|null,
    "courseId":     uuid|null,
    "teacherIds":   string[],         // NC user IDs of teachers
    "learnerIds":   string[],         // NC user IDs of enrolled learners
    "ncGroupId":    string|null,      // Nextcloud group ID for permission gating
    "period":       string,           // e.g. "2025-2026"
    "academicYear": string,           // e.g. "2025-2026"
    "lifecycle":    enum[planned,active,completed,archived],
    "tenant_id":    uuid
  },
  "x-openregister-lifecycle": {
    "states": ["planned","active","completed","archived"],
    "transitions": [
      { "from": "planned",   "to": "active",    "requires": "OCA\\Scholiq\\Lifecycle\\CohortMembershipGuard" },
      { "from": "active",    "to": "completed" },
      { "from": "completed", "to": "archived" },
      { "from": "planned",   "to": "archived" }
    ]
  },
  "x-openregister-relations": {
    "programme": { "schema": "Programme", "joinOn": "programmeId" },
    "course":    { "schema": "Course",    "joinOn": "courseId" }
  },
  "x-openregister-calculations": {
    "learnerCount": { "count": "learnerIds" }
  }
}
```

**CohortMembershipGuard** (ADR-031 lifecycle guard): checks `!empty($object['learnerIds'])`. Single boolean check — no OR queries needed. NC group provisioning (ncGroupId) is explicitly deferred to a future event listener per scope.

### 1.4 `Session`

```jsonc
"Session": {
  "slug": "session",
  "icon": "CalendarClockOutline",
  "required": ["cohortId", "title", "startsAt", "endsAt", "tenant_id"],
  "properties": {
    "cohortId":       uuid,
    "courseId":       uuid|null,
    "lessonId":       uuid|null,
    "title":          string,         // e.g. "Hoorcollege Mendelwetten"
    "startsAt":       datetime,
    "endsAt":         datetime,
    "location":       string|null,    // room name or URL — no conflict-resolution
    "materialIds":    uuid[],
    "assignmentIds":  uuid[],         // forward-ref to assignments spec
    "lifecycle":      enum[scheduled,in-progress,completed,cancelled],
    "tenant_id":      uuid
  },
  "x-openregister-lifecycle": {
    "states": ["scheduled","in-progress","completed","cancelled"],
    "transitions": [
      { "from": "scheduled",   "to": "in-progress" },
      { "from": "in-progress", "to": "completed"   },
      { "from": "scheduled",   "to": "cancelled"   },
      { "from": "in-progress", "to": "cancelled"   }
    ]
  },
  "x-openregister-relations": {
    "cohort":    { "schema": "Cohort",    "joinOn": "cohortId" },
    "course":    { "schema": "Course",    "joinOn": "courseId" },
    "lesson":    { "schema": "Lesson",    "joinOn": "lessonId" },
    "materials": { "schema": "Material",  "cardinality": "many-to-many", "joinOn": "materialIds" }
  },
  "x-openregister-calculations": {
    "durationMinutes": { "dateDiff": ["endsAt", "startsAt", "minutes"] },
    "isPast":          { "lt": ["endsAt", "@now"] }
  }
}
```

### 1.5 `Material`

```jsonc
"Material": {
  "slug": "material",
  "icon": "FilePresentationBoxOutline",
  "required": ["title", "kind", "fileRef", "tenant_id"],
  "properties": {
    "title":     string,
    "kind":      enum[slides,reading,video,scorm,cmi5,lti,link,document,other],
    "fileRef":   string,      // OR file attachment reference — app MUST NOT store bytes
    "url":       uri|null,    // used when kind=link
    "license":   string|null, // e.g. "CC-BY-4.0"
    "lomTags":   string[],    // NL-LOM / VDEX vocabulary tags
    "order":     integer,
    "courseId":  uuid|null,
    "lessonId":  uuid|null,
    "sessionId": uuid|null,
    "tenant_id": uuid
  },
  "x-openregister-relations": {
    "course":   { "schema": "Course",   "joinOn": "courseId" },
    "lesson":   { "schema": "Lesson",   "joinOn": "lessonId" },
    "session":  { "schema": "Session",  "joinOn": "sessionId" }
  }
}
```

---

## 2. Modified schemas in `lib/Settings/scholiq_register.json`

### 2.1 `Course` additions

Three new properties added (all optional/nullable — backward-compatible):

| Field | Type | Purpose |
|---|---|---|
| `parentCourseId` | uuid\|null | Self-reference making Course recursive. A "module" is a Course used as a container. |
| `curriculumPlanId` | uuid\|null | The governing CurriculumPlan. Consumed by grading spec for FinalGrade computation. |
| `programmeIds` | uuid[] | Programmes that include this Course. |

New `x-openregister-relations` entries: `parentCourse` (self-reference), `curriculumPlan`, `programmes` (many-to-many via programmeIds). Existing lifecycle, calculations, aggregations, and notifications are unchanged.

### 2.2 `Enrolment` addition

One new field: `cohortId` (uuid|null). One new relation in `x-openregister-relations`: `cohort` → Cohort schema via `cohortId`. All existing fields, lifecycle, calculations, and notifications unchanged.

---

## 3. PHP — ADR-031 legitimate exceptions only

| File | ADR-031 category | Why imperative |
|---|---|---|
| `lib/Lifecycle/ProgrammePublishGuard.php` | Lifecycle guard | Cross-schema pre-condition: requires an OR query to verify CurriculumPlan state. Cannot be expressed as a declarative JSON-logic condition. |
| `lib/Lifecycle/CohortMembershipGuard.php` | Lifecycle guard | Single-field pre-condition (`!empty(learnerIds)`). Kept as a PHP guard for lifecycle-contract consistency; no OR queries. |

NOT in this change (ADR-031 anti-patterns avoided): no PHP controller for Programme/CurriculumPlan/Cohort/Session/Material CRUD, no service layer, no TimedJob, no custom notification service, no event listener (NC group sync deferred to a future change).

---

## 4. Frontend — `CnAppRoot` consumption

### 4.1 Manifest pages in `src/manifest.json`

| Page ID | Route | Type | Schema |
|---|---|---|---|
| `Programmes` | `/curriculum/programmes` | `index` | `Programme` |
| `ProgrammeDetail` | `/curriculum/programmes/:id` | `detail` | `Programme` |
| `CurriculumPlans` | `/curriculum/plans` | `index` | `CurriculumPlan` |
| `CurriculumPlanDetail` | `/curriculum/plans/:id` | `detail` | `CurriculumPlan` |
| `Cohorts` | `/cohorts` | `index` | `Cohort` |
| `CohortDetail` | `/cohorts/:id` | `detail` | `Cohort` |
| `CohortTimetable` | `/cohorts/:id/timetable` | `custom` | component: `CohortTimetable` |
| `Sessions` | `/sessions` | `index` | `Session` |
| `SessionDetail` | `/sessions/:id` | `detail` | `Session` |
| `Materials` | `/materials` | `index` | `Material` |
| `MaterialDetail` | `/materials/:id` | `detail` | `Material` |

Nav: "Curriculum" menu entry (order 45) routes to `/curriculum/programmes`.

### 4.2 `CohortTimetable.vue`

Date-grouped timetable: fetches Cohort + Sessions (filter by `cohortId`, sort `startsAt:asc`) + Materials (parallel per-session fetch). Groups sessions by calendar date. Per-session renders: time range, `durationMinutes`, title, location, Materials list (icon + title + kind badge), and assignment count. Uses Options API + OR REST API calls via `generateUrl` (no custom Pinia store module). Registered in `src/main.js` as `customComponents: { CohortTimetable }`.

**NOT created**: `src/router/index.js` entries, `src/store/modules/*`, bespoke list/edit Vue files — `CnAppRoot` index/detail renderers cover those via the manifest.

---

## 5. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Programme state machine (draft → published → archived) | Declarative | `x-openregister-lifecycle` |
| CurriculumPlan state machine (draft → published → archived) | Declarative | `x-openregister-lifecycle` |
| Cohort state machine (planned → active → completed | archived) | Declarative | `x-openregister-lifecycle` |
| Session state machine (scheduled → in-progress → completed | cancelled) | Declarative | `x-openregister-lifecycle` |
| `Programme.courseCount` | Declarative | `x-openregister-calculations` |
| `Cohort.learnerCount` | Declarative | `x-openregister-calculations` |
| `Session.durationMinutes`, `Session.isPast` | Declarative | `x-openregister-calculations` |
| All schema relations | Declarative | `x-openregister-relations` |
| Programme publish pre-condition (cross-schema CurriculumPlan check) | Imperative | ADR-031 lifecycle guard |
| Cohort activate pre-condition (non-empty learnerIds check) | Imperative | ADR-031 lifecycle guard |
| CohortTimetable date-grouped view | Imperative (Vue) | Genuine custom UI a manifest index page cannot express |
| NC group provisioning on Cohort activate | Deferred | Out of scope for this spec |

---

## 6. Reuse Analysis (per ADR-001)

| Capability needed | OR abstraction consumed | Custom code written? |
|---|---|---|
| Object persistence with schemas | `ObjectService::saveObject()` / `findAll()` | No |
| Lifecycle state machine | `x-openregister-lifecycle` | No (two PHP guards only) |
| Cross-schema relations | `x-openregister-relations` | No |
| Derived/calculated fields | `x-openregister-calculations` | No |
| CRUD list + detail UI | `CnAppRoot` + `CnIndexPage` + `CnDetailPage` via manifest | No |
| Form dialogs | `CnFormDialog` (schema-driven, auto-generated) | No |
| Object sidebar (files, notes, audit) | `CnObjectSidebar` | No |
| Authorization + RBAC | `AuthorizationService` + OR RBAC | No |
| Audit trail | `x-openregister-lifecycle` emits events automatically | No |
| File attachment references | OR file attachments via `fileRef` | No |

No overlap detected with existing `ObjectService`, `RegisterService`, `SchemaService`, or `ConfigurationService` capabilities. No parallel link tables, no home-grown audit trail, no custom RBAC — all consumed from OR per ADR-022.

---

## 7. Seed Data

Realistic Dutch seed objects for `lib/Settings/scholiq_register.json` `components.objects[]`. All objects use the `@self` envelope. 3-5 objects per new schema.

### 7.1 Programme seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "programme", "slug": "hbo-v-bachelor" },
    "name": "HBO-V Bachelor Verpleegkunde",
    "code": "HBOV-2025",
    "level": "hbo",
    "description": "Vierjarige bachelor opleiding verpleegkunde aan de Hogeschool van Amsterdam.",
    "curriculumPlanId": null,
    "courseIds": [],
    "credentialTemplateId": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "programme", "slug": "vmbo-tl-examenjaar" },
    "name": "VMBO-TL Examenjaar 2025-2026",
    "code": "VMBO-TL-4",
    "level": "vo",
    "description": "Eindexamenjaar VMBO theoretische leerweg, schooljaar 2025-2026.",
    "curriculumPlanId": null,
    "courseIds": [],
    "credentialTemplateId": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "programme", "slug": "nis2-board-certificering" },
    "name": "NIS2 Bestuurderscertificering",
    "code": "NIS2-BOARD-2026",
    "level": "corporate",
    "description": "Verplichte certificeringstraining voor bestuurders onder de NIS2-richtlijn.",
    "curriculumPlanId": null,
    "courseIds": [],
    "credentialTemplateId": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 7.2 CurriculumPlan seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "curriculum-plan", "slug": "pta-vmbo-tl-wiskunde-2025" },
    "name": "PTA Wiskunde VMBO-TL 2025-2026",
    "kind": "pta",
    "requiredCourseIds": [],
    "electiveCourseIds": [],
    "components": [
      { "componentId": "t1p1", "label": "Toets periode 1",         "weight": 2, "period": "1", "kind": "assessment" },
      { "componentId": "t1p2", "label": "Toets periode 2",         "weight": 2, "period": "2", "kind": "assessment" },
      { "componentId": "t1p3", "label": "Toets periode 3",         "weight": 3, "period": "3", "kind": "assessment" },
      { "componentId": "pw1",  "label": "Praktische opdracht",     "weight": 1, "period": "2", "kind": "assignment" }
    ],
    "formula": "weighted-average",
    "gradeScaleId": null,
    "passRules": [{ "componentId": null, "minValue": 5.5 }],
    "periods": [
      { "periodId": "1", "label": "Periode 1", "startDate": "2025-09-01", "endDate": "2025-11-14" },
      { "periodId": "2", "label": "Periode 2", "startDate": "2025-11-17", "endDate": "2026-02-06" },
      { "periodId": "3", "label": "Periode 3", "startDate": "2026-02-09", "endDate": "2026-05-22" }
    ],
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "curriculum-plan", "slug": "nis2-training-curriculum" },
    "name": "NIS2 Trainingscurriculum Bestuurders 2026",
    "kind": "training-curriculum",
    "requiredCourseIds": [],
    "electiveCourseIds": [],
    "components": [
      { "componentId": "mod1", "label": "Module 1: Wetgeving en kaders",    "weight": 1, "period": "Q1", "kind": "assessment" },
      { "componentId": "mod2", "label": "Module 2: Technische maatregelen", "weight": 1, "period": "Q1", "kind": "assessment" },
      { "componentId": "attes","label": "Attestatie eindtoets",             "weight": 2, "period": "Q1", "kind": "assessment" }
    ],
    "formula": "all-must-pass",
    "gradeScaleId": null,
    "passRules": [{ "componentId": null, "minValue": 5.5 }],
    "periods": [
      { "periodId": "Q1", "label": "Q1 2026", "startDate": "2026-01-01", "endDate": "2026-03-31" }
    ],
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 7.3 Cohort seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "cohort", "slug": "cohort-4vwo-a-2025" },
    "name": "4VWO-A",
    "programmeId": null,
    "courseId": null,
    "teacherIds": ["docent.janssen"],
    "learnerIds": [],
    "ncGroupId": null,
    "period": "2025-2026",
    "academicYear": "2025-2026",
    "lifecycle": "planned",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "cohort", "slug": "cohort-hbo-v-jaar2-b-2025" },
    "name": "HBO-V Jaar 2 Groep B",
    "programmeId": null,
    "courseId": null,
    "teacherIds": ["docent.deboer", "docent.bakker"],
    "learnerIds": [],
    "ncGroupId": null,
    "period": "2025-2026",
    "academicYear": "2025-2026",
    "lifecycle": "planned",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "cohort", "slug": "cohort-nis2-batch1-2026" },
    "name": "NIS2 Batch 1 — Gemeenteambtenaren",
    "programmeId": null,
    "courseId": null,
    "teacherIds": ["trainer.vanderberg"],
    "learnerIds": [],
    "ncGroupId": null,
    "period": "Q1-2026",
    "academicYear": "2026",
    "lifecycle": "planned",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 7.4 Session seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "session", "slug": "session-wiskunde-week38-ma" },
    "cohortId": null,
    "courseId": null,
    "lessonId": null,
    "title": "Hoorcollege Algebra — week 38 maandag",
    "startsAt": "2025-09-15T09:00:00+02:00",
    "endsAt":   "2025-09-15T10:30:00+02:00",
    "location": "Lokaal 2.14",
    "materialIds": [],
    "assignmentIds": [],
    "lifecycle": "scheduled",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "session", "slug": "session-verpleegkunde-simulatie-1" },
    "cohortId": null,
    "courseId": null,
    "lessonId": null,
    "title": "Simulatietraining Basiszorg — Groep B",
    "startsAt": "2025-10-07T13:00:00+02:00",
    "endsAt":   "2025-10-07T17:00:00+02:00",
    "location": "Simulatiecentrum Kamer 3",
    "materialIds": [],
    "assignmentIds": [],
    "lifecycle": "scheduled",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "session", "slug": "session-nis2-webinar-mod1" },
    "cohortId": null,
    "courseId": null,
    "lessonId": null,
    "title": "NIS2 Module 1 — Wetgeving en kaders (webinar)",
    "startsAt": "2026-01-12T14:00:00+01:00",
    "endsAt":   "2026-01-12T16:00:00+01:00",
    "location": "https://meet.gemeenteonline.nl/nis2-batch1-mod1",
    "materialIds": [],
    "assignmentIds": [],
    "lifecycle": "scheduled",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 7.5 Material seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "material", "slug": "material-algebra-slides-wk38" },
    "title": "Algebra week 38 — Kwadratische vergelijkingen",
    "kind": "slides",
    "fileRef": "scholiq/materials/algebra-kw38-slides.pptx",
    "url": null,
    "license": "CC-BY-NC-4.0",
    "lomTags": ["wiskunde", "algebra", "vmbo-tl"],
    "order": 1,
    "courseId": null,
    "lessonId": null,
    "sessionId": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "material", "slug": "material-nis2-wetgeving-leeswijzer" },
    "title": "NIS2 Leeswijzer Cyberbeveiligingswet (PDF)",
    "kind": "reading",
    "fileRef": "scholiq/materials/nis2-leeswijzer-2026.pdf",
    "url": null,
    "license": "CC-BY-4.0",
    "lomTags": ["nis2", "cybersecurity", "wetgeving", "bestuurders"],
    "order": 1,
    "courseId": null,
    "lessonId": null,
    "sessionId": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "material", "slug": "material-verpleegkunde-protocol-basiszorg" },
    "title": "Protocol Basiszorg — HBO-V Jaar 2",
    "kind": "document",
    "fileRef": "scholiq/materials/protocol-basiszorg-hbov2025.pdf",
    "url": null,
    "license": "Alle rechten voorbehouden",
    "lomTags": ["verpleegkunde", "basiszorg", "protocol", "hbo"],
    "order": 1,
    "courseId": null,
    "lessonId": null,
    "sessionId": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "material", "slug": "material-nis2-external-link" },
    "title": "NCSC — NIS2 Handreiking voor bestuurders",
    "kind": "link",
    "fileRef": "",
    "url": "https://www.ncsc.nl/themas/nis2/handreiking-bestuurders",
    "license": null,
    "lomTags": ["nis2", "ncsc", "referentie"],
    "order": 2,
    "courseId": null,
    "lessonId": null,
    "sessionId": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```
