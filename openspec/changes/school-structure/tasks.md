# Tasks — School Structure (Phase 2 Foundation)

> Scope: 5 new schemas (Programme, CurriculumPlan, Cohort, Session, Material), 2 modified schemas (Course + Enrolment field additions), 2 PHP lifecycle guards, 11 manifest pages + 1 custom Vue view, seed data, l10n (en + nl).

---

## Phase 0: Deduplication Check

- [ ] Search `openspec/specs/` for any existing Programme, Cohort, Session, or Material schema definitions. Confirm no overlap before adding schemas. Document finding: "no overlap found" or reference the overlap and justify.
- [ ] Grep `lib/Service/` for any existing service implementing programme, cohort, session, or material logic. If found, evaluate whether to consume rather than duplicate. Document finding.

---

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `Programme` schema per design §1.1 — lifecycle (draft → published via `ProgrammePublishGuard` → archived; archived ↔ published), relations (`curriculumPlan`, `courses` many-to-many), calculation (`courseCount`). Required: `name`, `level`, `tenant_id`.
- [ ] Add `CurriculumPlan` schema per design §1.2 — `components` array (`{componentId, label, weight, period, kind: assignment|assessment|participation}`), `formula` enum (`weighted-average|last-attempt|best-of-n|all-must-pass`), `gradeScaleId`, `passRules`, `periods`, lifecycle (draft → published → archived ↔). Required: `name`, `kind`, `formula`, `tenant_id`.
- [ ] Add `Cohort` schema per design §1.3 — lifecycle (planned → active via `CohortMembershipGuard` → completed → archived; planned → archived), relations (`programme`, `course`), calculation (`learnerCount`). Required: `name`, `period`, `academicYear`, `tenant_id`.
- [ ] Add `Session` schema per design §1.4 — lifecycle (scheduled → in-progress → completed; scheduled|in-progress → cancelled), relations (`cohort`, `course`, `lesson`, `materials` many-to-many), calculations (`durationMinutes`, `isPast`). Required: `cohortId`, `title`, `startsAt`, `endsAt`, `tenant_id`.
- [ ] Add `Material` schema per design §1.5 — `kind` enum (slides|reading|video|scorm|cmi5|lti|link|document|other), `fileRef` (OR attachment ref — NOT bytes), `url` (for kind=link), `lomTags`, `order`, contextual IDs (`courseId|lessonId|sessionId`), relations (`course`, `lesson`, `session`). Required: `title`, `kind`, `fileRef`, `tenant_id`.
- [ ] Add seed objects (per design §7) for all 5 new schemas: 3 Programme, 2 CurriculumPlan, 3 Cohort, 3 Session, 4 Material — all using the `@self` envelope with realistic Dutch values.
- [ ] Validate JSON: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`. Confirm PASS.
- [ ] Check for duplicate keys: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"), object_pairs_hook=lambda pairs: dict(pairs))'`. Confirm PASS.
- [ ] Verify schema count incremented from 9 to 14.

---

## Phase 2: Modified schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `Course.parentCourseId` (uuid|null — self-reference for recursive Course), `Course.curriculumPlanId` (uuid|null), and `Course.programmeIds` (uuid[]). All nullable/optional — backward-compatible.
- [ ] Add `x-openregister-relations` entries to Course: `parentCourse` (self-reference via `parentCourseId`), `curriculumPlan` (via `curriculumPlanId`), `programmes` (many-to-many via `programmeIds`). Do NOT modify existing lifecycle, calculations, aggregations, or notifications on Course.
- [ ] Add `Enrolment.cohortId` (uuid|null) field and `cohort` relation to Enrolment's `x-openregister-relations` block. Do NOT modify any existing Enrolment fields, lifecycle, calculations, or notifications.
- [ ] Re-validate JSON after modifications (same commands as Phase 1). Confirm PASS.

---

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/ProgrammePublishGuard.php` implementing the lifecycle guard interface. Single public `check(array &$transitionContext): bool` method. Logic: (1) retrieve `curriculumPlanId` from the Programme object; (2) call `ObjectService::findAll(['register'=>'scholiq','schema'=>'CurriculumPlan','filters'=>['uuid'=>$curriculumPlanId,'lifecycle'=>'published'],'limit'=>1])` — return `false` if empty; (3) check that `requiredCourseIds` on the found CurriculumPlan is non-empty — return `false` if empty; (4) return `true`. No Application.php registration needed — OR resolves guards via DI by FQCN declared in schema.
- [ ] Add `@spec openspec/changes/school-structure/tasks.md#phase-3` PHPDoc tag to both guard classes (per ADR-003 spec traceability requirement).
- [ ] Create `lib/Lifecycle/CohortMembershipGuard.php` implementing the lifecycle guard interface. Single public `check(array &$transitionContext): bool` method. Logic: return `!empty($object['learnerIds'])`. No OR queries.
- [ ] Run `./vendor/bin/phpcs lib/Lifecycle/ProgrammePublishGuard.php lib/Lifecycle/CohortMembershipGuard.php`. Confirm PASS (auto-fix allowed).
- [ ] Run `./vendor/bin/phpstan analyse lib/ -c phpstan.neon`. Confirm PASS (0 errors).
- [ ] Run `php -l lib/Lifecycle/ProgrammePublishGuard.php lib/Lifecycle/CohortMembershipGuard.php`. Confirm both parse without errors.

---

## Phase 4: Manifest pages in `src/manifest.json`

