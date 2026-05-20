---
slug: attendance
title: Attendance & Threshold Reporting
status: planned
feature_tier: must
depends_on_adrs: [ADR-008, ADR-022, ADR-024, ADR-031]
created: 2026-05-20
updated: 2026-05-20
profiles: [leerplicht-16uur, college-aanwezigheid, training-attendance, compliance-presence]
replaces: [absence-leerplicht]
---

# Attendance & Threshold Reporting — Formal Requirements

## Overview

Attendance & Threshold Reporting introduces four OpenRegister schemas (`AttendanceRecord`, `ExcuseRequest`, `AttendanceThreshold`, `AttendanceFlag`) that together cover every institutional attendance-tracking need: Dutch Leerplichtwet art. 21a (16-lesuur rule), HE college-aanwezigheid, corporate compliance presence, and certification-programme hour requirements. All four schemas are declared in `lib/Settings/scholiq_register.json` using `x-openregister-*` extensions. Threshold-crossing detection reuses the same `calculatedChange` notification pattern as `Regulation.ragStatus` in the compliance-audit spec. The `AttendanceFlag` is `appendOnly: true` per ADR-008. Three PHP files are ADR-031 legitimate exceptions: `ExcuseApprovalHandler` (cross-object write on lifecycle transition), `AttendanceFlagCreationHandler` (new-object creation from calculatedChange event), and `AttendanceFlagReportGuard` (lifecycle guard stub). The leerplicht authority report is a forward reference to the `data-exchange` spec.

---

## Requirements

### REQ-AT-001 — AttendanceRecord: persistence and bulk marking

The system MUST persist one `AttendanceRecord` per (Session, learner) with `status` (present | absent-unexcused | absent-excused | late | left-early), `minutesAttended`, `markedBy`, `markedAt`, optional `reason` and `excuseRequestId`, in OpenRegister. The record is NOT appendOnly — a teacher may correct a mis-marked record; OR's update audit trail is sufficient. Bulk-marking from the `MarkAttendanceView` roster creates or updates one record per cohort member in a single user action.

#### Scenario AT-001-A: Teacher bulk-marks a Session roster

```
GIVEN a Session exists with a cohort of learners
  AND a teacher opens the MarkAttendanceView for that Session
WHEN the teacher assigns statuses (present / absent-unexcused / late / left-early)
  AND optionally enters minutesAttended for late / left-early rows
  AND clicks Save
THEN the system MUST create or update one AttendanceRecord per cohort member
  AND each record MUST carry the chosen status, minutesAttended (or null if absent), markedBy=teacher, markedAt=now
  AND OR's audit trail MUST capture the create/update event for each record
```

#### Scenario AT-001-B: Teacher corrects a mis-marked record

```
GIVEN an AttendanceRecord exists with status=absent-unexcused
  AND the teacher realises it was a mistake
WHEN the teacher opens the record and changes status to present with minutesAttended=50
  AND saves
THEN the system MUST update the AttendanceRecord (not appendOnly — update is allowed)
  AND OR's audit trail MUST record the before/after values for the update
```

#### Scenario AT-001-C: lesuren calculation

```
GIVEN an AttendanceRecord with status=late and minutesAttended=35
  AND the applicable AttendanceThreshold has lesuurMinutes=60
WHEN the OR calculation engine evaluates the record
THEN AttendanceRecord.lesuren MUST equal 35 / 60 ≈ 0.58
  AND AttendanceRecord.isUnexcusedAbsence MUST equal false (status is not absent-unexcused)
```

---

### REQ-AT-002 — ExcuseRequest: lifecycle and approved record flip

The system MUST support an `ExcuseRequest` submitted by a learner, parent, or 18+ learner-self, covering a date range, with a `reasonKind` enum and optional attachment. The request lifecycle is `submitted → approved | rejected`. On approval, the `ExcuseApprovalHandler` MUST flip all matching `AttendanceRecord`s (same learner, `markedAt` within `[dateFrom, dateTo]`, status `absent-unexcused`) to `absent-excused` and set their `excuseRequestId`. The DigiD handshake is out of scope; the `submittedAuthLevel` field records the eIDAS assurance level declaratively.

#### Scenario AT-002-A: Learner submits a sick-note excuse

```
GIVEN a learner is authenticated in the Nextcloud app
WHEN they open the SubmitExcuseModal and enter dateFrom=2026-03-10, dateTo=2026-03-12, reason="Griep", reasonKind=illness
  AND click Submit (without DigiD flow)
THEN the system MUST POST an ExcuseRequest with lifecycle=submitted, submittedBy=currentUser, submittedAuthLevel=basic
  AND the ExcuseRequest MUST be visible in the ExcuseRequests index page with status submitted
```

#### Scenario AT-002-B: Coordinator approves an ExcuseRequest and AttendanceRecords flip

