---
retrofit: true
---

# ai-companion-tools

## Why

The hydra AI Chat Companion (ADR-034 + ADR-035) wires a per-app `IMcpToolProvider` (openregister PR #1466) so an LLM can call back into a Conduction app for live data. Scholiq holds course catalogues alongside privacy-sensitive learner records, so its MVP provider deliberately exposes only the two least-sensitive read-only tools (`scholiq.listCourses`, `scholiq.getCourseDetails`). This capability spec retroactively documents the observable contract of that provider so future changes (adding tools, tightening auth, adopting an MCP-wide registry) extend an explicit baseline rather than relying on code-archaeology.

## What

- A per-app `IMcpToolProvider` implementation registered for the `scholiq` app id
- A hard-coded read-only tool catalogue exposing exactly two tools: `scholiq.listCourses` and `scholiq.getCourseDetails`
- Per-call argument validation, then per-object authorisation, then business logic — in that order
- Structured `{success | isError, error, message}` responses for every tool call (no thrown exceptions across the MCP boundary)
- Privacy guarantee: course + module metadata only — never `Enrolment`, `Attestation`, `Credential` or learner objects

## ADDED Requirements

### REQ-001: Provider declares an app id and a hard-coded tool catalogue
The provider MUST declare the `"scholiq"` app id and MUST return a fixed tool catalogue containing exactly the two read-only tools `scholiq.listCourses` and `scholiq.getCourseDetails`, regardless of caller identity or permissions. The catalogue MUST advertise input schemas with a `limit` (1–50, default 20) and optional `status` enum (`draft|published|archived`) for `listCourses`, and a required `id` string for `getCourseDetails`. The full catalogue is the discovery surface; per-object authorisation runs later, on invocation.

#### Scenario: Catalogue is the same for every caller
- GIVEN any caller (signed-in user, admin, or unauthenticated discovery)
- WHEN `getTools()` is invoked
- THEN the response lists exactly `scholiq.listCourses` and `scholiq.getCourseDetails` with their input schemas
- AND the catalogue does not change based on the caller's permissions

Covers: `getAppId()`, `getTools()`.

### REQ-002: Dispatcher routes by tool id and rejects unknown ids without throwing
`invokeTool()` MUST route exclusively on the tool id string, dispatching `scholiq.listCourses` to the list-courses handler and `scholiq.getCourseDetails` to the details handler. An unknown tool id MUST return a structured `{isError: true, error: "unknown_tool", message: "..."}` response that names the unknown id and lists the available tool ids — no exception MUST escape the dispatcher.

#### Scenario: Unknown tool id returns a structured error
- GIVEN any caller invokes `invokeTool('scholiq.nope', [])`
- WHEN the dispatcher matches no handler
- THEN the response is `{isError: true, error: 'unknown_tool', message: "Unknown tool id 'scholiq.nope'. Available tools: scholiq.listCourses, scholiq.getCourseDetails."}`
- AND no exception is thrown across the MCP boundary

Covers: `invokeTool()`.

### REQ-003: listCourses validates args, gates on authentication, caps results, and returns privacy-safe summaries
`handleListCourses()` MUST validate arguments first (reject `limit` outside 1–50 and `status` outside the lifecycle enum with `{isError: true, error: "invalid_arguments", ...}`), then gate on authentication via `requireCourseReadAccess()` (returning `{isError: true, error: "forbidden", ...}` for unauthenticated callers), then read at most `LIST_CAP` (20) `Course` objects via `ObjectService::findAll` — applying the optional `lifecycle` filter when `status` is set. Each returned course MUST be reshaped to an allow-listed catalogue summary (`uuid, code, name, name_nl, description, level, language, tags, mandatoryTraining, regulationSlug, renewalCourseSlug, lifecycle`) and accompanied by a citation `source` of `type: "scholiq.course"` pointing at `/apps/scholiq/courses/<uuid>`. A read failure MUST be logged at `error` level and surfaced as `{isError: true, error: "internal_error", ...}` — no exception escapes the tool.

#### Scenario: Authenticated list with status filter returns capped, privacy-safe summaries
- GIVEN an authenticated user invokes `handleListCourses({limit: 100, status: 'published'})`
- WHEN the handler runs
- THEN `validateListCoursesArgs` returns `limit: 100, status: 'published'`
- AND the `ObjectService::findAll` call passes `limit: 20` (the hard cap, not 100) and `filters: {lifecycle: 'published'}`
- AND each result in `courses[]` contains only the allow-listed catalogue fields
- AND each entry in `sources[]` has `type: "scholiq.course"` and `url: "/apps/scholiq/courses/<uuid>"`

#### Scenario: Unauthenticated caller is rejected before any read
- GIVEN an unauthenticated caller invokes `handleListCourses({})`
- WHEN argument validation passes and `requireCourseReadAccess()` returns false
- THEN the handler returns `{isError: true, error: "forbidden", message: "You must be signed in to list courses."}`
- AND no `ObjectService::findAll` call is made

#### Notes
- `requireCourseReadAccess()` admits any user with a non-empty `getUID()` — the final `return $userId !== ''` is tautological after the earlier empty-string check and effectively means "authenticated = allowed". OpenRegister's RBAC inside `ObjectService` is the second, per-object gate. This is the observed behaviour; tightening to a Scholiq-specific group check is a future spec.

Covers: `handleListCourses()`, `validateListCoursesArgs()`, `requireCourseReadAccess()`, `courseSummary()`, `courseSource()`.

### REQ-004: getCourseDetails resolves a course by id/uuid/slug/code, returns ordered modules, and never leaks learner PII
`handleGetCourseDetails()` MUST require a non-empty `id` argument, then gate on authentication via `requireCourseReadAccess()`, then resolve the course via `findCourse()` — trying direct id/uuid lookup first, then a filtered search by `slug`, then by `code`, returning the first match or null. A null resolution MUST surface as `{isError: true, error: "not_found", ...}`. On success the response MUST include the catalogue summary (REQ-003), an ordered list of `Lesson` summaries (`uuid, name, order, contentType, durationMinutes, learningObjectives, mandatoryTraining, regulationSlug, lifecycle`) sorted ascending by the 1-based `order` field via `loadCourseModules()`, and a `sources[]` array with one `scholiq.course` source plus one `scholiq.module` source per module — every URL built by `buildDeepLink()` from the table `course → /apps/scholiq/courses, module → /apps/scholiq/modules` (with a `{type}s` fallback for unknown types). The response MUST NOT contain `Enrolment`, `Attestation`, `Credential` or any learner-scoped object. Module-list lookup failures MUST be logged and degrade to an empty `modules[]` (course details still render); course-lookup failures MUST surface as `{isError: true, error: "internal_error", ...}`.

#### Scenario: Resolution falls back from uuid to slug to code
- GIVEN a course exists with `code: 'NL-1'` but no matching uuid or slug
- WHEN an authenticated user invokes `handleGetCourseDetails({id: 'NL-1'})`
- THEN `findCourse('NL-1')` first calls `ObjectService::find(id: 'NL-1', ...)` (returns null)
- AND falls back to `findAll(filters: {slug: 'NL-1'}, limit: 1)` (empty)
- AND then to `findAll(filters: {code: 'NL-1'}, limit: 1)` (returns the course)
- AND the response includes the course summary, ordered modules, and corresponding deep-link sources

#### Scenario: Module-load failure degrades to empty modules without failing the response
- GIVEN a course resolves successfully
- WHEN `loadCourseModules` throws during `ObjectService::findAll`
- THEN the exception is logged at error level with the courseUuid
- AND the response returns `{success: true, course: {...}, modules: [], sources: [{type: "scholiq.course", ...}]}`

Covers: `handleGetCourseDetails()`, `findCourse()`, `loadCourseModules()`, `moduleSummary()`, `buildDeepLink()`.

### REQ-005: Object normalisation accepts arrays, OpenRegister entities, and JsonSerializable shapes
The provider MUST normalise inputs from `ObjectService` into plain `array<string, mixed>` via `toArray()` — returning the input unchanged when already an array, otherwise calling `getObject()` then `jsonSerialize()` on objects (in that order, returning the first array-shaped result), with a final `(array)` cast fallback. UUID extraction via `extractUuid()` MUST consult, in order, `uuid`, `id`, `@self.uuid`, `@self.id`, returning the first present value as a string, or the empty string when none are present. These helpers MUST be the only normalisation path used inside the provider so adding a new tool inherits the same shape contract.

#### Scenario: Entity exposing getObject() is normalised
- GIVEN `ObjectService::findAll` returns objects exposing `getObject(): array`
- WHEN the handler iterates results
- THEN `toArray()` returns the `getObject()` array unchanged
- AND `extractUuid()` finds the uuid at `['uuid']`, `['id']`, `['@self']['uuid']` or `['@self']['id']`

#### Notes
- The `(array)` cast fallback means objects without `getObject()`/`jsonSerialize()` are exposed with their full property surface, which could include private fields rendered with mangled keys. No such shape is observed in practice from `ObjectService` — kept as a defensive last resort.

Covers: `toArray()`, `extractUuid()`.

## Non-Functional Requirements

- **Privacy:** Tools MUST NOT return `Enrolment`, `Attestation`, `Credential` or any learner-scoped object. The two MVP tools are the catalogue and one course's module structure — both learner-free.
- **Failure mode:** No exception MUST cross the MCP boundary. All failure paths return a structured `{isError: true, error, message}` response and log at `error` level with context.
- **Limits:** `listCourses` MUST cap its result set at 20 regardless of the requested `limit` (which itself is bounded to 1–50 at argument validation).
- **i18n:** Tool descriptions are English-only at this revision (consistent with the rest of MCP tooling, hydra ADR-034). Dutch localisation is a future change.

## Acceptance Criteria

- [x] `getAppId()` returns the literal `"scholiq"`
- [x] `getTools()` returns the two-tool catalogue regardless of caller
- [x] `invokeTool('scholiq.nope', [])` returns a structured `unknown_tool` error (no exception)
- [x] `handleListCourses({limit: 100})` caps the underlying `findAll` to `limit: 20`
- [x] `handleListCourses` rejects unauthenticated callers before any read
- [x] `handleGetCourseDetails` resolves by uuid → slug → code in that order
- [x] `loadCourseModules` failures degrade to empty modules + logged error
- [x] No response shape includes `Enrolment`, `Attestation`, `Credential` or learner fields
- [x] `toArray()` returns input unchanged for arrays, uses `getObject()` then `jsonSerialize()` for objects
- [x] `extractUuid()` consults `uuid → id → @self.uuid → @self.id` in that order

## Notes

- This spec captures observed behaviour shipped 2026-05-12 alongside hydra ADR-034 + ADR-035 + openregister PR #1466. It is a retrofit — it does not propose changes.
- Future tightening (Scholiq-specific group check in `requireCourseReadAccess`, Dutch tool descriptions, per-learner-authorised tools for enrolment/attestation/credential) belongs in follow-up `MODIFIED Requirements` deltas, not this baseline.
- The provider exposes a `LIST_CAP` of 20 distinct from the catalogue's advertised `limit: 50` upper bound; this is the observed contract — the maximum requested via input schema may exceed the actual cap, and the hard cap wins.
