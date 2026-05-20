# Design — Course Management (Phase 2)

> **Declarative-vs-imperative decision (per [hydra ADR-031](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every Course / Module / Lesson state transition, ECTS sum calculation, prerequisite-met flag, enrolment-count aggregation, and catalogue-change cascade fits `x-openregister-lifecycle` / `-calculations` / `-aggregations` / `-notifications` / `-relations`. They land as JSON patches on `lib/Settings/scholiq_register.json`, not as PHP service classes. **In-fleet references**: `decidesk/lib/Settings/decidesk_register.json` demonstrates `aggregations` + `calculations` + `relations` + `seeds` shapes. `lifecycle` cascades follow the contract in [openregister#1470](https://github.com/ConductionNL/openregister/issues/1470). The two services this change ships — `OoapiController` and `CourseCloneService` — are ADR-031 exceptions (external-system contract / document generation).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable), RBAC, archival, batch import, relations, lifecycle-cascade engine, notification engine. No app-local audit substrate, no app-local relation tables.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — all new index + detail pages are declared in `src/manifest.json` as `type: index` or `type: detail` bound to their respective schema. Custom components (`LessonPlayer` — Phase 1) remain registered via `customComponents` on `CnAppRoot`.

---

## 1. Schema patches in `lib/Settings/scholiq_register.json`

### 1.1 `Course` — extended fields (non-breaking per ADR-011)

Additional properties added to the existing Phase 1 `Course` schema:

```jsonc
// Added to Course.properties:
"ects":                        { "type": ["integer","null"], "minimum": 0, "description": "ECTS credits for this course (HE context, Bologna)." },
"ooApiCode":                   { "type": ["string","null"], "description": "OOAPI 5.0 courseCode." },
"ooApiEducationSpecificationId": { "type": ["string","null"], "format": "uuid", "description": "OOAPI 5.0 educationSpecification.educationSpecificationId." },
"clonedFromId":                { "type": ["string","null"], "format": "uuid", "description": "UUID of the Course this was cloned from." },
"academicYear":                { "type": ["string","null"], "pattern": "^\\d{4}-\\d{4}$", "description": "Academic year tag e.g. 2026-2027." },
"approvalStatus":              { "type": "string", "enum": ["none","pending","approved","rejected"], "default": "none" }

// Updated Course.properties.level enum (additive):
"level": { "type": "string", "enum": ["nlqf1","nlqf2","nlqf3","nlqf4","nlqf5","nlqf6","nlqf7","nlqf8","po","vo","mbo","hbo","wo","corporate"] }
```

Updated `x-openregister-lifecycle` publish guard now requires `moduleCount > 0`:

```jsonc
"x-openregister-lifecycle": {
  "field": "lifecycle",
  "default": "draft",
  "transitions": {
    "publish":   { "from": "draft",     "to": "published", "requires": "OCA\\Scholiq\\Lifecycle\\CoursePublishGuard" },
    "archive":   { "from": "published", "to": "archived" },
    "unarchive": { "from": "archived",  "to": "draft" }
  }
}
```

`CoursePublishGuard` updated to assert `moduleCount > 0` (Phase 1 checked `lessonCount`).

Updated `x-openregister-calculations` to add ECTS total:

```jsonc
"x-openregister-calculations": {
  "lessonCount":  { ... },   // unchanged from Phase 1
  "isPublished":  { ... },   // unchanged
  "moduleCount": {
    "type": "integer",
    "materialise": true,
    "expression": { "count": { "schema": "Module", "filter": { "courseId": "@self.id" } } }
  },
  "totalEcts": {
    "type": "integer",
    "materialise": true,
    "expression": { "sum": { "schema": "Module", "field": "ects", "filter": { "courseId": "@self.id" } } }
  },
  "prerequisiteMet": {
    "type": "boolean",
    "materialise": false,
    "description": "Runtime calculation — resolved per learner by the enrolment spec reading Prerequisite objects."
  }
}
```

---

### 1.2 `Module`

```jsonc
"Module": {
  "slug": "module",
  "icon": "BookOpenOutline",
  "version": "0.1.0",
  "title": "Module",
  "description": "An ordered unit of a Course containing one or more Lessons (schema:LearningResource).",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:LearningResource",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["courseId", "name", "order", "tenant_id"],
  "properties": {
    "courseId":           { "type": "string", "format": "uuid" },
    "name":               { "type": "string" },
    "name_nl":            { "type": ["string","null"] },
    "description":        { "type": ["string","null"] },
    "order":              { "type": "integer", "minimum": 1 },
    "ects":               { "type": ["integer","null"], "minimum": 0, "description": "ECTS credits for this module." },
    "learningObjectives": { "type": "array", "items": { "type": "string" } },
    "mandatoryTraining":  { "type": "boolean", "default": false },
    "regulationSlug":     { "type": ["string","null"] },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-relations": {
    "course":   { "register": "scholiq", "schema": "Course",  "cardinality": "many-to-one", "joinOn": "courseId" },
    "lessons":  { "register": "scholiq", "schema": "Lesson",  "cardinality": "one-to-many", "joinOn": "moduleId" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published" },
      "retire":  { "from": "published", "to": "retired" }
    }
  },
  "x-openregister-calculations": {
    "lessonCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "schema": "Lesson", "filter": { "moduleId": "@self.id" } } }
    }
  }
}
```

---

### 1.3 `LearningPath`

```jsonc
"LearningPath": {
  "slug": "learning-path",
  "icon": "MapMarkerPathOutline",
  "version": "0.1.0",
  "title": "LearningPath",
  "description": "An ordered sequence of Courses forming a learning journey (schema:EducationalOccupationalProgram).",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:EducationalOccupationalProgram",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["name", "tenant_id"],
  "properties": {
    "name":        { "type": "string" },
    "name_nl":     { "type": ["string","null"] },
    "description": { "type": ["string","null"] },
    "courseIds":   { "type": "array", "items": { "type": "string", "format": "uuid" }, "description": "Ordered array of Course UUIDs." },
    "totalEcts":   { "type": ["integer","null"], "description": "Declared total ECTS for this path (may differ from sum of course ECTS for partial-credit paths)." },
    "tags":        { "type": "array", "items": { "type": "string" } },
    "tenant_id":   { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published" },
      "archive": { "from": "published", "to": "archived" }
    }
  },
  "x-openregister-calculations": {
    "courseCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "arrayField": "courseIds" } }
    }
  }
}
```

---

### 1.4 `Prerequisite`

```jsonc
"Prerequisite": {
  "slug": "prerequisite",
  "icon": "ArrowRightCircleOutline",
  "version": "0.1.0",
  "title": "Prerequisite",
  "description": "Directed prerequisite edge: a learner must satisfy conditionType on sourceCourseId before enrolling in targetCourseId.",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "searchable": false
  },
  "required": ["sourceCourseId", "targetCourseId", "conditionType", "tenant_id"],
  "properties": {
    "sourceCourseId":  { "type": "string", "format": "uuid", "description": "Course that must be satisfied first." },
    "targetCourseId":  { "type": "string", "format": "uuid", "description": "Course requiring the prerequisite." },
    "conditionType":   { "type": "string", "enum": ["completion","grade","consent"], "description": "completion=passed the source course; grade=scored ≥ minimumGrade; consent=manager/DPO override accepted." },
    "minimumGrade":    { "type": ["number","null"], "minimum": 0, "maximum": 100, "description": "Required when conditionType=grade." },
    "description":     { "type": ["string","null"], "description": "Human-readable explanation shown to learner when prerequisite is unmet." },
    "tenant_id":       { "type": "string", "format": "uuid" }
  },
  "x-openregister-relations": {
    "sourceCourse": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "sourceCourseId" },
    "targetCourse": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "targetCourseId" }
  }
}
```

---

### 1.5 `CatalogChangeRequest`

```jsonc
"CatalogChangeRequest": {
  "slug": "catalog-change-request",
  "icon": "ClipboardCheckOutline",
  "version": "0.1.0",
  "title": "CatalogChangeRequest",
  "description": "Programme-committee approval workflow item for HE curriculum governance (schema:Action).",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Action",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["courseId", "requestedById", "changeDescription", "tenant_id"],
  "properties": {
    "courseId":           { "type": "string", "format": "uuid" },
    "requestedById":      { "type": "string", "description": "NC user id of the instructional designer who raised the request." },
    "changeDescription":  { "type": "string" },
    "ectsChange":         { "type": ["integer","null"], "description": "Proposed new ECTS total (null = no ECTS change)." },
    "reviewedById":       { "type": ["string","null"], "description": "NC user id of the committee reviewer." },
    "reviewNote":         { "type": ["string","null"] },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-relations": {
    "course": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "courseId" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "submit":   { "from": "draft",      "to": "submitted" },
      "approve":  {
        "from": "submitted",
        "to":   "approved",
        "cascades": [
          { "targetSchema": "Course", "targetId": "@self.courseId", "transition": "publish" }
        ]
      },
      "reject":   { "from": "submitted",  "to": "rejected" },
      "withdraw": { "from": ["draft","submitted"], "to": "withdrawn" }
    }
  },
  "x-openregister-notifications": {
    "committeeReviewRequested": {
      "trigger":   { "lifecycleEnter": "submitted" },
      "channel":   "nc-notification",
      "subject":   "scholiq.catalog.review_requested",
      "recipientFromTenantRole": "programme-committee",
      "userPreferenceKey": "notify_catalog_changes"
    },
    "requestApproved": {
      "trigger":   { "lifecycleEnter": "approved" },
      "channel":   "nc-notification",
      "subject":   "scholiq.catalog.request_approved",
      "recipient": "@self.requestedById"
    },
    "requestRejected": {
      "trigger":   { "lifecycleEnter": "rejected" },
      "channel":   "nc-notification",
      "subject":   "scholiq.catalog.request_rejected",
      "recipient": "@self.requestedById"
    }
  }
}
```

The `approve` transition's `cascades` block fires the `publish` transition on the linked `Course` object via OR's lifecycle cascade engine. This is what guarantees the change becomes visible in OOAPI within 5 minutes of approval — the `Course.lifecycle` moves to `published`, which OR's lifecycle engine records as `course.published`, and the `OoapiController` reads published courses from OR in real time.

---

## 2. Seed Data (Dutch, 3-5 objects per new schema)

### 2.1 `Module` seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "Module", "slug": "module-bio-intro" },
    "courseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "name": "Introductie Informatiebeveiliging",
    "name_nl": "Introductie Informatiebeveiliging",
    "order": 1,
    "ects": 1,
    "learningObjectives": ["Begrijp de basisprincipes van informatiebeveiliging", "Ken de relevante wet- en regelgeving"],
    "mandatoryTraining": true,
    "regulationSlug": "BIO",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Module", "slug": "module-bio-wetgeving" },
    "courseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "name": "Wettelijk Kader AVG en BIO",
    "name_nl": "Wettelijk Kader AVG en BIO",
    "order": 2,
    "ects": 2,
    "learningObjectives": ["Pas AVG-principes toe in dagelijkse werkzaamheden", "Verklaar de eisen van de Baseline Informatiebeveiliging Overheid"],
    "mandatoryTraining": true,
    "regulationSlug": "AVG",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Module", "slug": "module-bio-techniek" },
    "courseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "name": "Technische Beveiligingsmaatregelen",
    "name_nl": "Technische Beveiligingsmaatregelen",
    "order": 3,
    "ects": 2,
    "learningObjectives": ["Implementeer technische beveiligingsmaatregelen conform NIS2"],
    "mandatoryTraining": false,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Module", "slug": "module-eur-grondslagen" },
    "courseId": "<<Course.slug=europees-recht-beleid>>",
    "name": "Juridische Grondslagen Europese Unie",
    "name_nl": "Juridische Grondslagen Europese Unie",
    "order": 1,
    "ects": 5,
    "learningObjectives": ["Analyseer de primaire rechtsbronnen van de EU", "Begrijp het institutionele kader van de EU"],
    "mandatoryTraining": false,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Module", "slug": "module-dig-trans-strategie" },
    "courseId": "<<Course.slug=digitale-transformatie-bestuurders>>",
    "name": "Digitale Strategie en Bestuur",
    "name_nl": "Digitale Strategie en Bestuur",
    "order": 1,
    "ects": null,
    "learningObjectives": ["Formuleer een digitale transformatiestrategie", "Betrek stakeholders bij digitale verandertrajecten"],
    "mandatoryTraining": false,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 2.2 `LearningPath` seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "LearningPath", "slug": "leerpad-compliance-officer" },
    "name": "Leerpad Compliance Officer",
    "name_nl": "Leerpad Compliance Officer",
    "description": "Volledig traject van informatiebeveiliging tot privacyrecht voor nieuwe compliance officers bij Nederlandse gemeenten.",
    "courseIds": ["<<Course.slug=cursus-informatiebeveiliging>>", "<<Course.slug=europees-recht-beleid>>"],
    "totalEcts": 10,
    "tags": ["compliance", "overheid", "privacy"],
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "LearningPath", "slug": "leerpad-digitale-vaardigheid" },
    "name": "Leerpad Digitale Vaardigheid Bestuurders",
    "name_nl": "Leerpad Digitale Vaardigheid Bestuurders",
    "description": "Bestemd voor gemeentelijke bestuurders die grip willen krijgen op digitale transformatieprocessen.",
    "courseIds": ["<<Course.slug=digitale-transformatie-bestuurders>>"],
    "totalEcts": null,
    "tags": ["bestuur", "digitalisering"],
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "LearningPath", "slug": "leerpad-blended-learning-docent" },
    "name": "Leerpad Blended Learning voor Docenten",
    "name_nl": "Leerpad Blended Learning voor Docenten",
    "description": "HBO-docenten leren blended-learning cursussen ontwerpen, begeleiden en beoordelen.",
    "courseIds": ["<<Course.slug=onderwijskunde-blended-learning>>"],
    "totalEcts": 15,
    "tags": ["hbo", "didactiek", "blended"],
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 2.3 `Prerequisite` seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "Prerequisite", "slug": "prereq-eur-requires-bio" },
    "sourceCourseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "targetCourseId": "<<Course.slug=europees-recht-beleid>>",
    "conditionType": "completion",
    "minimumGrade": null,
    "description": "Je moet de cursus Informatiebeveiliging afgerond hebben voor je kunt instappen in Europees Recht en Beleid.",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Prerequisite", "slug": "prereq-blended-grade" },
    "sourceCourseId": "<<Course.slug=europees-recht-beleid>>",
    "targetCourseId": "<<Course.slug=onderwijskunde-blended-learning>>",
    "conditionType": "grade",
    "minimumGrade": 55,
    "description": "Voldoende resultaat (≥55) op Europees Recht is vereist voor doorstroom naar Onderwijskunde Blended Learning.",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "Prerequisite", "slug": "prereq-dig-trans-consent" },
    "sourceCourseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "targetCourseId": "<<Course.slug=digitale-transformatie-bestuurders>>",
    "conditionType": "consent",
    "minimumGrade": null,
    "description": "Deelname aan Digitale Transformatie voor Bestuurders vereist schriftelijke toestemming van je leidinggevende.",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### 2.4 `CatalogChangeRequest` seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "CatalogChangeRequest", "slug": "ccr-eur-ects-update" },
    "courseId": "<<Course.slug=europees-recht-beleid>>",
    "requestedById": "j.vandenbroeck",
    "changeDescription": "ECTS-waarde verhogen van 10 naar 12 vanwege uitbreiding van de practicumonderdelen in module Juridische Grondslagen.",
    "ectsChange": 12,
    "reviewedById": null,
    "reviewNote": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CatalogChangeRequest", "slug": "ccr-bio-nieuwe-module" },
    "courseId": "<<Course.slug=cursus-informatiebeveiliging>>",
    "requestedById": "a.vansomeren",
    "changeDescription": "Toevoegen van een nieuwe verplichte module NIS2 Cyberveiligheid (2 ECTS) conform de Cyberbeveiligingswet 2025.",
    "ectsChange": 7,
    "reviewedById": "p.dejong",
    "reviewNote": "Goedgekeurd — module past binnen de accreditatiegrenzen en sluit aan bij de nieuwe wettelijke eisen.",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "CatalogChangeRequest", "slug": "ccr-dig-trans-intrekking" },
    "courseId": "<<Course.slug=digitale-transformatie-bestuurders>>",
    "requestedById": "m.klaasen",
    "changeDescription": "Inhoud module Digitale Strategie en Bestuur actualiseren naar versie 2.0 met nieuwe casussen rondom AI-governance.",
    "ectsChange": null,
    "reviewedById": null,
    "reviewNote": null,
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

