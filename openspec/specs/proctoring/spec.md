---
slug: proctoring
title: Proctoring
status: idea
feature_tier: must
depends_on_adrs: [adr-004, adr-005]   # TODO until ADRs land — AI Act + assessment engine
created: 2026-05-11
---

# Proctoring

## Why
EU AI Act (Reg. 2024/1689) classifies online proctoring as **high-risk AI** (insight #1). Critical-priority stories from `assessment-examination` (configure proctoring per exam, take a proctored exam, review flagged events) require this be a first-class capability with a pluggable provider — buyers must not be locked to one US vendor whose data residency and AI-Act conformity are uncertain.

## What
Plug-in architecture for proctoring providers (ProctorU, Honorlock, Examity, in-house webcam recording), per-exam proctoring level configuration (none / record-only / live human / AI-assisted), pre-exam environment check and consent flow, integrity event detection and queue, examination-committee referral workflow, full audit trail of what was recorded, when, by whom, and for how long. AI-assisted detection MUST be feature-flag gated and logged for AI Act conformity.

## User Stories
- As an assessment expert, I want to choose the proctoring level per exam so I can match integrity needs without over-collecting data.
- As a student, I want a clear environment check and start flow so I know what is recorded and when the exam ends.
- As an assessment expert, I want a queue of flagged events with context so I can decide quickly whether to refer a case to the examination committee.
- As an examination office, I want every proctoring session's evidence retained per the institution's policy and auto-purged afterwards so we comply with AVG storage minimisation.

## Acceptance Criteria
- GIVEN an exam is configured for "AI-assisted live proctoring", WHEN AI-assisted features are disabled at tenant level, THEN the exam falls back to "live human only" and the planner is notified.
- GIVEN a learner starts a proctored exam, WHEN the consent screen loads, THEN the learner sees exactly which signals will be recorded (camera, mic, screen, network) and the retention period.
- GIVEN an integrity event is detected, WHEN it lands in the review queue, THEN it includes a 30-second clip, the rule that fired, and a one-click "refer to committee" action.
- GIVEN a proctoring session ends, WHEN the retention period elapses, THEN all recordings are purged and the audit log entry remains.

## Requirements
- The system MUST support pluggable proctoring providers behind a single internal interface.
- The system MUST gate AI-assisted detection behind a tenant feature flag and log every invocation for AI Act conformity.
- The system MUST present an explicit consent screen listing recorded signals and retention before any session start.

## Standards
EU AI Act (Reg. 2024/1689), AVG (GDPR), AVG-Onderwijs, Schema.org `EducationEvent`, IMS Caliper Analytics for event stream.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `ProctoringSession`, `IntegrityEvent`, `ProctoringProvider`, `ConsentRecord`, `RetentionPolicy`. All in OpenRegister.

## Out of Scope
- Building a from-scratch in-house webcam capture stack (use existing OSS like Jitsi/Talk or a third-party SDK).
- Biometric identity verification beyond ID-card photo match (V2 — also high-risk under AI Act).
- Live remote-hand interventions (provider-specific).
