# Design — Retrofit `ai-companion-tools`

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.** The code already exists at `lib/Mcp/ScholiqToolProvider.php` (shipped 2026-05-12 alongside hydra ADR-034 + ADR-035 and openregister PR #1466). This change documents the observable contract so future changes have something to extend.

## Goal

Capture the observed behaviour of the 15 methods on `ScholiqToolProvider` as 5 numbered REQs grouped into a new `ai-companion-tools` capability:

| REQ | Methods covered |
|---|---|
| REQ-001 — Provider declares an app id and a hard-coded tool catalogue | `getAppId`, `getTools` |
| REQ-002 — Dispatcher routes by tool id and rejects unknown ids without throwing | `invokeTool` |
| REQ-003 — listCourses validates args, gates on authentication, caps results, and returns privacy-safe summaries | `handleListCourses`, `validateListCoursesArgs`, `requireCourseReadAccess`, `courseSummary`, `courseSource` |
| REQ-004 — getCourseDetails resolves a course by id/uuid/slug/code, returns ordered modules, and never leaks learner PII | `handleGetCourseDetails`, `findCourse`, `loadCourseModules`, `moduleSummary`, `buildDeepLink` |
| REQ-005 — Object normalisation accepts arrays, OpenRegister entities, and JsonSerializable shapes | `toArray`, `extractUuid` |

## Why a new capability (not `--extend`)

The coverage report parked this cluster under `bucket_2a/assessment` because the matcher had to put it somewhere — but `assessment` is the Tests/Exams capability (item banks, QTI 3.0, proctoring). MCP tool provision is genuinely new territory: a per-app contract with hydra's AI chat companion via openregister's `IMcpToolProvider` abstraction. A new capability lets future work (adding more tools, tightening provider-side auth, switching to a dynamic catalogue) extend a clearly-scoped baseline rather than bloating an unrelated capability.

## Non-goals

- No code behaviour changes — annotations only.
- No "fixing" of observed-but-suspicious behaviour. The `requireCourseReadAccess()` tautological tail (`return $userId !== ''` after the empty-string check) is documented in REQ-003 Notes; tightening to a Scholiq-specific group check is a future spec.
- No Dutch translation of tool descriptions — consistent with the rest of MCP tooling at this revision.
- No tools that touch learner data — Enrolment / Attestation / Credential / per-learner tooling is deferred to a follow-up that wires proper per-student authorisation.

## Notes for archiver

- New capability spec under `openspec/specs/ai-companion-tools/spec.md` (no existing file — `--cluster` semantics).
- `retrofit: true` in frontmatter so Specter dashboards can filter the retrofit cohort.
- Annotations on `lib/Mcp/ScholiqToolProvider.php` reference `openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-N`; after archive the tags continue resolving via the archived change directory (ADR-003 convention).
