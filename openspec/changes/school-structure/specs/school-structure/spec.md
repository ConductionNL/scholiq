---
slug: school-structure
title: School Structure — Programmes, Curriculum Plans, Cohorts, Sessions
status: implemented
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-20
profiles: [pta-vo, oer-he, opleidingsplan-mbo, training-curriculum]
---

# School Structure

## Why

Every educational institution — a school, a university faculty, or a corporate training department — runs the same backbone: a **programme** (a degree / diploma / certification track) is described by a **governing plan** (which courses are required, what the assessment components are, how component grades roll up to a final grade, what the period structure is), learners take courses inside **cohorts** (a klas, a werkgroep, a training group), and a cohort meets in scheduled **sessions** (a les, a hoorcollege, a workshop) that carry materials and assignments. Scholiq's built register has `Course` + `Lesson` but no programme, no governing plan, no cohort, no session — so it can hold *content* but cannot model how a real institution *runs*. This spec adds that backbone in a jurisdiction-neutral way: the Dutch **PTA** (Programma van Toetsing en Afsluiting) is one profile of `CurriculumPlan`; an HE **OER/studiegids**, an MBO **opleidingsplan**, and a corporate **training curriculum** are others.

## What

- **Programme** — a named track (`HBO-V bachelor`, `NIS2 board certification`, `vmbo-tl examenjaar`) that aggregates courses, declares a credential to issue on completion, and points at one `CurriculumPlan`.
- **CurriculumPlan** — the governing document: ordered list of required + elective courses, the assessment-component definitions (with per-component weights, periods, and a roll-up formula), the grading scale, and pass/fail rules. The Dutch PTA's "kolommen met weegfactor per periode feeding the SE-gemiddelde" is exactly this; so is an HE module's "deeltoetsen → eindcijfer" weighting. Consumed by the `grading` spec to compute `FinalGrade`.
- **Course** (extends the existing schema) — collapse the rigid `Course → Module → Lesson` 3-level hierarchy into a **recursive Course** (a Course may contain sub-Courses *or* Lessons directly; a "module" is just a Course used as a container). Add `Course.curriculumPlanId` and `Course.programmeIds`.
- **Cohort** — a group of learners doing a Course/Programme together in a given period, with one or more teachers/instructors. Members are `LearnerProfile`s; backed by an NC group for permissioning.
- **Session** — a scheduled occurrence: cohort + course + start/end datetime + location (room / online URL) + attached `Material`s + linked `Assignment`s. The unit a teacher takes attendance against.
- **Material** — metadata for a file/presentation/reading/video/SCORM-cmi5 package attached to a Course, Lesson, or Session. The bytes live in OpenRegister's native file attachments; this schema carries type, license, LOM-style tags, and ordering.

## ADDED Requirements

### REQ-SS-001 — Programme schema persistence and lifecycle

The system SHALL persist `Programme` objects in the scholiq OpenRegister with lifecycle states `draft`, `published`, and `archived`. The `publish` transition SHALL be guarded by `ProgrammePublishGuard` (ADR-031 lifecycle guard). No PHP CRUD controller SHALL be created for Programme.

#### Scenario SS-001-A: Programme publish blocked — no CurriculumPlan

```
GIVEN a Programme in `draft` state with curriculumPlanId = null
WHEN the `publish` lifecycle transition is requested
THEN the transition SHALL be blocked by ProgrammePublishGuard
  AND the Programme SHALL remain in `draft` state
  AND the response SHALL contain a validation error indicating the CurriculumPlan is missing
```

#### Scenario SS-001-B: Programme publish blocked — CurriculumPlan not published

```
GIVEN a Programme in `draft` state with curriculumPlanId pointing to a CurriculumPlan in `draft` state
WHEN the `publish` lifecycle transition is requested
THEN the transition SHALL be blocked by ProgrammePublishGuard
  AND the Programme SHALL remain in `draft` state
```