---

## 3. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/CoursePublishGuard.php` | Lifecycle guard | Updated from Phase 1: asserts `$course->getModuleCount() > 0` before publish. Single-method. |
| `lib/Controller/OoapiController.php` | External-system contract | OOAPI 5.0 over-the-wire protocol. `GET /ooapi/v5/courses`, `GET /ooapi/v5/courses/{id}`, `GET /ooapi/v5/education-specifications`. Reads Course objects from OR; maps to OOAPI 5.0 JSON schema. Legitimate per ADR-031 — external-system contract. `#[PublicPage]` + `#[NoAdminRequired]`; Bearer-token auth validated in-method. |
| `lib/Service/CourseCloneService.php` | External-system contract — orchestrates multiple OR batch writes | Deep-clones Course + all Modules + Lessons via OR REST batch endpoint. Sets `clonedFromId` + new `academicYear`; zeroes `enrolment` aggregations. Returns UUID of cloned Course. Legitimate per ADR-031 — multi-object orchestration across a single OR transaction boundary. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `ModuleController` / `LessonController` — `CnAppRoot` index + detail pages via manifest cover all CRUD.
- `PrerequisiteService` — prerequisite graph reads happen via OR REST from the Vue enrolment flow; no PHP needed.
- `CatalogChangeRequestService` — lifecycle transitions (submit / approve / reject) are OR REST calls from the detail-page action buttons declared in the manifest.
- `CourseService::publish()` / `archive()` — handled declaratively by `x-openregister-lifecycle`.
- `LearningPathService` — CRUD via manifest + OR REST; no custom business logic needed.

