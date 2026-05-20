---
slug: learning-plan
title: Individual Learning Plan
status: planned
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-20
updated: 2026-05-20
profiles: [opp-passend-onderwijs, handelingsplan, iep, pdp-he, idp-corporate]
---

# Individual Learning Plan — Formal Requirements

## Overview

The Individual Learning Plan provides a structured, versioned document for learners with extra support needs — OPP (Wet Passend Onderwijs), handelingsplan, IEP, PDP, or IDP. Plans are created from sector templates, co-signed by all required parties, reviewed on a declared cadence, and evaluated per-goal. Every version and every signature is immutable; the full history is available to auditors. All state machines, calculations, notifications, and relations are declared in `lib/Settings/scholiq_register.json` via `x-openregister-*` extensions; no PHP service classes implement lifecycle or notification logic.

---

## Requirements

### REQ-LP-001 — Template-based plan creation

The system MUST support creating a `LearningPlan` from a `LearningPlanTemplate`, pre-populating the plan's goal domains and sections from the template.

#### Scenario LP-001-A: Coordinator creates OPP from sector template

```
GIVEN a LearningPlanTemplate with slug='opp-po-v1' and kind='opp' exists in lifecycle=published
  AND a coordinator is authenticated with role 'learning-support-coordinator'
WHEN the coordinator POSTs /api/openregister/scholiq/LearningPlan with
     { kind:'opp', templateId:<opp-po-v1 uuid>, learnerId:<uuid>, period:{...}, version:1 }
THEN the system MUST create a LearningPlan in OpenRegister with lifecycle='draft'
  AND the plan MUST be associated with the template via x-openregister-relations.template
  AND OR MUST return HTTP 201 with the created plan UUID
  AND an audit entry MUST be emitted by OR's lifecycle engine (lifecycle default 'draft')
```

#### Scenario LP-001-B: Template pre-structures goal domains (client-side prefill)

```
GIVEN a LearningPlanTemplate with goalDomains=['Taal / Lezen','Rekenen / Wiskunde','Sociaal-emotioneel']
WHEN a coordinator opens the new-plan form in the Scholiq frontend
THEN the SignPlanModal (or CnFormDialog) MUST pre-populate the goals[] array
     with one skeleton entry per goalDomain from the template
  AND each skeleton entry MUST have status='open' and an auto-generated goalId (UUID)
  AND the coordinator MUST be able to edit, remove, or add goals before saving
```

#### Scenario LP-001-C: Plan creation rejected when template is not published

```
GIVEN a LearningPlanTemplate exists in lifecycle='draft' (not yet published)
WHEN a coordinator attempts to create a LearningPlan referencing that templateId
THEN the system MUST reject the request with HTTP 422
  AND the response MUST contain an error referencing the unpublished template state
  AND no LearningPlan object MUST be created
```

---

### REQ-LP-002 — Co-signing and assurance level

The system MUST support co-signing of a specific LearningPlan version by all required parties (learner, parent/guardian, coordinator). A plan version MUST NOT transition from `draft` to `active` until all required signers have signed. Signing assurance level MUST be recorded on each Signature; a signature with an insufficient assurance level MUST NOT satisfy the co-sign requirement.

#### Scenario LP-002-A: All required signers sign — plan becomes active

```
GIVEN a LearningPlan with id=<planId> and version=1 is in lifecycle='draft'
  AND the associated LearningPlanTemplate has requiredSignerRoles=['learner','parent','coordinator']
  AND all three roles have created a Signature record in lifecycle='signed' for planId+version=1
WHEN the coordinator POSTs PATCH /api/openregister/scholiq/LearningPlan/<planId>/transition/submit
THEN LearningPlanVersionGuard MUST confirm all required Signature records exist with lifecycle='signed'
  AND OR MUST transition the plan to lifecycle='active'
  AND OR MUST emit a 'learningplan.activated' audit entry with actor, timestamp, and planId
  AND the plan detail page MUST show lifecycle='active'
```

#### Scenario LP-002-B: Submit rejected when signers are missing

```
GIVEN a LearningPlan with id=<planId> and version=1 is in lifecycle='draft'
  AND only the coordinator has signed (learner and parent Signatures are absent or pending)
WHEN the coordinator POSTs PATCH .../transition/submit
THEN LearningPlanVersionGuard MUST return Reject('All required signers must sign before the plan can become active')
  AND OR MUST return HTTP 422 with the guard rejection message
  AND the plan MUST remain in lifecycle='draft'
```

