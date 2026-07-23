---
slug: assignments
title: Assignments & Submissions
status: done
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opdracht-vo, opdracht-he, werkstuk, portfolio-item]
---

# Assignments & Submissions

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, lifecycle guards (late-submission enforcement), and a pluggable plagiarism PHP interface — no `#### Scenario:` headings exist in this spec.

## Purpose

Learners hand work in; teachers grade it. That loop is universal — a vmbo `opdracht`, an HBO `werkstuk`, a university `portfolio-item`, a corporate `case study`. Scholiq can hold courses and lessons but has nowhere for a learner to *submit* anything and nowhere for a teacher to mark it against criteria. This spec adds the deliverable side of assessment (the structured-test side is the `assessment` spec): an `Assignment` belongs to a Course or Session, has a due date and a `Rubric`; a learner files a `Submission` (one or more attachments) which moves through draft → submitted → returned; the grade a teacher gives a Submission becomes a `GradeEntry` (see `grading`) so it can roll up into a final grade per the CurriculumPlan.

## What

- **Assignment** — a deliverable: title, instructions (rich text + attached briefing `Material`s), Course/Session it belongs to, CurriculumPlan component it scores (`componentId`), `dueAt`, `maxPoints`, `allowLateSubmission` + late penalty, `rubricId`, `groupSubmission` (bool), visibility window.
- **Submission** — a learner's (or group's) hand-in for an Assignment: learnerId(s), attached files (OpenRegister attachments), `submittedAt`, `lifecycle` (draft → submitted → late → returned), teacher feedback text, `gradeEntryId` once marked.
- **Rubric** — reusable marking scheme: criteria, each with weighted levels (`{ criterionId, label, weight, levels: [{ label, points }] }[]`). A teacher marking a Submission picks a level per criterion → the points sum is the proposed grade.
- Submission window enforcement, late-flagging + penalty application, plagiarism-check hook (`x-plagiarism` provider config — pluggable, like proctoring; no built-in checker).

## User Stories

- As a teacher, I want to publish an Assignment with a due date and a rubric, so learners know exactly what's expected and how it's marked.
- As a learner, I want to upload my work, save it as a draft, and submit when ready — and see whether it landed before the deadline.
- As a teacher, I want to mark a Submission against the rubric and have the points feed the learner's grade automatically.
- As a learner, I want my returned Submission to show the rubric levels I scored and the teacher's comments.
- As a teacher, I want late submissions flagged and the configured penalty applied to the proposed grade.

## Acceptance Criteria

- GIVEN an Assignment with `dueAt` in the future, WHEN a learner submits, THEN the Submission lifecycle is `submitted` and `submittedAt` is recorded; submitting after `dueAt` sets lifecycle `late`.
- GIVEN `allowLateSubmission=false` and `dueAt` in the past, WHEN a learner attempts to submit, THEN the system rejects it (HTTP 422) and creates no Submission.
- GIVEN a teacher marks a Submission against a Rubric, WHEN they save, THEN a `GradeEntry` is created/updated with the summed points and linked to the Submission; the learner's view shows the per-criterion levels.
- GIVEN a Submission is `late` and the Assignment has a 10% penalty, WHEN the teacher marks it, THEN the proposed grade is reduced by 10% before becoming the GradeEntry value.
## Requirements
### Requirement: Persist Assignment domain objects in OpenRegister
The system MUST persist `Assignment`, `Submission`, `Rubric` as OpenRegister objects with
`x-openregister-lifecycle` (Submission: draft → submitted → late → returned), `x-openregister-relations`
(Assignment↔Course/Session/Rubric, Submission↔Assignment/learner), and `x-openregister-calculations`
(Submission `isLate`, `effectiveGrade`). `Assignment` additionally gains `peerReviewEnabled`,
`selfAssessmentEnabled`, `peerReviewersPerSubmission`, `peerReviewAnonymity` (`open | blind | double-blind`),
`peerReviewAllocationStrategy` (`round-robin | random | manual`), `peerReviewDueAt`, `peerReviewWeightPercent`,
and `selfAssessmentTiming` (`before-submission | after-submission | both`) — all additive, defaulting to
disabled/null so existing rows validate unchanged. The system MUST also persist two new schemas, `PeerReview`
(reviewer × submission × rubric scores, lifecycle `assigned → submitted → released`) and `SelfAssessment`
(learner × own submission × rubric scores, lifecycle `draft → submitted`), plus a computed, read-only
`PeerFeedbackSummary` (one row per `Submission`, aggregated from `released` `PeerReview`s). The existing
`AssignmentPublishGuard` on `draft → published` MUST additionally block publish when `peerReviewEnabled` or
`selfAssessmentEnabled` is `true` but `rubricId` is unset.

