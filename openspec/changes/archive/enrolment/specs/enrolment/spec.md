---
slug: enrolment
title: Enrolment
status: planned
feature_tier: must
depends_on_adrs: [ADR-008]
created: 2026-05-11
updated: 2026-05-11
wedge_scope: Phase 1 — corporate/government compliance bulk-enrol; HE Studielink deferred to Phase 2
---

# Enrolment — Formal Requirements

## Overview

Enrolment links learner identities (NC users) to course sections for compliance training. Phase 1 scope: individual and bulk enrolment, mandatory flag, due_date, source tracking, and T-30/T-7/T-1 notification dispatch. All state changes emit audit events per ADR-008.

---

## Requirements

### REQ-EN-001 — Single learner enrolment

The system MUST support creating an `Enrolment` object linking a single NC user to a `CourseSection`. The Enrolment MUST capture: learner_id, course_section_id, status (pending/active), mandatory bool, due_date, source, and enrolled_at.

#### Scenario EN-001-A: Compliance officer manually enrols one employee
```
GIVEN a compliance officer is authenticated with role 'admin' or 'hr'
WHEN they POST to /api/enrolments with {learner_id, course_section_id, mandatory:true, due_date:'2026-12-31', source:'manager'}
THEN the system MUST create an Enrolment with status='active'
  AND return HTTP 201 with the Enrolment including its UUID
  AND emit audit event 'enrolment.created' with after snapshot per ADR-008
  AND dispatch a 'course_enrolled' notification to the learner via OCP\Notification\IManager
```

#### Scenario EN-001-B: Learner cannot enrol another learner
```
GIVEN an authenticated user with role 'learner' only
WHEN they POST to /api/enrolments with another user's learner_id
THEN the system MUST return HTTP 403
  AND MUST NOT create any Enrolment object
```

#### Scenario EN-001-C: Duplicate enrolment rejected
```
GIVEN a learner already has an active Enrolment in a given CourseSection
WHEN a second POST to /api/enrolments is attempted for the same learner + section
THEN the system MUST return HTTP 409 Conflict
  AND MUST NOT create a duplicate Enrolment
```

---

### REQ-EN-002 — Bulk enrolment by audience (wedge core)

The system MUST support bulk-enrolment via `POST /api/enrolments/bulk` accepting an audience definition and creating individual Enrolment objects for all matching active users. Audience definitions MUST support: NC group id, NC user role, department string, or CSV of NC user ids.

#### Scenario EN-002-A: Bulk-enrol all employees in annual AVG refresher
```
GIVEN a compliance officer selects audience={nc_group_id:'all-employees'}, course_section_id, mandatory:true, due_date:'2026-11-30'
WHEN they POST to /api/enrolments/bulk
THEN the system MUST resolve the NC group to its current member list via IGroupManager
  AND create one Enrolment per active member who does not already have an active Enrolment in that section
  AND return HTTP 202 with a job_id for async status polling
  AND emit audit event 'enrolment.created' per individual Enrolment (or one batch event with correlation_id) per ADR-008
  AND dispatch 'cohort_enrolment_done' notification to the initiating compliance officer when all Enrolments are created
```

#### Scenario EN-002-B: Bulk-enrol skips already-enrolled learners
```
GIVEN 10 employees are being bulk-enrolled and 3 already have active Enrolments in the target section
WHEN POST /api/enrolments/bulk runs
THEN the system MUST create 7 new Enrolments
  AND MUST NOT create duplicate Enrolments for the 3 already-enrolled learners
  AND the response MUST include a summary: {enrolled: 7, skipped: 3, failed: 0}
```

#### Scenario EN-002-C: Bulk-enrol via CSV upload
```
GIVEN a compliance officer uploads a CSV file with 50 NC user ids
WHEN the bulk-enrolment endpoint processes the CSV
THEN the system MUST parse the CSV, resolve each user id against IUserManager::userExists()
  AND skip any user id that does not exist, recording it in the summary.failed count
  AND create Enrolments for all valid, active users
```

---

### REQ-EN-003 — Mandatory flag and due_date

The system MUST support setting `mandatory: true` and `due_date` on any Enrolment. Mandatory enrolments MUST be surfaced distinctly in learner views and dashboard KPIs.

