---
kind: code
depends_on: []
---

# Proposal: delegate-ooapi-to-opencatalogi

## Why

Scholiq's specs contradict themselves about who serves OOAPI 5.0, and no OOAPI implementation exists
anywhere at HEAD to break the tie in either direction.

- **`course-management` mandates that Scholiq itself serve the OOAPI endpoint.**
  `openspec/specs/course-management/spec.md:43-49` —

  ```
  ### Requirement: Publish course catalog via OOAPI 5.0
  The system MUST publish the course catalog via OOAPI 5.0 endpoints.

  #### Scenario: Serve the catalog over OOAPI 5.0
  - **GIVEN** a published course catalog
  - **WHEN** an authenticated client requests `/ooapi/v5/courses`
  - **THEN** the system returns an OOAPI 5.0-compliant response including ECTS, language, and level fields
  ```

  The same file's Acceptance Criteria block repeats the claim at line 31: "GIVEN an HE administrator queries
  `/ooapi/v5/courses` … THEN the response complies with OOAPI 5.0."

- **`data-exchange` forbids exactly that.** `openspec/specs/data-exchange/spec.md:55` — "Requirement:
  Delegate wire protocols to OpenConnector": *"Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML,
  **OOAPI**, or SAML/OAuth attribute-release wire protocols. Those MUST be OpenConnector source/target
  configurations…"* — OOAPI is named explicitly in the forbidden list.

- **Neither side is implemented.** Repo-wide grep for `ooapi`/`OOAPI` across `lib/`, `src/`, and
  `appinfo/routes.php` returns zero hits — no controller, no route, no adapter. This is a pure spec defect,
  not a half-built feature; nothing regresses by fixing it.

- **The Spectr research base already resolved the direction — course-management just never caught up.**
  Insight **1031** ("Cross-app leaf: OOAPI 5.0 course-catalog publication belongs in opencatalogi"): *"opencatalogi
  already owns public catalog publication (DCAT-AP experience). The leaf: scholiq course/programme objects
  … are published through opencatalogi with an OOAPI 5.0 profile — scholiq contributes the schema mapping,
  opencatalogi the publication surface, faceting and public API."* Story **9789** (`catalog-publish-ooapi`,
  priority critical) describes the user-facing want ("the course catalog exposed via Open Onderwijs API")
  without prescribing who serves the bytes. `nl_standards` **520** (OOAPI 5.0, SURF): *"opencatalogi should
  own the public catalog endpoint … openconnector can host the OOAPI adapter; scholiq supplies the source
  objects."* `nl_standards` **521** (RIO, DUO/Edustandaard): *"school-structure capability (programmes,
  cohorts) should be able to reference RIO identifiers; openconnector owns the DUO/RIO wire integration,
  scholiq stores RIO-linked school-structure objects."* — RIO's canonical models name `opleidingseenheden`
  (course/programme units) and `aangeboden opleidingen` (offered programmes/runs), which map onto Scholiq's
  existing `Course`/`Programme`/`Cohort` objects.

- **This matches the app's own architecture rules already applied to every other external protocol.**
  `data-exchange`'s "What" section (line 19) already states the pattern for BRON/ROD, OSO, Digikoppeling,
  SURFconext: Scholiq exposes data + a small `DataExchangeJob` queue; the wire protocol lives in
  OpenConnector. `openspec/config.yaml` (`rules.design`) — "ADR-006 (planned): BRON/ROD + UWLR + OSO +
  Edukoppeling adapters MUST live in OpenConnector — never inline HTTP from Scholiq" — is the same rule
  OOAPI should have followed from the start; `course-management`'s requirement was simply never aligned
  with it. `docs/features/course-management` roadmap notes (via `scholiq-research-context.md`) list "OOAPI
  5.0 catalog publication" under Phase 3 — an open, not-yet-built frontier — confirming there is no shipped
  behavior to preserve.

- **Scholiq's `Course` and `Programme` schemas already carry everything the contract needs.**
  `lib/Settings/scholiq_register.json` — `Course` has a `lifecycle` field (`draft → published → archived`,
  `x-openregister-lifecycle` with a `publish` transition guarded by `CoursePublishGuard`, plus a `published`
  notification already wired to `x-openregister-notifications`); `Programme` has the identical
  `draft → published → archived` lifecycle with `ProgrammePublishGuard`; `Cohort` carries `programmeId`,
  `courseId`, `period`, `academicYear`, `teacherIds`, `learnerIds` — the shape of a specific "run" of a
  course, i.e. OOAPI's `offering` resource. None of these needs a new field to become publishable; the gap
  is a *contract* (what gets mapped where, and who syncs it), not new persistence.

