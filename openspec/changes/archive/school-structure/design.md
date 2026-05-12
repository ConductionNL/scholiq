# Design — School Structure (Phase 2 Foundation)

> **Declarative-vs-imperative decision (per ADR-031)** — lifecycle state machines, relations, calculations, and aggregations for Programme, CurriculumPlan, Cohort, Session, and Material all fit `x-openregister-*` declarations in `scholiq_register.json`. Two PHP guards are legitimately imperative: ProgrammePublishGuard (cross-schema pre-condition: check that CurriculumPlan is published + has ≥1 required course before Programme publishes) and CohortMembershipGuard (single-field pre-condition: check that learnerIds is non-empty before Cohort activates). No controllers, no services.
>
> **OR abstractions consumed (per ADR-022)** — lifecycle engine, relations, calculations, notifications, RBAC, archival, MCP discovery. The CohortTimetable Vue view talks directly to OR's REST API.
>
> **Frontend (per ADR-024)** — all CRUD for Programme, CurriculumPlan, Cohort, Session, Material is served by `CnAppRoot`'s built-in index/detail renderers via `src/manifest.json`. The only genuinely-custom UI is the CohortTimetable timetable view (date-grouped session list with inline materials + assignment counts), which a manifest index page cannot express.

---

## 1. New schemas in `lib/Settings/scholiq_register.json`

### 1.1 `Programme`

```jsonc
"Programme": {
  "slug": "programme",
  "icon": "SchoolOutline",
  "required": ["name", "level", "tenant_id"],
  "properties": {
    "name": string,
    "code": string|null,
    "level": enum[po,vo,mbo,hbo,wo,corporate],
    "description": string|null,
    "curriculumPlanId": uuid|null,
    "courseIds": uuid[],
    "credentialTemplateId": uuid|null,
    "lifecycle": enum[draft,published,archived],
    "tenant_id": uuid
  },
  "x-openregister-lifecycle": { draft → published (requires ProgrammePublishGuard) → archived ↔ },
  "x-openregister-relations": {
    "curriculumPlan": { schema: CurriculumPlan, joinOn: curriculumPlanId },
    "courses": { schema: Course, cardinality: many-to-many, joinOn: courseIds }
  },
  "x-openregister-calculations": {
    "courseCount": { count: prop courseIds }
  }
}
```

**ProgrammePublishGuard** (ADR-031 lifecycle guard): Queries `ObjectService::findAll(['register'=>'scholiq','schema'=>'CurriculumPlan','filters'=>['uuid'=>$curriculumPlanId,'lifecycle'=>'published'],'limit'=>1])`. Checks `requiredCourseIds` is non-empty. Returns `false` (blocks) if either condition fails.

### 1.2 `CurriculumPlan`

```jsonc
"CurriculumPlan": {
  "slug": "curriculum-plan",
  "icon": "ClipboardTextOutline",
  "required": ["name", "kind", "formula", "tenant_id"],
  "properties": {
    "name": string,
    "kind": enum[pta,oer,opleidingsplan,training-curriculum,generic],
    "requiredCourseIds": uuid[],
    "electiveCourseIds": uuid[],
    "components": [{
      "componentId": string,  // stable ID for the kolom
      "label": string,        // display label (e.g. "Toets periode 1")
      "weight": number,       // relative weight (e.g. 3 = 3× contribution)
      "period": string|int,   // period identifier
      "kind": enum[assignment,assessment,participation]
    }],
    "formula": enum[weighted-average,last-attempt,best-of-n,all-must-pass],
    "gradeScaleId": uuid|null,
    "passRules": [{ "componentId": string|null, "minValue": number }],
    "periods": [{ "periodId": string, "label": string, "startDate": date, "endDate": date }],
    "lifecycle": enum[draft,published,archived],
    "tenant_id": uuid
  },
  "x-openregister-lifecycle": { draft → published → archived ↔ }
}
```

The `components` + `formula` shape is the canonical interface for the `grading` spec — FinalGrade computation reads CurriculumPlan.components and CurriculumPlan.formula. Dutch PTA: kolommen with weegfactor = components[].weight per period, SE-formula = formula: weighted-average.

### 1.3 `Cohort`

```jsonc
"Cohort": {
  "slug": "cohort",
  "icon": "AccountGroupOutline",
  "required": ["name", "period", "academicYear", "tenant_id"],
  "properties": {
    "name": string,
    "programmeId": uuid|null,
    "courseId": uuid|null,
    "teacherIds": string[],   // NC user IDs
    "learnerIds": string[],   // NC user IDs of LearnerProfile owners
    "ncGroupId": string|null,
    "period": string,
    "academicYear": string,
    "lifecycle": enum[planned,active,completed,archived],
    "tenant_id": uuid
  },
  "x-openregister-lifecycle": { planned → active (requires CohortMembershipGuard) → completed → archived; planned|completed → archived },
  "x-openregister-relations": {
    "programme": { schema: Programme },
    "course": { schema: Course }
  },
  "x-openregister-calculations": {
    "learnerCount": { count: prop learnerIds }
  }
}
```

**CohortMembershipGuard** (ADR-031 lifecycle guard): checks `!empty($object['learnerIds'])`. Single boolean check — no OR queries needed. NC group provisioning (ncGroupId) is explicitly deferred to a future event listener per spec scope limitations.

