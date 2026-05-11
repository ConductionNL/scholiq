# Design — Course Management

## 1. OpenRegister Schemas

### 1.1 `scholiq-course`

Maps to `Course` entity in ARCHITECTURE.md §3.1. Wedge fields only:

```json
{
  "title": "scholiq-course",
  "properties": {
    "id":           { "type": "string", "format": "uuid" },
    "code":         { "type": "string" },
    "name":         { "type": "string" },
    "name_nl":      { "type": ["string","null"] },
    "description":  { "type": ["string","null"] },
    "level":        { "type": "string", "enum": ["po","vo","mbo","hbo","wo","corporate"] },
    "language":     { "type": "string", "pattern": "^[a-z]{2}$" },
    "tenant_id":    { "type": "string", "format": "uuid" },
    "published":    { "type": "boolean" },
    "tags":         { "type": "array", "items": {"type":"string"} },
    "created_at":   { "type": "string", "format": "date-time" },
    "updated_at":   { "type": "string", "format": "date-time" },
    "deleted_at":   { "type": ["string","null"], "format": "date-time" }
  },
  "required": ["code","name","level","language","tenant_id","published"]
}
```

Deferred (Phase 2+): `credits`, `prerequisites`, `learning_outcomes`, `provider` (OOAPI).

### 1.2 `scholiq-lesson`

Maps to `Lesson` / Module entity in ARCHITECTURE.md §3.1.

```json
{
  "title": "scholiq-lesson",
  "properties": {
    "id":                   { "type": "string", "format": "uuid" },
    "course_id":            { "type": "string", "format": "uuid" },
    "name":                 { "type": "string" },
    "order":                { "type": "integer", "minimum": 1 },
    "content_type":         { "type": "string", "enum": ["text","video","scorm12","scorm2004","cmi5"] },
    "content_ref":          { "type": "string" },
    "duration_minutes":     { "type": ["integer","null"] },
    "mandatory_training":   { "type": "boolean", "default": false },
    "regulation_slug":      { "type": ["string","null"] },
    "created_at":           { "type": "string", "format": "date-time" },
    "updated_at":           { "type": "string", "format": "date-time" }
  },
  "required": ["course_id","name","order","content_type","content_ref"]
}
```

### 1.3 `scholiq-course-section`

Maps to `CourseSection` / Cohort in ARCHITECTURE.md §3.1.

Wedge fields: `id`, `course_id`, `name`, `start_date`, `end_date`, `mode` (online/blended/async), `tenant_id`, `nc_group_id` (optional).

Deferred: `nc_talk_room_id`, `nc_calendar_id`, `instructor_ids`, `max_seats`.

### 1.4 `scholiq-xapi-statement`

Append-only LRS statement store per ADR-002 §Implementation notes.

```json
{
  "title": "scholiq-xapi-statement",
  "append_only": true,
  "properties": {
    "id":           { "type": "string", "format": "uuid" },
    "actor":        { "type": "object" },
    "verb":         { "type": "object" },
    "object":       { "type": "object" },
    "result":       { "type": ["object","null"] },
    "context":      { "type": ["object","null"] },
    "timestamp":    { "type": "string", "format": "date-time" },
    "stored":       { "type": "string", "format": "date-time" },
    "authority":    { "type": ["object","null"] },
    "version":      { "type": "string", "const": "1.0.3" },
    "course_id":    { "type": ["string","null"], "format": "uuid" },
    "lesson_id":    { "type": ["string","null"], "format": "uuid" },
    "tenant_id":    { "type": "string", "format": "uuid" }
  },
  "required": ["actor","verb","object","stored","tenant_id"],
  "indexes": [
    ["actor.id","verb.id","stored"],
    ["course_id","tenant_id","stored"],
    ["verb.id","tenant_id","stored"]
  ]
}
```

---

## 2. PHP Controllers and Services

### 2.1 `CourseController`

Routes:
```
GET    /api/courses            → list (filterable: published, level, mandatory_training)
POST   /api/courses            → create
GET    /api/courses/{id}       → show
PATCH  /api/courses/{id}       → update
DELETE /api/courses/{id}       → archive (soft delete via deleted_at)
```

All state changes extend `AuditedController` and call `AuditTrail::record()` with the appropriate event_type from `AuditEventTypes`.

Role guards: create/update/delete restricted to roles `admin`, `hr`, `instructor`. Learners: GET only (published courses for their tenant).

### 2.2 `LessonController`

Routes:
```
GET    /api/courses/{courseId}/lessons             → list (sorted by order)
POST   /api/courses/{courseId}/lessons             → create (metadata only)
GET    /api/courses/{courseId}/lessons/{id}        → show
PATCH  /api/courses/{courseId}/lessons/{id}        → update
DELETE /api/courses/{courseId}/lessons/{id}        → delete
POST   /api/courses/{courseId}/lessons/import      → import cmi5/SCORM package
GET    /api/lessons/{id}/launch                    → get cmi5 launch token
```

### 2.3 `LrsController`

Implements xAPI 1.0.3 endpoints per ADR-002 §Implementation notes:
```
POST   /api/lrs/statements                          → post statement(s)
GET    /api/lrs/statements                          → query statements
GET    /api/lrs/activities/state                    → activity state
GET    /api/lrs/activities/profile                  → activity profile
GET    /api/lrs/agents/profile                      → agent profile
```

