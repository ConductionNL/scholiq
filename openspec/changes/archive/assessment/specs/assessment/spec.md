---
slug: assessment
title: Assessment, ItemBank & Items, Proctoring
status: implemented
feature_tier: must
depends_on_adrs: [ADR-005, ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [toets-vo, toets-he, tentamen, examen, quiz-formatief, certification-exam]
---

# Assessment, ItemBank & Items, Proctoring

## Why

Scholiq holds courses, cohorts, sessions, and assignments (hand-in work) but has no place for **structured testing**: a vmbo `toets`, an HBO `tentamen`, a state `examen`, a certification exam, or a formative quiz. These differ from assignments in three fundamental ways: (1) they draw from a curated **item bank** of QTI 3.0 items rather than open-ended submissions; (2) scoring is formulaic — choice and text-entry items are auto-scored on submit, essay items require teacher marking; (3) high-stakes exams often require **proctoring**, which the EU AI Act (Reg. 2024/1689 Annex III §3) classifies as high-risk AI when any AI-assisted flag review is involved.

No open-source Dutch assessment platform exists (Cito / DiatOets / IEP are proprietary). IMS QTI 3.0 is the lock-in escape hatch. This spec merges the `assessment-engine` and `proctoring` stubs: proctoring is configuration on an Assessment, not a standalone concern.

## ADDED Requirements

### Requirement: ItemBank schema persistence

The system SHALL persist `ItemBank` objects in the scholiq OpenRegister with lifecycle states draft, published, and archived.

#### Scenario: itemCount is computed from itemIds

GIVEN an ItemBank with three Items in its `itemIds` array
WHEN the ItemBank is read
THEN its `itemCount` calculation SHALL be 3.

### Requirement: Item schema persistence

The system SHALL persist `Item` objects that carry a QTI 3.0 body, an interaction type from the IMS QTI 3.0 enumeration, and a `correctResponse` that is null for items requiring manual scoring.

#### Scenario: extendedText item is flagged as needing manual scoring

GIVEN an Item with `interactionType` set to `extendedText`
WHEN the Item is read
THEN its `needsManualScoring` calculation SHALL be true.

#### Scenario: choice item with a correct response does not need manual scoring

GIVEN an Item with `interactionType` set to `choice` and a non-null `correctResponse`
WHEN the Item is read
THEN its `needsManualScoring` calculation SHALL be false.

### Requirement: Assessment schema persistence and publish guard

The system SHALL persist `Assessment` objects with lifecycle states draft, published, closed, and archived, and SHALL block the `publish` transition via `AssessmentPublishGuard` unless `itemRefs` is non-empty.

#### Scenario: Publish blocked when itemRefs is empty

GIVEN an Assessment in `draft` with an empty `itemRefs` array
WHEN the `publish` transition is requested
THEN the transition SHALL be blocked and the Assessment SHALL remain in `draft`.

#### Scenario: Publish allowed when itemRefs contains at least one item

GIVEN an Assessment in `draft` with one entry in `itemRefs`
WHEN the `publish` transition is requested
THEN the Assessment SHALL move to `published`.

#### Scenario: AI-assisted proctoring publish requires enabled AiFeature

GIVEN an Assessment in `draft` with non-empty `itemRefs` and `proctoring.flagReviewMode` set to `ai-assisted`
AND no AiFeature with slug `assessment-ai-proctor-review` in `enabled` state exists
WHEN the `publish` transition is requested
THEN the transition SHALL be blocked and the Assessment SHALL remain in `draft`.

#### Scenario: isAvailable reflects the availability window

GIVEN a published Assessment whose `availableFrom` is in the past and `availableUntil` is in the future
WHEN the Assessment is read
THEN its `isAvailable` calculation SHALL be true.

### Requirement: AssessmentResult schema persistence and auto-scoring on submit

The system SHALL persist `AssessmentResult` objects as append-only records with lifecycle states in-progress, submitted, and graded. On the `submit` transition the system SHALL auto-score all auto-scorable item responses as a side-effect without blocking the transition.

#### Scenario: choice response is auto-scored on submit

GIVEN an AssessmentResult in `in-progress` containing a response to a `choice` Item whose `correctResponse` matches the learner's response
WHEN the `submit` transition is requested
THEN the transition SHALL proceed, the response's `autoScore` SHALL equal the Item's `maxScore`, and `submittedAt` SHALL be set.

#### Scenario: extendedText response has null autoScore after submit

GIVEN an AssessmentResult in `in-progress` containing a response to an `extendedText` Item
WHEN the `submit` transition is requested
THEN the transition SHALL proceed and the response's `autoScore` SHALL be null.

#### Scenario: AssessmentResult is append-only

GIVEN a submitted AssessmentResult
WHEN a direct field-edit (non-transition PATCH) is attempted
THEN the system SHALL reject the request in accordance with the `appendOnly` schema flag.

### Requirement: AssessmentGradeGuard blocks grading until all manual items are scored

The system SHALL block the `grade` transition on an AssessmentResult until every item with `needsManualScoring` true has a non-null `manualScore` in the corresponding response entry.

#### Scenario: Grade blocked when a manual item lacks a manualScore

GIVEN a submitted AssessmentResult with one `extendedText` response whose `manualScore` is null
WHEN the `grade` transition is requested
THEN the transition SHALL be blocked and the AssessmentResult SHALL remain in `submitted`.

#### Scenario: Grade allowed when all manual items are scored

GIVEN a submitted AssessmentResult with one `extendedText` response whose `manualScore` is 7
WHEN the `grade` transition is requested
THEN the AssessmentResult SHALL move to `graded`.

### Requirement: QTI import pipeline

The system SHALL accept a QTI 2.x / 3.0 or IMS Common Cartridge ZIP package via a POST endpoint, parse its items, and create corresponding `Item` objects in the specified `ItemBank`.

#### Scenario: choice item imported from QTI 2.x package

GIVEN a valid QTI 2.x ZIP containing one `choice` item with a declared correct response
WHEN the package is posted to `/api/assessment/qti-import` with a valid `itemBankId`
THEN the response SHALL include `itemCount: 1` and an `Item` SHALL exist in the target ItemBank with `interactionType: choice` and a non-null `correctResponse`.

#### Scenario: extendedText item imported without correctResponse

GIVEN a valid QTI ZIP containing one `extendedText` item
WHEN the package is posted to `/api/assessment/qti-import` with a valid `itemBankId`
THEN the created `Item` SHALL have `correctResponse: null` and `needsManualScoring` true.

### Requirement: Pluggable proctoring with EU AI Act Art. 14 compliance

The system SHALL declare a `ProvidesProctoring` interface for proctoring adapters and SHALL ship no concrete provider. When a proctoring flag is reviewed in `ProctoringReviewQueue`, the decision SHALL be written only to `ProctoringSession`; it SHALL never automatically alter any field of `AssessmentResult`.

#### Scenario: No bundled proctoring provider

GIVEN a fresh Scholiq install
WHEN the available proctoring providers are enumerated
THEN the set SHALL be empty (a provider is added only by installing an adapter that implements `ProvidesProctoring`).

#### Scenario: Flag review does not alter AssessmentResult

GIVEN a ProctoringSession with one flag in `pending` state
WHEN an invigilator sets the flag's `reviewDecision` to `annulled`
THEN only the `ProctoringSession` object SHALL be updated; the linked `AssessmentResult` SHALL remain unchanged.

### Requirement: Declarative frontend

The system SHALL expose ItemBank, Item, Assessment, AssessmentResult, and ProctoringSession via manifest-declared pages and SHALL provide the test-taking, item-authoring, flag-review, and QTI-import flows as custom Vue components registered through `CnAppRoot`, with no bespoke CRUD controllers for these schemas.

#### Scenario: Manifest validates

GIVEN the app's `src/manifest.json`
WHEN it is validated against the `@conduction/nextcloud-vue` manifest schema
THEN validation SHALL pass with zero errors.
