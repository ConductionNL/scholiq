# Design — Attendance: AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag

> **Declarative-vs-imperative decision (per [hydra ADR-031](../../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md))** — all lifecycle state machines (ExcuseRequest: submitted → approved | rejected; AttendanceThreshold: draft → active → archived; AttendanceFlag: open → in-handling → reported → resolved), calculations (isUnexcusedAbsence, lesuren, unexcusedLesuren, daysSinceFlag), notifications (onThresholdCrossed — idempotency-keyed), relations (AttendanceRecord↔Session/learner/cohort/excuseRequest; AttendanceFlag↔learner/threshold/cohort), and the cohort-attendance dashboard widget ALL fit `x-openregister-*` declarations in `scholiq_register.json`. Three PHP files are legitimate ADR-031 exceptions: ExcuseApprovalHandler (cross-object write on lifecycle transition), AttendanceFlagCreationHandler (new-object creation from calculatedChange event), and AttendanceFlagReportGuard (lifecycle guard stub pending data-exchange spec). No services, no TimedJobs.
>
> **OR abstractions consumed (per [hydra ADR-022](../../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md))** — lifecycle engine, calculations engine, notifications engine, relations, RBAC, audit trail (appendOnly for AttendanceFlag — ADR-008), archival, MCP discovery. Threshold-crossing detection reuses the same `calculatedChange` notification pattern as `Regulation.ragStatus` in the compliance-audit spec — no parallel mechanism.
>
> **Frontend (per [hydra ADR-024](../../../../.claude/openspec/architecture/adr-024-app-manifest.md))** — all CRUD for AttendanceRecord, ExcuseRequest, AttendanceThreshold, AttendanceFlag is served by `CnAppRoot`'s built-in index/detail renderers via `src/manifest.json`. Two genuinely-custom views: `MarkAttendanceView` (Session roster bulk-mark grid — cannot be expressed as a schema-driven form) and `SubmitExcuseModal` (authenticated excuse submission with DigiD placeholder).

---

## 1. Schema decisions

### 1.1 Generic threshold model

The `AttendanceThreshold` is a configurable rule — not a hardcoded Dutch leerplicht job. The `kind: leerplicht-16uur` profile configures it with `metric: unexcused-lesuren`, `limit: 16`, `window.type: rolling-weeks, weeks: 4`, and `onCross.dataExchangeTarget: leerplicht`. All other profiles follow the same structure. This avoids per-country PHP code and keeps a single write path.

### 1.2 Threshold-crossing detection reuses Regulation pattern

`AttendanceThreshold` declares an `x-openregister-calculations.unexcusedLesuren` (or `attendancePercent`) and an `x-openregister-notifications.onThresholdCrossed` with `trigger: { calculatedChange: "unexcusedLesuren", to: ">= limit" }` — the exact mirror of `ragStatus → red` in `Regulation`. The OR calculation engine re-evaluates per AttendanceRecord write. **No TimedJob** — ADR-022 strictly prohibits a parallel mechanism.

### 1.3 AttendanceFlag is appendOnly

Per ADR-008, `appendOnly: true` on `AttendanceFlag`. The record and its lifecycle transitions constitute immutable audit evidence. The flag is ONLY CREATED by the `AttendanceFlagCreationHandler`; no auto-action against the learner is taken. The mentor's intervention and any report are tracked via the flag's own lifecycle (`open → in-handling → reported → resolved`). This mirrors the proctoring-flag human-in-the-loop principle from the assessment spec.

### 1.4 ExcuseRequest → AttendanceRecord flip

On `excuse-request → approved`, the `ExcuseApprovalHandler` (an `IEventListener`) queries all `AttendanceRecord`s for the `learnerId` with `status = absent-unexcused`, filters in PHP to those with `markedAt` in `[dateFrom, dateTo]`, flips each to `absent-excused`, sets `excuseRequestId`, and persists via `ObjectService::saveObject`. This is the ADR-031 cross-object write bridge — the only pattern that cannot be expressed as schema metadata.

### 1.5 lesuren calculation

