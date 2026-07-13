# Tasks: delegate-ooapi-to-opencatalogi

This is a spec-consistency + contract change (size S). There is no new schema, controller, or route to
build — `Course`/`Programme` already have the `lifecycle` + `publish`/`archive` transitions the contract
rides on, `Cohort` already has the offering shape, and `DataExchangeJob.target` is already free text. Tasks
below are spec sync, doc-only metadata, and cross-repo coordination — no feature-build tasks are included.

## 1. Spec sync

- [x] 1.1 Run `opsx-sync` (or manually merge) this change's `specs/course-management/spec.md` MODIFIED
      requirement into `openspec/specs/course-management/spec.md`, replacing the current
      "Publish course catalog via OOAPI 5.0" requirement body and its `#### Scenario: Serve the catalog over
      OOAPI 5.0` block.
- [x] 1.2 While syncing course-management, also correct the stray Acceptance Criteria bullet at
      `openspec/specs/course-management/spec.md:31` ("GIVEN an HE administrator queries `/ooapi/v5/courses`
      … THEN the response complies with OOAPI 5.0") — it predates and contradicts the delta above. Replace
      it with a bullet describing the publish → catalog-sync-job behavior instead of a direct-endpoint claim.
- [x] 1.3 Run `opsx-sync` for this change's `specs/data-exchange/spec.md` MODIFIED requirement into
      `openspec/specs/data-exchange/spec.md`, extending "Delegate wire protocols to OpenConnector" with the
      `ooapi-catalog` target and its new scenario.
- [x] 1.4 Re-run `openspec validate --changes` (or the equivalent full-spec validation) after sync to confirm
      no orphaned scenario or dangling requirement reference remains. `openspec validate --specs --strict`:
      26/26 passed. `openspec validate delegate-ooapi-to-opencatalogi --type change --strict`: valid.

## 2. Publication metadata (doc-only, no migration)

- [x] 2.1 In `lib/Settings/scholiq_register.json`, extend `DataExchangeJob.properties.target.description`'s
      example list to include `ooapi-catalog` alongside `bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`.
      No schema/type change — `target` is already `type: string` with no `enum`, so this is a description
      edit only and needs no register-version bump beyond the normal patch bump for a JSON edit. Done as the
      register document's own `info.version` patch bump 0.6.0 → 0.6.1 (existing convention in this file —
      every content edit gets a one-line changelog entry in `info.description`), not an app-version bump.
- [x] 2.2 Add the OOAPI 5.0 field-mapping reference (Course → course, Programme → program, Cohort →
      offering, RIO `opleidingseenheid`/`aangeboden opleiding` keying — see `design.md`'s table) to
      `docs/ARCHITECTURE.md` so it is the one source openconnector/opencatalogi implementers read instead of
      re-deriving it from the register JSON. Note: this repo's architecture doc is actually
      `docs/Technical/architecture.md` (there is no literal `docs/ARCHITECTURE.md`) — added as new §7 "OOAPI
      5.0 catalog-publication contract (cross-repo)", References renumbered §7→§8.

## 3. Cross-repo coordination (file only — not implemented in this change)

- [x] 3.1 File an issue against `ConductionNL/opencatalogi` for the `ooapi-catalog-publication` public
      endpoint (OOAPI 5.0 `course`/`program`/`offering` resources, faceting, DCAT-AP-style publication
      surface), referencing this proposal and its field-mapping table. **Superseded, not filed**: verified
      against `/home/rubenlinde/scholiq-goal/opencatalogi-dev/openspec/changes/ooapi-catalog-publication/` —
      this change is already merged on the opencatalogi side (its tasks.md shows Tasks 1–6 done, `target:
      ooapi-catalog`, `Course → course`/`Programme → program`/`Cohort → offering` mapping, matching this
      proposal's contract exactly). No issue needed.
- [ ] 3.2 File an issue against `ConductionNL/openconnector` for the `ooapi-catalog` adapter/target consuming
      Scholiq's `DataExchangeJob` (`target: ooapi-catalog`) and the field-mapping contract from `design.md`.
      **NOT done by this apply agent** — filing a real, org-visible GitHub issue is outside an apply agent's
      file-editing scope (same boundary the opencatalogi-side apply agent disclosed for its own Task 7).
      Confirmed still outstanding: opencatalogi's `ooapi-catalog-publication` change explicitly lists the
      OpenConnector `Synchronization` target as not-built-there either, with a ready-to-file issue title/body
      in its tasks.md Task 7. Flagging for the orchestrator/user to file (proposed title: "Add `ooapi-catalog`
      Synchronization target — source: scholiq `DataExchangeJob`, target: opencatalogi's `course`/`program`/
      `offering` schemas; mapping: `openspec/changes/delegate-ooapi-to-opencatalogi/design.md`'s field-mapping
      table").

## 4. Tests

- [x] 4.1 Add/confirm a unit test asserting `Course`/`Programme` `publish` and `archive` transitions still
      pass their existing `x-openregister-lifecycle` guard tests unmodified (no regression — this change
      does not alter transition behavior, only what downstream consumers are told to expect). No dedicated
      test file existed for `CoursePublishGuard`/`ProgrammePublishGuard` at HEAD — added
      `tests/Unit/Lifecycle/CoursePublishGuardTest.php` (4 tests: publish-with-lesson allowed,
      publish-without-lesson blocked, missing-id blocked, tenant-scoped lookup) and
      `tests/Unit/Lifecycle/ProgrammePublishGuardTest.php` (4 tests: publish allowed, no-plan blocked,
      unpublished-plan blocked, zero-required-courses blocked) exercising the guard classes' real logic
      against a mocked `ObjectService` (same pattern as `BsaDecisionGuardTest`). `archive`/`unarchive`
      transitions on both schemas carry no PHP guard (`x-openregister-lifecycle.transitions.archive` has no
      `requires` key) — nothing to regression-test there beyond the register JSON itself being unchanged for
      those two schemas, confirmed by diff (only `DataExchangeJob.target` and the document's `info` block
      were touched).
- [x] 4.2 Add a small assertion (schema description test, or a grep-based lint if the app already has one)
      that `DataExchangeJob.target`'s description string contains `ooapi-catalog`, so the documented
      convention from task 2.1 cannot silently regress. Added
      `tests/Unit/Settings/DataExchangeOoapiCatalogTargetTest.php` (3 tests: target stays a free-text string
      with no `enum`, description contains `ooapi-catalog`, register `info.version` bumped to `0.6.1`).

## 5. Docs

- [x] 5.1 Update `docs/ARCHITECTURE.md`'s standards/roadmap section to move "OOAPI 5.0 catalog publication"
      from an undifferentiated Phase 3 line item to an explicit note that Scholiq's part is the publication
      contract (this change) and the public endpoint/adapter live in opencatalogi/openconnector. This repo
      has no literal `docs/ARCHITECTURE.md` or a dedicated "standards/roadmap" section within
      `docs/Technical/architecture.md` (the closest match) — done together with task 2.2's new §7 "OOAPI 5.0
      catalog-publication contract (cross-repo)", which includes an explicit "who owns what" table
      superseding the old undifferentiated framing (`docs/Features/features.md`'s roadmap table entry
      "Publish course catalog via OOAPI 5.0 | V1" and `docs/intro.md`'s "OOAPI publishing are still roadmap
      items" prose were left as-is — out of scope for this task, which named `docs/ARCHITECTURE.md`
      specifically).
