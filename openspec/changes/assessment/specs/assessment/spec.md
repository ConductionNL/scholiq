---
slug: assessment
title: Assessment — Tests, Exams, Item Banks, Proctoring
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-005, ADR-022, ADR-024, ADR-031]
created: 2026-05-20
updated: 2026-05-20
profiles: [toets-vo, tentamen-he, examen, certification-exam, formative-quiz]
replaces: [assessment-engine, proctoring]
---

# Assessment — Tests, Exams, Item Banks, Proctoring

## Overview

Structured testing capability for Scholiq: vmbo `toets`, HBO `tentamen`, state `examen`, certification exam, and formative quiz. Draws from a reusable **item bank** of QTI 3.0 items, auto-scores choice/textEntry items on submit, routes essay items for teacher marking, and gates high-stakes exams through a pluggable proctoring interface. The EU AI Act (Reg. 2024/1689 Annex III §3) classifies online proctoring as high-risk AI — proctoring is therefore a pluggable provider (no bundled adapter ships) and any AI-assisted flag review requires a DPO-acknowledged `AiFeature` registration before it can be enabled (ADR-005).

---

## Requirements

### REQ-ASS-001 — ItemBank schema persistence

The system MUST persist `ItemBank` objects in the scholiq OpenRegister with lifecycle states `draft`, `published`, and `archived`. A calculated `itemCount` field MUST reflect the length of the `itemIds` array at read time.

#### Scenario ASS-001-A: ItemBank is created in draft state

```
GIVEN an item author is authenticated
WHEN they POST an ItemBank with name "Wiskunde A — HAVO 4/5" and subject "wiskunde A"
THEN the system MUST create an ItemBank object in OpenRegister
  AND the lifecycle field MUST be "draft"
  AND itemCount MUST be 0
```

#### Scenario ASS-001-B: itemCount reflects itemIds array length

```
GIVEN an ItemBank with three UUIDs in its itemIds array
WHEN the ItemBank is read
THEN its itemCount calculation MUST be 3
```

#### Scenario ASS-001-C: ItemBank lifecycle transitions

```
GIVEN an ItemBank in "draft" state
WHEN the "publish" transition is requested
THEN the ItemBank lifecycle MUST change to "published"

GIVEN a published ItemBank
WHEN the "archive" transition is requested
THEN the ItemBank lifecycle MUST change to "archived"
```

---

### REQ-ASS-002 — Item schema persistence

The system MUST persist `Item` objects carrying a QTI 3.0 body, an interaction type from the IMS QTI 3.0 enumeration, and a `correctResponse` that is null for items requiring manual scoring. A calculated `needsManualScoring` field MUST be true when `interactionType` is `extendedText` or `correctResponse` is null.

#### Scenario ASS-002-A: extendedText item is flagged as needing manual scoring

```
GIVEN an Item with interactionType "extendedText" and correctResponse null
WHEN the Item is read
THEN its needsManualScoring calculation MUST be true
```

#### Scenario ASS-002-B: choice item with a correct response does not need manual scoring

```
GIVEN an Item with interactionType "choice" and a non-null correctResponse
WHEN the Item is read
THEN its needsManualScoring calculation MUST be false
```

#### Scenario ASS-002-C: Item lifecycle transitions

```
GIVEN an Item in "draft" state
WHEN the "publish" transition is requested
THEN the Item lifecycle MUST change to "published"

GIVEN a published Item
WHEN the "retire" transition is requested
THEN the Item lifecycle MUST change to "retired"
```

---

### REQ-ASS-003 — Assessment schema persistence and publish guard

The system MUST persist `Assessment` objects with lifecycle states `draft`, `published`, `closed`, and `archived`. The `publish` transition MUST be blocked by `AssessmentPublishGuard` when `itemRefs` is empty. When `proctoring.flagReviewMode` is `ai-assisted`, the guard MUST additionally require an enabled `AiFeature` with slug `assessment-ai-proctor-review` (ADR-005 DPO gate).

#### Scenario ASS-003-A: Publish blocked when itemRefs is empty