#### Scenario LP-002-C: Parent signs with DigiD — assurance level recorded

```
GIVEN a LearningPlan requiring a Signature from 'parent'
  AND the LearningPlanTemplate has requiredAssuranceLevel='substantial'
WHEN a parent POSTs POST /api/openregister/scholiq/Signature
     { planId, planVersion:1, signerRole:'parent', signerId, assuranceLevel:'substantial', externalRefId:'<digid-txn>' }
  AND POSTs PATCH .../transition/sign
THEN SignatureAssuranceGuard MUST compare assuranceLevel='substantial' >= requiredAssuranceLevel='substantial' → OK
  AND OR MUST transition the Signature to lifecycle='signed'
  AND the Signature MUST persist with appendOnly=true (immutable once signed)
  AND OR MUST emit a 'learningplan.signature.signed' audit entry with signerRole, assuranceLevel, externalRefId
```

#### Scenario LP-002-D: Signature with insufficient assurance level is rejected

```
GIVEN a LearningPlanTemplate has requiredAssuranceLevel='substantial'
WHEN a parent attempts to sign with assuranceLevel='low'
THEN SignatureAssuranceGuard MUST return Reject('Signature assurance level is insufficient')
  AND OR MUST return HTTP 422
  AND the Signature MUST remain in lifecycle='pending'
  AND no 'learningplan.signature.signed' audit entry MUST be emitted
```

#### Scenario LP-002-E: Signed Signature is immutable

```
GIVEN a Signature record with lifecycle='signed' exists for planId+planVersion
WHEN any code path attempts to UPDATE or DELETE the Signature object
THEN OpenRegister MUST reject the operation (appendOnly: true on Signature schema — per ADR-022)
  AND OR MUST return HTTP 405 or schema-violation error
  AND the Signature record MUST remain unchanged
```

---

### REQ-LP-003 — Review cycle and quarterly reminder

The system MUST fire a `quarterlyReviewReminder` notification to the coordinator when a plan's `period.nextReviewDate` is approaching. The notification MUST be declared as a scheduled notification on the `LearningPlan` schema (NOT a PHP TimedJob). Idempotency MUST be enforced so a re-trigger does not double-fire.

#### Scenario LP-003-A: Review reminder fires 7 days before nextReviewDate

```
GIVEN a LearningPlan in lifecycle='active' with period.nextReviewDate='2026-11-01'
  AND the current date is 2026-10-25 (7 days before nextReviewDate)
WHEN OR's notification engine evaluates the scheduledOffset trigger
THEN OR MUST dispatch a 'scholiq.learningplan.review.due' Nextcloud notification
     to the user with id matching LearningPlan.coordinatorId
  AND the notification idempotencyKey MUST be 'scholiq.lp.review.<planId>.2026-11-01'
  AND a second evaluation on the same day MUST NOT produce a duplicate notification
     (idempotency enforced by OR's notification engine)
```

#### Scenario LP-003-B: No PHP TimedJob for review reminders

```
GIVEN the learning-plan change is fully installed
WHEN the codebase is scanned for PHP classes implementing OCP\BackgroundJob\TimedJob
     with method names matching '*review*' or '*reminder*'
THEN NO such class MUST exist (the notification is declared on the schema, not in a job)
```

---

### REQ-LP-004 — Evaluation recording and goal outcome

The system MUST support creating a `LearningPlanEvaluation` that records per-goal outcomes. When an evaluation marks a goal as "met", the corresponding goal entry on the LearningPlan MUST be updated to `status: 'met'` with `closedByEvaluationId` set to the evaluation UUID.

#### Scenario LP-004-A: Evaluation records goal as met

```
GIVEN a LearningPlan in lifecycle='under-evaluation' with a goal goalId=<g1> status='active'
  AND a coordinator creates a LearningPlanEvaluation with
      goalOutcomes=[{ goalId:<g1>, outcome:'met', narrative:'Doel behaald' }]
  AND the evaluation transitions to lifecycle='finalised'
WHEN the plan is retrieved via GET /api/openregister/scholiq/LearningPlan/<planId>
THEN the goal entry with goalId=<g1> MUST show status='met'
  AND goal.closedByEvaluationId MUST equal the evaluation UUID
  AND the plan detail page MUST display the met goal with the linked evaluation
```