#### Scenario SS-001-C: Programme publish blocked — no required courses

```
GIVEN a Programme in `draft` state with curriculumPlanId pointing to a `published` CurriculumPlan
  AND that CurriculumPlan has an empty `requiredCourseIds` array
WHEN the `publish` lifecycle transition is requested
THEN the transition SHALL be blocked by ProgrammePublishGuard
  AND the Programme SHALL remain in `draft` state
```

#### Scenario SS-001-D: Programme publish succeeds

```
GIVEN a Programme in `draft` state with curriculumPlanId pointing to a `published` CurriculumPlan
  AND that CurriculumPlan has at least one entry in `requiredCourseIds`
WHEN the `publish` lifecycle transition is requested
THEN the Programme SHALL transition to `published` state
  AND OR's audit trail SHALL record a `programme.published` transition event
```

#### Scenario SS-001-E: Programme courseCount calculation

```
GIVEN a Programme with 5 UUIDs in `courseIds`
WHEN the OR calculation engine materialises `courseCount`
THEN `courseCount` SHALL equal 5
```

---

### REQ-SS-002 — CurriculumPlan with weighted assessment components

The system SHALL persist `CurriculumPlan` objects with a structured `components` array (assessment-component definitions including `componentId`, `label`, `weight`, `period`, and `kind`), a `formula` enum for grade roll-up, `gradeScaleId`, `passRules`, and `periods`. No PHP CRUD controller SHALL be created for CurriculumPlan.

#### Scenario SS-002-A: CurriculumPlan stores PTA-profile kolom weights

```
GIVEN a CurriculumPlan of kind `pta` with a component of weight 3 in period "1"
WHEN the `grading` spec's FinalGrade computation reads the CurriculumPlan
THEN that component SHALL contribute 3× to the weighted average per its declared weight
```

#### Scenario SS-002-B: CurriculumPlan lifecycle — draft to published

```
GIVEN a CurriculumPlan in `draft` state
WHEN the `publish` transition is requested
THEN the CurriculumPlan SHALL transition to `published` state
```

#### Scenario SS-002-C: CurriculumPlan lifecycle — published to archived

```
GIVEN a CurriculumPlan in `published` state
WHEN the `archive` transition is requested
THEN the CurriculumPlan SHALL transition to `archived` state
```

#### Scenario SS-002-D: CurriculumPlan formula enum constraint

```
GIVEN a CurriculumPlan creation request with formula = "custom-algorithm"
WHEN the OpenRegister schema validator evaluates the object
THEN the request SHALL be rejected with a schema validation error
  AND the error SHALL identify the `formula` field as non-conformant with the enum
```

---

### REQ-SS-003 — Cohort schema with learner membership guard

The system SHALL persist `Cohort` objects with lifecycle states `planned`, `active`, `completed`, and `archived`. The `activate` transition SHALL be blocked by `CohortMembershipGuard` unless `learnerIds` is non-empty. No PHP CRUD controller SHALL be created for Cohort.

#### Scenario SS-003-A: Cohort activate blocked — empty learnerIds

```
GIVEN a Cohort in `planned` state with an empty `learnerIds` array
WHEN the `activate` lifecycle transition is requested
THEN the transition SHALL be blocked by CohortMembershipGuard
  AND the Cohort SHALL remain in `planned` state
```

#### Scenario SS-003-B: Cohort activate succeeds — learners present

```
GIVEN a Cohort in `planned` state with at least one NC user ID in `learnerIds`
WHEN the `activate` lifecycle transition is requested
THEN the Cohort SHALL transition to `active` state
  AND OR's audit trail SHALL record a `cohort.activated` transition event
```

#### Scenario SS-003-C: Cohort learnerCount calculation

```
GIVEN a Cohort with 12 entries in `learnerIds`
WHEN the OR calculation engine materialises `learnerCount`
THEN `learnerCount` SHALL equal 12
```

#### Scenario SS-003-D: Cohort associated with Programme via manifest detail page

