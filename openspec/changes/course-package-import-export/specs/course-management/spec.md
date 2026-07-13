## ADDED Requirements

### Requirement: Import a Common Cartridge or Moodle course package into the Course/Lesson/Material hierarchy

The system MUST support importing an IMS Common Cartridge 1.3 package or a Moodle backup (`.mbz`) archive via
a `CoursePackageImportService` (ADR-031 "External-format import" exception, mirroring `QtiImportService`).
The importer MUST walk the package's manifest (`imsmanifest.xml` for Common Cartridge; `moodle_backup.xml`
for Moodle) and materialise its organization tree as `Course`/`Lesson` objects (a folder-level organization
node becomes a child `Course` via `parentCourseId`, a leaf item becomes a `Lesson`), its web content and
weblink resources as `Material` objects, and MUST delegate any embedded QTI or Common-Cartridge-format
assessment items to the existing item-import machinery (`QtiImportService::importFromDirectory()`) rather
than re-implementing item parsing. `LtiToolPlacement` objects MUST be created for embedded LTI resources,
reusing the placement shape the "Place an LTI 1.3 tool inside a lesson" requirement already defines. The
importer MUST NOT implement any wire protocol — parsing an uploaded archive is a one-shot file transform, not
a conversation with a live external system (see `design.md` "Routing: scholiq, not openconnector").

@e2e exclude Package parsing and object-graph creation is backend logic verified by PHPUnit against fixture
archives; no scholiq DOM surface for the parse itself (the resulting report and course ARE drivable — see the
"names every resource's outcome" and frontend requirements below).

#### Scenario: A Common Cartridge package materialises its course structure

- **GIVEN** a valid IMS Common Cartridge 1.3 archive with an organization tree, web content resources, and
  embedded QTI assessment items
- **WHEN** an authorised user imports the package
- **THEN** the system creates a `Course` (and child `Course`s for nested organization folders), `Lesson`
  objects in manifest order, `Material` objects for the web content resources, and `Item`/`ItemBank` objects
  for the embedded QTI content via the existing item-import machinery

#### Scenario: A Moodle backup materialises the same structural shapes

- **GIVEN** a valid Moodle `.mbz` archive with sections, modules, and a quiz module using single-answer
  questions
- **WHEN** an authorised user imports the package
- **THEN** the system creates the equivalent `Course`/`Lesson`/`Material`/`Item` objects from the Moodle
  section/module structure and the supported quiz-question subset

#### Scenario: An LTI resource becomes a placement, not an inline link

- **GIVEN** a Common Cartridge package containing a `basiclti` resource
- **WHEN** the package is imported
- **THEN** an `LtiToolPlacement` object is created and the corresponding `Lesson.contentType` is set to `lti`
  with `contentRef` naming the placement, matching the shape a manually-placed LTI tool would have

### Requirement: Every course-package import produces a CoursePackageImportReport naming every resource's outcome

The system MUST persist a `CoursePackageImportReport` OpenRegister object for every import attempt, carrying
`sourceFormat`, `sourceFilename`, `courseId` (nullable until a `Course` exists), `lifecycle`
(`running → succeeded | partial | failed`), summary counts (`resourcesTotal`, `resourcesImported`,
`resourcesDegraded`, `resourcesDropped`), and an `entries` array with one row per source-package resource:
`resourceIdentifier`, `resourceType`, `title`, `outcome` (`imported` | `degraded` | `dropped`), `targetType`,
`targetId`, and a human-readable `reason`. The system MUST NOT omit a resource from `entries` for any reason
— a resource type the importer does not support MUST still produce a `dropped` entry naming why, never a
silent absence. `lifecycle` MUST resolve to `succeeded` only when zero entries are `degraded` or `dropped`;
any package with at least one non-`imported` entry MUST resolve to `partial`, which is a normal, non-error
terminal state.

#### Scenario: A package with unsupported content still reports every resource

<!-- @e2e exclude Report-content correctness (one entry per source resource, zero omissions) is a data
     invariant verified by PHPUnit against fixture archives with a known resource count; the rendered report
     IS drivable — see the frontend requirement below for the DOM-facing scenario. -->

- **GIVEN** a Common Cartridge package containing a discussion-topic resource (no scholiq schema represents
  discussions)
- **WHEN** the package is imported
- **THEN** the `CoursePackageImportReport` contains an entry for that resource with `outcome: dropped` and a
  `reason` naming why, and the report's `lifecycle` resolves to `partial`, not `succeeded`

