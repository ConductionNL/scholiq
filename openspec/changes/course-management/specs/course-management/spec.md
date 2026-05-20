---
slug: course-management
title: Course Management
status: planned
feature_tier: must
depends_on_adrs: [ADR-001, ADR-002, ADR-008, ADR-011]
created: 2026-05-20
updated: 2026-05-20
phase: 2 — extends Phase 1 compliance-audit wedge (REQ-CM-001 through REQ-CM-008)
---

# Course Management — Formal Requirements (Phase 2)

## Overview

Course Management Phase 2 extends the Phase 1 compliance-audit wedge (Course + Lesson + XapiStatement) with the full `Course → Module → Lesson` hierarchy, ordered LearningPaths, prerequisite enforcement, ECTS workload declaration, programme-committee approval workflow, course cloning, and OOAPI 5.0 catalog publication.

Phase 1 requirements (REQ-CM-001 through REQ-CM-008) remain in force. Requirements below start at REQ-CM-009.

All state changes MUST emit audit events via OR's lifecycle engine per ADR-008. All data MUST be persisted as OpenRegister objects per ADR-001. No custom database tables are permitted.

---

## Requirements

### REQ-CM-009 — Course → Module → Lesson hierarchy

The system MUST support a three-level content hierarchy: `Course` → `Module` → `Lesson`. Modules MUST be ordered within a Course via an integer `order` field starting at 1. Lessons MUST reference a `moduleId` (introduced in this phase) in addition to their existing `courseId`.

#### Scenario CM-009-A: Module created and ordered within a Course

```
GIVEN an instructional designer is authenticated with the 'admin' or 'hr' role
WHEN they POST a Module with {courseId, name, order: 2, ects: 3}
THEN the system MUST persist the Module as an OpenRegister object under the 'Module' schema
  AND the Module MUST appear in the Course's `modules` relation at position 2
  AND the Course's calculated `moduleCount` MUST increment to reflect the new Module
  AND the Course's calculated `totalEcts` MUST include the new Module's 3 ECTS
```

#### Scenario CM-009-B: Lessons returned ordered through Module hierarchy

```
GIVEN a Course has 2 Modules (order 1 and 2) each containing 2 Lessons (order 1 and 2)
WHEN a learner GETs /api/openregister/scholiq/Lesson?courseId={id}
THEN the response MUST return all 4 Lessons
  AND lessons MUST be resolvable in module-order then lesson-order sequence via the Module relation
```

#### Scenario CM-009-C: Course publish guard blocks publish with no Modules

```
GIVEN a Course exists with lifecycle='draft' and zero Modules
WHEN an admin triggers the 'publish' lifecycle transition
THEN the system MUST reject the transition
  AND return an error indicating at least one published Module is required
  AND the Course lifecycle MUST remain 'draft'
```

---

### REQ-CM-010 — ECTS workload declaration on Modules

The system MUST support declaring ECTS credits on individual `Module` objects via an integer `ects` field. The parent `Course` MUST expose a calculated `totalEcts` field equal to the sum of ECTS across all its Modules. The `totalEcts` value MUST be included in the OOAPI 5.0 course response.

#### Scenario CM-010-A: ECTS sum reflected on Course after Module update

```
GIVEN a Course has 3 Modules with ects values of 2, 3, and 5
WHEN a GET is made to the Course's OR endpoint
THEN Course.totalEcts MUST equal 10
  AND Course.totalEcts MUST update within one OR calculation cycle when any Module's ects changes
```

#### Scenario CM-010-B: OOAPI 5.0 response includes ECTS field

```
GIVEN a published Course has totalEcts = 10 and language = 'nl' and level = 'nlqf6'
WHEN an authenticated client GETs /ooapi/v5/courses/{id}
THEN the response body MUST include:
  - "credits": 10 (mapped from totalEcts)
  - "language": "nl-NL"
  - "level": "nlqf6"
  AND the response MUST conform to the OOAPI 5.0 Course schema
```

---

### REQ-CM-011 — Course cloning (clone as draft for next academic year)

The system MUST support cloning a published Course into a new draft Course with the same Modules and Lessons, a new `academicYear` tag, zero enrolments, and a `clonedFromId` back-reference. The clone operation MUST NOT affect enrolments or xAPI statements on the source Course.

#### Scenario CM-011-A: Successful clone of published Course