#### Scenario LP-004-B: Evaluation records goal as adjusted

```
GIVEN a goal goalId=<g2> with status='active' and targetDate='2027-06-30'
WHEN an evaluation records goalOutcomes=[{ goalId:<g2>, outcome:'adjusted', narrative:'Doel bijgesteld' }]
  AND the coordinator updates goal.target and goal.targetDate on the plan
THEN the goal MUST show status='adjusted'
  AND a new LearningPlan version MUST NOT be created just for the adjusted goal
     (a new version is only required when a material change triggers re-signing — see REQ-LP-005)
```

#### Scenario LP-004-C: Evaluation transitions plan back to active

```
GIVEN a LearningPlan in lifecycle='under-evaluation'
  AND a LearningPlanEvaluation has been finalised for the current review period
WHEN the coordinator POSTs PATCH .../LearningPlan/<planId>/transition/reopen
THEN OR MUST transition the plan from 'under-evaluation' to 'active'
  AND the plan.period.nextReviewDate MUST be updated to the evaluation's nextReviewDate
  AND OR MUST emit a 'learningplan.evaluation.started' audit entry when entering under-evaluation
     and the lifecycle engine records every transition in OR's immutable audit trail
```

---

### REQ-LP-005 — Version chain and immutability

The system MUST support an append-on-version pattern: a material change to an active plan creates a new version record. The prior version and its Signature records MUST be preserved and immutable. The new version MUST require re-signing by all required parties before becoming active.

#### Scenario LP-005-A: New version created on material change

```
GIVEN a LearningPlan in lifecycle='active' with version=1
WHEN a coordinator creates a new LearningPlan with parentPlanId=<planId v1> and version=2
  AND POSTs PATCH .../LearningPlan/<planId v1>/transition/supersede
THEN the v1 plan MUST transition to lifecycle='superseded' (immutable; appendOnly prior-version record)
  AND the v2 plan MUST start in lifecycle='draft' requiring re-signing
  AND the v1 Signature records MUST remain accessible and unmodified
  AND the LearningPlan detail page versionHistory tab MUST show both v1 (superseded) and v2 (draft)
```

#### Scenario LP-005-B: Auditor views full version + signature history

```
GIVEN a LearningPlan has three versions (v1 superseded, v2 superseded, v3 active)
  AND each version has associated Signature records
WHEN an auditor opens the LearningPlan detail page → versionHistory tab
THEN the UI MUST list all three versions with their lifecycle status
  AND for each version the Signature records MUST be visible (signerRole, signedAt, assuranceLevel)
  AND the auditTrail tab MUST show all lifecycle transitions across all versions
     (sourced from OR's built-in audit trail via CnObjectSidebar auditTrailTab)
```

---

### REQ-LP-006 — signatureRequested notification

The system MUST dispatch a `signatureRequested` notification to all required signers when a LearningPlan version is submitted for signing. The notification MUST be idempotency-keyed per plan+version to prevent double-dispatch.

#### Scenario LP-006-A: Notification dispatched on submit transition

```
GIVEN a LearningPlan with id=<planId> version=1 transitions to lifecycle='draft' and is ready for signing
WHEN the coordinator POSTs PATCH .../transition/submit (even if the guard rejects — the notification fires on the lifecycle engine's 'submit' attempt OR on a dedicated 'requestSignatures' action)
THEN OR's notification engine MUST dispatch 'scholiq.learningplan.signature.requested' notifications
     to all users referenced by LearningPlanTemplate.requiredSignerRoles
  AND the idempotencyKey 'scholiq.lp.sign.<planId>.1' MUST ensure no duplicate notifications
     on a second submit attempt
```

#### Scenario LP-006-B: signatureRequested NOT implemented as a PHP service

```
GIVEN the learning-plan change is fully installed
WHEN the codebase is scanned for PHP classes with method names matching '*notification*', '*notify*', or '*signatureRequest*'
THEN NO such class MUST exist in lib/Service/ for this notification
     (it is declared in LearningPlan.x-openregister-notifications.signatureRequested)
```

---

### REQ-LP-007 — Teacher visibility of active plan goals

The system MUST allow a teacher to retrieve the active LearningPlan goals for learners in their cohort, so teaching can reflect support measures.

#### Scenario LP-007-A: Teacher queries active plan goals for cohort

