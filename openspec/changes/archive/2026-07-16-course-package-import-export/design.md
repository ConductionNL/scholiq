# Design: course-package-import-export

## Context

Scholiq's `assessment` capability already imports *items* out of a QTI 2.x/3.0 or Common Cartridge package
(`QtiImportService`, verified at `lib/Service/QtiImportService.php`) but discards everything else in the
package — course outline, web content, weblinks, LTI placements. There is no exporter of any kind. ADR-002
named Common Cartridge import as a deferred "Phase 3" item (`openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md:72`)
that was never built. This document works out what a *course-level* package importer/exporter adds on top of
the existing item-level machinery, what it can and cannot preserve, how failures are surfaced instead of
silently dropped, and why the parsing work belongs in scholiq rather than openconnector.

## Goals / Non-Goals

**Goals**
- Import a Common Cartridge 1.3 or Moodle `.mbz` package into `Course`/`Lesson`/`Material`/`Item`/`ItemBank`/
  `Rubric`/`LtiToolPlacement`, reusing (not duplicating) the existing QTI/LTI machinery.
- Never silently drop content: every source resource gets a `CoursePackageImportReport` entry stating whether
  it was imported, degraded, or dropped, and why.
- Export a full course (structure, materials with resolved file bytes, items, rubrics) as both a portable
  Common Cartridge 1.3 package and a lossless scholiq-native JSON tree.
- Close the "Items use QTI 3.0 as canonical form" requirement's missing export half.
- Keep the same ADR-031/ADR-022 discipline the rest of the app holds: declarative where possible, PHP only
  for the external-format parsing/generation that cannot be declarative.

**Non-Goals**
- Full Moodle quiz-question-type parity (Moodle's `quiz/quiz.xml` question bank format is not QTI and has
  ~20 question subtypes; this change maps the common ones and reports the rest as dropped — see the fidelity
  table).
- cmi5/SCORM lesson-*content* package import. That is ADR-002's own separate, still-unbuilt gap
  (`POST /api/courses/{id}/lessons/import`, a `ScormController` — neither exists in `lib/`, and
  `Cmi5LaunchTokenService` is a confirmed disabled stub). This change's importer detects an embedded
  cmi5/SCORM resource inside a CC/mbz package and reports it as `dropped` with reason "requires ADR-002's
  lesson-content importer, not yet implemented" — it does not attempt to wire into a path that does not work.
- Discussion-forum / wiki / glossary content. No scholiq schema represents any of these; reported as
  `dropped`.
- Gradebook-column (CurriculumPlan component weighting) round-trip. Neither CC nor Moodle backups encode this
  in a form any two LMS agree on; `grading`'s `CurriculumPlan` stays a separately-configured, institution-set
  artifact, as it already is for every other course.
- A general-purpose "any external LMS" importer. Two formats (CC 1.3, Moodle `.mbz`) cover the competitors
  named in the Specter research (Moodle-derived state platforms) and the interop standard (CC); a third
  format is a follow-up if a specific buyer needs it.

## Routing: scholiq, not openconnector

`data-exchange`'s spec (`openspec/specs/data-exchange/spec.md:72-79`) draws this line explicitly: Scholiq
"MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, or SAML/OAuth attribute-release **wire protocols**" —
those are live, ongoing conversations with a named external *system* (BRON, OSO, a municipality, SURFconext),
each configured as an OpenConnector source/target connection. Course-package import/export is a different
shape entirely:

- It is a **one-shot file transform**, not a protocol conversation. A user uploads a ZIP/tar once; there is
  no ongoing handshake, no target system to configure a connection against, no `DataExchangeJob` to queue
  (there is nothing to retry against an external endpoint — the "external system" is a file the user already
  has).
- It requires **deep knowledge of Scholiq's own content model** (which CC resource types become a `Lesson`
  vs. a `Material` vs. an `Item`; how `Course.parentCourseId` recursion should represent a CC "item folder";
  which fields `CoursePackageImportReport` needs) — the opposite of openconnector's job, which is to be
  content-model-agnostic and configuration-driven.
