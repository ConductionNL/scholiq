---
slug: enrolment
title: Enrolment
status: implemented
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# Enrolment

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, prerequisite validation, and Studielink/Edukoppeling integration — no `#### Scenario:` headings exist in this spec.

## Why
Enrolment is the gateway from identity to learning record. For HE, Studielink integration is mandatory (insight #4); for corporate L&D, bulk-enrol of cohorts is the #1 line-manager workflow (5 high-priority stories). Without enrolment, every downstream capability — assessment, certification, compliance audit — has no subject.

## What
Manual and bulk enrolment of learners into courses, modules, and learning paths; cohort and group management (NL `klas`, HE `tutor group`, corporate `team`); eligibility/prerequisite checks; auto-enrol on hire (HR template) and on Studielink intake (HE); auto-enrol into certification renewal modules when expiry is detected; unenrolment with reason capture; immediate LMS account provisioning on enrolment.

## User Stories
- As an HE administrator, I want incoming Studielink enrolments to appear automatically in Scholiq so I do not rekey applicant data.
- As a student, I want an institutional account and LMS access immediately after enrolment so I can start orientation activities.
- As HR, I want to apply a 30-60-90 onboarding template when I create a new-hire account so modules are scheduled across days 1, 30, 60, and 90.
- As a line manager, I want to bulk-assign a course to my direct reports so all selected learners are enrolled with the same deadline and I see a team progress bar.
- As a compliance officer, I want to bulk-enrol every active employee in the annual refresher with deadlines T-30, T-7, T-1 days so coverage is automatic.

## Acceptance Criteria
- GIVEN a Studielink enrolment is received via the Edukoppeling adapter, WHEN it parses successfully, THEN a Learner + Enrolment object is created and an LMS account is provisioned within 60 seconds.
- GIVEN a line manager opens the team view, WHEN they multi-select reports and pick a course, THEN every selected learner is enrolled with a single shared deadline and notification.
- GIVEN HR creates a new hire, WHEN they pick the role, THEN the matching 30-60-90 template auto-applies and milestones populate Days 1/30/60/90.
- GIVEN a course has unmet prerequisites, WHEN a learner attempts enrolment, THEN the system blocks the enrolment and explains which prerequisite failed.

## Requirements

### Requirement: Bulk enrolment via cohort, role, department or CSV
The system MUST support bulk enrolment via cohort, role, department, or CSV upload.

### Requirement: Validate prerequisites before persistence
The system MUST validate prerequisites before enrolment is persisted.

### Requirement: Provision LMS account within 60 seconds via Studielink
The system MUST provision an LMS account within 60 seconds of an HE enrolment via Studielink.

## Standards
Studielink, Edukoppeling, OOAPI 5.0, IMS LIS (legacy), Schema.org `EducationEvent`, eduPersonAffiliation propagation.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Learner`, `Enrolment`, `Cohort`, `OnboardingTemplate`, `EnrolmentRule`. All in OpenRegister.

## Out of Scope
- Payment processing for paid enrolments (separate spec; routes to billing system).
- Waitlist auto-promotion (V1 enhancement).
- Cross-institution credit transfer (handled by oso-transfer / EDCI).
