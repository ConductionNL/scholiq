# AI Companion Tools (delta)

## REMOVED Requirements

### Requirement: Provider declares an app id and a hard-coded tool catalogue (REQ-001)
**Reason**: ADR-063 (hydra #102) forbids hand-written MCP tool code. `ScholiqToolProvider` is deleted; OpenRegister's `SchemaDerivedToolProvider` derives the tool catalogue from the `x-openregister-mcp` schema dialect instead, so there is no app-owned catalogue to declare.

**Migration**: The two-tool catalogue is superseded by 12 derived tools over 6 curated schemas. See `openspec/specs/mcp-tool-surface/spec.md` REQ-001 (curated schema set) and REQ-006 (no hand-written provider).

#### Scenario: No app-owned tool catalogue exists
- GIVEN Scholiq is installed
- WHEN the MCP tool catalogue for app id `scholiq` is enumerated
- THEN every tool in it was derived by OpenRegister from the schema dialect
- AND no tool was declared by a Scholiq-owned `IMcpToolProvider`

### Requirement: Dispatcher routes by tool id and rejects unknown ids without throwing (REQ-002)
**Reason**: With no provider, Scholiq owns no dispatcher. Tool dispatch, unknown-id handling, and the error envelope are OpenRegister's responsibility on the derived surface.

**Migration**: No app-side replacement. Dispatch behaviour is covered by OpenRegister's `ai-mcp` capability.

#### Scenario: Scholiq owns no dispatcher
- GIVEN an MCP tool call for app id `scholiq`
- WHEN the call is dispatched
- THEN OpenRegister resolves and invokes the derived tool
- AND no Scholiq class participates in dispatch

### Requirement: listCourses validates args, gates on authentication, caps results, and returns privacy-safe summaries (REQ-003)
**Reason**: `scholiq.listCourses` is derivable CRUD — a `course` search with a `lifecycle` filter — and is replaced by the derived `scholiq.course.search`. Argument validation, result caps, and the citation envelope are OpenRegister's, not Scholiq's.

**Migration**: The one behaviour in this requirement that was NOT plain CRUD — the non-admin "published courses only" gate (#197 / M2) — is preserved and moved into the schema as an `authorization.read` conditional rule. See `openspec/specs/mcp-tool-surface/spec.md` REQ-005. That rule is stricter than the removed one: it applies to the REST API and the UI as well, not only to the MCP surface.

#### Scenario: The published-only gate survives the migration
- GIVEN a draft course and an authenticated non-admin caller
- WHEN the caller searches courses through the derived MCP surface
- THEN the draft course is not returned
- AND the gate is enforced by the schema's `authorization.read` rule, not by app code

### Requirement: getCourseDetails resolves a course by id/uuid/slug/code, returns ordered modules, and never leaks learner PII (REQ-004)
**Reason**: `scholiq.getCourseDetails` is derivable CRUD (composite) — a `course` get plus a `lesson` search filtered on `courseId` — and is replaced by the derived `scholiq.course.get` + `scholiq.lesson.search`.

**Migration**: See `openspec/specs/mcp-tool-surface/spec.md` REQ-001 and REQ-004 (the `lesson` search declares `courseId` as a filter). The "never leaks learner PII" guarantee is strengthened, not weakened: REQ-003 of the new capability forbids the dialect on every learner-data schema, so no learner object is reachable from any Scholiq tool — not merely from this one.

#### Scenario: Course modules are still reachable
- GIVEN a published course with three lessons
- WHEN an agent calls `scholiq.course.get` and then `scholiq.lesson.search` filtered on `courseId`
- THEN the course metadata and its three lessons are returned
- AND no Enrolment, Attestation, Credential or learner object is reachable from any Scholiq tool

### Requirement: Object normalisation accepts arrays, OpenRegister entities, and JsonSerializable shapes (REQ-005)
**Reason**: `toArray()` / `extractUuid()` were plumbing internal to the deleted provider. The derived surface serialises OpenRegister objects itself.

**Migration**: No app-side replacement.

#### Scenario: Scholiq owns no object-normalisation code for MCP
- GIVEN the derived MCP surface returns a course
- WHEN the object is serialised for the agent
- THEN OpenRegister performs the serialisation
- AND no Scholiq class normalises MCP payloads