#### Scenario: Assignment objects persist in OpenRegister
- **GIVEN** the assignment schemas are registered in OpenRegister
- **WHEN** an `Assignment`, `Submission`, or `Rubric` is created
- **THEN** it is stored as an OpenRegister object carrying its `x-openregister-lifecycle` (Submission: draft →
  submitted → late → returned), `x-openregister-relations`, and `x-openregister-calculations` (Submission
  `isLate`, `effectiveGrade`) metadata

#### Scenario: PeerReview and SelfAssessment persist alongside Assignment
- **GIVEN** an `Assignment` with `peerReviewEnabled: true` and `selfAssessmentEnabled: true`
- **WHEN** a `PeerReview` or `SelfAssessment` is created against one of its `Submission`s
- **THEN** it is stored as an OpenRegister object carrying its own `x-openregister-lifecycle` and a
  `rubricScores` array shaped identically to `Submission.rubricScores`

#### Scenario: Publish is blocked when peer/self assessment is enabled without a Rubric
- **GIVEN** an `Assignment` in `draft` with `peerReviewEnabled: true` and `rubricId` unset
- **WHEN** the `publish` transition is attempted
- **THEN** `AssignmentPublishGuard` blocks it, because there is no `Rubric` for reviewers or the learner to
  score against

### Requirement: Submission attachments use OpenRegister file attachments
Submission attachments MUST use OpenRegister file attachments; no app-local file storage.

#### Scenario: Submission attachments stored as OpenRegister attachments
- **GIVEN** a learner filing a Submission with one or more files
- **WHEN** the files are uploaded
- **THEN** they are stored as OpenRegister file attachments and no app-local file storage is used

### Requirement: Marking a Submission emits a GradeEntry
Marking a Submission MUST emit (or update) a `GradeEntry` consumed by the `grading` spec; this spec MUST NOT
compute final grades itself. Peer and self-assessment scores (`PeerReview.rubricScores`/`totalScore`,
`SelfAssessment.rubricScores`/`totalScore`) MUST NOT be written into `Submission.rubricScores` or
`Submission.proposedGrade` by any automated code path, and MUST NOT create or influence a `GradeEntry`
directly — `GradeEntry.sourceKind` is not extended with a peer/self value. When `Assignment.peerReviewWeightPercent`
is set, it MAY drive a suggested blended number displayed to the teacher in `MarkSubmissionView`, but the
teacher's own entry into `Submission.rubricScores`/`proposedGrade` remains the only write path to a
`GradeEntry`.

#### Scenario: Marking a Submission emits a GradeEntry
- **GIVEN** a teacher marking a Submission against its Rubric
- **WHEN** the marking is saved
- **THEN** a `GradeEntry` is emitted or updated for the `grading` spec, and this spec does not compute the
  final grade itself

#### Scenario: Peer and self-assessment scores never create a GradeEntry
- **GIVEN** an Assignment with `peerReviewEnabled: true`, a `released` `PeerReview`, and a `submitted`
  `SelfAssessment` for a Submission that the teacher has not yet marked
- **WHEN** the `PeerReview` is released or the `SelfAssessment` is submitted
- **THEN** no `GradeEntry` is created or updated, and `Submission.rubricScores`/`proposedGrade` remain
  unchanged until the teacher marks the Submission themselves

#### Scenario: A configured peer-review weight only suggests, never writes, a blended score
- **GIVEN** an Assignment with `peerReviewWeightPercent: 20` and a `PeerFeedbackSummary.averageScore` for a
  Submission the teacher is marking
- **WHEN** the teacher opens `MarkSubmissionView`
- **THEN** a blended suggestion is displayed alongside the teacher's own entry fields
- **AND** `Submission.proposedGrade` is not pre-filled or altered until the teacher explicitly enters a value

### Requirement: Plagiarism check is a pluggable provider
The plagiarism-check hook MUST be a declared `x-plagiarism.provider` config on `Assignment` resolving to a pluggable PHP interface (no bundled provider) — analogous to proctoring providers in the `assessment` spec.

