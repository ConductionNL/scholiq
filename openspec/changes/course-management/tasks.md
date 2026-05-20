# Tasks — Course Management (Phase 2)

> Scope: schema patches for `Module`, `LearningPath`, `Prerequisite`, `CatalogChangeRequest` plus extensions to the existing `Course` schema (ECTS, OOAPI fields, clone metadata). Plus three ADR-031-exception PHP files (`OoapiController`, `CourseCloneService`, updated `CoursePublishGuard`). Frontend: `src/manifest.json` additions and `CourseCloneModal.vue`.
>
> Phase 1 implementation (schemas: Course, Lesson, XapiStatement; controllers: LrsController, ScormController, LessonImportController; services: Cmi5ImporterService, ScormToXapiTranslator) is assumed complete and unchanged unless noted.

---

## Phase 1: Deduplication Check (ADR-001)

- [ ] Verify `Module`, `LearningPath`, `Prerequisite`, and `CatalogChangeRequest` do not duplicate any existing OpenRegister schema in `openregister/lib/Service/` or `openspec/specs/`. Document findings in a one-line comment in this file. (Expected: no overlap — these schemas are Scholiq-domain-specific.)
- [ ] Verify `OoapiController` does not duplicate any existing OR/nextcloud-vue generic export controller. (Expected: OOAPI 5.0 is Scholiq-specific; no OR abstraction exists for this protocol.)
- [ ] Verify `CourseCloneService` cannot be replaced by OR's existing `ObjectService::copyObject()` primitive. Document outcome. (Expected: deep-clone across three schema types with field patching requires the composite service; a single `copyObject` call is insufficient.)

---

## Phase 2: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] **Extend `Course` schema** with non-breaking optional fields: `ects`, `ooApiCode`, `ooApiEducationSpecificationId`, `clonedFromId`, `academicYear`, `approvalStatus`. Update `level` enum to include NLQF values `nlqf1`–`nlqf8`. Add calculated fields `moduleCount` and `totalEcts` (see design §1.1). Update `CoursePublishGuard` assertion from `lessonCount > 0` to `moduleCount > 0`. Validate via OR schema-validation endpoint. All fields optional — non-breaking per ADR-011.

- [ ] **Add `Module` schema** per design §1.2: `x-openregister-relations` (course many-to-one, lessons one-to-many), `x-openregister-lifecycle` (draft → published → retired), `x-openregister-calculations` (lessonCount). Required fields: `courseId`, `name`, `order`, `tenant_id`. Validate via OR schema-validation endpoint.

- [ ] **Add `LearningPath` schema** per design §1.3: `x-openregister-lifecycle` (draft → published → archived), `x-openregister-calculations` (courseCount). Required fields: `name`, `tenant_id`. Validate via OR schema-validation endpoint.

- [ ] **Add `Prerequisite` schema** per design §1.4: `x-openregister-relations` (sourceCourse + targetCourse both many-to-one). No lifecycle (Prerequisite objects are created/deleted, not transitioned). Required fields: `sourceCourseId`, `targetCourseId`, `conditionType`, `tenant_id`. Validate via OR schema-validation endpoint.

- [ ] **Add `CatalogChangeRequest` schema** per design §1.5: `x-openregister-lifecycle` (draft → submitted → approved | rejected | withdrawn) with `approve` cascade to `Course.publish`, `x-openregister-notifications` (committeeReviewRequested, requestApproved, requestRejected), `x-openregister-relations` (course many-to-one). Required fields: `courseId`, `requestedById`, `changeDescription`, `tenant_id`. Validate via OR schema-validation endpoint.

- [ ] **Add seed data** for all new schemas (see design §2): 5 `Module` objects, 3 `LearningPath` objects, 3 `Prerequisite` objects, 3 `CatalogChangeRequest` objects. Use Dutch values per ADR-001 seed data requirements. Verify import is idempotent (re-importing with `force: false` MUST NOT create duplicates — matched by slug).

- [ ] Write JSON-validation tests asserting the five updated/new schema entries parse against OR's schema-extension contract. Run `composer test:schemas`.

---

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [ ] **Update `lib/Lifecycle/CoursePublishGuard.php`**: change assertion from `$course->getLessonCount() > 0` to `$course->getModuleCount() > 0`. Unit test: mock Course with 0 modules → guard returns false; with 1 published module → guard returns true.

- [ ] **Create `lib/Controller/OoapiController.php`**:
  - `GET /ooapi/v5/courses`: paginated list of published Courses; maps OR Course objects to OOAPI 5.0 Course schema (credits=totalEcts, language=language, level=level, educationSpecificationId=ooApiEducationSpecificationId). Annotate `#[PublicPage]` + `#[NoAdminRequired]`. Validate Bearer token via `OCP\Security\ISecureRandom` or `OCP\IConfig` shared-secret check (institution API key).
  - `GET /ooapi/v5/courses/{id}`: single Course. Same mapping. Returns 404 if Course is draft/archived.
  - `GET /ooapi/v5/education-specifications`: list of education specification stubs derived from `ooApiEducationSpecificationId` fields present on published Courses.
  - Register CORS OPTIONS route in `appinfo/routes.php`.
  - Integration test: seed a published Course with ECTS=10, level='nlqf6'; call the endpoint; assert response body validates against OOAPI 5.0 JSON schema.

