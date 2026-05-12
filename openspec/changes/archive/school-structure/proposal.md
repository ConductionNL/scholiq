## Why

Every educational institution runs the same backbone: a Programme (a degree / diploma / certification track) is governed by a CurriculumPlan (assessment components, weights, periods, pass rules), learners take courses inside Cohorts (a klas, werkgroep, or training group), and a Cohort meets in scheduled Sessions (a les, hoorcollege, or workshop) that carry Materials and forward-reference Assignments. Scholiq's built register already has Course and Lesson but has no Programme, no governing plan, no cohort, no session — so it can hold content but cannot model how a real institution runs. This spec adds that backbone in a jurisdiction-neutral way: the Dutch PTA (Programma van Toetsing en Afsluiting) is one profile of CurriculumPlan; an HE OER/studiegids, an MBO opleidingsplan, and a corporate training curriculum are others.

## What Changes

### New Schemas (5)

- **Programme** (slug `programme`) — a named track aggregating courses, declaring a credential to issue on completion, and pointing at one CurriculumPlan. Lifecycle: draft → published → archived. `ProgrammePublishGuard` (ADR-031 exception) blocks publish unless the assigned CurriculumPlan is published and has ≥1 required course.
- **CurriculumPlan** (slug `curriculum-plan`) — the governing document: structured component list (`{ componentId, label, weight, period, kind: assignment|assessment|participation }[]`), roll-up formula (weighted-average | last-attempt | best-of-n | all-must-pass), gradeScaleId, passRules, periods. Lifecycle: draft → published → archived. Consumed by the grading spec.
- **Cohort** (slug `cohort`) — a group of learners doing a Course/Programme together in a given period. Backed by a Nextcloud group (ncGroupId) for permissioning. `CohortMembershipGuard` (ADR-031 exception) blocks activate unless learnerIds is non-empty. Lifecycle: planned → active → completed | archived.
- **Session** (slug `session`) — a scheduled occurrence: cohort + course + start/end datetime + location + materialIds + assignmentIds. Lifecycle: scheduled → in-progress → completed | cancelled. Calculations: durationMinutes, isPast.
- **Material** (slug `material`) — metadata for a file/presentation/reading/video/SCORM-cmi5 package. Bytes live in OpenRegister file attachments; this schema carries kind, fileRef, url, license, lomTags, order, and contextual attachments (courseId | lessonId | sessionId).

### Modified Schemas (2)

- **Course** — adds `parentCourseId` (self-reference, making Course recursive), `curriculumPlanId`, `programmeIds`. Adds `x-openregister-relations` for parentCourse, curriculumPlan, and programmes. Does NOT change lifecycle, calculations, aggregations, or notifications.
- **Enrolment** — adds `cohortId` (uuid|null). Adds `cohort` relation in `x-openregister-relations`. Does NOT change lifecycle, calculations, or notifications.

### New PHP (2, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/ProgrammePublishGuard.php` — single `check()` method. Blocks Programme publish unless its CurriculumPlan is published and has ≥1 requiredCourseId. Uses `ObjectService::findAll()`.
- `lib/Lifecycle/CohortMembershipGuard.php` — single `check()` method. Blocks Cohort activate unless learnerIds is non-empty.

### New Frontend

- 11 manifest pages: Programmes (index+detail), CurriculumPlans (index+detail), Cohorts (index+detail), CohortTimetable (custom), Sessions (index+detail), Materials (index+detail).
- 1 custom Vue view: `src/views/CohortTimetable.vue` — date-grouped timetable with inline materials + assignment counts. Options API + `createObjectStore` pattern; no custom Pinia store modules.
- 1 nav menu entry: "Curriculum" (order 45) routing to Programmes.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages, timetable UI strings.

## Capabilities

### New Capabilities

- `school-structure`: Programme, CurriculumPlan, Cohort, Session, Material schemas + recursive Course. Declarative lifecycle, relations, and calculations on all schemas. Two ADR-031 PHP guards. Manifest pages for all entities. Custom CohortTimetable Vue view.

### Modified Capabilities

- `course-management` (existing): Course extended with parentCourseId + curriculumPlanId + programmeIds; Course is now recursive (a Course may contain sub-Courses or Lessons directly).
- `enrolment` (existing): Enrolment extended with cohortId field + cohort relation.

## Impact

- Adding new schemas to `scholiq_register.json` is additive; no existing data is touched. Schema count goes from 9 to 14.
- Course schema additions are backward-compatible (all new fields are optional / nullable).
- Enrolment schema addition is backward-compatible (cohortId is optional/nullable).
- No PHP controllers added; no OR REST API bypassed.
- CohortMembershipGuard defers NC group provisioning to a future event listener (intentionally minimal scope).