#### Scenario: Plagiarism check resolves to a pluggable provider
- **GIVEN** an Assignment with an `x-plagiarism.provider` config and no bundled checker
- **WHEN** the plagiarism-check hook fires
- **THEN** the configured provider is resolved through the pluggable PHP interface, analogous to proctoring providers in the `assessment` spec

### Requirement: Frontend is declarative with named custom views
The frontend MUST be declarative: `src/manifest.json` pages for Assignment index/detail and a custom
`SubmitWorkModal` + `MarkSubmissionView` Vue component (genuine UI that a manifest index/detail page can't
express). No PHP CRUD controllers, except the narrowly-scoped `PeerReviewController::allocate()` batch-matching
endpoint; the late-window enforcement is an `x-openregister-lifecycle` guard. Two further named custom views,
`PeerReviewMarkingView` (a reviewer completes a `PeerReview` against the Assignment's Rubric) and
`SelfAssessmentView` (a learner completes a `SelfAssessment` against the same Rubric), are added; the existing
`MarkSubmissionView` is extended with a read-only `PeerFeedbackSummary`/`SelfAssessment` context panel.

#### Scenario: Frontend is declarative with named custom views
- **GIVEN** the assignments app frontend
- **WHEN** the UI is composed
- **THEN** Assignment index/detail are declarative `src/manifest.json` pages, the only custom Vue components
  are `SubmitWorkModal`, `MarkSubmissionView`, `PeerReviewMarkingView`, and `SelfAssessmentView`, there are no
  PHP CRUD controllers besides the peer-review allocation endpoint, and late-window enforcement is an
  `x-openregister-lifecycle` guard

#### Scenario: MarkSubmissionView shows peer and self-assessment as read-only context
- **GIVEN** a teacher opening `MarkSubmissionView` for a Submission that has a `PeerFeedbackSummary` and a
  `submitted` `SelfAssessment`
- **WHEN** the view renders
- **THEN** both are shown as read-only context panels, and the teacher's own rubric marking fields remain the
  only editable grade input on the page

### Requirement: Assignment declares which competencies it assesses

The `Assignment` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies a graded `Submission` for this assignment provides evidence
for. The field MUST be additive — existing `Assignment` rows leave `competencyIds` as an empty array — and
MUST NOT be required. When set, the `competency` capability's `CompetencyAttainmentRollupHandler` MUST
treat every listed competency as aligned when the resulting `GradeEntry` (`sourceKind:
assignment-submission`) publishes.

#### Scenario: A published GradeEntry from an aligned Assignment feeds the competency roll-up

<!-- @e2e exclude Pure OpenRegister schema field; the roll-up behaviour itself is covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest, not a scholiq DOM surface here. -->

- **GIVEN** an `Assignment` with `competencyIds` set to one `Competency` UUID
- **WHEN** a learner's `Submission` for that assignment is marked and its `GradeEntry` transitions to
  `published`
- **THEN** the `competency` capability's roll-up handler creates or updates a `CompetencyAttainment` row
  for that learner and competency

#### Scenario: An assignment with no declared competencies does not participate in the roll-up

<!-- @e2e exclude Additive-field default-value / no-op handling; no DOM surface. -->

- **GIVEN** a pre-existing `Assignment` row with `competencyIds` unset (defaults to `[]`)
- **WHEN** a `Submission` for it is marked and published
- **THEN** no `CompetencyAttainment` row is created or updated, and grading behaves exactly as it did
  before this change

### Requirement: Peer review and self-assessment are configurable per Assignment
`Assignment` MUST expose `peerReviewEnabled`, `selfAssessmentEnabled`, `peerReviewersPerSubmission` (minimum
1, default 2), `peerReviewAnonymity` (`open | blind | double-blind`, default `blind`),
`peerReviewAllocationStrategy` (`round-robin | random | manual`, default `round-robin`), an optional
`peerReviewDueAt`, an optional `peerReviewWeightPercent` (0–100), and `selfAssessmentTiming`
(`before-submission | after-submission | both`, default `after-submission`). Every field is independently
toggleable — an Assignment MAY enable self-assessment without peer review, or vice versa.

#### Scenario: Peer review and self-assessment default to disabled
- **GIVEN** a newly created `Assignment` with no peer/self fields explicitly set
- **WHEN** it is persisted
- **THEN** `peerReviewEnabled` and `selfAssessmentEnabled` are both `false`, and no reviewer allocation or
  self-assessment prompt occurs

#### Scenario: An Assignment enables self-assessment without peer review
- **GIVEN** an Assignment with `selfAssessmentEnabled: true` and `peerReviewEnabled: false`
- **WHEN** a learner submits their work
- **THEN** they are prompted for a `SelfAssessment` per `selfAssessmentTiming`, and no `PeerReview` allocation
  occurs for this Assignment

### Requirement: Reviewer allocation runs as a dedicated service supporting round-robin, random, and manual strategies
`PeerReviewAllocationService` MUST allocate `PeerReview` rows for an Assignment's submissions, drawing its
reviewer pool from the Assignment's own submitters (not the full cohort), excluding every learner listed in a
Submission's own `learnerIds` from reviewing that Submission. It MUST support `round-robin` (deterministic
cyclic assignment), `random` (shuffled assignment, same exclusion rule), and `manual` (a no-op — the teacher
creates `PeerReview` rows by hand). Re-running allocation for an Assignment MUST be idempotent: it only tops
up submissions short of `peerReviewersPerSubmission` reviewers and never duplicates an existing
(reviewer, submission) pair.

