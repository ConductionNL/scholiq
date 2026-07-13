# Tasks: course-authoring-ux

Size L. No new PHP controller, route, or service is needed — reordering, block editing, and template
instantiation all go through OpenRegister's existing object-create/update endpoints from the frontend
(design.md D3, D5); the only backend surface touched is the `lib/Settings/scholiq_register.json` schema
itself.

## 1. Data model

- [ ] 1.1 Add `Lesson.blocks` to `lib/Settings/scholiq_register.json`: array, default `[]`, items
      `{blockId: string, type: enum [richText, media, quiz, assignment, ltiTool], order: integer, text:
      nullable string, materialId: nullable uuid $ref Material, assessmentId: nullable uuid $ref Assessment,
      assignmentId: nullable uuid $ref Assignment, ltiToolPlacementId: nullable uuid $ref
      LtiToolPlacement}`, each property carrying an English `title` + `description` per the app's schema
      convention.
- [ ] 1.2 Replace `Lesson`'s top-level `contentRef` `required` entry with the `allOf`/`if`/`then` conditional
      from design.md D2 (`contentType: text` → `contentRef` not required; every other `contentType` →
      `contentRef` required, unchanged from today).
- [ ] 1.3 Update `Lesson.contentType`'s `text` enum value description to state its new meaning ("native,
      block-composed lesson — see `blocks`") — doc-only, no enum change.
- [ ] 1.4 Add `Course.order` (nullable integer, no default) to `lib/Settings/scholiq_register.json`.
- [ ] 1.5 Add the new `CourseTemplate` schema per design.md's Data Model section (`name`, `description`,
      `level`, `sourceCoursesId`, `moduleStructure`, `curriculumPlanSkeleton`, `tenant_id`, `lifecycle`),
      with `x-openregister-lifecycle` (`draft → active → archived`, mirroring `LearningPlanTemplate`'s
      transitions).
- [ ] 1.6 Bump `lib/Settings/scholiq_register.json`'s `info.version` (0.7.0 → 0.8.0, retarget at apply time
      if a sibling wave-2 change lands first — design.md/proposal.md caveat) and append a version-history
      paragraph to `info.description` following the existing convention (one sentence per prior bump).
- [ ] 1.7 Bump `Lesson`'s and `Course`'s own per-schema `version` fields.

## 2. Manifest & registry wiring

- [ ] 2.1 Add `CourseBuilder` (`type: custom`, route `/courses/:courseId/builder`) and `LessonComposer`
      (`type: custom`, route `/courses/:courseId/lessons/:lessonId/compose`) to `src/manifest.json`,
      mirroring the `LessonPlayer`/`ItemAuthorView` entries' shape exactly.
- [ ] 2.2 Add declarative index/detail manifest pages for `CourseTemplate` (`register: scholiq, schema:
      course-template`), plus a menu entry, following the app's standard manifest-driven CRUD pattern (no
      new custom view needed for plain list/detail — only "Save as template" and "New course from template"
      are bespoke actions, wired into `CourseBuilder`).
- [ ] 2.3 Register `CourseBuilder`/`LessonComposer` in `src/registry.js` (`import` + `page(...)` entries),
      mirroring `ItemAuthorView`/`LessonPlayer`'s registration.

## 3. Frontend — CourseBuilder.vue

- [ ] 3.1 Load a `Course` and its child modules (`Course` rows with `parentCourseId` = this course) and each
      module's `Lesson`s, sorted by `order` (nulls last, per design.md's `Course.order` scenario).
- [ ] 3.2 Drag-and-drop reorder of modules (writes `Course.order`) and of lessons within a module (writes
      `Lesson.order`) via `vuedraggable`, mirroring `CnPageTreeNode.vue`'s pattern.
- [ ] 3.3 Keyboard-operable "Move up"/"Move down" buttons for the same two lists, sharing one
      `reorder(list, fromIndex, toIndex)` method with the drag-and-drop handlers (design.md D4) — real
      `<button>`s, boundary-disabled, `aria-label` per row, `aria-live="polite"` move announcements.
- [ ] 3.4 Create/delete module and lesson actions (thin wrappers over OR's existing object-create/delete
      endpoints — no new controller).
- [ ] 3.5 "Save as template" action: reads the current `Course` tree, writes a `CourseTemplate` per
      design.md's capture shape (module/lesson names/order/contentType, lightweight block placeholders for
      `richText` only — no live `materialId`/`assessmentId`/etc. pointers carried into the template).
- [ ] 3.6 "New course from template" action: reads a `CourseTemplate`, then sequentially creates one
      `Course`, its child module `Course`s, their `Lesson`s, and (if `curriculumPlanSkeleton` is set) one
      `CurriculumPlan` — the frontend orchestration from design.md D5. The resulting `Course` starts
      `lifecycle: draft` with zero enrolments.

