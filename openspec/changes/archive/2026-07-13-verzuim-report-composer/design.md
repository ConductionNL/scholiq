# Design: verzuim-report-composer

## Architecture Overview

This change touches only the *content* of the existing 16-uur threshold→flag→`DataExchangeJob` pipeline
(`attendance` + `data-exchange`, both `status: done`), not its detection or queueing mechanism:

```
AttendanceThreshold crosses (unchanged)
  └─ AttendanceFlag created (open)              ◄── NEW: interventions[] appended over time
       │  mentor/coordinator handle it                  by mentor/coordinator (append-only versions)
       ▼                                          ◄── NEW: schoolDaysSinceFlag / reportDeadlineAt /
  in-handling ─────────────────────────────────►      reportOverdue (declared calc, informational)
       │
       │  DataExchangeJob(target: leerplicht) auto-queued (unchanged trigger)
       ▼
  queued ──► running ──► succeeded              ◄── NEW: composed from AttendanceFlag +
       │  (no pending-parent-review — unchanged      breachingRecordIds + interventions,
       │   for this target, unlike oso/swv)           not the bare 4-field export
       ▼
  AttendanceFlagReportGuard checks job succeeded (unchanged)
       │
       ▼
  reported                                       ◄── NEW: municipalityFeedback recorded on the
       │                                              DataExchangeJob once known (coordinator-entered)
       ▼
  resolved (unchanged)
```

## Data Model

All changes are additive fields on existing OpenRegister objects — no new schemas, no new PHP database
tables (ADR-001).

### AttendanceFlag additions

| Field | Type | Notes |
|---|---|---|
| `interventions` | array of `{ recordedBy: string, recordedAt: date-time, note: string, lifecycleAtRecording: string\|null }` | Append-only growth list; each addition is a new audited version of the `appendOnly: true` flag (ADR-008), not an edit of a prior entry |
| `schoolDaysSinceFlag` | integer, `materialise: true` | `x-openregister-aggregations`, `metric: count_distinct`, `schema: Session`, counting distinct school days (by `Session.startsAt` date) with `startsAt >= windowEnd` — mirrors the existing `count_distinct` precedent (`lib/Settings/scholiq_register.json:956-974`) rather than a new mechanism |
| `reportDeadlineAt` | date, `materialise: true` | Derived once `schoolDaysSinceFlag` reaches 5; two-step calculation pattern matching `unexcusedLesuren`/`isThresholdCrossed` (`:6932-6966`) |
| `reportOverdue` | boolean, `materialise: true` | `lifecycle NOT IN (reported, resolved) AND @now >= reportDeadlineAt` |

### DataExchangeJob addition

| Field | Type | Notes |
|---|---|---|
| `municipalityFeedback` | object, nullable: `{ masRoute: string\|null, receivedAt: date-time\|null, note: string\|null, recordedBy: string\|null }` | Coordinator-entered once the municipality communicates its case-handling route for a `leerplicht` report; Scholiq neither polls for nor infers this |

### Verzuimloket dossier composition (implementation-time)

The `Leerplicht notification export` `DataMappingProfile` seed (`:7390-7422`) currently maps 4 flat
fields. This change either extends that mapping's `fieldMappings` to resolve `breachingRecordIds` and
`interventions` (if the mapping engine supports resolving `$ref` arrays into nested payload sections — it
already resolves scalar transforms like `bsn-to-pseudonym`/`cohort-to-brin`), or, if it doesn't, the
`leerplicht` composition path in the existing job-execution handler gets the same kind of dedicated
composition step already implied for `target: oso` by the data-exchange spec's "OSO dossier composer"
language (`openspec/specs/data-exchange/spec.md:25`) — a small, targeted addition to the *existing*
ADR-031 "external-system bridge" exception, not a new PHP service.

## Decisions

### Reuse `Session` occurrence as "school day", not a new vacation-calendar schema