#### Scenario: A fully-supported package resolves to succeeded

- **GIVEN** a Common Cartridge package containing only resource types this importer fully supports
- **WHEN** the package is imported
- **THEN** every entry has `outcome: imported`, `resourcesDegraded` and `resourcesDropped` are both `0`, and
  the report's `lifecycle` resolves to `succeeded`

#### Scenario: A corrupt or unrecognised archive fails loudly, not silently

<!-- @e2e exclude Error-path verified by PHPUnit against a deliberately corrupt fixture archive; no DOM
     surface for the parse failure itself beyond the report the frontend requirement below covers. -->

- **GIVEN** an archive that is not a valid ZIP/gzipped-tar or has no recognisable manifest
- **WHEN** an import is attempted
- **THEN** the `CoursePackageImportReport` resolves to `lifecycle: failed` with a non-empty `errorMessage`,
  `courseId` remains `null`, and no partial `Course`/`Lesson`/`Material` objects are left behind

### Requirement: Export a full course as Common Cartridge and scholiq-native JSON with resolved file attachments

The system MUST support exporting a `Course` (and its `Lesson`/`Material`/`Item`/`Rubric`/`LtiToolPlacement`
descendants) as (a) an IMS Common Cartridge 1.3 package for interoperability with other LMS platforms and (b)
a scholiq-native JSON tree for lossless round-trip back into Scholiq, via a `CoursePackageExportController`
mirroring the existing `AuditPackExportController`'s in-memory-ZIP streaming pattern. `Material.fileRef`
bytes MUST be resolved through OpenRegister's native file-attachment API and included in the exported
package — the export MUST NOT reference file paths the recipient cannot resolve. Embedded assessment items
MUST be exported in QTI 3.0 form via the `assessment` capability's item-export capability, not re-serialised
by the course exporter. Export MUST respect the exporting user's own read authorization: a field an
OpenRegister `x-property-rbac` rule would hide from that user in the UI MUST NOT appear in the export.

#### Scenario: Exporting a course produces a portable Common Cartridge package

- **GIVEN** a `Course` with `Lesson`s, `Material`s (including file-backed materials), and an `Assessment` with
  `Item`s
- **WHEN** an authorised user requests a Common Cartridge export
- **THEN** the system streams a ZIP containing an `imsmanifest.xml` organization tree, the resolved material
  file bytes, and the assessment items in QTI 3.0 form

#### Scenario: Exporting a course produces a lossless scholiq-native JSON tree

- **GIVEN** the same `Course` as above
- **WHEN** an authorised user requests a scholiq-native export
- **THEN** the system streams a JSON document that, when re-imported into a Scholiq tenant, reproduces the
  same `Course`/`Lesson`/`Material`/`Item`/`Rubric` object graph

#### Scenario: Export never leaks a field the exporting user cannot already see

<!-- @e2e exclude RBAC-boundary verification is backend authorization logic tested by PHPUnit against a
     user without a privileged role, mirroring FinalGrade's existing x-property-rbac PHPUnit coverage; no
     DOM surface distinguishes "field present" from "field correctly redacted" without inspecting the raw
     response body, which PHPUnit does directly. -->

- **GIVEN** a `Course` containing a `GradeEntry`-adjacent field an unprivileged exporting user is not
  authorized to read
- **WHEN** that user requests an export
- **THEN** the exported package does not contain the restricted field, matching what OpenRegister's own
  object-read API would have returned to that user

### Requirement: Course-package frontend is declarative with one named custom view for the import report

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `CoursePackageImportReport`. The
only custom Vue component MUST be `CoursePackageImportView` — an upload surface that submits the package and
then renders the resulting `CoursePackageImportReport`'s `entries` table (filterable by `outcome`) so an
instructional designer sees every imported, degraded, and dropped resource in one place. No PHP CRUD
controller — `CoursePackageImportController`/`CoursePackageExportController` are thin per ADR-022, delegating
all parsing/generation to `CoursePackageImportService`/`CoursePackageExportService`.

<!-- @e2e tests/e2e/spec-coverage/course-package-import-export.spec.ts -->

#### Scenario: An instructional designer uploads a package and sees the report

- **GIVEN** `CoursePackageImportView`
- **WHEN** an instructional designer uploads a course package
- **THEN** the resulting `CoursePackageImportReport` renders with its `entries` table, and the designer can
  filter it to `degraded`/`dropped` rows to see exactly what needs manual attention
