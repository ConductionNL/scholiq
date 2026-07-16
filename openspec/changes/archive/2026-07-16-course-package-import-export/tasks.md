# Tasks: course-package-import-export

## 1. Schema

- [ ] 1.1 Add `CoursePackageImportReport` to `lib/Settings/scholiq_register.json`: `sourceFormat`
  (`common-cartridge-1.3` | `moodle-backup`, required), `sourceFilename` (required), `courseId` (nullable
  $ref `Course`), `importedBy` (required), `importedAt` (required date-time), `lifecycle`
  (`running → succeeded | partial | failed`), `resourcesTotal`/`resourcesImported`/`resourcesDegraded`/
  `resourcesDropped` (integers, default 0), `errorMessage` (nullable), `entries` (array of
  `{resourceIdentifier, resourceType, title, outcome: imported|degraded|dropped, targetType, targetId,
  reason}`), `tenant_id`. Title/description (English) on every property per ADR-011.
  - **spec_ref**: `specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome`
  - **acceptance_criteria**:
    - Schema validates against the register's OpenAPI 3.0.0 conventions
    - `entries` items schema matches the fields listed above; no dangling `$ref`
- [ ] 1.2 Add `course-package.import`, `course-package.export`, `qti.export` to `lib/actions.seed.json`
  (default `["admin"]`, mirroring `qti.import`'s existing entry).
  - **spec_ref**: `design.md#security--privacy-posture`
  - **acceptance_criteria**:
    - All three actions present; default admin-only, broadenable via Admin Settings

## 2. Backend — shared extraction refactor

- [ ] 2.1 Refactor `QtiImportService::import()`: extract the existing `collectItemPaths()`/
  `importSingleItem()` loop into a new `public function importFromDirectory(string $dir, string
  $itemBankId, string $tenantId = ''): array`; `import()` becomes `extractZip()` then
  `importFromDirectory()`. No behavior change for the existing `QtiImportController` caller.
  - **spec_ref**: `design.md#why-extraction-is-refactored-not-duplicated`
  - **acceptance_criteria**:
    - Existing `QtiImportService` PHPUnit suite passes unchanged (regression check)
    - `importFromDirectory()` is callable directly against an already-extracted directory (new unit test)

## 3. Backend — course-package import

- [ ] 3.1 Add `OCA\Scholiq\Service\MbzExtractor` (SPDX; ADR-031 "External-format import" exception): extracts
  a gzipped-tar `.mbz` archive via `PharData`, porting `QtiImportService::extractZip()`'s zip-slip /
  decompression-bomb / per-file-size guards to the tar-extraction path (fixes for #207, ported not
  re-invented).
  - **spec_ref**: `design.md#security--privacy-posture`
  - **acceptance_criteria**:
    - Unit tests cover: valid `.mbz` extracts; path-traversal entry rejected; oversized total/entry rejected
- [ ] 3.2 Add `OCA\Scholiq\Service\CommonCartridgeParser` (SPDX): walks an extracted CC 1.3
  `imsmanifest.xml`, classifies each `<resource type="...">` (organization/webcontent/weblink/
  imsqti_item/imsqti_test/basiclti/other), and returns a resource-descriptor list the orchestrator
  consumes.
  - **spec_ref**: `specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy`
  - **acceptance_criteria**:
    - Unit tests cover: organization tree ordering preserved; each resource type in the fidelity table
      classified correctly against a fixture manifest
- [ ] 3.3 Add `OCA\Scholiq\Service\MoodleBackupParser` (SPDX): walks an extracted `.mbz`'s
  `moodle_backup.xml` + per-section/module XML, classifies each module (resource/page/url/quiz/assign/
  forum/wiki/glossary/other), and returns the same resource-descriptor shape `CommonCartridgeParser`
  returns.
  - **spec_ref**: `specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy`
  - **acceptance_criteria**:
    - Unit tests cover: section/module structure parsed against a fixture `.mbz`; unsupported module types
      (forum/wiki/glossary) classified as `other` for the report, not silently skipped
- [ ] 3.4 Add `OCA\Scholiq\Service\MoodleQuizQuestionMapper` (SPDX): maps Moodle's `quiz/quiz.xml`
  single-answer, multi-answer, short-answer, and essay question types to `Item` objects (matching
  `QtiImportService`'s `Item` shape); every other Moodle question subtype returns a `dropped`-marked
  descriptor rather than a partially-correct `Item`.
  - **spec_ref**: `design.md#fidelity--loss-table`
  - **acceptance_criteria**:
    - Unit tests cover: each of the four supported subtypes produces a correct `Item`; an unsupported
      subtype (e.g. drag-and-drop) produces a `dropped` descriptor, not a malformed `Item`
- [ ] 3.5 Add `OCA\Scholiq\Service\CoursePackageImportService` (SPDX; the orchestrator): detects
  CC-vs-Moodle from the archive (delegates to `MbzExtractor` or the existing `extractZip`-equivalent),
  runs the matching parser, and for each resource descriptor: creates `Course`/`Lesson` (organization
  nodes), `Material` (web content/weblink/resource/page/url), delegates to
  `QtiImportService::importFromDirectory()` (QTI/CC assessment items) or `MoodleQuizQuestionMapper`
  (Moodle quiz items), creates `LtiToolPlacement` (basiclti resources), creates `Assignment` (Moodle
  `assign` modules, existing schema per `assignments` capability, unmodified), or records a `dropped`
  entry (forum/wiki/glossary/cmi5-SCORM-embedded/unrecognised). Assembles and persists the
  `CoursePackageImportReport` throughout, resolving its final `lifecycle` per the report requirement's
  rule.
  - **spec_ref**: `specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy`
  - **acceptance_criteria**:
    - Integration-style unit tests cover: a full CC fixture import producing the expected object counts and
      report entries; a full Moodle fixture import doing the same; a corrupt-archive import producing
      `lifecycle: failed` with no partial objects left behind
- [ ] 3.6 Add `OCA\Scholiq\Controller\CoursePackageImportController` (SPDX; thin per ADR-022): single
  `import()` action, multipart upload (`file`), `#[NoAdminRequired]`, `ActionAuthService::requireAction(...,
  'course-package.import')`, resolves the caller's tenant (same pattern as
  `QtiImportController::import()`), delegates to `CoursePackageImportService`, returns the created
  `CoursePackageImportReport`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy`
  - **acceptance_criteria**:
    - Unauthenticated request rejected; missing file rejected; successful upload returns the report UUID

## 4. Backend — course-package + QTI export

- [ ] 4.1 Add `OCA\Scholiq\Service\QtiExportService` (SPDX; `assessment` capability): given an `ItemBank`
  UUID, builds a QTI 3.0 package (`imsmanifest.xml` + one `assessmentItem` XML per `Item`, each wrapping
  the stored `qtiBody` verbatim — no re-derivation from `interactionType`/`correctResponse`).
  - **spec_ref**: `specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package`
  - **acceptance_criteria**:
    - Unit test: exported `assessmentItem` XML byte-matches the stored `qtiBody` for an item whose
      `interactionType` was imported with degraded parsing
- [ ] 4.2 Add `OCA\Scholiq\Controller\QtiExportController` (SPDX; thin): single `export()` action,
  `itemBankId` param, `#[NoAdminRequired]`, `ActionAuthService::requireAction(..., 'qti.export')`, returns a
  `DataDownloadResponse` ZIP.
  - **spec_ref**: `specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package`
  - **acceptance_criteria**:
    - Unauthenticated request rejected; unknown `itemBankId` returns a clean 404/422, not a fatal error
- [ ] 4.3 Add `OCA\Scholiq\Service\CoursePackageExportService` (SPDX; `course-management`): given a `Course`
  UUID, walks its `Lesson`/`Material`/`Assessment`/`Rubric`/`LtiToolPlacement` descendants through
  OpenRegister's own object-read API (so `x-property-rbac` is enforced by construction, never bypassed),
  resolves `Material.fileRef` bytes via OR's file-attachment API, calls `QtiExportService` for any
  referenced `ItemBank`s, and builds both (a) a CC 1.3 `imsmanifest.xml` + resources ZIP and (b) a
  scholiq-native JSON tree.
  - **spec_ref**: `specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments`
  - **acceptance_criteria**:
    - Unit tests cover: CC export contains a manifest entry per Lesson/Material/Item; scholiq-native export
      round-trips back into an equivalent object graph when fed to `CoursePackageImportService`; a field
      hidden from the exporting user by `x-property-rbac` is absent from both export forms
- [ ] 4.4 Add `OCA\Scholiq\Controller\CoursePackageExportController` (SPDX; thin, mirrors
  `AuditPackExportController`'s ZIP-in-memory pattern): single `export()` action, `courseId` + `format`
  (`common-cartridge` | `scholiq-json`) params, `#[NoAdminRequired]`, `ActionAuthService::requireAction(...,
  'course-package.export')`, returns a `DataDownloadResponse`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments`
  - **acceptance_criteria**:
    - Both `format` values produce a downloadable response; an invalid `format` value is rejected with a
      clear error

## 5. Routes

- [ ] 5.1 Register `coursePackageImport#import` (`POST /api/course-management/course-package-import`),
  `coursePackageExport#export` (`GET /api/course-management/course-package-export`), and `qtiExport#export`
  (`GET /api/assessment/qti-export`) in `appinfo/routes.php`, following the existing `qtiImport#import`
  entry's shape and comment style.
  - **spec_ref**: all requirements in this change
  - **acceptance_criteria**:
    - `occ router:list` (or the OR route-reachability gate) resolves all three routes to an existing
      controller method

## 6. Frontend

- [ ] 6.1 Add `src/manifest.json` index/detail pages for `CoursePackageImportReport` (list/detail per the
  standard declarative pattern; detail page renders `entries` via a table widget).
  - **spec_ref**: `specs/course-management/spec.md#requirement-course-package-frontend-is-declarative-with-one-named-custom-view-for-the-import-report`
  - **acceptance_criteria**:
    - Pages render seeded report fixtures; no PHP CRUD controller added for CRUD (only the thin
      upload/export controllers from sections 3–4)
- [ ] 6.2 Add `src/views/CoursePackageImportView.vue`: file-upload control (CC/`.mbz`), calls
  `coursePackageImport#import`, then renders the returned `CoursePackageImportReport`'s `entries` table
  with an `outcome` filter (`imported`/`degraded`/`dropped`); strings via `t()`; any `NcSelect` carries
  `inputLabel`; no DOM-attribute reads (initial state via the standard OR object API). Add a manifest menu
  entry linking from the Course detail page.
  - **spec_ref**: `specs/course-management/spec.md#requirement-course-package-frontend-is-declarative-with-one-named-custom-view-for-the-import-report`
  - **acceptance_criteria**:
    - Upload + report render flow works against a seeded/mocked response; filter control changes the
      visible rows
- [ ] 6.3 Add a `CoursePackageExport` manifest page (`type: custom`, `component: CnExportWizard`), mirroring
  `AuditPackExport` (`src/manifest.json:1969-1976`) — scope (Course) + format (`common-cartridge` |
  `scholiq-json`) picker driving `coursePackageExport#export`. No new Vue file.
  - **spec_ref**: `specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments`
  - **acceptance_criteria**:
    - Page renders via the existing `CnExportWizard` component with Course scope + the two format options

## 7. Tests and docs

- [ ] 7.1 PHPUnit for `QtiImportService::importFromDirectory()` (regression), `MbzExtractor`,
  `CommonCartridgeParser`, `MoodleBackupParser`, `MoodleQuizQuestionMapper`, `CoursePackageImportService`,
  `QtiExportService`, `CoursePackageExportService` per the acceptance criteria in tasks 2.1–4.3 (minimum
  75% coverage for new code per ADR-009). Fixture archives (a minimal CC 1.3 `.imscc` and a minimal Moodle
  `.mbz`) added under `tests/fixtures/course-packages/`.
  - **spec_ref**: all requirements
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 7.2 Add `tests/e2e/spec-coverage/course-package-import-export.spec.ts` (Playwright): an instructional
  designer opens `CoursePackageImportView`, uploads a seeded package, and sees the resulting report with
  its `outcome` filter.
  - **spec_ref**: `specs/course-management/spec.md#scenario-an-instructional-designer-uploads-a-package-and-sees-the-report`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` reference in the spec scenario
- [ ] 7.3 Add Dutch and English translations for all new i18n keys (ADR-005).
  - **spec_ref**: all requirements
  - **acceptance_criteria**:
    - No hardcoded strings in `CoursePackageImportView.vue`; `nl`/`en` both populated

## 8. Verify

- [ ] 8.1 `openspec validate course-package-import-export --strict` clean; PHPUnit green for all new PHP
  classes plus the `QtiImportService` regression suite; Playwright
  `course-package-import-export.spec.ts` green; no dangling `$ref`s in the register JSON; a
  round-trip smoke test (export a seeded course as scholiq-native JSON, re-import it, diff the resulting
  object graph) passes.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; round-trip smoke test passes
