# Design — Enrolment

## 1. OpenRegister Schema — `scholiq-enrolment`

Maps to `Enrolment` entity in ARCHITECTURE.md §3.1 (`Schema.org EnrollmentRequest`):

```json
{
  "title": "scholiq-enrolment",
  "properties": {
    "id":                { "type": "string", "format": "uuid" },
    "learner_id":        { "type": "string" },
    "course_section_id": { "type": "string", "format": "uuid" },
    "status":            { "type": "string", "enum": ["pending","active","completed","withdrawn","failed","overdue"] },
    "enrolled_at":       { "type": "string", "format": "date-time" },
    "completed_at":      { "type": ["string","null"], "format": "date-time" },
    "mandatory":         { "type": "boolean", "default": false },
    "due_date":          { "type": ["string","null"], "format": "date" },
    "source":            { "type": "string", "enum": ["self","manager","hr","bulk","migrated"] },
    "manager_id":        { "type": ["string","null"] },
    "tenant_id":         { "type": "string", "format": "uuid" },
    "reminder_30_sent":  { "type": "boolean", "default": false },
    "reminder_7_sent":   { "type": "boolean", "default": false },
    "reminder_1_sent":   { "type": "boolean", "default": false },
    "bulk_job_id":       { "type": ["string","null"] },
    "reason":            { "type": ["string","null"] },
    "created_at":        { "type": "string", "format": "date-time" },
    "updated_at":        { "type": "string", "format": "date-time" }
  },
  "required": ["learner_id","course_section_id","status","enrolled_at","source","tenant_id"],
  "indexes": [
    ["learner_id","tenant_id","status"],
    ["course_section_id","tenant_id","status"],
    ["mandatory","due_date","status","tenant_id"],
    ["bulk_job_id"]
  ]
}
```

The `reminder_*_sent` booleans ensure idempotency: the daily job only dispatches each reminder once per Enrolment per threshold (REQ-EN-007).

---

## 2. PHP Controllers

### 2.1 `EnrolmentController`

Routes:
```
GET    /api/enrolments                   → list (filters: learner_id, course_section_id, mandatory, status, source)
POST   /api/enrolments                   → create single enrolment
GET    /api/enrolments/{id}              → show
PATCH  /api/enrolments/{id}              → update status/reason (withdraw, complete)
POST   /api/enrolments/bulk              → initiate bulk enrolment (returns 202 + job_id)
GET    /api/enrolments/bulk/{jobId}      → poll bulk-enrolment job status
```

Role guards:
- `learner`: GET own enrolments only (learner_id === authenticated user).
- `admin`, `hr`, `manager`: full CRUD.
- `instructor`: GET for their course sections.

All state changes: `AuditedController` + `AuditTrail::record()`.

---

## 3. PHP Services

### 3.1 `BulkEnrolmentService`

```php
class BulkEnrolmentService
{
    public function resolveAudience(array $audienceDefinition): array // returns NC user id list
    public function bulkEnrol(
        array $ncUserIds,
        string $courseSectionId,
        bool $mandatory,
        ?string $dueDate,
        string $managerId,
        string $bulkJobId
    ): BulkEnrolmentResult
}
```

`resolveAudience()` strategies:
- `nc_group_id`: calls `IGroupManager::get($groupId)->getUsers()` — returns all group members.
- `role`: queries OpenRegister for LearnerProfile objects with matching role + tenant_id.
- `department`: queries LearnerProfile by `department` extension field.
- `csv_user_ids`: parses the uploaded CSV, filters via `IUserManager::userExists()`.

`bulkEnrol()`:
1. Deduplicates against existing active Enrolments (one SELECT per batch, not per user).
2. Inserts new Enrolments in batches of 100 via `ObjectService::saveObjects()` (batch endpoint if OR supports it, else loop).
3. Each Enrolment: `source='bulk'`, `bulk_job_id` set, `mandatory=true`.
4. Returns `BulkEnrolmentResult{enrolled, skipped, failed, errors[]}`.
5. Dispatches `cohort_enrolment_done` notification to initiating user on completion.

### 3.2 `EnrolmentNotificationService`

```php
class EnrolmentNotificationService
{
    public function dispatchEnrolmentNotification(string $notificationSubject, string $learnerId, array $context): void
    public function dispatchDueReminder(Enrolment $enrolment, int $daysRemaining): void
}
```

Wraps `OCP\Notification\IManager`. Uses `SUBJECT_SETTING_MAP` constant binding subjects to user preferences (per FEATURES.md §7.3 pattern):

```php
const SUBJECT_SETTING_MAP = [
    'course_enrolled'     => 'notify_assignments',
    'compliance_due'      => 'notify_compliance_renewal',
    'assignment_overdue'  => 'notify_due_dates',
    'cohort_enrolment_done' => 'notify_assignments',
    'assignment_due_soon' => 'notify_due_dates',
];
```

