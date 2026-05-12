---
slug: school-structure
title: School Structure — Programmes, Curriculum Plans, Cohorts, Sessions
status: implemented
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [pta-vo, oer-he, opleidingsplan-mbo, training-curriculum]
---

# School Structure

## Why

Every educational institution — a school, a university faculty, or a corporate training department — runs the same backbone: a **programme** (a degree / diploma / certification track) is described by a **governing plan** (which courses are required, what the assessment components are, how component grades roll up to a final grade, what the period structure is), learners take courses inside **cohorts** (a klas, a werkgroep, a training group), and a cohort meets in scheduled **sessions** (a les, a hoorcollege, a workshop) that carry materials and assignments. Scholiq's built register has `Course` + `Lesson` but no programme, no governing plan, no cohort, no session — so it can hold *content* but cannot model how a real institution *runs*. This spec adds that backbone in a jurisdiction-neutral way: the Dutch **PTA** (Programma van Toetsing en Afsluiting) is one profile of `CurriculumPlan`; an HE **OER/studiegids**, an MBO **opleidingsplan**, and a corporate **training curriculum** are others.

## ADDED Requirements

### Requirement: Programme schema persistence

The system SHALL persist `Programme` objects in the scholiq OpenRegister with lifecycle states draft, published, and archived.

#### Scenario: Programme publish blocked without CurriculumPlan

GIVEN a Programme with no curriculumPlanId set
WHEN the `publish` lifecycle transition is requested
THEN the transition SHALL be blocked by ProgrammePublishGuard and the Programme SHALL remain in `draft` state.

#### Scenario: Programme publish blocked with unpublished CurriculumPlan

GIVEN a Programme with a curriculumPlanId pointing to a CurriculumPlan in `draft` state
WHEN the `publish` lifecycle transition is requested
THEN the transition SHALL be blocked by ProgrammePublishGuard and the Programme SHALL remain in `draft` state.

#### Scenario: Programme publish succeeds

GIVEN a Programme with a curriculumPlanId pointing to a `published` CurriculumPlan that has at least one requiredCourseId
WHEN the `publish` lifecycle transition is requested
THEN the Programme SHALL transition to `published` state.

---

### Requirement: CurriculumPlan schema with weighted assessment components

The system SHALL persist `CurriculumPlan` objects with a structured `components` array defining assessment components (kolommen), a `formula` enum for grade roll-up, `gradeScaleId`, `passRules`, and `periods`.

#### Scenario: CurriculumPlan stores PTA-profile component weights

GIVEN a CurriculumPlan of kind `pta` with a component of weight 3 and period 1
WHEN the grading spec computes a period average
THEN that component SHALL contribute 3× to the weighted average per the declared weight.

#### Scenario: CurriculumPlan lifecycle transitions

GIVEN a CurriculumPlan in `draft` state
WHEN the `publish` transition is requested
THEN the CurriculumPlan SHALL transition to `published` state.

GIVEN a CurriculumPlan in `published` state
WHEN the `archive` transition is requested
THEN the CurriculumPlan SHALL transition to `archived` state.

---

### Requirement: Cohort schema with learner membership guard

The system SHALL persist `Cohort` objects with lifecycle states planned, active, completed, and archived. The `activate` transition SHALL be blocked unless the Cohort has at least one learner in learnerIds.

#### Scenario: Cohort activate blocked with no learners

GIVEN a Cohort in `planned` state with an empty learnerIds array
WHEN the `activate` lifecycle transition is requested
THEN the transition SHALL be blocked by CohortMembershipGuard and the Cohort SHALL remain in `planned` state.

#### Scenario: Cohort activate succeeds with learners

GIVEN a Cohort in `planned` state with at least one learner ID in learnerIds
WHEN the `activate` lifecycle transition is requested
THEN the Cohort SHALL transition to `active` state.

#### Scenario: Cohort learnerCount calculation

GIVEN a Cohort with 12 entries in learnerIds
WHEN the OR calculation engine materialises learnerCount
THEN learnerCount SHALL equal 12.

---

### Requirement: Session schema with time-range and material references

The system SHALL persist `Session` objects linked to a Cohort with startsAt, endsAt, location, materialIds, and assignmentIds. The system SHALL materialise `durationMinutes` and `isPast` calculations.

#### Scenario: Session durationMinutes calculation