- [ ] Add 11 pages to `src/manifest.json`:
  - `Programmes` (type: `index`, route: `/curriculum/programmes`, schema: `programme`)
  - `ProgrammeDetail` (type: `detail`, route: `/curriculum/programmes/:id`, schema: `programme`)
  - `CurriculumPlans` (type: `index`, route: `/curriculum/plans`, schema: `curriculum-plan`)
  - `CurriculumPlanDetail` (type: `detail`, route: `/curriculum/plans/:id`, schema: `curriculum-plan`)
  - `Cohorts` (type: `index`, route: `/cohorts`, schema: `cohort`)
  - `CohortDetail` (type: `detail`, route: `/cohorts/:id`, schema: `cohort`)
  - `CohortTimetable` (type: `custom`, route: `/cohorts/:id/timetable`, component: `CohortTimetable`)
  - `Sessions` (type: `index`, route: `/sessions`, schema: `session`)
  - `SessionDetail` (type: `detail`, route: `/sessions/:id`, schema: `session`)
  - `Materials` (type: `index`, route: `/materials`, schema: `material`)
  - `MaterialDetail` (type: `detail`, route: `/materials/:id`, schema: `material`)
- [ ] Add nav menu entry: `{ "label": "Curriculum", "order": 45, "icon": "SchoolOutline", "route": "/curriculum/programmes" }`.
- [ ] Run `node tests/validate-manifest.js`. Confirm PASS (0 Ajv errors).

---

## Phase 5: Custom Vue view — CohortTimetable

- [ ] Create `src/views/CohortTimetable.vue` using Options API:
  - `props: { cohortId: String }` (received from manifest/router).
  - `data()`: `{ cohort: null, sessions: [], materialsBySession: {}, loading: true, error: null }`.
  - `created()`: parallel fetch of Cohort object + Sessions filtered by `cohortId` sorted by `startsAt:asc` via `generateUrl('/apps/scholiq') + OR REST API`.
  - After sessions load: parallel fetch Materials for each session (`materialIds`).
  - Computed `sessionsByDate`: group sessions by `startsAt.toLocaleDateString('nl-NL')`.
  - Template: `v-for` over date groups → date header → `v-for` sessions → session card (time range, `durationMinutes` from `isPast` hint, title, location, material list with kind badge, assignment count).
  - Empty state: `CnEmptyState` with message key `no_sessions_scheduled`.
  - Loading state: `NcLoadingIcon`.
  - Error state: `NcEmptyContent` with error message.
  - WCAG: `aria-live="polite"` on loading region, `role="list"` on session list, `<span class="sr-only">` for screen-reader-only text.
  - ALL user-visible strings via `t(appName, 'key')` — no hardcoded Dutch strings.
- [ ] Register `CohortTimetable` in `src/main.js` `customComponents` option passed to `CnAppRoot`.
- [ ] Run `node_modules/.bin/eslint src/views/CohortTimetable.vue`. Confirm 0 errors.
- [ ] Run `npm run build`. Confirm PASS.

---

## Phase 6: i18n

- [ ] Add new translation keys to `l10n/en.json`:
  - Page titles: `Programmes`, `Programme Detail`, `Curriculum Plans`, `Curriculum Plan Detail`, `Cohorts`, `Cohort Detail`, `Cohort Timetable`, `Sessions`, `Session Detail`, `Materials`, `Material Detail`.
  - Timetable UI: `no_sessions_scheduled`, `loading_timetable`, `timetable_error`, `materials_count` (plural), `assignments_count` (plural), `duration_minutes`, `opens_in_new_tab`.
- [ ] Add corresponding Dutch translations to `l10n/nl.json`:
  - `Programmes` → `Programma's`, `Curriculum Plans` → `Leerplannen`, `Cohorts` → `Cohorten`, `Sessions` → `Sessies`, `Materials` → `Materialen`, `Cohort Timetable` → `Cohortrooster`.
  - `no_sessions_scheduled` → `Geen sessies gepland voor dit cohort.`, etc.

---

## Phase 7: OpenSpec change documentation

- [ ] Confirm `openspec/changes/school-structure/proposal.md` exists and reflects design §"What Changes" accurately.
- [ ] Confirm `openspec/changes/school-structure/design.md` exists with: declarative-vs-imperative decision table, all 5 schema definitions, seed data section (≥3 objects per schema, Dutch values), reuse analysis, PHP guard rationale, manifest page table, and CohortTimetable Vue view design.
- [ ] Confirm `openspec/changes/school-structure/specs/school-structure/spec.md` exists with REQ-SS-001 through REQ-SS-009 in strict REQ-XXX-NNN / GIVEN-WHEN-THEN format.
- [ ] Confirm `openspec/changes/school-structure/tasks.md` (this file) is complete with all phases checked.

---

## Phase 8: Quality Gates

- [ ] JSON valid: `python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'` — PASS.
- [ ] No duplicate keys in register JSON — PASS.
- [ ] Schema count 9 → 14 confirmed.
- [ ] `node tests/validate-manifest.js` — PASS (0 Ajv errors).
- [ ] `./vendor/bin/phpcs lib/` — PASS (no CS errors after auto-fix).
- [ ] `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` — PASS (0 errors).
- [ ] ESLint `src/` — 0 errors (pre-existing warnings do not block).
- [ ] `npm run build` — PASS.
- [ ] Remove vendor symlink before committing if present: `rm vendor` in worktree.
