# Tasks: course-authoring-ux

Size L. No new PHP controller, route, or service is needed — reordering, block editing, and template
instantiation all go through OpenRegister's existing object-create/update endpoints from the frontend
(design.md D3, D5); the only backend surface touched is the `lib/Settings/scholiq_register.json` schema
itself.

## 1. Data model

- [x] 1.1 Add `Lesson.blocks` to `lib/Settings/scholiq_register.json`: array, default `[]`, items
      `{blockId: string, type: enum [richText, media, quiz, assignment, ltiTool], order: integer, text:
      nullable string, materialId: nullable uuid $ref Material, assessmentId: nullable uuid $ref Assessment,
      assignmentId: nullable uuid $ref Assignment, ltiToolPlacementId: nullable uuid $ref
      LtiToolPlacement}`, each property carrying an English `title` + `description` per the app's schema
      convention.
- [x] 1.2 Replace `Lesson`'s top-level `contentRef` `required` entry with the `allOf`/`if`/`then` conditional
      from design.md D2 (`contentType: text` → `contentRef` not required; every other `contentType` →
      `contentRef` required, unchanged from today).
- [x] 1.3 Update `Lesson.contentType`'s `text` enum value description to state its new meaning ("native,
      block-composed lesson — see `blocks`") — doc-only, no enum change.
- [x] 1.4 Add `Course.order` (nullable integer, no default) to `lib/Settings/scholiq_register.json`.
- [x] 1.5 Add the new `CourseTemplate` schema per design.md's Data Model section (`name`, `description`,
      `level`, `sourceCoursesId`, `moduleStructure`, `curriculumPlanSkeleton`, `tenant_id`, `lifecycle`),
      with `x-openregister-lifecycle` (`draft → active → archived`, mirroring `LearningPlanTemplate`'s
      transitions).
- [x] 1.6 Bump `lib/Settings/scholiq_register.json`'s `info.version` — read at apply time: HEAD was 0.15.0
      (not the stale 0.7.0 the proposal/design assumed), so this bumps 0.15.0 → 0.16.0 and appends a
      version-history paragraph to `info.description` following the existing convention.
- [x] 1.7 Bump `Lesson`'s and `Course`'s own per-schema `version` fields (both 0.2.0 → 0.3.0).

## 2. Manifest & registry wiring

- [x] 2.1 Add `CourseBuilder` (`type: custom`, route `/courses/:courseId/builder`) and `LessonComposer`
      (`type: custom`, route `/courses/:courseId/lessons/:lessonId/compose`) to `src/manifest.json`,
      mirroring the `LessonPlayer`/`ItemAuthorView` entries' shape exactly.
- [x] 2.2 Add declarative index/detail manifest pages for `CourseTemplate` (`register: scholiq, schema:
      course-template`), plus a menu entry, following the app's standard manifest-driven CRUD pattern (no
      new custom view needed for plain list/detail — only "Save as template" and "New course from template"
      are bespoke actions, wired into `CourseBuilder`).
- [x] 2.3 Register `CourseBuilder`/`LessonComposer` in `src/registry.js` (`import` + `page(...)` entries),
      mirroring `ItemAuthorView`/`LessonPlayer`'s registration.

## 3. Frontend — CourseBuilder.vue

- [x] 3.1 Load a `Course` and its child modules (`Course` rows with `parentCourseId` = this course) and each
      module's `Lesson`s, sorted by `order` (nulls last, per design.md's `Course.order` scenario).
- [x] 3.2 Drag-and-drop reorder of modules (writes `Course.order`) and of lessons within a module (writes
      `Lesson.order`) via `vuedraggable`, mirroring `CnPageTreeNode.vue`'s pattern.
- [x] 3.3 Keyboard-operable "Move up"/"Move down" buttons for the same two lists, sharing one
      `reorder(list, fromIndex, toIndex)` method with the drag-and-drop handlers (design.md D4) — real
      `<button>`s, boundary-disabled, `aria-label` per row, `aria-live="polite"` move announcements.
- [x] 3.4 Create/delete module and lesson actions (thin wrappers over OR's existing object-create/delete
      endpoints — no new controller).
- [x] 3.5 "Save as template" action: reads the current `Course` tree, writes a `CourseTemplate` per
      design.md's capture shape (module/lesson names/order/contentType, lightweight block placeholders for
      `richText` only — no live `materialId`/`assessmentId`/etc. pointers carried into the template).
