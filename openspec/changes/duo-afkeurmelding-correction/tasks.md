## 1. Schema: correlation stamp + ExchangeRejection + ExchangeErrorCode

- [ ] 1.1 Add `x-openregister-calculations`-free doc-only update to `DataExchangeJob.result.validationReport`
      (`lib/Settings/scholiq_register.json:9942-9950`): extend `description` to document the assumed
      per-item shape `{recordId, errorCode, errorMessage, field?}`. No `type` change.
- [ ] 1.2 Add new `ExchangeRejection` schema (slug `exchange-rejection`) per design.md's Data Model table:
      `dataExchangeJobId` ($ref DataExchangeJob), `errorCode`, `errorMessage`, `errorCodeRef` (nullable $ref
      ExchangeErrorCode), `offendingFields` (array<string>, default []), `sourceKind` (enum learner-profile |
      enrolment | final-grade | attendance-flag | support-request), `learnerProfileId` / `enrolmentId` /
      `finalGradeId` / `attendanceFlagId` / `supportRequestId` (nullable, $ref-typed per name), `rawRecord`
      (nullable object), `status` (enum open | corrected | resubmitted | accepted | waived, default open),
      `detectedAt` (required date-time), `correctedBy`/`correctedAt` (nullable), `resubmittedJobId` (nullable
      $ref DataExchangeJob), `waivedBy`/`waivedAt`/`waiveReason` (nullable), `correctionDeadlineAt` (nullable
      date), `tenant_id` (required). English `title` + `description` on every property.
- [ ] 1.3 Add `x-openregister-lifecycle` to `ExchangeRejection`: `property: status`, `initial: open`,
      transitions `markCorrected` (open → corrected), `resubmit` (corrected → resubmitted, `requires:
      OCA\Scholiq\Lifecycle\RejectionResubmitGuard`), `accept` (resubmitted → accepted), `reopen`
      (resubmitted → open), `waive` (open|corrected → waived, `requires:
      OCA\Scholiq\Lifecycle\RejectionWaiveGuard`).
- [ ] 1.4 Add `x-openregister-calculations.ageDays` (dateDiff detectedAt→now, days, materialise: true) and
      `.overdue` (correctionDeadlineAt != null AND correctionDeadlineAt <= now AND status NOT IN (accepted,
      waived), materialise: true) to `ExchangeRejection`, mirroring `AttendanceFlag.daysSinceFlag`/
      `.reportOverdue` (`:8648-8683`). Confirm the DSL's `if`/`ne` operators null-guard correctly when
      `correctionDeadlineAt` is unset — if not, express the null-guard as an explicit `and` branch instead
      (same fallback approach `verzuim-report-composer` used for its own aggregation, task 1.2 there).
- [ ] 1.5 Add `x-property-rbac.read: anyOf [{role: admin}, {role: principal}]` to `ExchangeRejection`. Add NO
      `x-openregister-authorization.create` block (listener-created only, mirrors `GradeNotification`).
- [ ] 1.6 Add new `ExchangeErrorCode` schema (slug `exchange-error-code`) per design.md's Data Model table:
      `code`, `target` (nullable), `description` ({nl, en}), `category` (nullable), `severity` (enum blocking
      | warning, default blocking), `active` (default true), `tenant_id`. Seed ~6 illustrative starter codes
      per design.md's Data Model note — clearly document in the seed data / schema description that this is
      illustrative starter data, not DUO's authoritative list.

## 2. Backend: correlation stamp + RejectionMappingHandler

- [ ] 2.1 In `DataExchangeRunHandler::buildPayload()` (`lib/Listener/DataExchangeRunHandler.php:540-618`),
      stamp `$record['_scholiqRecordId'] = $object['id'] ?? ($object['uuid'] ?? '')` in BOTH code paths
      (profile-mapped foreach loop and the no-profile/pass-through closure), before the leerplicht/swv
      dossier-composer calls so the stamp survives composition. No other method in this class changes.
