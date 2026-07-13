---
kind: code
depends_on: []
---

## Why

Scholiq has no in-app way to compose a Course's teaching content. Confirmed by reading every file in
`src/views/` (`ls src/views/` — 23 entries): `LessonPlayer.vue` *plays* a lesson and `ItemAuthorView.vue`
authors QTI 3.0 quiz **items** (`interactionType` choice/extendedText, `qtiBody`, `correctResponse` —
`src/views/ItemAuthorView.vue:1-22` docblock, `:190-229` form model). Neither, nor anything else in the
tree, lets an instructional designer build a **Lesson** or arrange a **Course**'s module/lesson tree. The
gap is structural, not cosmetic:

- **`LessonPlayer.vue` renders fields the `Lesson` schema doesn't have.** `LessonPlayer.vue:39-47` and
  `:93-95` read `lesson.title`, `lesson.summary`, and `lesson.content` (`v-html="sanitisedContent"`,
  `:173-175`). The `Lesson` object in `lib/Settings/scholiq_register.json` (properties: `courseId`, `name`,
  `order`, `contentType`, `contentRef`, `durationMinutes`, `learningObjectives`, `mandatoryTraining`,
  `regulationSlug`, `lifecycle`, `tenant_id`) has none of `title`, `summary`, or `content` — the player's
  "text lesson" rendering path is dead code; it has never had real data to display, because nothing has ever
  written a lesson body.
- **`Lesson.order` is a bare 1-based integer with zero reorder UI.** `lib/Settings/scholiq_register.json`
  (`Lesson.order`: `"type": "integer", "minimum": 1"`) with no drag-and-drop, no move-up/move-down control
  anywhere in `src/`. A grep across `src/` for `draggable|sortable|dragstart|dragover|vuedraggable|moveUp|
  moveDown` returns zero hits.
- **`Course` has no `order` field at all.** `Course` is recursive — `parentCourseId` (`$ref: Course`) makes
  a "module" just a child `Course` — but the schema (`lib/Settings/scholiq_register.json`, `Course`
  properties: `code`, `name`, `name_nl`, `description`, `level`, `language`, `tags`, `mandatoryTraining`,
  `regulationSlug`, `renewalCourseSlug`, `certificateTemplate`, `lifecycle`, `parentCourseId`,
  `curriculumPlanId`, `programmeIds`, `ectsCredits`, `tenant_id`) never declares how sibling modules under
  one parent are sequenced.
- **The spec's own Acceptance Criteria promise a "Clone for next year" feature that was never turned into a
  Requirement or built.** `openspec/specs/course-management/spec.md:28`: *"GIVEN an instructional designer
  opens a published course, WHEN they click 'Clone for next year', THEN a draft copy is created with a new
  academic year tag and zero enrolments."* The spec's `## Requirements` section (`spec.md:35-172`) has no
  requirement covering it, and a repo-wide case-insensitive grep for `clone` returns only an unrelated
  `Vue Router` component clone in `src/main.js:80` and an unrelated matrix clone in
  `src/components/admin/ActionAuthMatrix.vue:126` — no course-cloning code exists anywhere.

**Demand**: `course-management` is already ranked **#2 of 354 canonical features (153 demand, 43 tenders, 12
competitors)** per its own spec (`openspec/specs/course-management/spec.md:11`), and insight #16 ("OSS LMS
leaders all share dated UX" — same line) is exactly the gap this change closes: the differentiator was
never meant to be the data model (which is solid) but the authoring surface, which does not exist. The
wave-2 gap analysis (`wave2-plan.md` row 5, `course-authoring-ux`) collapses eight overlapping demand
signals into this one change — `drag-and-drop-course-builder`, `course-builder`, `content-authoring`,
`integrated-course-editor`, `lessons-compose-media-tests`, `smart-blocks-lesson-planner`,
`built-in-authoring`, `planner-reusable-course-roadmaps` — against **~11 competitors that ship a course/
lesson authoring experience**: LearnUpon, TalentLMS, LearnDash, Teachable, ATutor, Open edX, OpenOLAT,
Sakai, ILIAS, Gibbon, and It's Learning.

### The ADR-002 boundary — this does NOT contradict "we run, we don't author"

