# Design: course-authoring-ux

Size L. This change adds the one authoring surface `course-management` never got: composing a native
`Lesson` body from typed blocks, arranging a `Course`'s module/lesson tree, and turning a course structure
into a reusable template. It touches only `course-management` data and two new custom views; it does not
touch cmi5/xAPI/SCORM/LTI protocol code (that stays ADR-002's and `lti-tool-placement`'s territory).

## Context

Verified at HEAD (worktree `/home/rubenlinde/scholiq-goal2/scholiq`):

- `Lesson` (`lib/Settings/scholiq_register.json`) has `courseId`, `name`, `order`, `contentType`
  (`text | video | scorm12 | scorm2004 | cmi5 | lti | quiz`), `contentRef` (required, string),
  `durationMinutes`, `learningObjectives`, `mandatoryTraining`, `regulationSlug`, `lifecycle`, `tenant_id`.
  `contentType: text` has existed since the schema was written but nothing has ever populated a body for
  it — `LessonPlayer.vue:39-47,93-95` reads `lesson.title`/`lesson.summary`/`lesson.content`, none of which
  are `Lesson` properties, so that render path has always been dead.
- `Course` is recursive (`parentCourseId: $ref Course`) with no `order` field — sibling modules cannot be
  sequenced.
- `Material` (`school-structure` capability) already models file/video/SCORM-cmi5-package attachments to a
  Course/Lesson/Session, with its own `order`, `kind` enum, and a `fileRef` pointing at an OpenRegister file
  attachment — it does not store bytes itself.
- `Assessment.itemRefs` (`lib/Settings/scholiq_register.json:4697-4715`) is an existing precedent for a
  structured-JSON array of `{pointerId, ...override fields}` objects on a parent schema.
- `LearningPlanTemplate` (`learning-plan` capability) is the one existing "template" schema in this
  register: `sections` (ordered, `{sectionId, label, order, helpText}`), `goalDomains`,
  `requiredSignerRoles`, `defaultReviewCadenceMonths`, `lifecycle: draft → active → archived`. Its
  instantiation is UI-level, not a backend service — `learning-plan/spec.md:38`: *"GIVEN a sector template
  `opp-vo`, WHEN a coordinator creates a LearningPlan with `kind=opp` and that template, THEN the plan is
  pre-populated with the template's sections and goal domains."* No PHP service class exists for it
  (`learning-plan/spec.md:247`: "No PHP service classes — fully declarative").
- `@conduction/nextcloud-vue` (`package.json:31`, `^1.0.0-beta.194`) exports `CnMarkdownEditor`
  (`src/index.js:50` in the nc-vue repo) — a markdown textarea/preview editor with an optional lazy-loaded
  `mode: 'wysiwyg'` (Toast UI). Scholiq does not currently import it anywhere.
- `CnPageTreeNode.vue` (nc-vue) is the only existing drag-and-drop precedent in either repo:
  `import draggable from 'vuedraggable'` + `<draggable v-model="tree" @end="flatten">`. `vuedraggable` is a
  transitive dependency of `@conduction/nextcloud-vue`, not currently a direct dependency of scholiq.
  Neither repo has any existing keyboard-operable reorder pattern.
- `@nextcloud/dialogs` is already a direct scholiq dependency (`package.json:34`) but its file-picker export
  (`getFilePickerBuilder`) is unused anywhere in scholiq or nc-vue today.