```
GIVEN an Assessment in "draft" with an empty itemRefs array
WHEN the "publish" transition is requested
THEN the transition MUST be blocked
  AND the Assessment MUST remain in "draft"
  AND the error context MUST include a message indicating itemRefs is empty
```

#### Scenario ASS-003-B: Publish allowed when itemRefs contains at least one item

```
GIVEN an Assessment in "draft" with one entry in itemRefs
WHEN the "publish" transition is requested
THEN the Assessment lifecycle MUST change to "published"
```

#### Scenario ASS-003-C: AI-assisted proctoring publish requires enabled AiFeature

```
GIVEN an Assessment in "draft" with non-empty itemRefs
  AND proctoring.flagReviewMode set to "ai-assisted"
  AND no AiFeature with slug "assessment-ai-proctor-review" in "enabled" state exists
WHEN the "publish" transition is requested
THEN the transition MUST be blocked
  AND the Assessment MUST remain in "draft"
  AND the error context MUST reference the missing AiFeature gate (ADR-005)
```

#### Scenario ASS-003-D: isAvailable reflects the availability window

```
GIVEN a published Assessment with availableFrom in the past and availableUntil in the future
WHEN the Assessment is read
THEN its isAvailable calculation MUST be true

GIVEN a published Assessment with availableUntil in the past
WHEN the Assessment is read
THEN its isAvailable calculation MUST be false
```

#### Scenario ASS-003-E: totalPoints sums itemRefs points

```
GIVEN an Assessment with three itemRefs with points [2, 5, 3]
WHEN the Assessment is read
THEN its totalPoints calculation MUST be 10
  AND its itemCount calculation MUST be 3
```

#### Scenario ASS-003-F: keepScore=best selects the highest attempt score

```
GIVEN an Assessment with maxAttempts=2 and keepScore=best
  AND a learner has submitted two AssessmentResults with autoScores 6 and 8 (out of 10)
WHEN the grading spec reads the GradeEntry for this CurriculumPlan component
THEN the GradeEntry value MUST reflect the score of 8 (the higher attempt)
```

---

### REQ-ASS-004 — AssessmentResult schema persistence and auto-scoring on submit

The system MUST persist `AssessmentResult` objects as append-only records with lifecycle states `in-progress`, `submitted`, and `graded`. On the `submit` transition, `AssessmentScoringHandler` MUST auto-score all auto-scorable item responses as a side-effect without blocking the transition. extendedText responses and items with null `correctResponse` MUST receive `autoScore: null`.

#### Scenario ASS-004-A: choice response is auto-scored on submit

```
GIVEN an AssessmentResult in "in-progress" containing a response to a choice Item
  AND the learner's response matches the Item's correctResponse
WHEN the "submit" transition is requested
THEN the transition MUST proceed
  AND the response's autoScore MUST equal the Item's maxScore
  AND submittedAt MUST be set
  AND the lifecycle MUST change to "submitted"
```

#### Scenario ASS-004-B: extendedText response has null autoScore after submit

```
GIVEN an AssessmentResult in "in-progress" containing a response to an extendedText Item
WHEN the "submit" transition is requested
THEN the transition MUST proceed
  AND the response's autoScore MUST be null
  AND the lifecycle MUST change to "submitted" (NOT "graded")
```

#### Scenario ASS-004-C: AssessmentResult is append-only

```
GIVEN a submitted AssessmentResult
WHEN a direct field-edit (non-transition PATCH) is attempted on the object
THEN the system MUST reject the request (HTTP 409 or 422)
  AND the AssessmentResult MUST remain unchanged
  (the appendOnly schema flag enforces this via OR's abstraction — ADR-022)
```

#### Scenario ASS-004-D: partial scoring for order interaction type

```
GIVEN an AssessmentResult in "in-progress" containing a response to an order Item with maxScore 4
  AND the learner's response has 2 of 4 items in the correct position
WHEN the "submit" transition is requested
THEN the response's autoScore MUST be 2 (proportional partial scoring)
```

---

### REQ-ASS-005 — AssessmentGradeGuard blocks grading until all manual items are scored

