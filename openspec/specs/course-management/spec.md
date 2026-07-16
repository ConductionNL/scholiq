---
slug: course-management
title: Course Management
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-002, adr-011]   # TODO until ADRs land
created: 2026-05-11
---

# Course Management

@e2e exclude Most requirements in this spec define OpenRegister schema shapes, OOAPI endpoints, and cmi5/xAPI runtime with no dedicated browser journey. The course-authoring-ux requirements below are the exception — see their own per-scenario `@e2e` tags pointing at `tests/e2e/spec-coverage/course-authoring-ux.spec.ts`.

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

### Requirement: Place an LTI 1.3 tool inside a lesson via a dedicated placement object

The system MUST support placing an external LTI 1.3 tool inside a Course or Lesson as an
`LtiToolPlacement` OpenRegister object: `lessonId` (reference to the placing `Lesson`, nullable
when the placement is course-level), `courseId` (reference to the placing `Course`, nullable
when the placement is lesson-level), `openconnectorDeploymentId` (the UUID of the corresponding
`lti_deployment` registration in openconnector's register), `launchMode`
(`resource-link | deep-linking`), and, when AGS grade passback is desired,
`curriculumPlanId` / `gradeEntryComponentId` / `gradeScaleId` naming which grading component the
tool's scores feed. A `Lesson` with `contentType: lti` MUST set `contentRef` to the UUID of its
`LtiToolPlacement`, not a raw URL — a static link cannot carry a signed OIDC launch.

@e2e exclude Pure backend/data-model requirement — no dedicated browser journey; covered by PHPUnit schema tests

#### Scenario: An LtiToolPlacement names its openconnector registration

- **GIVEN** an instructional designer places an external LTI tool inside a Lesson
- **WHEN** the `LtiToolPlacement` is saved with `lessonId` and `openconnectorDeploymentId` set
- **THEN** the Lesson's `contentType` is `lti` and its `contentRef` equals the
  `LtiToolPlacement`'s UUID

#### Scenario: A placement configured for grade passback names its curriculum mapping

- **GIVEN** an `LtiToolPlacement` intended to feed AGS scores into the gradebook
- **WHEN** it is saved with `curriculumPlanId`, `gradeEntryComponentId`, and `gradeScaleId` set
- **THEN** those three fields are persisted on the placement, not inferred from any LTI protocol
  metadata

### Requirement: LessonPlayer delegates the OIDC launch to the openconnector adapter

When a learner opens a Lesson with `contentType: lti`, `LessonPlayer.vue` MUST resolve
`contentRef` to its `LtiToolPlacement` and call a scholiq backend endpoint
(`LtiToolPlacementController::launch`) that delegates to openconnector's Platform-role
launch-initiation service (openconnector REQ-LTI-006), passing only
`LtiToolPlacement.openconnectorDeploymentId`. Scholiq MUST NOT construct, sign, or verify any LTI
`id_token`, JWT, or JWK itself — it forwards the placement reference and renders back whatever
launch response (auto-submitting form or URL) openconnector returns, treating it as opaque. The
outbound call MUST reuse the existing scholiq→openconnector authenticated-REST pattern
(`IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig` bearer token under the
same `scholiq.openconnector_api_token` key `DataExchangeRunHandler::callOpenConnector()` already
uses) rather than introducing a second cross-app authentication mechanism.

@e2e exclude Launch delegation is a thin outbound proxy with no LTI protocol logic in scholiq; contract covered by PHPUnit against a mocked openconnector response

#### Scenario: Opening an LTI lesson delegates the launch and renders the response opaquely

- **GIVEN** a Lesson with `contentType: lti` whose `contentRef` names a valid `LtiToolPlacement`
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the backend calls openconnector's launch-initiation endpoint with the placement's
  `openconnectorDeploymentId`
- **AND** the response (auto-submitting form or URL) is rendered without scholiq inspecting any
  LTI claim it carries

#### Scenario: The outbound call reuses the existing cross-app auth pattern, not a new one

- **GIVEN** the `scholiq.openconnector_api_token` app-config value is set
- **WHEN** `LtiToolPlacementController::launch()` calls openconnector
- **THEN** the request carries the same bearer-token header shape
  `DataExchangeRunHandler::callOpenConnector()` already sends
- **AND** no second, LTI-specific cross-app credential is introduced

### Requirement: Course declares an ECTS credit value

The `Course` object MUST support an `ectsCredits` field (nullable number, `minimum: 0`) declaring the
Bologna-style credit value the course/module contributes toward a learner's cumulative EC total. The field
MUST be additive — existing `Course` rows leave it `null` — and MUST NOT be required, since `po`/`vo`/
`corporate` courses (which do not participate in ECTS-bearing programmes) never need to set it. Any
consumer summing a learner's earned credits MUST treat a `null` `ectsCredits` as `0`, not as an error.

#### Scenario: A course declares its ECTS value

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface. Consumed by the study-progress capability's BsaProgressEvaluator, itself covered by PHPUnit as referenced in that spec. -->

- **GIVEN** an HBO/WO course being authored
- **WHEN** the instructional designer sets `ectsCredits` to a positive number
- **THEN** the value persists on the `Course` object
- **AND** it is available to any downstream credit-summing calculation (e.g. the `study-progress`
  capability's `BsaProgressEvaluator`)

#### Scenario: An existing course without a declared credit value defaults to zero for summation

<!-- @e2e exclude Null-handling verified by the study-progress capability's BsaProgressEvaluatorTest; no scholiq DOM surface here. -->

- **GIVEN** a pre-existing `Course` row with `ectsCredits` unset (`null`)
- **WHEN** a downstream calculation sums a learner's earned credits across their passed courses
- **THEN** that course contributes `0` EC to the total
- **AND** the calculation does not error

### Requirement: A Lesson's body is authored as an ordered list of typed content blocks

The system SHALL support authoring a `Lesson`'s body as `blocks`, an ordered array of typed content blocks,
each `{blockId, type, order}` plus exactly one payload matching `type`: `richText` (inline markdown text),
`media` (a pointer to an existing `Material` UUID — covers image, video, file attachment, and cmi5/SCORM
package reference blocks via `Material.kind`), `quiz` (a pointer to an existing `Assessment` UUID),
`assignment` (a pointer to an existing `Assignment` UUID), or `ltiTool` (a pointer to an existing
`LtiToolPlacement` UUID). `Lesson.contentType: text` denotes a native, block-composed lesson; `contentRef`
remains required for every other `contentType` value (`video`, `scorm12`, `scorm2004`, `cmi5`, `lti`,
`quiz`) exactly as before, but is NOT required when `contentType: text` and `blocks` is populated — no
existing packaged-content lesson's validation changes.

#### Scenario: An instructional designer composes a lesson from mixed blocks

- **GIVEN** a `Lesson` with `contentType: text`
- **WHEN** the instructional designer adds a `richText` block, a `media` block pointing at an existing
  `Material`, and a `quiz` block pointing at an existing `Assessment`, in that order
- **THEN** `Lesson.blocks` persists all three blocks with their `order` and type-specific payload
- **AND** `contentRef` is not required for this `Lesson`

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A media block references an existing Material rather than duplicating file metadata

- **GIVEN** a `Material` already exists (`kind: video`, `fileRef` set) attached to the same `Lesson`
- **WHEN** the instructional designer adds a `media` block and selects that `Material`
- **THEN** the block persists only the `Material`'s UUID — no `fileRef`, `kind`, or file bytes are
  duplicated onto the block

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: Packaged-content lessons are unaffected

- **GIVEN** an existing `Lesson` with `contentType: cmi5` and `contentRef` set to a launch URL
- **WHEN** the `Lesson` is saved without any change
- **THEN** `contentRef` is still required and validation is unchanged — the conditional relaxation applies
  only to `contentType: text`

<!-- @e2e exclude Schema-level regression on an unrelated contentType; covered by PHPUnit schema validation tests (CourseAuthoringRegisterTest), no new browser journey -->

### Requirement: Lessons within a Course and blocks within a Lesson are reorderable by drag-and-drop and by keyboard

The system SHALL provide drag-and-drop reordering of `Lesson`s within a `Course` (writing `Lesson.order`)
and of blocks within a `Lesson` (writing each block's `order`), AND SHALL provide an equivalent
keyboard-operable reordering control (move up / move down) for both, so that reordering is never
drag-only. This satisfies WCAG 2.1 AA success criterion 2.1.1 (Keyboard) — a legal duty for an app serving
publicly funded Dutch schools (po/vo/mbo/hbo) under the Tijdelijk besluit digitale toegankelijkheid
overheid / EN 301 549, and a commitment `nextcloud-app`'s own spec already declares (WCAG 2.1 AA).

#### Scenario: A teacher reorders lessons within a course by drag-and-drop

- **GIVEN** a `Course` with three `Lesson`s in order 1, 2, 3
- **WHEN** the teacher drags the third lesson to the first position in `CourseBuilder`
- **THEN** the three `Lesson`s persist with `order` 1, 2, 3 reflecting the new sequence

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A teacher reorders lessons within a course using only the keyboard

- **GIVEN** a `Course` with three `Lesson`s in order 1, 2, 3
- **WHEN** the teacher tabs to the third lesson's "Move up" control and activates it twice, using no
  pointer device
- **THEN** the same `order` mutation as the drag-and-drop scenario is persisted
- **AND** the move is announced to assistive technology (e.g. "Lesson moved to position 1 of 3")

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A teacher reorders blocks within a lesson using only the keyboard

- **GIVEN** a `Lesson` with a `richText` block followed by a `quiz` block
- **WHEN** the teacher moves the `quiz` block up using its keyboard-operable control
- **THEN** `Lesson.blocks` persists the `quiz` block's `order` ahead of the `richText` block's `order`

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: A Course declares its display order among sibling modules

The system SHALL support an `order` field (nullable integer) on `Course`, used to sequence sibling child
`Course`s ("modules") sharing the same `parentCourseId`. The field MUST be additive — existing `Course` rows
leave it `null` — and any UI listing sibling modules MUST treat `null` as sorting after every module with an
explicit `order` value (append-to-end), never as an error or as position zero.

#### Scenario: A designer sets module order in the course builder

- **GIVEN** a `Course` with two child modules, both `order: null`
- **WHEN** the designer arranges them in `CourseBuilder` and saves
- **THEN** both modules persist explicit, distinct `order` values reflecting the arrangement

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A pre-existing module without an order value sorts last, not first

<!-- @e2e exclude Null-default sort behaviour is a pure list-rendering rule with no distinct browser journey beyond the drag-and-drop scenario already covered; verified by a component unit test on CourseBuilder's sort comparator (tests/unit-js/courseOrder.test.mjs) -->

- **GIVEN** two sibling modules, one with `order: 1` and one with `order: null`
- **WHEN** `CourseBuilder` renders the module list
- **THEN** the `order: 1` module is listed first and the `order: null` module is listed after it

### Requirement: A Course structure can be saved as a reusable template and instantiated

The system SHALL support a `CourseTemplate` object capturing a Course→Module→Lesson skeleton (module and
lesson names, order, `contentType`, and lightweight block placeholders — not live content references or
learner data) plus an optional `CurriculumPlan` skeleton (`kind`, `formula`, `components`, `periods`,
`passRules`, in the same shape `CurriculumPlan` itself already uses), captured either from an existing
`Course` ("Save as template") or authored from scratch, and instantiated into a new, independent `Course`
tree (and, when the skeleton is present, a new `CurriculumPlan`) that shares no object references with the
source.

#### Scenario: An instructional designer saves a published course as a template

- **GIVEN** a published `Course` with two modules and several lessons
- **WHEN** the designer chooses "Save as template" in `CourseBuilder`
- **THEN** a `CourseTemplate` is created capturing the module/lesson names, order, and content types
- **AND** the source `Course` and its `Lesson`s are unchanged

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: Instantiating a template creates a fresh, independent course tree

- **GIVEN** a `CourseTemplate` with two modules and three lessons across them
- **WHEN** the designer instantiates it as a new course
- **THEN** a new `Course` in `lifecycle: draft` is created, with new child `Course`s and `Lesson`s matching
  the template's structure, each with a freshly generated UUID
- **AND** the new `Course` has zero enrolments — fulfilling the "Clone for next year" acceptance criterion
  this spec declared above

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: LessonPlayer renders a Lesson's authored blocks

When `Lesson.contentType` is `text`, `LessonPlayer.vue` SHALL render `lesson.blocks` in `order`, dispatching
each block to a renderer by `type`: `richText` renders its markdown as sanitised HTML, `media` resolves and
renders the referenced `Material` (image/video/file/link per `Material.kind`), `quiz` embeds the referenced
`Assessment`'s take-flow, `assignment` renders a summary card linking to the referenced `Assignment`, and
`ltiTool` triggers the same opaque launch-delegation flow the existing `contentType: lti` branch already
uses, scoped to that one block rather than the whole lesson.

#### Scenario: A learner opens a native lesson and sees its composed blocks in order

- **GIVEN** a `Lesson` with `contentType: text` and three blocks (`richText`, `media`, `quiz`) in that order
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the three blocks render in their persisted order, each via its type-specific renderer

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

## Standards
SCORM, xAPI, cmi5, LTI 1.3, Common Cartridge, NL LOM, VDEX, OAI-PMH, OOAPI 5.0, Schema.org `Course` / `CourseInstance`, ECTS, Bologna. LTI 1.3 / LTI Advantage (Assignment & Grade Services, Deep Linking 2.0) protocol implementation lives entirely in openconnector's `lti-13-platform` adapter; Scholiq covers only the consuming-app placement and launch-delegation contract. WCAG 2.1 AA (reorder keyboard-operability, course-authoring-ux).

## Data Model
See `docs/ARCHITECTURE.md`. Uses entities: `Course`, `Module`, `Lesson`, `LearningPath`, `Prerequisite`, `CatalogChangeRequest`, `LtiToolPlacement`, `CourseTemplate`. All persisted via OpenRegister; no Scholiq tables. `CourseTemplate` (course-authoring-ux) captures a reusable Course→Module→Lesson (+ optional CurriculumPlan) skeleton, instantiated via frontend orchestration against OpenRegister's object-create endpoint — no new PHP controller.

## Out of Scope
- Authoring tool for SCORM packages themselves (use external authoring; we run, not author).
- Real-time collaborative lesson editing (V2).
- Marketplace / paid course storefront (separate spec if pursued).
