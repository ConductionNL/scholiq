---
slug: assignments
title: Assignments & Submissions
status: proposed
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-20
updated: 2026-05-20
profiles: [opdracht-vo, opdracht-he, werkstuk, portfolio-item]
---

# Assignments & Submissions

## Why

Learners hand work in; teachers mark it against criteria. Scholiq holds courses, lessons, cohorts, and sessions but has nowhere for a learner to submit anything and nowhere for a teacher to mark it. This adds the deliverable side of assessment (the structured-test side is the `assessment` spec): an `Assignment` belongs to a Course or Session and has a due date and a `Rubric`; a learner files a `Submission` (OpenRegister file attachments) which moves through draft → submitted → late → returned; the rubric mark becomes the submission's `proposedGrade` (and, once `grading` lands, a `GradeEntry`).

## ADDED Requirements

### REQ-ASN-001: Rubric schema persistence

The system SHALL persist `Rubric` objects in the scholiq OpenRegister with a `criteria` array of weighted levels and lifecycle states draft, active, and archived.

#### Scenario: Rubric exposes a computed maximum

GIVEN a Rubric with two criteria, each having a top level worth 5 points
WHEN the Rubric is read
THEN its `computedMaxPoints` calculation SHALL be 10.

#### Scenario: Rubric criterion count is derived

GIVEN a Rubric with three criteria
WHEN the Rubric is read
THEN its `criterionCount` calculation SHALL be 3.

### REQ-ASN-002: Assignment schema persistence and publish guard

The system SHALL persist `Assignment` objects with lifecycle states draft, published, closed, and archived, and SHALL block the `publish` transition via `AssignmentPublishGuard` unless the Assignment has a `courseId` or a `sessionId`.

#### Scenario: Publish blocked without a parent

GIVEN an Assignment in `draft` with neither `courseId` nor `sessionId` set
WHEN the `publish` transition is requested
THEN the transition SHALL be blocked and the Assignment SHALL remain in `draft`.

#### Scenario: Publish allowed with a course

GIVEN an Assignment in `draft` with `courseId` set
WHEN the `publish` transition is requested
THEN the Assignment SHALL move to `published`.

#### Scenario: Overdue is computed from dueAt

GIVEN a published Assignment whose `dueAt` is in the past
WHEN the Assignment is read
THEN its `isOverdue` calculation SHALL be true.

### REQ-ASN-003: Submission schema persistence and window guard

The system SHALL persist `Submission` objects with lifecycle states draft, submitted, late, and returned, store learner work as OpenRegister file attachments (not as bytes on the schema), and enforce the submission window via `SubmissionWindowGuard`.

#### Scenario: Submission inside the window

GIVEN an Assignment with `dueAt` in the future and a learner with a `draft` Submission
WHEN the `submit` transition is requested
THEN the Submission SHALL move to `submitted` and `submittedAt` SHALL be recorded.

#### Scenario: Late submission rejected when not allowed

GIVEN an Assignment with `dueAt` in the past and `allowLateSubmission` false
WHEN a learner requests the `submit` transition
THEN the transition SHALL be rejected (HTTP 422) and no Submission SHALL move out of `draft`.

#### Scenario: Late submission accepted when allowed

GIVEN an Assignment with `dueAt` in the past and `allowLateSubmission` true
WHEN a learner requests the `submit` transition
THEN the Submission SHALL move to `late` (not `submitted`).

#### Scenario: Effective grade applies the late penalty

GIVEN a `late` Submission with `proposedGrade` 8 and the Assignment's `latePenaltyPercent` 10
WHEN the Submission is read
THEN its `effectiveGrade` calculation SHALL be 7.2.

### REQ-ASN-004: Rubric marking produces a proposed grade

The system SHALL let a teacher mark a Submission against the linked Rubric, recording one chosen level per criterion in `rubricScores`, and SHALL set `proposedGrade` to the sum of the chosen levels' points.

#### Scenario: Marking sums the chosen levels

GIVEN a Submission whose Assignment links a Rubric with two criteria
WHEN the teacher picks the 5-point level for one criterion and the 3-point level for the other and saves
THEN `rubricScores` SHALL record both choices and `proposedGrade` SHALL be 8, and the Submission SHALL move to `returned`.

#### Scenario: Learner sees per-criterion feedback

GIVEN a returned Submission with `rubricScores` and `feedbackText` set
WHEN the learner opens the SubmissionDetail page
THEN the per-criterion levels and teacher feedback SHALL be visible.

### REQ-ASN-005: Pluggable plagiarism check

The system SHALL declare a `plagiarismProvider` configuration on `Assignment` that resolves to the `ProvidesPlagiarismCheck` interface, and SHALL ship no concrete plagiarism provider.

#### Scenario: No bundled provider

GIVEN a fresh Scholiq install
WHEN the available plagiarism providers are enumerated
THEN the set SHALL be empty (a provider is added only by installing an adapter that implements `ProvidesPlagiarismCheck`).

### REQ-ASN-006: Declarative frontend

The system SHALL expose Assignment, Rubric, and Submission via manifest-declared pages and SHALL provide the hand-in and marking flows as custom Vue components registered through `CnAppRoot`, with no bespoke CRUD controllers.

#### Scenario: Manifest validates

GIVEN the app's `src/manifest.json`
WHEN it is validated against the `@conduction/nextcloud-vue` manifest schema
THEN validation SHALL pass with zero errors.

#### Scenario: SubmitWorkModal enforces the submission window on the client

GIVEN a learner opens `SubmitWorkModal` for an Assignment whose `dueAt` is in 2 hours
WHEN the review step is rendered
THEN the modal SHALL display the remaining time and allow the learner to proceed to submit.

#### Scenario: MarkSubmissionView leaves a GradeEntry TODO

GIVEN a teacher saves a rubric marking in `MarkSubmissionView`
WHEN the Submission transitions to `returned`
THEN no `GradeEntry` object SHALL be created (the `// TODO(grading spec)` placeholder remains until the `grading` spec lands).
