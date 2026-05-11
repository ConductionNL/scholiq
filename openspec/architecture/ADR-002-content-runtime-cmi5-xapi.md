---
adr_id: ADR-002
title: Content runtime — cmi5 + xAPI as primary, SCORM 1.2/2004 compatibility shim
status: accepted
category: architecture
date: 2026-05-11
accepted_at: 2026-05-11
deciders:
  - architecture-team
supersedes: []
depends_on: []
applies_to:
  - course-management
  - certification
  - compliance-audit
  - assessment-engine
  - dashboard
---

# ADR-002 — Content runtime: cmi5 + xAPI primary, SCORM compatibility shim

## Status
**accepted** (2026-05-11) — binding before any Phase 1 spec moves from `idea` to `planned`. Implementation reviewers MUST verify conformance with §4 (Decision) on every spec PR. Supersession requires a new ADR plus migration plan for already-emitted xAPI statements.

## Context

Scholiq must launch, track, and report learning content across three very different markets (K-12, HE, corporate/government). The learning-technology ecosystem offers four serious runtime standards:

| Standard | Year | Status | Adoption |
|---|---|---|---|
| **SCORM 1.2** | 2001 | legacy | Universal in existing corporate LMS; required by most legacy content libraries |
| **SCORM 2004 (4th ed.)** | 2009 | legacy | Same; adds sequencing/navigation |
| **xAPI (Tin Can)** | 2013 | active | All modern authoring tools emit; LRS ecosystem mature |
| **cmi5** | 2016 | active | xAPI *profile* defining launch + AU lifecycle; replaces SCORM behaviour |

Brief insight (high impact, architecture category):
> *"Adopt cmi5 + xAPI as primary content launch + tracking instead of SCORM. SCORM 1.2/2004 still dominates installed content, but new authoring (Articulate, iSpring, Storyline, H5P) increasingly emits xAPI. cmi5 gives the LMS launches content with auth context, content reports back via xAPI, and we get a proper LRS instead of frozen SCORM state."*

Brief insight (architecture):
> *"Building an LMS on Nextcloud provides a unique privacy-first positioning. Self-hosted deployment means schools control their data."*

We need a single runtime convention that:
1. **Works for v0.1 compliance-audit** (the wedge — see WEDGE-PLAN.md). Most compliance training is "watch video + click attest"; cmi5 handles it cleanly with `cmi5.launched` + `cmi5.completed` + `cmi5.passed` statements.
2. **Works for full LMS later** (K-12, HE) — adaptive learning, granular activity tracking, mobile, off-line, simulations, AR/VR.
3. **Does not strand the installed base** of SCORM 1.2 and 2004 content (every Dutch corporate compliance library has SCORM packages).
4. **Stores learning events in a queryable, schema-clean form** for analytics, evidence packs, and AI Act audit trails (per ADR-008).

## Decision

Scholiq adopts **cmi5 + xAPI as the primary content launch protocol and learning-event tracking format**. SCORM 1.2 and SCORM 2004 packages are supported via a **compatibility shim** that translates SCORM runtime API calls into xAPI statements at the LMS boundary.

### Concretely

1. **Primary runtime**: cmi5. Every new `Lesson` entity with content_type set to `cmi5` is launched via the cmi5 launch protocol: Scholiq generates a one-time fetch URL bound to a signed Learner Profile token (JWT, RS256), the AU posts its `cmi5.launched` statement to Scholiq's LRS endpoint, and the AU posts subsequent `cmi5.completed` / `cmi5.passed` / `cmi5.failed` / `cmi5.terminated` statements through the same endpoint.

2. **xAPI Learning Record Store (LRS)**: Scholiq runs its own LRS, persisted as an `xApiStatement` schema in OpenRegister. Statements are append-only (per ADR-008). The LRS is xAPI 1.0.3 conformant — every statement has actor (Agent/Group), verb (IRI), object (Activity/Agent/StatementRef/SubStatement), optional context, result, timestamp, authority, stored.

