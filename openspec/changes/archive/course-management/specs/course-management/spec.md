---
slug: course-management
title: Course Management
status: planned
feature_tier: must
depends_on_adrs: [ADR-002, ADR-008]
created: 2026-05-11
updated: 2026-05-11
wedge_scope: Phase 1 — compliance-audit wedge only; HE/K-12 extensions deferred
---

# Course Management — Formal Requirements

## Overview

Course Management provides the Course, CourseSection, and Lesson entities that underpin all other wedge capabilities. For Phase 1 the scope is compliance-training delivery: video-based microlearning launched via cmi5 + xAPI (ADR-002), with a SCORM 1.2/2004 shim for legacy content. No assessment authoring, no OOAPI, no LTI in Phase 1.

All state changes MUST emit audit events per ADR-008.

---

## Requirements

### REQ-CM-001 — Course entity CRUD

The system MUST support creating, reading, updating, and archiving `Course` objects persisted as OpenRegister objects under the `scholiq-course` schema.

#### Scenario CM-001-A: Compliance officer creates a mandatory course
```
GIVEN a compliance officer is authenticated and has the 'admin' or 'hr' role
WHEN they POST to /api/courses with {code, name, level:'corporate', language:'nl', published:false}
THEN the system MUST create a Course object in OpenRegister
  AND return HTTP 201 with the created Course including its UUID
  AND emit an audit event with event_type 'course.published' (or 'course.created') per ADR-008
```

#### Scenario CM-001-B: Course soft-delete (archive)
```
GIVEN a Course with at least one active Enrolment exists
WHEN a compliance officer sends DELETE /api/courses/{id}
THEN the system MUST set deleted_at on the Course (soft delete)
  AND MUST NOT remove Enrolment or xApiStatement records linked to that course
  AND MUST emit audit event event_type 'course.archived' with before snapshot per ADR-008
```

#### Scenario CM-001-C: Learner cannot create or modify courses
```
GIVEN an authenticated user with role 'learner' only
WHEN they attempt POST /api/courses or PATCH /api/courses/{id}
THEN the system MUST return HTTP 403
  AND MUST NOT create or modify any Course object
```

---

### REQ-CM-002 — Lesson entity CRUD + content-type enforcement (ADR-002)

The system MUST support creating, reading, ordering, updating, and deleting `Lesson` objects under the `scholiq-lesson` schema. The `content_type` field MUST be one of: `text`, `video`, `scorm12`, `scorm2004`, `cmi5`.

#### Scenario CM-002-A: Lesson created with cmi5 content type
```
GIVEN a compliance officer creates a Lesson with content_type='cmi5' and a valid nc:files content_ref
WHEN the POST /api/courses/{courseId}/lessons request completes
THEN the system MUST persist the Lesson with content_type='cmi5'
  AND the Lesson's content_ref MUST point to a valid file path under /Scholiq/<tenant>/<courseId>/
```

#### Scenario CM-002-B: Lesson order is respected in learner view
```
GIVEN a Course has three Lessons with order values 1, 2, 3
WHEN a learner GETs /api/courses/{id}/lessons
THEN the response MUST return Lessons sorted ascending by order
  AND order values MUST be contiguous integers starting at 1
```

---

### REQ-CM-003 — cmi5 content import and launch (ADR-002)

The system MUST accept `.zip` packages containing a `cmi5.xml` manifest via `POST /api/courses/{id}/lessons/import`. A successful import MUST unpack the package into nc:files and create a Lesson record with content_type='cmi5'.

#### Scenario CM-003-A: Successful cmi5 package import
```
GIVEN a compliance officer uploads a valid cmi5 .zip with a cmi5.xml manifest
WHEN POST /api/courses/{id}/lessons/import processes the upload
THEN the system MUST unpack the package to /Scholiq/<tenant>/<courseId>/cmi5/<lessonId>/
  AND create a Lesson with content_type='cmi5' and content_ref pointing to the unpacked path
  AND return HTTP 201 with the created Lesson
```

#### Scenario CM-003-B: cmi5 AU launch generates signed token
```
GIVEN a learner is enrolled in a course and navigates to a cmi5 Lesson
WHEN the learner requests GET /api/lessons/{id}/launch
THEN the system MUST return a signed JWT launch token (RS256, exp ≤ 8h) containing:
  - actor (learner UUID)
  - activity_id (Lesson IRI)
  - registration UUID (per-session)
  - fetch URL (the LRS endpoint)
  AND the AU MUST use this token to authenticate xAPI statement posts to /api/lrs/statements
```

