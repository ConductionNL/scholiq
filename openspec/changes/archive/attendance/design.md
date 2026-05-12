# Design — Attendance: AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag

## 1. Schema decisions

### 1.1 Generic threshold model

The `AttendanceThreshold` is a configurable rule — not a hardcoded Dutch leerplicht job. The `kind: leerplicht-16uur` profile configures it with `metric: unexcused-lesuren`, `limit: 16`, `window.type: rolling-weeks, weeks: 4`, and `onCross.dataExchangeTarget: leerplicht`. All other profiles follow the same structure. This avoids per-country PHP code and keeps a single write path.

### 1.2 Threshold-crossing detection reuses Regulation pattern

`AttendanceThreshold` declares an `x-openregister-calculations.unexcusedCount` (or `attendancePercent`) and an `x-openregister-notifications.onThresholdCrossed` with `trigger: { calculatedChange: "unexcusedCount", to: ">= limit" }` (mirror of `ragStatus` → `red` in `Regulation`). The OR calculation engine re-evaluates per AttendanceRecord write. **No TimedJob** — ADR-022 strictly prohibits a parallel mechanism.

### 1.3 AttendanceFlag is appendOnly

Per ADR-008, `appendOnly: true` on `AttendanceFlag`. The record + its lifecycle transitions constitute immutable audit evidence. The flag is ONLY CREATED by the `AttendanceFlagCreationHandler`; no auto-action against the learner is taken. The mentor's intervention and any report are tracked via the flag's own lifecycle (`open → in-handling → reported → resolved`). This mirrors the proctoring-flag human-in-the-loop principle from the assessment spec.

### 1.4 ExcuseRequest → AttendanceRecord flip

On `excuse-request → approved`, the `ExcuseApprovalHandler` (an `IEventListener`) queries all `AttendanceRecord`s for the `learnerId` with `status = absent-unexcused`, filters in PHP to those with `markedAt` in `[dateFrom, dateTo]`, flips each to `absent-excused`, sets `excuseRequestId`, and persists via `ObjectService::saveObject`. This is the ADR-031 cross-object write bridge.

### 1.5 lesuren calculation

A "lesuur" is a standard Dutch school hour (~50–60 min). `AttendanceRecord.lesuren` is calculated as: when `status === present | late | left-early` → `minutesAttended / lesuurMinutes` (from the threshold, default 60); when fully absent → `session.durationMinutes / lesuurMinutes`. This is declared in `x-openregister-calculations.lesuren`. The per-learner rolling sum is aggregated on `AttendanceThreshold`.

## 2. Schemas

### 2.1 AttendanceRecord (slug `attendance-record`)

| field | type | notes |
|---|---|---|
| sessionId | uuid | required |
| learnerId | string | required — NC user ID |
| cohortId | uuid\|null | denormalised for filtering |
| status | enum | present\|absent-unexcused\|absent-excused\|late\|left-early |
| minutesAttended | int\|null | actual minutes; null = absent |
| markedBy | string | required — NC user ID of teacher |
| markedAt | datetime | required — ISO 8601 |
| reason | string\|null | optional free-text |
| excuseRequestId | uuid\|null | set when an approved ExcuseRequest flipped this record |
| tenant_id | string | required — uuid |

Not appendOnly — a teacher may correct a mis-marked record; OR's update audit is sufficient.

`x-openregister-calculations`:
- `isUnexcusedAbsence`: `status === 'absent-unexcused'`
- `lesuren`: `minutesAttended / lesuurMinutes` when attended; else `session.durationMinutes / lesuurMinutes`

`x-openregister-relations`: session → Session, learner (learnerId), cohort → Cohort, excuseRequest → ExcuseRequest.

### 2.2 ExcuseRequest (slug `excuse-request`)

| field | type | notes |
|---|---|---|
| learnerId | string | required — NC user ID |
| submittedBy | string | required — NC user ID (may be parent or 18+ learner) |
| dateFrom | date | required |
| dateTo | date | required |
| reason | string | required |
| reasonKind | enum | illness\|medical-appointment\|family-circumstance\|religious-observance\|bereavement\|other |
| attachmentRef | string\|null | optional — doctor's note ref etc. |
| submittedAuthLevel | enum | none\|basic\|substantial\|high — records eIDAS assurance |
| decidedBy | string\|null | NC user ID of coordinator |
| decidedAt | datetime\|null | ISO 8601 |
| decisionNote | string\|null | optional |
| lifecycle | string | submitted → approved \| rejected |
| tenant_id | string | required |

