---
slug: grading-pta
title: Grading & PTA (NL VO)
status: idea
feature_tier: must
depends_on_adrs: [adr-001, adr-003]   # TODO until ADRs land
created: 2026-05-11
---

# Grading & PTA (NL VO)

## Why
Dutch secondary education runs on the **PTA (Programma van Toetsing en Afsluiting)** with weighted columns per period feeding the school exam (SE) average. Magister and SOMtoday own this workflow today (insight #8 — 55% / 40% duopoly) but face systemic UX backlash (insight #17). One critical-priority story (`PTA weighting per kolom`) and three high/medium ones (`soft-publish`, `parent daily digest`, `pupil sees grade with weight and impact`) drive the spec.

## What
Per-kolom grade entry with declared weegfactor (PTA conformant); concept (draft) state with batch publish so a teacher can review the cohort distribution before parents are notified; automatic period and SE average calculation; per-grade impact display so pupils see what a single grade does to their average; parent notification preferences (per-grade ping vs daily digest); pupil-aged-18+ self-management of view/notify settings.

## User Stories
- As a teacher, I want to assign a weegfactor to each kolom according to the PTA so that the periode- and SE-gemiddelde are calculated automatically.
- As a teacher, I want to enter all grades in concept and then publish in one batch so I can review the cohort distribution before parents and pupils are notified.
- As a parent, I want to choose between instant push or a daily digest of new grades so I do not get a notification every time my child gets a deeltoetsje.
- As a pupil, I want to see each new grade together with its weight and impact on my period average so I understand what to focus on next.

## Acceptance Criteria
- GIVEN a kolom has a weegfactor of 3 in the PTA, WHEN a grade is entered, THEN it contributes 3× to the periode- and SE-gemiddelde calculation.
- GIVEN a teacher saves grades in concept, WHEN they preview the cohort distribution, THEN no parent or pupil notification has yet fired.
- GIVEN a parent has chosen "daily digest", WHEN grades are published throughout the day, THEN one summary notification fires at the configured time.
- GIVEN a pupil opens a new grade, WHEN the detail view loads, THEN it shows weight, points contributed, and the resulting periode-gemiddelde delta.

## Requirements
- The system MUST persist a weegfactor per kolom and use it in periode- and SE-gemiddelde calculations.
- The system MUST support a concept state with explicit batch publish before any external notification fires.
- The system MUST honour per-parent and per-pupil notification preferences (instant vs daily digest).

## Standards
NL VO PTA convention, Schema.org `Grade`, AVG-Onderwijs (parent vs 18+ pupil notification rights).

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Grade`, `GradeColumn`, `PtaDefinition`, `NotificationPreference`. All in OpenRegister.

## Out of Scope
- Cijferadministratie for PO (use `opp-cycle` and Cito-import instead).
- Centraal-Examen (CE) result import — handled by DUO/ROD flow.
- Cross-school cohort analytics (handled by mydash).
