---
kind: code
depends_on: []
---

## Why

`assignments` (`openspec/specs/assignments/spec.md`) already has the deliverable loop — a `Rubric`
(`lib/Settings/scholiq_register.json:3791`) is a reusable, weighted-criteria/leveled marking scheme; an
`Assignment` (`:3930`) links one to a `Course`/`Session`; a `Submission` (`:4143`) carries the learner's
attachments and, per the "Marking a Submission emits a `GradeEntry`" requirement
(`openspec/specs/assignments/spec.md:60-66`), the *teacher* scores it against that `Rubric` and the result
becomes a `GradeEntry` consumed by `grading`. But the spec's own "Out of Scope" section says so explicitly:

> "Peer review / peer grading (a follow-up)." — `openspec/specs/assignments/spec.md:95`

Confirmed at HEAD: `Rubric` has no `reviewerId`/`peerReview*` concept anywhere in
`lib/Settings/scholiq_register.json` (full-file case-insensitive grep for `peer|review` returns zero hits
outside doc comments), `Submission.rubricScores` (`:4143` region) is filled by exactly one actor — whoever
holds write access to the marking flow, described in the spec as "the teacher" — and there is no schema, no
lifecycle, and no allocation logic for a learner assessing another learner's (or their own) work. The gap is
real and total, not partial.

This matters because peer and self assessment is table-stakes in every mainstream LMS/VLE this app competes
with, and each of them ships it as a distinct, non-trivial subsystem — not a rubric reuse:

- **Moodle Workshop** — a dedicated activity module with its own two-phase (submission → assessment) workflow,
  a pluggable allocation strategy ("scheduled allocation", random, manual), and a configurable grading
  strategy that blends the *grade for submission* (what the teacher gives the work) with the *grade for
  assessment* (how well a student reviewed their peers) — two numbers, never silently merged into one.
- **Open edX ORA (Open Response Assessor)** — supports peer-assessment and self-assessment steps in the same
  problem definition, with an explicit "AI/staff/peer" grading-step pipeline and a configurable number of
  required peer reviews per submission before a learner's own grade unlocks.
- **itslearning** — peer assessment as a distinct activity type layered on top of (not replacing) teacher
  assessment, with anonymity toggles for the reviewer.
- **Gibbon** — gradebook-adjacent peer/self-assessment forms exist as a separate data type from the teacher's
  markbook entry, explicitly kept out of the official grade calculation.

Every one of these four competitor products draws the same line this change draws: peer/self scores are a
**second, parallel signal**, never a silent substitute for the teacher's mark. That line is doubly important
here because `grading`'s `GradeEntry`/`FinalGrade` already feed exam-board fraud/exemption dossiers
(`openspec/specs/grading/spec.md:159-169`, `openspec/specs/exam-board/spec.md`) — grade provenance has to
survive an appeal, so nothing may quietly widen `GradeEntry.sourceKind` (`assignment-submission |
assessment-result | participation | manual | exemption | lti-ags`,
`openspec/specs/grading/spec.md:52-58`) to include an unsupervised peer-authored value.