#### Scenario CM-003-C: cmi5 completion recorded in LRS
```
GIVEN a learner's AU posts a cmi5.completed xAPI statement to /api/lrs/statements
WHEN the LRS endpoint processes the statement
THEN the statement MUST be persisted as an append-only xapi_statement object in OpenRegister
  AND an audit event 'xapi.statement.received' MUST be emitted per ADR-008
  AND the response MUST be HTTP 204 (xAPI 1.0.3 conformant)
```

---

### REQ-CM-004 — SCORM 1.2/2004 compatibility shim (ADR-002)

The system MUST accept `.zip` SCORM 1.2 and SCORM 2004 packages via the same import endpoint and serve the SCORM runtime API (LMSInitialize, LMSGetValue, LMSSetValue, LMSCommit, LMSFinish, LMSGetLastError). Each SCORM runtime call that changes lesson status MUST be translated to the corresponding xAPI verb per ADR-002 §Decision (3).

#### Scenario CM-004-A: SCORM LMSSetValue lesson_status=completed maps to xAPI completed
```
GIVEN a learner is running a SCORM 1.2 lesson loaded in the SCORM shim iframe
WHEN the SCORM content calls LMSSetValue("cmi.core.lesson_status", "completed")
THEN ScormToXapiTranslator MUST post an xAPI statement with verb 'http://adlnet.gov/expapi/verbs/completed'
  AND the statement MUST be persisted in OpenRegister as an append-only xapi_statement
```

#### Scenario CM-004-B: SCORM suspend_data round-trips correctly
```
GIVEN a learner partially completes a SCORM 1.2 lesson and LMSSetValue("cmi.suspend_data", "<data>") is called
WHEN the learner relaunches the lesson
THEN LMSGetValue("cmi.suspend_data") MUST return the previously saved data
  AND the suspend_data MUST be stored in the xAPI result.extensions map per ADR-002
```

---

### REQ-CM-005 — xAPI LRS endpoint (ADR-002)

The system MUST expose an xAPI 1.0.3 conformant LRS at `POST /api/lrs/statements` and `GET /api/lrs/statements`. All statements MUST be append-only; no UPDATE or DELETE on xapi_statement objects is permitted.

#### Scenario CM-005-A: Unauthenticated LRS request rejected
```
GIVEN an unauthenticated request is sent to POST /api/lrs/statements
WHEN the LRS endpoint processes it
THEN the system MUST return HTTP 401
  AND MUST NOT persist any statement
```

#### Scenario CM-005-B: Authenticated LRS query returns actor-scoped statements
```
GIVEN a learner has 3 completed xAPI statements and an instructor has 5
WHEN the learner calls GET /api/lrs/statements?agent=<learner-uuid>
THEN the response MUST return only the 3 statements for that actor
  AND MUST NOT return any statements belonging to other actors
```

---

### REQ-CM-006 — Audit trail for all course mutations (ADR-008)

Every state-changing Course or Lesson operation MUST emit an audit event via `AuditTrail::record()` within the same DB transaction as the OpenRegister write.

#### Scenario CM-006-A: Course update audit event
```
GIVEN a compliance officer updates a Course's name via PATCH /api/courses/{id}
WHEN the update completes with HTTP 200
THEN an audit event MUST exist in OpenRegister with:
  - event_type: 'course.published' (if published=true) or a 'course.updated' subtype
  - before: the previous Course snapshot
  - after: the updated Course snapshot
  - actor_id: the authenticated user's NC uid
  - subject_id: the Course UUID
```

---

### REQ-CM-007 — Mandatory-training tag on Lessons

The system MUST support tagging a Lesson as `mandatory_training: true` with an associated `regulation_slug` (e.g. "AVG", "BIO", "NIS2"). The compliance-audit spec uses these tags to compute coverage %.

#### Scenario CM-007-A: Lesson tagged as mandatory NIS2 training
```
GIVEN a compliance officer creates a Lesson with mandatory_training=true, regulation_slug='NIS2'
WHEN GET /api/courses/{id}/lessons is called with ?mandatory_training=true&regulation_slug=NIS2
THEN the response MUST return all Lessons matching both filters
  AND the compliance-audit spec MUST be able to use this endpoint to enumerate mandatory content
```

---

### REQ-CM-008 — Content stored in nc:files

All lesson content files (cmi5 packages, SCORM packages, video files) MUST be stored in `nc:files` under `/Scholiq/<tenant_id>/<course_id>/`. The system MUST create the folder hierarchy at course-creation time using `OCP\Files\IRootFolder`.

#### Scenario CM-008-A: Folder created on course creation
```
GIVEN a new Course is created via POST /api/courses
WHEN the controller completes successfully
THEN the folder /Scholiq/<tenant_id>/<course_id>/ MUST exist in nc:files
  AND the Nextcloud file owner MUST be set to the creating user or a service account
```
