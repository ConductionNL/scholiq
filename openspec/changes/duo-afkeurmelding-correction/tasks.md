## 1. Schema: correlation stamp + ExchangeRejection + ExchangeErrorCode

- [x] 1.1 Add `x-openregister-calculations`-free doc-only update to `DataExchangeJob.result.validationReport`
      (`lib/Settings/scholiq_register.json:9942-9950`): extend `description` to document the assumed
      per-item shape `{recordId, errorCode, errorMessage, field?}`. No `type` change.
- [x] 1.2 Add new `ExchangeRejection` schema (slug `exchange-rejection`) per design.md's Data Model table:
      `dataExchangeJobId` ($ref DataExchangeJob), `errorCode`, `errorMessage`, `errorCodeRef` (nullable $ref
      ExchangeErrorCode), `offendingFields` (array<string>, default []), `sourceKind` (enum learner-profile |
      enrolment | final-grade | attendance-flag | support-request), `learnerProfileId` / `enrolmentId` /
      `finalGradeId` / `attendanceFlagId` / `supportRequestId` (nullable, $ref-typed per name), `rawRecord`
      (nullable object), `status` (enum open | corrected | resubmitted | accepted | waived, default open),
      `detectedAt` (required date-time), `correctedBy`/`correctedAt` (nullable), `resubmittedJobId` (nullable
      $ref DataExchangeJob), `waivedBy`/`waivedAt`/`waiveReason` (nullable), `correctionDeadlineAt` (nullable
      date), `tenant_id` (required). English `title` + `description` on every property.
- [x] 1.3 Add `x-openregister-lifecycle` to `ExchangeRejection`: `property: status`, `initial: open`,
      transitions `markCorrected` (open → corrected), `resubmit` (corrected → resubmitted, `requires:
      OCA\Scholiq\Lifecycle\RejectionResubmitGuard`), `accept` (resubmitted → accepted), `reopen`
      (resubmitted → open), `waive` (open|corrected → waived, `requires:
      OCA\Scholiq\Lifecycle\RejectionWaiveGuard`).
- [x] 1.4 Add `x-openregister-calculations.ageDays` (dateDiff detectedAt→now, days, materialise: true) and
      `.overdue` (correctionDeadlineAt != null AND correctionDeadlineAt <= now AND status NOT IN (accepted,
      waived), materialise: true) to `ExchangeRejection`, mirroring `AttendanceFlag.daysSinceFlag`/
      `.reportOverdue` (`:8648-8683`). Confirm the DSL's `if`/`ne` operators null-guard correctly when
      `correctionDeadlineAt` is unset — if not, express the null-guard as an explicit `and` branch instead
      (same fallback approach `verzuim-report-composer` used for its own aggregation, task 1.2 there).
      DONE: used the `if(eq(prop,null), false, and[...])` shape, same as `DataExchangeJob.durationSeconds`'
      verified-in-use null guard.
- [x] 1.5 Add `x-property-rbac.read: anyOf [{role: admin}, {role: principal}]` to `ExchangeRejection`. Add NO
      `x-openregister-authorization.create` block (listener-created only, mirrors `GradeNotification`).
- [x] 1.6 Add new `ExchangeErrorCode` schema (slug `exchange-error-code`) per design.md's Data Model table:
      `code`, `target` (nullable), `description` ({nl, en}), `category` (nullable), `severity` (enum blocking
      | warning, default blocking), `active` (default true), `tenant_id`. Seed ~6 illustrative starter codes
      per design.md's Data Model note — clearly document in the seed data / schema description that this is
      illustrative starter data, not DUO's authoritative list. DONE: seeded 6 codes (BRON-101/102/201/205,
      OSO-301, LP-401).

## 2. Backend: correlation stamp + RejectionMappingHandler

