# Design: duo-afkeurmelding-correction

## Architecture Overview

This change adds a second listener alongside the existing `DataExchangeRunHandler`, plus two small
lifecycle guards, plus two new schemas. It touches the existing handler in exactly one place (the payload
correlation stamp). Nothing in the detection/composition/OSO-review chain changes.

```
DataExchangeJob queued ──► running ──► succeeded | partial | failed      (UNCHANGED trigger + composers)
       │                      │
       │            DataExchangeRunHandler.buildPayload()
       │              NEW: stamps _scholiqRecordId = sourceObject.id
       │              onto every record before the OpenConnector POST
       ▼
  NEW: RejectionMappingHandler (listens on to IN {succeeded, partial, failed})
       │
       ├─ First pass (no ExchangeRejection references this job as resubmittedJobId):
       │     for each result.validationReport[i] with a recordId:
       │       resolve recordId → (sourceKind, sourceObjectId) via scope.schema
       │       create ExchangeRejection (open), idempotent on (dataExchangeJobId, recordId)
       │
       └─ Resubmission-outcome pass (job IS referenced by some ExchangeRejection.resubmittedJobId):
             for each such ExchangeRejection:
               still present in this job's validationReport? → back to `open` (fresh error)
               absent?                                        → `accepted`

  Admin worklist (ExchangeRejections index + ExchangeRejectionDetail, fully declarative):
       open ──"Mark corrected"──► corrected ──"Resubmit"──► resubmitted ──(handler above)──► accepted | open
        │                            │
        └──────────"Waive"───────────┴──────────────────────────────────────────────────► waived

  NEW: RejectionResubmitGuard (on `corrected → resubmitted`)
       - role check (admin/coordinator, mirrors MunicipalityFeedbackGuard)
       - creates ONE new DataExchangeJob: same target/mappingProfileId as the original job,
         scope = {schema: rejection.sourceKind, filters: {id: rejection.sourceObjectId}}
       - stamps rejection.resubmittedJobId = new job's id (server-side, never caller-supplied)

  NEW: RejectionWaiveGuard (on `open|corrected → waived`)
       - requires a non-empty waiveReason (mirrors PupilVoiceGuard's waived/waiverReason pattern)
       - stamps waivedBy/waivedAt server-side
```

## Data Model

All additive — no existing schema's `required` array or existing properties change (except
`DataExchangeJob.result.validationReport`'s `description`, which documents an assumed shape without
changing its `type`).

### ExchangeRejection (new schema, slug `exchange-rejection`)

| Field | Type | Notes |
|---|---|---|
| `dataExchangeJobId` | uuid, `$ref: DataExchangeJob`, required | The job whose `validationReport` produced this row. |
| `errorCode` | string, required | DUO's afkeurcode, verbatim from `validationReport[i].errorCode`. |
| `errorMessage` | string, required | DUO's message text, verbatim. |
| `errorCodeRef` | uuid, nullable, `$ref: ExchangeErrorCode` | Best-effort match against the local catalogue by `(code, target)`; null when the code isn't catalogued yet — never blocks rejection creation. |
| `offendingFields` | array\<string\>, default `[]` | From `validationReport[i].field` when OpenConnector supplies it; empty when not derivable. |
| `sourceKind` | enum `learner-profile \| enrolment \| final-grade \| attendance-flag \| support-request`, required | Mirrors `GradeEntry.sourceKind` (`:5479-5486`) — closed set matching the schemas every currently-seeded `DataMappingProfile.sourceSchema` value can name (`learner-profile`, `attendance-flag`, `support-request`) plus `enrolment`/`final-grade` (named by this change's brief and by `data-exchange`'s own Purpose section as BRON/ROD data, even though no `DataMappingProfile` seed exports them yet). |
| `learnerProfileId` / `enrolmentId` / `finalGradeId` / `attendanceFlagId` / `supportRequestId` | uuid, nullable, `$ref`-typed per name, one per `sourceKind` value | Same "one nullable typed field per enum value" shape as `GradeEntry.submissionId`/`assessmentResultId`/etc. (`:5490-5535`). Exactly one is set, matching `sourceKind`. |
| `rawRecord` | object, nullable | The mapped payload record OpenConnector rejected (post-`buildPayload`, pre-send) — kept for admin context without re-deriving it. |
| `status` | enum `open \| corrected \| resubmitted \| accepted \| waived`, default `open`, lifecycle-managed | See lifecycle below. |
| `detectedAt` | date-time, required | Stamped by `RejectionMappingHandler` at creation. |
| `correctedBy` / `correctedAt` | string/date-time, nullable | Stamped server-side by the `markCorrected` transition (whichever actor performs it). |
| `resubmittedJobId` | uuid, nullable, `$ref: DataExchangeJob` | The single-record job `RejectionResubmitGuard` created. Stamped server-side, never caller-supplied. |
| `waivedBy` / `waivedAt` / `waiveReason` | string/date-time/string, nullable | `waiveReason` required non-empty when `status=waived`, enforced by `RejectionWaiveGuard` (mirrors `DeliberationRecord.pupilVoice.waived`/`waiverReason`, `:7692-7720`). |
| `correctionDeadlineAt` | date, nullable | Externally-supplied (admin-entered, or a future OpenConnector-carried aanlevering-cyclus deadline). NOT computed — see Decisions. |
| `tenant_id` | uuid, required | Standard tenant isolation field, present on every schema in this register. |