```
GIVEN an instructional designer opens a published Course with academicYear='2025-2026' and 2 Modules, each with 2 Lessons
WHEN they POST to /api/courses/{id}/clone with body {academicYear: '2026-2027'}
THEN the system MUST create a new Course in lifecycle='draft' with:
  - clonedFromId = source Course UUID
  - academicYear = '2026-2027'
  - 2 new Module copies (same structure, new UUIDs, lifecycle='draft')
  - 4 new Lesson copies (same contentType + contentRef, new UUIDs, lifecycle='draft')
  AND the new Course MUST have zero enrolled learners
  AND the source Course and its enrolments MUST be unchanged
  AND HTTP 201 is returned with the new Course UUID
```

#### Scenario CM-011-B: Clone rejected for draft source Course

```
GIVEN a Course exists with lifecycle='draft'
WHEN an instructional designer POSTs to /api/courses/{id}/clone
THEN the system MUST return HTTP 422
  AND the error message MUST state that only published Courses can be cloned
```

---

### REQ-CM-012 — Prerequisite enforcement

The system MUST support defining prerequisite edges between Courses via the `Prerequisite` schema (conditionType: `completion` | `grade` | `consent`). The enrolment flow MUST consult `Prerequisite` objects; when a learner's enrolment history does not satisfy a prerequisite, the enrol action MUST be blocked and the failing prerequisite's `description` MUST be presented to the learner in plain text.

#### Scenario CM-012-A: Enrol button disabled for learner with unmet completion prerequisite

```
GIVEN Course B has a Prerequisite with sourceCourseId=Course A, conditionType='completion'
AND a learner has no completed Enrolment for Course A
WHEN the learner views the catalog entry for Course B
THEN the enrol button MUST be rendered in a disabled state
  AND the UI MUST display the Prerequisite's description text naming Course A
  AND no Enrolment object for Course B MUST be created
```

#### Scenario CM-012-B: Enrol button enabled when prerequisite is met

```
GIVEN Course B has a Prerequisite with sourceCourseId=Course A, conditionType='completion'
AND the learner has an Enrolment for Course A with lifecycle='completed'
WHEN the learner views the catalog entry for Course B
THEN the enrol button MUST be enabled
  AND the learner MAY proceed to enrol in Course B
```

#### Scenario CM-012-C: Grade prerequisite blocks enrolment below minimum grade

```
GIVEN Course B has a Prerequisite with sourceCourseId=Course A, conditionType='grade', minimumGrade=55
AND a learner has a completed Enrolment for Course A with a recorded grade of 48
WHEN the learner views Course B
THEN the enrol button MUST be rendered in a disabled state
  AND the UI MUST display the Prerequisite's description including the minimum grade threshold
```

---

### REQ-CM-013 — OOAPI 5.0 catalog publication

The system MUST expose an OOAPI 5.0 conformant catalog endpoint at `GET /ooapi/v5/courses`. The endpoint MUST return all published Courses for the authenticated institution. The response MUST include `ects` (as `credits`), `language`, `level`, and `educationSpecificationId` fields per OOAPI 5.0. The endpoint MUST be publicly accessible with Bearer-token authentication (no Nextcloud session required).

#### Scenario CM-013-A: Authenticated OOAPI query returns compliant response

```
GIVEN a published Course exists with ects=10, language='nl', level='nlqf6', ooApiEducationSpecificationId set
WHEN an HE administrator queries GET /ooapi/v5/courses with a valid Bearer token
THEN the response MUST be HTTP 200
  AND the response body MUST be valid against the OOAPI 5.0 Course schema
  AND the course entry MUST include: credits=10, language='nl-NL', level='nlqf6', educationSpecificationId
  AND draft or archived Courses MUST NOT appear in the response
```

#### Scenario CM-013-B: Unauthenticated OOAPI request rejected

```
GIVEN no Bearer token is present in the Authorization header
WHEN a client requests GET /ooapi/v5/courses
THEN the system MUST return HTTP 401
  AND no Course data MUST be returned
```

#### Scenario CM-013-C: Catalog visible in OOAPI within 5 minutes of approval

```
GIVEN a CatalogChangeRequest for Course X is in lifecycle='submitted'
WHEN a programme committee member approves it (triggers 'approve' transition)
THEN Course X's lifecycle MUST transition to 'published' via OR's lifecycle cascade
  AND within 5 minutes GET /ooapi/v5/courses MUST include Course X
  AND the OR audit trail MUST contain both 'catalogchangerequest.approved' and 'course.published' entries with matching correlation_id
```

---

### REQ-CM-014 — CatalogChangeRequest and programme-committee approval workflow

