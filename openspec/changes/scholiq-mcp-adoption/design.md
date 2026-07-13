# Design: scholiq-mcp-adoption

## Context

Scholiq ships `lib/Mcp/ScholiqToolProvider.php` — an `IMcpToolProvider` implementation with 2 hard-coded read tools (`scholiq.listCourses`, `scholiq.getCourseDetails`), registered through `Bootstrap::register(..., ['mcpProvider' => ScholiqToolProvider::class])` in `lib/AppInfo/Application.php:118`, and specced in the canonical `openspec/specs/ai-companion-tools/spec.md` (REQ-001…REQ-005).

ADR-063 (hydra #102) replaces that pattern. OpenRegister now derives `{appId}.{schema}.{verb}` tools from a per-schema `x-openregister-mcp` block, validated by `McpAnnotationValidator` (`VERBS = search|get|create|update|delete`, `SCOPES = read|create|update|delete`, `HINT_KEYS = readOnlyHint|destructiveHint|idempotentHint`) and read by `SchemaDerivedToolProvider` from **`$schema->getConfiguration()['x-openregister-mcp']`** — i.e. the key lives at `components.schemas.<schema>.configuration["x-openregister-mcp"]`, exactly as in the pipelinq exemplar.

Two constraints drive every decision below:

1. **A hand-written tool takes precedence over a derived tool.** Leaving the provider in place makes the dialect inert.
2. **Scholiq holds pupil and student personal data.** 66 schemas; the great majority are learner-identifiable. The MCP surface is read by an LLM on behalf of whoever is chatting — including a student.

## Goals / Non-Goals

**Goals**
- A curated, read-only, derived tool surface over the schemas a human genuinely asks an assistant about.
- No learner personal data, and no exam content, reachable through MCP.
- The provider's one real access-control behaviour preserved — moved into the schema, not silently dropped.
- Zero hand-written MCP tool code left in the app.

**Non-Goals**
- Write access of any kind (see Decision 3).
- Field-level redaction (the dialect cannot express it).
- Changing what the Scholiq UI or REST API exposes.

## Decisions

### Decision 1: Curate 6 of 66 schemas — ON list

The dialect is opt-in per schema (`enabled` is a required key), so a schema without a `configuration["x-openregister-mcp"]` block derives no tools at all. Six schemas get one. All are **catalogue / structural / reference** entities: none of them contains a `learnerId`, `learnerRef`, `ncUserId`, `bsnEncrypted`, `birthDate`, or any grade, attendance or engagement value.

| Schema (slug) | Verbs | `search.filters` (all verified real properties) | One-line justification |
|---|---|---|---|
| `course` | search, get | `code`, `level`, `language`, `lifecycle`, `mandatoryTraining`, `regulationSlug` | The catalogue entity — "what is this course, what level, is it mandatory" is the single most-asked question; no personal data. |
| `lesson` | search, get | `courseId`, `contentType`, `lifecycle`, `mandatoryTraining` | The module structure inside a course ("what's covered in week 3"); already the second half of today's `getCourseDetails`. |
| `programme` | search, get | `code`, `level`, `lifecycle` | "Which courses make up the HBO-V bachelor" — the aggregate a learner or advisor asks about; holds only `courseIds`, no people. |
| `session` | search, get | `cohortId`, `courseId`, `lessonId`, `lifecycle` | The timetable ("when and where is the next class") — carries `startsAt`/`endsAt`/`location` and *no* learner or teacher identifiers. |
| `assignment` | search, get | `courseId`, `sessionId`, `cohortId`, `lifecycle` | "When is it due, what do I have to do" — the assignment *definition*; learner work lives in `submission`, which is OFF. |
| `regulation` | search, get | `slug`, `active`, `audienceScope`, `requiresAnnualRenewal`, `lifecycle` | The corporate/compliance side: "which regulations require annual renewal" — rule metadata, no coverage figures per person. |

Every filter above was cross-checked against the schema's `properties` in `lib/Settings/scholiq_register.json`; `McpAnnotationValidator::validateFilters()` rejects (`mcp-unknown-filter-property`) any filter that is not a real property, so an un-cross-checked list fails the register import outright.

12 derived tools (6 × 2). Well inside ADR-063's 5–15-schema budget, and far below the tool-explosion threshold that degrades LLM accuracy.

**`material` was on this list and was removed during the read.** It is the one candidate that looked obviously safe and is not. `material` has **no `lifecycle` property at all** (verified: `title, kind, fileRef, url, license, lomTags, order, courseId, lessonId, sessionId, tenant_id`), so it cannot carry the conditional-read rule of Decision 5 — there is nothing to gate on. And `assignment.briefingMaterialIds` points at `material` objects, so a `material.search` would return the briefing pack for an assignment that has not been released yet, straight past the gate we are putting on `assignment` itself. A schema that cannot be gated and is reachable from gated content is an exposure hole, not a convenience. OFF. (Lesson content is still reachable: `lesson.contentRef` carries the file path, and `lesson` *is* gated.)

### Decision 2: What is left OFF — 60 schemas, and why

**Group A — learner personal data (AVG). 42 schemas. Hard refusal.**
`learner-profile` (holds `bsnEncrypted`, `birthDate`, `givenName`, `familyName`, `parentIds`, `guardianRefs` — a BSN-bearing identity record), `enrolment`, `lesson-completion`, `xapi-statement` (per-learner behavioural telemetry), `submission`, `assessment-result`, `grade-entry`, `final-grade`, `competency-attainment`, `attendance-record`, `excuse-request`, `attendance-flag`, `engagement-score`, `engagement-risk-flag`, `bsa-trajectory`, `bsa-progress-flag`, `bsa-warning`, `bsa-decision`, `fraud-case`, `exemption-case`, `deliberation-record`, `attestation`, `credential`, `external-training-record`, `learning-plan`, `learning-plan-evaluation`, `signature`, `support-request`, `tlv-application`, `grade-notification`, `conference-signup`, `conference-slot`, `conference-report`, `praktijkopleider`, `bpv-placement`, `praktijkovereenkomst`, `pok-signature`, `werkproces-assessment`, `bpv-visit-report`, `teacher-availability`, `cohort`, `proctoring-session`.

Two of these deserve to be named explicitly, because they are AVG **special categories** (art. 9), not merely personal data:
- `excuse-request` — `reasonKind` + `reason` on an absence request is, in practice, **health data about a minor**.
- `proctoring-session` — `recordedArtefactRefs` + `flags` are behavioural/biometric exam-surveillance artefacts.

**We refuse to expose any of these, at any verb, including `get`.** The task brief allowed "`get`-only at most" for grade/attendance-bearing schemas; we decline even that. A `get` tool is not safer than a `search` tool here — the derived `get` returns the whole object, and OpenRegister RBAC is the only thing between an LLM prompt and a pupil's BSN. An agent that can be talked into fetching one grade record by id is an agent that can be talked into fetching a hundred. There is no question a Scholiq assistant needs to answer that is worth putting a child's `bsnEncrypted` field one prompt-injection away from an LLM context window.

`cohort` is in this group for a less obvious reason: it looks structural, but `cohort.learnerIds` + `cohort.teacherIds` are Nextcloud user IDs — a **class roster**. The dialect has no field-level redaction, so exposing the cohort means exposing the roster. OFF.

**Group B — exam integrity. 4 schemas. Hard refusal.**
`item` (carries `correctResponse` and `qtiBody` — literally the answer key), `item-bank`, `assessment` (carries `itemRefs`, `passMark`, `availableFrom`), and the proctoring pairing above. An LLM surface that a student can query must not be able to read the questions or the answers. This is not an AVG concern; it is an academic-fraud concern, and it is the reason `assessment` is OFF while `assignment` is ON.

**Group C — ungateable. 1 schema.**
`material` — see the note under Decision 1. No `lifecycle`, therefore no conditional-read rule, therefore a bypass around the `assignment` gate via `briefingMaterialIds`.

**Group D — reference data with low ask-value. 10 schemas. Deliberate omission, not a safety call.**
`grade-scale`, `competency-framework`, `competency`, `rubric`, `learning-plan-template`, `curriculum-plan`, `rollover-plan`, `attendance-threshold`, `engagement-risk-threshold`, `conference-round`. These are safe to expose and genuinely non-personal — they are simply not worth 20 more tools. ADR-063 rule 3 is explicit that naive tool explosion costs ~9.5% LLM accuracy and 30k+ tokens. If a real user question turns out to need "what does a 5.5 mean on this scale", `grade-scale` is the first schema to promote.

**Group E — infrastructure / integration internals. 4 schemas.**
`ai-feature`, `lti-tool-placement`, `data-mapping-profile`, `data-exchange-job`. Operator plumbing; nobody asks an assistant about a sync job's mapping profile.

Totals: 42 (A) + 3 exam-only (B: `item`, `item-bank`, `assessment`; `proctoring-session` is counted in A) + 1 (C) + 10 (D) + 4 (E) = 60 OFF, 6 ON, 66 schemas.

### Decision 3: The surface is read-only — every write verb is refused

No `create`, `update` or `delete` verb is declared on any Scholiq schema, and none is proposed for a follow-up until the gap below is closed.

The reason is specific to Scholiq's data model, not a reflexive preference for reads. Every non-personal schema we exposed carries a `lifecycle` enum whose value **is** the gate between "draft, only staff can see it" and "live, every learner sees it":

- `course.lifecycle`: `draft | published | archived`
- `lesson.lifecycle`: `draft | published | retired`
- `assignment.lifecycle`: `draft | published | closed | archived`

An `update` verb on any of these is therefore a **publish verb**. An agent asked to "tidy up the biology course" that flips `lifecycle` to `published` has released unfinished material to a cohort of pupils; an agent that flips it to `archived` has withdrawn a live course mid-term. `assignment` compounds it: `update` there can move `dueAt`, change `maxPoints`, or shift `visibleFrom` — each a direct, visible consequence for a graded student.

The plausible write story ("an AI course-authoring assistant that drafts a lesson for me") is real and worth building. It needs a **lifecycle-scoped write** — a verb that may only create or modify objects while `lifecycle: draft` — and the `x-openregister-mcp` dialect cannot express that today: `scope` and the three hints describe the tool, not a precondition on the object's state. Recorded as a DEFERRED_QUESTION; not shipped as an unconditional `update`.

### Decision 4: Provider surgery — both tools are derivable CRUD, so the class is deleted

Read of `ScholiqToolProvider` at HEAD, method body by method body:

| Hand-written tool | What the body actually does | Classification | Disposition |
|---|---|---|---|
| `scholiq.listCourses` | `validateListCoursesArgs()` → auth gate → `ObjectService::findAll(['register' => 'scholiq', 'schema' => 'course', 'limit' => …, 'filters' => ['lifecycle' => …]])` → allow-list summary | **Derivable CRUD** — a `course` search with a `lifecycle` filter and a result cap. `SchemaDerivedToolProvider` supplies exactly this as `scholiq.course.search`. | **DELETE** |
| `scholiq.getCourseDetails` | `findCourse()` → `ObjectService::find()` with slug/code fallback → `loadCourseModules()` → `findAll(['schema' => 'lesson', 'filters' => ['courseId' => …]])` → sort by `order` | **Derivable CRUD (composite)** — a `course` get plus a `lesson` search filtered on `courseId`. Both derived: `scholiq.course.get` + `scholiq.lesson.search`. The only non-derived parts are cosmetic: the `sources` citation envelope and the client-side sort by `lesson.order`. | **DELETE** |

Neither tool contains business logic worth keeping. There is **no genuine non-CRUD behaviour** in this provider — no aggregation, no external call, no curated multi-step action. Every remaining method (`toArray`, `extractUuid`, `buildDeepLink`, `courseSummary`, `moduleSummary`) is plumbing that the derived surface does for itself.

Consequence: the provider ends with **zero** tools ⇒ **the class is deleted**, per ADR-063 ("if the provider ends up with zero tools, DELETE the class — don't leave an empty seam"). With no curated tool surviving, Scholiq needs **no** `IMcpScannableServices` implementation and **no** `#[McpTool]` attribute anywhere. `tests/Unit/Mcp/ScholiqToolProviderTest.php` and the `'mcpProvider' => ScholiqToolProvider::class` option in `Application.php` go with it. The `tests/Stubs/Mcp/IMcpToolProvider.php` stub and its two bootstrap `require_once` guards also become dead and are removed.

### Decision 5: The provider's draft-course gate moves into the schema — this is the load-bearing decision

`ScholiqToolProvider` is not purely a CRUD wrapper in one respect: it hand-enforces an authorisation rule (#197 / M2) that exists **nowhere else in the app**:

> a non-admin caller may only see `lifecycle: published` courses; a non-admin request for `draft` or `archived` is rejected, and a non-admin `getCourseDetails` on a non-published course returns `not_found` so drafts are not even discoverable.

**No Scholiq schema has an `authorization` block.** Verified against `lib/Settings/scholiq_register.json` at HEAD: zero of 66. So OpenRegister's default read posture applies, and deleting the provider without a replacement would let any authenticated learner read every draft and archived course, lesson and assignment through `scholiq.course.search`. That is a straight security regression executed in the name of an architecture cleanup — precisely the failure mode ADR-063's rule 1 warns about, inverted.

The replacement is the conditional-read rule that OpenCatalogi already uses for exactly this purpose (`{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}`). `OperatorEvaluator` supports `$eq/$ne/$in/$nin/$exists/$gt/$gte/$lt/$lte`, so on each exposed schema that has a **draft** lifecycle state:

```json
"authorization": {
  "read": [
    { "group": "authenticated", "match": { "lifecycle": { "$eq": "published" } } },
    "admin"
  ]
}
```

Per-schema, using each schema's own live-state values (read from the enums in the register, not assumed):

| Schema | `lifecycle` enum | Non-admin read rule |
|---|---|---|
| `course` | `draft, published, archived` | `{"lifecycle": {"$eq": "published"}}` — exactly the provider's #197 gate |
| `lesson` | `draft, published, retired` | `{"lifecycle": {"$eq": "published"}}` |
| `programme` | `draft, published, archived` | `{"lifecycle": {"$eq": "published"}}` |
| `assignment` | `draft, published, closed, archived` | `{"lifecycle": {"$in": ["published", "closed"]}}` — `closed` is past-deadline but still legitimately visible to the learner |
| `regulation` | `draft, published, archived` | `{"lifecycle": {"$eq": "published"}}` |
| `session` | `scheduled, in-progress, completed, cancelled` | **no lifecycle rule** — this enum has no draft state; all four values are live states, and a learner *should* be able to see that a class was cancelled |

This is strictly better than the provider gate: it applies to the REST API and the UI too, not only to MCP.

**Alternative considered and rejected:** keep a thin `#[McpTool]`-annotated `CourseService::searchPublishedCourses()` that re-implements the gate in PHP. Rejected — it reintroduces exactly the hand-written-tool precedence problem ADR-063 exists to remove, and it leaves the REST API ungated. The control belongs in the schema.

## Risks / Trade-offs

- [Provider deletion drops the draft gate] → `authorization.read` (Decision 5) lands in the **same commit**; verification asserts a non-admin `scholiq.course.search` returns zero `draft`/`archived` courses. If Task 2 cannot be made to pass, Task 3 (deletion) does not ship.
- [`assignment` release window is not enforced] → `lifecycle: published` is gated, but `visibleFrom` / `visibleUntil` are not. An assignment that is `published` but not yet `visibleFrom` is readable. Narrower than today's exposure (today the provider does not expose assignments at all), so this is a **new** exposure and it is flagged, not hand-waved. DEFERRED_QUESTION: two-clause match support.
- [Tool ids change] → `scholiq.listCourses` and `scholiq.getCourseDetails` disappear. Grep hermiq before merge.
- [Adding `authorization` changes non-MCP read behaviour] → drafts become invisible to non-admins in the UI/REST too. This is the intended posture (a pupil should not see a draft course) but it is a behaviour change beyond MCP, and the test plan must cover the UI path.
- [59 schemas OFF may frustrate a legitimate teacher question] → accepted. Promotion is cheap (add one `configuration` block); un-leaking a pupil's BSN from an LLM provider's logs is not.

## Migration Plan

1. Add `configuration["x-openregister-mcp"]` + `authorization` to the 7 schemas; `python3 -m json.tool` after every edit.
2. Re-import the register (repair step / seed CLI); `McpAnnotationValidator` rejects the import if any declared filter is not a real property — this is the guard, not an afterthought.
3. Assert the derived catalogue: 14 `scholiq.*.{search,get}` tools present; zero `scholiq.listCourses` / `scholiq.getCourseDetails`.
4. Delete provider + test + registration + stub in the same commit.

Rollback: revert the commit and re-import; the schemas lose both keys and the provider returns.

## Open Questions

- **DEFERRED_QUESTION (assignment visibility window):** can one `authorization.read` match clause combine `visibleFrom: {$lte: $now}` and `visibleUntil: {$gte: $now}`? If not, `assignment` should be reduced to `search`-only with `instructions` unexposed — which the dialect also cannot express, in which case `assignment` comes OFF entirely.
- **DEFERRED_QUESTION (lifecycle-scoped writes):** ADR-063's dialect cannot say "this `update` may only touch objects in `lifecycle: draft`". Until it can, Scholiq declares no write verb. Should this become an OpenRegister issue (a `precondition` key next to `scope`)?
- **DEFERRED_QUESTION (AVG verwerkingsregister):** the curated set is non-personal, but LLM processing of course/timetable data is a new processing purpose. Does `openspec/specs/avg-verwerkingsregister/` need an entry for agent access?
- **DEFERRED_QUESTION (property-level RBAC):** OpenRegister has `PropertyRbacHandler`. If the derived MCP surface honours it, `cohort` (minus `learnerIds`/`teacherIds`) and `grade-scale` become safely exposable. Is it wired into `SchemaDerivedToolProvider`?