The system MUST block the `grade` transition on an AssessmentResult until every item with `needsManualScoring: true` has a non-null `manualScore` in the corresponding response entry.

#### Scenario ASS-005-A: Grade blocked when a manual item lacks a manualScore

```
GIVEN a submitted AssessmentResult with one extendedText response whose manualScore is null
WHEN the "grade" transition is requested
THEN the transition MUST be blocked
  AND the AssessmentResult MUST remain in "submitted"
  AND the error context MUST identify which item is awaiting manual scoring
```

#### Scenario ASS-005-B: Grade allowed when all manual items are scored

```
GIVEN a submitted AssessmentResult with one extendedText response whose manualScore is 7
WHEN the "grade" transition is requested
THEN the AssessmentResult lifecycle MUST change to "graded"
```

#### Scenario ASS-005-C: Graded result emits a GradeEntry

```
GIVEN an AssessmentResult transitions to "graded"
  AND the parent Assessment has a non-null curriculumPlanComponentId
WHEN the grading spec processes the GradeEntry event
THEN a GradeEntry MUST exist (or be updated) for the learner's CurriculumPlan component
  (consumed by the grading spec — this requirement gates the hand-off point)
```

---

### REQ-ASS-006 — QTI import pipeline

The system MUST accept a QTI 2.x / 3.0 or IMS Common Cartridge ZIP package via `POST /api/assessment/qti-import`, parse its items, and create corresponding `Item` objects in the specified `ItemBank`.

#### Scenario ASS-006-A: choice item imported from QTI 2.x package

```
GIVEN a valid QTI 2.x ZIP containing one choice item with a declared correct response
WHEN the package is posted to /api/assessment/qti-import with a valid itemBankId
THEN the response MUST include itemCount: 1 and an itemIds array with one UUID
  AND an Item MUST exist in the target ItemBank with interactionType "choice"
  AND the Item's correctResponse MUST be non-null
```

#### Scenario ASS-006-B: extendedText item imported without correctResponse

```
GIVEN a valid QTI ZIP containing one extendedText item
WHEN the package is posted to /api/assessment/qti-import with a valid itemBankId
THEN the created Item MUST have correctResponse null
  AND needsManualScoring MUST be true
```

#### Scenario ASS-006-C: Common Cartridge package discovery

```
GIVEN a valid Common Cartridge .imscc ZIP containing two QTI items
WHEN the package is posted to /api/assessment/qti-import with a valid itemBankId
THEN the response MUST include itemCount: 2
  AND both Items MUST be created in the specified ItemBank
```

#### Scenario ASS-006-D: Unknown interaction types import with raw qtiBody

```
GIVEN a QTI ZIP containing a hotspot item (not fully parsed)
WHEN the package is posted to /api/assessment/qti-import
THEN an Item MUST be created with interactionType "hotspot"
  AND qtiBody MUST contain the raw QTI 3.0 XML
  AND correctResponse MAY be null
```

---

### REQ-ASS-007 — Pluggable proctoring with EU AI Act Art. 14 compliance

The system MUST declare a `ProvidesProctoring` interface for proctoring adapters and MUST ship no concrete provider. A proctoring session MUST be created in `ProctoringSession` when a learner starts a proctored Assessment. When a proctoring flag is reviewed in `ProctoringReviewQueue`, the decision MUST be written only to `ProctoringSession`; it MUST never automatically alter any field of `AssessmentResult`.

#### Scenario ASS-007-A: No bundled proctoring provider

```
GIVEN a fresh Scholiq install
WHEN the available proctoring providers are enumerated
THEN the set MUST be empty
  AND a provider is added only by installing an adapter that implements ProvidesProctoring
```

#### Scenario ASS-007-B: Swapping provider config uses the new adapter

```
GIVEN an Assessment with proctoring.provider="surf-proctoring"
WHEN proctoring.provider is changed to "honorlock" (config only — no code change)
THEN starting a new session MUST call the honorlock adapter's startSession()
  AND no code path MUST be hard-coded to a specific provider name
```

