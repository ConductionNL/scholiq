# Tasks: scholiq-mcp-adoption

## Implementation Tasks

### Task 1: Declare the MCP dialect on the 6 curated schemas (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-exactly-six-curated-schemas-declare-the-mcp-dialect-req-001`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN `course`, `lesson`, `programme`, `session`, `assignment`, `regulation` are edited THEN each carries `configuration["x-openregister-mcp"]` with `enabled: true` and a `tools` block holding only `search` and `get`, each with `scope: "read"` and `readOnlyHint: true`
  - GIVEN the same six schemas WHEN their `search.filters` lists are read THEN every entry is a real property of that schema (per REQ-004's list) and no `create`/`update`/`delete` verb appears anywhere in the file
  - GIVEN the other 60 schemas WHEN the file is grepped for `x-openregister-mcp` THEN exactly 6 occurrences are found
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/scholiq_register.json` runs THEN it exits 0 and no pre-existing key is dropped
- [ ] Implement
- [ ] Test

### Task 2: Add the `authorization.read` lifecycle gate (must / MVP) — BLOCKS Task 3
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-draft-and-archived-content-is-not-readable-by-non-admin-callers-req-005`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN `course`, `lesson`, `programme`, `regulation` WHEN edited THEN each carries `"authorization": {"read": [{"group": "authenticated", "match": {"lifecycle": {"$eq": "published"}}}, "admin"]}`
  - GIVEN `assignment` WHEN edited THEN its match is `{"lifecycle": {"$in": ["published", "closed"]}}`
  - GIVEN `session` WHEN edited THEN it carries NO lifecycle match rule (its enum has no draft state)
  - GIVEN a draft course and an authenticated non-admin caller WHEN `scholiq.course.search` runs THEN the draft course is absent; WHEN the same caller runs `scholiq.course.get` on it THEN the read is denied
  - GIVEN an admin caller WHEN `scholiq.course.search` runs THEN the draft course IS returned
- [ ] Implement
- [ ] Test

### Task 3: Delete `ScholiqToolProvider` and every trace of it (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-code-remains-in-scholiq-req-006`
- **files**: `lib/Mcp/ScholiqToolProvider.php` (delete), `tests/Unit/Mcp/ScholiqToolProviderTest.php` (delete), `tests/Stubs/Mcp/IMcpToolProvider.php` (delete), `lib/AppInfo/Application.php`, `tests/bootstrap.php`, `tests/bootstrap-unit.php`
- **acceptance_criteria**:
  - GIVEN Task 2 passes WHEN the provider is deleted THEN `lib/Mcp/` is empty and `grep -rn "ScholiqToolProvider" lib/ tests/` returns nothing
  - GIVEN `lib/AppInfo/Application.php` WHEN the `Bootstrap::register()` options array is read THEN the `'mcpProvider' => ScholiqToolProvider::class` entry and the `use OCA\Scholiq\Mcp\ScholiqToolProvider;` import are gone
  - GIVEN `tests/bootstrap.php` and `tests/bootstrap-unit.php` WHEN read THEN the now-dead `IMcpToolProvider` stub `require_once` guards are removed
  - GIVEN the app is installed WHEN the container is asked for `OCA\OpenRegister\Mcp\IMcpToolProvider::scholiq` THEN no service is registered
  - GIVEN the touched PHP files WHEN scoped PHPCS runs THEN it is clean, and `composer test` shows zero new failures against a self-measured baseline
- [ ] Implement
- [ ] Test

### Task 4: Verify the derived surface and record the migration (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-no-learner-personal-data-and-no-exam-content-is-exposed-req-003`
- **files**: `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN the register is re-imported WHEN `McpAnnotationValidator::validate()` runs on each schema THEN no `mcp-unknown-filter-property`, `mcp-unknown-verb`, `mcp-bad-scope`, `mcp-bad-hint` or `mcp-missing-enabled` error is returned
  - GIVEN the MCP tool catalogue for app id `scholiq` WHEN enumerated THEN it contains exactly 12 tools (`{course,lesson,programme,session,assignment,regulation}.{search,get}`), and `scholiq.listCourses` / `scholiq.getCourseDetails` are ABSENT (no shadow)
  - GIVEN the same catalogue WHEN enumerated THEN it contains no `scholiq.learner-profile.*`, `scholiq.grade-entry.*`, `scholiq.attendance-record.*`, `scholiq.item.*`, `scholiq.assessment.*` or `scholiq.cohort.*` tool
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the ADR-063 migration and the breaking tool-id change (`scholiq.listCourses` → `scholiq.course.search`)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate scholiq-mcp-adoption --type change --strict` passes
- [ ] Manual testing against acceptance criteria (non-admin sees no drafts; admin does)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit: the deleted `ScholiqToolProviderTest` is removed, not skipped; zero new failures vs a self-measured baseline (`composer test`)
- [ ] All tests pass
- Newman/Postman: N/A — this change adds no HTTP endpoint. The MCP surface is served by OpenRegister's `/api/mcp`, which is covered by openregister's own suite.
- Browser tests (Playwright MCP): N/A — no UI change. The `authorization` rule does change what a non-admin sees in the course list, which is covered by the manual verification above.

## Documentation (company-wide ADR-010)
- [ ] `docs/` records the curated MCP schema set, the read-only posture, and the AVG rationale for the 60 OFF schemas
- Screenshots: N/A — no user-facing UI is added or changed.

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings. The tool descriptions in the dialect are agent-facing prose read by an LLM, not UI copy, and are English by convention.
