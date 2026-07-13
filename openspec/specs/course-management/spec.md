---
slug: course-management
title: Course Management
status: done
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
- GIVEN a `Course` transitions to `published`, WHEN the publication contract's field mapping is applied, THEN a `DataExchangeJob` (`target: ooapi-catalog`) carries the OOAPI 5.0 `course` resource fields (ECTS, language, level) to the catalog publication surface hosted by opencatalogi — Scholiq itself serves no `/ooapi/v5/*` endpoint.

## Requirements

### Requirement: Course/Module/Lesson hierarchy in OpenRegister
The system MUST support Course → Module → Lesson hierarchy persisted as OpenRegister objects.

#### Scenario: Persist course hierarchy as OpenRegister objects
- **GIVEN** an instructional designer authoring a course with modules and lessons
- **WHEN** the course, its modules, and their lessons are saved
- **THEN** the system persists the Course → Module → Lesson hierarchy as related OpenRegister objects

### Requirement: Publish course catalog via OOAPI 5.0

The system MUST NOT serve OOAPI 5.0 endpoints itself. Instead it MUST define the OOAPI 5.0 catalog
**publication contract**: (a) which objects are eligible for publication — `Course` and `Programme` with
`lifecycle: published`, with `Cohort` representing a specific "run" of a course or programme; (b) the
**field mapping** from Scholiq's objects to OOAPI 5.0 resources — `Course → course`, `Programme → program`,
`Cohort → offering` — keyed to RIO `opleidingseenheid` / `aangeboden opleiding` identifiers where the
institution has recorded them, and omitted otherwise; and (c) the **publication lifecycle** — a `publish`
transition on `Course` or `Programme` MUST queue a `DataExchangeJob` (`direction: sync`,
`target: ooapi-catalog`, per the `data-exchange` spec's delegation mechanism) so the catalog reflects the
change, and an `archive` transition MUST queue the matching unpublish/removal sync. The public `/ooapi/v5/*`
HTTP surface and the OOAPI 5.0 wire protocol are served by **opencatalogi**; the field-mapping adapter is
hosted in **openconnector**. Scholiq implements neither.

#### Scenario: Publishing a course queues a catalog-sync job, not a scholiq-served endpoint

- **GIVEN** a `Course` with `lifecycle: draft` and its required OOAPI mapping fields populated (`code`,
  `name`, `level`, `language`)
- **WHEN** an instructional designer transitions it to `published`
- **THEN** the system queues a `DataExchangeJob` with `direction: sync` and `target: ooapi-catalog` carrying
  the OOAPI 5.0 `course` resource field mapping
- **AND** Scholiq itself exposes no `/ooapi/v5/*` route — the catalog request is served by opencatalogi

#### Scenario: Unpublishing removes the catalog entry

- **GIVEN** a `Course` or `Programme` with `lifecycle: published`
- **WHEN** it is archived
- **THEN** the system queues a corresponding unpublish `DataExchangeJob` (`target: ooapi-catalog`) so the
  opencatalogi-hosted OOAPI 5.0 catalog removes or deprecates the entry

#### Scenario: Field mapping covers course, program, and offering resources keyed to RIO where available

- **GIVEN** a `Programme` that aggregates `Course`s and a `Cohort` representing one specific run of a course
- **WHEN** the publication contract's field mapping is applied
- **THEN** the `Course` maps to the OOAPI `course` resource, the `Programme` maps to the OOAPI `program`
  resource, and the `Cohort` maps to the OOAPI `offering` resource
- **AND** each mapped resource carries its RIO `opleidingseenheid` / `aangeboden opleiding` identifier when
  the source object has one, and omits the RIO identifier field otherwise

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