#### Scenario ASS-007-C: Flag review does not alter AssessmentResult

```
GIVEN a ProctoringSession with one flag in "pending" state
  AND a linked AssessmentResult in "submitted" state
WHEN an invigilator sets the flag's reviewDecision to "annulled"
THEN only the ProctoringSession object MUST be updated (flag.reviewDecision, reviewedBy, reviewedAt)
  AND the linked AssessmentResult MUST remain unchanged (lifecycle still "submitted")
  AND no automatic grade penalty or result annulment MUST occur
```

#### Scenario ASS-007-D: pendingFlagCount calculation

```
GIVEN a ProctoringSession with three flags: two "pending" and one "allowed"
WHEN the ProctoringSession is read
THEN pendingFlagCount MUST be 2
  AND hasAnnulledFlag MUST be false
```

#### Scenario ASS-007-E: AI-assisted flag review is gated by AiFeature (ADR-005)

```
GIVEN an Assessment with proctoring.flagReviewMode "ai-assisted"
  AND no AiFeature with slug "assessment-ai-proctor-review" in "enabled" state
WHEN the publish transition is requested for this Assessment
THEN the transition MUST be blocked (AssessmentPublishGuard enforces the ADR-005 gate)
  AND v1 MUST ship with flagReviewMode "manual" only
```

---

### REQ-ASS-008 — Declarative frontend via src/manifest.json

The system MUST expose ItemBank, Item, Assessment, AssessmentResult, and ProctoringSession via manifest-declared index and detail pages. The test-taking, item-authoring, flag-review, and QTI-import flows MUST be custom Vue components registered via `customComponents` on `CnAppRoot`. No bespoke CRUD controllers for these schemas MUST ship.

#### Scenario ASS-008-A: Manifest validates

```
GIVEN the app's src/manifest.json
WHEN it is validated against the @conduction/nextcloud-vue manifest schema
THEN validation MUST pass with zero Ajv errors
```

#### Scenario ASS-008-B: TakeAssessmentView enforces time limit

```
GIVEN a published Assessment with timeLimitMinutes=90
  AND a learner navigates to the TakeAssessmentView
WHEN the timer reaches 0
THEN the submit transition MUST be dispatched automatically
  AND the learner MUST NOT be able to submit further responses
```

#### Scenario ASS-008-C: ItemAuthorView shows import notice for unsupported interaction types

```
GIVEN an item author opens ItemAuthorView for a hotspot item
WHEN the view renders
THEN the UI MUST display an import notice ("Use ImportQtiModal to add this item type")
  AND no native hotspot editor MUST be shown
```

#### Scenario ASS-008-D: ProctoringReviewQueue only writes to ProctoringSession

```
GIVEN an invigilator opens ProctoringReviewQueue
  AND clicks "Annuleren" on a pending flag
WHEN the action is submitted
THEN the frontend MUST PATCH only the ProctoringSession object
  AND MUST NOT issue any write to AssessmentResult
```

---

## Standards

- **IMS QTI 3.0** — canonical stored format for all Item objects
- **QTI 2.x + Common Cartridge** — import-only; converted to QTI 3.0 on import by QtiImportService
- **LTI 1.3** — external tool launch (consumed via course-management; assessment may be launched via LTI)
- **EU AI Act Reg. 2024/1689 Annex III §3** — online proctoring = high-risk AI → ADR-005 gate for any AI-assisted flag review
- **ISO/IEC 23988** — computer-based assessment; proctoring conduct principles
- **Caliper Analytics** — learning events emitted alongside xAPI (via ADR-002 LRS substrate)

## Out of Scope

- AI item generation, AI essay scoring, adaptive testing — explicitly deferred; would each require an `AiFeature` DPO registration
- Concrete proctoring vendor adapters (ProctorU, Honorlock, SURF-hosted) — interface only
- Full QTI 3.0 interaction-type editor — only `choice` and `extendedText` have native editors
- Hand-in assignments — the `assignments` spec
- IRT scoring calculation — `scoringScheme: irt` is stored; calculation deferred
- Peer review of assessments — follow-up spec
