# Tasks — Assignments & Submissions

> Scope: 3 new schemas (Rubric, Assignment, Submission), 2 PHP lifecycle guards + 1 pluggable plagiarism interface, manifest pages + 2 custom Vue views, l10n (en+nl).

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `Rubric` schema per design §1.1 — criteria array with weighted levels, maxPoints; lifecycle draft → active → archived; calculations criterionCount + computedMaxPoints. Required: name, tenant_id.
- [ ] Add `Assignment` schema per design §1.2 — course/session/cohort scope, curriculumPlanComponentId, briefingMaterialIds, dueAt, maxPoints, allowLateSubmission + latePenaltyPercent, rubricId, groupSubmission, visibility window, plagiarismProvider; lifecycle draft → published (via AssignmentPublishGuard) → closed | archived; relations course/session/cohort/rubric/briefingMaterials; calculations isOverdue (dateDiff) + submissionCount + gradedCount. Required: title, tenant_id.
- [ ] Add `Submission` schema per design §1.3 — assignmentId, learnerIds[], attachmentRefs[] (OR attachments, no bytes), submittedAt, feedbackText, rubricScores[], proposedGrade, gradeEntryId (forward-ref); lifecycle draft → submitted → late → returned (via SubmissionWindowGuard); relations assignment/learners; calculations isLate + effectiveGrade. Required: assignmentId, tenant_id. Not appendOnly.
- [ ] Validate JSON (`python3 -c 'import json; json.load(open(...))'`); no duplicate keys; schema count 14 → 17.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/AssignmentPublishGuard.php` — single `check(array &$transitionContext): bool`; returns false unless courseId or sessionId set. No OR queries.
- [ ] Create `lib/Lifecycle/SubmissionWindowGuard.php` — single `check(array &$transitionContext): bool`; looks up the parent Assignment via `ObjectService::findAll(['register'=>'scholiq','schema'=>'assignment','filters'=>['uuid'=>$assignmentId],'limit'=>1])`; enforces the submission window; branches the target state to `late` when after dueAt + late allowed.
- [ ] Create `lib/Plagiarism/ProvidesPlagiarismCheck.php` — pluggable interface (startCheck/fetchReport); no concrete provider ships. Referenced by `Assignment.plagiarismProvider`.
- [ ] No `Application.php` registration — guards resolved by OR's lifecycle engine via the FQCN in the schema `requires:`.
- [ ] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [ ] Add Assignments / AssignmentDetail, Rubrics / RubricDetail, Submissions / SubmissionDetail (index + detail) pages.
- [ ] Add `SubmitWorkModal` (custom, component=SubmitWorkModal) and `MarkSubmissionView` (custom, component=MarkSubmissionView) pages.
- [ ] Add an "Assignments" nav `menu` entry (order=40, route=Assignments).
- [ ] `node tests/validate-manifest.js` PASS (0 Ajv errors). No string-array `config.tabs` on detail pages.

## Phase 4: Frontend Vue + main.js

- [ ] Create `src/views/SubmitWorkModal.vue` — upload → save draft → submit (OR REST + attachment API + `submit` transition). Three steps: file pick, assignment brief review, confirm + submit. Options API + `createObjectStore`; no Pinia module.
- [ ] Create `src/views/MarkSubmissionView.vue` — rubric marking → proposedGrade; `// TODO(grading spec): emit GradeEntry`. Dispatch `return` transition on save.
- [ ] Register both in `src/main.js` via `customComponents` on `CnAppRoot`.
- [ ] `npm run lint` 0 errors; `npm run build` succeeds.

## Phase 5: i18n

- [ ] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages + the modal/marking surfaces.

## Phase 6: Spec-validation gate

- [ ] `node tests/validate-json-strict.js` PASS (no dup keys; no appendOnly nested in x-openregister).
- [ ] `node tests/validate-register.js` PASS (schema shape, slug uniqueness, lifecycle requires → PHP class exists, clobber heuristic).
- [ ] `node tests/validate-manifest.js` PASS (0 Ajv errors).
