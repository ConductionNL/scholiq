# Design — Course Management

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every Course / Lesson state transition + count + status calculation + relation fits the `x-openregister-*` extensions. They land as JSON patches on `lib/Settings/scholiq_register.json` (canonical reference: `decidesk/lib/Settings/decidesk_register.json`), not as PHP service classes. The two services this change ships — `Cmi5ImporterService` and `ScormToXapiTranslator` — are ADR-031 exceptions (NLP / external-system contract).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable), RBAC, archival, relations, and the schema-extension engine. No app-local audit substrate, no app-local relation tables, no app-local archival code.
>
> **Frontend (per [hydra ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md))** — `Courses` index page is declared in `src/manifest.json` (added by the `nextcloud-app` change). `LessonPlayer` is registered via `customComponents` on `CnAppRoot`; there is no app-local Vue Router code.

## 1. Schema patches in `lib/Settings/scholiq_register.json`

The change is a JSON patch on the register file adding three schemas: `Course`, `Lesson`, `XapiStatement`. (The course-section / cohort concept is deferred to Phase 2 per Wedge Scope §5.)

### 1.1 `Course`

```jsonc
"Course": {
  "slug": "course",
  "icon": "FolderOutline",
  "version": "0.1.0",
  "title": "Course",
  "description": "A course or training program (Schema.org Course)",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Course",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["code", "name", "level", "language", "tenant_id"],
  "properties": {
    "code":               { "type": "string", "description": "Course code (e.g. BIO-3H-2026)" },
    "name":               { "type": "string" },
    "name_nl":            { "type": ["string","null"] },
    "description":        { "type": ["string","null"] },
    "level":              { "type": "string", "enum": ["po","vo","mbo","hbo","wo","corporate"] },
    "language":           { "type": "string", "pattern": "^[a-z]{2}$" },
    "tags":               { "type": "array", "items": { "type": "string" } },
    "mandatoryTraining":  { "type": "boolean", "default": false },
    "regulationSlug":     { "type": ["string","null"] },
    "renewalCourseSlug":  { "type": ["string","null"] },
    "certificateTemplate":{ "type": ["string","null"], "description": "nc:files path to PDF template" },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish":   { "from": "draft",     "to": "published", "requires": "OCA\\Scholiq\\Lifecycle\\CoursePublishGuard" },
      "archive":   { "from": "published", "to": "archived" },
      "unarchive": { "from": "archived",  "to": "draft" }
    }
  },
  "x-openregister-calculations": {
    "lessonCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "schema": "Lesson", "filter": { "courseId": "@self.id" } } }
    },
    "isPublished": {
      "type": "boolean",
      "materialise": true,
      "expression": { "eq": [ { "prop": "lifecycle" }, "published" ] }
    }
  },
  "x-openregister-aggregations": {
    "enrolledLearners":  { "metric": "count_distinct", "schema": "Enrolment", "field": "learnerId", "filter": { "courseId": "@self.id" } },
    "completedLearners": { "metric": "count_distinct", "schema": "Enrolment", "field": "learnerId", "filter": { "courseId": "@self.id", "lifecycle": "completed" } }
  }
}
```

The `CoursePublishGuard` is a thin PHP class under `lib/Lifecycle/` that asserts `lessonCount > 0` before the `draft → published` transition fires. Lifecycle guards are an ADR-031 legitimate seam.

The lifecycle declaration replaces what would have been `CourseService::publish()` / `CourseService::archive()`. The aggregations replace what would have been `CourseService::getEnrolmentCount()`. The calculations replace what would have been `CourseService::getLessonCount()`. OR's lifecycle engine emits `course.published` / `course.archived` audit-trail entries on every transition automatically (ADR-008 + ADR-022).

### 1.2 `Lesson`