**Chosen**: a school day = a calendar day on which the tenant has ≥1 scheduled `Session`
(`count_distinct` aggregation over `Session.startsAt`, date-truncated).
**Rejected**: a dedicated `Vacation`/`AcademicCalendar` schema with explicit closure periods. Verified at
HEAD: no such schema exists anywhere in the register or specs, and the 16-uur window itself has never
needed one — vacation weeks already carry zero `Session`s, so they already contribute zero to every
`AttendanceRecord`-driven calculation. Building a parallel vacation calendar just for the 5-day deadline
would duplicate information `Session` scheduling already encodes (ADR-022's spirit: don't build a second
mechanism for something already derivable), and would risk drifting out of sync with the actual timetable
if a school later reschedules or cancels sessions without updating a separate calendar.
**Caveat**: this is not a perfect proxy — a single-day ad-hoc school closure with no `Session`s scheduled
(e.g. a study day) also reads as "not a school day," which is actually correct for the deadline's intent
(no one's there to act on the report), so this is treated as a feature, not a gap.

### No `pending-parent-review` gate on the verzuimloket dossier

**Chosen**: `target: leerplicht` composition proceeds `queued → running` the same way non-OSO targets
already do (`DataExchangeRunGuard`, `:7668-7671`), unchanged by this composition richness increase.
**Rejected**: reusing the OSO/SWV `pending-parent-review` gate for the richer dossier. The leerplicht
report is a unilateral statutory obligation on the school (Leerplichtwet art. 21a) — the parent does not
get to block or approve it, unlike a genuinely discretionary OSO transfer or SWV zorgvraag. Composing a
richer payload does not change who has authority over sending it.

### `interventions` as a growing sub-list on `AttendanceFlag`, not a separate schema

**Chosen**: an array field on the existing append-only `AttendanceFlag`.
**Rejected**: a standalone `AttendanceFlagIntervention` object (the `zorgvraag-swv-tlv-chain` /
`DeliberationRecord` pattern). That pattern fits when the sub-record has its own lifecycle, RBAC, or
cross-references independent of its parent; a mentor's contact note has none of that — it is scoped
entirely to one flag's handling history and is naturally bounded (a handful of entries per flag, not an
independently queryable collection). A sub-list keeps the composer's job (read one flag, get its full
history) a single-object read instead of an extra join, and matches the existing
`LearningPlanEvaluation.goalOutcomes`/`note` shape (`lib/Settings/scholiq_register.json:6180-6213`) already
used for a structurally identical "array of attributed notes" case.

### `municipalityFeedback` recorded, never adjudicated

**Chosen**: a coordinator-entered field on `DataExchangeJob`; Scholiq does not poll DUO's verzuimloket or
infer a route.
**Rejected**: any automated ingestion or interpretation of the municipality's decision. Mirrors the
established posture for externally-decided outcomes elsewhere in this codebase (`TlvApplication.decision`
in `openspec/changes/zorgvraag-swv-tlv-chain/design.md:127-137`, not yet merged but the same principle
already governs `data-exchange`'s own "Scholiq implements no wire protocol" posture) — the municipality is
the authority; Scholiq's job is to hold what it's told, not to interpret it.

## Security Considerations

- **`municipalityFeedback` write scope**: MUST be restricted to the coordinator role (or admin) that
  handles the flag, not any authenticated user. The register's only existing `x-openregister-authorization`
  precedent (`:1281-1286`, `xapi-statement`) gates `create` only and is explicitly labelled a stopgap in
  its own comment — there is no evidenced `update`-scoped field-level RBAC key in this register yet. If OR
  does not support a per-field write gate, implement the restriction as a small validation callback checked
  before the field is accepted (mirroring the existing lifecycle-guard pattern, e.g.
  `AttendanceFlagReportGuard`), not as a broad admin-only lock on the whole `DataExchangeJob` object (which
  already has other legitimately coordinator-writable fields via its normal request flow).
- **No new sensitive-data exposure**: `interventions.note` and `municipalityFeedback.note` are free text
  written by school staff about a minor's attendance case — the same sensitivity class `AttendanceFlag`
  already carries (it's `appendOnly` for ADR-008 audit reasons precisely because it's compliance-sensitive
  evidence). No new field here crosses the tenant boundary that wasn't already implicitly reachable via the
  existing `leerplicht` `DataExchangeJob` — the composition richness increase is still bounded to the same
  flag + its own breaching records, not a wider learner-data pull.
- **Audit**: `interventions` additions are new versions of an already-`appendOnly` object (ADR-008
  unchanged); `municipalityFeedback` updates on `DataExchangeJob` (not append-only) MUST still emit an OR
  audit-trail entry, consistent with the existing "every transition is audited" requirement — since this is
  a plain field update rather than a lifecycle transition, confirm at implementation time that OR's
  audit-trail emission covers non-transition field updates for this schema, or add the update to the
  audited surface if it doesn't.

## Trade-offs

- **Session-occurrence proxy for "school day" vs a dedicated calendar** — chosen for reuse and to avoid a
  second, driftable source of truth; the cost is the ad-hoc-closure edge case noted above, judged
  acceptable (and arguably correct) for a deadline whose purpose is "can the school realistically act."
- **`interventions` as an unbounded array vs a capped/paginated sub-collection** — a flag's handling window
  is short (opens, gets handled, gets reported within the 5-day deadline this change adds), so unbounded
  growth is not a realistic operational concern; revisit only if a buyer's process routinely re-opens flags
  many times.
