# Design — Assignments & Submissions

## 1. Schemas

### 1.1 Rubric (slug `rubric`)

A reusable marking scheme an Assignment can point at.

| field | type | notes |
|---|---|---|
| name | string | required |
| description | string\|null | |
| criteria | array | `{ criterionId, label, weight (number), levels: [{ levelId, label, points (number) }] }` — required, ≥1 |
| maxPoints | number | author-declared; `computedMaxPoints` is the sum of each criterion's top level |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived (and active → archived) |

`x-openregister-calculations`: `criterionCount`, `computedMaxPoints`.

### 1.2 Assignment (slug `assignment`)

| field | type | notes |
|---|---|---|
| title | string | required |
| instructions | string | rich text |
| courseId / sessionId / cohortId | uuid\|null | scope; publish requires courseId OR sessionId |
| curriculumPlanComponentId | string\|null | which `CurriculumPlan.components[].componentId` this scores |
| briefingMaterialIds | uuid[] | attached `Material`s |
| dueAt | datetime\|null | |
| maxPoints | number | |
| allowLateSubmission | bool | default false |
| latePenaltyPercent | number | default 0 |
| rubricId | uuid\|null | |
| groupSubmission | bool | default false |
| visibleFrom / visibleUntil | datetime\|null | learner-visibility window |
| plagiarismProvider | string\|null | resolves to `ProvidesPlagiarismCheck` — pluggable, no bundled provider |
| tenant_id | string | required |
| lifecycle | string | draft → published → closed \| archived |

`x-openregister-lifecycle.transitions.publish.requires`: `OCA\Scholiq\Lifecycle\AssignmentPublishGuard`.
`x-openregister-relations`: course, session, cohort, rubric, briefingMaterials.
`x-openregister-calculations`: `isOverdue` (dateDiff vs `dueAt`), `submissionCount`, `gradedCount`.

### 1.3 Submission (slug `submission`)

| field | type | notes |
|---|---|---|
| assignmentId | uuid | required |
| learnerIds | string[] | NC user IDs — one for solo, many for group |
| attachmentRefs | string[] | OpenRegister file-attachment references — **no bytes stored on this schema** |
| submittedAt | datetime\|null | set on `submit` |
| feedbackText | string\|null | teacher feedback |
| rubricScores | array | `{ criterionId, levelId, points }` — the teacher's per-criterion marking |
| proposedGrade | number\|null | sum of `rubricScores[].points` |
| gradeEntryId | uuid\|null | set once the `grading` spec's `GradeEntry` exists (forward-ref) |
| tenant_id | string | required |
| lifecycle | string | draft → submitted → late → returned |

`x-openregister-lifecycle.transitions.submit.requires`: `OCA\Scholiq\Lifecycle\SubmissionWindowGuard`.
`x-openregister-relations`: assignment, learners.
`x-openregister-calculations`: `isLate`, `effectiveGrade`.
Not `appendOnly` — a draft submission is editable; once `submitted`/`returned` it should not change, which the lifecycle (no transition back to `draft`) enforces.

## 2. PHP — ADR-031 legitimate exceptions

- **`AssignmentPublishGuard`** — `check(array &$transitionContext): bool`. Returns false unless `$object['courseId'] !== null || $object['sessionId'] !== null`. No OR queries. Single responsibility.
- **`SubmissionWindowGuard`** — `check(array &$transitionContext): bool`. Reads `$object['assignmentId']`, looks up the Assignment via `ObjectService::findAll(['register'=>'scholiq','schema'=>'assignment','filters'=>['uuid'=>$assignmentId],'limit'=>1])`. If now > `dueAt` and `allowLateSubmission === false` → return false (OR surfaces HTTP 422). If now > `dueAt` and late is allowed → set the transition's target state to `late` (per OR's lifecycle-guard contract — see `OCA\OpenRegister\Service\Lifecycle\TransitionEngine`; if the contract can't redirect, just allow and rely on the `isLate` calculation) and return true. Else return true. Single responsibility; no audit writes (OR's lifecycle engine emits those).
- **`ProvidesPlagiarismCheck`** — an interface (`startCheck($submissionId): string`, `fetchReport($checkId): array`) that concrete plagiarism adapters (Turnitin / Ouriginal / Compilatio) would implement. None ships. Wired only via the `Assignment.plagiarismProvider` config; the actual call is out of scope here.

Guards are resolved by OR's lifecycle engine via DI by the FQCN declared in the schema's `requires:` — no `registerEventListener` in `Application.php` needed (consistent with the existing `AttestationSigningGuard`, `CoursePublishGuard`, `ProgrammePublishGuard`).

## 3. Frontend

### 3.1 Manifest pages

`Assignments` (index, schema=Assignment), `AssignmentDetail` (detail), `Rubrics` (index), `RubricDetail` (detail), `Submissions` (index — usually filtered per assignment), `SubmissionDetail` (detail, readOnly for learners), `SubmitWorkModal` (custom, component `SubmitWorkModal`), `MarkSubmissionView` (custom, component `MarkSubmissionView`). One nav `menu` entry: "Assignments". `validate-manifest` must pass against the nc-vue schema — detail pages do **not** carry a string-array `config.tabs` (that's settings-page only); CnObjectSidebar renders detail tabs automatically.

### 3.2 SubmitWorkModal.vue

Three steps inside one modal: (1) pick/drag files; (2) review the assignment brief + due date + whether you're inside the window; (3) confirm + submit — create/update the `Submission` via OR REST, attach files via OR's attachment API, then dispatch the `submit` transition (which runs `SubmissionWindowGuard`). Options API; `createObjectStore`; no custom Pinia module.

### 3.3 MarkSubmissionView.vue

Shows the submission's attachments + the linked Rubric's criteria. Teacher picks a level per criterion → the points sum becomes `proposedGrade`. On save: write `rubricScores` + `proposedGrade` + `feedbackText` to the Submission and dispatch the `return` transition. Leaves a `// TODO(grading spec): emit/update a GradeEntry for the Assignment's curriculumPlanComponentId` — `GradeEntry` does not exist until the `grading` spec lands; this view does not fabricate one.

## 4. Out of scope

- The structured-test / exam path (QTI items, scoring engine, proctoring) — the `assessment` spec.
- Peer review / peer grading — a follow-up.
- The plagiarism-detection algorithm — only the pluggable interface is here.
- Final-grade computation — the `grading` spec (`GradeEntry` → `FinalGrade`).
