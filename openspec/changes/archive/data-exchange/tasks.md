# Tasks — Data Exchange (capability)

> Scope: 2 new schemas (DataMappingProfile, DataExchangeJob), 3 new PHP exceptions (DataExchangeRunHandler + DataExchangeRunGuard + OsoDossierReviewGuard), 2 updated PHP files (AttendanceFlagCreationHandler + AttendanceFlagReportGuard — attendance TODOs fulfilled), manifest pages + 2 custom Vue views, l10n (en+nl). Count: 33 → 35.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `DataMappingProfile` schema per design §2.1 — name, target, direction, sourceSchema, targetSchema, fieldMappings[], validationProfile, active, lifecycle (draft→active→archived), tenant_id; x-openregister-seed (BRON/ROD, OSO, leerplicht); no x-openregister-relations (target/sourceSchema are string refs).
- [x] Add `DataExchangeJob` schema per design §2.2 — direction, target, mappingProfileId, scope (schema/filters/cohortId/period), requestedBy, requestedAt, startedAt, finishedAt, result (counts/validationReport/artefactRef), connectorRunId, errorMessage, originFlagId, lifecycle (queued→pending-parent-review|running→succeeded|failed|partial), tenant_id; x-openregister-lifecycle with DataExchangeRunGuard (run) + OsoDossierReviewGuard (approveDossier); x-openregister-relations (mappingProfile, originFlag); x-openregister-calculations (durationSeconds, successRate); x-openregister-notifications (jobFinished on terminal state).
- [x] Validate JSON (`python3 -c 'import json; json.load(open(...))'`); no duplicate slugs; schema count 33 → 35. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Listener/DataExchangeRunHandler.php` — IEventListener for ObjectTransitionedEvent; filters to data-exchange-job → running; loads mapping profile; queries source objects; applies transforms (bsn-to-pseudonym/date-iso8601/cohort-to-brin/passthrough); calls OpenConnector REST API; records connectorRunId + result; sets lifecycle succeeded/partial/failed. BSN never extracted or transmitted.
- [x] Create `lib/Lifecycle/DataExchangeRunGuard.php` — check() blocks `queued→running` for OSO target; passes for all other targets.
- [x] Create `lib/Lifecycle/OsoDossierReviewGuard.php` — check() verifies actor is in LearnerProfile.parentIds before approveDossier.
- [x] Update `lib/Lifecycle/AttendanceFlagCreationHandler.php` — fulfill TODO: when onCross.dataExchangeTarget is set, call queueDataExchangeJob() to create a DataExchangeJob (queued), set dataExchangeJobId on the flag. Remove _dataExchangeTargetIntent placeholder.
- [x] Update `lib/Lifecycle/AttendanceFlagReportGuard.php` — fulfill TODO: check() now queries linked DataExchangeJob; returns false unless lifecycle=succeeded; passes if no job linked (manual report).
- [x] Register `DataExchangeRunHandler` in `Application.php` for `ObjectTransitionedEvent`.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new/modified files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add DataMappingProfiles / DataMappingProfileDetail pages (index+detail, schema=DataMappingProfile).
- [x] Add DataExchangeJobs / DataExchangeJobDetail pages (index+detail, schema=DataExchangeJob).
- [x] Add RequestExportModal (custom, component=RequestExportModal) and OsoDossierReviewView (custom, component=OsoDossierReviewView) pages.
- [x] Add "Data Exchange" nav menu entry (order=60, route=DataExchangeJobs).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/RequestExportModal.vue` — direction + target + mapping profile + scope picker; OpenConnector delegation notice; OSO pending-parent-review note; submit → POST DataExchangeJob (queued); poll lifecycle/result. Options API + direct fetch; no custom Pinia module.
- [x] Create `src/views/OsoDossierReviewView.vue` — load job + learner data (LearnerProfile/GradeEntry/AttendanceRecord); render dossier read-only; Approve (approveDossier) + Reject (fail) for pending-parent-review state; BSN not shown, ECK iD privacy note. Options API + direct fetch; no custom Pinia module.
- [x] Register both in `src/main.js` via customComponents.
- [x] `npm run lint` 0 errors; `npm run stylelint` clean for new files; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages and the two custom views (plain-English keys, both languages).

## Phase 6: Spec-validation gate

- [x] `node tests/validate-json-strict.js` PASS.
- [x] `node tests/validate-register.js` PASS (slug uniqueness, lifecycle requires → PHP class exists).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.

## Phase 7: OpenSpec change documents

- [x] `openspec/changes/data-exchange/proposal.md` — why, what changes, capabilities.
- [x] `openspec/changes/data-exchange/design.md` — schema tables, PHP algorithm, frontend spec, out of scope.
- [x] `openspec/changes/data-exchange/tasks.md` — this file.
- [x] `openspec/changes/data-exchange/specs/data-exchange/spec.md` — canonical per-app spec.