- [x] 2.1 In `DataExchangeRunHandler::buildPayload()` (`lib/Listener/DataExchangeRunHandler.php`),
      stamp `$record['_scholiqRecordId'] = $object['id'] ?? ($object['uuid'] ?? '')` in BOTH code paths
      (profile-mapped foreach loop and the no-profile/pass-through closure), before the leerplicht/swv
      dossier-composer calls so the stamp survives composition. No other method in this class changes.
      DONE: also re-asserted the stamp after the fieldMappings loop in the profile-mapped path, so a
      misconfigured `targetField: '_scholiqRecordId'` mapping entry cannot corrupt it — covered by
      `testCorrelationIdSurvivesTargetFieldCollision`.
- [x] 2.2 Add `lib/Listener/RejectionMappingHandler.php` (new, ADR-031 exception, mirrors
      `DataExchangeRunHandler`'s single-responsibility shape): `IEventListener` on `ObjectTransitionedEvent`
      filtered to `register=scholiq, schema=data-exchange-job, to IN (succeeded, partial, failed)`.
      - First-pass path: if no `ExchangeRejection` has `resubmittedJobId` = this job's id, walk
        `result.validationReport`; for each entry with a `recordId`, resolve `sourceKind` from the job's
        `scope.schema` (map schema slug → sourceKind enum value), create one `ExchangeRejection` per entry —
        idempotent on `(dataExchangeJobId, recordId)` (query existing rows first, mirroring
        `saveJobFields`'s find-before-write pattern). Best-effort resolve `errorCodeRef` via
        `ExchangeErrorCode` lookup on `(code, target)`; leave null on no match. Copy `offendingFields` from
        `validationReport[i].field` when present.
      - Resubmission-outcome path: if job.id IS referenced by one or more `ExchangeRejection.resubmittedJobId`
        values, for each such rejection: if its `recordId` still appears in this job's `validationReport`,
        transition it `reopen` (with fresh errorCode/errorMessage stamped via a `saveJobFields`-equivalent
        field update, not a new create); if absent, transition it `accept`.
      - Enforce the job's own `tenant_id` on every `ObjectService::findAll` call, mirroring the `#186`
        tenant-forcing pattern already in `DataExchangeRunHandler`.
      DONE with one deliberate simplification vs the brief's literal wording: `recordId` IS the source
      object's own id (buildPayload() stamps `_scholiqRecordId = sourceObject.id`), so resolving a rejection
      to its source object needs NO re-query of the object — only `sourceKind` (from `scope.schema`) plus
      `recordId` itself. `rawRecord` is left null (nullable field): the documented per-item validationReport
      shape `{recordId, errorCode, errorMessage, field?}` carries no record payload to populate it from —
      fabricating one would violate "never fabricate data"; flagged in the final report.
- [x] 2.3 Add `lib/Lifecycle/RejectionResubmitGuard.php` (new, mirrors `MunicipalityFeedbackGuard`'s
      role-check + server-side-stamp shape): `check(&$transitionContext)` — verify actor is in
      `admin`/`coordinator` groups; on success, create ONE new `DataExchangeJob` (`direction: export`,
      `target`/`mappingProfileId` copied from the original job referenced by `dataExchangeJobId`, `scope:
      {schema: rejection.sourceKind, filters: {id: rejection.sourceObjectId}}`, `requestedBy: actor`,
      `requestedAt: now`, `lifecycle: queued`) via `ObjectService::saveObject`, then stamp
      `transitionContext['payload']['resubmittedJobId']` to the new job's id (server-side only, never a
      caller-supplied value).
- [x] 2.4 Add `lib/Lifecycle/RejectionWaiveGuard.php` (new, mirrors `PupilVoiceGuard`'s waived/waiverReason
      enforcement pattern): `check(&$transitionContext)` — verify actor is in `admin`/`coordinator` groups AND
      `transitionContext['payload']['waiveReason']` is a non-empty string; on success stamp
      `waivedBy`/`waivedAt` server-side.
- [x] 2.5 Register `RejectionMappingHandler` in `Application.php` for `ObjectTransitionedEvent` alongside the
      existing `DataExchangeRunHandler` registration.

## 3. Frontend: manifest pages

- [x] 3.1 Add `ExchangeRejections` index page (`/data-exchange/rejections`, schema `exchange-rejection`).
- [x] 3.2 Add `ExchangeRejectionDetail` page (`/data-exchange/rejections/:id`): `data` widget (columns: 2),
      `related` widget (resolves `dataExchangeJobId` + whichever `sourceKind` `$ref` field is set — no extra
      config needed, mirrors `GradeEntryDetail`/`AttendanceFlagDetail`), `lifecycleActions.field: status`.
      Sidebar: metadata + audit-trail tab, same as every other detail page in this register.
- [x] 3.3 Add `ExchangeErrorCodes` index page (`/data-exchange/error-codes`, schema `exchange-error-code`) +
      `ExchangeErrorCodeDetail` page (`data` widget only — reference data, no lifecycle actions needed beyond
      the register's own default create/edit).
- [x] 3.4 Add nav entries for `ExchangeRejections` and `ExchangeErrorCodes`. DEVIATION FROM BRIEF: verified at
      HEAD there was no existing "Data Exchange" nav menu at all — `DataExchangeJobs`/`DataMappingProfiles`
      were themselves orphaned (reachable only by direct route, zero menu entries; the exact
      "orphaned-capability" defect class this fleet actively gates against). Created a new `GroupDataExchange`
      nav group (admin/principal-gated via `visibleIf`) containing all four pages
      (`DataMappingProfilesMenu`/`DataExchangeJobsMenu`/`ExchangeRejectionsMenu`/`ExchangeErrorCodesMenu`),
      fixing the pre-existing gap alongside adding the two new entries, per this repo's "always fix
      pre-existing issues encountered during a task" convention.
- [x] 3.5 On `DataExchangeJobDetail`, add an `object-list` widget (`dej-rejections`) listing this job's
      `ExchangeRejection`s (filter `dataExchangeJobId: @objectId`, `rowRoute: ExchangeRejectionDetail`,
      `viewAllRoute: ExchangeRejections`), mirroring the existing `dmp-jobs` widget pattern on
      `DataMappingProfileDetail`.

## 4. Tests

- [x] 4.1 `DataExchangeRunHandlerTest::testBuildPayloadStampsCorrelationId*` (5 tests) — both profile-mapped
      and pass-through paths carry `_scholiqRecordId` equal to the source object's `id`; confirmed the
      leerplicht/swv composers don't strip it; confirmed a targetField-name collision doesn't corrupt it.
- [x] 4.2 `RejectionMappingHandlerTest` (13 tests) — first-pass creation (correct `sourceKind` resolution per
      `scope.schema`), idempotency on redelivery, resubmission-outcome accept/reopen paths, errorCodeRef
      resolution (known code / unknown code fail-open), tenant-scoping, unsupported-sourceKind skip,
      empty-report no-op, wrong-schema/non-terminal/non-matching-event ignores.
- [x] 4.3 `RejectionResubmitGuardTest` (8 tests) — role-gate (authorised/unauthorised), single-job creation
      with correct scope/target/mappingProfileId, server-side `resubmittedJobId` stamping (caller-supplied
      value ignored), unresolvable-original-job / save-failure / unsupported-sourceKind denials, mirroring
      `MunicipalityFeedbackGuardTest`'s coverage shape.
- [x] 4.4 `RejectionWaiveGuardTest` (8 tests) — role-gate, empty/whitespace/missing-reason refusal,
      non-empty-reason success, server-side `waivedBy`/`waivedAt` stamping, caller-supplied `waivedBy`
      overwritten, mirroring `MunicipalityFeedbackGuardTest`'s structure (no pre-existing `PupilVoiceGuardTest`
      file was found to mirror instead).
- [x] 4.5 `ExchangeRejectionRegisterTest` (11 tests) — declared-shape assertions for `ageDays`/`overdue`
      calculations, lifecycle transitions, `x-property-rbac` block, `sourceKind` typed-$ref fields,
      `ExchangeErrorCode` seed/defaults, `validationReport` doc update, gate-28 title+description coverage,
      mirroring `VerzuimReportComposerRegisterTest`'s established pattern (schema/calc-shape only —
      calculation *execution* runs in OpenRegister core, not Scholiq PHP).
