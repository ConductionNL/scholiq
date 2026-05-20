## Why

Every educational institution runs the same backbone: a Programme (a degree / diploma / certification track) is governed by a CurriculumPlan (assessment components, weights, periods, pass rules), learners take courses inside Cohorts (a klas, werkgroep, or training group), and a Cohort meets in scheduled Sessions (a les, hoorcollege, or workshop) that carry Materials and forward-reference Assignments. Scholiq's built register already has Course and Lesson but has no Programme, no governing plan, no Cohort, no Session — so it can hold content but cannot model how a real institution runs. This spec adds that backbone in a jurisdiction-neutral way: the Dutch PTA (Programma van Toetsing en Afsluiting) is one profile of CurriculumPlan; an HE OER/studiegids, an MBO opleidingsplan, and a corporate training curriculum are others.

## What Changes

### New Schemas (5)

- **Programme** (slug `programme`) — a named track (`HBO-V bachelor`, `NIS2 board certification`, `vmbo-tl examenjaar`) aggregating courses, declaring a credential to issue on completion, and pointing at one CurriculumPlan. Lifecycle: draft → published (via `ProgrammePublishGuard`) → archived. `ProgrammePublishGuard` (ADR-031 exception) blocks publish unless the assigned CurriculumPlan is published and has ≥1 required course.
- **CurriculumPlan** (slug `curriculum-plan`) — the governing document: structured component list (`{ componentId, label, weight, period, kind: assignment|assessment|participation }[]`), roll-up formula (`weighted-average | last-attempt | best-of-n | all-must-pass`), gradeScaleId, passRules, and periods. The Dutch PTA's "kolommen met weegfactor per periode feeding the SE-gemiddelde" is exactly this shape. Lifecycle: draft → published → archived. Consumed by the `grading` spec to compute `FinalGrade`.
- **Cohort** (slug `cohort`) — a group of learners doing a Course/Programme together in a given academic period, with one or more teachers. Members are LearnerProfile owners (NC user IDs); backed by a Nextcloud group (ncGroupId) for permissioning. `CohortMembershipGuard` (ADR-031 exception) blocks activate unless learnerIds is non-empty. Lifecycle: planned → active → completed | archived.
- **Session** (slug `session`) — a scheduled occurrence: cohort + course + start/end datetime + location (room name or online URL) + materialIds + assignmentIds. The unit a teacher takes attendance against. Lifecycle: scheduled → in-progress → completed | cancelled. Calculations: durationMinutes, isPast.
- **Material** (slug `material`) — metadata for a file/presentation/reading/video/SCORM-cmi5 package. The bytes live in OpenRegister file attachments; this schema carries kind, fileRef, url, license, lomTags (NL-LOM/VDEX), order, and contextual attachment fields (courseId | lessonId | sessionId).

### Modified Schemas (2)

- **Course** — adds `parentCourseId` (uuid|null — self-reference making Course recursive; a "module" is a Course used as a container), `curriculumPlanId` (uuid|null), and `programmeIds` (uuid[]). Adds `x-openregister-relations` entries for parentCourse (self), curriculumPlan, and programmes (many-to-many). Existing lifecycle, calculations, aggregations, and notifications are unchanged.
- **Enrolment** — adds `cohortId` (uuid|null). Adds `cohort` relation to `x-openregister-relations`. All existing fields, lifecycle, calculations, and notifications unchanged.

### New PHP (2 — ADR-031 legitimate exceptions only)

- `lib/Lifecycle/ProgrammePublishGuard.php` — single `check()` method. Uses `ObjectService::findAll()` to verify the referenced CurriculumPlan is in `published` state and has ≥1 `requiredCourseId`. OR-queries make this an imperative guard rather than a declarative lifecycle condition.
- `lib/Lifecycle/CohortMembershipGuard.php` — single `check()` method. Checks `!empty($object['learnerIds'])`. Kept as a PHP guard for lifecycle-contract consistency; no OR queries needed.

### New Frontend

- 11 manifest pages in `src/manifest.json`: Programmes (index + detail), CurriculumPlans (index + detail), Cohorts (index + detail), CohortTimetable (custom), Sessions (index + detail), Materials (index + detail).
- 1 custom Vue view: `src/views/CohortTimetable.vue` — date-grouped timetable with inline materials + assignment counts. Options API + direct OR REST API calls via `generateUrl`. No custom Pinia store modules.
- 1 nav menu entry: "Curriculum" (order 45) routing to Programmes.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages and the CohortTimetable UI strings.

## Capabilities

### New Capabilities

- `school-structure`: Programme, CurriculumPlan, Cohort, Session, and Material schemas with declarative lifecycle, relations, and calculations in `scholiq_register.json`. Two ADR-031 PHP guards. Manifest pages for all five entities. Custom `CohortTimetable` Vue view.

### Modified Capabilities

- `course-management` (existing): Course extended with `parentCourseId` + `curriculumPlanId` + `programmeIds`; Course is now recursive (a Course may contain sub-Courses or Lessons directly). A "module" is simply a Course used as a container — no separate Module entity.
- `enrolment` (existing): Enrolment extended with `cohortId` field and corresponding `cohort` relation.

## Impact

- Adding new schemas to `scholiq_register.json` is additive; no existing data is touched. Schema count goes from 9 to 14.
- Course schema additions are backward-compatible (all new fields are optional / nullable).
- Enrolment schema addition is backward-compatible (`cohortId` is optional/nullable).
- No PHP CRUD controllers added; no OR REST API bypassed.
- NC group provisioning (ncGroupId sync) on Cohort activation deferred to a future event listener — intentionally minimal scope.
- The `grading` spec depends on `CurriculumPlan.components` and `CurriculumPlan.formula` being present to compute `FinalGrade`; this change satisfies that dependency.