- [x] 3.6 "New course from template" action: reads a `CourseTemplate`, then sequentially creates one
      `Course`, its child module `Course`s, their `Lesson`s, and (if `curriculumPlanSkeleton` is set) one
      `CurriculumPlan` — the frontend orchestration from design.md D5. The resulting `Course` starts
      `lifecycle: draft` with zero enrolments.

## 4. Frontend — LessonComposer.vue

- [x] 4.1 Load a `Lesson` and render its `blocks` in `order`.
- [x] 4.2 Add-block affordance offering the five block types (`richText`, `media`, `quiz`, `assignment`,
      `ltiTool`).
- [x] 4.3 `richText` block editor: `CnMarkdownEditor` in its default (non-WYSIWYG) mode, `v-model` bound to
      the block's `text`.
- [x] 4.4 `media` block editor: `@nextcloud/dialogs`' `getFilePickerBuilder` to pick an NC file, then
      create/update a `Material` (`fileRef` = picked path, `lessonId` = current lesson, `kind` inferred from
      the file's MIME type or user-selected), then store the resulting `Material`'s UUID as `materialId`.
      Also supports selecting an already-existing `Material` via `NcSelect` (spec's "media block references
      an existing Material" scenario) without duplicating its fields. `webpack.config.js`'s `path: false`
      fallback (previously safe because no scholiq code called the file picker) was changed to
      `path-browserify` — this is the first real caller.
- [x] 4.5 `quiz`/`assignment`/`ltiTool` block editors: `NcSelect` pickers listing existing `Assessment` /
      `Assignment` / `LtiToolPlacement` objects scoped to the current course/tenant, each with `inputLabel`
      set.
- [x] 4.6 Drag-and-drop + keyboard-operable reorder of blocks (writes each block's `order`), same shared
      `reorder()` pattern as `CourseBuilder`'s (design.md D4).
- [x] 4.7 Remove-block action.
- [x] 4.8 Save persists the full `blocks` array on the `Lesson` via OR's existing object-update endpoint.

## 5. Frontend — LessonPlayer.vue block rendering

- [x] 5.1 Add a `contentType === 'text'` branch that iterates `lesson.blocks` in `order` and dispatches each
      to a renderer by `type`: `richText` (sanitised markdown-to-HTML via `cnRenderMarkdown`), `media`
      (resolve the referenced `Material` and render per its `kind`), `quiz` (a summary card that navigates to
      `TakeAssessmentView` for the referenced `Assessment` — "embed" interpreted as in-app navigation to the
      existing take-flow, not an inline iframe/re-render of that view's own logic), and `assignment` (a
      summary card linking to the referenced `Assignment`'s detail page).
- [x] 5.2 `ltiTool` blocks reuse the existing `launchLti()`/`submitLtiLaunchForm()` methods' logic (via a new
      `launchLtiForBlock()` method sharing `submitLtiLaunchForm()` and the same backend endpoint), scoped to
      the block's `ltiToolPlacementId` rather than the whole lesson's `contentRef`, with per-block launch
      state (`blockLtiState`, keyed by `blockId`) since a lesson may carry more than one `ltiTool` block
      (`LtiToolPlacement.lessonId` is nullable). Launch is a click-triggered "Open external tool" button per
      block rather than auto-launch-on-mount, to avoid a multi-block auto-launch cascade — the spec does not
      mandate auto-launch for blocks (only the existing whole-lesson `contentType: lti` branch auto-launches).
- [x] 5.3 Remove the dead `lesson.title`/`lesson.summary`/`lesson.content` reads (and the `course.title`
      read, which was equally dead — `Course` has no `title` property either) — `lesson.name`/`course.name`
      (the schemas' actual title fields) replace them in the header.

## 6. Accessibility verification

- [ ] 6.1 Keyboard-only walkthrough of `CourseBuilder` (reorder modules, reorder lessons, create/delete,
      save-as-template, instantiate-from-template) with no pointer device. NOT PERFORMED in this session — no
      running dev instance was available to drive live. The implementation targets this: every reorder
      control is a real `<button>` (not `div`/`role="button"`), boundary-disabled, with a descriptive
      `aria-label`, and the keyboard move handlers share the exact same `persistOrder()`/mutation path the
      drag-and-drop handlers use, so no behaviour is keyboard-second-class by construction — but that claim
      is unverified against a live screen and keyboard in this run.
- [ ] 6.2 Keyboard-only walkthrough of `LessonComposer` (add/remove/reorder blocks, edit each block type)
      with no pointer device. NOT PERFORMED — same caveat as 6.1; same real-`<button>` / shared-mutation-path
      construction applies (`reorderBlock()`/`renumberBlocks()`).
- [ ] 6.3 Screen-reader spot-check of the `aria-live` move announcements and every `NcSelect`'s `inputLabel`
      association. NOT PERFORMED — no screen reader / running instance available in this session. Every
      `NcSelect` in both views carries `input-label` and `aria-label-combobox` (`hydra-gate-nc-input-labels`
      convention, grep-verified against every `<NcSelect` occurrence in `CourseBuilder.vue`/
      `LessonComposer.vue`), and both views render one shared `aria-live="polite"` region (`liveMessage`)
      updated by every reorder mutation — implemented per the WCAG 2.1 AA contract, not live-verified with
      assistive technology.

## 7. Tests

- [x] 7.1 PHPUnit schema-validation tests: `Lesson` with `contentType: text` and no `contentRef` validates;
      `Lesson` with any other `contentType` and no `contentRef` still fails validation (regression guard for
      D2's conditional). `tests/Unit/Settings/CourseAuthoringRegisterTest.php`, 9 tests / 192 assertions,
      green. Also fixed a mechanical fallout: `ReportCardComposerRegisterTest::testRegisterVersionBumped`
      hardcoded the prior register version (0.15.0); updated to a `version_compare(...>=...)` floor instead
      of an exact pin, so future version bumps in this worktree don't re-break it the same way.
- [x] 7.2 `tests/e2e/spec-coverage/course-authoring-ux.spec.ts` (Playwright) covering the `@e2e`-tagged
      scenarios in `specs/course-management/spec.md`: compose blocks + keyboard block reorder + LessonPlayer
      rendering (one test), drag-and-drop lesson reorder + keyboard lesson reorder (one test), module
      add/reorder in `CourseBuilder` (one test), save-as-template + instantiate-from-template (one test) — 4
      tests total. Written mirroring `adaptive-release.spec.ts`'s discover-via-OR-API-or-skip convention (no
      raw API POST fixture creation — module/lesson/block fixtures are created through the app's OWN new UI
      inside each test). Verified with `npx playwright test ... --list` (4 tests discovered, file parses and
      type-checks) — NOT executed against a live Nextcloud instance in this session (none was running).
- [x] 7.3 Component-level unit test for the `order: null` sorts-last comparator (`CourseBuilder`'s module
      list). No JS unit-test framework (jest/vitest/@vue/test-utils) exists anywhere in this repo, so the
      comparator was extracted to a plain ES module (`src/utils/courseOrder.js`, imported by
      `CourseBuilder.vue`) specifically so it is testable without an SFC compile step, and tested with
      Node's built-in test runner (`tests/unit-js/courseOrder.test.mjs`, new `npm run test:js-unit` script) —
      5 tests, all green, zero new framework dependency.
- [x] 7.4 Run PHPCS/PHPMD/Psalm/PHPStan on any touched PHP. Confirmed: this change touches ZERO files under
      `lib/` (schema-JSON + Vue + JS only, exactly as this note anticipated) — `phpcs.xml`'s `<file>lib</file>`
      scope has nothing new to check. The two touched PHP files are both under `tests/`, outside that scope.

## 8. Docs & spec traceability

- [x] 8.1 Add `@spec openspec/changes/course-authoring-ux/tasks.md#task-N` docblock tags to
      `CourseBuilder.vue`, `LessonComposer.vue`, and the modified sections of `LessonPlayer.vue`.
- [x] 8.2 Merge this change's `specs/course-management/spec.md` delta into
      `openspec/specs/course-management/spec.md`.
- [x] 8.3 Update `openspec/specs/course-management/spec.md`'s Data Model section to list `CourseTemplate`
      alongside the existing entities.
- [x] 8.4 Update `docs/` (app feature docs, if present for course-management) to describe the new authoring
      flow for instructional designers. Added a "Compose the course structure and lesson content" section to
      `docs/user-guide/user/02-create-course.md` (prose only — no new screenshots were captured in this
      session, so no new numbered/screenshotted steps were added to keep the existing tutorial convention
      honest).
