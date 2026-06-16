---
slug: course-management
title: Course Management
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-002, adr-011]   # TODO until ADRs land
created: 2026-05-11
---

# Course Management

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, OOAPI endpoints, and cmi5/xAPI runtime — no `#### Scenario:` headings exist in this spec.

## Purpose
Course Management ranks #2 of 354 canonical features (153 demand, 43 tenders, 12 competitors). All 13 OSS LMS leaders ship it; the differentiator is a modern Vue/NL-Design surface — insight #16 says "OSS LMS leaders all share dated UX". Without authoring, Scholiq cannot anchor the LVS, eLearning, training, and certification surfaces above it.

## What
Authoring of courses, modules, and lessons; cloning of templates; ordered learning paths; published-vs-draft state; ECTS workload declaration for HE; programme-committee approval workflow for HE catalog changes; Open Onderwijs API publication so external sites and student portals consume one source. Content runtime is cmi5 + xAPI primary, with a SCORM 1.2/2004 shim for legacy packages.

## User Stories
- As an HE administrator, I want the course catalog exposed via Open Onderwijs API so external apps and the institution website pull from one source.
- As a board member, I want catalog changes to go through programme committee approval so curriculum governance is auditable.
- As an administrator, I want each module to declare ECTS workload so totals match the 60-credit-per-year Bologna rule.
- As a student, I want the catalog to tell me up-front whether I meet a course prerequisite so I do not waste time on a denied registration.
- As an instructional designer, I want to clone a published course as a draft so I can prepare next year's edition without breaking the live one.

## Acceptance Criteria
- GIVEN an instructional designer opens a published course, WHEN they click "Clone for next year", THEN a draft copy is created with a new academic year tag and zero enrolments.
- GIVEN a student opens the catalog, WHEN a course has unmet prerequisites, THEN the enrol button is disabled and the failing prerequisite is named in plain text.
- GIVEN a programme committee approves a catalog change, WHEN approval is recorded, THEN the change becomes visible in OOAPI within 5 minutes.
- GIVEN an HE administrator queries `/ooapi/v5/courses`, WHEN the request authenticates, THEN the response complies with OOAPI 5.0 and includes ECTS, language, and level fields.

## Requirements

### Requirement: Course/Module/Lesson hierarchy in OpenRegister
The system MUST support Course → Module → Lesson hierarchy persisted as OpenRegister objects.

#### Scenario: Persist course hierarchy as OpenRegister objects
- **GIVEN** an instructional designer authoring a course with modules and lessons
- **WHEN** the course, its modules, and their lessons are saved
- **THEN** the system persists the Course → Module → Lesson hierarchy as related OpenRegister objects

### Requirement: Publish course catalog via OOAPI 5.0
The system MUST publish the course catalog via OOAPI 5.0 endpoints.

#### Scenario: Serve the catalog over OOAPI 5.0
- **GIVEN** a published course catalog
- **WHEN** an authenticated client requests `/ooapi/v5/courses`
- **THEN** the system returns an OOAPI 5.0-compliant response including ECTS, language, and level fields

### Requirement: Run cmi5 + xAPI natively with SCORM shim
The system MUST run cmi5 + xAPI content natively and SHOULD provide a SCORM 1.2/2004 compatibility shim.

#### Scenario: Run cmi5/xAPI content with SCORM fallback
- **GIVEN** a lesson backed by a content package
- **WHEN** a learner launches the lesson
- **THEN** the system runs cmi5 + xAPI content natively
- **AND** it runs SCORM 1.2/2004 packages through the compatibility shim

## Standards
SCORM, xAPI, cmi5, LTI 1.3, Common Cartridge, NL LOM, VDEX, OAI-PMH, OOAPI 5.0, Schema.org `Course` / `CourseInstance`, ECTS, Bologna.

## Data Model
See `docs/ARCHITECTURE.md`. Uses entities: `Course`, `Module`, `Lesson`, `LearningPath`, `Prerequisite`, `CatalogChangeRequest`. All persisted via OpenRegister; no Scholiq tables.

## Out of Scope
- Authoring tool for SCORM packages themselves (use external authoring; we run, not author).
- Real-time collaborative lesson editing (V2).
- Marketplace / paid course storefront (separate spec if pursued).
