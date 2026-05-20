# Tasks — Grading

> Scope: 2 new schemas (GradeScale, GradeEntry, FinalGrade; NotificationPreference NOT added — OR provides it via UserService + BatchNotificationJob), 2 PHP exceptions (GradeFormulaEvaluator + GradeRollupHandler), manifest pages + 2 custom Vue views (GradebookView + GradeImpactDetail), MarkSubmissionView TODO fulfilled, seed data (4 GradeScale + 4 GradeEntry + 4 FinalGrade), l10n (en+nl). Schema count: 22 → 24.

## Phase 0: Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing `GradeEntry`, `GradeScale`, `FinalGrade`, or grade-roll-up capability — confirm no overlap with `ObjectService`, `RegisterService`, `SchemaService`, or `ConfigurationService`. Document findings (even if "no overlap found") as a comment in this file before proceeding.
- [ ] 0.2 Confirm OR already exposes `UserService::getNotificationPreferences` / `setNotificationPreferences` + `BatchNotificationJob` for notification-preference dispatch. If it does, do NOT create a `NotificationPreference` schema (design §1 decision).
- [ ] 0.3 Confirm `GradeFormulaEvaluator` is not already implemented in any existing `lib/` directory or openregister calculation engine.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] 1.1 Add `GradeScale` schema (slug `grade-scale`) per design §2.1: `name` (required), `kind` enum (`numeric`|`letter`|`ects`|`pass-fail`|`percentage`|`band`), `bands[]` (`{ bandId, label, minValue, maxValue, pass }`), `min`, `max`, `passThreshold`, `roundingRule` enum, `tenant_id` (required); lifecycle `draft → active → archived`; calculation `bandCount`.
- [ ] 1.2 Add `GradeEntry` schema (slug `grade-entry`) per design §2.2: `learnerId` (required), `curriculumPlanId` (required), `componentId` (required), `courseId`, `cohortId`, `sourceKind` enum, `submissionId`, `assessmentResultId`, `sessionId`, `value` (required), `gradeScaleId` (required), `weight` (nullable override), `period`, `grader`, `gradedAt`, `comment`, `tenant_id` (required); lifecycle `concept → published → revised` (no back-transition to concept); `x-openregister-notifications.gradePublished` with idempotencyKey `"${@self.id}-${@self.lifecycle}"`; `x-openregister-relations` (learner, curriculumPlan, course, cohort, submission, assessmentResult, session); `x-openregister-calculations` (`effectiveWeight`, `pointsContributed`). Not appendOnly.
- [ ] 1.3 Add `FinalGrade` schema (slug `final-grade`) per design §2.3: `learnerId` (required), `courseId`, `programmeId`, `curriculumPlanId` (required), `gradeScaleId` (required), `tenant_id` (required); no lifecycle; `x-openregister-aggregations` cross-schema pull of published GradeEntries; `x-openregister-calculations` (`value`, `breakdown`, `passed`, `lastRecomputedAt`); `x-openregister-triggers.calculatedChange`. ReadOnly at frontend.
- [ ] 1.4 Add `gradeEntryComponentId` field to `AssessmentResult` schema if not already present.
- [ ] 1.5 Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); confirm no duplicate schema slugs; confirm schema count 22 → 24.

## Phase 2: Seed data in `lib/Settings/scholiq_register.json`