A "lesuur" is a standard Dutch school hour (~50–60 min). `AttendanceRecord.lesuren` is calculated as: when `status === present | late | left-early` → `minutesAttended / lesuurMinutes` (from the threshold, default 60); when fully absent → `session.durationMinutes / lesuurMinutes`. This is declared in `x-openregister-calculations.lesuren`. The per-learner rolling sum is aggregated on `AttendanceThreshold`.

---

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

`x-openregister-calculations`: `unexcusedLesuren` (rolling sum per learner) — the crossing-detection materialised value.

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

---

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

`IEventListener<ObjectTransitionedEvent>`. Listens for the OR `calculatedChange` event when `AttendanceThreshold.unexcusedLesuren` crosses `limit` (represented as `ObjectTransitionedEvent` on the threshold object — or, if OR uses a dedicated `CalculatedChangeEvent`, that event; fall back to `ObjectTransitionedEvent` with `to=threshold-crossed`).

On match:
1. Read `threshold.learnerId` (the per-learner context from the event), `threshold.id`, `threshold.cohortId`, `threshold.window`, `threshold.onCross`.
2. Resolve `mentorId` from `LearnerProfile.managerId` for the learner.
3. Create `AttendanceFlag` (`open`) with `windowStart`, `windowEnd`, `metricValue`, `breachingRecordIds`, `mentorId`, `attendanceThresholdId` via `ObjectService::saveObject`.
4. If `onCross.dataExchangeTarget` is set: record the target intent on the flag (`dataExchangeJobId=null` for now). `// TODO(data-exchange spec): queue a DataExchangeJob to dataExchangeTarget and set dataExchangeJobId`.
5. The handler does NOT auto-act against the learner — only the flag is created.

Registered in `Application.php`. Legitimate per ADR-031: new-object creation from a calculatedChange event cannot be expressed as schema metadata.

### 3.3 AttendanceFlagReportGuard (lib/Lifecycle/AttendanceFlagReportGuard.php)

Referenced from `AttendanceFlag.x-openregister-lifecycle.transitions.report.requires`.

For now: `check(array &$transitionContext): bool { return true; }` with `// TODO(data-exchange spec): verify DataExchangeJob succeeded before allowing reported`.

Not registered in `Application.php` — OR resolves guards by class name from the schema `requires:` string.

---

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

---

## 5. Threshold-crossing — NOT a TimedJob

The `unexcusedLesuren` calculation on `AttendanceThreshold` is re-evaluated by OR's engine on each `AttendanceRecord` save that references a learner covered by the threshold. When the materialised value crosses `limit`, OR fires the `calculatedChange` notification (`onThresholdCrossed`). The `AttendanceFlagCreationHandler` creates the `AttendanceFlag` in response. The `idempotencyKey` (`${@self.id}-${learnerId}-${windowStart}`) prevents duplicate flags for the same window crossing.

---

## 6. Seed Data

Seed data is loaded into `lib/Settings/scholiq_register.json` under `components.objects[]` with the `@self` envelope per ADR-001. All values are fictional but realistic Dutch school data.