---

## 4. Frontend — `CnAppRoot` consumption

### 4.1 Manifest extension (`src/manifest.json`)

```jsonc
{
  "pages": [
    // Phase 1 pages (unchanged):
    // { "id": "Courses",      "route": "/courses",                        "type": "index",  "config": { "register": "scholiq", "schema": "Course" } },
    // { "id": "CourseDetail", "route": "/courses/:id",                    "type": "detail", "config": { "register": "scholiq", "schema": "Course" } },
    // { "id": "LessonPlayer", "route": "/courses/:courseId/lessons/:id",  "type": "custom", "config": { "component": "LessonPlayer" } },

    // Phase 2 additions:
    { "id": "Modules",                  "route": "/modules",                          "type": "index",  "config": { "register": "scholiq", "schema": "Module" } },
    { "id": "ModuleDetail",             "route": "/modules/:id",                      "type": "detail", "config": { "register": "scholiq", "schema": "Module" } },
    { "id": "LearningPaths",            "route": "/learning-paths",                   "type": "index",  "config": { "register": "scholiq", "schema": "LearningPath" } },
    { "id": "LearningPathDetail",       "route": "/learning-paths/:id",               "type": "detail", "config": { "register": "scholiq", "schema": "LearningPath" } },
    { "id": "CatalogChangeRequests",    "route": "/catalog-changes",                  "type": "index",  "config": { "register": "scholiq", "schema": "CatalogChangeRequest" } },
    { "id": "CatalogChangeReqDetail",   "route": "/catalog-changes/:id",              "type": "detail", "config": { "register": "scholiq", "schema": "CatalogChangeRequest" } },
    { "id": "CourseClone",              "route": "/courses/:id/clone",                "type": "custom", "config": { "component": "CourseCloneModal" } }
  ]
}
```