- [x] 4.6 `tests/e2e/spec-coverage/data-exchange.spec.ts` (new file) — lightweight route-reachability smoke
      coverage (3 tests: ExchangeRejections index, ExchangeErrorCodes index, ExchangeRejectionDetail route),
      mirroring `eportfolio.spec.ts`'s established "declarative pages, no seeded fixtures assumed" precedent.
      DEVIATION FROM BRIEF: `ExchangeRejection` is exclusively listener-created (no generic create-UI path
      exists to seed one), so a fully seeded interactive resubmit-flow pass is deferred to a
      dev-instance-seeded follow-up — the deep-link resolution and resubmit job-creation logic themselves are
      unit-tested by `RejectionMappingHandlerTest`/`RejectionResubmitGuardTest` (task 4.2/4.3). Not
      live-browser-verified in this pass (no running dev instance in this execution context).
- [x] 4.7 Added `@spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-N` tags to every new/touched
      PHP class + method (`RejectionMappingHandler`, `RejectionResubmitGuard`, `RejectionWaiveGuard`, and the
      touched `DataExchangeRunHandler::buildPayload()`), per this register's existing `@spec` convention. NOTE:
      hydra gate-46 (spec-anchor-existence) does not recognise `#task-N` fragments against this (or any other)
      `tasks.md` file's numbered-checklist-item convention (only literal markdown headings resolve) — this is
      a pre-existing, fleet-wide gate/convention mismatch (530 baseline findings across the whole repo before
      this change), not something introduced here; flagged in the final report rather than silently deviating
      from the established `@spec` tag convention every other file in this codebase uses.