ADR-002 (`openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md`) is binding and this change respects
it. Its "what we do NOT do" list (`ADR-002:78-81`) says *"We do not author new SCORM content from inside
Scholiq"* — i.e. Scholiq is not becoming a SCORM/cmi5/H5P **package** authoring tool; those formats stay
externally authored and are launched/tracked per ADR-002's runtime contract. `course-management`'s own Out
of Scope section echoes the same boundary: *"Authoring tool for SCORM packages themselves (use external
authoring; we run, not author)"* (`openspec/specs/course-management/spec.md`, `## Out of Scope`).

That boundary is about **packaged** content (`contentType: scorm12 | scorm2004 | cmi5`), which this change
does not touch — those lessons keep launching exactly as ADR-002 defines. This change is about **native**
lesson pages: `Lesson.contentType: text`, an enum value that has existed since the schema was written but
has never had a real authoring or rendering path (see the dead-field evidence above). Composing a native
page out of a paragraph, an embedded quiz, a video, and an assignment link is not "authoring a SCORM
package" any more than writing a wiki page is; it is the one content type ADR-002 was silent on because it
sits entirely inside Scholiq's own object model, with no external runtime standard to defer to.

### Reuse, not new schemas, for every block payload

This change deliberately does not introduce a `Block` OpenRegister schema. Every content type the composer
needs already has a first-class OpenRegister object it can point at:

- **Rich text** — no object needed; the block carries markdown text inline (reusing
  `@conduction/nextcloud-vue`'s `CnMarkdownEditor`, see design.md D1).
- **Image/video/file attachment/SCORM-cmi5 package reference** — the `Material` schema already exists
  (`lib/Settings/scholiq_register.json`, capability `school-structure`,
  `openspec/specs/school-structure/spec.md:27,70-76`): *"Metadata for a file, presentation, reading, video,
  or SCORM/cmi5 package attached to a Course, Lesson, or Session... Materials MUST reference OpenRegister
  file attachments; this app MUST NOT store file bytes itself."* It already has `courseId`/`lessonId`/
  `sessionId` (nullable, one set), `kind` (`slides | reading | video | scorm | cmi5 | lti | link | document |
  other`), `fileRef`, and its own `order`. A media/file/package block is a pointer to a `Material` UUID, not
  a re-implementation of file handling.
- **Embedded quiz** — the `Assessment` schema already aggregates `Item`s via `itemRefs`
  (`lib/Settings/scholiq_register.json:4697-4715`, an array of `{itemId, points}`) — a block references an
  existing `Assessment` UUID.
- **Assignment reference** — `Assignment` already exists (`assignments` capability); a block references an
  existing `Assignment` UUID, the same convention `Submission.assignmentId` already uses
  (`lib/Settings/scholiq_register.json`, `{"type": "string", "format": "uuid", "$ref": "Assignment"}`).
- **External/LTI tool reference** — `LtiToolPlacement` already exists (wave-1,
  `openspec/changes/archive/2026-07-13-lti-tool-placement/`) with a nullable `lessonId`, so multiple
  placements can already target one `Lesson` — a block references an existing `LtiToolPlacement` UUID.

The only new persisted structure is `Lesson.blocks`: an ordered array of `{blockId, type, order, ...one
pointer or inline text field...}`, the same "structured JSON array of typed pointers" shape
`Assessment.itemRefs` already establishes as a precedent in this register (see design.md D2 for the full
rationale on why this is JSON-on-`Lesson` rather than a new schema).

## What Changes

- **`Lesson.blocks`** (new, additive array field) — an ordered list of typed content blocks
  (`richText | media | quiz | assignment | ltiTool`), each pointing at an existing `Material` / `Assessment`
  / `Assignment` / `LtiToolPlacement` object (or carrying inline markdown for `richText`). `contentType`
  keeps its current meaning for packaged content (`video`, `scorm12`, `scorm2004`, `cmi5`, `lti`, `quiz`);
  `contentType: text` is redefined from an always-dead value to "native, block-composed lesson body" —
  `contentRef` becomes conditionally required (still required for every other `contentType`, not required
  when `contentType: text` and `blocks` is populated) via a JSON-Schema `allOf`/`if`/`then`, a construct
  already precedented once in this register (`exam-board-case-handling`, `GradeEntry.sourceKind`).
- **`Course.order`** (new, nullable, additive integer field) — sibling modules (child `Course`s sharing a
  `parentCourseId`) become explicitly sequenced; `null` sorts after any ordered siblings (append-to-end),
  matching the app's existing convention for additive nullable fields (`ectsCredits`).
- **`CourseTemplate`** (new schema) — a reusable Course→Module→Lesson skeleton (+ optional `CurriculumPlan`
  skeleton) that can be captured from an existing `Course` ("Save as template") and instantiated into a new,
  independent `Course` tree — the concrete implementation of the "Clone for next year" capability the spec
  already promised, generalised to reusable templates rather than a single-course copy (evidence:
  `planner-reusable-course-roadmaps`).
- **Two new custom views** (`src/manifest.json` `type: custom`, `src/registry.js`-registered, mirroring the
  `LessonPlayer`/`ItemAuthorView` pattern exactly):
  - **`CourseBuilder.vue`** — the Course/Module/Lesson tree editor: create, reorder (drag-and-drop AND
    keyboard move-up/move-down — see design.md D4 for the WCAG 2.1 AA rationale), and delete modules and
    lessons; "Save as template" / "New course from template" actions.
  - **`LessonComposer.vue`** — the per-lesson block editor: add, remove, and reorder (same dual
    drag-and-drop + keyboard pattern) blocks; edit each block's payload (`CnMarkdownEditor` for `richText`,
    an `@nextcloud/dialogs` file picker + `Material` create/update for media blocks, `NcSelect` pickers
    against existing `Assessment`/`Assignment`/`LtiToolPlacement` objects for the other block types — every
    `NcSelect` carries `inputLabel` per the app's accessibility rule).
- **`LessonPlayer.vue`** gains a render path for `contentType: text` that walks `lesson.blocks` and renders
  each block by `type` — replacing the dead `lesson.content`/`lesson.title`/`lesson.summary` reads with the
  fields the schema (as of this change) actually has.
- **No new PHP controller.** Template instantiation is a frontend orchestration that issues a sequence of
  calls against OpenRegister's existing object-create endpoint (the same `fetch()`-against-
  `/apps/openregister/api/objects/...` pattern `LessonPlayer.vue`/`ItemAuthorView.vue` already use) — one
  `Course`, its child `Course`s, their `Lesson`s, and an optional `CurriculumPlan` — consuming OR's
  abstractions rather than adding a pass-through CRUD controller (ADR-022).