`x-openregister-calculations.ageDays`: `dateDiff(detectedAt, now, days)`, `materialise: true` — mirrors
`AttendanceFlag.daysSinceFlag` (`:8648-8657`).
`x-openregister-calculations.overdue`: `correctionDeadlineAt != null AND correctionDeadlineAt <= now AND
status NOT IN (accepted, waived)`, `materialise: true` — same `and`/`ne`/`gte`/`prop` DSL vocabulary already
verified in use for `AttendanceFlag.reportOverdue` (`:8656-8683`); null-safe because the `if` operator
(verified present in the DSL) short-circuits when `correctionDeadlineAt` is null.
`x-property-rbac.read`: `anyOf [{role: admin}, {role: principal}]` — same tightest-available posture as
`SupportRequest`/`TlvApplication` (`:7269-7290`, `:7436-7450`); no dedicated data-exchange-coordinator role
exists in this register (documented gap, same as those two schemas). No `x-openregister-authorization.create`
block — `ExchangeRejection` is exclusively listener-created, same as `GradeNotification`
(`:10149-`), never created through the generic object-create UI.

### ExchangeErrorCode (new schema, slug `exchange-error-code`)

| Field | Type | Notes |
|---|---|---|
| `code` | string, required | The afkeurcode, e.g. as DUO issues it. |
| `target` | string, nullable | Which `DataExchangeJob.target` this code applies to (`bron-rod`, `oso`, ...); null = generic/unknown target. |
| `description` | object `{nl, en}`, required | Human-readable explanation, same bilingual-object shape `x-openregister-notifications.subject` already uses throughout this register. |
| `category` | string, nullable | Free-text grouping (e.g. "identiteit", "inschrijving"). |
| `severity` | enum `blocking \| warning`, default `blocking` | Whether DUO's own semantics treat this as a hard rejection or an accepted-with-warning. |
| `active` | boolean, default `true` | Deactivate stale codes without deleting history. |
| `tenant_id` | uuid, required | Tenant isolation. |