## 5. Docs + verify

- [x] 5.1 Checked `l10n/en.json`/`l10n/nl.json` for any new user-facing strings introduced by the manifest
      pages/lifecycle transition labels (`Mark corrected`, `Resubmit`, `Waive`). VERIFIED: no existing
      transition (e.g. `startHandling`, `approveDossier`, `recordMunicipalityFeedback`) has an explicit l10n
      entry either — labels are derived generically from the transition name, not sourced from the catalogue.
      No new keys added; confirmed via task 5.2 that this introduces zero new missing-key entries.
- [x] 5.2 Ran `node tests/l10n/check-l10n-parity.js` — confirmed zero occurrences of any new term this change
      introduces (`Rejection`, `error code`, `Resubmit`, `Waive`, `Exchange`, ...) anywhere in the tool's
      missing-key report; all reported gaps are pre-existing and unrelated (e.g. Leaderboard/engagement
      strings).
- [x] 5.3 Ran `phpstan analyse` (clean, 0 errors) and `phpcs --standard=phpcs.xml` (clean after `phpcbf`
      auto-fixed alignment/spacing + one manual inline-if rewrite) scoped to every new/touched file in `lib/`.
- [ ] 5.4 File an OpenConnector adapter issue documenting the `_scholiqRecordId` → `recordId` echo-back
      contract addition to the `/apps/openconnector/api/sources/{name}/run` response shape, alongside the
      other adapter issues `data-exchange`'s "Delegate wire protocols to OpenConnector" requirement already
      lists. NOT DONE: filing a cross-repo GitHub/Codeberg issue is outside this delegation's scope (no repo
      write/issue-filing tools available in this execution context) — flagged as deferred follow-up work in
      the final report, per this repo's "file issues for deferred work" convention.
- [x] 5.5 `openspec validate duo-afkeurmelding-correction --type change --strict` — valid.
