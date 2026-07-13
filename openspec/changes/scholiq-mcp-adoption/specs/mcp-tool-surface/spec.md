# MCP Tool Surface

Scholiq's agent-facing tool surface, derived by OpenRegister from a per-schema `x-openregister-mcp` declaration (ADR-063). Scholiq writes no MCP tool code: it curates which schemas an agent may read, and OpenRegister derives `scholiq.{schema}.{verb}` tools from that declaration.

## ADDED Requirements

### Requirement: Exactly six curated schemas declare the MCP dialect (REQ-001)
Scholiq MUST declare `x-openregister-mcp` on exactly six schemas — `course`, `lesson`, `programme`, `session`, `assignment`, `regulation` — and MUST NOT declare it on any of the other 60 schemas in the Scholiq register. The declaration MUST live at `components.schemas.<schema>.configuration["x-openregister-mcp"]` (the path `SchemaDerivedToolProvider` reads via `$schema->getConfiguration()`), and MUST carry `enabled: true`. Every other schema MUST have no `x-openregister-mcp` key at all, so that it derives no tool.

#### Scenario: The derived catalogue contains only the curated schemas
- GIVEN the Scholiq register is imported
- WHEN OpenRegister's `SchemaDerivedToolProvider` builds the tool catalogue for app id `scholiq`
- THEN it exposes tools for exactly the schemas `course`, `lesson`, `programme`, `session`, `assignment`, `regulation`
- AND no tool exists for `learner-profile`, `enrolment`, `grade-entry`, `attendance-record`, `submission`, `assessment`, `item`, `item-bank`, `cohort`, `material`, or any other schema

#### Scenario: A schema without the dialect derives nothing
- GIVEN the `grade-entry` schema has no `configuration["x-openregister-mcp"]` key
- WHEN the derived catalogue is built
- THEN no `scholiq.grade-entry.*` tool is registered

### Requirement: The MCP surface is read-only — no write verb is declared (REQ-002)
Every declared verb MUST be `search` or `get`, MUST set `scope: "read"`, and MUST set `readOnlyHint: true`. Scholiq MUST NOT declare `create`, `update`, or `delete` on any schema. Rationale (binding): `course.lifecycle`, `lesson.lifecycle`, and `assignment.lifecycle` are themselves the gate between draft and learner-visible content, so an `update` verb on any exposed schema is a publish verb; the dialect cannot express a lifecycle-scoped precondition, so no write verb may be declared until it can.

#### Scenario: No derived tool mutates a Scholiq object
- GIVEN the derived catalogue for app id `scholiq`
- WHEN every tool in it is inspected
- THEN each tool id ends in `.search` or `.get`
- AND no tool id ends in `.create`, `.update`, or `.delete`
- AND every tool declares `scope: "read"` and `readOnlyHint: true`

### Requirement: No learner personal data and no exam content is exposed (REQ-003)
Scholiq MUST NOT declare the MCP dialect on any schema carrying learner-identifiable data or exam content, at any verb — including `get`. This covers, without limitation: `learner-profile` (holds `bsnEncrypted`, `birthDate`, `parentIds`), `enrolment`, `submission`, `grade-entry`, `final-grade`, `assessment-result`, `competency-attainment`, `attendance-record`, `attendance-flag`, `lesson-completion`, `xapi-statement`, `engagement-score`, `engagement-risk-flag`, `bsa-trajectory`, `bsa-progress-flag`, `bsa-warning`, `bsa-decision`, `fraud-case`, `exemption-case`, `deliberation-record`, `attestation`, `credential`, `external-training-record`, `learning-plan`, `learning-plan-evaluation`, `signature`, `support-request`, `tlv-application`, `grade-notification`, `conference-signup`, `conference-slot`, `conference-report`, `praktijkopleider`, `bpv-placement`, `praktijkovereenkomst`, `pok-signature`, `werkproces-assessment`, `bpv-visit-report`, `teacher-availability`, and `cohort` (holds `learnerIds`/`teacherIds` — a class roster). It covers the AVG art. 9 special-category schemas `excuse-request` (absence reasons are health data about a minor) and `proctoring-session` (behavioural exam-surveillance artefacts). It covers the exam-integrity schemas `item` (holds `correctResponse`), `item-bank`, and `assessment` (holds `itemRefs`, `passMark`). It covers `material`, which has no `lifecycle` property and therefore cannot carry the conditional-read rule of REQ-005, while being reachable from `assignment.briefingMaterialIds` — exposing it would route around the `assignment` gate.

#### Scenario: A learner-data schema derives no tool even for a single-object fetch
- GIVEN a caller asks the agent for one pupil's grade by object id
- WHEN the agent searches its tool catalogue
- THEN no `scholiq.grade-entry.get` tool exists to call
- AND no `scholiq.learner-profile.get` tool exists to call