```
GIVEN an ExcuseRequest exists for learnerId=jan.jansen with dateFrom=2026-03-10, dateTo=2026-03-12, lifecycle=submitted
  AND AttendanceRecords exist for jan.jansen on 2026-03-10 (absent-unexcused), 2026-03-11 (absent-unexcused), 2026-03-12 (absent-unexcused)
WHEN a coordinator transitions the ExcuseRequest to approved
THEN ExcuseApprovalHandler MUST query all AttendanceRecords for jan.jansen with status=absent-unexcused
  AND filter to those with markedAt within [2026-03-10, 2026-03-12]
  AND update each matching record: status=absent-excused, excuseRequestId=<the request's id>
  AND the affected AttendanceThreshold.unexcusedLesuren MUST recompute (OR calculation engine re-evaluates)
```

#### Scenario AT-002-C: Coordinator rejects an ExcuseRequest

```
GIVEN an ExcuseRequest with lifecycle=submitted and reasonKind=other, no attachmentRef
WHEN a coordinator transitions the ExcuseRequest to rejected with decisionNote="Onvoldoende onderbouwing"
THEN the ExcuseRequest lifecycle MUST move to rejected
  AND no AttendanceRecords MUST be flipped
  AND the coordinator's decision MUST be visible on the ExcuseRequestDetail page
```

---

### REQ-AT-003 — AttendanceThreshold: configuration and crossing detection

The system MUST support configuring an `AttendanceThreshold` rule with `kind`, `scope` (per-learner / per-cohort), `window` (rolling-weeks or fixed-term), `metric` (unexcused-lesuren | unexcused-sessions | attendance-percent-below), `limit`, and `onCross` actions. Crossing detection MUST be a declared `x-openregister-calculations` + `calculatedChange` notification trigger on the threshold schema — the same pattern as `Regulation.ragStatus` in the compliance-audit spec. A PHP `TimedJob` is explicitly prohibited (ADR-022).

#### Scenario AT-003-A: Configure the leerplicht-16uur threshold

```
GIVEN a coordinator creates an AttendanceThreshold with kind=leerplicht-16uur, metric=unexcused-lesuren, limit=16, window={type:rolling-weeks, weeks:4}, onCross.notifyRoles=[mentor,attendance-coordinator], onCross.createFlag=true, onCross.dataExchangeTarget=leerplicht
WHEN the threshold is saved with lifecycle=active
THEN the schema's x-openregister-calculations.unexcusedLesuren MUST begin evaluating against all AttendanceRecords for learners in scope
  AND the x-openregister-notifications.onThresholdCrossed MUST be armed with idempotencyKey=${threshold.id}-${learnerId}-${windowStart}
```

#### Scenario AT-003-B: Threshold crossing triggers flag creation

```
GIVEN an AttendanceThreshold (kind=leerplicht-16uur, limit=16, window=rolling-4-weeks) is active
  AND a learner has accumulated 15 unexcused lesuren in the rolling window
WHEN a new AttendanceRecord is saved for that learner with status=absent-unexcused and lesuren=1.0
  AND the OR engine recomputes unexcusedLesuren = 16 (>= limit)
THEN OR MUST fire the calculatedChange event on the threshold
  AND AttendanceFlagCreationHandler MUST create an AttendanceFlag with learnerId, attendanceThresholdId, windowStart, windowEnd, metricValue=16, breachingRecordIds, lifecycle=open
  AND the onCross notification MUST fire to mentor + attendance-coordinator roles
```

#### Scenario AT-003-C: Idempotency prevents duplicate flags for the same window

```
GIVEN an AttendanceFlag already exists for learnerId=X, attendanceThresholdId=Y, windowStart=2026-01-12 (lifecycle=open)
WHEN the learner gains one more unexcused absence in the same rolling window (unexcusedLesuren increases from 16 to 17)
  AND OR fires another calculatedChange event
THEN the onThresholdCrossed notification MUST NOT fire (idempotencyKey matches an existing flag)
  AND NO second AttendanceFlag MUST be created for the same window
```

---

### REQ-AT-004 — AttendanceFlag: workflow and external report

`AttendanceFlag` MUST be `appendOnly: true` per ADR-008. Its lifecycle (`open → in-handling → reported → resolved`) tracks the mentor's intervention and any outbound report to an external authority. The `AttendanceFlagReportGuard` guards the `report` transition; for Phase 1 it returns `true` unconditionally (stub). When `onCross.dataExchangeTarget` is set, the flag MUST record the intent; actual `DataExchangeJob` queueing is deferred to the `data-exchange` spec.

#### Scenario AT-004-A: Flag is created open and is read-only in the UI

```
GIVEN an AttendanceFlag was created by AttendanceFlagCreationHandler with lifecycle=open
WHEN a coordinator opens the AttendanceFlagDetail page
THEN the page MUST render readOnly=true (appendOnly schema — no edit actions)
  AND the flag's breachingRecordIds, windowStart, windowEnd, metricValue MUST be displayed
  AND the only permitted action MUST be a lifecycle transition button (in-handling)
```

#### Scenario AT-004-B: Mentor moves flag through workflow

```
GIVEN an AttendanceFlag with lifecycle=open
WHEN a mentor transitions it to in-handling (records that intervention has begun)
  AND later a coordinator transitions it to reported (after the external report is submitted)
  AND finally resolves it
THEN the lifecycle MUST progress open → in-handling → reported → resolved
  AND OR's audit trail MUST capture each transition (actor, timestamp, from, to)
  AND the flag object MUST NOT be deletable or editable (appendOnly enforced by OR)
```