- **`data-exchange`'s `DataExchangeJob.target` is already an open string, not a closed enum** —
  `lib/Settings/scholiq_register.json` `DataExchangeJob.properties.target`: `"description": "Named
  OpenConnector connection (e.g. 'bron-rod', 'oso', 'leerplicht', 'surfconext', 'hr')."` — free text with
  examples, so a new `ooapi-catalog` target requires no schema migration.

## What Changes

- **`course-management`**: MODIFY "Publish course catalog via OOAPI 5.0" so Scholiq no longer claims to
  serve `/ooapi/v5/*` itself. Instead it MUST define the **publication contract**:
  - which objects are eligible — `Course`/`Programme` with `lifecycle: published`, and `Cohort` as the
    representative "run" of a course;
  - the **field mapping** toward OOAPI 5.0 resources: `Course → course`, `Programme → program`,
    `Cohort → offering`, keyed to RIO `opleidingseenheid` / `aangeboden opleiding` identifiers where the
    institution has them (nullable — most PO/VO/MBO-corporate tenants won't);
  - the **publication lifecycle**: a `publish` transition on `Course`/`Programme` queues a
    `DataExchangeJob` (`direction: sync`, `target: ooapi-catalog`) so the catalog reflects the change; an
    `archive` transition queues the matching unpublish/removal sync.
  - The public `/ooapi/v5/*` HTTP surface and the OOAPI wire protocol are explicitly OUT of scope for
    Scholiq; they are served by **opencatalogi**, with the mapping/adapter logic hosted in
    **openconnector** — consistent with `data-exchange`'s existing rule.
- **`data-exchange`**: MODIFY "Delegate wire protocols to OpenConnector" to name `ooapi-catalog` as one of
  the concrete OpenConnector targets alongside `bron-rod`/`oso`/`leerplicht`/`surfconext`/`hr`, and add a
  scenario tying course-management's catalog-publication contract to the same `DataExchangeJob` delegation
  mechanism already used for every other external protocol. No behavior in `data-exchange` changes — this
  closes the loop so the two specs read as one consistent story instead of a silent overlap.
- **Cross-repo work (tracked here in prose, not as a `depends_on` — those are other repos' change slugs,
  outside this worktree):** the actual OOAPI 5.0 endpoint implementation is a `ooapi-catalog-publication`
  change in **opencatalogi** (public endpoint, faceting, DCAT-AP-style publication surface applied to
  OOAPI), and the field-mapping adapter is a matching change in **openconnector** (an `ooapi-catalog`
  connector consuming this proposal's mapping contract). Both are out of scope for this scholiq-side change
  and should be filed as issues against those repos referencing this proposal.
- **No new Scholiq schema fields, no new controller, no new route.** `Course`/`Programme` already have the
  `lifecycle` + `publish`/`archive` transitions this contract rides on; `Cohort` already has the offering
  shape; `DataExchangeJob.target` is already free text. The only artifact this change produces beyond the
  spec deltas is documentation (a field-mapping reference) and a doc-only extension of the `target` field's
  example list in the register JSON description — reflected honestly in `tasks.md` as a spec-consistency +
  contract change, not a feature build.

## Impact

- **Specs**: `course-management` (MODIFIED requirement), `data-exchange` (MODIFIED requirement).
- **Code**: none functionally new; `lib/Settings/scholiq_register.json` `DataExchangeJob.target`
  description gets a doc-only example addition (`ooapi-catalog`). No migration — the field is already a
  free-text string.
- **Docs**: adds an OOAPI 5.0 field-mapping reference (Course/Programme/Cohort → course/program/offering,
  RIO keying) for opencatalogi/openconnector implementers to consume.
- **Other repos (not implemented here)**: opencatalogi needs an OOAPI 5.0 public endpoint change; openconnector
  needs an `ooapi-catalog` adapter change. Both referenced as `ooapi-catalog-publication` in prose per the
  gap-report's cross-repo routing (insight 1031).