### 6.1 AttendanceThreshold seed objects (4)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "AttendanceThreshold", "slug": "threshold-leerplicht-16uur" },
    "name": "Leerplicht 16-uur regel",
    "kind": "leerplicht-16uur",
    "scope": "per-learner",
    "cohortId": null,
    "window": { "type": "rolling-weeks", "weeks": 4 },
    "metric": "unexcused-lesuren",
    "limit": 16,
    "lesuurMinutes": 60,
    "onCross": { "notify": true, "notifyRoles": ["mentor", "attendance-coordinator"], "createFlag": true, "dataExchangeTarget": "leerplicht" },
    "active": true,
    "lifecycle": "active",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceThreshold", "slug": "threshold-hbo-aanwezigheid" },
    "name": "HBO college-aanwezigheid 80%",
    "kind": "college-aanwezigheid",
    "scope": "per-learner",
    "cohortId": null,
    "window": { "type": "fixed-term", "termId": "semester-1-2026" },
    "metric": "attendance-percent-below",
    "limit": 80,
    "lesuurMinutes": 60,
    "onCross": { "notify": true, "notifyRoles": ["mentor", "studieloopbaanbegeleider"], "createFlag": true, "dataExchangeTarget": null },
    "active": true,
    "lifecycle": "active",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceThreshold", "slug": "threshold-nis2-training" },
    "name": "NIS2 compliancetraining aanwezigheid",
    "kind": "compliance-presence",
    "scope": "per-learner",
    "cohortId": null,
    "window": { "type": "fixed-term", "termId": "nis2-2026-q1" },
    "metric": "unexcused-sessions",
    "limit": 1,
    "lesuurMinutes": 60,
    "onCross": { "notify": true, "notifyRoles": ["compliance-officer", "manager"], "createFlag": true, "dataExchangeTarget": null },
    "active": true,
    "lifecycle": "active",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceThreshold", "slug": "threshold-mbo-stages" },
    "name": "MBO stage-aanwezigheid 90%",
    "kind": "training-attendance",
    "scope": "per-learner",
    "cohortId": null,
    "window": { "type": "rolling-weeks", "weeks": 8 },
    "metric": "attendance-percent-below",
    "limit": 90,
    "lesuurMinutes": 60,
    "onCross": { "notify": true, "notifyRoles": ["mentor", "stagebegeleider"], "createFlag": true, "dataExchangeTarget": null },
    "active": true,
    "lifecycle": "draft",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  }
]
```

### 6.2 AttendanceRecord seed objects (4)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "AttendanceRecord", "slug": "attendance-rec-jan-2026-01-15" },
    "sessionId": "aaaaaaaa-0001-0001-0001-000000000001",
    "learnerId": "jan.jansen@canisius.nl",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "status": "present",
    "minutesAttended": 50,
    "markedBy": "leraar.berg@canisius.nl",
    "markedAt": "2026-01-15T09:00:00+01:00",
    "reason": null,
    "excuseRequestId": null,
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceRecord", "slug": "attendance-rec-maria-2026-01-22" },
    "sessionId": "aaaaaaaa-0001-0001-0001-000000000002",
    "learnerId": "maria.devries@canisius.nl",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "status": "absent-unexcused",
    "minutesAttended": null,
    "markedBy": "leraar.berg@canisius.nl",
    "markedAt": "2026-01-22T09:05:00+01:00",
    "reason": null,
    "excuseRequestId": null,
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceRecord", "slug": "attendance-rec-ahmed-2026-01-29" },
    "sessionId": "aaaaaaaa-0001-0001-0001-000000000003",
    "learnerId": "ahmed.yilmaz@canisius.nl",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "status": "late",
    "minutesAttended": 35,
    "markedBy": "leraar.berg@canisius.nl",
    "markedAt": "2026-01-29T08:52:00+01:00",
    "reason": "Treinvertraging",
    "excuseRequestId": null,
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceRecord", "slug": "attendance-rec-sophie-2026-02-05" },
    "sessionId": "aaaaaaaa-0001-0001-0001-000000000004",
    "learnerId": "sophie.vandenberg@canisius.nl",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "status": "absent-excused",
    "minutesAttended": null,
    "markedBy": "leraar.berg@canisius.nl",
    "markedAt": "2026-02-05T09:01:00+01:00",
    "reason": "Ziekte — goedgekeurd excuus",
    "excuseRequestId": "eeeeeeee-0001-0001-0001-000000000001",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  }
]
```

