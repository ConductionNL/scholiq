---
kind: code
depends_on: []
---

## Why

Four `#[NoAdminRequired]` endpoints resolve a caller-supplied ID (or filter) via `ObjectService::find()` /
`ObjectService::findAll()` **without a `tenant_id` scope**, while `ActionAuthService::requireAction()`
(the only guard present) checks an instance-wide role/action matrix stored in `IAppConfig` under
`scholiq.actions` (`lib/Service/ActionAuthService.php:34` — a one-line AppHost subclass of
`GenericActionAuthService`) and has **no concept of tenant membership at all**. The action matrix answers
"is this user's NC group allowed to call `rollover.plan`?", never "does this specific plan/record belong to
this user's tenant?". That second check is each controller's own responsibility — and the team already knows
it: `AuditPackExportController::export()` (`lib/Controller/AuditPackExportController.php:139-160`, tagged
`#184`), `QtiImportController::import()` (`lib/Controller/QtiImportController.php:120-131`, tagged
`wave-12 WF2`), and `XapiCompletionHandler::handle()` (`lib/Lifecycle/XapiCompletionHandler.php:137-141,
171-174, 232-236`, tagged `H1`) all explicitly resolve the caller's `tenant_id` and thread it into every
query filter. Four sibling endpoints were missed:

- **`RolloverController::proposeMapping()`** (`lib/Controller/RolloverController.php:86-116`) — queries
  `cohort` objects filtered only by `academicYear` (line 99-105), no `tenant_id`. Any user holding the
  `rollover.plan` action grant sees every tenant's cohort names/sizes for a matching academic year string.
- **`RolloverController::preview()`** (`lib/Controller/RolloverController.php:133-166`) — fetches the
  `rollover-plan` object by caller-supplied `$planId` alone (line 147: `$this->objectService->find(id:
  $planId, register: 'scholiq', schema: 'rollover-plan')`), with no check that the plan's own `tenant_id`
  matches the caller. A guessed/enumerated `planId` from another tenant lets the caller read that tenant's
  cohort-promotion report, mutate `dryRunReport` on it, and advance its `lifecycle` to `previewed` (line
  159-163) — an unauthorized cross-tenant state write, not just a read.
- **`ExternalTrainingController::issueCredential()`** (`lib/Controller/ExternalTrainingController.php:139-183`)
  — fetches the `external-training-record` object by caller-supplied `$recordId` alone (line 151-155), no
  `tenant_id` check, then **mints and issues a signed Credential** for that record (line 164-183). A user
  holding `external-training.issue-credential` can issue a real credential against another tenant's
  training record.
- **`ExternalTrainingController::learnerCoverage()`** (`lib/Controller/ExternalTrainingController.php:227-253`)
  — calls `trainingService->isLearnerCovered()` / `coveringEvidenceClass()` with a caller-supplied
  `$learnerId` from any tenant, no scoping. The method's own docblock (line 213-217) argues "no
  arbitrary-object exposure beyond a boolean + class", which is true only *within* a tenant; it does not
  address cross-tenant leakage of whether an arbitrary learner elsewhere is regulation-covered.

This is exactly the IDOR shape ADR-005 (security) and the fleet's `hydra-gate-no-admin-idor` gate exist to
catch: a per-object action ("preview this rollover plan", "issue this credential", "check this learner's
coverage") authorized only by a coarse role check, with the per-object tenant guard silently absent.

## What Changes

- **BREAKING (authorization semantics):** `RolloverController::proposeMapping()` adds a `tenant_id` filter
  (resolved from the caller's per-user tenant binding, matching the existing
  `AuditPackExportController`/`QtiImportController` pattern) to the `cohort` query.
- **BREAKING (authorization semantics):** `RolloverController::preview()` resolves the caller's tenant and
  returns HTTP 404 when the fetched `rollover-plan`'s own `tenant_id` does not match, before any read of
  `dryRunReport`/cohort data or write of `lifecycle`.
- **BREAKING (authorization semantics):** `ExternalTrainingController::issueCredential()` resolves the
  caller's tenant and returns HTTP 404 when the fetched `external-training-record`'s `tenant_id` does not
  match, before building or saving the Credential payload.
- **BREAKING (authorization semantics):** `ExternalTrainingController::learnerCoverage()` resolves the
  caller's tenant and returns HTTP 404 (or `{covered: false, evidenceClass: null}`, matching the "no
  arbitrary-object exposure" intent already documented) when the target `learnerId` does not resolve to a
  `LearnerProfile` in the caller's own tenant.
- **No schema change.** `tenant_id` already exists on `cohort`, `rollover-plan`, `external-training-record`,
  and `learner-profile` (used elsewhere in the same file with the `#184`/`H1` tenant-resolution pattern);
  this change only adds the missing filter/comparison in the four call sites above.
- **No new dependency, no new endpoint, no nc-vue change.**

## Capabilities

### Modified Capabilities

- `school-year-rollover`: `proposeMapping` and `preview` MUST scope their OpenRegister reads (and, for
  `preview`, the subsequent `lifecycle` write) to the caller's own tenant.
- `external-training-recording`: `issueCredential` and `learnerCoverage` MUST scope their OpenRegister reads
  (and, for `issueCredential`, the Credential write) to the caller's own tenant.

## Impact

- **`lib/Controller/RolloverController.php`** — `proposeMapping()` (~line 99), `preview()` (~line 147):
  add tenant resolution + filter/guard.
- **`lib/Controller/ExternalTrainingController.php`** — `issueCredential()` (~line 151),
  `learnerCoverage()` (~line 227-253): add tenant resolution + filter/guard.
- **Tests**: add a negative-path unit/integration test per endpoint asserting a cross-tenant ID returns
  404 (or the non-leaking coverage default), mirroring the existing tenant-scoping tests for
  `AuditPackExportController`/`QtiImportController` if present.