```jsonc
"Lesson": {
  "slug": "lesson",
  "icon": "FileDocumentOutline",
  "version": "0.1.0",
  "title": "Lesson",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:LearningResource",
    "active": true,
    "searchable": true
  },
  "required": ["courseId", "name", "order", "contentType", "contentRef"],
  "properties": {
    "courseId":          { "type": "string", "format": "uuid" },
    "name":              { "type": "string" },
    "order":             { "type": "integer", "minimum": 1 },
    "contentType":       { "type": "string", "enum": ["text","video","scorm12","scorm2004","cmi5","lti","quiz"] },
    "contentRef":        { "type": "string", "description": "nc:files path, cmi5 launch URL, or LTI link" },
    "durationMinutes":   { "type": ["integer","null"] },
    "learningObjectives":{ "type": "array", "items": { "type": "string" } },
    "mandatoryTraining": { "type": "boolean", "default": false },
    "regulationSlug":    { "type": ["string","null"] },
    "tenant_id":         { "type": "string", "format": "uuid" }
  },
  "x-openregister-relations": {
    "course": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one", "joinOn": "courseId" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published" },
      "retire":  { "from": "published", "to": "retired" }
    }
  }
}
```

`x-openregister-relations` replaces what would have been a manual `LessonMapper::findByCourse()` join. OR's relation engine resolves both directions and respects RBAC.

### 1.3 `XapiStatement` (LRS substrate per ADR-002)

```jsonc
"XapiStatement": {
  "slug": "xapi-statement",
  "icon": "TextBoxOutline",
  "version": "0.1.0",
  "title": "XapiStatement",
  "description": "xAPI 1.0.3 statement (LRS)",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["actor", "verb", "object", "stored", "tenant_id"],
  "properties": {
    "actor":      { "type": "object" },
    "verb":       { "type": "object" },
    "object":     { "type": "object" },
    "result":     { "type": ["object","null"] },
    "context":    { "type": ["object","null"] },
    "timestamp":  { "type": "string", "format": "date-time" },
    "stored":     { "type": "string", "format": "date-time" },
    "authority":  { "type": ["object","null"] },
    "version":    { "type": "string", "const": "1.0.3" },
    "courseId":   { "type": ["string","null"], "format": "uuid" },
    "lessonId":   { "type": ["string","null"], "format": "uuid" },
    "tenant_id":  { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "stored",
    "transitions": {}
  }
}
```

`appendOnly: true` consumes OR's append-only abstraction (ADR-022). The empty `transitions` map signals that every save emits a `xapi.statement.received` audit entry via OR's lifecycle engine — no Scholiq-side `record()` call.

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/CoursePublishGuard.php` | Lifecycle guard | Asserts `Course.lessonCount > 0` before publish. Single-method. Called by OR's lifecycle engine. |
| `lib/Service/Cmi5ImporterService.php` | NLP / domain-specific text processing | Parses `cmi5.xml` / `imsmanifest.xml` from uploaded ZIPs; creates Lesson objects with correct `contentType`. ADR-031 §"What apps SHOULD still write in PHP" explicitly includes "domain-specific text processing". |
| `lib/Service/ScormToXapiTranslator.php` | External-system contract | Translates SCORM 1.2 / 2004 LMS API calls to xAPI verbs. Adapter for an external runtime protocol. |
| `lib/Controller/LrsController.php` | External-system contract | xAPI 1.0.3 over-the-wire endpoint. Writes statements as `XapiStatement` objects; OR emits the audit entry. |
| `lib/Controller/ScormController.php` | External-system contract | SCORM iframe + JSON-RPC bridge. Delegates to `ScormToXapiTranslator`. |
| `lib/Controller/LessonImportController.php` | Wraps Cmi5ImporterService | Single `import($courseId)` action; accepts the upload and dispatches to the importer. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `CourseController` — not needed. `CnAppRoot`'s `Courses` index page (declared in the manifest) reads/writes Course objects via OR's REST API directly. CRUD is OR's responsibility.
- `LessonController` — same reason for plain CRUD. The import + launch actions go through their own thin controllers above.
- `CourseService` / `LessonService` / `Cmi5LaunchService` (the v1 ones — the `Cmi5LaunchTokenService` was moved into the `nextcloud-app` change since it's the launch-flow seam used across multiple specs).
- `CourseContentService::ensureCourseFolder` — folder management is an OR archival-abstraction concern; the upload controller hands the file to OR which stores it in the right location.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension

The `nextcloud-app` change already declared the `Courses` index page bound to `register=scholiq schema=Course`. This change extends `src/manifest.json` with:

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    { "id": "CourseDetail",  "route": "/courses/:id",                       "type": "detail", "config": { "register": "scholiq", "schema": "Course" } },
    { "id": "LessonPlayer",  "route": "/courses/:courseId/lessons/:lessonId","type": "custom", "config": { "component": "LessonPlayer" } }
  ]
}
```

