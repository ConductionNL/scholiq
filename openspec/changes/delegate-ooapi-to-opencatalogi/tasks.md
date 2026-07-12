# Tasks: delegate-ooapi-to-opencatalogi

This is a spec-consistency + contract change (size S). There is no new schema, controller, or route to
build — `Course`/`Programme` already have the `lifecycle` + `publish`/`archive` transitions the contract
rides on, `Cohort` already has the offering shape, and `DataExchangeJob.target` is already free text. Tasks
below are spec sync, doc-only metadata, and cross-repo coordination — no feature-build tasks are included.

## 1. Spec sync

- [ ] 1.1 Run `opsx-sync` (or manually merge) this change's `specs/course-management/spec.md` MODIFIED
      requirement into `openspec/specs/course-management/spec.md`, replacing the current
      "Publish course catalog via OOAPI 5.0" requirement body and its `#### Scenario: Serve the catalog over
      OOAPI 5.0` block.
- [ ] 1.2 While syncing course-management, also correct the stray Acceptance Criteria bullet at
      `openspec/specs/course-management/spec.md:31` ("GIVEN an HE administrator queries `/ooapi/v5/courses`
      … THEN the response complies with OOAPI 5.0") — it predates and contradicts the delta above. Replace
      it with a bullet describing the publish → catalog-sync-job behavior instead of a direct-endpoint claim.
- [ ] 1.3 Run `opsx-sync` for this change's `specs/data-exchange/spec.md` MODIFIED requirement into
      `openspec/specs/data-exchange/spec.md`, extending "Delegate wire protocols to OpenConnector" with the
      `ooapi-catalog` target and its new scenario.
- [ ] 1.4 Re-run `openspec validate --changes` (or the equivalent full-spec validation) after sync to confirm
      no orphaned scenario or dangling requirement reference remains.

## 2. Publication metadata (doc-only, no migration)

- [ ] 2.1 In `lib/Settings/scholiq_register.json`, extend `DataExchangeJob.properties.target.description`'s
      example list to include `ooapi-catalog` alongside `bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`.
      No schema/type change — `target` is already `type: string` with no `enum`, so this is a description
      edit only and needs no register-version bump beyond the normal patch bump for a JSON edit.
- [ ] 2.2 Add the OOAPI 5.0 field-mapping reference (Course → course, Programme → program, Cohort →
      offering, RIO `opleidingseenheid`/`aangeboden opleiding` keying — see `design.md`'s table) to
      `docs/ARCHITECTURE.md` so it is the one source openconnector/opencatalogi implementers read instead of
      re-deriving it from the register JSON.

## 3. Cross-repo coordination (file only — not implemented in this change)

- [ ] 3.1 File an issue against `ConductionNL/opencatalogi` for the `ooapi-catalog-publication` public
      endpoint (OOAPI 5.0 `course`/`program`/`offering` resources, faceting, DCAT-AP-style publication
      surface), referencing this proposal and its field-mapping table.
- [ ] 3.2 File an issue against `ConductionNL/openconnector` for the `ooapi-catalog` adapter/target consuming
      Scholiq's `DataExchangeJob` (`target: ooapi-catalog`) and the field-mapping contract from `design.md`.

## 4. Tests

- [ ] 4.1 Add/confirm a unit test asserting `Course`/`Programme` `publish` and `archive` transitions still
      pass their existing `x-openregister-lifecycle` guard tests unmodified (no regression — this change
      does not alter transition behavior, only what downstream consumers are told to expect).
- [ ] 4.2 Add a small assertion (schema description test, or a grep-based lint if the app already has one)
      that `DataExchangeJob.target`'s description string contains `ooapi-catalog`, so the documented
      convention from task 2.1 cannot silently regress.

## 5. Docs

- [ ] 5.1 Update `docs/ARCHITECTURE.md`'s standards/roadmap section to move "OOAPI 5.0 catalog publication"
      from an undifferentiated Phase 3 line item to an explicit note that Scholiq's part is the publication
      contract (this change) and the public endpoint/adapter live in opencatalogi/openconnector.
