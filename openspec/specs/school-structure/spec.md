---
slug: school-structure
title: School Structure — Programmes, Curriculum Plans, Cohorts, Sessions
status: done
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [pta-vo, oer-he, opleidingsplan-mbo, training-curriculum]
---

# School Structure

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, recursive Course schema, and material attachment config — no `#### Scenario:` headings exist in this spec.

## Purpose

Every educational institution — a school, a university faculty, or a corporate training department — runs the same backbone: a **programme** (a degree / diploma / certification track) is described by a **governing plan** (which courses are required, what the assessment components are, how component grades roll up to a final grade, what the period structure is), learners take courses inside **cohorts** (a klas, a werkgroep, a training group), and a cohort meets in scheduled **sessions** (a les, a hoorcollege, a workshop) that carry materials and assignments. Scholiq's built register has `Course` + `Lesson` but no programme, no governing plan, no cohort, no session — so it can hold *content* but cannot model how a real institution *runs*. This spec adds that backbone in a jurisdiction-neutral way: the Dutch **PTA** (Programma van Toetsing en Afsluiting) is one profile of `CurriculumPlan`; an HE **OER/studiegids**, an MBO **opleidingsplan**, and a corporate **training curriculum** are others.

## What

- **Programme** — a named track (`HBO-V bachelor`, `NIS2 board certification`, `vmbo-tl examenjaar`) that aggregates courses, declares a credential to issue on completion, and points at one `CurriculumPlan`.
- **CurriculumPlan** — the governing document: ordered list of required + elective courses, the assessment-component definitions (with per-component weights, periods, and a roll-up formula), the grading scale, and pass/fail rules. The Dutch PTA's "kolommen met weegfactor per periode feeding the SE-gemiddelde" is exactly this; so is an HE module's "deeltoetsen → eindcijfer" weighting. Consumed by the `grading` spec to compute `FinalGrade`.
- **Course** (extends the existing schema) — collapse the rigid `Course → Module → Lesson` 3-level hierarchy into a **recursive Course** (a Course may contain sub-Courses *or* Lessons directly; a "module" is just a Course used as a container). Add `Course.curriculumPlanId` and `Course.programmeIds`.
- **Cohort** — a group of learners doing a Course/Programme together in a given period, with one or more teachers/instructors. Members are `LearnerProfile`s; backed by an NC group for permissioning.
- **Session** — a scheduled occurrence: cohort + course + start/end datetime + location (room / online URL) + attached `Material`s + linked `Assignment`s. The unit a teacher takes attendance against.
- **Material** — metadata for a file/presentation/reading/video/SCORM-cmi5 package attached to a Course, Lesson, or Session. The bytes live in OpenRegister's native file attachments; this schema carries type, license, LOM-style tags, and ordering.

## User Stories

- As a programme coordinator, I want to define a Programme with its CurriculumPlan once, so that every cohort that runs it inherits the same required-course list and grade-weighting rules.
- As a teacher, I want to attach this week's slides, reading list, and the homework brief to a Session, so learners see everything for that class in one place.
- As an administrator, I want to clone a published Course as a draft for next year without breaking the live one or carrying over enrolments.
- As a learner, I want my cohort's schedule (sessions with times, rooms, and materials) on one page.
- As a Dutch VO exam secretary, I want to express the PTA — kolommen, weegfactoren, periods, SE-formula — as a CurriculumPlan, so grading and the SE-gemiddelde are computed automatically.

## Acceptance Criteria

- GIVEN a Programme with a CurriculumPlan listing 3 required courses, WHEN a Cohort is created for that Programme, THEN the cohort's required-course set equals the plan's and the grade-weighting formula is the plan's.
- GIVEN a teacher opens a Session, WHEN they attach a Material, THEN it appears in every cohort member's view of that session with the declared title, type, and order.
- GIVEN an administrator clicks "Clone for next year" on a published Course, THEN a draft copy is created with a new academic-year tag, the same Lesson tree, and zero enrolments.
- GIVEN a PTA expressed as a CurriculumPlan with a kolom of weegfactor 3, WHEN the `grading` spec computes the period average, THEN that kolom contributes 3× (see `grading`).