3. **SCORM compatibility shim**: `Lesson` entities with content_type `scorm12` or `scorm2004` are unpacked into the course's nc:files folder (`/Scholiq/<tenant>/<course-id>/scorm/<lesson-id>/`) and served through a Scholiq controller that exposes the SCORM runtime API (`LMSInitialize`, `LMSGetValue`, `LMSSetValue`, `LMSCommit`, `LMSFinish`, `LMSGetLastError`). Each SCORM call translates to one xAPI statement:

   | SCORM call | xAPI statement |
   |---|---|
   | `LMSInitialize()` | verb=`http://adlnet.gov/expapi/verbs/launched` |
   | `LMSSetValue("cmi.core.lesson_status","completed")` | verb=`http://adlnet.gov/expapi/verbs/completed` |
   | `LMSSetValue("cmi.core.lesson_status","passed")` | verb=`http://adlnet.gov/expapi/verbs/passed` |
   | `LMSSetValue("cmi.core.lesson_status","failed")` | verb=`http://adlnet.gov/expapi/verbs/failed` |
   | `LMSSetValue("cmi.core.score.raw", N)` | result.score.raw=N |
   | `LMSSetValue("cmi.suspend_data", …)` | result.extensions for suspend_data |
   | `LMSFinish()` | verb=`http://adlnet.gov/expapi/verbs/terminated` |

   The shim is **one-way**: SCORM in, xAPI out. Scholiq does not author SCORM content; new authoring is cmi5.

4. **Content packaging**: cmi5 packages (.zip with `cmi5.xml` manifest), SCORM 1.2 packages (.zip with `imsmanifest.xml`), and SCORM 2004 packages (.zip with `imsmanifest.xml`) are all uploaded through the same `POST /api/courses/{id}/lessons/import` endpoint. Manifest detection picks the correct unpacker. Common Cartridge (`.imscc`) is supported via a separate importer in Phase 3.

5. **Launch security**: every AU launch carries a signed Learner Profile token (JWT, RS256, exp ≤ 8h) so the AU cannot impersonate other learners; statements posted to the LRS are authenticated by that token. Token rotation per session.

6. **Content storage**: cmi5 + SCORM packages live in `nc:files` at `/Scholiq/<tenant>/<course-id>/`. We do not duplicate content into OpenRegister; only the unpacked path + manifest metadata + xAPI statement stream go to OpenRegister.

7. **What we do NOT do**:
   - We do not embed AICC HACP. Too small an install base to justify.
   - We do not implement LTI 1.1; LTI 1.3 only (lands in `assessment-engine` spec, Phase 3).
   - We do not author new SCORM content from inside Scholiq.

## Consequences

### Positive
- Modern content authors (Articulate Storyline 360, Adobe Captivate 2025, iSpring Suite, H5P, Articulate Rise, Genially) emit xAPI/cmi5 out of the box → zero friction onboarding for corporate content libraries.
- The xAPI statement stream is queryable in OpenRegister → directly feeds compliance evidence packs, AVG retention, AI Act audit trails (per ADR-008), mydash analytics.
- Single learning-event format across all capabilities → grades, exam scores, attestations, lesson completions all become xAPI statements with different verbs. Uniform analytics surface.
- Forward compatibility — xAPI captures off-platform learning (mobile apps, simulations, on-the-job assessment, AR/VR) without architectural changes.
- Distinct from Moodle / ILIAS / Open edX's SCORM-first defaults → marketing differentiation.

