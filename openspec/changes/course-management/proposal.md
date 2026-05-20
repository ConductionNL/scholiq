## Why

Course Management ranks #2 of 354 canonical features (153 demand, 43 tenders, 12 competitors). All 13 OSS LMS leaders ship it; the differentiator is a modern Vue/NL-Design surface — insight #16 says "OSS LMS leaders all share dated UX". Without authoring, Scholiq cannot anchor the LVS, eLearning, training, and certification surfaces above it.

The Phase 1 compliance-audit wedge delivered a minimal `Course` + `Lesson` + `XapiStatement` model sufficient to run mandatory training. This Phase 2 change delivers the full production course model: a `Course → Module → Lesson` hierarchy with ordered learning paths, prerequisite enforcement, ECTS workload declaration for the HE market, a programme-committee approval workflow for auditable curriculum governance, and OOAPI 5.0 catalog publication so external sites and student portals consume a single authoritative source. It also introduces course cloning so instructional designers can prepare next-year editions without disrupting live enrolments.

## What Changes

- Add OpenRegister schema `Module` (ordered unit between Course and Lesson; carries ECTS credit allocation; schema:LearningResource).
- Add OpenRegister schema `LearningPath` (ordered sequence of Course objects; schema:EducationalOccupationalProgram; prerequisite chain optional).
- Add OpenRegister schema `Prerequisite` (directed edge between two Course objects with condition type: `completion` | `grade` | `consent`).
- Add OpenRegister schema `CatalogChangeRequest` (programme-committee approval workflow item; lifecycle: `draft → submitted → approved → rejected`).
- Extend `Course` schema with ECTS field (`ects`), NLQF-aligned `level` enum (levels 1-8 + `corporate`), OOAPI fields (`ooApiCode`, `ooApiEducationSpecificationId`), and clone metadata (`clonedFromId`, `academicYear`).
- Update `Course.x-openregister-lifecycle` publish guard to require at least one published `Module` (previously at least one published `Lesson`).
- Add `Scholiq\Controllers\OoapiController`: `GET /ooapi/v5/courses`, `GET /ooapi/v5/courses/{id}`, `GET /ooapi/v5/education-specifications` — OOAPI 5.0 conformant; authenticated via Bearer token; public page; response includes ECTS, language, level, educationSpecificationId per OOAPI 5.0 schema.
- Add `Scholiq\Service\CourseCloneService`: deep-clones a published `Course` plus all its `Module` and `Lesson` objects into a new draft Course; sets `clonedFromId` + new `academicYear`; zeroes aggregations; calls OR batch endpoint — legitimate per ADR-031 (external-system contract orchestrating multiple OR writes).
- Extend `src/manifest.json` with `ModuleList`, `ModuleDetail`, `LearningPathList`, `LearningPathDetail`, `CatalogChangeRequestList`, `CatalogChangeRequestDetail` pages.
- All mutations emit audit events via OR lifecycle engine per ADR-008.

## Capabilities

### New Capabilities

- `module-hierarchy`: Module entity CRUD; `Course → Module → Lesson` ordered hierarchy; Module-level ECTS credit declaration; Course publish guard requires at least one published Module.
- `learning-paths`: LearningPath entity CRUD; ordered course sequences linked via OR relations; prerequisite chain via `Prerequisite` schema.
- `prerequisite-enforcement`: `Prerequisite` objects consulted at enrolment time; enrol button disabled in UI when prerequisites unmet; failing prerequisite named in plain text per acceptance criteria.
- `course-cloning`: Clone a published Course (+ all Modules and Lessons) as a new draft with a new academic year tag and zero enrolments via `CourseCloneService`.
- `ooapi-publication`: OOAPI 5.0 conformant catalog endpoints at `/ooapi/v5/courses`; authenticated reads return ECTS, language, level, `educationSpecificationId`; catalog changes from approved `CatalogChangeRequest` become visible within 5 minutes.
- `catalogue-governance`: `CatalogChangeRequest` schema + programme-committee approval lifecycle; approved requests cascade to Course `published` state; governance trail recorded via OR audit per ADR-008.

### Modified Capabilities

- `course-management` (Phase 1): `Course` schema extended with ECTS, OOAPI, and clone metadata fields (all optional — non-breaking per ADR-011). Lifecycle publish guard updated to require at least one published `Module`. Phase 1 content-runtime (cmi5 + xAPI LRS + SCORM shim) unchanged.

## Impact

- **OpenRegister schemas**: `Module`, `LearningPath`, `Prerequisite`, `CatalogChangeRequest` must be registered before the enrolment spec can enforce prerequisites or before HE catalog publication flows are exercised.
- **`Course` schema extension**: adding optional fields (`ects`, `ooApiCode`, `clonedFromId`, `academicYear`) is non-breaking per ADR-011. The `level` enum gains NLQF values 1-8 (additive — non-breaking).
- **OOAPI 5.0 endpoint**: `OoapiController` opens a new URL namespace `/ooapi/v5/`; requires `#[PublicPage]` + `#[NoAdminRequired]` with Bearer-token validation; CORS OPTIONS route must be registered.
- **Programme committee workflow**: `CatalogChangeRequest.lifecycle approved` cascades a `publish` transition on the linked `Course` via OR's lifecycle cascade; OR emits `course.published` audit entry automatically — no additional PHP.
- **CourseCloneService**: deep-clone touches OR batch endpoint; content files in `nc:files` are referenced by path (no duplication); `clonedFromId` back-reference preserved for lineage reporting.
- **`enrolment` spec**: prerequisite checking at enrolment time reads `Prerequisite` objects; these must exist and be seeded before end-to-end enrolment tests pass.
- **Certification spec**: `LearningPath` completion aggregation feeds credential issuance; no change to certification spec required — it reads OR's `enrolment.completed` audit events which are unchanged.