#### Scenario EN-003-A: Mandatory enrolment appears in learner's mandatory list
```
GIVEN a learner has one optional Enrolment and one mandatory Enrolment with due_date in 14 days
WHEN the learner calls GET /api/enrolments?learner_id=me&mandatory=true
THEN the response MUST return only the mandatory Enrolment
  AND the due_date MUST be present in the response
```

#### Scenario EN-003-B: Overdue mandatory enrolment triggers escalation notification
```
GIVEN a mandatory Enrolment has due_date = today and status = 'active' (not completed)
WHEN the daily EnrolmentDueReminderJob runs at midnight UTC
THEN the system MUST dispatch an 'assignment_overdue' notification to both the learner and the learner's manager_id (if set)
  AND update the Enrolment status to 'overdue'
  AND emit audit event 'enrolment.overdue' per ADR-008
```

---

### REQ-EN-004 — Source tracking

Every Enrolment MUST record its `source` field as one of: `self`, `manager`, `hr`, `bulk`, `migrated`. The compliance-audit spec uses source to distinguish auto-enrolments from manually assigned ones in evidence packs.

#### Scenario EN-004-A: Bulk-enrolment source recorded
```
GIVEN a bulk-enrolment is created via POST /api/enrolments/bulk
WHEN the individual Enrolments are persisted
THEN each Enrolment MUST have source='bulk'
  AND the compliance-audit evidence export MUST be able to filter by source
```

---

### REQ-EN-005 — Enrolment completion transition (integration with course-management)

When a learner's xAPI statement with verb `completed` or `passed` is received for a Lesson that is the final lesson of a mandatory CourseSection, the system MUST transition the Enrolment status from `active` to `completed` and set `completed_at`.

#### Scenario EN-005-A: Final lesson completion closes enrolment
```
GIVEN a learner has an active Enrolment in a CourseSection with 1 mandatory Lesson
  AND the learner's LRS receives an xAPI 'completed' statement for that Lesson
WHEN the EnrolmentCompletionListener processes the xapi.statement.received audit event
THEN the Enrolment MUST transition to status='completed' with completed_at=now()
  AND emit audit event 'enrolment.completed' per ADR-008
  AND dispatch 'course_enrolled' (completion variant) notification to learner
  AND if a Credential issuance rule is defined, trigger certification/credential-issue flow
```

---

### REQ-EN-006 — Enrolment withdrawal

The system MUST support withdrawing an Enrolment with a mandatory reason capture. Withdrawn Enrolments MUST be retained in OpenRegister (soft state, status='withdrawn') for audit purposes.

#### Scenario EN-006-A: Compliance officer withdraws an enrolment
```
GIVEN an active Enrolment exists for a learner
WHEN a compliance officer sends PATCH /api/enrolments/{id} with {status:'withdrawn', reason:'employee left'}
THEN the Enrolment status MUST be set to 'withdrawn'
  AND the reason MUST be stored
  AND the Enrolment MUST remain queryable in OpenRegister (not deleted)
  AND emit audit event 'enrolment.withdrawn' with before/after snapshots per ADR-008
```

---

### REQ-EN-007 — T-30/T-7/T-1 due-date notifications (ADR-008)

The system MUST dispatch `compliance_due` notifications to learners and managers at T-30, T-7, and T-1 days before `due_date` for mandatory Enrolments that are not yet `completed` or `withdrawn`.

#### Scenario EN-007-A: T-30 reminder dispatched
```
GIVEN a mandatory Enrolment has due_date exactly 30 days in the future and status='active'
WHEN the daily EnrolmentDueReminderJob runs
THEN the system MUST dispatch a 'compliance_due' notification to the learner
  AND if manager_id is set, MUST dispatch the same notification to the manager
  AND MUST NOT dispatch the notification again the following day (idempotency: track dispatched_at per threshold)
```

#### Scenario EN-007-B: No reminder for completed enrolments
```
GIVEN a mandatory Enrolment with due_date in 7 days has status='completed'
WHEN the daily EnrolmentDueReminderJob runs
THEN the system MUST NOT dispatch any notification for this Enrolment
```