- [ ] 2.1 Add 4 `GradeScale` seed objects per design §8.1: `grade-scale-nl-numeriek` (numeric, 1–10, passThreshold 5.5, half-up-1dp), `grade-scale-ects` (ects, A–F bands with pass flags), `grade-scale-geslaagd-gezakt` (pass-fail, geslaagd/gezakt bands), `grade-scale-percentage` (percentage, 0–100, passThreshold 55).
- [ ] 2.2 Add 4 `GradeEntry` seed objects per design §8.2 using Dutch names (jan.devries, fatima.elamrani, pieter.vandenberg, lisa.bakker), real Dutch period labels (`periode-1`, `periode-2`), and matching `@self` slugs.
- [ ] 2.3 Add 4 `FinalGrade` seed objects per design §8.3, one per learner, referencing the same `curriculumPlanId` and `gradeScaleId`.
- [ ] 2.4 Validate slug uniqueness across all seed objects (`python3 -c` script or manual check). Confirm re-import is idempotent (matched by `@self.slug`).

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [ ] 3.1 Create `lib/Grading/GradeFormulaEvaluator.php` — single public method `evaluate(string $curriculumPlanId, string $learnerId): array`; returns `['value' => float, 'passed' => bool, 'breakdown' => array]`; applies `weighted-average` / `last-attempt` / `best-of-n` / `all-must-pass` formulas; reads CurriculumPlan + published GradeEntries via `ObjectService::find()` / `findAll()`; no state, no audit writes, no side-effects.
- [ ] 3.2 Create `lib/Listener/GradeRollupHandler.php` — listens for `ObjectTransitionedEvent`; on `grade-entry` publish: fetches/creates FinalGrade, calls `GradeFormulaEvaluator::evaluate()`, persists via `ObjectService::saveObject()`, resolves `LearnerProfile.parentIds` and fires `gradePublished` notification for each parent; on `assessment-result` graded: creates concept GradeEntry + sets `AssessmentResult.gradeEntryId`.
- [ ] 3.3 Register `GradeRollupHandler` in `lib/AppInfo/Application.php` via `registerEventListener(ObjectTransitionedEvent::class, GradeRollupHandler::class)`.
- [ ] 3.4 Update `src/views/MarkSubmissionView.vue` — remove `// TODO(grading spec)` comment; on `saveAndReturn`, POST to create a `concept` GradeEntry (`sourceKind: assignment-submission`, `value: proposedGrade`, `componentId: assignment.curriculumPlanComponentId`), then PUT `Submission.gradeEntryId`.
- [ ] 3.5 `./vendor/bin/phpcs lib/` passes; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` passes (0 errors); `php -l` passes on all new files.

## Phase 4: Manifest pages in `src/manifest.json`

- [ ] 4.1 Add standard pages: `GradeScales` (index, schema=GradeScale, route=/grades/scales), `GradeScaleDetail` (detail, route=/grades/scales/:id), `GradeEntries` (index, schema=GradeEntry, route=/grades/entries), `GradeEntryDetail` (detail, route=/grades/entries/:id), `FinalGrades` (index, schema=FinalGrade, readOnly, route=/grades/final), `FinalGradeDetail` (detail, schema=FinalGrade, readOnly, route=/grades/final/:id).
- [ ] 4.2 Add custom pages: `GradebookView` (type=custom, component=GradebookView, route=/grades/cohort/:cohortId/plan/:planId) and `GradeImpactDetail` (type=custom, component=GradeImpactDetail, route=/grades/entries/:id/impact).
- [ ] 4.3 Add nav menu entry `{ id: "grades", label: "grading.nav.grades", route: "GradeEntries", order: 46 }`.
- [ ] 4.4 `node tests/validate-manifest.js` passes (0 Ajv errors). No visibleIf/public set; detail pages do not carry string-array `config.tabs`; custom pages use `{ id, title, type, component }` shape only.

## Phase 5: Frontend Vue

- [ ] 5.1 Create `src/views/GradebookView.vue` — cohort × component grade grid; fetches Cohort (learner list), CurriculumPlan (components), and all GradeEntries for the cohort+plan; cell click creates/updates a `concept` GradeEntry; distribution histogram (value-band buckets) below the grid; "Publish all" button batch-transitions all concept entries to `published`. Options API + `createObjectStore`; no custom Pinia module; all strings via `t(appName, key)`.
- [ ] 5.2 Create `src/views/GradeImpactDetail.vue` — for one published GradeEntry: shows `value`, `effectiveWeight`, `pointsContributed`; period average (fetches sibling published GradeEntries for same period); FinalGrade delta (fetches FinalGrade for learnerId + curriculumPlanId). Read-only. Options API; no custom Pinia module.
- [ ] 5.3 Register `GradebookView` and `GradeImpactDetail` in `src/main.js` via the `customComponents` prop on `CnAppRoot` (or equivalent manifest custom-component registration).
- [ ] 5.4 `npm run lint` exits 0; `npm run stylelint` is clean for new files; `npm run build` succeeds.

## Phase 6: i18n

- [ ] 6.1 Add all new translation keys to `l10n/en.json`: page titles for all 8 manifest pages, grade impact labels (weight, points contributed, period average, final grade delta), gradebook column/row headers, "Publish all" button, distribution histogram label, notification preference labels.
- [ ] 6.2 Add Dutch translations for all new keys to `l10n/nl.json`: use Dutch educational vocabulary (weegfactor, berekend eindcijfer, periode-gemiddelde, definitief publiceren, etc.).

## Phase 7: Spec-validation gate

- [ ] 7.1 `node tests/validate-json-strict.js` passes.
- [ ] 7.2 `node tests/validate-register.js` passes (slug uniqueness; lifecycle `requires:` PHP class exists for any declared guard; no orphan relations).
- [ ] 7.3 `node tests/validate-manifest.js` passes (0 Ajv errors; all custom component IDs match registered `customComponents`).
- [ ] 7.4 `npm run check:manifest` (ADR-024 gate) passes.