### 3.3 `EnrolmentCompletionListener`

Listens for `xapi.statement.received` events via `OCP\EventDispatcher\IEventDispatcher`. When the statement verb is `completed` or `passed` and the Lesson has `mandatory_training=true`:

1. Looks up the Enrolment for (learner_id, course_section_id).
2. Checks if this was the final Lesson of the CourseSection.
3. If yes: sets status='completed', completed_at=now, emits `enrolment.completed` audit event.
4. Emits `scholiq.enrolment.completed` NC event for downstream listeners (certification spec).

---

## 4. Background Job

### `EnrolmentDueReminderJob`

Extends `OCP\BackgroundJob\TimedJob`. Interval: 86400 seconds (daily). Runs at midnight UTC.

Query: SELECT Enrolments WHERE mandatory=true AND status IN ('active','pending') AND due_date IS NOT NULL AND due_date > today ORDER BY due_date ASC.

For each result:
- If `due_date - today = 30` AND `reminder_30_sent = false`: dispatch + set `reminder_30_sent = true`.
- If `due_date - today = 7` AND `reminder_7_sent = false`: dispatch + set `reminder_7_sent = true`.
- If `due_date - today = 1` AND `reminder_1_sent = false`: dispatch + set `reminder_1_sent = true`.
- If `due_date < today` AND status = 'active': set status='overdue', emit `enrolment.overdue` audit event, dispatch `assignment_overdue` to learner + manager.

Each reminder_*_sent field update is a PATCH to OpenRegister (not a full object replace). Emit `enrolment.reminder.sent` audit event per dispatch.

---

## 5. Vue Frontend

### 5.1 Route additions

```js
{ path: '/enrolments',          component: () => import('../views/EnrolmentListView.vue') },
{ path: '/enrolments/:id',      component: () => import('../views/EnrolmentDetailView.vue') },
```

Bulk-enrolment is a modal, not a route: `BulkEnrolmentModal.vue`.

### 5.2 Key components

- **`EnrolmentListView.vue`**: `CnDataTable` over `useEnrolmentStore`. Columns: learner name, course, status badge, mandatory icon, due_date (with red highlight if overdue), source, enrolled_at. Filters: status, mandatory, regulation_slug (via course link).
- **`BulkEnrolmentModal.vue`**: multi-step modal (Step 1: audience — group picker / CSV upload; Step 2: section + mandatory + due_date; Step 3: review count + submit). Shows async job progress bar via polling `GET /api/enrolments/bulk/{jobId}`.
- **`EnrolmentDetailView.vue`**: `CnDetailPage` + `CnObjectSidebar`. Tabs: Details, Audit Trail. Withdraw action triggers PATCH with reason capture.

### 5.3 Stores

`useEnrolmentStore = createObjectStore('/api/enrolments')` — standard Options API pattern.

---

## 6. Audit Events Emitted

| Endpoint / Action | event_type | lawful_basis |
|---|---|---|
| POST /api/enrolments | `enrolment.created` | contract (employment training obligation) |
| POST /api/enrolments/bulk (per Enrolment) | `enrolment.created` | contract |
| PATCH /api/enrolments/{id} → status=withdrawn | `enrolment.withdrawn` | contract |
| EnrolmentCompletionListener | `enrolment.completed` | contract |
| EnrolmentDueReminderJob → overdue | `enrolment.overdue` | contract |
| EnrolmentDueReminderJob → reminder sent | `enrolment.reminder.sent` | contract |

Add new event types to `AuditEventTypes::KNOWN`: `enrolment.overdue`, `enrolment.reminder.sent`.

---

## 7. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `ObjectService` | Persist Enrolment objects |
| IGroupManager | `OCP\IGroupManager` | Resolve NC group → user list for bulk-enrol |
| IUserManager | `OCP\IUserManager` | Validate user existence for CSV bulk-enrol |
| Notification | `OCP\Notification\IManager` | T-30/T-7/T-1 reminders, completion notifications |
| EventDispatcher | `OCP\EventDispatcher\IEventDispatcher` | Listen for xapi.statement.received (completion) |
| AuditTrail | `Scholiq\Service\AuditTrail` | All mutation audit events |
| Compliance-audit spec | reads `scholiq-enrolment` | Coverage % computation (denominator) |
| Certification spec | listens for `enrolment.completed` | Triggers credential issuance |

---

## 8. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| Studielink HE enrolment | Phase 2 |
| 30-60-90 onboarding template application | V1 |
| Prerequisite enforcement at enrolment | Phase 2 (requires prerequisite graph in course-management) |
| Waitlist auto-promotion | V1 |
| Cross-institution credit transfer | Phase 2 (oso-transfer spec) |
| Payment processing for paid enrolments | Enterprise |
