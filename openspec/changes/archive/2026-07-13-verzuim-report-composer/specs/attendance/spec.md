# Attendance — Verzuimloket Report Composer & Deadline Delta

**Spec refs**: `attendance`, `data-exchange`

## MODIFIED Requirements

### Requirement: Persist Attendance domain objects in OpenRegister

The system MUST persist `AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, `AttendanceFlag` as
OpenRegister objects with `x-openregister-lifecycle` (ExcuseRequest: submitted → approved | rejected;
AttendanceFlag: open → in-handling → reported → resolved), `x-openregister-relations` (AttendanceRecord↔
Session/learner, Flag↔learner/threshold), `x-openregister-calculations` (per-learner rolling counts vs
each threshold), and `x-openregister-notifications` (`onCross` mentor/coordinator alert,
idempotency-keyed). `AttendanceFlag` MUST be `appendOnly: true` (audit per ADR-008). `AttendanceFlag` MUST
additionally persist an `interventions` list — each entry timestamped, attributed to the acting
mentor/coordinator (Nextcloud user ID), and carrying a free-text note — recording the school's handling
history (contact attempts, agreements reached, escalations) while the flag is `open`/`in-handling`.
Appending an intervention MUST NOT bypass `appendOnly` versioning: each addition is a new, audited version
of the flag (ADR-008), never an in-place edit of a prior entry.

#### Scenario: Attendance objects persist in OpenRegister

- **GIVEN** the attendance schemas are registered in OpenRegister
- **WHEN** an `AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, or `AttendanceFlag` is created
- **THEN** it is stored as an OpenRegister object with its lifecycle, relations, calculations, and
  notifications metadata, and `AttendanceFlag` is `appendOnly: true` for audit (ADR-008)

#### Scenario: A mentor's intervention is recorded on the flag

- **GIVEN** an `AttendanceFlag` in `in-handling`
- **WHEN** a mentor records a contact attempt with the learner as an intervention note
- **THEN** the note is appended to the flag's `interventions` list with its author and timestamp, as a new
  audited version of the append-only flag

### Requirement: External authority reporting via DataExchangeJob

The report to an external authority (leerplichtambtenaar via Digikoppeling, etc.) MUST be a
`DataExchangeJob` (see `data-exchange`), not implemented inline here. When the target is `leerplicht` (the
verzuimloket melding), the composed report MUST draw on the originating `AttendanceFlag`'s
`breachingRecordIds` (the underlying `AttendanceRecord`s) and its `interventions` history, not only the
flag's summary metrics — mirroring `data-exchange`'s OSO dossier-composer pattern of assembling a dossier
from multiple linked objects rather than a flat field-mapped export. `AttendanceFlag` MUST additionally
carry a declared statutory reporting-deadline calculation: 5 school days after the threshold crossing,
where a school day is a calendar day on which the tenant has at least one scheduled `Session` (reusing
`Session` occurrence as the school-day source of truth — vacation weeks and closure days already carry no
`Session`s and are excluded without a separate vacation calendar). Reaching or passing the deadline while
the flag has not reached `reported` MUST raise a distinct, idempotency-keyed overdue signal to the mentor
and coordinator, additive to the existing `onCross` alert. This deadline calculation is informational and
MUST NOT alter the existing `in-handling → reported` transition guard.

#### Scenario: External authority report runs as a DataExchangeJob

- **GIVEN** a crossed threshold whose `onCross` includes an external-authority target
- **WHEN** the report to the authority (e.g. leerplichtambtenaar via Digikoppeling) is dispatched
- **THEN** it runs as a `DataExchangeJob` (see `data-exchange`) and is not implemented inline in this spec

#### Scenario: Verzuimloket report composes from records and intervention history

- **GIVEN** an `AttendanceFlag` with populated `breachingRecordIds` and two recorded `interventions`
- **WHEN** its `leerplicht`-target `DataExchangeJob` composes the verzuimloket dossier
- **THEN** the composed dossier includes the breaching `AttendanceRecord`s and the intervention history,
  not just the flag's summary metrics

#### Scenario: Deadline countdown skips a vacation week

- **GIVEN** an `AttendanceFlag` raised on the last school day before a one-week vacation with no scheduled
  `Session`s
- **WHEN** the 5-school-day reporting deadline is calculated
- **THEN** the vacation week contributes zero school days to the countdown, and the deadline lands on the
  5th day on which the tenant has a scheduled `Session` after the crossing

#### Scenario: Unreported flag past deadline raises an overdue signal

- **GIVEN** an `AttendanceFlag` that has not reached `reported` by its calculated `reportDeadlineAt`
- **WHEN** the deadline calculation flips to overdue
- **THEN** a distinct, idempotency-keyed overdue notification reaches the mentor and coordinator, in
  addition to the original `onCross` alert, and the flag's existing report-transition guard is unaffected
