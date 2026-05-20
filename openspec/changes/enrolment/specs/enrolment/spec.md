---
slug: enrolment
title: Enrolment
status: planned
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-006, ADR-008]
created: 2026-05-20
updated: 2026-05-20
wedge_scope: |
  Phase 1 (corporate/government bulk-enrol, mandatory flag, T-30/T-7/T-1 reminders) is archived.
  This spec adds: Studielink auto-enrolment (HE), 30-60-90 onboarding templates, EnrolmentRule declarative
  triggers, prerequisite enforcement, and team-bulk-enrol with shared deadline.
---

# Enrolment — Formal Requirements

## Overview

Enrolment links learner identities to courses and learning paths. This extended spec builds on the Phase 1
foundation (single + bulk enrolment, mandatory flag, due-date notifications) and adds: automated Studielink
intake handling for higher education, 30-60-90 onboarding templates applied on hire, declarative
`EnrolmentRule` objects for trigger-based auto-enrolment, and prerequisite/eligibility enforcement.

All state changes emit audit events via OpenRegister's audit-trail abstraction (ADR-008). No parallel
Scholiq-side audit substrate.

---

## Requirements

### REQ-ENR-001 — Studielink auto-enrolment via Edukoppeling adapter

The system MUST create a `LearnerProfile` + `Enrolment` automatically when an `openconnector.studielink.intake.received` event is received from the OpenConnector Edukoppeling adapter. The LMS account MUST be provisioned within 60 seconds of the event being processed.

#### Scenario ENR-001-A: Successful Studielink intake creates learner and enrolment

```
GIVEN the OpenConnector Edukoppeling adapter publishes an openconnector.studielink.intake.received event
  AND the event payload contains a valid student identifier, programme-id, and intake date
  AND the programme-id maps to an active Course in Scholiq
WHEN StudielinkEnrolmentHandler processes the event
THEN the system MUST idempotently create or update a LearnerProfile with the student's identity attributes
  AND create an Enrolment with source=studielink, lifecycle=pending, and dueDate set from the intake deadline
  AND transition the Enrolment to lifecycle=active (prerequisitesMet check passes for intake enrolments)
  AND set lmsProvisionedAt within 60 seconds of the event timestamp
  AND emit audit event enrolment.created and enrolment.activated per ADR-008
  AND dispatch a scholiq.enrolment.activated notification to the learner via OR notifications
```

#### Scenario ENR-001-B: Duplicate Studielink intake is idempotent

```
GIVEN a LearnerProfile and active Enrolment already exist for the same student + programme
WHEN a second openconnector.studielink.intake.received event arrives for the same student + programme
THEN the system MUST NOT create a duplicate LearnerProfile or Enrolment
  AND MUST return without error (idempotent operation)
  AND MUST log a warning in OR's audit trail noting the duplicate intake was suppressed
```

#### Scenario ENR-001-C: Unknown programme-id in Studielink intake

```
GIVEN the Studielink intake contains a programme-id that has no matching published Course in Scholiq
WHEN StudielinkEnrolmentHandler processes the event
THEN the system MUST NOT create a LearnerProfile or Enrolment
  AND MUST emit an error audit entry with event_type=enrolment.studielink.unmatched-programme
  AND MUST dispatch an alert notification to the tenant admin role
```

---

### REQ-ENR-002 — 30-60-90 onboarding template application on hire

The system MUST apply a matching `OnboardingTemplate` when a new hire's `LearnerProfile` is created, creating `Enrolment` objects for each milestone day with calculated `dueDate = hireDate + milestoneDay`.

#### Scenario ENR-002-A: New hire triggers onboarding milestone enrolments

```
GIVEN an active OnboardingTemplate exists with roleSlug='medewerker' and milestones on days 1, 30, 60, 90
  AND an active EnrolmentRule exists with triggerEvent=hire, audienceType=role, audienceValue='medewerker'
    AND onboardingTemplateId pointing to the above template
WHEN a new LearnerProfile is created with roleSlug='medewerker'
THEN OnboardingTemplateApplicator MUST create one Enrolment per milestone per course in that milestone
  AND each Enrolment MUST have source=system, onboardingTemplateId set, and onboardingMilestoneDay set
  AND each Enrolment MUST have dueDate = learnerProfile.hireDate + milestoneDay (in calendar days)
  AND mandatory=true for milestones where the template milestone.mandatory=true
  AND emit enrolment.created audit events per ADR-008 for each created Enrolment
```

