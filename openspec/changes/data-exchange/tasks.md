# Tasks — Data Exchange (capability)

> Scope: 2 new schemas (DataMappingProfile, DataExchangeJob), 3 new PHP exceptions (DataExchangeRunHandler + DataExchangeRunGuard + OsoDossierReviewGuard), 2 updated PHP files (AttendanceFlagCreationHandler + AttendanceFlagReportGuard — attendance TODOs fulfilled), manifest pages + 2 custom Vue views, l10n (en+nl). Count: 33 → 35.

## Phase 0: Deduplication check

- [ ] Search `openspec/specs/` and `openregister/lib/Service/` for overlap with `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`, and shared Vue components. Document findings (even "no overlap found") in `design.md §1.8 Reuse Analysis`.
- [ ] Confirm no existing Scholiq schema (slug `data-exchange-job` or `data-mapping-profile`) already exists in `lib/Settings/scholiq_register.json`. If found, extend rather than add.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `DataMappingProfile` schema per design §2.1 — name, target, direction, sourceSchema, targetSchema, fieldMappings[], validationProfile, active, lifecycle (draft→active→archived), tenant_id; x-openregister-lifecycle (draft→active→archived); no x-openregister-relations (target/sourceSchema are string refs).
- [ ] Add seed DataMappingProfile objects per design §3.1 — BRON/ROD, OSO, leerplicht, SURFconext (draft), HR (draft/inactive). Use `@self` envelope; slugs unique.
- [ ] Add `DataExchangeJob` schema per design §2.2 — direction, target, mappingProfileId, scope (schema/filters/cohortId/period), requestedBy, requestedAt, startedAt, finishedAt, result (counts/validationReport/artefactRef), connectorRunId, errorMessage, originFlagId, lifecycle (queued→pending-parent-review|running→succeeded|failed|partial), tenant_id; x-openregister-lifecycle with DataExchangeRunGuard (run) + OsoDossierReviewGuard (approveDossier); x-openregister-relations (mappingProfile→DataMappingProfile, originFlag→AttendanceFlag); x-openregister-calculations (durationSeconds, successRate); x-openregister-notifications (jobFinished on terminal state, idempotency-keyed).
- [ ] Add seed DataExchangeJob objects per design §3.2 — 5 objects covering partial, pending-parent-review, succeeded (leerplicht), succeeded (hr-sync), succeeded (oso-import). Use `@self` envelope; slugs unique.
- [ ] Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); confirm no duplicate slugs; confirm schema count increments 33 → 35.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Listener/DataExchangeRunHandler.php` — implements `IEventListener` for `ObjectTransitionedEvent`; filters to register=scholiq, schema=data-exchange-job, to=running; records `startedAt`; loads DataMappingProfile (if mappingProfileId set); queries source objects via `ObjectService::findAll(scope)`; applies transforms (bsn-to-pseudonym reads `eckId` only / date-iso8601 / cohort-to-brin / passthrough); POSTs payload to OpenConnector REST API (`OPENCONNECTOR_RUN_PATH` constant); if null/error response saves job as `failed`; otherwise records `connectorRunId`, `result`, `finishedAt`, sets lifecycle to succeeded/partial/failed. `bsnEncrypted` MUST NOT appear in any payload or log.
- [ ] Create `lib/Lifecycle/DataExchangeRunGuard.php` — `check(array &$transitionContext): bool`; returns `false` when `object.target === 'oso'` AND `from === 'queued'`; returns `true` otherwise.
- [ ] Create `lib/Lifecycle/OsoDossierReviewGuard.php` — `check(array &$transitionContext): bool`; reads `actor` from context; resolves `learnerId` from `object.scope.filters.learnerId`; fetches `LearnerProfile`; returns `in_array(actor, parentIds, true)`.
- [ ] Update `lib/Lifecycle/AttendanceFlagCreationHandler.php` — fulfill TODO: when `onCross.dataExchangeTarget` is set, call `queueDataExchangeJob(target, learnerId, windowStart, windowEnd, tenantId)`, save DataExchangeJob (direction: export, lifecycle: queued), set `AttendanceFlag.dataExchangeJobId` to returned UUID. Remove `_dataExchangeTargetIntent` placeholder usage.
- [ ] Update `lib/Lifecycle/AttendanceFlagReportGuard.php` — fulfill TODO: if `flag.dataExchangeJobId === null` return `true`; else fetch DataExchangeJob and return `true` only if `lifecycle === 'succeeded'`.
- [ ] Register `DataExchangeRunHandler` in `Application.php` for `ObjectTransitionedEvent`.
- [ ] Run `./vendor/bin/phpcs lib/` — 0 errors. Run `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` — 0 errors. Run `php -l` on all new/modified files — no syntax errors.

## Phase 3: Manifest pages in `src/manifest.json`

- [ ] Add DataMappingProfiles page (type: index, route: /data-exchange/mapping-profiles, schema=DataMappingProfile).
- [ ] Add DataMappingProfileDetail page (type: detail, route: /data-exchange/mapping-profiles/:id, schema=DataMappingProfile).
- [ ] Add DataExchangeJobs page (type: index, route: /data-exchange/jobs, schema=DataExchangeJob).
- [ ] Add DataExchangeJobDetail page (type: detail, route: /data-exchange/jobs/:id, schema=DataExchangeJob).
- [ ] Add RequestExportModal custom page (type: custom, route: /data-exchange/request, component=RequestExportModal).
- [ ] Add OsoDossierReviewView custom page (type: custom, route: /data-exchange/jobs/:id/oso-review, component=OsoDossierReviewView).
- [ ] Add "Data Exchange" nav menu entry (order=60, route=DataExchangeJobs).
- [ ] Run `node tests/validate-manifest.js` — 0 Ajv errors.

## Phase 4: Frontend Vue + main.js

- [ ] Create `src/views/RequestExportModal.vue` — direction + target (known targets + custom) + mapping profile (filtered by target) + scope (schema + cohortId + period) picker; OpenConnector delegation notice ("Scholiq delegates wire-protocol execution to the named OpenConnector connection"); OSO note about `pending-parent-review`; submit → POST DataExchangeJob (lifecycle: queued); poll lifecycle + result every 3 s until terminal state; Options API + direct fetch; no custom Pinia module.
- [ ] Create `src/views/OsoDossierReviewView.vue` — load DataExchangeJob by route `:id`; load LearnerProfile + GradeEntry[] + AttendanceRecord[] for `scope.filters.learnerId`; render ECK iD (not bsnEncrypted) with privacy note ("BSN wordt niet getoond of verzonden"); grades table + attendance table (read-only); when `pending-parent-review`: Approve (approveDossier transition) + Reject (fail + reason) buttons; read-only in all other states; Options API + direct fetch; no custom Pinia module.
- [ ] Register both components in `src/main.js` via `customComponents`.
- [ ] Run `npm run lint` — 0 errors. Run `npm run stylelint` on new files — clean. Run `npm run build` — succeeds.

## Phase 5: i18n

- [ ] Add new translation keys to `l10n/en.json` for all new pages, nav entry, RequestExportModal texts (including delegation notice), and OsoDossierReviewView texts (including privacy note).
- [ ] Add corresponding Dutch translations to `l10n/nl.json` for the same keys.

## Phase 6: Spec-validation gate

- [ ] Run `node tests/validate-json-strict.js` — passes.
- [ ] Run `node tests/validate-register.js` — passes (slug uniqueness, lifecycle `requires` → PHP class exists).
- [ ] Run `node tests/validate-manifest.js` — passes (0 Ajv errors).

## Phase 7: Verify OpenSpec change documents

- [ ] Confirm `openspec/changes/data-exchange/proposal.md` exists and covers: why, what changes, capabilities (new + updated), out of scope.
- [ ] Confirm `openspec/changes/data-exchange/design.md` exists and covers: architectural decisions (§1.1–1.8), schema tables (§2), seed data (§3), PHP algorithms (§4), frontend spec (§5), out of scope (§6).
- [ ] Confirm `openspec/changes/data-exchange/specs/data-exchange/spec.md` exists with REQ-DEX-001 through REQ-DEX-015 and all acceptance criteria.
- [ ] Confirm `openspec/changes/data-exchange/tasks.md` — this file.
