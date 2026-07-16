---
kind: code
depends_on: []
---

## Why

**Migration fidelity is a trust weapon, and Scholiq cannot make the anti-lock-in promise credibly today
because course-level exchange does not exist.**

- **Canvas's forced New Quizzes migration is the live cautionary tale.** Instructure required institutions to
  migrate off Classic Quizzes, and the migration is documented — by the receiving universities themselves, in
  public help-desk pages, not by a competitor's marketing — as lossy: Question Groups do not transfer at all
  ([Alamo Colleges: "Question Groups will NOT transfer during migration"](https://alamocolleges.screenstepslive.com/a/1469553-new-quizzes-transition-timeline-and-faq)),
  embedded media in Classic Quizzes does not display after migration, LaTeX formulas convert to
  non-editable images, and answer-level feedback survives only for Multiple Choice questions
  ([Stanford: "Missing or Changed Functionality in New Quizzes"](https://canvashelp.stanford.edu/hc/en-us/articles/4405462061843-Missing-or-Changed-Functionality-in-New-Quizzes)),
  and Surveys are unsupported entirely, with a fix not promised until Q4 2025–Q1 2026
  ([UChicago Academic Technology, Oct 2025](https://academictech.uchicago.edu/2025/10/21/new-quizzes-migration/)).
  Every one of these is a public, dated, institution-authored loss inventory of exactly the content types
  Scholiq's own `assessment` capability persists (`Item.correctResponse`, `AssessmentResult` feedback,
  `ProctoringSession` — `lib/Settings/scholiq_register.json` schemas `Item`/`Assessment`).
- **The EU/German incumbent landscape a switching school comes from is Moodle-centric, not proprietary.**
  North Rhine-Westphalia's state platform LOGINEO NRW ships "a learning management system based on Moodle";
  Baden-Württemberg's SCHULE@BW offers a choice of "the Moodle and itslearning learning management systems"
  ([DigiBitS: German state education-platform overview](https://www.digibits.de/materialien/lehr-und-lernplattformen-der-bundeslaender-im-ueberblick/);
  [Interoperable Europe Portal on HPI Schul-Cloud/Sciebo](https://interoperable-europe.ec.europa.eu/collection/open-source-observatory-osor/document/use-open-source-cloud-education-cases-hpi-schul-cloud-and-sciebo-germany)).
  A school cannot adopt Scholiq if its years of Moodle course content (structure, materials, quiz banks,
  rubrics) are trapped in a `.mbz` backup with no path in. Without an import path, Scholiq is not a candidate
  for the largest addressable EU public-sector replacement market; without an export path, Scholiq cannot
  credibly claim the EUPL-1.2 "your content is yours" position against that same incumbent.
- **The gap is real and specific, verified at HEAD, not assumed.** `assessment`'s "Items use QTI 3.0 as
  canonical form" requirement (`openspec/specs/assessment/spec.md:53-59`) already requires QTI 2.x +
  Common Cartridge **item** import, and it is genuinely implemented: `QtiImportService`
  (`lib/Service/QtiImportService.php:1-624`) extracts a ZIP, detects `qti3`/`qti2`/`cc` packages
  (`detectPackageType()`, lines 259-287), and creates `Item` objects — including pulling QTI items out of a
  Common Cartridge archive (`collectItemPaths()`'s `$isCcItem` branch, lines 337, and the class docblock's own
  "IMS Common Cartridge 1.x — extracts QTI items", lines 16-17). This is wired to a real endpoint
  (`QtiImportController.php`, route `qtiImport#import` at `appinfo/routes.php`). **But it only ever extracts
  the QTI items out of a CC package and discards everything else** — the CC organization/outline, web-content
  resources, weblinks, and LTI placements a real course package carries are never read. There is no
  course-level importer, and **no exporter of any kind exists anywhere in `lib/`** (confirmed: no file in
  `lib/Controller/` or `lib/Service/` matches `Export` except `AuditPackExportController.php`, which exports
  the compliance audit log, not course content).
- **ADR-002 itself already named this gap and deferred it.** `openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md:72`
  states plainly: "Common Cartridge (`.imscc`) is supported via a separate importer in Phase 3." That importer
  was never built — this change is that deferred Phase 3 piece, scoped to the course-*package* layer (not
  the cmi5/SCORM lesson-*content* layer, which ADR-002 §"Content packaging" also describes
  (`POST /api/courses/{id}/lessons/import`, a `ScormController`) but which likewise was never implemented:
  confirmed by `find lib/Controller lib/Service` returning no `ScormController`/`LessonImport*` file at all,
  and by `Cmi5LaunchTokenService.php`'s own docblock (lines 17-21) stating it is a disabled stub
  ("`isEnabled()` returns false until the course-management change ships"). That is a separate, pre-existing
  gap this change does not attempt to close — see "Out of Scope" in `design.md`.
- **The QTI 3.0 canonical form is stored losslessly already, which makes item-level export nearly free.**
  Both the import path (`QtiImportService::importSingleItem()`, `lib/Service/QtiImportService.php:442`,
  `$qtiBody = $xml->saveXML();`) and the in-app author path (`ItemAuthorView.vue:409-417`, `buildQtiBody()`)
  persist a verbatim QTI 3.0 XML string on every `Item`. Item-level QTI *export* — the missing half of the
  "Items use QTI 3.0 as canonical form" requirement — is therefore a genuinely low-risk addition: it wraps
  already-stored, already-valid XML in a manifest, it does not need to re-derive anything the import parser
  currently can't (the interaction-type TODO documented in `QtiImportService.php:18-20` is an *import*
  limitation and is untouched by this change).
- **Routing precedent already exists and this change follows it rather than inventing a new rule.** The
  `data-exchange` spec draws the scholiq-vs-openconnector line explicitly: "Scholiq MUST NOT implement
  Edukoppeling, StUF, OSO-XML, OOAPI, or SAML/OAuth attribute-release **wire protocols**"
  (`openspec/specs/data-exchange/spec.md:72-79`) — i.e. openconnector owns live protocol conversations with
  external *systems*. Course-package import/export is not a wire protocol: it is a one-shot user-uploaded
  ZIP/tar **file format** requiring a content-model transform into Scholiq's own OpenRegister schemas — the
  exact category `QtiImportService`'s own docblock already claims as an ADR-031 "External-format import"
  exception (`lib/Service/QtiImportService.php:10-11`): "parsing ZIP/XML from an external interchange format
  (QTI, IMS CC) cannot be expressed declaratively." This change is the same category at a wider scope, not a
  new precedent — see `design.md` "Routing: scholiq, not openconnector" for the full argument.

## What Changes

- **Course-level package import** (`course-management` delta): a new `CoursePackageImportService`
  (ADR-031 "External-format import" exception, same category as `QtiImportService`) parses an **IMS Common
  Cartridge 1.3** or **Moodle backup (`.mbz`)** archive and materialises `Course`/`Lesson`/`Material`
  hierarchy, delegating embedded QTI/CC-assessment resources to a refactored, shared
  `QtiImportService::importFromDirectory()` (extracted from the existing `import()` so both callers share one
  ZIP-extraction-then-parse path instead of duplicating it) so `Item`/`ItemBank` creation stays owned by
  `assessment`, not duplicated here.
- **A new `CoursePackageImportReport` OpenRegister object** — the anti-Canvas promise made structural: every
  resource in the source package gets one entry (`imported` / `degraded` / `dropped`) with a reason, never a
  silent omission. Surfaced as a declarative detail page.
- **Course-level package export** (`course-management` delta): a new `CoursePackageExportController` (mirrors
  the existing `AuditPackExportController.php` ZIP-in-memory pattern) streams a course as (a) an IMS Common
  Cartridge 1.3 package for cross-platform interop and (b) a scholiq-native JSON tree for lossless round-trip
  — both including `Material.fileRef` bytes resolved through OpenRegister's native file-attachment API (the
  same "app does not store file bytes, OR does" contract `Material`'s own schema description already states).
- **Item-level QTI 3.0 export** (`assessment` delta, ADDED requirement): an `ItemBank` can be exported as a
  QTI 3.0 package, completing the import-only "Items use QTI 3.0 as canonical form" requirement into a real
  round-trip. This is consumed by the course export above (an exported course's assessment resources are QTI
  3.0, not a scholiq-only format) and is independently useful for an item author moving a bank between
  Scholiq tenants.
- **Honest, bounded format support**: Common Cartridge gets fuller fidelity (structure, web content, weblinks,
  QTI items, LTI placements) because it reuses the existing QTI/LTI machinery; Moodle `.mbz` gets a narrower,
  explicitly-scoped subset (structure, resource/page/url modules, a best-effort quiz-module mapping) because
  Moodle's own quiz XML is not QTI and a full parser for it is out of scope for an M-sized change — see
  `design.md`'s format-support matrix and fidelity/loss table for the exact line.

## Impact

- **`lib/Settings/scholiq_register.json`** — one new schema, `CoursePackageImportReport` (course-management).
  No changes to existing schemas' `required`/shape (purely additive).
- **New PHP** — `OCA\Scholiq\Service\CoursePackageImportService`, `OCA\Scholiq\Service\CommonCartridgeParser`,
  `OCA\Scholiq\Service\MoodleBackupParser`, `OCA\Scholiq\Controller\CoursePackageImportController`,
  `OCA\Scholiq\Controller\CoursePackageExportController`, `OCA\Scholiq\Service\CoursePackageExportService`,
  `OCA\Scholiq\Service\QtiExportService`, `OCA\Scholiq\Controller\QtiExportController`. One refactor:
  `QtiImportService::import()` split to extract a reusable `importFromDirectory()`.
- **`appinfo/routes.php`** — three new `#[NoAdminRequired]` routes:
  `coursePackageImport#import`, `coursePackageExport#export`, `qtiExport#export`.
- **`lib/actions.seed.json`** — three new ADR-023 actions, `course-package.import`, `course-package.export`,
  and `qti.export` (default `["admin"]`, broadenable via Admin Settings, mirroring `qti.import`'s existing
  default).
- **`src/manifest.json`** — index/detail pages for `CoursePackageImportReport`; one new custom view,
  `CoursePackageImportView` (upload + live report), reusing `ItemAuthorView`'s "custom view for a genuine
  non-CRUD interaction" precedent; the export surface reuses the existing `CnExportWizard` shared component
  (the same declarative `type: custom, component: CnExportWizard` pattern `AuditPackExport`
  (`src/manifest.json:1969-1976`) already uses) rather than adding a second bespoke view.
- **Affected specs**: `course-management` (ADDED: course-package import/export requirements + the report
  schema), `assessment` (ADDED: ItemBank QTI 3.0 export). Neither spec's existing requirements are modified.
- **Out of scope** (see `design.md` for the full list and reasoning): Moodle full quiz-question-type parity;
  cmi5/SCORM lesson-content package import (ADR-002's separate, still-unbuilt `ScormController`/
  `lessons/import` gap); discussion-forum content (no scholiq schema exists for it); gradebook-column
  round-trip (CC does not standardize this portably; `grading`'s `CurriculumPlan` is configured separately).