#### Scenario ENR-002-B: No matching template — no enrolments created

```
GIVEN a new LearnerProfile is created with roleSlug='extern-adviseur'
  AND no active OnboardingTemplate exists for roleSlug='extern-adviseur'
WHEN OnboardingTemplateApplicator processes the learner.profile.created event
THEN the system MUST NOT create any Enrolment objects
  AND MUST NOT raise an error
```

#### Scenario ENR-002-C: Department-scoped template applied correctly

```
GIVEN two active OnboardingTemplates exist: one for roleSlug='medewerker' (no departmentSlug)
  AND one for roleSlug='medewerker' with departmentSlug='ict'
WHEN a LearnerProfile is created with roleSlug='medewerker' and departmentSlug='ict'
THEN ONLY the department-scoped template (departmentSlug='ict') MUST be applied
  AND the generic template MUST NOT be applied
```

---

### REQ-ENR-003 — Prerequisite enforcement at enrolment activation

The system MUST validate that all prerequisite courses declared on a `Course` object have been completed by the learner before allowing an `Enrolment` to transition from `pending` to `active`. If prerequisites are unmet, the transition MUST be blocked with a structured error listing the missing prerequisites.

#### Scenario ENR-003-A: Enrolment blocked when prerequisite not completed

```
GIVEN a Course named 'Gevorderd AVG' has prerequisiteCourseIds: ['<uuid-basis-avg>']
  AND a learner has NO completed Enrolment in 'Basis AVG' (<uuid-basis-avg>)
WHEN the system attempts to transition an Enrolment for 'Gevorderd AVG' from pending to active
THEN PrerequisiteCheckGuard MUST block the transition
  AND MUST return HTTP 422 with body:
    { "blocked": true, "missing": [{ "courseId": "<uuid-basis-avg>", "title": "Basis AVG" }] }
  AND MUST NOT create a persisted active Enrolment
  AND MUST set prerequisitesMet=false on the pending Enrolment
```

#### Scenario ENR-003-B: Enrolment activates when all prerequisites are completed

```
GIVEN a Course 'Gevorderd AVG' has prerequisiteCourseIds: ['<uuid-basis-avg>']
  AND the learner HAS a completed Enrolment (lifecycle=completed) in 'Basis AVG'
WHEN the system activates the Enrolment for 'Gevorderd AVG'
THEN PrerequisiteCheckGuard MUST allow the transition
  AND the Enrolment MUST transition to lifecycle=active
  AND prerequisitesMet MUST be set to true
  AND emit enrolment.activated audit event per ADR-008
```

#### Scenario ENR-003-C: Courses without prerequisites always pass the guard

```
GIVEN a Course has no prerequisiteCourseIds (empty array or null)
WHEN any learner's Enrolment for that Course is activated
THEN PrerequisiteCheckGuard MUST allow the transition unconditionally
  AND prerequisitesMet MUST be set to true
```

---

### REQ-ENR-004 — Bulk enrolment by audience with shared deadline (line manager)

The system MUST support a line manager enrolling multiple learners in a course simultaneously with a single shared deadline. All selected learners MUST be enrolled in a single operation via OR's batch endpoint. The manager MUST see a real-time team progress bar after submission.

#### Scenario ENR-004-A: Line manager bulk-enrols direct reports

```
GIVEN a line manager opens TeamBulkEnrolModal
  AND selects 5 direct reports, a published course, mandatory=true, and a shared dueDate
WHEN they submit the bulk-enrolment form
THEN the modal MUST POST to OR's batch endpoint POST /api/openregister/scholiq/Enrolment/batch
  AND create 5 Enrolment objects with source=manager, managerId=<current-user>, shared dueDate, bulkJobId
  AND each Enrolment that passes prerequisite check MUST transition to lifecycle=active
  AND the modal MUST poll GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid> and render a progress bar
  AND each enrolled learner MUST receive a scholiq.enrolment.activated notification
```

#### Scenario ENR-004-B: Learners already enrolled are skipped in bulk operation

```
GIVEN 3 of the 5 selected learners already have an active or completed Enrolment in the target course
WHEN the bulk enrolment is submitted
THEN the batch endpoint MUST create 2 new Enrolments (skipping the 3 duplicates)
  AND the progress summary returned by polling MUST include: { enrolled: 2, skipped: 3, failed: 0 }
```