### Negative / risks
- LRS implementation work upfront (Scholiq runs its own LRS, not delegating to an external Learning Locker or Yet LRS). Mitigated by: OpenRegister handles persistence + audit + relations; the LRS endpoint is a thin xAPI-conformant POST handler.
- SCORM shim has edge cases (suspend_data resume semantics, sequencing branches in SCORM 2004) that may not translate cleanly. Mitigation: explicit "best-effort SCORM 2004 sequencing" caveat in docs; offer customers a content-conversion path to cmi5 via the importer.
- cmi5 ecosystem smaller than SCORM ecosystem (fewer authoring tools default to cmi5 today). Mitigation: most modern tools at least emit xAPI even when launch is still SCORM-based; cmi5 adoption growing.
- Requires us to be xAPI 1.0.3 conformant — adds test surface. Mitigation: ADL's xAPI Conformance Test Suite is available and free.

## Alternatives considered

- **SCORM-first (default for Moodle/Open edX)**. Rejected because it limits us to LMS-internal tracking and frozen state, and provides no path to off-platform learning. Bad differentiator.
- **xAPI without cmi5 (raw xAPI + custom launch)**. Rejected because every custom launch protocol creates an integration tax on content vendors; cmi5 is the industry-agreed answer.
- **Both AICC and SCORM and xAPI and cmi5**. Rejected as scope creep. AICC HACP usage is rounding error in NL market.
- **Delegate LRS to external Learning Locker / Yet LRS**. Rejected because we want statement-level access for compliance evidence; running our own LRS in OpenRegister keeps the data sovereign and queryable in a single store.

## Implementation notes

- A new OpenRegister schema `xApiStatement` lives under `openregister/schemas/scholiq-xapi-statement.json`. Append-only (no UPDATE). Indexed on actor.id + verb.id + object.id + timestamp + course_id (extension).
- Controller `Scholiq\Controllers\LrsController` exposes `POST /api/lrs/statements`, `GET /api/lrs/statements`, `GET /api/lrs/activities/state`, `GET /api/lrs/activities/profile`, `GET /api/lrs/agents/profile` — the xAPI 1.0.3 endpoints.
- Controller `Scholiq\Controllers\ScormController` exposes the SCORM 1.2 + 2004 runtime API; serves SCORM packages from nc:files.
- Service `Scholiq\Service\Cmi5LaunchService` mints signed launch tokens and resolves AU activity refs.
- Service `Scholiq\Service\ScormToXapiTranslator` does the SCORM-call-to-xAPI-statement translation.
- The compliance-audit spec consumes xAPI statements with verb in {`completed`, `passed`} + activity matching a "mandatory training" tag to compute coverage %.
- All learner identifiers in xAPI statements use opaque internal UUID, never BSN (per ADR-001 deferred — but the convention is set from day one).

## Verification

A capability is considered cmi5-conformant in Scholiq if:
- Uploading the AU's cmi5 package (.zip with cmi5.xml manifest) succeeds.
- Launching the AU produces a valid cmi5.launched statement in the LRS.
- AU completion produces cmi5.completed (and cmi5.passed / cmi5.failed if scored).
- Statements pass ADL's xAPI Conformance Test Suite for the verbs used.

A capability is considered SCORM-shim-conformant if:
- Uploading a SCORM 1.2 or SCORM 2004 package succeeds.
- The SCORM API in-browser returns "true" / valid values for the standard calls.
- Every `cmi.core.lesson_status` write produces an xAPI statement with the corresponding verb.
- `LMSFinish` produces an xAPI `terminated` statement.

## References

- xAPI 1.0.3 specification: https://github.com/adlnet/xAPI-Spec
- cmi5 specification: https://github.com/AICC/CMI-5_Spec_Current
- ADL xAPI Conformance Test Suite: https://github.com/adlnet/lrs-conformance-test-suite
- 1EdTech Caliper Analytics (alternative — emitted alongside xAPI for LMS-to-LMS interop)
- SCORM 1.2 / 2004 specs (legacy reference)
- Brief insight: "Adopt cmi5 + xAPI as primary content launch + tracking instead of SCORM" (architecture, high)
- Companion ADR: ADR-008 (immutable audit trail) — xAPI statements are an instance of the broader audit-trail pattern.
