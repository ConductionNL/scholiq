---
kind: code
depends_on: []
---

## Why

Evidence: insight 1144, stories 10051/10052, journeys 1733/1734 — the verzuimloket report a coordinator
sends to the municipality when an `AttendanceFlag` crosses the 16-uur Leerplichtwet art. 21a threshold is,
at HEAD, a bare four-field statistics export with no case history, no statutory-deadline visibility, and
nowhere to record what the municipality does with it. Verified directly against HEAD (2026-07-12):

- **The `leerplicht` `DataMappingProfile` maps four fields only.** The seeded "Leerplicht notification
  export" profile (`lib/Settings/scholiq_register.json:7390-7422`) maps `learnerId → leerlingId`,
  `windowStart → periodeVan`, `windowEnd → periodeTm`, `metricValue → aantalLesuren` — the threshold
  crossing's raw numbers, nothing else. Compare this to `target: oso`, which the `data-exchange` spec's own
  "What" section documents as composed by a dedicated **dossier composer** — "assembles the transfer
  dossier from existing `LearnerProfile` + `GradeEntry` + `AttendanceRecord` + `LearningPlan` data"
  (`openspec/specs/data-exchange/spec.md:25`) — a genuinely richer, multi-object composition. The
  verzuimloket report has no equivalent; a leerplichtambtenaar receiving it sees a threshold number with no
  context on what the school already tried.
- **`AttendanceFlag` has no field for the school's prior interventions.** Its full property set
  (`lib/Settings/scholiq_register.json:7046-7127`) is `learnerId`, `attendanceThresholdId`, `cohortId`,
  `windowStart`/`windowEnd`, `metricValue`, `breachingRecordIds`, `dataExchangeJobId`, `mentorId`,
  `lifecycle`, `tenant_id` — no notes, no intervention log. The mentor's contact attempts and any agreement
  reached before the report goes out (exactly what a receiving municipality wants to know) are not
  captured anywhere, even though the flag's own description already claims it "tracks the mentor's
  intervention" (`:7005`) — the schema does not yet back that claim.
- **The 16-uur window does not itself model vacations — it doesn't need to, and there is nothing to reuse
  for a calendar deadline.** `AttendanceThreshold.window` (`:6790-6810`) is a plain "rolling N weeks"
  bound over `AttendanceRecord.markedAt`; the 16-uur count is only ever accrued from `AttendanceRecord`s
  tied to a `Session` (`:3298-3357`), and schools schedule no `Session`s during a vacation — so vacation
  weeks are implicitly excluded from the lesuren count by having nothing to count, not by any explicit
  vacation-calendar mechanism. Repo-wide grep for `Vacation|AcademicCalendar|Holiday|schoolWeek|vakantie`
  across `lib/Settings/scholiq_register.json` and every `openspec/specs/*/spec.md` returns zero hits: **no
  vacation-calendar schema exists to reuse.** The statutory 5-working-day report deadline this change adds
  is a new, genuinely calendar-facing requirement that has to define "school day" itself — the only
  HEAD-verified precedent for what already counts as a school day is `Session` occurrence, which this
  change reuses rather than inventing a parallel vacation calendar.
- **`DataExchangeJob` has nowhere to record what happens after the report is sent.** Its full property set
  (`:7468-7647`) ends at `result`/`connectorRunId`/`errorMessage`/`originFlagId`/`lifecycle` — once a
  `leerplicht` job reaches `succeeded`, there is no field for the municipality's follow-up (which
  case-handling route — the MAS-route — it assigns the report to). The nearest precedent in this codebase
  for "record an external authority's decision without adjudicating it" is `TlvApplication.decision`
  (`openspec/changes/zorgvraag-swv-tlv-chain/design.md:127-137`, itself unmerged) — this change applies the
  same posture to the leerplicht report.

This is a genuine MUST-tier gap in a `feature_tier: must` spec (`openspec/specs/attendance/spec.md:5`): the
threshold→flag→`DataExchangeJob` reporting *mechanism* is done and correctly delegates the wire protocol
(`openconnector`), but the report's *content* and the school's statutory obligations around it are not
modelled. This change closes that gap without touching the existing detection/queueing chain.

