# Tasks — School Structure (Phase 2 Foundation)

> Scope: 5 new schemas (Programme, CurriculumPlan, Cohort, Session, Material), 2 modified schemas (Course + Enrolment field additions), 2 PHP guards, 11 manifest pages + 1 custom Vue view, l10n (en+nl).

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `Programme` schema per design §1.1 — lifecycle (draft → published via ProgrammePublishGuard → archived ↔), relations (curriculumPlan, courses many-to-many), calculations (courseCount). Required: name, level, tenant_id.
- [x] Add `CurriculumPlan` schema per design §1.2 — components array ({componentId, label, weight, period, kind: assignment|assessment|participation}), formula enum (weighted-average|last-attempt|best-of-n|all-must-pass), gradeScaleId, passRules, periods, lifecycle (draft → published → archived ↔). Required: name, kind, formula, tenant_id.
- [x] Add `Cohort` schema per design §1.3 — lifecycle (planned → active via CohortMembershipGuard → completed → archived; planned|completed → archived), relations (programme, course), calculations (learnerCount). Required: name, period, academicYear, tenant_id.
- [x] Add `Session` schema per design §1.4 — lifecycle (scheduled → in-progress → completed; scheduled|in-progress → cancelled), relations (cohort, course, lesson, materials many-to-many), calculations (durationMinutes, isPast). Required: cohortId, title, startsAt, endsAt, tenant_id.
- [x] Add `Material` schema per design §1.5 — kind enum (slides|reading|video|scorm|cmi5|lti|link|document|other), fileRef (OR attachment ref, not bytes), url (for kind=link), lomTags, order, contextual IDs (courseId|lessonId|sessionId), relations (course, lesson, session). Required: title, kind, fileRef, tenant_id.
- [x] Validate JSON: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'` PASS.
- [x] Check no duplicate keys with `object_pairs_hook`. PASS.
- [x] Verify schema count: 9 → 14. CONFIRMED.

## Phase 2: Modified schemas in `lib/Settings/scholiq_register.json`

- [x] Add `Course.parentCourseId` (uuid|null — self-reference for recursive Course), `Course.curriculumPlanId` (uuid|null), `Course.programmeIds` (uuid[]).
- [x] Add `x-openregister-relations` to Course: parentCourse (self-ref), curriculumPlan, programmes (many-to-many). No changes to existing lifecycle/calculations/aggregations/notifications.
- [x] Add `Enrolment.cohortId` (uuid|null) and cohort relation to Enrolment's `x-openregister-relations`. No changes to existing Enrolment fields.

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Lifecycle/ProgrammePublishGuard.php` — single `check(array &$transitionContext): bool` method. Uses `ObjectService::findAll(['register'=>'scholiq','schema'=>'CurriculumPlan','filters'=>['uuid'=>$curriculumPlanId,'lifecycle'=>'published'],'limit'=>1])`. Checks `requiredCourseIds` non-empty. Logs info on block. No registration needed in Application.php — OR resolves guards via DI by FQCN declared in schema.
- [x] Create `lib/Lifecycle/CohortMembershipGuard.php` — single `check(array &$transitionContext): bool` method. Checks `!empty($object['learnerIds'])`. No OR queries.
- [x] Run `./vendor/bin/phpcs lib/Lifecycle/ProgrammePublishGuard.php lib/Lifecycle/CohortMembershipGuard.php`. PASS (after auto-fix).
- [x] Run `./vendor/bin/phpstan analyse lib/ -c phpstan.neon`. PASS (0 errors).
- [x] Verify `php -l` passes for both files.

## Phase 4: Manifest pages in `src/manifest.json`

- [x] Add 11 pages: Programmes (index), ProgrammeDetail (detail), CurriculumPlans (index), CurriculumPlanDetail (detail), Cohorts (index), CohortDetail (detail), CohortTimetable (custom, component=CohortTimetable), Sessions (index), SessionDetail (detail), Materials (index), MaterialDetail (detail).
- [x] Add nav menu entry "Curriculum" (order 45) routing to Programmes.
- [x] Run `node tests/validate-manifest.js` (with APP_MANIFEST_SCHEMA env var pointing at nc-vue schema). PASS (0 Ajv errors).

## Phase 5: Custom Vue view

- [x] Create `src/views/CohortTimetable.vue` — Options API, date-grouped timetable, OR REST API calls via generateUrl, per-session materials + assignment count display. WCAG-friendly (aria-live, role=alert, sr-only spans).
- [x] Register `CohortTimetable` in `src/main.js` customComponents.
- [x] Run `node_modules/.bin/eslint src/` from worktree. 0 errors (13 pre-existing warnings).
- [x] Fix pre-existing `quote-props` errors in `src/views/AuditPackExportModal.vue`. DONE.
- [x] Run `npm run build`. PASS (warnings are pre-existing module-not-found from nc-vue dist build).

## Phase 6: i18n

- [x] Add new keys to `l10n/en.json`: page titles, timetable UI strings (loading, error, empty-state, materials, assignments plural, opens-in-new-tab).
- [x] Add new keys to `l10n/nl.json` with Dutch translations.

## Phase 7: OpenSpec change documentation

- [x] Write `openspec/changes/school-structure/proposal.md` — why, what, capabilities, impact.
- [x] Write `openspec/changes/school-structure/design.md` — declarative-vs-imperative decisions, schema designs, PHP guard rationale, manifest page table, Vue view design.
- [x] Write `openspec/changes/school-structure/tasks.md` — this file.
- [x] Copy spec from `openspec/specs/school-structure/spec.md` to `openspec/changes/school-structure/specs/school-structure/spec.md` with ADDED Requirements block in strict SHA-MUST / GIVEN-WHEN-THEN format.

## Phase 8: Quality gate

- [x] JSON valid, no dup keys, schema count 9→14.
- [x] `node tests/validate-manifest.js` PASS.
- [x] `./vendor/bin/phpcs lib/` PASS.
- [x] `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors).
- [x] ESLint `src/` from worktree: 0 errors.
- [x] `npm run build` PASS.
- [ ] Remove vendor symlink before committing: `rm vendor` in worktree.