GIVEN a Session with startsAt 09:00 and endsAt 10:30 on the same date
WHEN the OR calculation engine materialises durationMinutes
THEN durationMinutes SHALL equal 90.

#### Scenario: Session isPast flag

GIVEN a Session with endsAt in the past relative to the current timestamp
WHEN the OR calculation engine materialises isPast
THEN isPast SHALL be true.

#### Scenario: Session cancelled lifecycle

GIVEN a Session in `scheduled` state
WHEN the `cancel` lifecycle transition is requested
THEN the Session SHALL transition to `cancelled` state.

---

### Requirement: Material schema — metadata only, no file bytes

The system SHALL persist `Material` objects carrying metadata (title, kind, fileRef, url, license, lomTags, order) and contextual attachment fields (courseId, lessonId, sessionId). The system MUST NOT store file bytes in the Material schema; bytes SHALL reside in OpenRegister file attachments referenced by fileRef.

#### Scenario: Material attached to a Session appears in timetable

GIVEN a teacher attaches a Material with kind=slides to a Session
WHEN a learner views the CohortTimetable page for that Cohort
THEN the Session's material list SHALL display the Material's title, kind badge, and a link or file reference.

---

### Requirement: Recursive Course schema

The `Course` schema MUST support a `parentCourseId` self-reference field (uuid|null), enabling a Course to act as a module container for sub-Courses. The Course schema MUST also carry `curriculumPlanId` (uuid|null) and `programmeIds` (uuid[]).

#### Scenario: Course used as module container

GIVEN a parent Course with parentCourseId=null and two child Courses with parentCourseId set to the parent's UUID
WHEN the relations engine resolves parentCourse
THEN each child Course's parentCourse relation SHALL resolve to the parent Course object.

---

### Requirement: Enrolment cohort association

The `Enrolment` schema MUST carry a `cohortId` (uuid|null) field and a `cohort` relation linking it to a Cohort object.

#### Scenario: Enrolment associated with a Cohort

GIVEN an Enrolment with cohortId set to a valid Cohort UUID
WHEN the relations engine resolves cohort
THEN the Enrolment's cohort relation SHALL resolve to the corresponding Cohort object.

---

### Requirement: CohortTimetable custom view

The system SHALL provide a `CohortTimetable` custom Vue page accessible at `/cohorts/:id/timetable`. The view SHALL display the cohort's sessions grouped by calendar date, sorted by startsAt ascending, with inline display of attached Materials and an assignment count per session.

#### Scenario: Learner views timetable

GIVEN a Cohort with three Sessions scheduled on two different dates and two Materials on one Session
WHEN a learner navigates to the CohortTimetable page for that Cohort
THEN the timetable SHALL show two date groups, each containing the appropriate Sessions, with the one Session showing two Materials in its materials list.

#### Scenario: Timetable renders empty state

GIVEN a Cohort with no Sessions
WHEN a learner navigates to the CohortTimetable page for that Cohort
THEN the view SHALL display the empty-state message "No sessions scheduled for this cohort yet."

---

### Requirement: Manifest declarative pages for all new schemas

The system SHALL declare index and detail pages in `src/manifest.json` for Programme, CurriculumPlan, Cohort, Session, and Material schemas, allowing `CnAppRoot` built-in renderers to handle all CRUD. No PHP controller SHALL be created for Programme, CurriculumPlan, Cohort, Session, or Material CRUD.

#### Scenario: Manifest validates against schema

GIVEN the `src/manifest.json` file with all school-structure pages added
WHEN `node tests/validate-manifest.js` is executed
THEN the script SHALL exit with code 0 (zero Ajv errors).

---

## Standards

Schema.org `EducationalOccupationalProgram`, `Course`, `CourseInstance`, `Syllabus`; NL LOM / VDEX for Material tags; ECTS / Bologna for HE workload; NL VO PTA convention as a `CurriculumPlan` profile; OOAPI 5.0 for HE catalog publication (deferred to a follow-up — out of scope here).

## Out of Scope

- Room-booking conflict resolution and timetabling optimisation (a Session just records a location string).
- OOAPI 5.0 catalog publication endpoints (separate follow-up).
- The actual content runtime — cmi5/xAPI/SCORM execution is `course-management`'s job.
- Grading computation — that's the `grading` spec; this spec only *declares* the weighting in the CurriculumPlan.
- NC group provisioning (ncGroupId sync) on Cohort activation — deferred to a future event listener.