`x-openregister-lifecycle.transitions.approve` triggers `ExcuseApprovalHandler` (listener) to flip matching AttendanceRecords.

`x-openregister-relations`: learner (learnerId).

### 2.3 AttendanceThreshold (slug `attendance-threshold`)

| field | type | notes |
|---|---|---|
| name | string | required |
| kind | enum | leerplicht-16uur\|college-aanwezigheid\|training-attendance\|compliance-presence\|generic |
| scope | enum | per-learner\|per-cohort |
| cohortId | uuid\|null | null = applies to all cohorts |
| window | object | `{type: "rolling-weeks", weeks: int}` or `{type: "fixed-term", termId: string}` |
| metric | enum | unexcused-lesuren\|unexcused-sessions\|attendance-percent-below |
| limit | number | e.g. 16 for leerplicht |
| lesuurMinutes | int | default 60 — used when metric is `unexcused-lesuren` |
| onCross | object | `{notify: bool, notifyRoles: string[], createFlag: bool, dataExchangeTarget: string\|null}` |
| active | boolean | default true |
| lifecycle | string | draft → active → archived |
| tenant_id | string | required |

`x-openregister-calculations`: `unexcusedLesuren` (rolling sum per learner) — the crossing detection materialised value.

`x-openregister-notifications.onThresholdCrossed`: trigger `calculatedChange` on `unexcusedLesuren` crossing `limit`; `recipientFromTenantRole` the `notifyRoles`; `idempotencyKey: "${@self.id}-${learnerId}-${windowStart}"`. Mirrors `officerAlertOnCoverageDrop` on `Regulation`.

`x-openregister-widgets.cohortAttendanceGrid`: type `cohort-attendance-grid`, title `scholiq.widget.attendance.cohort`.

`x-openregister-relations`: cohort → Cohort.

### 2.4 AttendanceFlag (slug `attendance-flag`, `appendOnly: true`)

| field | type | notes |
|---|---|---|
| learnerId | string | required |
| attendanceThresholdId | uuid | required |
| cohortId | uuid\|null | optional |
| windowStart | date | required |
| windowEnd | date | required |
| metricValue | number | value at crossing (e.g. 16) |
| breachingRecordIds | uuid[] | AttendanceRecords that pushed over the limit |
| dataExchangeJobId | uuid\|null | set when outbound report is queued |
| mentorId | string\|null | NC user ID resolved from LearnerProfile.managerId |
| lifecycle | string | open → in-handling → reported → resolved |
| tenant_id | string | required |

`x-openregister-lifecycle.transitions.report.requires`: `OCA\Scholiq\Lifecycle\AttendanceFlagReportGuard` (stub — returns true; tighten in data-exchange spec).

`x-openregister-calculations`: `daysSinceFlag`.

`x-openregister-relations`: learner (learnerId), attendanceThreshold → AttendanceThreshold, cohort → Cohort.

## 3. PHP — ADR-031 legitimate exceptions

### 3.1 ExcuseApprovalHandler (lib/Lifecycle/ExcuseApprovalHandler.php)

`IEventListener<ObjectTransitionedEvent>`. Filters to `register=scholiq, schema=excuse-request, to=approved`.

Algorithm:
1. Read `request.learnerId`, `request.dateFrom`, `request.dateTo`, `request.id`.
2. Fetch all `AttendanceRecord`s for the learner with `status=absent-unexcused` via `ObjectService::findAll`.
3. Filter in PHP to those with `markedAt` within `[dateFrom, dateTo]`.
4. For each: set `status=absent-excused`, `excuseRequestId=request.id`, persist via `ObjectService::saveObject`.

Registered in `Application.php` for `ObjectTransitionedEvent`. Legitimate per ADR-031: cross-object write bridge that cannot be expressed declaratively.

### 3.2 AttendanceFlagCreationHandler (lib/Lifecycle/AttendanceFlagCreationHandler.php)

`IEventListener<ObjectTransitionedEvent>`. Listens for the OR `calculatedChange` event when `AttendanceThreshold.unexcusedLesuren` crosses `limit` (represented as `ObjectTransitionedEvent` on the threshold object with special marker — or, if OR uses a dedicated `CalculatedChangeEvent`, that event; fall back to `ObjectTransitionedEvent` with `to=threshold-crossed`).