## Requirements

### Requirement: Persist school-structure domain objects in OpenRegister
The system MUST persist `Programme`, `CurriculumPlan`, `Cohort`, `Session`, `Material` as OpenRegister objects with `x-openregister-lifecycle` (draft → published → archived for Programme/Course; scheduled → in-progress → completed | cancelled for Session) and `x-openregister-relations` (Cohort↔Programme/Course, Session↔Cohort/Course, Material↔Course/Lesson/Session).

#### Scenario: School-structure objects persisted in OpenRegister
- **GIVEN** the school-structure domain schemas are registered
- **WHEN** a coordinator creates a Programme, CurriculumPlan, Cohort, Session, and Material
- **THEN** each is stored as an OpenRegister object carrying the declared lifecycle states and relations

### Requirement: Course schema is recursive with curriculumPlanId + programmeIds
The `Course` schema MUST be recursive (`parentCourseId` self-reference) and MUST carry `curriculumPlanId` + `programmeIds`.

#### Scenario: Course nests sub-courses and references plan and programmes
- **GIVEN** the `Course` schema
- **WHEN** a Course is created as a container with sub-Courses and linked to a plan and programmes
- **THEN** it stores the `parentCourseId` self-reference plus `curriculumPlanId` and `programmeIds`

### Requirement: CurriculumPlan carries component list and roll-up formula
The `CurriculumPlan` MUST carry a structured component list `{ componentId, label, weight, period, kind: assignment|assessment|participation }[]` and a roll-up `formula` (named: `weighted-average` | `last-attempt` | `best-of-n` | `all-must-pass`).

#### Scenario: CurriculumPlan declares weighted components and formula
- **GIVEN** a `CurriculumPlan` expressing a PTA
- **WHEN** a kolom is defined with a weegfactor and a named roll-up formula
- **THEN** the plan carries the structured component list (with weight, period, kind) and the named `formula` that the `grading` spec consumes

### Requirement: Materials reference OpenRegister file attachments
Materials MUST reference OpenRegister file attachments; this app MUST NOT store file bytes itself.

#### Scenario: Material points at an OpenRegister file attachment
- **GIVEN** a teacher attaches a file to a Session
- **WHEN** the Material is saved
- **THEN** it references the OpenRegister file attachment and this app stores no file bytes of its own

### Requirement: Frontend is declarative with cohort-timetable exception
Frontend MUST be declarative: `src/manifest.json` pages for Programme/CurriculumPlan/Cohort/Session index+detail; a custom Vue view only for the cohort timetable if a manifest page can't render it. No PHP CRUD controllers.

#### Scenario: Pages are manifest-declared with timetable exception
- **GIVEN** the school-structure frontend is configured
- **WHEN** the app renders Programme, CurriculumPlan, Cohort, and Session screens
- **THEN** index/detail pages come from `src/manifest.json` and the only custom Vue view is the cohort timetable (when a manifest page cannot render it), with no PHP CRUD controllers

## Standards

Schema.org `EducationalOccupationalProgram`, `Course`, `CourseInstance`, `Syllabus`; NL LOM / VDEX for Material tags; ECTS / Bologna for HE workload; NL VO PTA convention as a `CurriculumPlan` profile; OOAPI 5.0 for HE catalog publication (deferred to a follow-up — out of scope here).

## Data Model

All in OpenRegister. New schemas: `Programme`, `CurriculumPlan`, `Cohort`, `Session`, `Material`. Modified: `Course` (recursive + curriculumPlanId + programmeIds), `Enrolment` (add optional `cohortId`). See `docs/ARCHITECTURE.md`.

## Out of Scope

- Room-booking conflict resolution and timetabling optimisation (a Session just records a location string).
- OOAPI 5.0 catalog publication endpoints (separate follow-up).
- The actual content runtime — cmi5/xAPI/SCORM execution is `course-management`'s job.
- Grading computation — that's the `grading` spec; this spec only *declares* the weighting in the CurriculumPlan.