## Impact

- **`lib/Settings/scholiq_register.json`** — `Lesson.blocks` (additive), `Lesson`'s `allOf`/`if`/`then` on
  `contentRef` requiredness, `Course.order` (additive), new `CourseTemplate` schema. `info.version`
  0.7.0 → 0.8.0 (⚠️ other wave-2 changes in this worktree may independently bump the same version — whichever
  lands first takes 0.8.0, the second retargets 0.9.0 at apply time, per the `exam-board-case-handling`
  precedent for this exact collision).
- **`src/manifest.json`** — two new `type: custom` pages (`CourseBuilder`, `LessonComposer`) plus index/
  detail pages for `CourseTemplate`; `src/registry.js` gains the two new view imports/registrations.
- **`src/views/`** — new `CourseBuilder.vue`, new `LessonComposer.vue`; `LessonPlayer.vue` modified to render
  `lesson.blocks`.
- **`package.json`** — new direct dependency `vuedraggable` (pinned to the version
  `@conduction/nextcloud-vue` already carries transitively, per `CnPageTreeNode.vue`'s established pattern,
  so the bundle does not ship two Vue-2-compatible Sortable wrappers). `@nextcloud/dialogs` (already a
  direct dependency, `package.json:34`) is used for the first time for its file picker.
- **Affected specs**: `course-management` (this delta). `school-structure` (`Material`), `assessment`
  (`Assessment`/`Item`), `assignments` (`Assignment`), and the merged `lti-tool-placement` delta on
  `course-management` (`LtiToolPlacement`) are consumed read-only — no changes to those capabilities.
- **Out of scope**: authoring SCORM/cmi5/H5P packages themselves (stays externally authored, per ADR-002 —
  see the boundary discussion above); real-time collaborative lesson editing (`course-management`'s own Out
  of Scope, unchanged); a Deep Linking LTI content-picker UI (named out of scope by the `lti-tool-placement`
  change already); block-level versioning/revision history (a follow-up if a buyer needs it).
