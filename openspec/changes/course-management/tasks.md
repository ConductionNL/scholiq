# Tasks — Course Management

## Phase 1: OpenRegister schemas

- [ ] Create `openregister/schemas/scholiq-course.json` with all Phase-1 fields (code, name, name_nl, description, level enum, language, tenant_id, published, tags, timestamps, deleted_at). Add index on (tenant_id, published, deleted_at).
- [ ] Create `openregister/schemas/scholiq-lesson.json` with all fields including mandatory_training bool and regulation_slug. Add index on (course_id, order).
- [ ] Create `openregister/schemas/scholiq-course-section.json` with (id, course_id, name, start_date, end_date, mode enum, tenant_id, nc_group_id). Add index on (course_id, tenant_id).
- [ ] Create `openregister/schemas/scholiq-xapi-statement.json` with `append_only: true`; all xAPI 1.0.3 fields; indexes on (actor.id, verb.id, stored), (course_id, tenant_id, stored), (verb.id, tenant_id, stored). Write unit test verifying OR rejects UPDATE on this schema.

## Phase 2: PHP services

- [ ] Create `Scholiq\Service\Cmi5LaunchService`: `mintLaunchToken(learnerId, lessonId, registrationId)` returning RS256 JWT; key stored in `OCP\Security\ICrypto` under `scholiq.cmi5.launch.private`; unit test mocks ICrypto, asserts token claims (actor, activity_id, registration, fetch_url, exp ≤ 8h).
- [ ] Create `Scholiq\Service\ScormToXapiTranslator`: maps the 7 SCORM API calls (LMSInitialize, LMSSetValue lesson_status, LMSSetValue score.raw, LMSSetValue suspend_data, LMSFinish) to xAPI verbs per ADR-002 §Decision (3) table; unit tests cover all 7 mappings.
- [ ] Create `Scholiq\Service\CourseContentService`: `ensureCourseFolder(tenantId, courseId)` using `IRootFolder`; `importPackage(courseId, lessonId, uploadedFile)` detecting cmi5.xml vs imsmanifest.xml manifest and unpacking; unit tests mock IRootFolder.

## Phase 3: PHP controllers

- [ ] Create `Scholiq\Controllers\CourseController` extending `AuditedController`: implement list (GET /api/courses with ?mandatory_training and ?regulation_slug filters), show, create (emits `course.published`), update (emits `course.published`), archive (sets deleted_at, emits `course.archived`). Role guard: create/update/delete restricted to admin/hr/instructor roles. Integration test: full CRUD cycle with audit event assertions.
- [ ] Create `Scholiq\Controllers\LessonController` extending `AuditedController`: list (sorted by order), show, create, update, delete, import endpoint (calls CourseContentService::importPackage), launch endpoint (calls Cmi5LaunchService::mintLaunchToken). Integration test: import cmi5 zip, verify folder created in nc:files, verify Lesson record created with correct content_type.
- [ ] Create `Scholiq\Controllers\LrsController`: POST /api/lrs/statements validates xAPI 1.0.3 structure, persists xapi_statement via ObjectService (append-only), emits `xapi.statement.received` audit event; GET /api/lrs/statements with actor-scoped filtering; returns 204/200 per xAPI spec. Integration test: post a cmi5.completed statement, verify it's queryable and audit event exists.
- [ ] Create `Scholiq\Controllers\ScormController`: GET /api/scorm/{lessonId}/launch serves SCORM player page from template; POST /api/scorm/{lessonId}/api accepts SCORM JSON-RPC calls and delegates to ScormToXapiTranslator; suspend_data stored in xapi result.extensions and returned on LMSGetValue. Integration test: simulate SCORM session with LMSInitialize → LMSSetValue(lesson_status, completed) → LMSFinish; verify xAPI statement in LRS.

## Phase 4: Vue frontend

- [ ] Add route entries to `src/router/index.js` for /courses, /courses/new, /courses/:id, /courses/:id/edit, /courses/:id/lessons/:lid.
- [ ] Create `src/stores/courseStore.js` using `createObjectStore('/api/courses')`. Write Vitest unit test confirming list, create, update, delete actions.
- [ ] Create `src/views/CourseListView.vue` using CnDataTable; columns: code, name, level, published status badge, lesson count; empty state uses NcEmptyContent with "Create your first course" action for admin/instructor role.
- [ ] Create `src/views/CourseDetailView.vue` using CnDetailPage + CnObjectSidebar with tabs: Details, Lessons, Enrolments count, Audit Trail. Playwright test: create course, navigate to detail, assert all tabs load.
- [ ] Create `src/views/CourseFormView.vue` with form fields per schema (code, name, name_nl, level dropdown, language dropdown, published toggle). Playwright test: fill form, submit, assert course appears in list.
- [ ] Create `src/views/LessonPlayer.vue` branching on content_type: cmi5 → iframe launch flow; scorm12/scorm2004 → SCORM shim iframe; video → `<video>` tag. Playwright test: create text lesson, navigate to player, assert content renders.

## Phase 5: Quality gate

- [ ] Add `lesson.created`, `lesson.updated`, `lesson.deleted` to `AuditEventTypes::KNOWN` in nextcloud-app spec's `AuditEventTypes.php`.
- [ ] Run `composer check:strict`; fix all PHPCS/PHPMD/Psalm/PHPStan violations before PR.
- [ ] Run `npm run lint`; fix all ESLint violations in Vue files.
- [ ] Playwright smoke tests: course CRUD round-trip, cmi5 launch token fetch, SCORM shim LMS API roundtrip.