- The register's own JSON-Schema conditional-requiredness precedent: `GradeEntry` uses `allOf`/`if`/`then`
  to make `value` conditionally required by `sourceKind` (`lib/Settings/scholiq_register.json:5612-5634`,
  introduced by `exam-board-case-handling`, whose design.md §7 documents it as *"Standard JSON Schema
  if/then/else — no prior precedent for this construct in this register."* This is now the second use.

## Decisions

### D1 — Blocks are structured JSON on `Lesson`, not a new `Block` schema

**Decision**: `Lesson.blocks` is an array property on the existing `Lesson` object — `{blockId, type, order,
text?, materialId?, assessmentId?, assignmentId?, ltiToolPlacementId?}` — not a new `Block` OpenRegister
schema with its own lifecycle, RBAC, and CRUD surface.

**Rationale**: a block is not an independent domain entity with its own audit/lifecycle/RBAC needs — it is
a position in one `Lesson`'s body, always read and written as part of that `Lesson`, never queried,
filtered, or referenced independently across `Lesson`s. `Assessment.itemRefs` already establishes exactly
this shape in this register (a structured array of `{pointerId, overrides}` objects that is not its own
schema), and every block payload that isn't inline text is *itself* a pointer to an object that already has
its own schema, lifecycle, and RBAC — `Material`, `Assessment`, `Assignment`, `LtiToolPlacement`. Giving
blocks a separate schema would mean either (a) `Block.lessonId` + a second `order` field duplicating
`Lesson.blocks`'s ordering, doubling the number of round-trips needed to load and save one lesson body
(fetch `Lesson`, then list-fetch `Block`s, then re-sort client-side), or (b) inlining full copies of
`Material`/`Assessment`/`Assignment` fields onto `Block` rows, which is the file-metadata-duplication D6
below already rejects for the media case specifically.

**Rejected alternative**: a `Block` schema with `lessonId`, `blockType`, `order`, and per-type payload
columns. Rejected because it buys nothing a JSON array on `Lesson` doesn't already give (blocks are never
accessed outside their parent `Lesson`'s save/load cycle) and costs an extra network round trip on every
lesson read/write plus a second `order` concept to keep in sync with the array's own position.

### D2 — `Lesson.contentType: text` is redefined to mean "native, block-composed"; `contentRef` becomes conditionally required

`contentType: text` has never had real behaviour (see Context). Rather than adding a new enum value
(`blocks` or similar) alongside the six existing ones, this change gives `text` its intended meaning: a
`Lesson` whose body is `blocks` rather than a single packaged reference. This is additive in practice —
there is no seed data and no existing UI that ever wrote a `text`-typed lesson with a real body (`Lesson`'s
`x-openregister-seed` is `[]`), so no row's behaviour changes.

`contentRef` is required in the schema's top-level `required` array today, unconditionally. This change
adds:

```json
"allOf": [{
  "if": { "properties": { "contentType": { "const": "text" } }, "required": ["contentType"] },
  "then": {},
  "else": { "required": ["contentRef"] }
}]
```

...removing `contentRef` from the flat `required` array and moving its requiredness into the conditional,
mirroring `GradeEntry`'s existing `sourceKind`-conditional `value` requiredness pattern exactly (same
`allOf`/`if`/`then` shape, same rationale: a field that is mandatory for most enum values and meaningless
for one). Every `contentType` other than `text` keeps identical validation to today.

**Rejected alternative**: keep `contentRef` unconditionally required and have block-composed lessons set it
to an empty string or a placeholder. Rejected — an empty-but-required string is exactly the kind of
"technically valid, semantically meaningless" data the `if`/`then` construct exists to avoid, and
`exam-board-case-handling`'s reviewers already established the precedent for handling this correctly rather
than with a sentinel value.

### D3 — Media/file/SCORM-cmi5-reference blocks point at `Material`, never re-embed file data

A `media`-type block carries only `materialId` (`$ref: Material`). It does not carry `fileRef`, `kind`,
`license`, or `lomTags` — those already live on `Material` and `school-structure`'s own requirement already
states *"Materials MUST reference OpenRegister file attachments; this app MUST NOT store file bytes
itself"* (`school-structure/spec.md:71`). Duplicating any of that onto a block would create two sources of
truth for one file's metadata the moment someone edits the `Material` directly (e.g. from a `Session`'s
attached-materials list, which `Material` already supports independently of any `Lesson.blocks` usage).

Picking or creating the underlying `Material` from `LessonComposer` is new integration work, not reuse:
neither scholiq nor nc-vue currently calls `@nextcloud/dialogs`' `getFilePickerBuilder` anywhere, despite
`@nextcloud/dialogs` already being a direct scholiq dependency. `LessonComposer`'s media-block editor opens
the NC Files picker, then creates (or updates) a `Material` row with `fileRef` set to the picked path and
`lessonId` set to the current lesson, then stores that `Material`'s UUID as the block's `materialId` — using
`Material`'s existing OR object-create endpoint, not a new controller.

### D4 — Reordering: `vuedraggable` for pointer/mouse/touch, a parallel keyboard control for accessibility, both write the same mutation

**The finding to design against**: WCAG 2.1 AA success criterion 2.1.1 (Keyboard) requires that all
functionality be operable through a keyboard interface, "except where the underlying function requires
input that depends on the path of the user's movement." A pure `mousedown`/`touchstart`-driven
drag-and-drop reorder (which is what `vuedraggable`/SortableJS provides on its own — it has no built-in
keyboard reordering) fails this outright, and Scholiq targets publicly funded Dutch schools (po/vo/mbo/hbo),
for whom WCAG 2.1 AA conformance is a legal duty under the Tijdelijk besluit digitale toegankelijkheid
overheid (implementing EU Directive 2016/2102) — not a nice-to-have. `nextcloud-app`'s own spec already
commits the whole app to WCAG 2.1 AA (`openspec/specs/nextcloud-app/spec.md:172`).