### 6.3 ExcuseRequest seed objects (3)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "ExcuseRequest", "slug": "excuse-sophie-2026-02-04" },
    "learnerId": "sophie.vandenberg@canisius.nl",
    "submittedBy": "ouder.vandenberg@gmail.com",
    "dateFrom": "2026-02-04",
    "dateTo": "2026-02-06",
    "reason": "Sophie heeft griep en kan niet naar school komen. Doktersverklaring bijgevoegd.",
    "reasonKind": "illness",
    "attachmentRef": "doktersverklaring-sophie-2026-02-04.pdf",
    "submittedAuthLevel": "substantial",
    "decidedBy": "coordinator.smit@canisius.nl",
    "decidedAt": "2026-02-04T14:30:00+01:00",
    "decisionNote": "Goedgekeurd op basis van doktersverklaring.",
    "lifecycle": "approved",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "ExcuseRequest", "slug": "excuse-jan-2026-03-10" },
    "learnerId": "jan.jansen@canisius.nl",
    "submittedBy": "jan.jansen@canisius.nl",
    "dateFrom": "2026-03-10",
    "dateTo": "2026-03-10",
    "reason": "Medische afspraak tandarts.",
    "reasonKind": "medical-appointment",
    "attachmentRef": null,
    "submittedAuthLevel": "basic",
    "decidedBy": null,
    "decidedAt": null,
    "decisionNote": null,
    "lifecycle": "submitted",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "ExcuseRequest", "slug": "excuse-ahmed-2026-01-08" },
    "learnerId": "ahmed.yilmaz@canisius.nl",
    "submittedBy": "ouder.yilmaz@hotmail.com",
    "dateFrom": "2026-01-08",
    "dateTo": "2026-01-08",
    "reason": "Religieuze feestdag.",
    "reasonKind": "religious-observance",
    "attachmentRef": null,
    "submittedAuthLevel": "none",
    "decidedBy": "coordinator.smit@canisius.nl",
    "decidedAt": "2026-01-08T10:00:00+01:00",
    "decisionNote": "Afgewezen — geen voorafgaand verzoek ingediend.",
    "lifecycle": "rejected",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  }
]
```

### 6.4 AttendanceFlag seed objects (3)

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "AttendanceFlag", "slug": "flag-maria-leerplicht-2026-w04" },
    "learnerId": "maria.devries@canisius.nl",
    "attendanceThresholdId": "tttttttt-0001-0001-0001-000000000001",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "windowStart": "2026-01-12",
    "windowEnd": "2026-02-09",
    "metricValue": 16,
    "breachingRecordIds": [
      "aaaaaaaa-bbbb-0001-0001-000000000001",
      "aaaaaaaa-bbbb-0001-0001-000000000002",
      "aaaaaaaa-bbbb-0001-0001-000000000003"
    ],
    "dataExchangeJobId": null,
    "mentorId": "mentor.dekker@canisius.nl",
    "lifecycle": "in-handling",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceFlag", "slug": "flag-thomas-leerplicht-2026-w08" },
    "learnerId": "thomas.bakker@canisius.nl",
    "attendanceThresholdId": "tttttttt-0001-0001-0001-000000000001",
    "cohortId": "cccccccc-0001-0001-0001-000000000001",
    "windowStart": "2026-02-09",
    "windowEnd": "2026-03-09",
    "metricValue": 18,
    "breachingRecordIds": [
      "aaaaaaaa-bbbb-0002-0001-000000000001",
      "aaaaaaaa-bbbb-0002-0001-000000000002"
    ],
    "dataExchangeJobId": "ddddddd1-0001-0001-0001-000000000001",
    "mentorId": "mentor.dekker@canisius.nl",
    "lifecycle": "reported",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  },
  {
    "@self": { "register": "scholiq", "schema": "AttendanceFlag", "slug": "flag-lisa-hbo-2026-sem1" },
    "learnerId": "lisa.oosterhout@hsu.nl",
    "attendanceThresholdId": "tttttttt-0001-0001-0001-000000000002",
    "cohortId": "cccccccc-0002-0001-0001-000000000001",
    "windowStart": "2026-02-01",
    "windowEnd": "2026-06-30",
    "metricValue": 72,
    "breachingRecordIds": [],
    "dataExchangeJobId": null,
    "mentorId": "slb.hoekstra@hsu.nl",
    "lifecycle": "open",
    "tenant_id": "11111111-1111-1111-1111-111111111111"
  }
]
```

---

## 7. Reuse Analysis