Authentication: NC session token OR cmi5 JWT from `Cmi5LaunchService`. Every POST writes an `xapi.statement.received` audit event.

### 2.4 `ScormController`

Serves SCORM 1.2/2004 runtime API bridge via a dedicated iframe-accessible endpoint:
```
GET    /api/scorm/{lessonId}/launch                 → serve SCORM player page
POST   /api/scorm/{lessonId}/api                    → SCORM LMS API calls (JSON RPC)
```

`ScormToXapiTranslator` maps each SCORM call to xAPI per ADR-002 §Decision (3) table. SCORM packages served from nc:files.

### 2.5 `Cmi5LaunchService`

```php
class Cmi5LaunchService
{
    public function mintLaunchToken(
        string $learnerId,
        string $lessonId,
        string $registrationId
    ): string // RS256 JWT, exp = now+8h
}
```

Private key: stored in `OCP\Security\ICrypto` keyring under key `scholiq.cmi5.launch.private`.

### 2.6 `CourseContentService`

Handles package import logic:
1. Detect manifest type from .zip content (cmi5.xml vs imsmanifest.xml).
2. Unpack to `nc:files` at `/Scholiq/<tenant>/<course_id>/<type>/<lesson_id>/`.
3. Create Lesson record with correct content_type and content_ref.
4. Return the created Lesson.

---

## 3. Vue Frontend

### 3.1 Route additions (`src/router/index.js`)

```js
{ path: '/courses',                    component: () => import('../views/CourseListView.vue')    },
{ path: '/courses/new',                component: () => import('../views/CourseFormView.vue')    },
{ path: '/courses/:id',                component: () => import('../views/CourseDetailView.vue')  },
{ path: '/courses/:id/edit',           component: () => import('../views/CourseFormView.vue')    },
{ path: '/courses/:id/lessons/:lid',   component: () => import('../views/LessonPlayer.vue')      },
```

### 3.2 Key components

- **`CourseListView.vue`**: `CnDataTable` or `CnIndexPage` over a Pinia `createObjectStore` store querying `GET /api/courses`. Columns: code, name, level, published badge, lesson count.
- **`CourseDetailView.vue`**: `CnDetailPage` with a `CnObjectSidebar`. Tabs: Details, Lessons, Enrolments (count), Audit Trail (`CnAuditTrailTab`).
- **`CourseFormView.vue`**: form bound to `POST` / `PATCH /api/courses`. Fields per schema.
- **`LessonPlayer.vue`**: renders the appropriate player based on lesson content_type:
  - `cmi5`: launch token → AU iframe with postMessage bridge
  - `scorm12` / `scorm2004`: SCORM shim iframe pointing to ScormController
  - `video`: `<video>` element with nc:files direct URL
  - `text`: HTML content render

### 3.3 Stores

`useCourseStore = createObjectStore('/api/courses')` — follows the Options API + `createObjectStore` pattern from `feedback_store-pattern.md`.

---

## 4. nc:files Integration

At course creation, `CourseContentService::ensureCourseFolder()` calls:

```php
$userFolder = $this->rootFolder->getUserFolder($systemUser);
$path = "/Scholiq/{$tenantId}/{$courseId}";
if (!$userFolder->nodeExists($path)) {
    $userFolder->newFolder($path);
}
```

System user: the `scholiq` service account or the creating user. Folder is created idempotently (nodeExists check).

---

## 5. Wedge Scope Exclusions

The following ARCHITECTURE.md entities and standards are explicitly NOT implemented in Phase 1:

| Excluded | Deferred to |
|---|---|
| `prerequisites` array on Course | Phase 2 (complex prerequisite graph) |
| `credits` (ECTS) on Course | Phase 2 (HE context) |
| OOAPI 5.0 catalog endpoints | Phase 2 |
| LTI 1.3 launch | Phase 3 (assessment-engine) |
| `Assessment` and `Question` entities | Phase 3 (assessment-engine spec) |
| Common Cartridge import | Phase 3 |
| Programme committee approval workflow | Phase 2 (HE-specific) |

---

## 6. Audit Events Emitted

| Endpoint | event_type | before/after |
|---|---|---|
| POST /api/courses | `course.published` | null / Course |
| PATCH /api/courses/{id} | `course.published` | Course / Course |
| DELETE /api/courses/{id} | `course.archived` | Course / null |
| POST /api/courses/{id}/lessons | `lesson.created` (add to AuditEventTypes) | null / Lesson |
| PATCH /api/courses/{id}/lessons/{id} | `lesson.updated` | Lesson / Lesson |
| DELETE /api/courses/{id}/lessons/{id} | `lesson.deleted` | Lesson / null |
| POST /api/lrs/statements | `xapi.statement.received` | null / Statement |

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `ObjectService::saveObject()` | Persist Course, Lesson, xApiStatement |
| nc:files | `OCP\Files\IRootFolder` | Course content folder + package storage |
| nc:crypto | `OCP\Security\ICrypto` | cmi5 JWT signing key |
| AuditTrail (nextcloud-app) | `Scholiq\Service\AuditTrail` | All mutation audit events |
| Enrolment spec | `scholiq-enrolment` schema | Downstream; reads course_id |
| Compliance-audit spec | `GET /api/lrs/statements` | Reads xAPI completions for coverage % |
