# Tasks — Course Management

> Scope: schema patches for `Course`, `Lesson`, `XapiStatement` declaring all lifecycle / aggregation / calculation / relation behaviour via `x-openregister-*`. Plus the four ADR-031-exception PHP files (cmi5 importer, SCORM translator, LRS + SCORM + Lesson-import controllers, CoursePublishGuard).

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add `Course` schema per design §1.1 — including `x-openregister-lifecycle` (`draft → published → archived` with `CoursePublishGuard` precondition on publish), `x-openregister-calculations` (`lessonCount`, `isPublished`), `x-openregister-aggregations` (`enrolledLearners`, `completedLearners`). Validate via OR's schema-validation endpoint. Reference: `decidesk/lib/Settings/decidesk_register.json` Meeting schema for lifecycle + aggregations + calculations.
- [ ] Add `Lesson` schema per design §1.2 — including `x-openregister-relations` (`course` many-to-one) and `x-openregister-lifecycle` (`draft → published → retired`).
- [ ] Add `XapiStatement` schema per design §1.3 — `appendOnly: true` (consumes OR's append-only abstraction per ADR-022); empty `transitions` map signals "every save audits as xapi.statement.received".
- [ ] Write a JSON-validation test that asserts the three new schema entries parse against OR's schema-extension contract.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/CoursePublishGuard.php`: single `check($transitionContext)` method asserting `$course->getLessonCount() > 0`. Receives the Course object via the transition context. Unit test: mock context with 0 lessons → returns false; with 3 lessons → returns true.
- [ ] Create `lib/Service/Cmi5ImporterService.php`: `import($uploadedFile, $courseId)` detects manifest type (`cmi5.xml` vs `imsmanifest.xml`); unpacks via OR's archival/file abstraction; creates Lesson objects via `ObjectService::saveObject` with correct `contentType` (`cmi5` / `scorm12` / `scorm2004`); returns array of created lesson UUIDs. Legitimate per ADR-031 — domain-specific text processing. Unit test: mock a 2-AU cmi5 ZIP → assert 2 Lesson objects created.
- [ ] Create `lib/Service/ScormToXapiTranslator.php`: maps the 7 SCORM API calls (LMSInitialize, LMSSetValue lesson_status, LMSSetValue score.raw, LMSSetValue suspend_data, LMSFinish, etc.) to xAPI verbs per ADR-002 §Decision (3). Output is a partial xAPI statement; the caller writes it via `ObjectService::saveObject('XapiStatement', ...)`. Legitimate per ADR-031 — external-system contract. Unit tests cover all 7 mappings.
- [ ] Create `lib/Controller/LrsController.php`: POST `/api/lrs/statements` validates xAPI 1.0.3 envelope, writes the statement via `ObjectService::saveObject('XapiStatement', ...)`, returns 200 + statement id per xAPI spec. GET `/api/lrs/statements` queries OR via `ObjectService` with actor-scoped filtering. Auth: NC session OR cmi5 JWT from `Cmi5LaunchTokenService`. Integration test: post a `cmi5.completed` statement → assert it's queryable AND the OR audit-trail entry `xapi.statement.received` exists (verifying the schema's append-only lifecycle fired).
- [ ] Create `lib/Controller/ScormController.php`: GET `/api/scorm/{lessonId}/launch` serves the SCORM player page from `templates/scorm-player.php`; POST `/api/scorm/{lessonId}/api` accepts SCORM JSON-RPC calls, delegates to `ScormToXapiTranslator`, persists the translated statement to `XapiStatement`. Integration test: simulate SCORM session → assert xAPI statement materialised.
- [ ] Create `lib/Controller/LessonImportController.php`: POST `/api/courses/{courseId}/lessons/import` accepts the uploaded ZIP, calls `Cmi5ImporterService::import`. Integration test: upload a cmi5 ZIP → assert 2 Lesson objects exist + Course's calculated `lessonCount` is now 2.
- [ ] Append the new routes to `appinfo/routes.php`.

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with `CourseDetail` page (`type: detail`, bound to register=scholiq schema=Course) and `LessonPlayer` page (`type: custom`, `component: LessonPlayer`). Re-run `npm run check:manifest`.
- [ ] Create `src/views/LessonPlayer.vue` per design §3.2 — branches on `lesson.contentType`. Register via `customComponents` on `CnAppRoot` in `src/main.js`. Playwright test: navigate to a `text` lesson → assert HTML renders; navigate to a `cmi5` lesson → assert the launch-token endpoint is called.
- [ ] **Do NOT** create `src/router/index.js` route entries. **Do NOT** create `src/stores/courseStore.js` or `src/views/CourseListView.vue` / `CourseFormView.vue` / `CourseDetailView.vue` — `CnAppRoot`'s built-in index + detail renderers cover the wedge. (If a future feature genuinely needs a custom view, register it via `customComponents`.)

## Phase 4: Audit-event vocabulary — none

- [ ] **Do NOT** add `lesson.created` / `lesson.updated` / `lesson.deleted` to a Scholiq-side `AuditEventTypes::KNOWN`. OR's lifecycle engine emits the event types automatically per schema metadata. ADR-022 + ADR-008 prohibit a parallel app-side vocabulary.

## Phase 5: Quality gate

- [ ] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Playwright smoke tests: Course CRUD round-trip via `CnAppRoot`'s built-in index page; cmi5 launch-token fetch returns a valid JWT; SCORM shim LMS API roundtrip persists an xAPI statement.