#### Scenario ENR-004-C: Bulk enrolment via CSV upload

```
GIVEN a compliance officer uploads a CSV file containing 50 NC user ids via TeamBulkEnrolModal
  AND 48 user ids are valid active users
  AND 2 user ids do not exist in NC IUserManager
WHEN the bulk-enrolment is submitted
THEN the system MUST create up to 48 Enrolment objects for the valid users
  AND the summary MUST show { enrolled: X, skipped: Y, failed: 2 } where X+Y=48
  AND the 2 invalid user ids MUST appear in a failed-ids list in the modal
```

---

### REQ-ENR-005 — EnrolmentRule declarative triggers

The system MUST evaluate active `EnrolmentRule` objects and automatically create Enrolment objects when a matching trigger event occurs. Rules MUST support trigger types: `hire`, `studielink-intake`, `certificate-expiry`, and `cohort-activate`.

#### Scenario ENR-005-A: Certificate-expiry trigger creates renewal enrolment

```
GIVEN an active EnrolmentRule with triggerEvent=certificate-expiry, audienceType=all,
  courseIds=['<uuid-avg-herhalingscursus>'], dueDays=30
  AND a learner's Credential for AVG has expiresAt = today + 30 days
  AND the certification spec's expiry-detection mechanism emits credential.expiry.detected
WHEN the event is processed and the active EnrolmentRule is evaluated
THEN the system MUST create an Enrolment with courseId=<uuid-avg-herhalingscursus>,
  source=system, mandatory=true, dueDate = today + 30
  AND emit enrolment.created per ADR-008
  AND dispatch a scholiq.enrolment.renewal.due notification to the learner
```

#### Scenario ENR-005-B: Inactive EnrolmentRule is not evaluated

```
GIVEN an EnrolmentRule with lifecycle=archived exists for triggerEvent=hire
WHEN a new LearnerProfile is created
THEN the archived rule MUST NOT trigger any Enrolment creation
```

---

### REQ-ENR-006 — Unenrolment with reason capture

The system MUST support withdrawing an Enrolment with a mandatory reason. Withdrawn Enrolments MUST be retained in OpenRegister (soft-delete, lifecycle=withdrawn) for audit purposes.

#### Scenario ENR-006-A: Admin withdraws an active enrolment

```
GIVEN an active Enrolment exists for a learner
WHEN an admin or HR officer transitions the Enrolment to lifecycle=withdrawn
  AND provides a non-empty reason string
THEN the Enrolment MUST transition to lifecycle=withdrawn with reason stored
  AND the Enrolment MUST remain queryable via GET /api/openregister/scholiq/Enrolment/{id}
  AND emit enrolment.withdrawn audit event with before/after snapshots per ADR-008
  AND dispatch a scholiq.enrolment.withdrawn notification to the learner
```

#### Scenario ENR-006-B: Withdrawal of Studielink enrolment also emits eduPersonAffiliation update

```
GIVEN an Enrolment with source=studielink is withdrawn
WHEN the withdrawal is persisted
THEN the system MUST queue an eduPersonAffiliation-removal event for the OpenConnector adapter
  AND the learner's LMS access MUST be deprovisioned (OR lifecycle extension point)
```

---

### REQ-ENR-007 — LMS account provisioning SLA (HE Studielink flow)

For enrolments with `source=studielink`, the system MUST provision an LMS account and set `lmsProvisionedAt` within 60 seconds of the `openconnector.studielink.intake.received` event being processed.

#### Scenario ENR-007-A: LMS provisioned within SLA

```
GIVEN a Studielink intake event is received and a valid Enrolment is created
WHEN the lms.account.provision background job completes
THEN lmsProvisionedAt on the Enrolment MUST be set to a timestamp
  AND the elapsed time from event receipt to lmsProvisionedAt MUST be ≤ 60 seconds
  AND the learner MUST be able to access the LMS immediately after lmsProvisionedAt is set
```

#### Scenario ENR-007-B: LMS provisioning failure is auditable

```
GIVEN a Studielink intake event is received
  AND the LMS account provisioning job fails (e.g. LMS unavailable)
WHEN the job fails after 3 retries
THEN lmsProvisionedAt MUST remain null
  AND an error audit entry MUST be emitted with event_type=enrolment.lms.provision.failed
  AND a notification MUST be dispatched to the tenant admin to investigate
```
