---
kind: code
depends_on: []
---

## Why

A Dutch school's `leveringsverplichting` (statutory delivery obligation) requires it to send correct
pupil/enrolment/result data to **DUO BRON/ROD**, and DUO returns per-record **afkeurmeldingen** (rejections
with an error code and message) for anything it refuses. Every NL LAS ships an inline-correction loop for
this: see the rejected record next to the reason, fix the underlying data, resend just that record. Verified
directly against HEAD (2026-07-13): Scholiq has the delivery mechanism but not the correction loop.

- **`DataExchangeJob.result` already carries a per-record `validationReport`, but nothing consumes it.**
  `lib/Settings/scholiq_register.json:9921-9959` declares `result: {recordsProcessed, recordsAccepted,
  recordsRejected, validationReport: array<object>, artefactRef}`. `validationReport`'s `items` schema is
  `{"type": "object"}` — genuinely free-form, no documented per-record shape, no field tying an entry back
  to the Scholiq object that produced it. `lib/Listener/DataExchangeRunHandler.php:263-269` copies
  `$connectorResult['validationReport'] ?? []` straight from OpenConnector's response into `result` and does
  nothing else with it — no loop over entries, no lookup, no created object. Grepping the whole PHP tree for
  `ExchangeRejection|RejectionMapping|afkeurmelding` returns zero hits: the mapping step does not exist.
- **The job lifecycle already has a `partial` state for exactly this situation, but it's a dead end.**
  `lib/Settings/scholiq_register.json:10022-10093` (`x-openregister-lifecycle`) has `succeeded | failed |
  partial` as terminal states reachable from `running`, and `DataExchangeRunHandler.php:276-284` sets
  `partial` precisely when `recordsRejected > 0 && recordsAccepted > 0`. Once there, the only declared
  transition out is `requeue` (`partial|failed → queued`, `:10086-10089`), which re-runs the **entire**
  original `scope` — there is no way to resend only the corrected records, and no worklist surfaces which
  records need fixing before a requeue would even help.
- **The payload sent to OpenConnector carries no correlation identifier.** `buildPayload()`
  (`DataExchangeRunHandler.php:540-618`) only ever writes `$mapping['targetField'] => value` pairs from
  `DataMappingProfile.fieldMappings` (or, in the no-profile path, the raw object with PII stripped) — the
  Scholiq source object's own `id` is never included in what gets sent. Even if OpenConnector faithfully
  echoed back which record it rejected, Scholiq has nothing in the payload to match that echo against a
  specific `LearnerProfile`/`Enrolment`/`FinalGrade`/`AttendanceFlag`. This is the concrete blocker for
  "rejected record → source object" resolution, not a hypothetical gap.