## What Changes

- **Verzuimloket dossier composition mirrors the OSO dossier composer.** For `target: leerplicht`, the
  `DataExchangeJob` payload is composed from the originating `AttendanceFlag` plus its
  `breachingRecordIds` (the underlying `AttendanceRecord`s) and a new `interventions` history — the same
  "assemble from linked objects" pattern the OSO composer already uses — instead of the current bare
  four-field mapping. Unlike OSO/SWV, this composition does **not** gate on `pending-parent-review`: it is
  a mandatory Leerplichtwet art. 21a report to the municipality, not a discretionary transfer.
- **`AttendanceFlag` gains an `interventions` log.** Timestamped, author-attributed, free-text entries
  recording the school's handling history (mentor contact, agreements, escalations) between `open` and
  `reported`/`resolved`. Each addition is a new audited version of the append-only flag (ADR-008) — no
  in-place edits.
- **Statutory 5-working-day deadline countdown.** A declared calculation on `AttendanceFlag` counts school
  days since the crossing, where a school day is a calendar day with at least one scheduled `Session`
  (reused, not a new vacation calendar — see Why). Reaching/passing day 5 while still unreported raises a
  distinct, idempotency-keyed overdue signal to the mentor + coordinator, additive to the existing
  `onCross` alert. This is informational only — it does not change `AttendanceFlagReportGuard`'s existing
  `in-handling → reported` gate (still: report only after the `DataExchangeJob` succeeds).
- **Municipality MAS-route feedback.** `DataExchangeJob` gains a nullable `municipalityFeedback`
  (route, received-at, note, recorded-by) that an authorised coordinator records once the municipality
  communicates its case-handling route for a `leerplicht` report. Scholiq records this; it does not poll,
  infer, or automate it.
- **Frontend**: surface the intervention log, the deadline/overdue state, and the linked job's
  `municipalityFeedback` on the existing declarative `AttendanceFlagDetail` page. No new custom view, no
  PHP CRUD controller (ADR-022).

## Impact

- `openspec/specs/attendance/spec.md` — MODIFIED requirements: "Persist Attendance domain objects in
  OpenRegister" (adds `interventions`), "External authority reporting via DataExchangeJob" (adds dossier
  composition richness + the statutory deadline countdown).
- `openspec/specs/data-exchange/spec.md` — MODIFIED requirements: "Persist DataExchangeJob and
  DataMappingProfile in OpenRegister" (adds `municipalityFeedback`); "Verzuimloket dossier composition
  mirrors the OSO dossier composer" (new, mirroring the existing OSO composer pattern, explicitly without
  the `pending-parent-review` gate).
- `lib/Settings/scholiq_register.json` — `AttendanceFlag` (`interventions`,
  `schoolDaysSinceFlag`/`reportDeadlineAt`/`reportOverdue`), `DataExchangeJob` (`municipalityFeedback`),
  and the `Leerplicht notification export` `DataMappingProfile` seed (implementation-time — see
  `tasks.md`).
- `src/manifest.json` / `AttendanceFlagDetail` — read-only surfacing of the above; no new pages.
- No new PHP service classes beyond the existing ADR-031 "external-system bridge" exception already granted
  to `data-exchange`'s job-execution handler (this extends the `leerplicht` composition path within it) and
  whatever aggregation wiring `schoolDaysSinceFlag` needs if the declarative `count_distinct` metric
  (`lib/Settings/scholiq_register.json:956-974`) doesn't cover date-truncation out of the box — see
  `tasks.md` task 1.2.
- Does NOT touch: `AttendanceThreshold`'s crossing detection, `AttendanceFlag`'s existing lifecycle
  transitions/guard (`AttendanceFlagReportGuard`), or the `DataExchangeJob` auto-queue wiring — the
  threshold→flag→`DataExchangeJob` chain is out of scope for this change.