- **The precedent already exists and already lives in scholiq.** `QtiImportService`'s own docblock names
  exactly this exception: "Legitimate PHP per ADR-031 §'External-format import': parsing ZIP/XML from an
  external interchange format (QTI, IMS CC) cannot be expressed declaratively" (`lib/Service/QtiImportService.php:10-11`).
  That service already parses Common Cartridge archives — just only the QTI items inside them. Extending the
  same service family to read the rest of the same archive is the same exception at a wider scope, not a new
  one. Filing this as an openconnector adapter would mean openconnector re-implementing Scholiq's own
  `Course`/`Lesson`/`Material`/`Item` schema-mapping knowledge, duplicating logic that must stay in sync with
  every future schema change to those objects — exactly the "apps consume, they don't duplicate" ADR-022
  discipline argues against, just inverted (here scholiq's own domain knowledge would be duplicated
  elsewhere).
- **LTI stays split the same way it already is.** `course-management`'s existing `LtiToolPlacement` +
  `LessonPlayer` requirements (`openspec/specs/course-management/spec.md:89-148`) already draw this exact
  line for LTI: scholiq owns the *placement* (which Lesson references which tool, with which grade mapping),
  openconnector owns the *protocol* (OIDC launch, JWT signing — `LessonPlayer delegates the OIDC launch to
  the openconnector adapter`, same spec, lines 117-148). A CC package's `basiclti` resource maps to a new
  `LtiToolPlacement` row exactly as if an instructional designer had placed it by hand; the actual launch
  still goes through openconnector, unchanged. Course-package import does not touch openconnector at all —
  it only creates the same placement object a human would have created manually.

## Format support matrix

