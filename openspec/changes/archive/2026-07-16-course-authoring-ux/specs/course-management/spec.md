# Course Management — Course Authoring UX Delta

**Spec refs**: `course-management` (this delta). Read-only consumers, unchanged by this delta:
`school-structure` (`Material`), `assessment` (`Assessment`/`Item`), `assignments` (`Assignment`), and the
`lti-tool-placement` delta already merged into `course-management` (`LtiToolPlacement`).

## ADDED Requirements

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

<!-- @e2e exclude Schema-level regression on an unrelated contentType; covered by PHPUnit schema validation tests, no new browser journey -->

### Requirement: Lessons within a Course and blocks within a Lesson are reorderable by drag-and-drop and by keyboard

The system SHALL provide drag-and-drop reordering of `Lesson`s within a `Course` (writing `Lesson.order`)
and of blocks within a `Lesson` (writing each block's `order`), AND SHALL provide an equivalent
keyboard-operable reordering control (move up / move down) for both, so that reordering is never
drag-only. This satisfies WCAG 2.1 AA success criterion 2.1.1 (Keyboard) — a legal duty for an app serving
publicly funded Dutch schools (po/vo/mbo/hbo) under the Tijdelijk besluit digitale toegankelijkheid
overheid / EN 301 549, and a commitment `nextcloud-app`'s own spec already declares
(`openspec/specs/nextcloud-app/spec.md:172`, "WCAG 2.1 AA").

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

<!-- @e2e exclude Null-default sort behaviour is a pure list-rendering rule with no distinct browser journey beyond the drag-and-drop scenario already covered; verified by a component unit test on CourseBuilder's sort comparator -->

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
  this spec already declared without a backing requirement

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: LessonPlayer renders a Lesson's authored blocks

When `Lesson.contentType` is `text`, `LessonPlayer.vue` SHALL render `lesson.blocks` in `order`, dispatching
each block to a renderer by `type`: `richText` renders its markdown as sanitised HTML, `media` resolves and
renders the referenced `Material` (image/video/file/link per `Material.kind`), `quiz` embeds the referenced
`Assessment`'s take-flow, `assignment` renders a summary card linking to the referenced `Assignment`, and
`ltiTool` triggers the same opaque launch-delegation flow the existing `contentType: lti` branch already
uses (`LessonPlayer.vue:238-269`), scoped to that one block rather than the whole lesson.

#### Scenario: A learner opens a native lesson and sees its composed blocks in order

- **GIVEN** a `Lesson` with `contentType: text` and three blocks (`richText`, `media`, `quiz`) in that order
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the three blocks render in their persisted order, each via its type-specific renderer

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

## Standards

Schema.org `LearningResource` (unchanged, `Lesson`'s existing `x-openregister.schemaType`); WCAG 2.1 AA
(reorder keyboard-operability); no new external content-runtime standard — this delta is scoped entirely to
native, in-Scholiq lesson composition, and does not touch cmi5/xAPI/SCORM/LTI 1.3 protocol handling (ADR-002,
`lti-tool-placement`).
