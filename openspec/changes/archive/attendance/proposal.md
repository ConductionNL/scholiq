## Why

Institutions record who was present at every Session, and some are legally obligated to act when absence crosses a threshold. The Dutch **Leerplichtwet** art. 21a requires every school to report `ongeoorloofd verzuim` of 16 lesuren in any rolling 4-week window to the `leerplichtambtenaar`. Higher-education programmes track `college-aanwezigheid` for attendance-based credit. Corporate compliance training needs presence proof for audit. Certification programmes require N hours of attended instruction.

The structure is identical across all cases: an `AttendanceRecord` per (Session, learner) with a status, and an `AttendanceThreshold` rule that watches a learner's records over a window and fires when crossed. This generalises and supersedes the Dutch `absence-leerplicht` stub. The 16-lesuur rule is one `AttendanceThreshold` profile; the *report to the leerplichtambtenaar* is a `DataExchangeJob` (the `data-exchange` spec — a forward reference, not implemented here).

## What Changes

### New Schemas (4) — `lib/Settings/scholiq_register.json` (29 → 33)

- **AttendanceRecord** (slug `attendance-record`) — per Session per learner fact. `status` enum: `present` | `absent-unexcused` | `absent-excused` | `late` | `left-early`. Bulk-markable from the Session roster. `x-openregister-calculations`: `isUnexcusedAbsence`, `lesuren`. No appendOnly — a teacher may correct a mis-marked record; OR's update audit trail is sufficient.
- **ExcuseRequest** (slug `excuse-request`) — a learner, parent, or 18+ learner-self submits an absence excuse for a date range. Lifecycle: `submitted → approved | rejected`. On `approved`: an `ExcuseApprovalHandler` listener flips matching `AttendanceRecord`s (same learner, `markedAt` within `[dateFrom, dateTo]`, status `absent-unexcused`) to `absent-excused` and sets their `excuseRequestId`. `submittedAuthLevel` records the DigiD eIDAS assurance level — the handshake itself is out of scope.
- **AttendanceThreshold** (slug `attendance-threshold`) — a configured rule. `kind` profiles: `leerplicht-16uur | college-aanwezigheid | training-attendance | compliance-presence | generic`. Crossing detection via `x-openregister-calculations` + `calculatedChange` notification — the **same** mechanism used by `Regulation`'s `ragStatus` threshold (no parallel TimedJob — ADR-022). Lifecycle: `draft → active → archived`.
- **AttendanceFlag** (slug `attendance-flag`) — `appendOnly: true` (ADR-008 audit). Created when a threshold crosses. Lifecycle: `open → in-handling → reported → resolved`. The flag tracks the mentor's intervention and any outbound report. It NEVER auto-acts against the learner; the mentor's intervention is human-in-the-loop, mirroring the proctoring-flag rule from the assessment spec.

### New PHP (3, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/ExcuseApprovalHandler.php` — `IEventListener` for `ObjectTransitionedEvent`; filters to `excuse-request → approved`; queries `AttendanceRecord`s for `learnerId` + `absent-unexcused` in `[dateFrom, dateTo]` and flips each to `absent-excused` + sets `excuseRequestId`.
- `lib/Lifecycle/AttendanceFlagCreationHandler.php` — `IEventListener` for the `calculatedChange` event on `AttendanceThreshold` crossing; creates an `AttendanceFlag` (`open`), fires `onCross` notification to `notifyRoles` recipients (resolves mentor from `LearnerProfile.managerId`), and records the `dataExchangeTarget` intent on the flag (actual queueing deferred to the `data-exchange` spec).
- `lib/Lifecycle/AttendanceFlagReportGuard.php` — guard for the `report` transition on `AttendanceFlag`; for now returns `true` with a `// TODO(data-exchange spec)` comment. Will tighten to verify `DataExchangeJob` success once that spec lands.

### New Frontend

- Manifest pages: `AttendanceRecords` / `AttendanceRecordDetail`, `ExcuseRequests` / `ExcuseRequestDetail`, `AttendanceThresholds` / `AttendanceThresholdDetail`, `AttendanceFlags` / `AttendanceFlagDetail` (readOnly — appendOnly). Custom pages: `MarkAttendanceView` (Session roster bulk-mark grid), `SubmitExcuseModal` (learner/parent excuse submission with DigiD placeholder). Nav menu entry: "Attendance" (order 55).
- Dashboard widget `cohort-attendance` declared as `x-openregister-widgets` on `AttendanceThreshold` — type `cohort-attendance-grid`.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages and both custom views (plain-English keys, both languages).

## Capabilities

### New Capabilities

- `attendance`: AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag schemas with declarative lifecycle / calculations / notifications; ExcuseApprovalHandler + AttendanceFlagCreationHandler PHP listener exceptions; AttendanceFlagReportGuard stub; manifest pages + two custom Vue views; l10n en+nl.

### Out of Scope

- Wire protocol of the leerplicht report (Digikoppeling/StUF) — `data-exchange` / openconnector adapter.
- DigiD authentication handshake — openconnector / NC auth.
- Geofenced / NFC / biometric attendance capture (follow-up).
- Truancy-pattern AI prediction (`AiFeature` registration).
- `DataExchangeJob` creation from `AttendanceFlagCreationHandler` (deferred to `data-exchange` spec; flag records the target intent).