Seeded with ~6 illustrative starter codes covering the categories `data-exchange`'s own Purpose section names
(identity/BSN format, enrolment overlap, missing prior-education code, missing birth date) — explicitly
documented in the seed's own admin-facing description as illustrative starter data, mirroring how
`DataMappingProfile.targetSchema`'s `DUO:LeerlingV2`/`OSO:TransferDossier` values are already free-form,
non-authoritative identifiers accepted elsewhere in this register. **The authoritative code list is DUO's,
surfaced to Scholiq via OpenConnector** — an OpenConnector adapter issue (alongside the BRON/OSO/leerplicht
ones the `data-exchange` spec's "Delegate wire protocols to OpenConnector" requirement already lists) should
carry catalogue updates; this schema is deliberately a local cache/lookup, not a claim of authority.

### DataExchangeJob (modified, doc-only + one behavioural addition)

- `result.validationReport`'s `description` documents the assumed per-item shape: `{recordId, errorCode,
  errorMessage, field?}`. `type` stays `array<object>` — unchanged, additive documentation only, same as
  `DataExchangeJob.target`'s `ooapi-catalog` example was added doc-only in `delegate-ooapi-to-opencatalogi`
  (register description `v0.6.1`).
- No schema field is added to `DataExchangeJob` itself for resubmission scoping — a resubmission job reuses
  the existing `scope.filters` mechanism (`{id: sourceObjectId}`), already exercised identically by
  `loadMappingProfile`/`saveJobFields`/`resolveLearnerWhitelist` (all `filters: ['id' => X]`,
  `DataExchangeRunHandler.php:401-422`, `:1035-1062`, `:732-770`). No new OR filter capability is assumed.

## Decisions

### `sourceKind` enum + per-kind typed `$ref`, not a polymorphic bare-string pointer

**Chosen**: `sourceKind` enum + `learnerProfileId`/`enrolmentId`/`finalGradeId`/`attendanceFlagId`/
`supportRequestId`, exactly mirroring `GradeEntry.sourceKind` (`:5479-5535`).
**Rejected**: a single `sourceSchema` (free string, matching `DataMappingProfile.sourceSchema`'s own
"any Scholiq schema can be a source" convention, `:9541-9549`) + a bare `sourceObjectId` with no `$ref`.
That shape is genuinely more open (any schema, no register-file change needed to add a new source), and it's
also an established pattern in this register — but it cannot be resolved into a clickable deep link by the
existing declarative `related`-widget mechanism, which resolves *declared* `$ref` fields
(`GradeEntryDetail`/`AttendanceFlagDetail`/`DataMappingProfileDetail` all use `"type": "related"` with no
further config, per the "kind-agnostic slot resolver" already in this codebase). Choosing the open shape
would force a new custom Vue component just to compute a route from a runtime schema-name string — a
declarative-first violation for a problem the codebase has already solved twice (`GradeEntry.sourceKind`
grew from 3 to 6 values across three separate waves without becoming unwieldy). The bounded set here (5
values) is smaller than `GradeEntry`'s and maps exactly onto the schemas the brief and the `data-exchange`
spec's own Purpose section name. **Cost**: adding a sixth exportable source schema later needs a schema
change (new enum value + new `$ref` field) rather than being automatically supported — judged acceptable
given the precedent already normalises exactly this kind of incremental growth.

### Correlation via a stamped `_scholiqRecordId`, not inferred from field values

**Chosen**: `buildPayload()` always writes `_scholiqRecordId = sourceObject.id` into every record, regardless
of `fieldMappings` content, and the design assumes OpenConnector echoes it back as `recordId` on any
rejected item.
**Rejected**: inferring which source object a rejection belongs to by matching DUO's error payload against
already-mapped field values (e.g. matching on `eckId` or `birthDate`). Verified at HEAD:
`bsn-to-pseudonym`'s `eckId` output is the only field guaranteed present for `bron-rod`/`oso` (the two
`MANDATORY_PROFILE_TARGETS` requiring a profile, `DataExchangeRunHandler.php:520`), but nothing guarantees
`eckId` is unique-and-stable-enough to reverse-match against an arbitrary DUO error payload shape that
Scholiq doesn't control, and the `leerplicht`/`swv` composers ship dossiers, not flat mapped fields, at all —
there is no single field an inference rule could rely on across every target. An explicit correlation id is
the only approach that works uniformly across all five targets and both `buildPayload()` code paths.
**Cost**: this is a new assumption about OpenConnector's contract (ASSUMPTION, unverified — OpenConnector's
actual `/sources/{name}/run` response is not in this repo, same caveat already attached to the whole
`OPENCONNECTOR_RUN_PATH` contract). Flagged for the same follow-up OpenConnector adapter issue the
`data-exchange` spec already asks for.

### Per-rejection resubmission, not a multi-select batch action

**Chosen**: `Resubmit` on ONE `ExchangeRejection` creates ONE new single-record `DataExchangeJob`
(`scope.filters.id = sourceObjectId`).
**Rejected**: a bulk "resubmit all corrected records for this target" action creating one batched job.
Batching would need either (a) a new `scope.recordIds: uuid[]` field plus an unverified bulk-`in` filter on
`ObjectService::findAll` (no precedent for an array/`in` filter operator anywhere in this register — every
existing `filters` example is scalar equality), or (b) a new custom Vue multi-select view with its own
selection state and a bespoke create-then-link orchestration. Every existing "one transition auto-creates one
sibling object" precedent in this codebase (`AttendanceFlagCreationHandler` queuing one `DataExchangeJob`,
`SupportRequestSubmitHandler` queuing one `DataExchangeJob`) is 1:1, not 1:N. Per-rejection resubmission
keeps the mechanism inside a single lifecycle-guard side effect and reuses the existing generic
`lifecycleActions` button UI with zero new frontend code, at the cost of one small `DataExchangeJob` per
corrected record instead of one batched job per target. **Not revisited now**: if a school routinely
corrects dozens of records per aanlevering-cyclus, a later `ExchangeRejectionBatchResubmitModal` custom view
could add `scope.recordIds` without changing `ExchangeRejection`'s shape — deferred, not designed against.

### `correctionDeadlineAt` is an input, not a computed statutory countdown

**Chosen**: a plain nullable field, admin-set (or, later, OpenConnector-supplied from DUO's own aanlevering-
cyclus deadline).
**Rejected**: a fixed-offset declared calculation, the same shape as `AttendanceFlag.reportDeadlineAt`
(`windowEnd + 7 days`, `:8721-8748`). That field has a verified statutory anchor (Leerplichtwet art. 21a's
5-school-day rule, already coded and cited in `verzuim-report-composer`). No equivalent fixed day-count for
BRON/ROD afkeurmelding correction is verifiable anywhere in this repo or this spec — DUO's actual deadlines
are tied to aanlevering-cyclus peildata that vary by round and are communicated externally, not derivable
from `detectedAt + N`. Fabricating an N here would repeat the exact anti-pattern `reportDeadlineAt`'s own
description already warns against ("informational approximation... not the authoritative signal"), but with
no verified anchor at all to approximate from. `ageDays` (calculated, always available) is the honest
always-on urgency signal; `correctionDeadlineAt`/`overdue` activate only once someone (or OpenConnector, in a
future wave) actually supplies a real deadline.

### Why this is Scholiq's job, not OpenConnector's

`data-exchange`'s own Purpose section is explicit about the boundary: OpenConnector owns "the actual wire
protocols" (Edukoppeling, StUF, OSO XML, SAML/OAuth); Scholiq owns exposing its data, holding the job queue,
and the audit trail (`openspec/specs/data-exchange/spec.md:19`). The correction loop this change adds is
squarely on Scholiq's side of that line for three concrete reasons, not just a restatement of the general
principle:

1. **Resolving a rejection to a source object requires Scholiq's own schema knowledge.** Only Scholiq knows
   that `target: bron-rod` with `scope.schema: learner-profile` means a rejected record is a `LearnerProfile`,
   or that `target: leerplicht` means it's the `AttendanceFlag` the composer built the dossier from.
   OpenConnector is a generic protocol adapter with no notion of `LearnerProfile`/`Enrolment`/`FinalGrade`/
   `AttendanceFlag` — it can echo a correlation id back, but it cannot know what that id *is* in domain
   terms.
2. **"Fix it" means editing a Scholiq object through Scholiq's own RBAC and UI.** The deep link this change
   adds lands on `LearnerProfileDetail`/`EnrolmentDetail`/`FinalGradeDetail`/`AttendanceFlagDetail` — pages
   that already enforce this register's own field-level RBAC and lifecycle guards. OpenConnector has no
   surface for that; building one there would duplicate every access-control decision Scholiq already makes
   about its own data.
3. **The correction workflow (worklist, mark-corrected, resubmit) is a Scholiq business process, not a wire
   concern.** `RejectionResubmitGuard` creating a new `DataExchangeJob` is Scholiq orchestrating its own job
   queue — the same category of action `AttendanceFlagCreationHandler`/`SupportRequestSubmitHandler` already
   perform, both accepted ADR-031 exceptions precisely because they're single-responsibility orchestration
   of Scholiq's own object graph, not protocol implementation. OpenConnector's role stays exactly where it
   already is: execute the run and, with this change's one addition, echo back which record it rejected.

## Security Considerations

- **`ExchangeRejection` read is admin/principal-only** (`x-property-rbac`), the same tightest-available
  posture already applied to `SupportRequest`/`TlvApplication` zorg-adjacent data — DUO afkeurmeldingen
  routinely quote learner PII (name, birth date, BSN-derived pseudonym) back in `errorMessage`/`rawRecord`.
- **`rawRecord` never contains `bsnEncrypted`/`bsnHash`/`email`** — it's the *already-PII-stripped*
  `buildPayload()` output (the same `unset()` calls that already run before every existing send,
  `DataExchangeRunHandler.php:554`, `:595`), not a fresh dump of the source object. No new PII exposure
  surface is created.
- **`resubmittedJobId`, `correctedBy`/`correctedAt`, `waivedBy`/`waivedAt` are always server-stamped**, never
  caller-supplied — same convention `MunicipalityFeedbackGuard` and `PupilVoiceGuard`/`FraudCaseDecisionGuard`
  already establish for compliance-sensitive attribution fields in this register.
- **`RejectionResubmitGuard`/`RejectionWaiveGuard` role-gate on `admin`/`coordinator`** (the same
  `AUTHORISED_GROUPS` shape as `MunicipalityFeedbackGuard.php:73-77`) since, as documented there and in
  `verzuim-report-composer`'s design.md, this register has no declarative field-scoped write-authorization
  extension — a guard on a transition is the only proven mechanism.
- **No new sensitive-data cross-tenant exposure**: `RejectionMappingHandler` resolves `recordId` only within
  the originating job's own `tenant_id` (mirrors the `#186` tenant-forcing already applied to every
  `ObjectService::findAll` call in `DataExchangeRunHandler`, e.g. `:450-451`) — a rejection can never be
  created against a source object outside the job's own tenant.
