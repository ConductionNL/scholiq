# Design — Assessment, ItemBank & Items, Proctoring

> **Declarative-vs-imperative decision (per [hydra ADR-031](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — every ItemBank/Item/Assessment state transition, count, and derived field fits the `x-openregister-*` extensions and lands in `lib/Settings/scholiq_register.json`. The five PHP files that ship (3 lifecycle guards/handlers + 1 service + 1 interface + 1 controller + 1 scoring service) are all ADR-031 legitimate exceptions: lifecycle guard, auto-scoring side-effect handler, external-interface adapter, domain-specific parser, and thin import controller. No state-machine service classes, no notification services, no aggregation loops.
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable), RBAC, archival, relations, append-only schema flag, and the schema-extension engine. No parallel audit substrate, no relation tables, no home-grown append-only store.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — ItemBanks, Items, Assessments, AssessmentResults, ProctoringSessions are declared as manifest pages in `src/manifest.json`. Four genuinely custom views (TakeAssessmentView, ItemAuthorView, ProctoringReviewQueue, ImportQtiModal) are registered via `customComponents` on `CnAppRoot`. No bespoke CRUD controllers for any schema.

## 1. Schemas

### 1.1 ItemBank (slug `item-bank`)

A reusable collection of Items grouped by subject.

```jsonc
"ItemBank": {
  "slug": "item-bank",
  "icon": "DatabaseOutline",
  "version": "0.1.0",
  "title": "ItemBank",
  "description": "A reusable collection of QTI 3.0 assessment items grouped by subject",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Collection",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["name", "tenant_id"],
  "properties": {
    "name":        { "type": "string" },
    "description": { "type": ["string", "null"] },
    "subject":     { "type": ["string", "null"], "description": "e.g. 'wiskunde A', 'Engels B1'" },
    "itemIds":     { "type": "array", "items": { "type": "string", "format": "uuid" } },
    "tenant_id":   { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish":  { "from": "draft",     "to": "published" },
      "archive":  { "from": "published", "to": "archived" },
      "reopen":   { "from": "archived",  "to": "draft" }
    }
  },
  "x-openregister-calculations": {
    "itemCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "prop": "itemIds" } }
    }
  },
  "x-openregister-relations": {
    "items": {
      "register": "scholiq",
      "schema": "Item",
      "cardinality": "one-to-many",
      "joinOn": "itemIds"
    }
  }
}
```

### 1.2 Item (slug `item`)

A single QTI 3.0 assessment item. `QtiImportService` converts QTI 2.x / Common Cartridge to the canonical QTI 3.0 body on import.

```jsonc
"Item": {
  "slug": "item",
  "icon": "HelpCircleOutline",
  "version": "0.1.0",
  "title": "Item",
  "description": "A QTI 3.0 assessment item stored in canonical QTI 3.0 XML/JSON body",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Question",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["title", "interactionType", "maxScore", "tenant_id"],
  "properties": {
    "itemBankId":       { "type": ["string", "null"], "format": "uuid" },
    "title":            { "type": "string" },
    "interactionType":  {
      "type": "string",
      "enum": ["choice", "textEntry", "extendedText", "hotspot", "order", "match", "gapMatch", "inlineChoice"]
    },
    "qtiBody":          { "type": "string", "description": "QTI 3.0 XML or JSON body" },
    "correctResponse":  { "type": ["object", "null"], "description": "Structured correct answer; null for extendedText / manually scored" },
    "maxScore":         { "type": "number", "minimum": 0 },
    "subjectTags":      { "type": "array", "items": { "type": "string" } },
    "difficulty":       { "type": ["number", "null"], "minimum": 0, "maximum": 1, "description": "IRT difficulty 0..1; null if unset" },
    "tenant_id":        { "type": "string", "format": "uuid" }
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
    "needsManualScoring": {
      "type": "boolean",
      "materialise": true,
      "expression": {
        "or": [
          { "eq": [ { "prop": "interactionType" }, "extendedText" ] },
          { "eq": [ { "prop": "correctResponse" }, null ] }
        ]
      }
    }
  },
  "x-openregister-relations": {
    "itemBank": {
      "register": "scholiq",
      "schema": "ItemBank",
      "cardinality": "many-to-one",
      "joinOn": "itemBankId"
    }
  }
}
```

### 1.3 Assessment (slug `assessment`)

A structured test. Proctoring is config on the Assessment, not a separate schema.

```jsonc
"Assessment": {
  "slug": "assessment",
  "icon": "ClipboardTextOutline",
  "version": "0.1.0",
  "title": "Assessment",
  "description": "A structured test: toets, tentamen, examen, quiz, or certification exam",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:EducationalOccupationalProgram",
    "active": true,
    "hardDelete": false,
    "searchable": true
  },
  "required": ["title", "tenant_id"],
  "properties": {
    "title":                    { "type": "string" },
    "description":              { "type": ["string", "null"] },
    "courseId":                 { "type": ["string", "null"], "format": "uuid" },
    "sessionId":                { "type": ["string", "null"], "format": "uuid" },
    "cohortId":                 { "type": ["string", "null"], "format": "uuid" },
    "curriculumPlanComponentId":{ "type": ["string", "null"], "format": "uuid" },
    "itemRefs": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "itemId": { "type": "string", "format": "uuid" },
          "points": { "type": "number", "minimum": 0 }
        },
        "required": ["itemId", "points"]
      }
    },
    "scoringScheme":    { "type": "string", "enum": ["points", "passMark", "irt"], "default": "passMark" },
    "passMark":         { "type": ["number", "null"], "description": "Required when scoringScheme is passMark" },
    "timeLimitMinutes": { "type": ["integer", "null"], "minimum": 1, "description": "Wall-clock limit; null = untimed" },
    "maxAttempts":      { "type": "integer", "minimum": 1, "default": 1 },
    "keepScore":        { "type": "string", "enum": ["best", "last", "average"], "default": "best" },
    "availableFrom":    { "type": ["string", "null"], "format": "date-time" },
    "availableUntil":   { "type": ["string", "null"], "format": "date-time" },
    "proctoring": {
      "type": ["object", "null"],
      "description": "null = unproctored",
      "properties": {
        "provider":        { "type": "string", "description": "Adapter identifier matching ProvidesProctoring implementation" },
        "lockdownBrowser": { "type": "boolean", "default": false },
        "recordWebcam":    { "type": "boolean", "default": false },
        "flagReviewMode":  { "type": "string", "enum": ["manual", "ai-assisted"], "default": "manual" }
      },
      "required": ["provider", "flagReviewMode"]
    },
    "gradeEntryComponentId": { "type": ["string", "null"], "format": "uuid" },
    "tenant_id":             { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft",     "to": "published", "requires": "OCA\\Scholiq\\Lifecycle\\AssessmentPublishGuard" },
      "close":   { "from": "published", "to": "closed" },
      "archive": { "from": "closed",    "to": "archived" },
      "reopen":  { "from": "closed",    "to": "published" }
    }
  },
  "x-openregister-calculations": {
    "itemCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "prop": "itemRefs" } }
    },
    "totalPoints": {
      "type": "number",
      "materialise": true,
      "expression": { "sum": { "prop": "itemRefs", "field": "points" } }
    },
    "isProctored": {
      "type": "boolean",
      "materialise": true,
      "expression": { "neq": [ { "prop": "proctoring" }, null ] }
    },
    "isAvailable": {
      "type": "boolean",
      "materialise": true,
      "expression": {
        "and": [
          { "eq": [ { "prop": "lifecycle" }, "published" ] },
          { "or": [
            { "eq": [ { "prop": "availableFrom" }, null ] },
            { "lte": [ { "prop": "availableFrom" }, { "now": {} } ] }
          ]},
          { "or": [
            { "eq": [ { "prop": "availableUntil" }, null ] },
            { "gte": [ { "prop": "availableUntil" }, { "now": {} } ] }
          ]}
        ]
      }
    }
  },
  "x-openregister-relations": {
    "course":  { "register": "scholiq", "schema": "Course",  "cardinality": "many-to-one", "joinOn": "courseId" },
    "session": { "register": "scholiq", "schema": "Session", "cardinality": "many-to-one", "joinOn": "sessionId" },
    "cohort":  { "register": "scholiq", "schema": "Cohort",  "cardinality": "many-to-one", "joinOn": "cohortId" },
    "items":   { "register": "scholiq", "schema": "Item",    "cardinality": "one-to-many", "joinOn": "itemRefs[].itemId" }
  }
}
```

`AssessmentPublishGuard` blocks publish when `itemRefs` is empty, and also when `proctoring.flagReviewMode === 'ai-assisted'` and no enabled `AiFeature` with slug `assessment-ai-proctor-review` exists (ADR-005 DPO gate).

### 1.4 AssessmentResult (slug `assessment-result`, appendOnly)

One learner attempt. Append-only — no in-place editing; a new object is created per attempt.

```jsonc
"AssessmentResult": {
  "slug": "assessment-result",
  "icon": "CheckboxMarkedOutline",
  "version": "0.1.0",
  "title": "AssessmentResult",
  "description": "A learner's attempt on an Assessment — append-only",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:LearnerReport",
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["assessmentId", "learnerId", "tenant_id"],
  "properties": {
    "assessmentId":       { "type": "string", "format": "uuid" },
    "learnerId":          { "type": "string", "description": "Nextcloud user ID" },
    "attemptNumber":      { "type": "integer", "minimum": 1, "default": 1 },
    "responses": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "itemId":      { "type": "string", "format": "uuid" },
          "response":    {},
          "autoScore":   { "type": ["number", "null"] },
          "manualScore": { "type": ["number", "null"] }
        },
        "required": ["itemId"]
      }
    },
    "startedAt":          { "type": ["string", "null"], "format": "date-time" },
    "submittedAt":        { "type": ["string", "null"], "format": "date-time" },
    "proctoringSessionId":{ "type": ["string", "null"], "format": "uuid" },
    "gradeEntryId":       { "type": ["string", "null"], "format": "uuid" },
    "tenant_id":          { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "in-progress",
    "transitions": {
      "submit": { "from": "in-progress", "to": "submitted", "requires": "OCA\\Scholiq\\Lifecycle\\AssessmentScoringHandler" },
      "grade":  { "from": "submitted",   "to": "graded",    "requires": "OCA\\Scholiq\\Lifecycle\\AssessmentGradeGuard" }
    }
  },
  "x-openregister-relations": {
    "assessment": { "register": "scholiq", "schema": "Assessment", "cardinality": "many-to-one", "joinOn": "assessmentId" }
  }
}
```

`AssessmentScoringHandler` always returns true (never blocks the submit) but writes `autoScore` into each response as a side-effect before the transition commits. `AssessmentGradeGuard` blocks the grade transition until every response for an `extendedText` or null-`correctResponse` item has a non-null `manualScore`.

### 1.5 ProctoringSession (slug `proctoring-session`, appendOnly)

Created by `TakeAssessmentView` when a proctored Assessment is started. Flag review decisions are written by `ProctoringReviewQueue`. Append-only.

```jsonc
"ProctoringSession": {
  "slug": "proctoring-session",
  "icon": "CameraOutline",
  "version": "0.1.0",
  "title": "ProctoringSession",
  "description": "A proctored exam session — append-only; flags never auto-alter results (EU AI Act Art. 14)",
  "type": "object",
  "x-openregister": {
    "schemaType": "schema:Event",
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["assessmentResultId", "learnerId", "provider", "tenant_id"],
  "properties": {
    "assessmentResultId":   { "type": "string", "format": "uuid" },
    "learnerId":            { "type": "string" },
    "provider":             { "type": "string", "description": "Adapter identifier from Assessment.proctoring.provider" },
    "providerSessionId":    { "type": ["string", "null"] },
    "status":               { "type": "string", "enum": ["created", "active", "ended", "error"] },
    "recordedArtefactRefs": { "type": "array", "items": { "type": "string" }, "description": "OR file-attachment references; bytes stay with provider" },
    "flags": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "flagId":         { "type": "string", "format": "uuid" },
          "kind":           { "type": "string" },
          "occurredAt":     { "type": "string", "format": "date-time" },
          "severity":       { "type": "string", "enum": ["low", "medium", "high"] },
          "reviewDecision": { "type": "string", "enum": ["pending", "allowed", "annulled"], "default": "pending" },
          "reviewedBy":     { "type": ["string", "null"] },
          "reviewedAt":     { "type": ["string", "null"], "format": "date-time" }
        },
        "required": ["flagId", "kind", "occurredAt", "severity", "reviewDecision"]
      }
    },
    "tenant_id": { "type": "string", "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "created",
    "transitions": {
      "activate": { "from": "created", "to": "active" },
      "end":      { "from": "active",  "to": "ended" },
      "error":    { "from": "active",  "to": "error" }
    }
  },
  "x-openregister-calculations": {
    "pendingFlagCount": {
      "type": "integer",
      "materialise": true,
      "expression": { "count": { "prop": "flags", "filter": { "reviewDecision": "pending" } } }
    },
    "hasAnnulledFlag": {
      "type": "boolean",
      "materialise": true,
      "expression": { "any": { "prop": "flags", "filter": { "reviewDecision": "annulled" } } }
    }
  },
  "x-openregister-relations": {
    "assessmentResult": { "register": "scholiq", "schema": "AssessmentResult", "cardinality": "many-to-one", "joinOn": "assessmentResultId" }
  }
}
```

**EU AI Act Art. 14 invariant**: A flag — even one bearing `reviewDecision: annulled` — NEVER automatically alters any `AssessmentResult` field. `ProctoringReviewQueue` writes only to `ProctoringSession`. Any consequence (annulling the attempt) is a human decision recorded outside this schema.

---

## 2. PHP — ADR-031 legitimate exceptions

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/AssessmentPublishGuard.php` | Lifecycle guard | Asserts non-empty itemRefs + ADR-005 AI gate for ai-assisted mode. Single-method; called by OR's lifecycle engine. |
| `lib/Lifecycle/AssessmentGradeGuard.php` | Lifecycle guard | Asserts all manual-scoring items have a manualScore before grade transition. |
| `lib/Lifecycle/AssessmentScoringHandler.php` | Lifecycle guard (side-effect) | Always returns true; auto-scores choice/textEntry/inlineChoice/order/match/gapMatch/hotspot responses as a side-effect on submit. extendedText → autoScore=null. |
| `lib/Service/AssessmentScoringService.php` | Calculation engine above schema metadata | Public API for programmatic auto-scoring outside lifecycle engine; delegates to AssessmentScoringHandler + saves via ObjectService. ADR-031 §"calculation engine above schema metadata" exception. |
| `lib/Service/QtiImportService.php` | Domain-specific text processing | Parses QTI 2.x / 3.0 / Common Cartridge ZIP manifests and item XML; creates Item objects. ADR-031 §"domain-specific text processing". |
| `lib/Proctoring/ProvidesProctoring.php` | External-system contract | Interface for proctoring adapters (startSession, endSession, fetchFlags). No concrete provider ships. |
| `lib/Controller/QtiImportController.php` | External-system contract | Thin POST `/api/assessment/qti-import`; accepts upload → QtiImportService. |

**Explicitly NOT in this change** (ADR-031 anti-patterns):
- `AssessmentController` / `ItemController` / `ItemBankController` / `AssessmentResultController` / `ProctoringSessionController` — `CnAppRoot` manifest pages cover CRUD.
- `AssessmentService` (state machine) — `x-openregister-lifecycle` declarative.
- `AssessmentNotificationService` — `x-openregister-notifications` declarative (future: T-30 reminder etc.).
- `ScoringAggregationService` — `x-openregister-aggregations` declarative.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest pages

```jsonc
{
  "pages": [
    { "id": "ItemBanks",              "route": "/item-banks",                   "type": "index",  "config": { "register": "scholiq", "schema": "ItemBank" } },
    { "id": "ItemBankDetail",         "route": "/item-banks/:id",               "type": "detail", "config": { "register": "scholiq", "schema": "ItemBank" } },
    { "id": "Items",                  "route": "/items",                        "type": "index",  "config": { "register": "scholiq", "schema": "Item" } },
    { "id": "ItemDetail",             "route": "/items/:id",                    "type": "detail", "config": { "register": "scholiq", "schema": "Item" } },
    { "id": "Assessments",            "route": "/assessments",                  "type": "index",  "config": { "register": "scholiq", "schema": "Assessment" } },
    { "id": "AssessmentDetail",       "route": "/assessments/:id",              "type": "detail", "config": { "register": "scholiq", "schema": "Assessment" } },
    { "id": "AssessmentResults",      "route": "/assessment-results",           "type": "index",  "config": { "register": "scholiq", "schema": "AssessmentResult", "readOnly": true } },
    { "id": "AssessmentResultDetail", "route": "/assessment-results/:id",       "type": "detail", "config": { "register": "scholiq", "schema": "AssessmentResult", "readOnly": true } },
    { "id": "ProctoringSessions",     "route": "/proctoring-sessions",          "type": "index",  "config": { "register": "scholiq", "schema": "ProctoringSession", "readOnly": true } },
    { "id": "ProctoringSessionDetail","route": "/proctoring-sessions/:id",      "type": "detail", "config": { "register": "scholiq", "schema": "ProctoringSession", "readOnly": true } },
    { "id": "TakeAssessmentView",     "route": "/assessments/:id/take",         "type": "custom", "config": { "component": "TakeAssessmentView" } },
    { "id": "ItemAuthorView",         "route": "/items/:id/author",             "type": "custom", "config": { "component": "ItemAuthorView" } },
    { "id": "ProctoringReviewQueue",  "route": "/proctoring/review-queue",      "type": "custom", "config": { "component": "ProctoringReviewQueue" } },
    { "id": "ImportQtiModal",         "route": "/items/import-qti",             "type": "custom", "config": { "component": "ImportQtiModal" } }
  ],
  "navigation": [
    { "id": "assessments-nav", "label": "Assessments", "route": "/assessments", "icon": "ClipboardTextOutline", "order": 48 }
  ]
}
```

### 3.2 TakeAssessmentView.vue

Timed test-taking surface. Loads the Assessment + its Items; creates an AssessmentResult (in-progress) via OR REST; optionally shows a proctoring notice (placeholder — no concrete adapter ships); presents items one at a time with a countdown timer; collects responses; dispatches the `submit` transition (triggers `AssessmentScoringHandler`). On submit, shows per-item `autoScore` for auto-scored items and "awaiting teacher review" label for extendedText. Options API; direct fetch; no custom Pinia module.

### 3.3 ItemAuthorView.vue

QTI item editor. Full authoring for `choice` (radio option list + mark correct) and `extendedText` (maxScore only; essay prompt). All other interaction types show an import notice ("Use ImportQtiModal to add this item type"). Builds a QTI 3.0 XML body string from the form state. Saves via OR REST POST (create) or PATCH (update). Options API; direct fetch; no custom Pinia module.

### 3.4 ProctoringReviewQueue.vue

Invigilator flag-review queue. Fetches all ProctoringSession objects and filters to those with `pendingFlagCount > 0`. Per flag: "Toestaan" and "Annuleren" buttons record `reviewDecision`, `reviewedBy`, and `reviewedAt` via PATCH to ProctoringSession. NEVER reads or writes AssessmentResult (EU AI Act Art. 14 human oversight). Options API; direct fetch; no custom Pinia module.

### 3.5 ImportQtiModal.vue

ZIP upload surface. Loads available ItemBanks from OR, lets the author select a target bank and choose a `.zip` file, POSTs to `QtiImportController`. Displays created item count and slugs on success. Options API; direct fetch; no custom Pinia module.

---

## 4. Audit Events Emitted (declaratively)

OR emits every audit entry automatically based on schema metadata. No `AuditTrail::record()` calls; no parallel audit substrate.

| Trigger | event_type | Declared in schema |
|---|---|---|
| ItemBank `draft → published` | `itembank.published` | `ItemBank.x-openregister-lifecycle` |
| Item `draft → published` | `item.published` | `Item.x-openregister-lifecycle` |
| Assessment `draft → published` | `assessment.published` | `Assessment.x-openregister-lifecycle` |
| Assessment `published → closed` | `assessment.closed` | `Assessment.x-openregister-lifecycle` |
| AssessmentResult created (in-progress) | `assessmentresult.created` | OR default save audit |
| AssessmentResult `in-progress → submitted` | `assessmentresult.submitted` | `AssessmentResult.x-openregister-lifecycle` |
| AssessmentResult `submitted → graded` | `assessmentresult.graded` | `AssessmentResult.x-openregister-lifecycle` |
| ProctoringSession `created → active` | `proctoringsession.activated` | `ProctoringSession.x-openregister-lifecycle` |
| ProctoringSession `active → ended` | `proctoringsession.ended` | `ProctoringSession.x-openregister-lifecycle` |

---

## 5. Seed Data

Seed objects for `lib/Settings/scholiq_register.json` — 3–5 objects per schema; fictional Dutch values; idempotent on re-import (matched by slug).

### ItemBank seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "item-bank", "slug": "seed-ib-wiskunde-havo" },
    "name": "Wiskunde A — HAVO 4/5",
    "description": "Itembank voor wiskunde A toetsen in HAVO leerjaar 4 en 5",
    "subject": "wiskunde A",
    "itemIds": [],
    "lifecycle": "published",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "item-bank", "slug": "seed-ib-engels-b2" },
    "name": "Engels B2 — Leesvaardigheid",
    "description": "QTI 3.0 items voor leesvaardigheid op B2-niveau",
    "subject": "Engels",
    "itemIds": [],
    "lifecycle": "published",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "item-bank", "slug": "seed-ib-biologie-vo" },
    "name": "Biologie — Cellen en erfelijkheid",
    "description": "Toets items voor het thema cellen en erfelijkheid, vmbo-gt/havo",
    "subject": "biologie",
    "itemIds": [],
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### Item seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "item", "slug": "seed-item-kwadraat" },
    "title": "Kwadratische vergelijking — keuze",
    "interactionType": "choice",
    "qtiBody": "<assessmentItem xmlns='http://www.imsglobal.org/xsd/imsqti_v3p0'><itemBody><choiceInteraction responseIdentifier='RESPONSE' maxChoices='1'><simpleChoice identifier='A'>x = 2</simpleChoice><simpleChoice identifier='B'>x = −2</simpleChoice><simpleChoice identifier='C'>x = ±2</simpleChoice><simpleChoice identifier='D'>geen oplossing</simpleChoice></choiceInteraction></itemBody></assessmentItem>",
    "correctResponse": { "identifier": "C" },
    "maxScore": 2,
    "subjectTags": ["wiskunde A", "algebra"],
    "difficulty": 0.45,
    "lifecycle": "published",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "item", "slug": "seed-item-essay-klimaat" },
    "title": "Essay: effecten van klimaatverandering op biodiversiteit",
    "interactionType": "extendedText",
    "qtiBody": "<assessmentItem xmlns='http://www.imsglobal.org/xsd/imsqti_v3p0'><itemBody><extendedTextInteraction responseIdentifier='RESPONSE' maxStrings='1' expectedLength='400'/></itemBody></assessmentItem>",
    "correctResponse": null,
    "maxScore": 10,
    "subjectTags": ["biologie", "klimaat", "essay"],
    "difficulty": null,
    "lifecycle": "published",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "item", "slug": "seed-item-tekstbegrip-engels" },
    "title": "Reading comprehension — main idea identification",
    "interactionType": "choice",
    "qtiBody": "<assessmentItem xmlns='http://www.imsglobal.org/xsd/imsqti_v3p0'><itemBody><choiceInteraction responseIdentifier='RESPONSE' maxChoices='1'><simpleChoice identifier='A'>The benefits of renewable energy</simpleChoice><simpleChoice identifier='B'>The history of fossil fuels</simpleChoice><simpleChoice identifier='C'>Economic growth strategies</simpleChoice><simpleChoice identifier='D'>Population growth trends</simpleChoice></choiceInteraction></itemBody></assessmentItem>",
    "correctResponse": { "identifier": "A" },
    "maxScore": 1,
    "subjectTags": ["Engels", "leesvaardigheid", "B2"],
    "difficulty": 0.62,
    "lifecycle": "published",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### Assessment seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "assessment", "slug": "seed-ass-wiskunde-havo4-periode1" },
    "title": "Wiskunde A — Periode 1 toets HAVO 4",
    "description": "Eerste periodieke toets wiskunde A voor HAVO klas 4",
    "itemRefs": [],
    "scoringScheme": "passMark",
    "passMark": 5.5,
    "timeLimitMinutes": 90,
    "maxAttempts": 1,
    "keepScore": "last",
    "availableFrom": "2026-09-15T08:00:00Z",
    "availableUntil": "2026-09-15T10:30:00Z",
    "proctoring": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "assessment", "slug": "seed-ass-engels-b2-schriftelijk" },
    "title": "Engels B2 — Schriftelijk examen",
    "description": "Schriftelijk examen leesvaardigheid en schrijfvaardigheid niveau B2",
    "itemRefs": [],
    "scoringScheme": "passMark",
    "passMark": 5.5,
    "timeLimitMinutes": 120,
    "maxAttempts": 1,
    "keepScore": "last",
    "availableFrom": "2026-10-20T09:00:00Z",
    "availableUntil": "2026-10-20T11:00:00Z",
    "proctoring": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "assessment", "slug": "seed-ass-formative-biologie" },
    "title": "Biologie — Formatieve quiz cellen",
    "description": "Korte formatieve quiz over celbiologie; onbeperkte pogingen",
    "itemRefs": [],
    "scoringScheme": "points",
    "passMark": null,
    "timeLimitMinutes": 15,
    "maxAttempts": 3,
    "keepScore": "best",
    "availableFrom": null,
    "availableUntil": null,
    "proctoring": null,
    "lifecycle": "draft",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### AssessmentResult seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "assessment-result", "slug": "seed-ar-wiskunde-leerling-001" },
    "assessmentId": "00000000-0000-0000-0001-000000000001",
    "learnerId": "leerling.jansen",
    "attemptNumber": 1,
    "responses": [
      { "itemId": "00000000-0000-0000-0002-000000000001", "response": "C", "autoScore": 2, "manualScore": null }
    ],
    "startedAt": "2026-09-15T08:05:00Z",
    "submittedAt": "2026-09-15T09:47:00Z",
    "proctoringSessionId": null,
    "gradeEntryId": null,
    "lifecycle": "submitted",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  },
  {
    "@self": { "register": "scholiq", "schema": "assessment-result", "slug": "seed-ar-biologie-essay-leerling-002" },
    "assessmentId": "00000000-0000-0000-0001-000000000002",
    "learnerId": "leerling.devries",
    "attemptNumber": 1,
    "responses": [
      { "itemId": "00000000-0000-0000-0002-000000000002", "response": "Klimaatverandering leidt tot verlies van habitats...", "autoScore": null, "manualScore": null }
    ],
    "startedAt": "2026-10-20T09:02:00Z",
    "submittedAt": "2026-10-20T10:38:00Z",
    "proctoringSessionId": null,
    "gradeEntryId": null,
    "lifecycle": "submitted",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

### ProctoringSession seeds

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "proctoring-session", "slug": "seed-ps-demo-session-001" },
    "assessmentResultId": "00000000-0000-0000-0003-000000000001",
    "learnerId": "leerling.demo",
    "provider": "surf-proctoring",
    "providerSessionId": "SURF-2026-00001",
    "status": "ended",
    "recordedArtefactRefs": [],
    "flags": [
      {
        "flagId": "00000000-0000-0000-0004-000000000001",
        "kind": "look-away",
        "occurredAt": "2026-09-15T08:42:00Z",
        "severity": "low",
        "reviewDecision": "allowed",
        "reviewedBy": "surveillant.bakker",
        "reviewedAt": "2026-09-15T11:00:00Z"
      }
    ],
    "lifecycle": "ended",
    "tenant_id": "00000000-0000-0000-0000-000000000001"
  }
]
```

---

## 6. Reuse Analysis (per ADR-031 deduplication requirement)

| OR abstraction | Used by this change |
|---|---|
| `ObjectService::saveObject()` | QtiImportService, AssessmentScoringService, AssessmentGradeGuard |
| `ObjectService::findAll()` | AssessmentPublishGuard (AiFeature lookup) |
| `x-openregister-lifecycle` | All 5 schemas |
| `x-openregister-calculations` | ItemBank (itemCount), Item (needsManualScoring), Assessment (itemCount/totalPoints/isProctored/isAvailable), ProctoringSession (pendingFlagCount/hasAnnulledFlag) |
| `x-openregister-relations` | All 5 schemas |
| `appendOnly: true` | AssessmentResult, ProctoringSession |
| OR audit-trail abstraction (ADR-008) | All lifecycle transitions emit audit entries automatically |
| `CnAppRoot` + manifest `index`/`detail` pages | ItemBank, Item, Assessment, AssessmentResult, ProctoringSession |
| `customComponents` | TakeAssessmentView, ItemAuthorView, ProctoringReviewQueue, ImportQtiModal |

No existing OR or Scholiq service covers QTI parsing, auto-scoring, or the proctoring adapter interface — these are the legitimate new code surface.

---

## 7. Out of Scope

- Concrete proctoring adapters (only `ProvidesProctoring` interface ships).
- IRT-based scoring (`scoringScheme: irt` is stored; calculation is deferred).
- Final-grade computation — the `grading` spec owns `GradeEntry → FinalGrade`.
- AI-assisted flag review — v1 ships `flagReviewMode: manual` only; `ai-assisted` requires an `AiFeature` DPO acknowledgement (ADR-005) and is a gated future feature.
- Full QTI 3.0 interaction-type editor — only `choice` and `extendedText` have native editors; all other types use the import path.
- Peer review of assessments — follow-up spec.