### 1.4 `Session`

```jsonc
"Session": {
  "slug": "session",
  "icon": "CalendarClockOutline",
  "required": ["cohortId", "title", "startsAt", "endsAt", "tenant_id"],
  "properties": {
    "cohortId": uuid,
    "courseId": uuid|null,
    "lessonId": uuid|null,
    "title": string,
    "startsAt": datetime,
    "endsAt": datetime,
    "location": string|null,
    "materialIds": uuid[],
    "assignmentIds": uuid[],  // forward-ref to assignments spec
    "lifecycle": enum[scheduled,in-progress,completed,cancelled],
    "tenant_id": uuid
  },
  "x-openregister-lifecycle": { scheduled → in-progress → completed; scheduled|in-progress → cancelled },
  "x-openregister-relations": {
    "cohort": { schema: Cohort },
    "course": { schema: Course },
    "lesson": { schema: Lesson },
    "materials": { schema: Material, cardinality: many-to-many, joinOn: materialIds }
  },
  "x-openregister-calculations": {
    "durationMinutes": { dateDiff: [endsAt, startsAt, minutes] },
    "isPast": { lt: [endsAt, @now] }
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
    "title": string,
    "kind": enum[slides,reading,video,scorm,cmi5,lti,link,document,other],
    "fileRef": string,   // OR file attachment reference; app does NOT store bytes
    "url": uri|null,     // for kind=link
    "license": string|null,
    "lomTags": string[], // NL-LOM / VDEX tags
    "order": integer,
    "courseId": uuid|null,
    "lessonId": uuid|null,
    "sessionId": uuid|null,
    "tenant_id": uuid
  },
  "x-openregister-relations": {
    "course": { schema: Course },
    "lesson": { schema: Lesson },
    "session": { schema: Session }
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
| `curriculumPlanId` | uuid\|null | The governing CurriculumPlan. Consumed by grading spec. |
| `programmeIds` | uuid[] | Programmes that include this Course. |

New `x-openregister-relations` block added: `parentCourse` (self), `curriculumPlan`, `programmes` (many-to-many).

Existing lifecycle, calculations, aggregations, and notifications are unchanged.

### 2.2 `Enrolment` addition

One new field: `cohortId` (uuid|null). One new relation added to `x-openregister-relations`: `cohort` → Cohort schema. All existing fields, lifecycle, calculations, and notifications unchanged.

---

## 3. PHP — ADR-031 legitimate exceptions only

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/ProgrammePublishGuard.php` | Lifecycle guard | Cross-schema pre-condition: requires a OR query to check CurriculumPlan state. Cannot be expressed declaratively. |
| `lib/Lifecycle/CohortMembershipGuard.php` | Lifecycle guard | Single-field pre-condition (non-empty learnerIds). Kept as PHP for guard contract consistency; no OR queries needed. |

NOT in this change (ADR-031 anti-patterns avoided): no controller for Programme/CurriculumPlan/Cohort/Session/Material CRUD, no service layer, no TimedJob, no event listener (NC group sync deferred).

---

## 4. Frontend — `CnAppRoot` consumption

### 4.1 Manifest pages

| Page ID | Route | Type | Schema |
|---|---|---|---|
| Programmes | /curriculum/programmes | index | Programme |
| ProgrammeDetail | /curriculum/programmes/:id | detail | Programme |
| CurriculumPlans | /curriculum/plans | index | CurriculumPlan |
| CurriculumPlanDetail | /curriculum/plans/:id | detail | CurriculumPlan |
| Cohorts | /cohorts | index | Cohort |
| CohortDetail | /cohorts/:id | detail | Cohort |
| CohortTimetable | /cohorts/:id/timetable | custom | CohortTimetable component |
| Sessions | /sessions | index | Session |
| SessionDetail | /sessions/:id | detail | Session |
| Materials | /materials | index | Material |
| MaterialDetail | /materials/:id | detail | Material |

Nav: "Curriculum" menu entry (order 45) routes to Programmes.

### 4.2 `CohortTimetable.vue`

Date-grouped timetable: fetches Cohort + Sessions (filter by cohortId, sort startsAt:asc) + Materials (per-session parallel fetch). Groups sessions by calendar date. Renders per-session: time range, duration, title, location, materials (icon + title + kind badge), assignment count. Uses Options API + direct OR REST API calls via `generateUrl` (no custom store). Registered in `src/main.js` customComponents as `CohortTimetable`.

**NOT created**: src/router/index.js entries, src/store/modules/*, bespoke list/edit Vue files — CnAppRoot index/detail renderers cover those.

---

## 5. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Programme state machine | declarative | lifecycle |
| CurriculumPlan state machine | declarative | lifecycle |
| Cohort state machine | declarative | lifecycle |
| Session state machine | declarative | lifecycle |
| Programme.courseCount | declarative | calculation |
| Cohort.learnerCount | declarative | calculation |
| Session.durationMinutes, isPast | declarative | calculation |
| All schema relations | declarative | relations |
| Programme publish precondition (cross-schema) | imperative | "Lifecycle guard" |
| Cohort activate precondition (non-empty check) | imperative | "Lifecycle guard" |
| CohortTimetable date-grouped view | imperative (Vue) | "Genuine UI a manifest index can't express" |
| NC group provisioning on Cohort activate | deferred | Out of scope for this spec |