### 4.2 `CourseCloneModal.vue`

Single custom Vue component. One-step modal with `academicYear` field (text input, format 2026-2027) and a confirmation button. Posts to Scholiq's `POST /api/courses/{id}/clone` endpoint (thin wrapper around `CourseCloneService`). On success, navigates to the newly created draft Course's detail page. No app-local store; no app-local router code.

### 4.3 Prerequisite enforcement in enrolment flow

The enrolment Vue flow (owned by the `enrolment` spec) reads `GET /api/openregister/scholiq/Prerequisite?targetCourseId={id}` from OR directly. When any prerequisite has `conditionType=completion` and the learner has no matching `Enrolment` in `completed` state, the enrol button is rendered disabled and the `description` field of the failing `Prerequisite` object is shown in plain text. No custom Scholiq endpoint or PHP logic — OR REST + Vue rendering.

---

## 5. Audit Events Emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| Module transition `draft → published` | `module.published` | `Module.x-openregister-lifecycle` |
| Module transition `published → retired` | `module.retired` | `Module.x-openregister-lifecycle` |
| Course recalculates `moduleCount` | (internal OR calculation event) | `Course.x-openregister-calculations` |
| CatalogChangeRequest transition `draft → submitted` | `catalogchangerequest.submitted` | `CatalogChangeRequest.x-openregister-lifecycle` |
| CatalogChangeRequest transition `submitted → approved` | `catalogchangerequest.approved` | `CatalogChangeRequest.x-openregister-lifecycle` |
| CatalogChangeRequest approve cascades to Course publish | `course.published` | Lifecycle cascade → `Course.x-openregister-lifecycle` |
| CatalogChangeRequest transition `submitted → rejected` | `catalogchangerequest.rejected` | `CatalogChangeRequest.x-openregister-lifecycle` |
| LearningPath transition `draft → published` | `learningpath.published` | `LearningPath.x-openregister-lifecycle` |
| Course cloned (CourseCloneService OR batch write) | `course.created` (OR default save audit) | OR default save audit |

