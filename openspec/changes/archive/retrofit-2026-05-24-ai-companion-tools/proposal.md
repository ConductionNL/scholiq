# Retrofit — ai-companion-tools

Describes the observed behaviour of 15 methods on `lib/Mcp/ScholiqToolProvider.php` as 5 new REQs under a brand-new `ai-companion-tools` capability. Code already exists (shipped 2026-05-12 alongside hydra ADR-034 + ADR-035 and openregister PR #1466) — this change retroactively specifies it so future changes have something to extend.

## Affected code units

- `lib/Mcp/ScholiqToolProvider.php::getAppId()`
- `lib/Mcp/ScholiqToolProvider.php::getTools()`
- `lib/Mcp/ScholiqToolProvider.php::invokeTool()`
- `lib/Mcp/ScholiqToolProvider.php::handleListCourses()`
- `lib/Mcp/ScholiqToolProvider.php::validateListCoursesArgs()`
- `lib/Mcp/ScholiqToolProvider.php::handleGetCourseDetails()`
- `lib/Mcp/ScholiqToolProvider.php::loadCourseModules()`
- `lib/Mcp/ScholiqToolProvider.php::courseSource()`
- `lib/Mcp/ScholiqToolProvider.php::requireCourseReadAccess()`
- `lib/Mcp/ScholiqToolProvider.php::findCourse()`
- `lib/Mcp/ScholiqToolProvider.php::courseSummary()`
- `lib/Mcp/ScholiqToolProvider.php::moduleSummary()`
- `lib/Mcp/ScholiqToolProvider.php::buildDeepLink()`
- `lib/Mcp/ScholiqToolProvider.php::toArray()`
- `lib/Mcp/ScholiqToolProvider.php::extractUuid()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes from the code as it ships
- Draft REQs that fold the 15 methods into 5 distinct observable behaviours (provider identity & catalogue, dispatch, list-courses tool, get-course-details tool, object normalisation)
- Notes sections surface observed-but-suspicious behaviour (e.g. `requireCourseReadAccess()` ends with a tautological `$userId !== ''` check after already returning false on empty — currently lets every authenticated user through; that is the observed behaviour and is left as-is here)
- Mint as a new capability (not `--extend`) because no MCP / AI-companion capability exists in scholiq's specs/ yet — the coverage report categorised it under bucket_2a/`assessment` only because the matcher had nowhere else to put it

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
