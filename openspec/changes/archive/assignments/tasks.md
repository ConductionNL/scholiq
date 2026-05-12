# Tasks — Assignments & Submissions (Phase 2)

> Scope: 3 new schemas (Rubric, Assignment, Submission), 2 PHP lifecycle guards + 1 pluggable plagiarism interface, manifest pages + 2 custom Vue views, l10n (en+nl).

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `Rubric` schema per design §1.1 — criteria array with weighted levels, maxPoints; lifecycle draft → active → archived; calculations criterionCount + computedMaxPoints. Required: name, tenant_id.
- [x] Add `Assignment` schema per design §1.2 — course/session/cohort scope, curriculumPlanComponentId, briefingMaterialIds, dueAt, maxPoints, allowLateSubmission + latePenaltyPercent, rubricId, groupSubmission, visibility window, plagiarismProvider; lifecycle draft → published (via AssignmentPublishGuard) → closed | archived; relations course/session/cohort/rubric/briefingMaterials; calculations isOverdue (dateDiff) + submissionCount + gradedCount. Required: title, tenant_id.
- [x] Add `Submission` schema per design §1.3 — assignmentId, learnerIds[], attachmentRefs[] (OR attachments, no bytes), submittedAt, feedbackText, rubricScores[], proposedGrade, gradeEntryId (forward-ref); lifecycle draft → submitted → late → returned (via SubmissionWindowGuard); relations assignment/learners; calculations isLate + effectiveGrade. Required: assignmentId, tenant_id. Not appendOnly.
- [x] Validate JSON; no duplicate keys; schema count 14 → 17. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Lifecycle/AssignmentPublishGuard.php` — single `check(array &$transitionContext): bool`; returns false unless courseId or sessionId set. No OR queries.
- [x] Create `lib/Lifecycle/SubmissionWindowGuard.php` — single `check(array &$transitionContext): bool`; looks up the parent Assignment via `ObjectService::findAll(['register'=>'scholiq','schema'=>'assignment','filters'=>['uuid'=>$assignmentId],'limit'=>1])`; enforces the submission window; branches the target state to `late` when after dueAt + late allowed.
- [x] Create `lib/Plagiarism/ProvidesPlagiarismCheck.php` — pluggable interface (startCheck/fetchReport); no concrete provider ships. Referenced by `Assignment.plagiarismProvider`.
- [x] No `Application.php` registration — guards resolved by OR's lifecycle engine via the FQCN in the schema `requires:`.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add Assignments / AssignmentDetail, Rubrics / RubricDetail, Submissions / SubmissionDetail (index + detail) pages.
- [x] Add `SubmitWorkModal` (custom, component=SubmitWorkModal) and `MarkSubmissionView` (custom, component=MarkSubmissionView) pages.
- [x] Add an "Assignments" nav `menu` entry.
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). No string-array `config.tabs` on detail pages.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/SubmitWorkModal.vue` — upload → save draft → submit (OR REST + attachment API + `submit` transition). Options API + `createObjectStore`; no Pinia module.
- [x] Create `src/views/MarkSubmissionView.vue` — rubric marking → proposedGrade; `// TODO(grading spec): emit GradeEntry`. Dispatch `return` transition on save.
- [x] Register both in `src/main.js` via `customComponents` on `CnAppRoot`.
- [x] `npm run lint` 0 errors; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages + the modal/marking surfaces.

## Phase 6: Spec-validation gate

- [x] `node tests/validate-json-strict.js` PASS (no dup keys; no appendOnly nested in x-openregister).
- [x] `node tests/validate-register.js` PASS (schema shape, slug uniqueness, lifecycle requires → PHP class exists, clobber heuristic).
