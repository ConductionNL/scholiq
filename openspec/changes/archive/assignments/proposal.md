## Why

Learners hand work in; teachers mark it. That loop is universal — a vmbo `opdracht`, an HBO `werkstuk`, a university `portfolio-item`, a corporate `case study`. Scholiq can hold courses, lessons, and (after `school-structure`) cohorts and sessions, but there is nowhere for a learner to *submit* anything and nowhere for a teacher to mark it against criteria. This change adds the deliverable side of assessment (the structured-test side is the `assessment` spec): an `Assignment` belongs to a Course or Session and has a due date and a `Rubric`; a learner files a `Submission` (one or more OpenRegister file attachments) which moves through draft → submitted → late → returned; the rubric mark a teacher gives a Submission becomes its `proposedGrade` (and, once the `grading` spec lands, a `GradeEntry` that rolls up per the CurriculumPlan formula).

## What Changes

### New Schemas (3) — `lib/Settings/scholiq_register.json` (14 → 17)

- **Rubric** (slug `rubric`) — reusable marking scheme: `criteria` (`{ criterionId, label, weight, levels: [{ levelId, label, points }] }[]`), `maxPoints`. Lifecycle: draft → active → archived. Calculations: `criterionCount`, `computedMaxPoints`.
- **Assignment** (slug `assignment`) — a deliverable: `courseId` / `sessionId` / `cohortId` scope, `curriculumPlanComponentId`, `briefingMaterialIds`, `dueAt`, `maxPoints`, `allowLateSubmission` + `latePenaltyPercent`, `rubricId`, `groupSubmission`, visibility window, `plagiarismProvider` hook. Lifecycle: draft → published → closed | archived. `AssignmentPublishGuard` (ADR-031 exception) blocks `publish` unless a `courseId` or `sessionId` is set. Calculations: `isOverdue` (dateDiff vs `dueAt`), `submissionCount`, `gradedCount`.
- **Submission** (slug `submission`) — a learner's (or group's) hand-in: `assignmentId`, `learnerIds[]`, `attachmentRefs[]` (OpenRegister file attachments — no bytes stored here), `submittedAt`, `feedbackText`, `rubricScores` (`{ criterionId, levelId, points }[]`), `proposedGrade`, `gradeEntryId` (forward-ref to the `grading` spec). Lifecycle: draft → submitted → late → returned. `SubmissionWindowGuard` (ADR-031 exception) rejects `submit` after `Assignment.dueAt` when late submission is not allowed (and redirects the target state to `late` when it is). Calculations: `isLate`, `effectiveGrade` (`proposedGrade` reduced by `latePenaltyPercent` when `isLate`). Not append-only — submissions are editable in draft.

### New PHP (3, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/AssignmentPublishGuard.php` — single `check()`; publish requires a courseId or sessionId.
- `lib/Lifecycle/SubmissionWindowGuard.php` — single `check()`; looks up the parent Assignment via `ObjectService::findAll()`, enforces the submission window, branches the target state for late submissions.
- `lib/Plagiarism/ProvidesPlagiarismCheck.php` — a pluggable interface; no concrete provider ships (analogous to the proctoring provider in the `assessment` spec). Referenced by the schema's `plagiarismProvider` config.

### New Frontend

- Manifest pages: Assignments / AssignmentDetail, Rubrics / RubricDetail, Submissions / SubmissionDetail (index + detail) + `SubmitWorkModal` (custom) + `MarkSubmissionView` (custom) + an "Assignments" nav entry.
- `src/views/SubmitWorkModal.vue` — upload → save draft → submit. `src/views/MarkSubmissionView.vue` — rubric marking → `proposedGrade` (a `// TODO(grading spec)` marks where it will emit a `GradeEntry`). Both Options API + `createObjectStore`, registered in `src/main.js` `customComponents`. No router edits, no Pinia store modules.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for the pages + the two modal/marking surfaces.

## Capabilities

### New Capabilities

- `assignments`: Rubric, Assignment, Submission schemas with declarative lifecycle / relations / calculations; two lifecycle guards + one pluggable plagiarism interface; manifest pages + two custom Vue views for the hand-in and marking flows.