- [ ] 2.2 Add `lib/Listener/RejectionMappingHandler.php` (new, ADR-031 exception, mirrors
      `DataExchangeRunHandler`'s single-responsibility shape): `IEventListener` on `ObjectTransitionedEvent`
      filtered to `register=scholiq, schema=data-exchange-job, to IN (succeeded, partial, failed)`.
      - First-pass path: if no `ExchangeRejection` has `resubmittedJobId` = this job's id, walk
        `result.validationReport`; for each entry with a `recordId`, resolve `sourceKind` from the job's
        `scope.schema` (map schema slug → sourceKind enum value), create one `ExchangeRejection` per entry —
        idempotent on `(dataExchangeJobId, recordId)` (query existing rows first, mirroring
        `saveJobFields`'s find-before-write pattern, `DataExchangeRunHandler.php:1033-1062`). Best-effort
        resolve `errorCodeRef` via `ExchangeErrorCode` lookup on `(code, target)`; leave null on no match.
        Copy `offendingFields` from `validationReport[i].field` when present.
      - Resubmission-outcome path: if job.id IS referenced by one or more `ExchangeRejection.resubmittedJobId`
        values, for each such rejection: if its `recordId` still appears in this job's `validationReport`,
        transition it `reopen` (with fresh errorCode/errorMessage stamped via `saveJobFields`-equivalent
        field update, not a new create); if absent, transition it `accept`.
      - Enforce the job's own `tenant_id` on every `ObjectService::findAll` call, mirroring the `#186`
        tenant-forcing pattern already in `DataExchangeRunHandler`.
- [ ] 2.3 Add `lib/Lifecycle/RejectionResubmitGuard.php` (new, mirrors `MunicipalityFeedbackGuard`'s
      role-check + server-side-stamp shape): `check(&$transitionContext)` — verify actor is in
      `admin`/`coordinator` groups; on success, create ONE new `DataExchangeJob` (`direction: export`,
      `target`/`mappingProfileId` copied from the original job referenced by `dataExchangeJobId`, `scope:
      {schema: rejection.sourceKind, filters: {id: rejection.sourceObjectId}}`, `requestedBy: actor`,
      `requestedAt: now`, `lifecycle: queued`) via `ObjectService::saveObject`, then stamp
      `transitionContext['payload']['resubmittedJobId']` to the new job's id (server-side only, never a
      caller-supplied value).
- [ ] 2.4 Add `lib/Lifecycle/RejectionWaiveGuard.php` (new, mirrors `PupilVoiceGuard`'s waived/waiverReason
      enforcement pattern, `lib/Settings/scholiq_register.json:7692-7720` for the shape being mirrored):
      `check(&$transitionContext)` — verify actor is in `admin`/`coordinator` groups AND
      `transitionContext['payload']['waiveReason']` is a non-empty string; on success stamp
      `waivedBy`/`waivedAt` server-side.
- [ ] 2.5 Register `RejectionMappingHandler` in `Application.php` for `ObjectTransitionedEvent` alongside the
      existing `DataExchangeRunHandler` registration.

## 3. Frontend: manifest pages

- [ ] 3.1 Add `ExchangeRejections` index page (`/data-exchange/rejections`, schema `exchange-rejection`).
- [ ] 3.2 Add `ExchangeRejectionDetail` page (`/data-exchange/rejections/:id`): `data` widget (columns: 2),
      `related` widget (resolves `dataExchangeJobId` + whichever `sourceKind` `$ref` field is set — no extra
      config needed, mirrors `GradeEntryDetail`/`AttendanceFlagDetail`), `lifecycleActions.field: status`.
      Sidebar: metadata + audit-trail tab, same as every other detail page in this register.
- [ ] 3.3 Add `ExchangeErrorCodes` index page (`/data-exchange/error-codes`, schema `exchange-error-code`) +
      `ExchangeErrorCodeDetail` page (`data` widget only — reference data, no lifecycle actions needed beyond
      the register's own default create/edit).
- [ ] 3.4 Add nav entries for `ExchangeRejections` and `ExchangeErrorCodes` under the existing "Data Exchange"
      nav menu (alongside `DataExchangeJobs`/`DataMappingProfiles`).
- [ ] 3.5 On `DataExchangeJobDetail` (`src/manifest.json:6675-`), add an `object-list` widget listing this
      job's `ExchangeRejection`s (filter `dataExchangeJobId: @objectId`, `rowRoute: ExchangeRejectionDetail`,
      `viewAllRoute: ExchangeRejections`), mirroring the existing `dmp-jobs` widget pattern on
      `DataMappingProfileDetail` (`:6558-6600`).

## 4. Tests

- [ ] 4.1 `DataExchangeRunHandlerTest::testBuildPayloadStampsCorrelationId` — both profile-mapped and
      pass-through paths carry `_scholiqRecordId` equal to the source object's `id`; confirm the leerplicht/
      swv composers don't strip it.
- [ ] 4.2 `RejectionMappingHandlerTest` — first-pass creation (correct `sourceKind` resolution per
      `scope.schema`), idempotency on redelivery, resubmission-outcome accept/reopen paths, errorCodeRef
      resolution (known code / unknown code fail-open), tenant-scoping.
- [ ] 4.3 `RejectionResubmitGuardTest` — role-gate (authorised/unauthorised), single-job creation with
      correct scope/target/mappingProfileId, server-side `resubmittedJobId` stamping (caller-supplied value
      ignored), mirroring `MunicipalityFeedbackGuardTest`'s coverage shape.
- [ ] 4.4 `RejectionWaiveGuardTest` — role-gate, empty-reason refusal, non-empty-reason success, server-side
      `waivedBy`/`waivedAt` stamping, mirroring `PupilVoiceGuardTest` if it exists (else mirror
      `MunicipalityFeedbackGuardTest`'s structure).
- [ ] 4.5 `ExchangeRejectionRegisterTest` — declared-shape assertions for `ageDays`/`overdue` calculations,
      lifecycle transitions, `x-property-rbac` block, mirroring `VerzuimReportComposerRegisterTest`'s
      established pattern for asserting register-JSON declarations (schema/calc-shape only — calculation
      *execution* runs in OpenRegister core, not Scholiq PHP, same scope boundary as every other
      `x-openregister-calculations` test in this suite).
- [ ] 4.6 `tests/e2e/spec-coverage/data-exchange.spec.ts` (new file) — the two UI-facing scenarios: deep-link
      from a rejection to its source object's detail page; resubmit creates one scoped job and updates the
      rejection's status, driven through the actual manifest-rendered pages (not the API).
- [ ] 4.7 Add `@spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-N` tags to every new/touched
      PHP class + method (`RejectionMappingHandler`, `RejectionResubmitGuard`, `RejectionWaiveGuard`, and the
      touched `DataExchangeRunHandler::buildPayload()`), per this register's existing `@spec` convention.

## 5. Docs + verify

- [ ] 5.1 Check `l10n/en.json`/`l10n/nl.json` (or wherever this app's locale catalogues live) for any new
      user-facing strings introduced by the manifest pages/lifecycle transition labels (`Mark corrected`,
      `Resubmit`, `Waive`); add English-keyed entries + Dutch translations if the manifest's generic
      lifecycle-action rendering requires explicit labels rather than deriving them from the transition name.
- [ ] 5.2 Run `node tests/l10n/check-l10n-parity.js` (or this repo's equivalent parity check) — confirm no new
      missing-key regressions introduced by this change specifically (pre-existing gaps are out of scope,
      per this repo's established convention).
- [ ] 5.3 Run `phpstan analyse` and `phpcs --standard=phpcs.xml` scoped to every new/touched file in `lib/`.
- [ ] 5.4 File an OpenConnector adapter issue documenting the `_scholiqRecordId` → `recordId` echo-back
      contract addition to the `/apps/openconnector/api/sources/{name}/run` response shape, alongside the
      other adapter issues `data-exchange`'s "Delegate wire protocols to OpenConnector" requirement already
      lists.
- [ ] 5.5 `openspec validate duo-afkeurmelding-correction --type change --strict` until valid.