The system MUST support a `CatalogChangeRequest` schema with lifecycle `draft → submitted → approved | rejected`. Submitting a request MUST notify all users with the `programme-committee` tenant role. Approving a request MUST cascade to publish the linked Course within the same OR transaction. All lifecycle transitions MUST be recorded in the OR audit trail per ADR-008.

#### Scenario CM-014-A: Instructional designer submits a catalog change request

```
GIVEN an instructional designer is authenticated
WHEN they trigger the 'submit' transition on a CatalogChangeRequest in lifecycle='draft'
THEN the CatalogChangeRequest lifecycle MUST change to 'submitted'
  AND an OR audit entry with event_type='catalogchangerequest.submitted' MUST be created
  AND all users with the 'programme-committee' role MUST receive a Nextcloud notification
```

#### Scenario CM-014-B: Approval cascades to Course publish

```
GIVEN a CatalogChangeRequest in lifecycle='submitted' references courseId=X
WHEN a programme committee member triggers the 'approve' transition
THEN the CatalogChangeRequest lifecycle MUST change to 'approved'
  AND Course X lifecycle MUST change to 'published' in the same OR cascade
  AND OR audit entries for both 'catalogchangerequest.approved' and 'course.published' MUST exist
  AND the requesting instructional designer MUST receive an approval notification
```

#### Scenario CM-014-C: Rejected request notifies requestor

```
GIVEN a CatalogChangeRequest is in lifecycle='submitted'
WHEN a programme committee member triggers the 'reject' transition with a reviewNote
THEN the CatalogChangeRequest lifecycle MUST change to 'rejected'
  AND the reviewNote MUST be persisted on the CatalogChangeRequest object
  AND the requesting instructional designer MUST receive a rejection notification containing the reviewNote
```

---

### REQ-CM-015 — LearningPath entity

The system MUST support creating, reading, updating, and publishing `LearningPath` objects that define an ordered sequence of Courses. A LearningPath MUST expose a calculated `courseCount` field. Deleting a LearningPath MUST NOT affect the constituent Course or Enrolment objects.

#### Scenario CM-015-A: LearningPath created with ordered course sequence

```
GIVEN an instructional designer creates a LearningPath with courseIds=[CourseA.id, CourseB.id]
WHEN they GET the LearningPath from OR
THEN the LearningPath MUST persist the courseIds array in submission order
  AND LearningPath.courseCount MUST equal 2
  AND each courseId MUST resolve to a Course object via OR's relation engine
```

#### Scenario CM-015-B: LearningPath publish lifecycle

```
GIVEN a LearningPath is in lifecycle='draft'
WHEN an admin triggers the 'publish' transition
THEN the LearningPath lifecycle MUST change to 'published'
  AND an OR audit entry with event_type='learningpath.published' MUST be created
```

---

### REQ-CM-016 — Content runtime (cmi5 + xAPI + SCORM shim) — Phase 1 carried forward

The system MUST continue to satisfy REQ-CM-003 (cmi5 import and launch), REQ-CM-004 (SCORM 1.2/2004 shim), and REQ-CM-005 (xAPI LRS endpoint) from Phase 1. In Phase 2 the content launch context is enriched: the cmi5 launch token and the SCORM session MUST carry the `moduleId` of the lesson being launched alongside `courseId` and `lessonId`, so that xAPI statements can be filtered by module for ECTS progress tracking.

#### Scenario CM-016-A: cmi5 launch token includes moduleId

```
GIVEN a Lesson has moduleId set
WHEN a learner requests GET /api/lessons/{id}/launch
THEN the signed JWT launch token MUST include a context extension:
  "http://scholiq.nl/extensions/moduleId": "<moduleId>"
  AND the resulting xAPI statement persisted in the LRS MUST carry this extension in context.extensions
```

---

### REQ-CM-017 — Audit trail for all Phase 2 mutations

Every state-changing operation on `Module`, `LearningPath`, `Prerequisite`, and `CatalogChangeRequest` objects MUST emit an audit entry via OR's lifecycle engine (per ADR-008). No app-local `AuditTrail::record()` call is permitted.

#### Scenario CM-017-A: Module publication audit entry

```
GIVEN a Module is in lifecycle='draft'
WHEN an admin triggers the 'publish' transition
THEN an OR audit entry MUST exist with:
  - event_type: 'module.published'
  - subject_id: the Module UUID
  - actor_id: the authenticated user's NC uid
  - before.lifecycle: 'draft'
  - after.lifecycle: 'published'
```