| Existing OR service / abstraction | How attendance consumes it |
|---|---|
| `ObjectService::findAll` + `saveObject` | ExcuseApprovalHandler and AttendanceFlagCreationHandler use these — the only PHP that touches OR objects; no custom persistence layer |
| `x-openregister-lifecycle` | ExcuseRequest (submitted → approved \| rejected), AttendanceThreshold (draft → active → archived), AttendanceFlag (open → in-handling → reported → resolved) — replaces any PHP state-machine service |
| `x-openregister-calculations` | `isUnexcusedAbsence`, `lesuren` on AttendanceRecord; `unexcusedLesuren` on AttendanceThreshold; `daysSinceFlag` on AttendanceFlag — replaces any `compute*` service method |
| `x-openregister-notifications` | `onThresholdCrossed` with `calculatedChange` trigger — mirrors `officerAlertOnCoverageDrop` in compliance-audit; replaces any notification-dispatch service |
| `x-openregister-relations` | AttendanceRecord↔Session/learner/cohort/excuseRequest; AttendanceFlag↔learner/threshold/cohort |
| `x-openregister-widgets` | `cohortAttendanceGrid` on AttendanceThreshold — consumed by `CnDashboardPage` without any widget-service PHP |
| OR `appendOnly: true` (ADR-022) | AttendanceFlag — same mechanism as Attestation in compliance-audit; no app-local guard needed |
| `Regulation.ragStatus` `calculatedChange` pattern | Template for `AttendanceThreshold.unexcusedLesuren` crossing detection — no parallel mechanism (ADR-022) |
| `CnAppRoot` index/detail pages | All four schemas rendered declaratively; no custom list/detail controllers or Vue pages |
| OR audit trail (ADR-008) | AttendanceFlag `appendOnly` + all lifecycle transitions — evidence log is OR's audit trail; no app-local audit substrate |

**Deduplication check**: searched `openspec/specs/` and `lib/Service/` for attendance, threshold, absence, leerplicht, excuse. No existing AttendanceRecord/AttendanceThreshold/AttendanceFlag/ExcuseRequest schemas or services found in the current codebase. The `absence-leerplicht` stub in school-structure is explicitly superseded by this change (noted in context-brief `replaces: [absence-leerplicht]`).

---

## 8. Declarative-vs-imperative decision summary

| Behaviour | Decision | ADR-031 row |
|---|---|---|
| ExcuseRequest state machine (submitted → approved \| rejected) | declarative | lifecycle |
| AttendanceThreshold state machine (draft → active → archived) | declarative | lifecycle |
| AttendanceFlag state machine (open → in-handling → reported → resolved) | declarative | lifecycle |
| isUnexcusedAbsence, lesuren per AttendanceRecord | declarative | calculation |
| unexcusedLesuren rolling sum per threshold | declarative | calculation |
| daysSinceFlag on AttendanceFlag | declarative | calculation |
| Threshold-crossing alert to mentor + coordinator | declarative | notification (calculatedChange trigger) |
| Cohort-attendance dashboard widget | declarative | widget |
| AttendanceRecord↔Session/learner/cohort/excuseRequest relations | declarative | relations |
| AttendanceFlag immutability | declarative (OR appendOnly) | consumed via ADR-022 |
| Audit trail | declarative (OR) | consumed via ADR-022 — ADR-008 |
| ExcuseRequest approval → flip AttendanceRecords | imperative (PHP) | cross-object write bridge |
| AttendanceFlag creation from calculatedChange event | imperative (PHP) | new-object creation from event — not expressible declaratively |
| AttendanceFlagReportGuard (stub) | imperative (PHP) | lifecycle guard — deferred tightening |
| DataExchangeJob queueing | deferred to data-exchange spec | forward reference |

---

## 9. Out of scope

- Digikoppeling/StUF wire protocol (data-exchange spec).
- DigiD authentication handshake (openconnector).
- Geofenced / biometric attendance capture.
- Truancy-pattern AI prediction (AiFeature).
- DataExchangeJob queueing from AttendanceFlagCreationHandler (data-exchange spec — flagged with TODO).