**Decision**: two independent, redundant interaction paths that both call the same reorder mutation
(re-write the affected items' `order` values and PATCH them via OR's existing object-update endpoint):

1. **Drag-and-drop** via `vuedraggable` (mirroring `CnPageTreeNode.vue`'s established pattern), added as a
   new **direct** scholiq dependency pinned to the same version `@conduction/nextcloud-vue` carries
   transitively (`^2.24.3`), so the bundle never ships two Vue-2-compatible Sortable wrappers.
2. **"Move up" / "Move down" icon buttons** on every reorderable row (`Lesson`s in `CourseBuilder`, blocks
   in `LessonComposer`), each a real, individually focusable, `aria-label`-carrying button (e.g. *"Move
   lesson 'Module 1 — NIS2 Scope' up"*), disabled at the boundary (first item's "up", last item's "down"),
   with an `aria-live="polite"` region announcing the result ("Lesson moved to position 1 of 3") — this is
   new code in both repos; no prior keyboard-reorder pattern existed to copy.

Both paths write through one shared method (`reorder(list, fromIndex, toIndex)`) so there is exactly one
place that recomputes contiguous `order` values and issues the PATCH calls — the two interaction handlers
are thin event adapters, not two independent implementations that could drift.

**Rejected alternative**: rely on `vuedraggable`'s own keyboard fallback. Rejected because SortableJS (which
`vuedraggable` wraps) has no built-in keyboard-operable reorder mode — this was verified by inspecting
`CnPageTreeNode.vue`'s usage, which wires no keyboard handling at all. Treating drag-and-drop as
"accessible enough" because *a* control renders would be exactly the trap the brief calls out.

**Rejected alternative**: native HTML5 `draggable` attribute + `dragstart`/`dragover`/`drop` handlers instead
of `vuedraggable`. Rejected — native HTML5 DnD has materially worse touch-device support (teachers/designers
on tablets are a real audience for a course composer) and buys nothing over the already-precedented
`vuedraggable` pattern; the keyboard path is new code either way, so there is no accessibility argument for
avoiding the library.

### D5 — Template instantiation is a frontend orchestration, not a new PHP endpoint

A `CourseTemplate` captures a *skeleton* — module/lesson names, `order`, `contentType`, lightweight block
placeholders (a `richText` placeholder's `text` is instructional copy like "Introduction — replace with your
own text"; other block types carry no `materialId`/`assessmentId`/etc., since a template must not hardcode
UUID pointers to content objects that may not exist in the destination tenant/course) — plus an optional
`curriculumPlanSkeleton` object reusing `CurriculumPlan`'s own `kind`/`formula`/`components`/`periods`/
`passRules` shapes verbatim.

**Decision**: "Instantiate" is a sequence of calls from `CourseBuilder.vue` against OpenRegister's existing
object-create endpoint — one `Course`, then one child `Course` per `moduleStructure` entry (`parentCourseId`
= the new top `Course`), then one `Lesson` per nested lesson entry (`courseId` = the new module), then,
if `curriculumPlanSkeleton` is present, one `CurriculumPlan` with `curriculumPlanId` back-set onto the new
top `Course` — the exact `fetch()`-against-`/apps/openregister/api/objects/...` pattern
`LessonPlayer.vue`/`ItemAuthorView.vue` already use, and the same UI-level pattern `LearningPlanTemplate`
instantiation already establishes (no PHP service class). This keeps the architecture rule intact: no new
pass-through CRUD controller (ADR-022) — every object created is one OR object-create call the frontend
already knows how to make.

**Rejected alternative**: a `CourseTemplateController::instantiate()` PHP endpoint that creates the whole
tree server-side in one transaction. Rejected — this would be exactly the "pass-through CRUD controller"
architecture rule forbids (its body would be N calls into `ObjectService::create()`, nothing OR doesn't
already expose), and `LearningPlanTemplate` already demonstrates this app's convention of doing template
pre-population client-side. A follow-up can revisit this if a very large template (dozens of lessons) makes
the sequential frontend round-trips slow enough to matter — not evidenced today.

### D6 — Rich text: reuse `CnMarkdownEditor`, no new WYSIWYG dependency

`CnMarkdownEditor` (nc-vue) already ships, is unused by scholiq today, and its default `edit`/`split`/
`preview` modes are a plain markdown textarea + live preview with zero extra bundle cost — the heavier
Toast UI WYSIWYG mode only loads if a lesson author opts into `mode="wysiwyg"`. This avoids adding tiptap,
Quill, or CKEditor as a fourth rich-text stack in the fleet; markdown is also what `LearningPlanTemplate`'s
`helpText` and every other free-text field in this register already assumes as the house format (via NC's
own markdown rendering conventions), so a `richText` block's `text` round-trips through the same rendering
path `v-html`-sanitised content elsewhere in the app already uses.

**Rejected alternative**: a full block-level rich-text editor (e.g. tiptap-based, matching Notion/Google
Docs-style inline block editing) built new for this change. Rejected as scope creep for a v1 — `blocks`
already gives structural composition (paragraphs, media, quiz, assignment, tool as separate ordered units);
within a single `richText` block, markdown is sufficient and matches the one rich-text component this
codebase already has a licence to use for free.

## Accessibility of reorder (summary)

Both `CourseBuilder` (lessons within a course, modules within a course via `Course.order`) and
`LessonComposer` (blocks within a lesson) ship the D4 dual-path pattern: drag-and-drop is available but
never the only path, every reorderable row exposes real `<button>` controls (not `div`/`role="button"`) with
descriptive `aria-label`s, boundary buttons are `disabled` (not merely styled to look inert), and a shared
`aria-live="polite"` region announces the result of every move — satisfying WCAG 2.1 AA SC 2.1.1 (Keyboard)
and supporting SC 4.1.2 (Name, Role, Value) via native button semantics rather than custom ARIA widgets.
`NcSelect` pickers used for quiz/assignment/LTI-tool block references all carry `inputLabel` per the app's
existing accessibility rule (`hydra-gate-nc-input-labels`).

## Security / RBAC posture

No new authorization surface: `CourseBuilder`/`LessonComposer` create and update `Lesson`, `Course`,
`Material`, and `CourseTemplate` objects entirely through OpenRegister's existing object API, which already
enforces whatever RBAC role gates `Course`/`Lesson` write access today (unchanged by this delta — no new
controller, no new route, no new auth attribute). `CourseTemplate` carries no learner data (only structural
skeleton fields), so it introduces no new AVG/privacy surface — confirmed by its property list (design's
Data Model, below) having no learner/PII-shaped field.

## Data Model

All in `lib/Settings/scholiq_register.json`.

**`Lesson`** (modified, additive): + `blocks` (array, default `[]`; items `{blockId: string, type: enum
[richText, media, quiz, assignment, ltiTool], order: integer, text: nullable string, materialId: nullable
uuid $ref Material, assessmentId: nullable uuid $ref Assessment, assignmentId: nullable uuid $ref Assignment,
ltiToolPlacementId: nullable uuid $ref LtiToolPlacement}`); `contentRef`'s top-level `required` entry
replaced by the `allOf`/`if`/`then` conditional described in D2.

**`Course`** (modified, additive): + `order` (nullable integer, no default — absence/`null` means
"unordered, sorts last").

**New: `CourseTemplate`** — `name` (string), `description` (nullable string), `level` (enum, matches
`Course.level`), `sourceCoursesId` (nullable uuid, `$ref: Course` — the course this was captured from, null
if authored from scratch), `moduleStructure` (array of `{key: string, name: string, order: integer,
ectsCredits: nullable number, lessons: array of {key: string, name: string, order: integer, contentType:
enum matches Lesson.contentType, durationMinutes: nullable integer, blocksSkeleton: nullable array of
{blockId: string, type: enum matches block type, order: integer, text: nullable string}}}`),
`curriculumPlanSkeleton` (nullable object: `kind`, `formula`, `components`, `periods`, `passRules` — same
shapes as `CurriculumPlan`'s own fields), `tenant_id`, `lifecycle` (`draft → active → archived`, mirroring
`LearningPlanTemplate`'s exact transition set).

## Non-goals

- Authoring SCORM/cmi5/H5P packages themselves — stays externally authored per ADR-002; a `media` block can
  *reference* an existing `Material` of `kind: scorm | cmi5`, it does not build one.
- Real-time collaborative lesson editing (`course-management`'s own existing Out of Scope, unaffected).
- A Deep Linking LTI content-picker UI inside `LessonComposer` — named out of scope by `lti-tool-placement`'s
  own design.md Non-goals; an `ltiTool` block references an existing, already-configured
  `LtiToolPlacement`.
- Block-level revision history / versioning — a follow-up if evidenced.
- A full WYSIWYG block editor (Notion-style inline editing) — see D6; markdown inside a `richText` block is
  the v1 answer.
- Server-side (PHP) template instantiation — see D5; revisit only if sequential frontend round-trips prove
  too slow for very large templates.
