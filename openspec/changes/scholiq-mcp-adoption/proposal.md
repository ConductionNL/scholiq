# Proposal: scholiq-mcp-adoption

## Summary

Adopt ADR-063 (hydra #102) in Scholiq: replace the hand-written `ScholiqToolProvider` with OpenRegister's declarative MCP surface. A curated set of **6 of Scholiq's 66 schemas** declares the `x-openregister-mcp` dialect, from which OpenRegister derives `scholiq.{schema}.{verb}` tools automatically. The surface is **read-only** (`search` + `get` only), and every schema carrying learner personal data — grades, attendance, enrolments, learner profiles, BSA decisions, fraud cases — is deliberately left OFF for AVG reasons. The provider class is deleted in the same change, because a hand-written tool takes precedence over a derived tool and would otherwise permanently shadow it.

## Motivation

- **ADR-063 forbids hand-written MCP tool code.** Scholiq currently ships `lib/Mcp/ScholiqToolProvider.php` (2 tools: `scholiq.listCourses`, `scholiq.getCourseDetails`). Both are plain CRUD reads against OpenRegister and are exactly what the derived surface supplies for free.
- **Stale hand-written tools shadow derived ones.** Declaring the dialect without deleting the provider produces an inert dialect: the derived `scholiq.course.search` never wins over the hand-written `scholiq.listCourses`. Dialect + surgery must land together.
- **Scholiq is the fleet's highest-risk MCP app.** 66 schemas, most of them carrying pupil/student personal data (AVG art. 9 special categories in `ExcuseRequest.reasonKind` and `ProctoringSession` behavioural artefacts). A naive "declare everything" adoption would hand an LLM a class roster, a grade book, and an exam item bank with correct answers. Curation is the deliverable, not a formality.
- **The provider's one real security control is undeclared.** `ScholiqToolProvider` hand-enforces "non-admins may only see `lifecycle: published` courses" (#197 / M2). **No Scholiq schema has an `authorization` block**, so deleting the provider without replacing that gate would expose draft and archived courses to every authenticated learner through the derived surface. This change moves that control down into the schema where it belongs.

## Affected Projects

- [ ] Project: scholiq — declare `x-openregister-mcp` on 6 schemas, add `authorization` read rules, delete `ScholiqToolProvider` + its test + its DI registration
- [ ] Project: openregister — **no code change**; consumed read-only as the derivation registry (`SchemaDerivedToolProvider`, `McpAnnotationValidator`)

## Capabilities

- `mcp-tool-surface` — new capability: Scholiq's declarative, curated, read-only agent tool surface
- `ai-companion-tools` — existing capability: its provider requirements are REMOVED (superseded)

## Scope

### In Scope

- `x-openregister-mcp` declaration under `configuration` on 6 schemas: `course`, `lesson`, `programme`, `session`, `assignment`, `regulation`
- Read verbs only (`search`, `get`), with `scope: read` and `readOnlyHint: true` on every declared verb
- `search.filters` lists cross-checked against each schema's real properties
- An `authorization.read` conditional rule on the 5 exposed schemas that have a `draft` lifecycle state, so non-admins see only live content
- Deletion of `lib/Mcp/ScholiqToolProvider.php`, `tests/Unit/Mcp/ScholiqToolProviderTest.php`, and the `mcpProvider` option in `lib/AppInfo/Application.php`
- Removal of the superseded `ai-companion-tools` requirements from the canonical spec

### Out of Scope

- Any `create` / `update` / `delete` verb on any Scholiq schema (see Risks; refused in this change)
- Exposure of any learner-personal-data schema (60 schemas left OFF)
- `#[McpTool]`-attributed service methods and the `IMcpScannableServices` opt-in — **not needed**: both existing tools are derivable CRUD, so no curated tool survives the surgery
- Hermiq-side prompt/agent changes
- The AVG verwerkingsregister entry for agent access (deferred, see Open Questions)

## Approach

1. Add `configuration["x-openregister-mcp"]` to the 6 curated schemas in `lib/Settings/scholiq_register.json` (Scholiq's schemas have no `configuration` object today, so it is introduced).
2. Add `authorization.read` to the 5 of those that have a `draft` lifecycle state: an `$eq`/`$in`-matched conditional rule for `authenticated` plus an unconditional `admin` entry — replicating the provider's #197 gate at the layer that the derived surface actually goes through. `session` is exempt (its lifecycle has no draft state — `scheduled|in-progress|completed|cancelled` are all live states).
3. Delete the provider, its unit test, and its registration; verify the derived catalogue exposes `scholiq.course.search` / `scholiq.course.get` and no hand-written tool remains to shadow them.

## New Dependencies

None. OpenRegister is already a hard dependency; ADR-063's derivation lives entirely inside it.

## Impact

- **Tool ids change.** `scholiq.listCourses` → `scholiq.course.search`; `scholiq.getCourseDetails` → `scholiq.course.get` + `scholiq.lesson.search` (filter `courseId`). Any Hermiq prompt or saved agent that hard-codes the old ids breaks.
- **Tool count grows** from 2 to 12 (6 schemas × 2 read verbs), well inside the ADR-063 budget.
- `lib/Settings/scholiq_register.json` is re-imported; the `authorization` addition changes read visibility for draft/archived objects across the app, not just over MCP.

## Cross-Project Dependencies

- **openregister** ≥ the commit carrying `SchemaDerivedToolProvider` + `McpAnnotationValidator` (present at `origin/development`). No change requested there.
- **hermiq** is the sole agent consumer; it classifies write/destructive tools from the 3-segment verb suffix. A read-only surface needs no Hermiq change.

## Risks

### Risk 1: Deleting the provider drops the draft-course gate

- **Severity**: High
- **Detail**: `ScholiqToolProvider::handleListCourses()` / `handleGetCourseDetails()` hand-enforce that non-admins see only `lifecycle: published` courses. No Scholiq schema has an `authorization` block, so the derived surface would happily return drafts and archived courses to any authenticated learner.
- **Mitigation**: The `authorization.read` conditional rule is a blocking task in this change (Task 2) and MUST land in the same commit as the provider deletion. Verification asserts a non-admin `scholiq.course.search` returns zero draft courses.

### Risk 2: Personal data reaches an LLM through a schema nobody curated

- **Severity**: High
- **Detail**: The majority of the 60 OFF schemas carry learner-identifiable data (`learnerId`, `learnerRef`, `bsnEncrypted`, `birthDate`, grades, attendance). `enabled: true` on any of them makes it agent-readable.
- **Mitigation**: The dialect is opt-in per schema (`enabled` is a required key). Only the 6 curated schemas get a `configuration["x-openregister-mcp"]` block at all; the other 60 have no dialect key and therefore derive no tools. design.md records the OFF list with per-group reasons.

### Risk 3: Exam integrity — item banks with correct answers

- **Severity**: High
- **Detail**: `Item` carries `correctResponse` and `qtiBody`; `Assessment` carries `itemRefs`, `passMark`, `availableFrom`. An agent a student can talk to must never be able to read these.
- **Mitigation**: `item`, `item-bank`, `assessment`, `proctoring-session` are explicitly OFF and named as such in design.md.

### Risk 4: Unreleased assignment instructions leak

- **Severity**: Medium
- **Detail**: `assignment` is ON (it carries no personal data and "when is this due?" is the single most plausible student question), but it has `visibleFrom` / `visibleUntil` and a `draft` lifecycle. A derived `get` has no time-window guard of its own.
- **Mitigation**: The same `authorization.read` conditional rule (Task 2) gates `assignment` on `lifecycle: published`; the `visibleFrom` window is captured as a DEFERRED_QUESTION because expressing "now within [visibleFrom, visibleUntil]" needs two `$lte`/`$gte` clauses whose combination is unverified.

### Risk 5: Hermiq prompts referencing the old tool ids

- **Severity**: Low
- **Mitigation**: Grep hermiq for `scholiq.listCourses` / `scholiq.getCourseDetails` before merge; both are MVP-era ids with no known saved-agent consumers.

## Rollback Strategy

Revert the commit. The register JSON is re-imported on the next repair step, which drops the `x-openregister-mcp` and `authorization` keys and returns the schemas to their pre-change state; restoring `ScholiqToolProvider.php` + the `mcpProvider` option restores the 2 hand-written tools. No data migration, no destructive schema change.

## Open Questions

- Does OpenRegister's `authorization` match engine support **two** operator clauses on distinct properties in one rule (needed for the `visibleFrom` ≤ now ≤ `visibleUntil` assignment window)? `OperatorEvaluator` supports `$eq/$ne/$in/$nin/$exists/$gt/$gte/$lt/$lte`; the combination is unverified.
- Does exposing course/lesson/session metadata to an LLM require an entry in Scholiq's AVG verwerkingsregister (`openspec/specs/avg-verwerkingsregister/`)? The curated set is non-personal by construction, but the *processing* is new.
- Should `cohort` be exposed with a field-level redaction of `learnerIds` / `teacherIds` once OpenRegister's property-level RBAC (`PropertyRbacHandler`) is wired into the derived MCP surface? Today it is OFF because the dialect cannot hide a property.