The other verified gap this change closes: **there is no field-level RBAC primitive in this register at
HEAD.** That is not a guess — it is the register's own documented residual limitation, restated identically
at three separate schemas: `FraudCase` ("this register has no field-level read/write RBAC primitive at
HEAD", `lib/Settings/scholiq_register.json:6462`), `ProctoringSession.flags[].reviewDecision`
(`:5269`, "Object-level scoping ... and field-level write RBAC ... are NOT enforced here — known platform
gap"), and `parent-conferences`' `pupilVoice.statementNote` (`:7776`, "a future portal-contribution
provider's field-projection concern"). Anonymity in peer review is fundamentally a field-level visibility
problem (hide *who* reviewed you, not *whether* you can read the object), so this change cannot lean on a
primitive that doesn't exist — it has to design around the gap the same way those three precedents did, and
say so explicitly (see `design.md` "Anonymity Enforcement").

## What Changes

- **Per-Assignment configuration** — `Assignment` gains `peerReviewEnabled`, `selfAssessmentEnabled`,
  `peerReviewersPerSubmission`, `peerReviewAnonymity` (`open | blind | double-blind`),
  `peerReviewAllocationStrategy` (`round-robin | random | manual`), `peerReviewDueAt`, and
  `peerReviewWeightPercent` (advisory-only, see below); `selfAssessmentTiming`
  (`before-submission | after-submission | both`).
- **Reviewer allocation service** — `OCA\Scholiq\PeerReview\PeerReviewAllocationService`, invoked by a new,
  narrowly-scoped `PeerReviewController::allocate()` endpoint (admin-only), draws its reviewer pool from the
  Assignment's own submitters (mutual peer review, not the whole cohort), excludes every member of a
  submission's own `learnerIds` from reviewing it, and supports round-robin and random strategies; `manual`
  is a no-op (the teacher creates `PeerReview` rows by hand). This is a service because OpenRegister has no
  batch-allocation primitive — matching the brief's own framing and the precedent set by
  `GradeFormulaEvaluator`/`BsaProgressEvaluator` for logic that cross-schema JSON-logic can't express.
- **`PeerReview`** (new object) — reviewer × submission × rubric scores + comments, its own lifecycle
  (`assigned → submitted → released`), gated by a shared `RubricScoresCompletionGuard` on `submit`.
- **`SelfAssessment`** (new object) — a learner scores their own `Submission` against the same `Rubric`,
  `before-submission` or `after-submission` per `Assignment.selfAssessmentTiming`, own lifecycle
  (`draft → submitted`).
- **`PeerFeedbackSummary`** (new, computed/read-only object) — the *only* surface a submission's author reads
  peer feedback through: one row per `Submission`, aggregated by a new `PeerFeedbackAggregator` listener from
  `released` `PeerReview`s. This is the server-side anonymity mechanism — see `design.md`.
- **Grade authority stays with the teacher** — the "Marking a Submission emits a `GradeEntry`" requirement is
  extended, not replaced: peer/self scores MUST NOT auto-populate `Submission.rubricScores`/`proposedGrade`
  or `GradeEntry`. `peerReviewWeightPercent`, when set, only drives a *suggested* blended number shown in
  `MarkSubmissionView` for the teacher to consider — never written automatically.
- **Frontend** — new named custom views `PeerReviewMarkingView` (reviewer completes a `PeerReview`) and
  `SelfAssessmentView` (learner completes a `SelfAssessment`); `MarkSubmissionView` (existing) gains a
  read-only `PeerFeedbackSummary`/`SelfAssessment` context panel. Everything else is `src/manifest.json`
  index/detail pages. No PHP CRUD controllers beyond the allocation endpoint.

## Impact

- **`lib/Settings/scholiq_register.json`** — `Assignment` gains 7 additive properties (existing rows default
  to disabled/null, no breaking change); three new schemas: `PeerReview`, `SelfAssessment`,
  `PeerFeedbackSummary`.
- **New PHP** — `OCA\Scholiq\PeerReview\PeerReviewAllocationService`,
  `OCA\Scholiq\Listener\PeerFeedbackAggregator`, `OCA\Scholiq\Lifecycle\RubricScoresCompletionGuard`, one
  extension to the existing `OCA\Scholiq\Lifecycle\AssignmentPublishGuard`
  (`lib/Settings/scholiq_register.json:4087`) to block publish when peer review or self-assessment is
  enabled but `rubricId` is unset, and `OCA\Scholiq\Controller\PeerReviewController` (one action:
  `allocate`).
- **`src/manifest.json`** — index/detail pages for `PeerReview`, `SelfAssessment`, `PeerFeedbackSummary`; two
  new custom views (`PeerReviewMarkingView`, `SelfAssessmentView`); `MarkSubmissionView` extended.
- **Affected specs**: `assignments` (this change, MODIFIED + ADDED requirements). `grading` is a read-only
  precedent — `GradeEntry.sourceKind` is explicitly NOT extended (see "Why").
- **Out of scope**: cross-assignment reviewer load-balancing beyond one Assignment's own submitter pool;
  guaranteeing no "reciprocal" reviewer pairs (A reviews B and B reviews A) — a nice-to-have `random`-strategy
  refinement, not required at this scope; full double-blind redaction of attachment filenames/content
  (a `docudesk` follow-up if a buyer needs it — see `design.md` "Anonymity Enforcement" for the honest limit
  of what's server-enforced today); AI-assisted/AI-similarity peer-matching (would be an `AiFeature`
  registration, not requested here).
