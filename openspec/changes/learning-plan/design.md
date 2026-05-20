# Design — Individual Learning Plan

> **Declarative-vs-imperative decision (per [hydra ADR-031](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — plan lifecycle state machines, goal-met aggregation, `isFullySigned` calculation, `nextReviewDue` derivation, `quarterlyReviewReminder` and `signatureRequested` notifications — ALL fit `x-openregister-lifecycle` / `x-openregister-calculations` / `x-openregister-notifications`. The only PHP is the `LearningPlanVersionGuard` lifecycle guard that enforces version-immutability on material change (a cryptographic/domain precondition the schema engine cannot resolve itself).
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — audit trail (immutable, hash-chained, retention-aware), lifecycle engine, calculations engine, notifications engine, relations, RBAC, `appendOnly` object store. No parallel link tables, no custom notification service, no parallel state machine.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — LearningPlan and LearningPlanEvaluation pages are declared in `src/manifest.json`; the signing flow is a single `SignPlanModal.vue` custom component registered via `customComponents` on `CnAppRoot`.

---

## 1. Schema patches on `lib/Settings/scholiq_register.json`

### 1.1 `LearningPlanTemplate`

```jsonc
"LearningPlanTemplate": {
  "slug": "learning-plan-template",
  "icon": "FileDocumentOutline",
  "version": "0.1.0",
  "title": "LearningPlanTemplate",
  "description": "Sector template that pre-structures a LearningPlan's goal domains and sections",
  "type": "object",
  "x-openregister": { "active": true, "searchable": true },
  "required": ["slug", "name", "kind", "sectorCode"],
  "properties": {
    "slug":                   { "type": "string", "pattern": "^[a-z0-9-]+$" },
    "name":                   { "type": "string" },
    "kind":                   { "type": "string", "enum": ["opp","handelingsplan","iep","pdp","idp"] },
    "sectorCode":             { "type": "string", "enum": ["PO","VO","MBO","HO","corporate"] },
    "description":            { "type": ["string","null"] },
    "goalDomains":            { "type": "array", "items": { "type": "string" } },
    "sections":               {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["id","title"],
        "properties": {
          "id":          { "type": "string" },
          "title":       { "type": "string" },
          "description": { "type": ["string","null"] },
          "required":    { "type": "boolean", "default": true }
        }
      }
    },
    "requiredSignerRoles":    { "type": "array", "items": { "type": "string", "enum": ["learner","parent","coordinator"] },
                                "default": ["learner","parent","coordinator"] },
    "requiredAssuranceLevel": { "type": "string", "enum": ["none","low","substantial","high"], "default": "none" },
    "active":                 { "type": "boolean", "default": true }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish":  { "from": "draft",     "to": "published" },
      "archive":  { "from": "published", "to": "archived" }
    }
  }
}
```

### 1.2 `LearningPlan`

```jsonc
"LearningPlan": {
  "slug": "learning-plan",
  "icon": "AccountSchoolOutline",
  "version": "0.1.0",
  "title": "LearningPlan",
  "description": "Individualised learning plan for a learner — OPP, handelingsplan, IEP, PDP, or IDP",
  "type": "object",
  "x-openregister": {
    "active": true,
    "searchable": true,
    "hardDelete": false,
    "appendOnly": false
  },
  "required": ["learnerId","kind","templateId","period","version"],
  "properties": {
    "learnerId":        { "type": "string", "format": "uuid" },
    "cohortId":         { "type": ["string","null"], "format": "uuid" },
    "templateId":       { "type": "string", "format": "uuid" },
    "kind":             { "type": "string", "enum": ["opp","handelingsplan","iep","pdp","idp"] },
    "version":          { "type": "integer", "minimum": 1, "default": 1 },
    "parentPlanId":     { "type": ["string","null"], "format": "uuid",
                          "description": "UUID of the plan this version supersedes" },
    "period": {
      "type": "object",
      "required": ["startDate","endDate","reviewCadence"],
      "properties": {
        "startDate":     { "type": "string", "format": "date" },
        "endDate":       { "type": "string", "format": "date" },
        "reviewCadence": { "type": "string", "enum": ["monthly","quarterly","biannual","annual"] },
        "nextReviewDate":{ "type": ["string","null"], "format": "date" }
      }
    },
    "goals": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["goalId","description","domain","status"],
        "properties": {
          "goalId":       { "type": "string", "format": "uuid" },
          "description":  { "type": "string" },
          "domain":       { "type": "string" },
          "baseline":     { "type": ["string","null"] },
          "target":       { "type": ["string","null"] },
          "targetDate":   { "type": ["string","null"], "format": "date" },
          "status":       { "type": "string", "enum": ["open","active","met","adjusted","dropped"] },
          "closedByEvaluationId": { "type": ["string","null"], "format": "uuid" },
          "evidenceRefs": { "type": "array", "items": { "type": "string", "format": "uuid" } }
        }
      },
      "default": []
    },
    "supportMeasures": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["measureId","description","responsibleId"],
        "properties": {
          "measureId":     { "type": "string", "format": "uuid" },
          "description":   { "type": "string" },
          "responsibleId": { "type": "string", "format": "uuid" },
          "startDate":     { "type": ["string","null"], "format": "date" },
          "endDate":       { "type": ["string","null"], "format": "date" }
        }
      },
      "default": []
    },
    "coordinatorId": { "type": ["string","null"], "format": "uuid" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "submit":          { "from": "draft",             "to": "active",
                           "requires": "OCA\\Scholiq\\Lifecycle\\LearningPlanVersionGuard" },
      "startEvaluation": { "from": "active",            "to": "under-evaluation" },
      "close":           { "from": "under-evaluation",  "to": "closed" },
      "supersede":       { "from": ["active","under-evaluation"], "to": "superseded" },
      "reopen":          { "from": "under-evaluation",  "to": "active" }
    }
  },
  "x-openregister-calculations": {
    "goalsMetCount": {
      "type": "integer",
      "materialise": true,
      "expression": {
        "count": { "filter": { "arrayProp": "goals", "where": { "status": "met" } } }
      }
    },
    "nextReviewDue": {
      "type": "string",
      "materialise": true,
      "expression": { "prop": "period.nextReviewDate" }
    },
    "isFullySigned": {
      "type": "boolean",
      "materialise": false,
      "description": "True when all required co-signers (from template.requiredSignerRoles) have a Signature in status signed for this planId+version"
    }
  },
  "x-openregister-notifications": {
    "quarterlyReviewReminder": {
      "trigger":        { "scheduledOffset": { "prop": "period.nextReviewDate" }, "offsetDays": -7 },
      "channel":        "nc-notification",
      "subject":        "scholiq.learningplan.review.due",
      "idempotencyKey": "scholiq.lp.review.{{ id }}.{{ period.nextReviewDate }}",
      "recipientFromRelation": "coordinatorId"
    },
    "signatureRequested": {
      "trigger":   { "lifecycleTransition": "submit" },
      "channel":   "nc-notification",
      "subject":   "scholiq.learningplan.signature.requested",
      "idempotencyKey": "scholiq.lp.sign.{{ id }}.{{ version }}",
      "recipientFromTemplateField": "requiredSignerRoles"
    }
  },
  "x-openregister-relations": {
    "learner":   { "register": "scholiq", "schema": "LearnerProfile",       "cardinality": "many-to-one", "joinOn": "learnerId" },
    "template":  { "register": "scholiq", "schema": "LearningPlanTemplate", "cardinality": "many-to-one", "joinOn": "templateId" },
    "cohort":    { "register": "scholiq", "schema": "Cohort",               "cardinality": "many-to-one", "joinOn": "cohortId" },
    "coordinator": { "register": "scholiq", "schema": "LearnerProfile",    "cardinality": "many-to-one", "joinOn": "coordinatorId" }
  },
  "x-openregister-widgets": {
    "planStatusSummary": {
      "type": "stats-block",
      "title": "scholiq.widget.learningplan.status",
      "props": { "primary": "goalsMetCount", "secondary": "isFullySigned" }
    }
  }
}
```

**What each block replaces:**

| v1 element | Replaced by |
|---|---|
| PHP `LearningPlanService::transitionTo()` | `x-openregister-lifecycle` — lifecycle engine handles transitions, audit entries, CloudEvents |
| PHP `GoalService::countMet()` | `x-openregister-calculations.goalsMetCount` |
| PHP `PlanService::getNextReviewDue()` | `x-openregister-calculations.nextReviewDue` |
| PHP `SignatureService::isFullySigned()` | `x-openregister-calculations.isFullySigned` |
| PHP `NotificationService::sendReviewReminder()` | `x-openregister-notifications.quarterlyReviewReminder` |
| PHP `NotificationService::sendSignatureRequest()` | `x-openregister-notifications.signatureRequested` |

### 1.3 `LearningPlanEvaluation`

```jsonc
"LearningPlanEvaluation": {
  "slug": "learning-plan-evaluation",
  "icon": "CheckboxMarkedOutline",
  "version": "0.1.0",
  "title": "LearningPlanEvaluation",
  "description": "Dated review of a LearningPlan — records per-goal outcomes and drives the plan into the next cycle",
  "type": "object",
  "x-openregister": { "active": true, "searchable": true, "hardDelete": false },
  "required": ["planId","planVersion","evaluationDate","evaluatorId"],
  "properties": {
    "planId":            { "type": "string", "format": "uuid" },
    "planVersion":       { "type": "integer", "minimum": 1 },
    "evaluationDate":    { "type": "string", "format": "date" },
    "evaluatorId":       { "type": "string", "format": "uuid" },
    "attendees":         { "type": "array", "items": { "type": "string", "format": "uuid" }, "default": [] },
    "overallNarrative":  { "type": ["string","null"] },
    "nextReviewDate":    { "type": ["string","null"], "format": "date" },
    "goalOutcomes": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["goalId","outcome"],
        "properties": {
          "goalId":    { "type": "string", "format": "uuid" },
          "outcome":   { "type": "string", "enum": ["met","adjusted","dropped","continued"] },
          "narrative": { "type": ["string","null"] }
        }
      },
      "default": []
    }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "finalise": { "from": "draft", "to": "finalised" }
    }
  },
  "x-openregister-relations": {
    "plan": { "register": "scholiq", "schema": "LearningPlan", "cardinality": "many-to-one", "joinOn": "planId" },
    "evaluator": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "evaluatorId" }
  }
}
```

### 1.4 `Signature`

```jsonc
"Signature": {
  "slug": "learning-plan-signature",
  "icon": "DrawPenOutline",
  "version": "0.1.0",
  "title": "Signature",
  "description": "Co-sign record on a specific LearningPlan version by learner, parent, or coordinator",
  "type": "object",
  "x-openregister": {
    "active": true,
    "hardDelete": false,
    "appendOnly": true
  },
  "required": ["planId","planVersion","signerRole","signerId"],
  "properties": {
    "planId":           { "type": "string", "format": "uuid" },
    "planVersion":      { "type": "integer", "minimum": 1 },
    "signerRole":       { "type": "string", "enum": ["learner","parent","coordinator"] },
    "signerId":         { "type": "string", "format": "uuid" },
    "signedAt":         { "type": ["string","null"], "format": "date-time" },
    "assuranceLevel":   { "type": "string", "enum": ["none","low","substantial","high"], "default": "none" },
    "externalRefId":    { "type": ["string","null"],
                          "description": "Opaque reference returned by the external signing flow (DigiD transaction ID)" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "pending",
    "transitions": {
      "sign":   { "from": "pending",  "to": "signed",
                  "requires": "OCA\\Scholiq\\Lifecycle\\SignatureAssuranceGuard" },
      "reject": { "from": "pending",  "to": "rejected" }
    }
  },
  "x-openregister-relations": {
    "plan":   { "register": "scholiq", "schema": "LearningPlan", "cardinality": "many-to-one", "joinOn": "planId" },
    "signer": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "signerId" }
  }
}
```

The `appendOnly: true` consumes OR's append-only abstraction (ADR-022 — row: "Content versioning / Append-only object store"). Once a Signature record is `signed`, it cannot be mutated; any dispute resolution creates a new `rejected` record on the new version.

---

## 2. PHP files — ADR-031 legitimate exceptions only

| File | ADR-031 category | Why kept |
|---|---|---|
| `lib/Lifecycle/LearningPlanVersionGuard.php` | Lifecycle guard | Called by OR's lifecycle engine when `LearningPlan.lifecycle: draft → active` fires. Checks that all required co-signers (read from `LearningPlanTemplate.requiredSignerRoles`) have a `Signature` record in `lifecycle=signed` for the current `planId + version`. If not satisfied, returns `Reject('All required signers must sign before the plan can become active')`. Single-method, no state. |
| `lib/Lifecycle/SignatureAssuranceGuard.php` | Lifecycle guard | Called by OR's lifecycle engine when `Signature.lifecycle: pending → signed` fires. Reads `requiredAssuranceLevel` from the linked `LearningPlanTemplate` and compares it to the `assuranceLevel` supplied on the transition payload. Returns `Reject('Signature assurance level is insufficient')` when the level is below the minimum. Single-method, no state. |

**Explicitly NOT in this change** (ADR-031 + ADR-022 anti-patterns):
- `LearningPlanService::transitionTo*` — replaced by `x-openregister-lifecycle`.
- `NotificationService::sendReviewReminder / sendSignatureRequest` — replaced by `x-openregister-notifications`.
- `GoalService::countMet` — replaced by `x-openregister-calculations.goalsMetCount`.
- Any PHP TimedJob for review reminders — the requirement explicitly forbids it; use `x-openregister-notifications` scheduled trigger.
- `SignatureService::isFullySigned` — replaced by `x-openregister-calculations.isFullySigned`.

---

## 3. Frontend — `CnAppRoot` consumption

### 3.1 Manifest extension

```jsonc
{
  "pages": [
    {
      "id": "LearningPlanIndex",
      "route": "/learning-plans",
      "type": "index",
      "title": "scholiq.page.learningplan.index.title",
      "config": { "register": "scholiq", "schema": "LearningPlan" }
    },
    {
      "id": "LearningPlanDetail",
      "route": "/learning-plans/:id",
      "type": "detail",
      "title": "scholiq.page.learningplan.detail.title",
      "config": {
        "register": "scholiq",
        "schema": "LearningPlan",
        "tabs": ["details","evaluations","versionHistory","signatures","auditTrail"]
      }
    },
    {
      "id": "LearningPlanEvaluationIndex",
      "route": "/learning-plans/:planId/evaluations",
      "type": "index",
      "title": "scholiq.page.learningplanevaluation.index.title",
      "config": { "register": "scholiq", "schema": "LearningPlanEvaluation" }
    },
    {
      "id": "LearningPlanEvaluationDetail",
      "route": "/learning-plans/:planId/evaluations/:id",
      "type": "detail",
      "title": "scholiq.page.learningplanevaluation.detail.title",
      "config": { "register": "scholiq", "schema": "LearningPlanEvaluation" }
    },
    {
      "id": "SignPlan",
      "route": "/learning-plans/:id/sign",
      "type": "custom",
      "title": "scholiq.page.sign.title",
      "config": { "component": "SignPlanModal" }
    }
  ]
}
```

### 3.2 `SignPlanModal.vue`

The only custom Vue component in this change. Renders:
1. Summary of the LearningPlan version (goals, supportMeasures, period).
2. Per-signer checklist showing which roles have already signed and which are pending.
3. "Ondertekenen" button that:
   - POSTs `POST /api/openregister/scholiq/Signature` with `{ planId, planVersion, signerRole, signerId, assuranceLevel }` (lifecycle defaults to `pending`).
   - POSTs `PATCH /api/openregister/scholiq/Signature/:id/transition/sign`.
   - `SignatureAssuranceGuard` runs server-side; on 422 renders the assurance-level error inline.
4. If DigiD flow is required (assuranceLevel ≥ substantial): renders an external-link button pointing to the openconnector DigiD redirect — on return, picks up the `externalRefId` from the query param and sets it on the Signature object before dispatching the `sign` transition.

Registered via `customComponents` on `CnAppRoot` — no custom Vue Router entry needed.

### 3.3 No app-local store, no app-local router code

Per ADR-031 + ADR-024: no `useLearningPlanStore.js`, no `LearningPlanListView.vue`, no `LearningPlanDetailView.vue`, no custom router entries. `CnAppRoot`'s built-in index / detail renderers consume the schema-declared manifest pages; `createObjectStore('LearningPlan')` from `@conduction/nextcloud-vue` supplies the Pinia store for free.

---

## 4. Signing Flow (end-to-end, declarative)

```
Coordinator creates plan draft
  → POST /api/openregister/scholiq/LearningPlan
    { kind:'opp', templateId, learnerId, goals:[...], period:{...}, version:1, lifecycle:'draft' }
    → OR pre-populates goals array from LearningPlanTemplate.goalDomains (client-side prefill in SignPlanModal)
    → lifecycle default: draft

Coordinator submits for signing
  → PATCH .../transition/submit
    → LearningPlanVersionGuard fires: checks Signature objects for planId+version
    → No signatures yet → Reject
    → Frontend shows "signing required" state
    → OR dispatches signatureRequested notification to all requiredSignerRoles

Parent/learner/coordinator opens signing link
  → SignPlanModal renders plan summary
  → "Ondertekenen" button clicked
    → POST /api/openregister/scholiq/Signature { planId, planVersion, signerRole:'parent', assuranceLevel:'substantial' }
    → PATCH .../transition/sign
      → SignatureAssuranceGuard: requiredAssuranceLevel='substantial', provided='substantial' → OK
      → OR saves Signature with lifecycle=signed + appendOnly enforced
      → OR emits signature.signed audit entry

All required signers have signed (isFullySigned becomes true)
  → Coordinator retries PATCH .../LearningPlan/:id/transition/submit
    → LearningPlanVersionGuard: all roles signed → OK
    → OR transitions LearningPlan to lifecycle=active
    → Prior version (if any) receives .../transition/supersede automatically
    → signatureRequested idempotency key prevents double-notification

Quarterly review arrives
  → x-openregister-notifications fires quarterlyReviewReminder to coordinator (idempotency-keyed)
  → Coordinator creates LearningPlanEvaluation record
  → Records per-goal outcomes; finalise transition closes met goals (sets goal.status='met' + closedByEvaluationId)
  → Plan transitions: active → under-evaluation → active (or closed)

Auditor review
  → LearningPlan detail page → versionHistory tab: all versions with their Signatures
  → auditTrail tab: OR's built-in audit trail (CnObjectSidebar audit-trail tab)
```

---

## 5. Audit Events Emitted (declaratively)

| Trigger | event_type | Declared in schema |
|---|---|---|
| LearningPlan `draft → active` | `learningplan.activated` | `LearningPlan.x-openregister-lifecycle` |
| LearningPlan `active → under-evaluation` | `learningplan.evaluation.started` | `LearningPlan.x-openregister-lifecycle` |
| LearningPlan `under-evaluation → closed` | `learningplan.closed` | `LearningPlan.x-openregister-lifecycle` |
| LearningPlan `→ superseded` | `learningplan.superseded` | `LearningPlan.x-openregister-lifecycle` |
| Signature `pending → signed` | `learningplan.signature.signed` | `Signature.x-openregister-lifecycle` |
| Signature `pending → rejected` | `learningplan.signature.rejected` | `Signature.x-openregister-lifecycle` |
| Evaluation `draft → finalised` | `learningplan.evaluation.finalised` | `LearningPlanEvaluation.x-openregister-lifecycle` |
| quarterlyReviewReminder dispatch | (notification audit entry) | `LearningPlan.x-openregister-notifications` |
| signatureRequested dispatch | (notification audit entry) | `LearningPlan.x-openregister-notifications` |

---

## 6. Seed Data

Seed data is loaded into `lib/Settings/scholiq_register.json` under `components.objects[]` using the `@self` envelope, idempotent per slug.

### LearningPlanTemplate (2 objects)

```jsonc
{
  "@self": { "register": "scholiq", "schema": "LearningPlanTemplate", "slug": "opp-po-v1" },
  "slug": "opp-po-v1",
  "name": "OPP Primair Onderwijs (versie 1)",
  "kind": "opp",
  "sectorCode": "PO",
  "description": "Standaard OPP-sjabloon voor het primair onderwijs conform de Wet Passend Onderwijs",
  "goalDomains": ["Taal / Lezen", "Rekenen / Wiskunde", "Sociaal-emotioneel", "Motoriek", "Werkhouding"],
  "sections": [
    { "id": "huidig-niveau",   "title": "Huidig ontwikkelingsniveau", "required": true },
    { "id": "doelen",          "title": "Gestelde doelen",            "required": true },
    { "id": "ondersteuning",   "title": "Ondersteuningsmaatregelen",  "required": true },
    { "id": "evaluatie",       "title": "Evaluatieafspraken",         "required": true }
  ],
  "requiredSignerRoles":    ["learner","parent","coordinator"],
  "requiredAssuranceLevel": "none",
  "active": true,
  "lifecycle": "published"
},
{
  "@self": { "register": "scholiq", "schema": "LearningPlanTemplate", "slug": "opp-vo-v1" },
  "slug": "opp-vo-v1",
  "name": "OPP Voortgezet Onderwijs (versie 1)",
  "kind": "opp",
  "sectorCode": "VO",
  "description": "Standaard OPP-sjabloon voor het voortgezet onderwijs",
  "goalDomains": ["Nederlands", "Wiskunde", "Engels", "Sociaal-emotioneel", "Studievaardigheden"],
  "sections": [
    { "id": "beginsituatie",   "title": "Beginsituatie en ondersteuningsbehoefte", "required": true },
    { "id": "doelen",          "title": "Uitstroomdoelen per domein",              "required": true },
    { "id": "maatregelen",     "title": "Ondersteuningsmaatregelen",               "required": true },
    { "id": "ondertekening",   "title": "Ondertekening",                           "required": true }
  ],
  "requiredSignerRoles":    ["learner","parent","coordinator"],
  "requiredAssuranceLevel": "none",
  "active": true,
  "lifecycle": "published"
}
```

### LearningPlan (3 objects)

```jsonc
{
  "@self": { "register": "scholiq", "schema": "LearningPlan", "slug": "lp-emma-de-vries-opp-2026" },
  "learnerId": "00000000-0000-0000-0000-000000000101",
  "cohortId":  "00000000-0000-0000-0000-000000000201",
  "templateId": "00000000-0000-0000-0000-000000000301",
  "kind": "opp",
  "version": 1,
  "parentPlanId": null,
  "period": {
    "startDate":      "2026-08-01",
    "endDate":        "2027-07-31",
    "reviewCadence":  "quarterly",
    "nextReviewDate": "2026-11-01"
  },
  "goals": [
    {
      "goalId":      "00000000-0000-0000-0001-000000000001",
      "description": "Emma leest technisch op AVI-M5 niveau aan het einde van schooljaar 2026-2027",
      "domain":      "Taal / Lezen",
      "baseline":    "AVI-E4 (september 2026)",
      "target":      "AVI-M5",
      "targetDate":  "2027-06-30",
      "status":      "active",
      "evidenceRefs": []
    },
    {
      "goalId":      "00000000-0000-0000-0001-000000000002",
      "description": "Emma werkt zelfstandig 20 minuten aan een taak zonder afleidende gedragingen",
      "domain":      "Werkhouding",
      "baseline":    "5 minuten zelfstandig werken (september 2026)",
      "target":      "20 minuten",
      "targetDate":  "2027-03-31",
      "status":      "active",
      "evidenceRefs": []
    }
  ],
  "supportMeasures": [
    {
      "measureId":     "00000000-0000-0000-0002-000000000001",
      "description":   "Dagelijkse leesondersteuning via dyslexiebegeleider (30 min per dag)",
      "responsibleId": "00000000-0000-0000-0000-000000000401",
      "startDate":     "2026-09-01",
      "endDate":       "2027-07-31"
    }
  ],
  "coordinatorId": "00000000-0000-0000-0000-000000000401",
  "lifecycle": "active"
},
{
  "@self": { "register": "scholiq", "schema": "LearningPlan", "slug": "lp-yusuf-el-amin-opp-2026" },
  "learnerId": "00000000-0000-0000-0000-000000000102",
  "cohortId":  "00000000-0000-0000-0000-000000000202",
  "templateId": "00000000-0000-0000-0000-000000000302",
  "kind": "opp",
  "version": 2,
  "parentPlanId": "00000000-0000-0000-0000-000000000501",
  "period": {
    "startDate":      "2026-08-01",
    "endDate":        "2027-07-31",
    "reviewCadence":  "quarterly",
    "nextReviewDate": "2026-11-15"
  },
  "goals": [
    {
      "goalId":      "00000000-0000-0000-0001-000000000010",
      "description": "Yusuf bereikt eindniveau wiskunde vmbo-t voor het vak algebra",
      "domain":      "Wiskunde",
      "baseline":    "vmbo-b niveau (augustus 2026)",
      "target":      "vmbo-t eindniveau",
      "targetDate":  "2027-06-30",
      "status":      "active",
      "evidenceRefs": []
    }
  ],
  "supportMeasures": [
    {
      "measureId":     "00000000-0000-0000-0002-000000000010",
      "description":   "Tweemaal per week extra wiskundebegeleiding (intern begeleider)",
      "responsibleId": "00000000-0000-0000-0000-000000000402",
      "startDate":     "2026-09-01",
      "endDate":       "2027-01-31"
    }
  ],
  "coordinatorId": "00000000-0000-0000-0000-000000000402",
  "lifecycle": "draft"
},
{
  "@self": { "register": "scholiq", "schema": "LearningPlan", "slug": "lp-sophie-van-den-berg-pdp-2026" },
  "learnerId": "00000000-0000-0000-0000-000000000103",
  "cohortId":  null,
  "templateId": "00000000-0000-0000-0000-000000000303",
  "kind": "pdp",
  "version": 1,
  "parentPlanId": null,
  "period": {
    "startDate":      "2026-09-01",
    "endDate":        "2027-08-31",
    "reviewCadence":  "biannual",
    "nextReviewDate": "2027-02-01"
  },
  "goals": [
    {
      "goalId":      "00000000-0000-0000-0001-000000000020",
      "description": "Sophie rondt de minor Data Science af met een voldoende voor alle vier de cursussen",
      "domain":      "Studievaardigheden",
      "baseline":    "0 van 4 cursussen afgerond (september 2026)",
      "target":      "4 van 4 cursussen met minimaal een 6",
      "targetDate":  "2027-01-31",
      "status":      "open",
      "evidenceRefs": []
    }
  ],
  "supportMeasures": [
    {
      "measureId":     "00000000-0000-0000-0002-000000000020",
      "description":   "Maandelijks voortgangsgesprek met studieloopbaanbegeleider",
      "responsibleId": "00000000-0000-0000-0000-000000000403",
      "startDate":     "2026-09-15",
      "endDate":       "2027-08-31"
    }
  ],
  "coordinatorId": "00000000-0000-0000-0000-000000000403",
  "lifecycle": "active"
}
```

### LearningPlanEvaluation (2 objects)

```jsonc
{
  "@self": { "register": "scholiq", "schema": "LearningPlanEvaluation", "slug": "eval-emma-q1-2026" },
  "planId":           "00000000-0000-0000-0000-000000000601",
  "planVersion":      1,
  "evaluationDate":   "2026-11-04",
  "evaluatorId":      "00000000-0000-0000-0000-000000000401",
  "attendees":        ["00000000-0000-0000-0000-000000000101", "00000000-0000-0000-0000-000000000404"],
  "overallNarrative": "Emma maakt goede voortgang op het leesdoel. Het werkhouddoel vraagt nog extra aandacht. Plan wordt ongewijzigd voortgezet.",
  "nextReviewDate":   "2027-02-04",
  "goalOutcomes": [
    { "goalId": "00000000-0000-0000-0001-000000000001", "outcome": "continued", "narrative": "Emma leest nu op AVI-E4+; op schema." },
    { "goalId": "00000000-0000-0000-0001-000000000002", "outcome": "adjusted",  "narrative": "Doelstelling bijgesteld: 15 minuten per einde Q2." }
  ],
  "lifecycle": "finalised"
},
{
  "@self": { "register": "scholiq", "schema": "LearningPlanEvaluation", "slug": "eval-sophie-mid-2026" },
  "planId":           "00000000-0000-0000-0000-000000000603",
  "planVersion":      1,
  "evaluationDate":   "2027-02-03",
  "evaluatorId":      "00000000-0000-0000-0000-000000000403",
  "attendees":        ["00000000-0000-0000-0000-000000000103"],
  "overallNarrative": "Sophie heeft twee van de vier cursussen succesvol afgerond. Plan wordt voortgezet.",
  "nextReviewDate":   "2027-06-01",
  "goalOutcomes": [
    { "goalId": "00000000-0000-0000-0001-000000000020", "outcome": "continued", "narrative": "2/4 cursussen afgerond met voldoende." }
  ],
  "lifecycle": "finalised"
}
```

### Signature (3 objects)

```jsonc
{
  "@self": { "register": "scholiq", "schema": "Signature", "slug": "sig-emma-coordinator-v1" },
  "planId":         "00000000-0000-0000-0000-000000000601",
  "planVersion":    1,
  "signerRole":     "coordinator",
  "signerId":       "00000000-0000-0000-0000-000000000401",
  "signedAt":       "2026-08-20T09:15:00Z",
  "assuranceLevel": "none",
  "externalRefId":  null,
  "lifecycle":      "signed"
},
{
  "@self": { "register": "scholiq", "schema": "Signature", "slug": "sig-emma-parent-v1" },
  "planId":         "00000000-0000-0000-0000-000000000601",
  "planVersion":    1,
  "signerRole":     "parent",
  "signerId":       "00000000-0000-0000-0000-000000000404",
  "signedAt":       "2026-08-22T14:30:00Z",
  "assuranceLevel": "none",
  "externalRefId":  null,
  "lifecycle":      "signed"
},
{
  "@self": { "register": "scholiq", "schema": "Signature", "slug": "sig-emma-learner-v1" },
  "planId":         "00000000-0000-0000-0000-000000000601",
  "planVersion":    1,
  "signerRole":     "learner",
  "signerId":       "00000000-0000-0000-0000-000000000101",
  "signedAt":       "2026-08-22T15:00:00Z",
  "assuranceLevel": "none",
  "externalRefId":  null,
  "lifecycle":      "signed"
}
```

---

## 7. Reuse Analysis

Per [hydra ADR-001 §"Deduplication check"](../../../../.claude/openspec/architecture/adr-001-data-layer.md):

| Reused capability | Source | How consumed in this change |
|---|---|---|
| Object store + schema validation | `OpenRegister::ObjectService` | All four schemas land in `scholiq_register.json`; no custom Entity/Mapper |
| Lifecycle engine | `OpenRegister x-openregister-lifecycle` | LearningPlan (5 transitions), Signature (2), LearningPlanEvaluation (1), LearningPlanTemplate (2) |
| Calculations engine | `OpenRegister x-openregister-calculations` | `goalsMetCount`, `nextReviewDue`, `isFullySigned` |
| Notifications engine | `OpenRegister x-openregister-notifications` | `quarterlyReviewReminder`, `signatureRequested` |
| Relations engine | `OpenRegister x-openregister-relations` | LearningPlan↔learner/template/cohort, Evaluation↔Plan, Signature↔Plan |
| Append-only object store | `OpenRegister appendOnly: true` | `Signature` schema; prior-version LearningPlan records |
| Audit trail | `OpenRegister` built-in (per ADR-008) | All lifecycle transitions produce audit entries automatically; `CnObjectSidebar.auditTrailTab` surfaces them |
| RBAC | `OpenRegister::AuthorizationService` | Coordinator role required for plan creation/transition; parent role for signature |
| Index / detail pages | `@conduction/nextcloud-vue CnAppRoot` | LearningPlanIndex, LearningPlanDetail, EvaluationIndex, EvaluationDetail all via manifest |
| Object store (Pinia) | `@conduction/nextcloud-vue createObjectStore` | No custom store needed |
| Form dialogs | `@conduction/nextcloud-vue CnFormDialog` | Goal + supportMeasure inline editing |
| Object sidebar | `@conduction/nextcloud-vue CnObjectSidebar` | auditTrail, files, notes tabs free |

No overlap found with existing Scholiq specs (`compliance-audit`, `enrolment`, `grading`, `assessment`). The `Signature` schema is scholiq-specific and does not duplicate any OR core schema.

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| LearningPlan state machine (draft→active→under-evaluation→closed\|superseded) | declarative | lifecycle |
| Signature state machine (pending→signed\|rejected) | declarative | lifecycle |
| LearningPlanEvaluation state machine (draft→finalised) | declarative | lifecycle |
| LearningPlanTemplate state machine (draft→published→archived) | declarative | lifecycle |
| `goalsMetCount` | declarative | calculation |
| `nextReviewDue` | declarative | calculation |
| `isFullySigned` | declarative | calculation |
| `quarterlyReviewReminder` dispatch | declarative | notification |
| `signatureRequested` dispatch | declarative | notification |
| Co-sign completeness check before `draft→active` | imperative (PHP) | "Lifecycle guard" |
| Assurance-level check on `pending→signed` | imperative (PHP) | "Lifecycle guard" |
| Signature append-only immutability | declarative (OR `appendOnly`) | consumed via ADR-022 |
| Audit trail | declarative (OR) | consumed via ADR-022 |
| Version history tab | declarative (manifest `versionHistory` tab config) | consumed via ADR-024 |
| Review-reminder TimedJob | **not implemented** (declared notification replaces it) | n/a |