| Format | Import | Export | Notes |
|---|---|---|---|
| IMS Common Cartridge 1.3 (`imsmanifest.xml`, `imsccv1p3`) | Yes — structure, web content, weblinks, QTI items, LTI placements | Yes — full course as CC 1.3 | Primary interop format; reuses QTI/LTI machinery already in the app |
| Moodle backup (`.mbz`, gzipped tar with `moodle_backup.xml`) | Yes — narrower subset (see fidelity table) | No | Import-only: Moodle's own backup format is not a portable export target for a non-Moodle system; export targets CC instead |
| Scholiq-native JSON | Yes (round-trip of this change's own export) | Yes | Lossless — the only format guaranteed to round-trip every scholiq field, including ones no external standard has a slot for (e.g. `Assessment.proctoring`, `x-property-rbac` on graded objects is never exported — see Security Posture) |
| QTI 3.0 item package (ItemBank-level, not course-level) | Already implemented (`QtiImportService`, pre-existing) | New in this change (`QtiExportService`) | Completes the `assessment` spec's existing import-only requirement |

## Fidelity / loss table

Every row below is one line a `CoursePackageImportReport` entry can produce. "Maps cleanly" entries need no
report entry beyond `imported`; everything else MUST produce a `degraded` or `dropped` entry with the stated
reason — this is not optional per-format behavior, it is the mechanism itself (see next section).

| Source element | Outcome | Target / reason |
|---|---|---|
| CC organization tree (folders, ordering) | Maps cleanly | Folder → child `Course` (`parentCourseId` set, reusing the existing module-as-a-course recursion already documented for `ectsCredits`, `lib/Settings/scholiq_register.json` `Course.parentCourseId`); leaf item → `Lesson` (`order` from manifest position) |
| CC web content / associated content resource | Maps cleanly | `Material` (`kind: document`, `fileRef` into OR's file-attachment API, `courseId`/`lessonId` set) |
| CC web link resource | Maps cleanly | `Material` (`kind: link`, `url` set, no `fileRef`) |
| CC/QTI assessment item (`imsqti_item_xmlv2p*`/`v3p0`) | Maps cleanly (delegates to the existing QTI item importer) | `Item` in a new or matched `ItemBank`; interaction-type parsing limits are the *pre-existing* `QtiImportService` limitation (see proposal "Why"), unchanged by this work — `choice`/`extendedText` fully parsed, others import with raw `qtiBody` preserved and a `degraded` report entry, never dropped |
| CC `basiclti` resource | Maps cleanly | `LtiToolPlacement` (`launchMode: resource-link`); the actual OIDC launch is unaffected — see "Routing" above |
| Moodle section/module structure | Maps cleanly | Same `Course`/`Lesson` mapping as CC's organization tree |
| Moodle `resource`/`page`/`url` module | Maps cleanly | `Material` (`document`/`link` per module type) |
| Moodle `quiz` module, single-answer/multi-answer/short-answer/essay questions | Degraded | `Item` created from Moodle's own question XML (not QTI — a `MoodleQuizQuestionMapper`, separate from `QtiImportService`, produces the same `Item` shape); `correctResponse` carried for the subset above, `degraded` report entry naming the Moodle question-type coverage gap for anything else (Moodle has ~20 question subtypes; this change maps the four most common) |
| Moodle `quiz` module, other question subtypes (drag-and-drop, calculated, cloze/embedded, random) | Dropped | No scholiq representation attempted; report entry names the Moodle question type and recommends manual re-authoring via `ItemAuthorView` |
| Moodle `assign` module | Degraded | `Assignment` (existing schema, `assignments` capability, unmodified by this change) created with `title`/`instructions`/`dueAt`/`maxPoints`; Moodle-specific grading-workflow config (peer review, group config beyond `groupSubmission`) is not carried, reported as `degraded` |
| CC/Canvas proprietary rubric extension | Degraded (best-effort) | `Rubric` (`criteria`, `maxPoints`) when the vendor-specific rubric XML is present and parseable; `dropped` with reason when the extension is absent or unparseable — this is explicitly best-effort, not a formal CC 1.3 feature |
| cmi5/SCORM package embedded in a CC/mbz archive | Dropped | Reason: "requires ADR-002's lesson-content importer, not yet implemented" — see Non-Goals; never silently absorbed into a broken path |
| Discussion topic / forum / wiki / glossary (CC `imsdt`, Moodle `forum`/`wiki`/`glossary` modules) | Dropped | No scholiq schema represents any of these; reported by name so the institution knows to migrate this content manually, not assume it happened |
| Gradebook column / weighting definitions | Not attempted | Neither format encodes this portably across LMS vendors; `grading`'s `CurriculumPlan` is configured separately, as it is for every course regardless of import source — this is documented in the report as informational, not as a failure |

## The import-report mechanism

`CoursePackageImportReport` (new OpenRegister object, `course-management`) is the structural anti-Canvas
promise: the report exists whether the import fully succeeds, partially succeeds, or fails, and it names
every resource, not just a success count.

```
CoursePackageImportService (extraction + manifest walk)
   │  for each manifest resource:
   │    → CommonCartridgeParser / MoodleBackupParser resolves resource type
   │    → routes to: Course/Lesson creation | Material creation |
   │      QtiImportService::importFromDirectory() (QTI items) |
   │      MoodleQuizQuestionMapper (Moodle quiz items) |
   │      LtiToolPlacement creation | (nothing — reported dropped)
   │    → appends one CoursePackageImportReport.entries[] row: {resourceIdentifier, resourceType,
   │      title, outcome: imported|degraded|dropped, targetType, targetId, reason}
   ▼
CoursePackageImportReport (lifecycle: running → succeeded | partial | failed)
   summary: {resourcesTotal, resourcesImported, resourcesDegraded, resourcesDropped}
   courseId: the created top-level Course (nullable — null only on `failed`, when no Course was created)
```

`lifecycle` resolves to `succeeded` only when `resourcesDegraded == 0 && resourcesDropped == 0`; any
`degraded`/`dropped` entry forces `partial`; a package that cannot be opened/parsed at all (corrupt archive,
unrecognised manifest) produces `failed` with zero entries and an `errorMessage`. `partial` is a normal,
expected, non-error terminal state — it is the honest default for any real-world package, not an edge case.
The report is a first-class declarative index/detail page (`src/manifest.json`), and the one custom view,
`CoursePackageImportView`, renders the entries table with `outcome` as a filterable column so an
instructional designer can see, in one place, "here is everything that came in, and here is everything that
didn't and why" — the structural difference from Canvas's approach, where the loss discovery happened after
the fact, by instructors, in production courses.

The export side needs no new custom Vue component at all: `AuditPackExport` (`src/manifest.json:1969-1976`)
already establishes the pattern of a declarative `type: custom, component: CnExportWizard` manifest page
mounting the shared `@conduction/nextcloud-vue` export-wizard component for a server-side ZIP-bundle
download. `CoursePackageExport` reuses the identical page shape (scope picker → format choice → server-side
bundle), so this change's only genuinely new frontend code is `CoursePackageImportView`.

## Data Model

```
CoursePackageImportReport (new)
   courseId ──> Course (nullable until a Course is created)
   entries[] (embedded array, not a separate schema — no independent lifecycle per entry, purely descriptive)

CoursePackageImportService
   ├─ CommonCartridgeParser   (imsmanifest.xml walk, CC 1.3 resource-type routing)
   ├─ MoodleBackupParser      (gzipped-tar extraction via PharData, moodle_backup.xml walk)
   ├─ MoodleQuizQuestionMapper (Moodle question XML → Item, single/multi/short-answer/essay subset)
   └─ QtiImportService::importFromDirectory()  (existing service, refactored to accept an
        already-extracted directory so CoursePackageImportService and the existing
        QtiImportController share one parse path instead of two)

CoursePackageExportService                       QtiExportService
   ├─ builds CC 1.3 imsmanifest.xml + resources    └─ wraps Item.qtiBody (already-valid QTI 3.0 XML,
   ├─ builds scholiq-native JSON tree                  verbatim from ItemAuthorView/QtiImportService)
   └─ resolves Material.fileRef via OR's file-             in a QTI 3.0 package manifest
        attachment API (bytes never duplicated
        into OpenRegister; same contract Material's
        own schema description already states)
```

### `CoursePackageImportReport` fields

`sourceFormat` (`common-cartridge-1.3` | `moodle-backup`, required), `sourceFilename` (required),
`courseId` ($ref `Course`, nullable), `importedBy` (NC user id, required), `importedAt` (date-time,
required), `lifecycle` (`running → succeeded | partial | failed`), `resourcesTotal`/`resourcesImported`/
`resourcesDegraded`/`resourcesDropped` (integers), `errorMessage` (nullable, `failed` only), `entries`
(array of `{resourceIdentifier, resourceType, title, outcome, targetType, targetId, reason}`), `tenant_id`.
Additive-only against the register; no existing schema's shape changes.

### Why extraction is refactored, not duplicated

`QtiImportService::import()` currently does extraction (`extractZip`) and parsing (`collectItemPaths` +
`importSingleItem`) as one method. `CoursePackageImportService` needs the same ZIP-extraction primitive
(zip-slip/decompression-bomb guards already hardened there, `lib/Service/QtiImportService.php:144-248`,
fixes for #207) plus its own manifest walk over the *rest* of the package. Rather than copy the
extraction/security logic, `QtiImportService::import()` is split into `extractZip()` (already private,
unchanged) → `importFromDirectory($dir, $itemBankId, $tenantId)` (new, contains the existing
`collectItemPaths`/`importSingleItem` loop) → `import()` becomes a two-line wrapper (`extractZip` then
`importFromDirectory`). `CoursePackageImportService` extracts the CC/mbz archive with its *own* extractor
(Moodle's `.mbz` is a gzipped tar, not a ZIP — `ZipArchive` cannot open it; a separate `MbzExtractor` using
PHP's `PharData` is required for that format only, CC stays on the existing `ZipArchive` path) and, for the
QTI-item-bearing subset of a CC package, calls `QtiImportService::importFromDirectory()` directly on its own
already-extracted directory — one extraction per package, one parser per format, zero duplicated security
logic.

## Rejected Alternatives

- **Route CC/mbz parsing through openconnector as a new adapter.** Rejected — see "Routing" above. This is a
  one-shot file transform requiring Scholiq's own schema-mapping knowledge, not a wire-protocol conversation
  with a configured external system; the existing `QtiImportService` precedent already establishes this stays
  in scholiq, and openconnector would have to duplicate Scholiq's `Course`/`Lesson`/`Material`/`Item` mapping
  knowledge to do it, which is the wrong direction for ADR-022's "apps consume, don't duplicate" discipline.
- **Queue import/export as a `DataExchangeJob`, matching the `data-exchange` pattern.** Rejected — that
  pattern exists for jobs with a named external *target* connection to retry against (BRON, OSO, a
  municipality). A course-package import has no external target: the file is already on the user's machine,
  the "job" is a single synchronous parse-and-create operation with no retry-against-a-remote-system
  semantics, and `CoursePackageImportReport`'s own lifecycle (`running → succeeded | partial | failed`)
  already gives it the same auditable state machine without borrowing a schema shaped for something else.
- **Silently skip unsupported resource types (no report).** Rejected — this is precisely the Canvas failure
  mode the proposal's "Why" documents. A per-resource, reason-bearing report is the entire point of this
  change; skipping it would ship the same trust problem it exists to solve.
- **Attempt full Moodle quiz-question-type parity in this change.** Rejected for an M-sized change — Moodle's
  own question bank format has ~20 subtypes with no QTI equivalent for several of them (drag-and-drop,
  calculated, cloze). Mapping the four most common (single/multi/short-answer/essay) covers the realistic
  majority of a school's item bank; the rest reports as `dropped` by name rather than blocking the whole
  import or fabricating a lossy conversion. A follow-up change can widen coverage without touching this
  change's schema or report mechanism.
- **Export course content as a scholiq-native format only (skip Common Cartridge export).** Rejected — the
  anti-lock-in promise requires a *portable* export a competing LMS can actually import, not just a format
  only Scholiq itself can read back. CC export is kept alongside the scholiq-native JSON (which stays the
  lossless round-trip target for re-importing into another Scholiq tenant) rather than replacing it.
- **Build a generic "any LMS" plugin-based importer architecture up front.** Rejected — two concrete formats
  (CC 1.3, Moodle `.mbz`) cover the named competitor landscape; a plugin architecture for hypothetical future
  formats is speculative generality this change does not need yet, and `CommonCartridgeParser`/
  `MoodleBackupParser` as two concrete classes behind `CoursePackageImportService`'s format-detection branch
  is easy to extend later without a premature abstraction now.

## Security / Privacy Posture

- Both new controller actions are gated via the existing ADR-023 `ActionAuthService::requireAction()` pattern
  (`course-package.import`, `course-package.export`), seeded `["admin"]`-only by default in
  `lib/actions.seed.json`, broadenable via Admin Settings — the same default posture `qti.import` already
  holds, and for the same reason: importing/exporting a full course is a higher-blast-radius action than
  authoring a single item.
- Import inherits `QtiImportService`'s existing hardening (zip-slip protection, decompression-bomb caps,
  per-file size caps — fixes for #207, `lib/Service/QtiImportService.php:144-248`) for the CC path; the new
  `MbzExtractor` for Moodle's gzipped-tar format applies the same total-size/per-file caps and path-traversal
  resolution, ported rather than re-invented.
- Every created object (`Course`, `Lesson`, `Material`, `Item`, `Rubric`, `LtiToolPlacement`,
  `CoursePackageImportReport`) is stamped with the caller's resolved `tenant_id`, mirroring
  `QtiImportController`'s existing per-user tenant resolution (`lib/Controller/QtiImportController.php:120-131`)
  — cross-tenant contamination via an uploaded package is structurally prevented the same way cross-tenant
  `ItemBank` poisoning already is.
- Export never emits an object's `x-property-rbac`-restricted fields beyond what the exporting user is
  authorized to read — `CoursePackageExportService` builds its payload through OpenRegister's own object read
  API (which already applies `x-property-rbac`), not a raw database query, so an export can never leak a
  field the exporting admin could not already see in the UI.
- `CoursePackageImportReport.entries[]` names resource titles verbatim from the uploaded package (which may
  include personal names in a Moodle gradebook comment, for instance) — the report itself carries the same
  `tenant_id` scoping and the same `x-openregister-authorization`-gated access as every other object in the
  register; it is not a public artefact.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects; the one new schema (`CoursePackageImportReport`) is additive, no new
  database tables (ADR-001).
- No pass-through CRUD controller — `CoursePackageImportController`/`CoursePackageExportController`/
  `QtiExportController` are thin per ADR-022, exactly the shape `QtiImportController`/
  `AuditPackExportController` already hold; all parsing/generation logic lives in the `Service` classes.
- PHP is limited to the ADR-031 "External-format import"/"document generation" exceptions already exercised
  by `QtiImportService`/`AuditPackExportController` — no new exception category is introduced.
- Wire protocols stay in openconnector (unchanged — this change touches openconnector not at all; see
  "Routing").
- UI is manifest-driven; the one custom view (`CoursePackageImportView`) is a genuine non-CRUD interaction
  (upload + live report), the same bar `ItemAuthorView`/`ProctoringReviewQueue` were held to.
- i18n keys in English; SPDX headers on all new PHP files.
