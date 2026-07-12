## 1. Schema: AttendanceFlag — interventions + statutory deadline

- [ ] 1.1 Add `interventions` array field to `AttendanceFlag`
      (`lib/Settings/scholiq_register.json:7046-7127`): items `{recordedBy: string, recordedAt:
      date-time, note: string, lifecycleAtRecording: string|null}`. `default: []`. Purely additive — do
      not touch `required` or existing properties.
- [ ] 1.2 Add `x-openregister-aggregations.schoolDaysSinceFlag` to `AttendanceFlag`: `metric:
      count_distinct`, `schema: Session`, counting distinct calendar dates from `Session.startsAt` with
      `startsAt >= windowEnd`, tenant-scoped — mirror the existing `count_distinct` precedent
      (`:956-974`). If the aggregation engine can't date-truncate a `date-time` field for `count_distinct`
      directly, add a small materialised `sessionDate` calculation on `Session` first (mirroring
      `AttendanceRecord.lesuren`'s derived-field pattern, `:6503`) and aggregate against that instead.
- [ ] 1.3 Add `x-openregister-calculations.reportDeadlineAt` / `.reportOverdue` to `AttendanceFlag`,
      derived from `schoolDaysSinceFlag` reaching 5 — same two-step declared-calculation shape as
      `AttendanceThreshold.unexcusedLesuren`/`isThresholdCrossed` (`:6932-6966`). `reportOverdue` is true
      only while `lifecycle` is `open` or `in-handling` (not `reported`/`resolved`).
- [ ] 1.4 Add `x-openregister-notifications.reportDeadlineOverdue` to `AttendanceFlag`: `calculatedChange`
      trigger on `reportOverdue` flipping `false → true`, idempotency-keyed, recipients `learnerId`'s
      mentor + coordinator (same recipient shape as the existing `flagRaised` notification, `:7013-7037`),
      NL/EN subject. Additive — do not remove or alter `flagRaised`.

## 2. Schema: DataExchangeJob — municipality feedback

- [ ] 2.1 Add nullable `municipalityFeedback` object to `DataExchangeJob`
      (`lib/Settings/scholiq_register.json:7468-7647`): `{masRoute: string|null, receivedAt:
      date-time|null, note: string|null, recordedBy: string|null}`. `default: null`. Purely additive.
- [ ] 2.2 Restrict write access to `municipalityFeedback` to the coordinator role (or admin). The
      register's only existing `x-openregister-authorization` block (`:1281-1286`) gates `create` only and
      is itself a documented stopgap — confirm at implementation time whether OR supports field-scoped
      `update` authorization; if not, add a small validation callback (mirroring the existing
      `AttendanceFlagReportGuard`/`OsoDossierReviewGuard` lifecycle-guard shape) checked when this field
      changes, rather than locking the whole object to admin-only.

## 3. Verzuimloket dossier composition (target: leerplicht)

- [ ] 3.1 Extend the `Leerplicht notification export` `DataMappingProfile` seed
      (`:7390-7422`) — or add a dedicated composition step in the existing job-execution handler alongside
      the OSO composer path — so the `target: leerplicht` payload includes the flag's resolved
      `breachingRecordIds` (`AttendanceRecord`s) and `interventions`, not only `learnerId`/`windowStart`/
      `windowEnd`/`metricValue`.
- [ ] 3.2 Confirm the `leerplicht` target still reaches `running` via `DataExchangeRunGuard`
      (`:7665-7671`) and does NOT route through `pendingParentReview`/`approveDossier`/
      `OsoDossierReviewGuard` (`:7648-7664`) — add a regression test asserting this if the guard's
      condition is ever broadened to match by dossier shape instead of `target === 'oso'`.

## 4. Frontend

- [ ] 4.1 Surface `interventions` as a read-only timeline (plus an add-note action) on the existing
      declarative `AttendanceFlagDetail` page (`src/manifest.json`) — no new custom view unless OR's
      existing repeating-subform rendering genuinely can't cover an attributed note list.
- [ ] 4.2 Surface `reportDeadlineAt`/`reportOverdue` and the linked job's `municipalityFeedback`
      (via `dataExchangeJobId`) as a read-only summary on `AttendanceFlagDetail`.

## 5. Tests + docs + verify

- [ ] 5.1 Unit test: `interventions` entries accumulate without overwriting prior entries; each addition
      versions the `appendOnly: true` flag (ADR-008).
- [ ] 5.2 Unit test: `schoolDaysSinceFlag`/`reportDeadlineAt` — a week with no scheduled `Session`s (a
      vacation) contributes zero toward the 5-day count; the deadline lands on the 5th actual school day
      after the crossing.
- [ ] 5.3 Integration test: a `leerplicht`-target `DataExchangeJob` auto-queued from a flag composes a
      payload including the breaching records and intervention history, and reaches `running` without
      entering `pending-parent-review`.
- [ ] 5.4 Integration test: `municipalityFeedback` can only be written by an authorised coordinator/admin;
      writing it does not itself change the flag's or job's lifecycle state.
- [ ] 5.5 Add `@spec` docblock tags (ADR-020) to any new/touched PHP (aggregation wiring, the
      `municipalityFeedback` write guard).
- [ ] 5.6 Add Dutch and English translations for the new `reportDeadlineOverdue` notification subject and
      any new UI strings.
- [ ] 5.7 Run `composer check:strict` on touched PHP; fix any pre-existing warnings encountered in those
      files.
- [ ] 5.8 Run `openspec validate verzuim-report-composer --strict` and resolve any errors.
