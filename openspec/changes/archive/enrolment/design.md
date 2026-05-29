# Design — Enrolment

> **Declarative-vs-imperative decision (per [hydra ADR-031 §"How to apply this rule"](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every Enrolment state transition, overdue detection, days-remaining calculation, T-30/T-7/T-1 reminder dispatch, completion notification, manager-alert-on-overdue, and the completion side-effect (xAPI completed statement → Enrolment activates → Credential issues) fits the `x-openregister-lifecycle` / `-calculations` / `-notifications` extensions. **In-fleet references**: `decidesk/lib/Settings/decidesk_register.json` ActionItem schema demonstrates `calculations` (e.g. `isOverdue`, `daysLate`) — the shape we adopt. `lifecycle` + `notifications` are not yet demonstrated anywhere in the fleet; both follow the contracts defined in [openregister#1470](https://codeberg.org/Conduction/openregister/issues/1470) (specifically the `idempotencyKey`, `dateDiff`, and `calculatedChange` notification primitives).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — bulk import (OR's REST batch endpoint), audit trail, notifications, lifecycle events, relations, RBAC. No app-local audit substrate, no app-local notification service, no app-local bulk-enrol service.
>
> **Frontend (per [hydra ADR-024](../../../../hydra/openspec/architecture/adr-024-app-manifest.md))** — `Enrolments` index page already declared in `src/manifest.json` (nextcloud-app change). Bulk-enrol modal is a `customComponents` entry; it talks straight to OR's batch endpoint.

## 1. Schema patch on `lib/Settings/scholiq_register.json`

The change is a single JSON patch adding the `Enrolment` schema. Every behaviour from the v1 design (status transitions, reminders, overdue detection, completion-triggered credential issuance) IS the schema declaration.

```jsonc
"Enrolment": {
  "slug": "enrolment",
  "icon": "AccountSchoolOutline",
  "version": "0.1.0",
  "title": "Enrolment",
  "description": "Learner enrolment in a course (Schema.org EnrollmentRequest)",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:EnrollmentRequest",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["learnerId", "courseId", "tenant_id", "source"],
  "properties": {
    "learnerId":     { "type": "string" },
    "courseId":      { "type": "string", "format": "uuid" },
    "mandatory":     { "type": "boolean", "default": false },
    "dueDate":       { "type": ["string","null"], "format": "date" },
    "source":        { "type": "string", "enum": ["self","manager","hr","bulk","migrated","system"] },
    "managerId":     { "type": ["string","null"] },
    "bulkJobId":     { "type": ["string","null"] },
    "reason":        { "type": ["string","null"] },
    "regulationSlug":{ "type": ["string","null"] },
    "tenant_id":     { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "pending",
    "transitions": {
      "activate":  { "from": "pending",                  "to": "active" },
      "complete":  { "from": "active",                   "to": "completed" },
      "withdraw":  { "from": ["pending","active"],       "to": "withdrawn" },
      "fail":      { "from": "active",                   "to": "failed" }
    }
  },
  "x-openregister-relations": {
    "learner": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "learnerId" },
    "course":  { "register": "scholiq", "schema": "Course",         "cardinality": "many-to-one", "joinOn": "courseId" }
  },
  "x-openregister-calculations": {
    "isOverdue": {
      "type": "boolean",
      "materialise": true,
      "expression": {
        "and": [
          { "eq": [ { "prop": "lifecycle" }, "active" ] },
          { "neq": [ { "prop": "dueDate" }, null ] },
          { "lt":  [ { "prop": "dueDate" }, "@now" ] }
        ]
      }
    },
    "daysRemaining": {
      "type": "integer",
      "materialise": true,
      "expression": {
        "if": [
          { "eq": [ { "prop": "dueDate" }, null ] },
          null,
          { "dateDiff": [ { "prop": "dueDate" }, "@now", "days" ] }
        ]
      }
    },
    "ragStatus": {
      "type": "string",
      "materialise": true,
      "expression": {
        "case": [
          { "when": { "eq":  [ { "prop": "lifecycle" }, "completed" ] }, "then": "completed" },
          { "when": { "prop": "isOverdue" },                              "then": "red" },
          { "when": { "lte": [ { "prop": "daysRemaining" }, 7 ] },        "then": "amber" },
          { "default": "green" }
        ]
      }
    }
  },
  "x-openregister-notifications": {
    "welcomeOnActivate": {
      "trigger":   { "lifecycleEnter": "active" },
      "channel":   "nc-notification",
      "subject":   "scholiq.enrolment.activated",
      "recipient": "@self.learnerId",
      "userPreferenceKey": "notify_assignments"
    },
    "completionOnComplete": {
      "trigger":   { "lifecycleEnter": "completed" },
      "channel":   "nc-notification",
      "subject":   "scholiq.enrolment.completed",
      "recipient": "@self.learnerId",
      "userPreferenceKey": "notify_assignments"
    },
    "reminderT30": {
      "trigger":   { "calculated": "daysRemaining", "eq": 30, "and": { "eq": [ { "prop": "mandatory" }, true ] } },
      "channel":   "nc-notification",
      "subject":   "scholiq.enrolment.due.t30",
      "recipient": "@self.learnerId",
      "userPreferenceKey": "notify_due_dates",
      "idempotencyKey": "reminderT30"
    },
    "reminderT7":  { "trigger": { "calculated": "daysRemaining", "eq": 7,  "and": { "eq": [ { "prop": "mandatory" }, true ] } }, "channel": "nc-notification", "subject": "scholiq.enrolment.due.t7",  "recipient": "@self.learnerId", "userPreferenceKey": "notify_due_dates", "idempotencyKey": "reminderT7" },
    "reminderT1":  { "trigger": { "calculated": "daysRemaining", "eq": 1,  "and": { "eq": [ { "prop": "mandatory" }, true ] } }, "channel": "nc-notification", "subject": "scholiq.enrolment.due.t1",  "recipient": "@self.learnerId", "userPreferenceKey": "notify_due_dates", "idempotencyKey": "reminderT1" },
    "managerAlertOnOverdue": {
      "trigger":   { "calculated": "isOverdue", "eq": true },
      "channel":   "nc-notification",
      "subject":   "scholiq.enrolment.overdue",
      "recipient": "@self.managerId",
      "fallbackRecipientFromTenantRole": "hr"
    }
  }
}
```

**What each block replaces:**

| v1 element | Replaced by |
|---|---|
| `EnrolmentController` CRUD | `CnAppRoot` index/detail pages binding to `register=scholiq schema=Enrolment` (declared in nextcloud-app manifest). |
| `EnrolmentService::transition*` | `x-openregister-lifecycle.transitions`. OR's lifecycle engine emits the audit entries automatically. |
| `EnrolmentCompletionListener` (listens for xAPI completion) | A schema-level rule on `XapiStatement` notifies on a `verb=completed` event → fires the Enrolment.lifecycle `complete` transition. Declared as `x-openregister-notifications` on `XapiStatement` referencing the Enrolment relation. (Future migration option per ADR-031.) For v0.1 the simplest correct path is the thin `lib/Lifecycle/XapiCompletionHandler.php` guard — see PHP §2 below. |
| `BulkEnrolmentService::bulkEnrol` | OR's REST batch-import endpoint. The Vue modal posts to `POST /api/openregister/{registerSlug}/{schemaSlug}/batch` directly. No Scholiq controller. |
| `BulkEnrolmentService::resolveAudience` | NC's native `IGroupManager` REST endpoints + the modal's CSV-parser code (browser-side). |
| `EnrolmentNotificationService` | `x-openregister-notifications` on the Enrolment schema. OR resolves recipients via `@self.learnerId`, checks the per-user `userPreferenceKey`, and dispatches via NC's notification engine. |
| `EnrolmentDueReminderJob` (TimedJob) | `x-openregister-notifications.reminderT30/T7/T1` triggered by the `daysRemaining` calculated field. Per ADR-031 §"Background jobs that walk an object queue and apply a transition", this is the case (1) pattern: "Use a derived field instead of persisting the state". `idempotencyKey` makes OR's notification engine dispatch each reminder once per object per key. |
| `reminder_*_sent` boolean fields | Removed. OR tracks notification dispatch internally via `idempotencyKey`. |

---

## 2. PHP files that ship in this change (ADR-031 exceptions only)

The wedge ships **one** PHP file:

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/XapiCompletionHandler.php` | Lifecycle guard / handler | Listens for OR's `xapi.statement.received` audit event (verb=`completed` or `passed`); when the statement maps to a Lesson with `mandatoryTraining=true` and the lesson is the last of the Course, dispatches the `complete` transition on the relevant Enrolment via OR's REST API. Single-method handler called by OR's lifecycle engine. |

This handler is the **only legitimate PHP** for the Enrolment domain. Per ADR-031 §"Lifecycle guards as called from `x-openregister-lifecycle.requires`", a single-method handler that orchestrates a downstream OR transition is a legitimate seam.

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `EnrolmentController` — `CnAppRoot` index/detail page covers list/show; transitions (`withdraw`, `complete`) are OR REST calls from the manifest's detail-page action buttons.
- `EnrolmentService` (state machine) — `x-openregister-lifecycle` declarative.
- `EnrolmentNotificationService` — `x-openregister-notifications` declarative.
- `EnrolmentDueReminderJob` (`OCP\BackgroundJob\TimedJob`) — `x-openregister-notifications` with calculated-field triggers replaces it. Per ADR-031 §"Background jobs that walk an object queue and apply a transition" case (1).
- `EnrolmentCompletionListener` — replaced by `XapiCompletionHandler` lifecycle handler.
- `BulkEnrolmentService` — OR's REST batch endpoint replaces it; the Vue modal calls OR directly.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension

The `Enrolments` index page is already declared in `src/manifest.json` (nextcloud-app change). This change extends the manifest with:

```jsonc
{
  "pages": [
    /* ... existing pages ... */
    { "id": "EnrolmentDetail", "route": "/enrolments/:id",       "type": "detail", "config": { "register": "scholiq", "schema": "Enrolment" } },
    { "id": "BulkEnrol",       "route": "/enrolments/bulk",      "type": "custom", "config": { "component": "BulkEnrolModal" } }
  ]
}
```

### 3.2 `BulkEnrolModal.vue`

Single custom Vue component registered via `customComponents` on `CnAppRoot`. Multi-step modal:

1. **Audience picker** — group selector (calls NC's native `/ocs/v2.php/cloud/groups`) or CSV upload (parsed browser-side).
2. **Section + config** — Course picker (calls OR REST `GET /api/openregister/scholiq/Course?lifecycle=published`), `mandatory` toggle, `dueDate` picker.
3. **Confirm + submit** — POSTs **directly** to OR's batch endpoint: `POST /api/openregister/scholiq/Enrolment/batch` with body `{objects: [{learnerId, courseId, mandatory, dueDate, source: "bulk", bulkJobId: <uuid>}, ...]}`.

No Scholiq backend involvement; no `/api/enrolments/bulk` controller. The `bulkJobId` UUID is generated browser-side; the modal polls `GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid>` to surface progress.

### 3.3 No app-local store, no app-local Vue Router code

Per ADR-031 + ADR-024: no `useEnrolmentStore`, no `src/views/EnrolmentListView.vue` / `EnrolmentDetailView.vue`. `CnAppRoot`'s built-in renderers cover the wedge.

---

## 4. Audit Events Emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| Enrolment created (any source) | `enrolment.created` | OR default save audit |
| Enrolment transition `pending → active` | `enrolment.activated` | `Enrolment.x-openregister-lifecycle` |
| Enrolment transition `active → completed` | `enrolment.completed` | `Enrolment.x-openregister-lifecycle` |
| Enrolment transition `* → withdrawn` | `enrolment.withdrawn` | `Enrolment.x-openregister-lifecycle` |
| Enrolment transition `active → failed` | `enrolment.failed` | `Enrolment.x-openregister-lifecycle` |
| Calculated `isOverdue` becomes `true` | `enrolment.overdue.detected` | OR's calculation-change events (via the managerAlertOnOverdue notification) |
| Reminder dispatched | `notification.dispatched` (with `idempotencyKey`) | OR notification engine |

No `AuditEventTypes::KNOWN`, no `Scholiq\Service\AuditTrail::record()`.

---

## 5. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | Schema lifecycle / calculations / notifications + REST + audit + relations + batch import | Every Enrolment operation |
| OpenRegister notifications | OR's notification engine + per-user-preference resolver | T-30 / T-7 / T-1 reminders, completion + activation alerts, manager-alert-on-overdue |
| IGroupManager (native NC) | NC OCS API | Audience resolution for bulk-enrol (browser-side) |
| @conduction/nextcloud-vue | `CnAppRoot` + `customComponents` | Frontend shell + `BulkEnrolModal` registration |
| Course-management change | `Course` schema + `XapiStatement` schema | Enrolment relations resolve here |
| Certification change | Listens to OR's `enrolment.completed` audit event | Auto-issues Credential — implemented as schema notification on Course, not as a Scholiq listener service (see certification design) |

---

## 6. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| Enrolment state machine | declarative | lifecycle |
| isOverdue detection | declarative | calculation |
| daysRemaining computation | declarative | calculation |
| RAG status display | declarative | calculation |
| Welcome / completion notification | declarative | notification |
| T-30 / T-7 / T-1 reminders | declarative | notification (calculated-field-triggered) |
| Manager alert on overdue | declarative | notification |
| Bulk-enrolment | declarative (OR REST batch) | (consumed via ADR-022) |
| Audience resolution (group / CSV) | declarative (browser code + NC OCS API) | (consumed via ADR-022) |
| Audit entries on every transition | declarative (OR) | (consumed via ADR-022) |
| Course/Learner relation joins | declarative | relation |
| Enrolment CRUD UI | declarative (CnAppRoot + OR REST) | (consumed via ADR-024) |
| xAPI completion → Enrolment.complete | imperative (PHP, single-method handler) | "Lifecycle guards" exception |

---

## 7. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| Studielink HE enrolment | Phase 2 |
| 30-60-90 onboarding template application | V1 |
| Prerequisite enforcement at enrolment | Phase 2 (requires Course prerequisite graph) |
| Waitlist auto-promotion | V1 |
| Cross-institution credit transfer | Phase 2 (oso-transfer spec) |
| Payment processing | Enterprise |