```
GIVEN a Cohort with a non-null `programmeId`
WHEN a user opens the CohortDetail manifest page
THEN the `CnDetailCard` section for `programme` SHALL resolve and display the Programme name
```

---

### REQ-SS-004 — Session schema with time-range calculations and material references

The system SHALL persist `Session` objects linked to a Cohort with `startsAt`, `endsAt`, `location`, `materialIds`, and `assignmentIds`. The system SHALL materialise `durationMinutes` and `isPast` as OR calculations. No PHP CRUD controller SHALL be created for Session.

#### Scenario SS-004-A: Session durationMinutes calculation

```
GIVEN a Session with startsAt = "2025-09-15T09:00:00+02:00" and endsAt = "2025-09-15T10:30:00+02:00"
WHEN the OR calculation engine materialises `durationMinutes`
THEN `durationMinutes` SHALL equal 90
```

#### Scenario SS-004-B: Session isPast flag — past session

```
GIVEN a Session with endsAt in the past relative to the current server timestamp
WHEN the OR calculation engine materialises `isPast`
THEN `isPast` SHALL be true
```

#### Scenario SS-004-C: Session isPast flag — future session

```
GIVEN a Session with endsAt in the future relative to the current server timestamp
WHEN the OR calculation engine materialises `isPast`
THEN `isPast` SHALL be false
```

#### Scenario SS-004-D: Session cancellation lifecycle

```
GIVEN a Session in `scheduled` state
WHEN the `cancel` lifecycle transition is requested
THEN the Session SHALL transition to `cancelled` state
  AND OR's audit trail SHALL record the transition event
```

#### Scenario SS-004-E: Session in-progress to completed

```
GIVEN a Session in `in-progress` state
WHEN the `complete` lifecycle transition is requested
THEN the Session SHALL transition to `completed` state
```

---

### REQ-SS-005 — Material schema — metadata only, no file bytes

The system SHALL persist `Material` objects carrying metadata (`title`, `kind`, `fileRef`, `url`, `license`, `lomTags`, `order`) and contextual attachment fields (`courseId`, `lessonId`, `sessionId`). The system MUST NOT store file bytes in the Material schema; bytes SHALL reside in OpenRegister file attachments referenced by `fileRef`. No PHP CRUD controller SHALL be created for Material.

#### Scenario SS-005-A: Material attached to a Session appears in CohortTimetable

```
GIVEN a Session with a `materialIds` array containing one Material UUID (kind=slides)
WHEN a user navigates to the CohortTimetable page for the parent Cohort
THEN the Session's material list SHALL display the Material's title, kind badge, and file reference
```

#### Scenario SS-005-B: Material with kind=link stores URL, not bytes

```
GIVEN a Material with kind = "link" and a non-null `url`
WHEN the Material is persisted
THEN the Material object SHALL be stored with the URL in `url`
  AND `fileRef` SHALL be empty or null (no file bytes stored by the app)
```

---

### REQ-SS-006 — Recursive Course schema

The `Course` schema MUST support a `parentCourseId` self-reference field (uuid|null), enabling a Course to act as a module container for sub-Courses. The Course schema MUST also carry `curriculumPlanId` (uuid|null) and `programmeIds` (uuid[]). All additions MUST be backward-compatible (optional/nullable).

#### Scenario SS-006-A: Course used as a module container

```
GIVEN a parent Course with parentCourseId = null
  AND two child Courses each with parentCourseId set to the parent's UUID
WHEN the OR relations engine resolves `parentCourse` for each child
THEN each child Course's `parentCourse` relation SHALL resolve to the parent Course object
```

#### Scenario SS-006-B: Existing Course objects unaffected

```
GIVEN a Course object created before this change (no parentCourseId, curriculumPlanId, or programmeIds fields)
WHEN the Course is loaded from OpenRegister after the schema patch
THEN the Course SHALL be returned successfully
  AND parentCourseId SHALL default to null
  AND programmeIds SHALL default to []
```