#### Scenario: Exam item banks are unreachable from the agent surface
- GIVEN a student-facing agent session
- WHEN the tool catalogue is enumerated
- THEN no `scholiq.item.*`, `scholiq.item-bank.*`, or `scholiq.assessment.*` tool is present

### Requirement: Every declared search filter is a real property of its schema (REQ-004)
Every entry in a `search.filters` list MUST be the name of a property that exists in that schema's `properties` block. OpenRegister's `McpAnnotationValidator::validateFilters()` rejects an unknown filter with `mcp-unknown-filter-property` and fails the register import, so an incorrect list is a hard import failure, not a silent degradation. The declared filters MUST be: `course` → `code`, `level`, `language`, `lifecycle`, `mandatoryTraining`, `regulationSlug`; `lesson` → `courseId`, `contentType`, `lifecycle`, `mandatoryTraining`; `programme` → `code`, `level`, `lifecycle`; `session` → `cohortId`, `courseId`, `lessonId`, `lifecycle`; `assignment` → `courseId`, `sessionId`, `cohortId`, `lifecycle`; `regulation` → `slug`, `active`, `audienceScope`, `requiresAnnualRenewal`, `lifecycle`.

#### Scenario: The register imports without a dialect validation error
- GIVEN the six curated schemas declare their `search.filters`
- WHEN the Scholiq register is imported into OpenRegister
- THEN `McpAnnotationValidator::validate()` returns no `mcp-unknown-filter-property` error
- AND no `mcp-unknown-verb`, `mcp-bad-scope`, `mcp-bad-hint`, or `mcp-missing-enabled` error is returned

### Requirement: Draft and archived content is not readable by non-admin callers (REQ-005)
Each exposed schema whose `lifecycle` enum contains a `draft` state MUST carry an `authorization.read` rule that restricts non-admin callers to that schema's live lifecycle values and grants admins unconditional read: `course` → `{"lifecycle": {"$eq": "published"}}`; `lesson` → `{"lifecycle": {"$eq": "published"}}`; `programme` → `{"lifecycle": {"$eq": "published"}}`; `assignment` → `{"lifecycle": {"$in": ["published", "closed"]}}`; `regulation` → `{"lifecycle": {"$eq": "published"}}`. `session` MUST NOT be gated on lifecycle, because its enum (`scheduled|in-progress|completed|cancelled`) contains no draft state and a learner is entitled to see that a class was cancelled. This requirement replaces — and MUST land in the same change as — the hand-written non-admin lifecycle gate in `ScholiqToolProvider` (#197 / M2); no Scholiq schema carries an `authorization` block today, so deleting the provider without this rule would expose every draft and archived object to any authenticated learner through the derived surface.

#### Scenario: A non-admin search returns no draft courses
- GIVEN a draft course and a published course exist
- AND the caller is an authenticated non-admin user
- WHEN `scholiq.course.search` is invoked with no filters
- THEN only the published course is returned
- AND the draft course is absent from the result set

#### Scenario: A non-admin get on a draft course is denied
- GIVEN a course with `lifecycle: draft`
- AND the caller is an authenticated non-admin user
- WHEN `scholiq.course.get` is invoked with that course's id
- THEN OpenRegister's RBAC read check denies the object
- AND the draft course's existence is not disclosed to the caller

#### Scenario: An admin still sees drafts
- GIVEN a course with `lifecycle: draft`
- AND the caller is a Nextcloud admin
- WHEN `scholiq.course.search` is invoked
- THEN the draft course is returned

### Requirement: No hand-written MCP tool code remains in Scholiq (REQ-006)
Scholiq MUST NOT ship any `IMcpToolProvider` implementation, and MUST NOT register an `mcpProvider` alias. `lib/Mcp/ScholiqToolProvider.php`, `tests/Unit/Mcp/ScholiqToolProviderTest.php`, the `'mcpProvider' => ScholiqToolProvider::class` option in `lib/AppInfo/Application.php`, and the now-dead `tests/Stubs/Mcp/IMcpToolProvider.php` stub MUST be deleted. Because a hand-written tool takes precedence over a derived tool, a surviving `scholiq.listCourses` would permanently shadow `scholiq.course.search` and render the entire dialect inert. No `#[McpTool]` attribute and no `IMcpScannableServices` implementation are needed, because both hand-written tools are derivable CRUD and no curated tool survives the migration.

#### Scenario: The derived tools are not shadowed
- GIVEN the Scholiq register declares the dialect
- WHEN the MCP tool catalogue for app id `scholiq` is enumerated
- THEN `scholiq.course.search` and `scholiq.course.get` are present
- AND `scholiq.listCourses` and `scholiq.getCourseDetails` are absent

#### Scenario: The app registers no tool provider
- GIVEN Scholiq is installed and enabled
- WHEN the container is asked for `OCA\OpenRegister\Mcp\IMcpToolProvider::scholiq`
- THEN no service is registered under that alias