## 4. Frontend — LessonComposer.vue

- [ ] 4.1 Load a `Lesson` and render its `blocks` in `order`.
- [ ] 4.2 Add-block affordance offering the five block types (`richText`, `media`, `quiz`, `assignment`,
      `ltiTool`).
- [ ] 4.3 `richText` block editor: `CnMarkdownEditor` in its default (non-WYSIWYG) mode, `v-model` bound to
      the block's `text`.
- [ ] 4.4 `media` block editor: `@nextcloud/dialogs`' `getFilePickerBuilder` to pick an NC file, then
      create/update a `Material` (`fileRef` = picked path, `lessonId` = current lesson, `kind` inferred from
      the file's MIME type or user-selected), then store the resulting `Material`'s UUID as `materialId`.
- [ ] 4.5 `quiz`/`assignment`/`ltiTool` block editors: `NcSelect` pickers listing existing `Assessment` /
      `Assignment` / `LtiToolPlacement` objects scoped to the current course/tenant, each with `inputLabel`
      set.
- [ ] 4.6 Drag-and-drop + keyboard-operable reorder of blocks (writes each block's `order`), same shared
      `reorder()` pattern as `CourseBuilder`'s (design.md D4).
- [ ] 4.7 Remove-block action.
- [ ] 4.8 Save persists the full `blocks` array on the `Lesson` via OR's existing object-update endpoint.

## 5. Frontend — LessonPlayer.vue block rendering

- [ ] 5.1 Add a `contentType === 'text'` branch that iterates `lesson.blocks` in `order` and dispatches each
      to a renderer by `type`: `richText` (sanitised markdown-to-HTML), `media` (resolve the referenced
      `Material` and render per its `kind`), `quiz` (embed the referenced `Assessment`'s take-flow), and
      `assignment` (a summary card linking to the referenced `Assignment`).
- [ ] 5.2 `ltiTool` blocks reuse the existing `launchLti()`/`submitLtiLaunchForm()` methods
      (`LessonPlayer.vue:238-298`), scoped to the block's `ltiToolPlacementId` rather than the whole
      lesson's `contentRef`.
- [ ] 5.3 Remove the dead `lesson.title`/`lesson.summary`/`lesson.content` reads (`LessonPlayer.vue:39-47,
      93-95`) now that `contentType: text` has a real rendering path — `lesson.name` (the schema's actual
      title field) replaces `lesson.title` in the header.

## 6. Accessibility verification

- [ ] 6.1 Keyboard-only walkthrough of `CourseBuilder` (reorder modules, reorder lessons, create/delete,
      save-as-template, instantiate-from-template) with no pointer device.
- [ ] 6.2 Keyboard-only walkthrough of `LessonComposer` (add/remove/reorder blocks, edit each block type)
      with no pointer device.
- [ ] 6.3 Screen-reader spot-check of the `aria-live` move announcements and every `NcSelect`'s `inputLabel`
      association.

## 7. Tests

- [ ] 7.1 PHPUnit schema-validation tests: `Lesson` with `contentType: text` and no `contentRef` validates;
      `Lesson` with any other `contentType` and no `contentRef` still fails validation (regression guard for
      D2's conditional).
- [ ] 7.2 `tests/e2e/spec-coverage/course-authoring-ux.spec.ts` (Playwright) covering the `@e2e`-tagged
      scenarios in `specs/course-management/spec.md`: compose a mixed-block lesson, drag-and-drop lesson
      reorder, keyboard-only lesson reorder, keyboard-only block reorder, module order in `CourseBuilder`,
      save-as-template, instantiate-from-template, `LessonPlayer` rendering composed blocks in order.
- [ ] 7.3 Component-level unit test for the `order: null` sorts-last comparator (`CourseBuilder`'s module
      list).
- [ ] 7.4 Run PHPCS/PHPMD/Psalm/PHPStan on any touched PHP (schema JSON only touches; expect no PHP diff
      for this change per the "no new PHP controller" note above — re-verify at apply time).

## 8. Docs & spec traceability

- [ ] 8.1 Add `@spec openspec/changes/course-authoring-ux/tasks.md#task-N` docblock tags to
      `CourseBuilder.vue`, `LessonComposer.vue`, and the modified sections of `LessonPlayer.vue`.
- [ ] 8.2 Merge this change's `specs/course-management/spec.md` delta into
      `openspec/specs/course-management/spec.md`.
- [ ] 8.3 Update `openspec/specs/course-management/spec.md`'s Data Model section to list `CourseTemplate`
      alongside the existing entities.
- [ ] 8.4 Update `docs/` (app feature docs, if present for course-management) to describe the new authoring
      flow for instructional designers.