---

### REQ-SS-007 — Enrolment cohort association

The `Enrolment` schema MUST carry a `cohortId` (uuid|null) field and a `cohort` relation linking it to a Cohort object. All additions MUST be backward-compatible.

#### Scenario SS-007-A: Enrolment associated with a Cohort

```
GIVEN an Enrolment with cohortId set to a valid Cohort UUID
WHEN the OR relations engine resolves `cohort`
THEN the Enrolment's `cohort` relation SHALL resolve to the corresponding Cohort object
```

#### Scenario SS-007-B: Existing Enrolments unaffected

```
GIVEN an Enrolment created before this change (no cohortId field)
WHEN the Enrolment is loaded from OpenRegister after the schema patch
THEN the Enrolment SHALL be returned successfully
  AND cohortId SHALL default to null
```

---

### REQ-SS-008 — CohortTimetable custom Vue view

The system SHALL provide a `CohortTimetable` custom Vue page accessible at `/cohorts/:id/timetable` via the manifest. The view SHALL display the cohort's sessions grouped by calendar date, sorted by `startsAt` ascending, with inline display of attached Materials and an assignment count per session.

#### Scenario SS-008-A: Learner views timetable with sessions

```
GIVEN a Cohort with three Sessions scheduled on two different calendar dates
  AND one of the Sessions has two Materials attached via materialIds
WHEN a learner navigates to the CohortTimetable page for that Cohort
THEN the timetable SHALL render two date groups
  AND each group SHALL contain the Sessions scheduled on that date
  AND the Session with two Materials SHALL display two material entries (title + kind badge each)
```

#### Scenario SS-008-B: Timetable renders empty state

```
GIVEN a Cohort with no Sessions
WHEN a learner navigates to the CohortTimetable page for that Cohort
THEN the view SHALL display an empty-state message indicating no sessions are scheduled
```

#### Scenario SS-008-C: Timetable displays session duration

```
GIVEN a Session with durationMinutes = 90
WHEN the CohortTimetable renders that Session
THEN the session card SHALL display the duration (e.g. "90 min" or "1u 30m")
```

---

### REQ-SS-009 — Manifest declarative pages for all new schemas

The system SHALL declare index and detail pages in `src/manifest.json` for Programme, CurriculumPlan, Cohort, Session, and Material schemas, allowing `CnAppRoot` built-in renderers to handle all CRUD. No PHP CRUD controller SHALL be created for any of these five schemas.

#### Scenario SS-009-A: Manifest validates against canonical schema

```
GIVEN the `src/manifest.json` file updated with all school-structure pages
WHEN `node tests/validate-manifest.js` is executed
THEN the script SHALL exit with code 0 (zero Ajv validation errors)
```

#### Scenario SS-009-B: All new schema routes are reachable via the manifest

```
GIVEN the manifest declares index + detail pages for Programme, CurriculumPlan, Cohort, Session, Material
  AND the CohortTimetable custom page
WHEN a user navigates to each of these routes in the browser
THEN each route SHALL load without a 404 or routing error
```

---

## Standards

Schema.org `EducationalOccupationalProgram`, `Course`, `CourseInstance`, `Syllabus`; NL LOM / VDEX for Material tags; ECTS / Bologna for HE workload; NL VO PTA convention as a `CurriculumPlan` profile; OOAPI 5.0 for HE catalog publication (deferred to a follow-up — out of scope here).

## Out of Scope

- Room-booking conflict resolution and timetabling optimisation (a Session just records a location string).
- OOAPI 5.0 catalog publication endpoints (separate follow-up).
- The actual content runtime — cmi5/xAPI/SCORM execution is `course-management`'s job.
- Grading computation — that's the `grading` spec; this spec only *declares* the weighting in the CurriculumPlan.
- NC group provisioning (ncGroupId sync) on Cohort activation — deferred to a future event listener.