No app-local `AuditEventTypes::KNOWN`, no `Scholiq\Service\AuditTrail::record()`.

---

## 6. Reuse Analysis (per ADR-001)

| Capability required | OR / nextcloud-vue abstraction used | Custom code? |
|---|---|---|
| Module / LearningPath / Prerequisite / CatalogChangeRequest CRUD | `ObjectService` via `CnAppRoot` manifest pages | None |
| Module + Course pagination + search | `IndexService` + `CnFacetSidebar` via `CnIndexPage` | None |
| CatalogChangeRequest approval workflow | `x-openregister-lifecycle` + cascade | None |
| Prerequisite graph query | OR REST `GET /openregister/scholiq/Prerequisite?targetCourseId=X` | None |
| Prerequisite-unmet UI feedback | Vue rendering in enrolment flow (enrolment spec) | None |
| Course deep-clone (multi-object batch) | `CourseCloneService` — OR REST batch | ADR-031 exception |
| OOAPI 5.0 wire protocol | `OoapiController` | ADR-031 exception |
| Audit trail for all transitions | OR lifecycle engine + ADR-008 | None |
| Notification to programme committee | `x-openregister-notifications` on `CatalogChangeRequest` | None |
| Seed data import | `ConfigurationService::importFromApp()` repair step | None |

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schemas + lifecycle/cascade/relations/calculations/aggregations/notifications + audit trail + batch endpoint | All Course, Module, LearningPath, Prerequisite, CatalogChangeRequest persistence + behaviour |
| `nc:files` (IRootFolder) | Phase 1 `CourseContentService` | Content files; clone shares content-ref path — no duplication |
| OCP\Security\ICrypto | Phase 1 `Cmi5LaunchTokenService` | JWT signing for cmi5 launch (unchanged) |
| OOAPI 5.0 external clients | `OoapiController` Bearer-token endpoint | Student portals + institution website catalog reads |
| @conduction/nextcloud-vue | `CnAppRoot` + `customComponents` | Frontend shell + `CourseCloneModal` registration |
| `enrolment` spec | Prerequisite schema + OR REST | Prerequisite enforcement at enrolment time |
| `certification` spec | OR `enrolment.completed` audit events + Course ECTS | Credential issuance trigger (unchanged) |

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Module state machine (draft → published → retired) | declarative | lifecycle |
| Course → Module → Lesson hierarchy | declarative | relation |
| Course ECTS sum | declarative | calculation (sum aggregation) |
| Course module count | declarative | calculation (count) |
| LearningPath state machine | declarative | lifecycle |
| LearningPath course count | declarative | calculation |
| CatalogChangeRequest approval workflow | declarative | lifecycle + cascade |
| Programme committee notification | declarative | notification |
| Prerequisite graph persistence | declarative (OR REST) | (consumed via ADR-022) |
| Prerequisite-unmet UI feedback | declarative (Vue + OR REST) | (consumed via ADR-024) |
| Audit entries on every transition | declarative (OR) | (consumed via ADR-022) |
| Course deep-clone | imperative (PHP) | "External-system contract / multi-object orchestration" exception |
| OOAPI 5.0 wire protocol | imperative (PHP) | "External-system integrations" exception |
| cmi5 JWT signing, SCORM shim, xAPI LRS | imperative (PHP — Phase 1, unchanged) | "Cryptographic operations / External-system contract" exception |

---

## 9. Phase 2 Scope Exclusions

| Excluded | Deferred to |
|---|---|
| LTI 1.3 launch from LearningPath | Phase 3 (assessment-engine) |
| Common Cartridge import for LearningPaths | Phase 3 |
| Adaptive sequencing (AI-gated module unlock) | Phase 3 (ADR-005 AI Act gate required) |
| OOAPI 5.0 programmes + offerings endpoints | V1 |
| Cross-institution prerequisite recognition (OSO) | V1 |
| Marketplace / paid course storefront | Enterprise (out of scope per context-brief) |