- [ ] **Create `lib/Service/CourseCloneService.php`**:
  - `clone(string $courseId, string $academicYear): string` — returns new Course UUID.
  - Reads source Course + all Modules + all Lessons via `ObjectService::findObjects`.
  - Writes new Course (lifecycle='draft', clonedFromId=source, academicYear=new) via OR REST batch.
  - For each Module: creates new Module copy (lifecycle='draft', courseId=new Course UUID, same fields).
  - For each Lesson: creates new Lesson copy (lifecycle='draft', courseId=new Course UUID, moduleId=new Module UUID, same contentRef).
  - Registers as `POST /api/courses/{id}/clone` via `CourseCloneController` (thin one-action controller calling this service).
  - Unit test: mock OR responses; assert 1 Course + 2 Modules + 4 Lessons created; assert source Course unchanged; assert `clonedFromId` set.
  - Integration test: full round-trip — clone a seeded Course; verify new objects exist in OR; verify source enrolments unaffected.

- [ ] **Append new routes to `appinfo/routes.php`**:
  ```php
  ['name' => 'OoapiController#courses',         'url' => '/ooapi/v5/courses',               'verb' => 'GET'],
  ['name' => 'OoapiController#coursesById',      'url' => '/ooapi/v5/courses/{id}',          'verb' => 'GET'],
  ['name' => 'OoapiController#eduSpecs',         'url' => '/ooapi/v5/education-specifications', 'verb' => 'GET'],
  ['name' => 'OoapiController#options',          'url' => '/ooapi/v5/{path}',                'verb' => 'OPTIONS'],
  ['name' => 'CourseCloneController#clone',      'url' => '/api/courses/{id}/clone',         'verb' => 'POST'],
  ```

- [ ] **Update Phase 1 cmi5 launch token** in `Cmi5LaunchTokenService` (or `LrsController`) to include `moduleId` context extension in the JWT payload when the Lesson has a `moduleId`. Integration test: launch a Lesson that has a moduleId; assert the stored xAPI statement contains `context.extensions["http://scholiq.nl/extensions/moduleId"]`.

---

## Phase 4: Frontend — manifest extension

- [ ] **Extend `src/manifest.json`** with Module index/detail pages, LearningPath index/detail pages, CatalogChangeRequest index/detail pages, and `CourseCloneModal` custom page per design §4.1. Run `npm run check:manifest` — MUST pass.

- [ ] **Create `src/views/CourseCloneModal.vue`** per design §4.2: one-step modal with `academicYear` text input (format: YYYY-YYYY, client-side validation), confirmation button, POSTs to `/api/courses/{id}/clone`, navigates to new draft Course on success. Register via `customComponents` on `CnAppRoot` in `src/main.js`. Playwright test: open the modal on a published Course; submit; assert new draft Course appears in course list with correct academicYear.

- [ ] **Verify prerequisite enforcement UI** in the enrolment flow (owned by `enrolment` spec, but validate integration here): confirm that `GET /api/openregister/scholiq/Prerequisite?targetCourseId={id}` returns the seeded Prerequisite objects and that the enrolment Vue component renders the disabled-enrol state. Add a Playwright test: navigate to a course with an unmet prerequisite; assert enrol button is disabled and description text is visible.

- [ ] **Do NOT** create `src/stores/moduleStore.js`, `src/stores/learningPathStore.js`, or any `src/views/*ListView.vue` / `*FormView.vue` for the new schemas — `CnAppRoot`'s built-in index + detail renderers cover all CRUD.

---

## Phase 5: Documentation

- [ ] Create `docs/features/course-management.md` with a feature overview, screenshot of the Course → Module → Lesson hierarchy view, screenshot of the CatalogChangeRequest approval workflow, and a note on OOAPI 5.0 integration. Use Playwright MCP to capture screenshots per ADR-010.
- [ ] Add Dutch (nl) translations for all new UI strings introduced in `CourseCloneModal.vue` and any new manifest page titles to `l10n/nl.js` and `l10n/en.js`.

---

## Phase 6: Quality gates

- [ ] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan level 8); fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; MUST pass with new pages registered.
- [ ] Unit test coverage ≥ 75% for all new PHP files (`OoapiController`, `CourseCloneService`, updated `CoursePublishGuard`). Run `composer test:coverage`.
- [ ] Playwright smoke tests:
  - Module CRUD round-trip via `CnAppRoot` index page.
  - CatalogChangeRequest submit → approve flow; assert Course transitions to published.
  - Course clone flow; assert new draft Course created with correct `clonedFromId` and `academicYear`.
  - OOAPI 5.0 endpoint returns valid response for seeded published Course.
  - Prerequisite-unmet: enrol button disabled with description text visible.
- [ ] Verify OR audit trail contains `module.published`, `catalogchangerequest.approved`, `course.published` (cascade), `learningpath.published` entries after smoke tests. Assert via OR audit-trail query API.