- **Two wave-1 changes just extended the same handler and established the composer/idempotency/guard
  patterns this change reuses.** `verzuim-report-composer` (archived) added `composeLeerplichtDossier` +
  `resolveAttendanceRecords` (`DataExchangeRunHandler.php:645-666`, `:829-868`) and `MunicipalityFeedbackGuard`
  — a role-gated self-loop transition that stamps server-side fields because "this register has no
  declarative field-scoped write-authorization extension" (`lib/Lifecycle/MunicipalityFeedbackGuard.php:9-14`).
  `zorgvraag-swv-tlv-chain` (archived) added `composeSwvDossier` + `routeSupportRequestToSwv`
  (`DataExchangeRunHandler.php:342-390`, `:701-718`) and established the "record, don't adjudicate" posture
  for externally-decided outcomes plus the `admin`/`principal`-only RBAC posture for zorg-adjacent data
  (`SupportRequest`'s `x-openregister-authorization`/`x-property-rbac`, `:7269-7290`). This change is a third,
  narrower extension of the same handler + the same guard/idempotency idioms — not a parallel mechanism.
- **`GradeEntry.sourceKind` is the register's own precedent for "one of several possible source objects."**
  `lib/Settings/scholiq_register.json:5479-5535` models a mark's origin as an enum (`sourceKind`) plus one
  nullable `$ref`-typed id field per enum value (`submissionId`, `assessmentResultId`, `sessionId`,
  `exemptionCaseId`, `fraudCaseId`, `ltiToolPlacementId`), grown one value at a time across three separate
  waves (exam-board-case-handling, lti-tool-placement). This is the established, precedented alternative to a
  polymorphic bare-string `$ref`-less pointer for exactly this "rejection resolves to one of N schemas"
  shape, and this change reuses it rather than inventing a new mechanism (see design.md).

This is a genuine MUST-tier gap: `openspec/specs/data-exchange/spec.md` (`feature_tier: should`) already
promises "see the validation report... know the `leveringsverplichting` is met" as a user story
(`:30`), but "see the report" currently means reading opaque JSON on the job object with no path from a
rejected record to the thing an administrator can actually fix. This change closes that loop without
touching the existing detection/queueing/composition chain those two wave-1 changes already built.

## What Changes

- **`DataExchangeJob`'s OpenConnector payload gains a correlation identifier.** `buildPayload()` stamps
  `_scholiqRecordId` (the source object's own `id`) onto every record it builds, in both the profile-mapped
  and pass-through paths, before delegating to OpenConnector. `result.validationReport`'s expected per-item
  shape is documented as `{recordId, errorCode, errorMessage, field?}` — an extension of the existing
  `OPENCONNECTOR_RUN_PATH` integration-contract assumption (`DataExchangeRunHandler.php:100-107`), to be
  filed as an OpenConnector adapter issue alongside the others the `data-exchange` spec already lists.
- **New `ExchangeRejection` schema.** One row per rejected record: which `DataExchangeJob` it came from,
  DUO's `errorCode` + `errorMessage`, a best-effort `errorCodeRef` into the new error-code catalogue, which
  Scholiq object it maps back to (`sourceKind` enum + one typed `$ref` id field per kind — mirrors
  `GradeEntry.sourceKind`), the offending field(s) when derivable, an urgency signal, and a `status` lifecycle
  (`open → corrected → resubmitted → accepted | open` again on repeat rejection, or `open|corrected →
  waived`).
- **New `RejectionMappingHandler`** (ADR-031 exception, same shape as `DataExchangeRunHandler`): listens for
  `DataExchangeJob` transitions into `succeeded`/`partial`/`failed`. On a job's first pass, walks
  `result.validationReport`, resolves each entry's `recordId` back to the querying `scope.schema` +ID, and
  creates one `ExchangeRejection` per entry (idempotent on `(dataExchangeJobId, recordId)`). On a
  resubmission job (identified by an `ExchangeRejection.resubmittedJobId` pointing at it), updates the
  originating rejection instead of creating a new row: `accepted` if the record no longer appears in the new
  `validationReport`, back to `open` (with the fresh error) if it does.
- **Inline correction worklist.** `ExchangeRejections` index + `ExchangeRejectionDetail` pages (declarative
  manifest, no custom Vue view) — the detail page's existing `related`-widget mechanism
  (`GradeEntryDetail`/`AttendanceFlagDetail` precedent) auto-resolves whichever `sourceKind` `$ref` field is
  set into a deep link to the offending object's own (already-editable) detail page. `lifecycleActions`
  renders `Mark corrected` / `Resubmit` / `Waive` buttons from the declared lifecycle, same as every other
  schema in this register.
- **Per-rejection resubmission, not batched.** `Resubmit` (`corrected → resubmitted`) is guarded by a new
  `RejectionResubmitGuard` (mirrors `MunicipalityFeedbackGuard`'s role-check + server-side-stamp shape) that
  creates one new `DataExchangeJob` scoped to exactly this rejection's source object
  (`scope.filters.id = sourceObjectId` — the existing generic filter mechanism, no schema change needed) and
  stamps `resubmittedJobId` into the transition payload. See design.md for why batching was rejected.
- **Rejection age surfaced, not a fabricated statutory countdown.** `ageDays` is a declared calculation
  (`dateDiff` from `detectedAt` to now, mirroring `AttendanceFlag.daysSinceFlag`,
  `:8648-8657`). Unlike `AttendanceFlag.reportOverdue`'s verified 5-school-day Leerplichtwet art. 21a
  deadline, no fixed statutory day-count for BRON/ROD afkeurmelding correction is verifiable from this repo —
  DUO communicates aanlevering-cyclus deadlines externally, per aanleveringsronde, not as a fixed offset.
  `correctionDeadlineAt` is therefore a plain nullable field an admin (or a future OpenConnector-supplied
  value) sets, not a computed one; `overdue` derives from it declaratively when set.
- **Starter DUO error-code catalogue as reference data.** New `ExchangeErrorCode` schema, seeded with a small
  illustrative starter set (explicitly documented as illustrative, mirroring how `DataMappingProfile`'s own
  `targetSchema` values like `DUO:LeerlingV2` are already free-form illustrative identifiers in this
  register) — the authoritative list belongs to DUO/OpenConnector; Scholiq's catalogue is a local,
  admin-editable, best-effort lookup, not a source of truth.

## Impact

- `openspec/specs/data-exchange/spec.md` — MODIFIED requirement: "Persist DataExchangeJob and
  DataMappingProfile in OpenRegister" (adds the `_scholiqRecordId` correlation contract). ADDED
  requirements: persist `ExchangeRejection`; resolve rejections to their source object; track rejection age;
  inline correction/resubmission worklist; DUO error-code catalogue as reference data.
- `lib/Settings/scholiq_register.json` — new `ExchangeRejection`, `ExchangeErrorCode` schemas;
  `DataExchangeJob.result.validationReport` item-shape documentation only (no type change).
- `lib/Listener/DataExchangeRunHandler.php` — `buildPayload()` gains the `_scholiqRecordId` stamp (both
  code paths); no other existing method touched.
- New `lib/Listener/RejectionMappingHandler.php` (ADR-031 exception, mirrors `DataExchangeRunHandler`).
- New `lib/Lifecycle/RejectionResubmitGuard.php`, `lib/Lifecycle/RejectionWaiveGuard.php` (ADR-031
  exceptions, mirror `MunicipalityFeedbackGuard`/`PupilVoiceGuard`'s role-check + server-side-stamp shape).
- `src/manifest.json` — new `ExchangeRejections` index + `ExchangeRejectionDetail` pages, new
  `ExchangeErrorCodes` index + detail pages; no new custom Vue components.
- Does NOT touch: `DataExchangeRunGuard`, `OsoDossierReviewGuard`, the OSO/SWV dossier composers, or any
  existing `DataExchangeJob` transition other than the ones already declared (`requeue` is untouched — a
  full-scope requeue remains available alongside, not instead of, per-record resubmission).