- **`ExchangeErrorCode` is not itself sensitive** — no PII, purely reference data — but its `active`/seed
  content should be reviewable by an admin, same posture as `DataMappingProfile`'s `active`/`lifecycle`
  fields (create/update left ungated beyond the register's own default object-level RBAC, consistent with
  `DataMappingProfile` having no `x-openregister-authorization` block either).

## Trade-offs

- **Per-rejection (not batched) resubmission** — simpler, fully declarative, zero new frontend code; costs
  one small job per corrected record instead of one batch job per target. Acceptable given DUO/BRON
  correction cycles are inherently per-afkeurmelding already (see Decisions).
- **`sourceKind` as a bounded 5-value enum, not an open polymorphic pointer** — gets native deep-linking for
  free via the existing `related` widget; costs a schema change to add a 6th source schema later (matches
  `GradeEntry.sourceKind`'s own precedented growth pattern).
- **`correctionDeadlineAt` as an unverified external input rather than a computed field** — honest given no
  statutory day-count is verifiable from this repo, but means `overdue`/urgency is inert until someone
  actually sets a deadline; revisit if OpenConnector's BRON/ROD adapter later starts returning a per-round
  deadline that could populate it automatically.
- **`_scholiqRecordId` is a new, unverified OpenConnector contract addition** — the single biggest
  implementation risk in this change (same category of risk the existing `OPENCONNECTOR_RUN_PATH` assumption
  already carries); needs live-verify against a real OpenConnector `bron-rod` adapter before this is provably
  correct end-to-end, and an adapter issue filed so OpenConnector actually echoes it back.