On match:
1. Read `threshold.learnerId` (the per-learner context from the event), `threshold.id`, `threshold.cohortId`, `threshold.window`, `threshold.onCross`.
2. Resolve `mentorId` from `LearnerProfile.managerId` for the learner.
3. Create `AttendanceFlag` (`open`) with `windowStart`, `windowEnd`, `metricValue`, `breachingRecordIds`, `mentorId`, `attendanceThresholdId` via `ObjectService::saveObject`.
4. If `onCross.dataExchangeTarget` is set: record the target intent on the flag (`dataExchangeJobId=null` for now). // TODO(data-exchange spec): queue a DataExchangeJob to dataExchangeTarget and set dataExchangeJobId.
5. The handler does NOT auto-act against the learner — only the flag is created.

Registered in `Application.php`. Legitimate per ADR-031: new-object creation from a calculatedChange event cannot be expressed as schema metadata.

### 3.3 AttendanceFlagReportGuard (lib/Lifecycle/AttendanceFlagReportGuard.php)

Referenced from `AttendanceFlag.x-openregister-lifecycle.transitions.report.requires`.

For now: `check(array &$transitionContext): bool { return true; }` with `// TODO(data-exchange spec): verify DataExchangeJob succeeded before allowing reported`.

Not registered in `Application.php` — OR resolves guards by class name from the schema `requires:` string.

## 4. Frontend

### 4.1 Manifest pages

| id | route | type | notes |
|---|---|---|---|
| AttendanceRecords | /attendance/records | index | schema=AttendanceRecord |
| AttendanceRecordDetail | /attendance/records/:id | detail | schema=AttendanceRecord |
| ExcuseRequests | /attendance/excuses | index | schema=ExcuseRequest |
| ExcuseRequestDetail | /attendance/excuses/:id | detail | schema=ExcuseRequest |
| AttendanceThresholds | /attendance/thresholds | index | schema=AttendanceThreshold |
| AttendanceThresholdDetail | /attendance/thresholds/:id | detail | schema=AttendanceThreshold |
| AttendanceFlags | /attendance/flags | index | schema=AttendanceFlag, readOnly |
| AttendanceFlagDetail | /attendance/flags/:id | detail | schema=AttendanceFlag, readOnly |
| MarkAttendanceView | /sessions/:sessionId/attendance | custom | component=MarkAttendanceView |
| SubmitExcuseModal | /attendance/excuses/submit | custom | component=SubmitExcuseModal |

Nav menu: "Attendance", route=AttendanceRecords, order=55.

### 4.2 MarkAttendanceView.vue

- Fetches the Session (`:sessionId`) and its Cohort's `learnerIds`.
- Renders a roster grid: one row per learner — status dropdown (present/absent-unexcused/absent-excused/late/left-early) + optional minutesAttended field.
- "Mark all present" shortcut fills all rows to `present`.
- Save: POST/PUT one `AttendanceRecord` per learner (check for existing via GET first; update if found, create if not).
- Options API, no Pinia module.

### 4.3 SubmitExcuseModal.vue

- Form: `learnerId` (pre-filled from current user), `dateFrom`, `dateTo`, `reason`, `reasonKind` select.
- DigiD flow toggle: if selected, shows placeholder banner ("DigiD authentication would happen here"); records `submittedAuthLevel: substantial`.
- Default click-to-confirm: records `submittedAuthLevel: basic`.
- On submit: POST `ExcuseRequest` with `submittedBy=currentUser`.
- Options API, no Pinia module.

## 5. Threshold-crossing — NOT a TimedJob

The `unexcusedLesuren` calculation on `AttendanceThreshold` is re-evaluated by OR's engine on each `AttendanceRecord` save that references a learner covered by the threshold. When the materialised value crosses `limit`, OR fires the `calculatedChange` notification (`onThresholdCrossed`). The `AttendanceFlagCreationHandler` creates the `AttendanceFlag` in response. The `idempotencyKey` (`${@self.id}-${learnerId}-${windowStart}`) prevents duplicate flags for the same window crossing.

## 6. Out of scope

- Digikoppeling/StUF wire protocol (data-exchange spec).
- DigiD authentication handshake (openconnector).
- Geofenced / biometric attendance capture.
- Truancy-pattern AI prediction (AiFeature).
- DataExchangeJob queueing from AttendanceFlagCreationHandler (data-exchange spec — flagged with TODO).