```
GIVEN a teacher is authenticated with access to cohort <cohortId>
  AND one or more LearningPlans in lifecycle='active' have cohortId=<cohortId>
WHEN the teacher opens the LearningPlan index page filtered by cohortId
THEN the UI MUST display all active plans for learners in that cohort
  AND for each plan the goals[] array MUST be visible (description, domain, status, targetDate)
  AND the teacher MUST NOT be able to edit the plan (read-only RBAC via OR's AuthorizationService)
```

---

### REQ-LP-008 — Declarative schema compliance (ADR-031)

The system MUST implement all lifecycle transitions, calculations, and notifications as `x-openregister-*` schema declarations in `lib/Settings/scholiq_register.json`. No PHP service class implementing lifecycle, aggregation, calculation, or notification logic for LearningPlan is permitted.

#### Scenario LP-008-A: No prohibited service classes after install

```
GIVEN the learning-plan change is fully installed
WHEN the codebase is scanned for PHP classes under lib/Service/
     whose method names match transition*, setStatus*, advance*, getSummary*, getStats*,
     count*, computeField*, derive*, notifyOn*, dispatchNotification*, sendReminder*
THEN NO such class MUST exist for LearningPlan, LearningPlanEvaluation, or Signature
```

#### Scenario LP-008-B: goalsMetCount returned as OR calculation

```
GIVEN a LearningPlan with 5 goals where 3 have status='met'
WHEN GET /api/openregister/scholiq/LearningPlan/<planId> is called
THEN the response MUST include a computed field goalsMetCount=3
  AND this value MUST be produced by OR's calculations engine
     (declared as x-openregister-calculations.goalsMetCount on the LearningPlan schema)
  AND no PHP method computing this value MUST exist in lib/Service/
```

#### Scenario LP-008-C: isFullySigned returned as OR calculation

```
GIVEN a LearningPlan version=1 with all required Signature records in lifecycle='signed'
WHEN GET /api/openregister/scholiq/LearningPlan/<planId> is called
THEN the response MUST include isFullySigned=true
  AND when one required Signature is absent THEN isFullySigned=false
```

---

### REQ-LP-009 — Manifest pages and frontend architecture (ADR-024)

The system MUST declare all LearningPlan-related pages in `src/manifest.json` following ADR-024. No custom Vue Router entries or custom list/detail view components beyond `SignPlanModal.vue` are permitted.

#### Scenario LP-009-A: All routes resolve via manifest

```
GIVEN src/manifest.json declares pages: LearningPlanIndex, LearningPlanDetail,
      LearningPlanEvaluationIndex, LearningPlanEvaluationDetail, SignPlan
WHEN npm run check:manifest is executed
THEN the manifest validation MUST pass with no schema errors
  AND each declared route MUST be reachable from the app nav (ADR-029 route-reachability gate)
```

#### Scenario LP-009-B: No prohibited custom Vue components

```
GIVEN the learning-plan change is fully installed
WHEN the frontend src/ directory is scanned for .vue files
THEN ONLY SignPlanModal.vue MUST be a custom component added by this change
  AND no LearningPlanListView.vue, LearningPlanDetailView.vue, EvaluationListView.vue,
      or EvaluationDetailView.vue MUST exist
     (CnAppRoot's built-in renderers handle index and detail pages via the manifest)
```

---

### REQ-LP-010 — OpenRegister schema compliance (ADR-001, ADR-022)

The system MUST persist all LearningPlan data as OpenRegister objects. No custom PHP Entity, Mapper, or database table is permitted for `LearningPlan`, `LearningPlanEvaluation`, `Signature`, or `LearningPlanTemplate`.

#### Scenario LP-010-A: No custom database tables after install

```
GIVEN the learning-plan change is installed via the Nextcloud repair step
WHEN the database is inspected for tables prefixed with 'oc_scholiq_learning*'
THEN NO such tables MUST exist
  AND all data MUST reside in OpenRegister's object tables
```

#### Scenario LP-010-B: Schema registered on install

```
GIVEN the app is installed (repair step runs ConfigurationService::importFromApp)
WHEN GET /api/openregister/scholiq/schemas is called
THEN the response MUST include schemas with slugs:
     'learning-plan', 'learning-plan-evaluation', 'learning-plan-signature', 'learning-plan-template'
  AND each schema MUST have the x-openregister-lifecycle and other extensions declared
```