#### Scenario AT-004-C: DataExchangeTarget intent is recorded on the flag

```
GIVEN an AttendanceThreshold with onCross.dataExchangeTarget=leerplicht
WHEN an AttendanceFlag is created by AttendanceFlagCreationHandler
THEN the flag MUST be created with dataExchangeJobId=null (job not yet queued — data-exchange spec TODO)
  AND a comment // TODO(data-exchange spec) MUST be present in AttendanceFlagCreationHandler.php
  AND the flag's dataExchangeJobId field MUST remain null until the data-exchange spec lands
```

---

### REQ-AT-005 — Mentor cohort attendance widget

The system MUST provide a `cohort-attendance-grid` dashboard widget declared via `x-openregister-widgets` on the `AttendanceThreshold` schema. The widget MUST display all learners in the mentor's cohort with their current `unexcusedLesuren` count against each active threshold, sorted by proximity to the limit (most at-risk first).

#### Scenario AT-005-A: Mentor views cohort attendance widget

```
GIVEN a mentor is authenticated
  AND has learners in cohort Klas-3A, of which 3 have unexcusedLesuren counts of 14, 8, 2 against a limit of 16
WHEN the mentor opens a dashboard page containing the cohort-attendance widget
THEN the widget MUST list learners sorted descending by (metricValue / limit): 14/16, 8/16, 2/16
  AND each row MUST show learner name, current unexcusedLesuren, limit, and proximity indicator
  AND learners at or above the limit MUST be visually flagged
```

#### Scenario AT-005-B: Widget reflects real-time calculation updates

```
GIVEN a mentor's cohort-attendance widget shows learner Jan at 14/16
WHEN a new AttendanceRecord is saved for Jan with status=absent-unexcused (lesuren=1.0)
  AND OR recomputes unexcusedLesuren=15
WHEN the mentor refreshes the widget
THEN Jan's count MUST show 15/16
  AND the widget MUST NOT require a page reload to reflect the calculation update (polling or reactive query)
```

---

### REQ-AT-006 — Declarative implementation constraints

All threshold-crossing detection, lifecycle management, notification dispatch, and widget definition MUST be declared in `lib/Settings/scholiq_register.json` using `x-openregister-*` extensions. No PHP `TimedJob` class may be introduced for attendance-threshold evaluation. `AttendanceFlag` MUST use `appendOnly: true` consumed from OR — no app-local immutability guard.

#### Scenario AT-006-A: No TimedJob class exists after implementation

```
GIVEN the attendance change is fully implemented
WHEN the codebase is searched for files matching lib/Job/*Attendance*.php or lib/Job/*Threshold*.php
THEN no such files MUST exist
  AND the threshold-crossing detection MUST be verifiable solely from the x-openregister-calculations + x-openregister-notifications blocks on AttendanceThreshold in scholiq_register.json
```

#### Scenario AT-006-B: AttendanceFlag cannot be modified or deleted

```
GIVEN an AttendanceFlag object exists in OpenRegister
WHEN any code path calls ObjectService::updateObject or ObjectService::deleteObject on it
THEN OR MUST reject the call (appendOnly: true enforcement)
  AND the flag MUST remain unchanged
  AND no app-local PHP guard class for immutability MUST exist (consumed from OR per ADR-022)
```

---

### REQ-AT-007 — Frontend pages and custom views

The system MUST declare `src/manifest.json` index + detail pages for all four schemas. `MarkAttendanceView` (Session roster bulk-mark grid) and `SubmitExcuseModal` (excuse submission with DigiD placeholder) MUST be implemented as custom Vue components registered via `customComponents`. No PHP CRUD controllers may be introduced.

#### Scenario AT-007-A: Manifest validates with zero errors

```
GIVEN the attendance manifest pages are added to src/manifest.json
WHEN npm run check:manifest is executed
THEN the manifest MUST validate against the canonical schema with 0 Ajv errors
  AND all 10 pages (8 standard + 2 custom) MUST be present and well-formed
```

#### Scenario AT-007-B: MarkAttendanceView renders and saves roster

```
GIVEN a teacher navigates to /sessions/:sessionId/attendance
WHEN MarkAttendanceView loads the Session and its cohort roster
  AND the teacher marks all learners present and clicks Save
THEN one AttendanceRecord per cohort member MUST be created or updated via POST/PUT to the OR API
  AND no PHP controller endpoint on the Scholiq app side MUST handle the save (direct OR API calls)
```

#### Scenario AT-007-C: SubmitExcuseModal submits an ExcuseRequest

```
GIVEN a learner or parent navigates to /attendance/excuses/submit
WHEN they fill in dateFrom, dateTo, reason, reasonKind and click Submit
THEN SubmitExcuseModal MUST POST an ExcuseRequest to the OR API with lifecycle=submitted
  AND if the DigiD flow toggle is selected, submittedAuthLevel MUST be substantial with a placeholder banner shown
  AND no PHP controller on the Scholiq app MUST handle the submission
```
