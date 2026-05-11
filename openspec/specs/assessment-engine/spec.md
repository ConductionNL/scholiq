---
slug: assessment-engine
title: Assessment Engine
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-004, adr-005]   # TODO until ADRs land
created: 2026-05-11
---

# Assessment Engine
 
## Why
Insight #15: "No open-source Dutch assessment platform exists — Cito/DiatOets/IEP all proprietary." The 4 critical-priority assessment-examination stories (item-bank import, online proctored exam, conflict detection, configurable proctoring) prove the demand is concrete, not theoretical. IMS QTI 3.0 is the lock-in escape hatch — every other vendor either ignores it or offers partial support.

## What
Native authoring, import, and export of test items in IMS QTI 3.0; reusable item banks; exam definitions composed from item banks; exam scheduling with room and slot allocation; conflict detection across student timetables; equipment- and capacity-aware room suggestion; ICS / LMS calendar sync of sittings; approved extra-time accommodations applied automatically.

## User Stories
- As an assessment expert, I want to import and export item banks in IMS QTI 3.0 so I am not locked into one assessment platform.
- As an examination office planner, I want Scholiq to flag students with overlapping exam timeslots so I can resolve clashes before publishing.
- As a planner, I want Scholiq to suggest rooms that match exam type, capacity and required equipment so I do not need to check each room manually.
- As a student with a registered learning disability, I want my approved extra time to be applied automatically so I do not need to remind staff each session.
- As a student, I want exams to appear in my LMS calendar and personal ICS feed so I do not miss a sitting.

## Acceptance Criteria
- GIVEN an item bank file in QTI 3.0 ZIP format, WHEN imported, THEN every item validates against the QTI 3.0 schema and is browsable in the bank UI.
- GIVEN an exam timetable is generated, WHEN any two of a student's exams overlap, THEN the planner sees a red conflict badge and cannot publish until resolved or overridden.
- GIVEN a learner has an approved extra-time accommodation, WHEN their session starts, THEN the timer is extended by the configured factor without staff intervention.
- GIVEN an exam is scheduled, WHEN the schedule publishes, THEN the sitting appears in the learner's Nextcloud calendar via `OCP\Calendar\IManager`.

## Requirements
- The system MUST consume and produce IMS QTI 3.0 item banks losslessly.
- The system MUST detect timetable conflicts across the learner's full enrolment set before publishing.
- The system MUST apply registered accommodations (extra time, separate room, screen reader) automatically per session.

## Standards
IMS QTI 3.0 (1EdTech), Caliper Analytics, LTI 1.3 (proxied launch), Schema.org `EducationEvent`, ICS (RFC 5545) for calendar sync.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `ItemBank`, `Item`, `Exam`, `ExamSitting`, `Room`, `Accommodation`. All in OpenRegister.

## Out of Scope
- Live proctoring orchestration — handled by `proctoring` spec.
- Automatic essay scoring / NLP grading (V2; high-risk under EU AI Act).
- Paper-based optical mark recognition.
