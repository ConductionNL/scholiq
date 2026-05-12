# Tasks — Grading (Phase 3)

> Scope: 3 new schemas (GradeScale, GradeEntry, FinalGrade), 2 PHP exceptions (GradeFormulaEvaluator + GradeRollupHandler), manifest pages + 2 custom Vue views, MarkSubmissionView TODO fulfilled, l10n (en+nl).
> NotificationPreference: OR already exposes `UserService::getNotificationPreferences/setNotificationPreferences` + `BatchNotificationJob` — NOT added as a schema. Count: 22 → 24.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `GradeScale` schema per design §2.1 — kind enum, bands array (for letter/ects/band), min/max/passThreshold (for numeric/percentage), roundingRule enum; lifecycle draft → active → archived; calculation bandCount. Required: name, kind, tenant_id.
- [x] Add `GradeEntry` schema per design §2.2 — learnerId, curriculumPlanId, componentId, courseId, cohortId, sourceKind enum, submissionId/assessmentResultId/sessionId, value, gradeScaleId, weight (override), period, grader, gradedAt, comment, tenant_id; lifecycle concept → published → revised; notifications gradePublished with idempotencyKey; relations learner/curriculumPlan/course/cohort/submission/assessmentResult/session; calculations effectiveWeight + pointsContributed. Not appendOnly.
- [x] Add `FinalGrade` schema per design §2.3 — learnerId, courseId, programmeId, curriculumPlanId, gradeScaleId, tenant_id; no lifecycle; cross-schema aggregation over published GradeEntries; calculations value/breakdown/passed/lastRecomputedAt; calculatedChange trigger. ReadOnly.
- [x] Validate JSON (`python3 -c 'import json; json.load(open(...))'`); no duplicate keys; schema count 22 → 24. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Grading/GradeFormulaEvaluator.php` — single `evaluate(string $curriculumPlanId, string $learnerId): array` method; applies weighted-average/last-attempt/best-of-n/all-must-pass formulas over the learner's published GradeEntries; returns `{ value, passed, breakdown }`.
- [x] Create `lib/Listener/GradeRollupHandler.php` — listens for `ObjectTransitionedEvent`; on grade-entry publish, recomputes the FinalGrade via GradeFormulaEvaluator and persists; on assessment-result graded, creates a concept GradeEntry bridge. Parent fan-out for gradePublished notification.
- [x] Register `GradeRollupHandler` in `Application.php` for `ObjectTransitionedEvent`.
- [x] Update `src/views/MarkSubmissionView.vue` — remove `// TODO(grading spec)` comment; write concept GradeEntry + set `Submission.gradeEntryId` on saveAndReturn.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add GradeScales / GradeScaleDetail, GradeEntries / GradeEntryDetail, FinalGrades / FinalGradeDetail (readOnly) pages.
- [x] Add GradebookView (custom, component=GradebookView) and GradeImpactDetail (custom, component=GradeImpactDetail) pages.
- [x] Add "Grades" nav menu entry (order=46, route=GradeEntries).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). No visibleIf/public; no string-array config.tabs; custom widgets {id,title,type} only; actions {id,label,icon?,permission?,primary?,confirm?,handler?} only.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/GradebookView.vue` — cohort × component grid; concept entry create/edit; distribution histogram; "Publish all" batch transition. Options API + createObjectStore; no Pinia module.
- [x] Create `src/views/GradeImpactDetail.vue` — value/effectiveWeight/pointsContributed + period average + final-grade delta. Read-only.
- [x] Register both in `src/main.js` via customComponents.
- [x] `npm run lint` 0 errors; `npm run stylelint` clean for new files; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages and the two custom views.

## Phase 6: Spec-validation gate

- [x] `node tests/validate-json-strict.js` PASS.
- [x] `node tests/validate-register.js` PASS (slug uniqueness, lifecycle requires → PHP class exists).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors).