The `index` and `detail` page types come straight from `CnAppRoot`'s built-in renderers (ADR-024 §10 closed enum). They give us the list + detail view + sidebar tabs + audit-trail tab for free; no app-local Vue views needed.

### 3.2 `LessonPlayer.vue`

Single custom Vue component registered via `customComponents` on `CnAppRoot`. Branches on `lesson.contentType`:
- `cmi5`: fetch JWT from `GET /api/lessons/{id}/launch` → AU iframe with postMessage bridge.
- `scorm12` / `scorm2004`: SCORM shim iframe pointing to `/api/scorm/{lessonId}/launch`.
- `video`: `<video>` element with nc:files URL resolved via OR.
- `text`: HTML render.

No app-local store, no app-local fetch glue beyond the launch-token call.

### 3.3 No app-local store, no app-local Vue Router code

Course / Lesson list + detail interactions go through `CnAppRoot`'s built-in OR REST integration. Per ADR-031 + ADR-024 we do not create a `useCourseStore = createObjectStore('/api/courses')` — there is no `/api/courses` controller; the data path is `manifest.pages[Courses].config → register=scholiq schema=Course → OR REST`.

---

## 4. Audit Events Emitted (declaratively)

OR emits every audit entry automatically based on schema metadata. The wedge produces:

| Trigger | event_type | Declared in schema |
|---|---|---|
| Course transition `draft → published` | `course.published` | `Course.x-openregister-lifecycle` |
| Course transition `published → archived` | `course.archived` | `Course.x-openregister-lifecycle` |
| Lesson save | `lesson.created` / `lesson.updated` | OR default save audit |
| Lesson transition `draft → published` | `lesson.published` | `Lesson.x-openregister-lifecycle` |
| `XapiStatement` save | `xapi.statement.received` | `XapiStatement` is append-only — every save audits |

No `AuditEventTypes::KNOWN` to maintain; the event type vocabulary lives with OR's audit-trail abstraction (ADR-022 + ADR-008-rewrite).

---

## 5. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| `CourseSection` / Cohort schema | Phase 2 |
| `prerequisites` array | Phase 2 (requires prerequisite graph) |
| `credits` (ECTS) | Phase 2 (HE context) |
| OOAPI 5.0 catalog publication | Phase 2 |
| LTI 1.3 launch | Phase 3 (assessment-engine) |
| `Assessment` + `Question` schemas | Phase 3 |
| Common Cartridge import | Phase 3 |
| Programme committee approval workflow | Phase 2 (HE) |

---

## 6. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schemas + lifecycle/relations/calculations/aggregations + audit trail | Course / Lesson / XapiStatement persistence + behaviour |
| OpenRegister archival | OR's archival-destruction-workflow abstraction | nc:files content folder lifecycle |
| OCP\Security\ICrypto | `OCP\Security\ICrypto` | cmi5 JWT signing key (via `Cmi5LaunchTokenService` in nextcloud-app change) |
| OpenConnector | Adapter framework | LTI 1.3 (Phase 3) |
| @conduction/nextcloud-vue | `CnAppRoot` + `customComponents` | Frontend shell + `LessonPlayer` registration |

---

## 7. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Course state machine (draft → published → archived) | declarative | lifecycle |
| Course lesson count | declarative | calculation |
| Course enrolment count | declarative | aggregation |
| Lesson → Course join | declarative | relation |
| xAPI statement persistence | declarative | lifecycle + append-only |
| Audit entries on every transition | declarative (OR) | (consumed via ADR-022) |
| Course CRUD | declarative (CnAppRoot + OR REST) | (consumed via ADR-024 + ADR-022) |
| cmi5 JWT signing | imperative (PHP) | "Cryptographic operations" exception |
| SCORM ↔ xAPI translation | imperative (PHP) | "External-system contract" exception |
| cmi5/SCORM ZIP parsing | imperative (PHP) | "NLP / domain-specific text processing" exception |
| xAPI 1.0.3 wire-protocol controller | imperative (PHP) | "External-system integrations" exception |

Every imperative entry has a single-cell justification rooted in ADR-031 §"What apps SHOULD still write in PHP". Anything not on this table is declarative.