#### Scenario: Round-robin allocates the configured reviewer count while excluding self
- **GIVEN** an Assignment with `peerReviewAllocationStrategy: round-robin`, `peerReviewersPerSubmission: 2`,
  and five Submissions each from a different learner
- **WHEN** `PeerReviewAllocationService::allocate()` runs
- **THEN** every Submission ends up with exactly 2 `PeerReview` rows in `assigned` state, and no learner is
  assigned to review their own Submission

#### Scenario: Manual strategy performs no automatic allocation
- **GIVEN** an Assignment with `peerReviewAllocationStrategy: manual`
- **WHEN** `PeerReviewAllocationService::allocate()` is invoked
- **THEN** no `PeerReview` rows are created; a teacher must create them through the manifest create form

#### Scenario: Re-running allocation is idempotent
- **GIVEN** an Assignment where every Submission already has its full complement of `assigned`/`submitted`/
  `released` `PeerReview`s
- **WHEN** `PeerReviewAllocationService::allocate()` is run again
- **THEN** no new `PeerReview` rows are created and no existing (reviewer, submission) pair is duplicated

### Requirement: PeerReview captures one reviewer's rubric-based assessment with its own lifecycle
`PeerReview` MUST persist `assignmentId`, `submissionId`, `reviewerId`, `rubricScores` (the same
`{criterionId, levelId, points}[]` shape as `Submission.rubricScores`), an optional `totalScore`, and an
optional `comments` field, with lifecycle `assigned → submitted → released`.
`x-openregister-authorization.create` MUST restrict creation to `admin` (system-created via allocation, or a
teacher's manual creation) — a reviewer never authors their own `PeerReview` row, only transitions an
allocated one. The `submit` transition (`assigned → submitted`) MUST be guarded by
`RubricScoresCompletionGuard`, which blocks the transition unless `rubricScores` covers every `criterionId` in
the linked Assignment's `Rubric`.

#### Scenario: A reviewer completes an assigned PeerReview
- **GIVEN** a `PeerReview` in `assigned` state for a reviewer
- **WHEN** the reviewer scores every criterion of the linked Rubric and submits
- **THEN** the `PeerReview` transitions to `submitted`

#### Scenario: Submit is blocked when rubric coverage is incomplete
- **GIVEN** a `PeerReview` in `assigned` state whose linked Rubric has three criteria, and `rubricScores` only
  covers two of them
- **WHEN** `submit` is attempted
- **THEN** `RubricScoresCompletionGuard` blocks the transition

#### Scenario: A teacher releases a submitted PeerReview
- **GIVEN** a `PeerReview` in `submitted` state
- **WHEN** a teacher (or admin) transitions it to `released`
- **THEN** the `PeerReview` becomes eligible for `PeerFeedbackAggregator` to include in the Submission's
  `PeerFeedbackSummary`

### Requirement: Self-assessment lets a learner score their own submission against the Assignment's Rubric
`SelfAssessment` MUST persist `assignmentId`, `submissionId`, `learnerId`, `timing`
(`before-submission | after-submission`), `rubricScores`, an optional `totalScore`, and an optional `comments`
field, with lifecycle `draft → submitted`. `learnerId` MUST be one of the linked `Submission.learnerIds`. The
`submit` transition MUST be guarded by the same `RubricScoresCompletionGuard` used by `PeerReview.submit`.

#### Scenario: A learner completes a self-assessment before submitting
- **GIVEN** an Assignment with `selfAssessmentEnabled: true` and `selfAssessmentTiming: before-submission`
- **WHEN** the learner scores their own draft Submission against the Rubric and submits the `SelfAssessment`
- **THEN** it transitions to `submitted`, independently of the Submission's own `draft → submitted` transition

#### Scenario: A learner completes a self-assessment after submitting
- **GIVEN** an Assignment with `selfAssessmentTiming: after-submission` and a Submission already in
  `submitted` state
- **WHEN** the learner scores their own Submission against the Rubric and submits the `SelfAssessment`
- **THEN** it transitions to `submitted`, and the Submission's own lifecycle is unaffected

### Requirement: Reviewer identity is hidden from the submission author via a server-enforced feedback projection
`PeerReview.x-property-rbac.read` MUST be a fixed rule — `anyOf: [{role: admin}, {match: {field: reviewerId,
operator: eq, value: $userId}}]` — independent of `Assignment.peerReviewAnonymity`, so a submission's author
can never read a raw `PeerReview` row. The author MUST instead read peer feedback through
`PeerFeedbackSummary`, computed by `PeerFeedbackAggregator` from `released` `PeerReview`s for that Submission:
when `peerReviewAnonymity` is `blind` or `double-blind`, `feedbackItems[].reviewerId` MUST be computed as
`null`; when `open`, it MUST be populated with the reviewer's identity. This is a server-enforced guarantee on
the reviewer-identity axis via object-shape projection, not a UI convention. The reviewee-identity axis for
`double-blind` (hiding whose work a reviewer is grading) is NOT a server-enforced guarantee at this register's
current RBAC capability — `PeerReviewMarkingView` MUST withhold the author's identity from its own display as
a UI convention, but a caller with direct object-level read access to the linked `Submission` is not blocked
at the field level (documented, not silently assumed away).

#### Scenario: The author cannot read a raw PeerReview
- **GIVEN** a submission author who is neither `admin` nor the `reviewerId` of a given `PeerReview`
- **WHEN** that author requests the `PeerReview` object directly
- **THEN** the request is denied by `x-property-rbac.read` (fail-closed)

#### Scenario: Blind and double-blind hide reviewer identity in the feedback summary
- **GIVEN** an Assignment with `peerReviewAnonymity: blind` (or `double-blind`) and a `released` `PeerReview`
  for one of its Submissions
- **WHEN** `PeerFeedbackAggregator` computes the Submission's `PeerFeedbackSummary`
- **THEN** the corresponding `feedbackItems[].reviewerId` is `null`

#### Scenario: Open anonymity reveals reviewer identity in the feedback summary
- **GIVEN** an Assignment with `peerReviewAnonymity: open` and a `released` `PeerReview` for one of its
  Submissions
- **WHEN** `PeerFeedbackAggregator` computes the Submission's `PeerFeedbackSummary`
- **THEN** the corresponding `feedbackItems[].reviewerId` is populated with the reviewer's identity

#### Scenario: Double-blind reviewee-identity hiding is UI-level only, and this is documented
- **GIVEN** an Assignment with `peerReviewAnonymity: double-blind`
- **WHEN** a reviewer opens `PeerReviewMarkingView` for their assigned `PeerReview`
- **THEN** the view withholds the Submission's learner identity from its own display
- **AND** this withholding does not change the reviewer's underlying object-level read grant on the linked
  `Submission`, which remains unrestricted at the field level — consistent with the same documented limit
  already stated for `FraudCase`'s `ExamCaseDossierView` (`openspec/specs/exam-board/spec.md:136-139`)

## Standards

Schema.org `CreativeWork` / `MediaObject` for submissions; IMS Caliper for submission events; QTI is *not* used here (that's `assessment`); plagiarism providers (Turnitin/Ouriginal/Compilatio) behind an interface.

## Data Model

All in OpenRegister. New: `Assignment`, `Submission`, `Rubric`. Touches: `GradeEntry` (from `grading`), `Material` (from `school-structure`). One ADR-031 PHP exception: the late-submission lifecycle guard. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The structured-test / exam path (QTI items, scoring engine, proctoring) — that's the `assessment` spec.
- Peer review / peer grading (a follow-up).
- The actual plagiarism-detection algorithm (provider behind the hook only).
- Final-grade computation (the `grading` spec).
