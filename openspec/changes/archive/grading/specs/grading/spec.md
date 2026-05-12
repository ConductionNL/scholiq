---
slug: grading
title: Grading — Grade Entries, Scales, Final Grades, Soft-Publish
status: planned
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [pta-se-vo, eindcijfer-he, ects-conversion, pass-fail-certification]
replaces: [grading-pta]
---

# Grading

## Why

Component grades have to roll up into a final grade, and the roll-up rule belongs to the governing plan, not the gradebook. Dutch VO does this with the **PTA**: each `kolom` has a `weegfactor`, kolommen group by `periode`, and the weighted average across periods is the **SE-gemiddelde** which (with the CE) gives the **eindcijfer**. An HE module does the same with `deeltoetsen → eindcijfer`. A certification track does it with `all-must-pass`. Magister/SOMtoday own the Dutch VO workflow today but draw systemic UX backlash (instant per-grade pings, no concept state, opaque impact). This spec generalises it: a `GradeEntry` is one mark on one component for one learner; a `FinalGrade` is computed from a learner's GradeEntries using the `CurriculumPlan`'s declared `formula` and `component weights` (from `school-structure`); soft-publish lets a teacher review the cohort distribution before any parent/learner notification fires; the learner sees each grade's weight and its impact on the running average.

## What

- **GradeScale** — a named scale: numeric `1.0–10.0` (NL), letter `A–F`, ECTS `A–F`, pass/fail, percentage, custom band sets. Carries the pass threshold and rounding rule.
- **GradeEntry** — one mark: learnerId, the CurriculumPlan `componentId` it scores, the source object (`assignmentSubmissionId` | `assessmentResultId` | `participationSessionId` | manual), `value` (on the component's GradeScale), `weight` (defaults from the CurriculumPlan component but overridable per entry), `period`, `lifecycle` (concept → published → revised), grader, gradedAt, comment.
- **FinalGrade** — a *calculated* schema: per learner per Course/Programme, recomputed (via `x-openregister-calculations` + the cross-schema-aggregation feature) whenever a GradeEntry for that learner publishes — applying the CurriculumPlan's `formula` (`weighted-average` | `last-attempt` | `best-of-n` | `all-must-pass`) over the published GradeEntries, producing the value, the pass/fail verdict, and a `breakdown` (per-period averages, per-component contributions).
- **Soft-publish** — GradeEntries start `concept`; a teacher batch-publishes a cohort's entries in one action *after* previewing the distribution. Only `published` entries notify (per the learner's / parent's notification-preference: instant ping vs daily digest, with the 18+-learner controlling their own per AVG-Onderwijs).
- **Impact view** — when a learner opens a new GradeEntry, the detail shows its weight, the points it contributed, and the resulting period-average and final-grade delta.

## User Stories

- As a teacher, I want the weegfactor per kolom to come from the PTA (CurriculumPlan) so the periode- and SE-gemiddelde compute automatically — and I want to override a single entry's weight when the situation warrants.
- As a teacher, I want to enter a cohort's grades in concept, preview the distribution, and publish them in one batch so parents and learners aren't pinged per deeltoetsje.
- As a parent, I want to choose instant push or a daily digest of new grades; as an 18+ learner, I want that choice to be mine, not my parent's.
- As a learner, I want each new grade shown with its weight, points contributed, and the resulting change to my period average and final grade.
- As a certification coordinator, I want an `all-must-pass` formula so a learner is only "passed" once every required component is at or above its threshold.

## Acceptance Criteria

- GIVEN a CurriculumPlan component with weight 3, WHEN a GradeEntry publishes for it, THEN the learner's FinalGrade recomputes with that entry counting 3× in the weighted average; a per-entry `weight` override takes precedence over the component default.
- GIVEN a teacher saves a cohort's GradeEntries as `concept` and opens the distribution preview, THEN no parent/learner notification has fired; on batch publish, exactly one notification per recipient fires according to their preference.
- GIVEN a parent set "daily digest", WHEN several grades publish during the day, THEN one summary notification fires at the configured time.
- GIVEN a learner opens a published GradeEntry, THEN the detail shows weight, points contributed, and the period-average and final-grade deltas.
- GIVEN a CurriculumPlan with `formula: all-must-pass` and one component below threshold, THEN the FinalGrade `passed` is false regardless of the average.

## Requirements

- The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects. `GradeEntry` has `x-openregister-lifecycle` (concept → published → revised) and `x-openregister-notifications` keyed so a re-publish/backfill doesn't double-notify. `FinalGrade` is computed via `x-openregister-calculations` + cross-schema aggregation over the learner's published `GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights.
- Notification dispatch MUST honour per-parent / per-18+-learner preference (instant vs daily digest), backed by a `NotificationPreference` schema or the existing OR notification-preference mechanism (whichever OR exposes).
- The roll-up MUST NOT be a PHP TimedJob — it MUST be a declared calculation that re-fires on `GradeEntry` publish (the `calculatedChange` trigger feature). The only PHP exception allowed: a stateless `GradeFormulaEvaluator` invoked by the calculation engine if a formula can't be expressed in JSON-logic (ADR-031 "calculation engine above schema metadata").
- Frontend declarative: `src/manifest.json` pages for GradeEntry/FinalGrade index+detail per cohort; a custom `GradebookView` (the cohort grid with concept→publish — genuine UI) and `GradeImpactDetail` Vue component. No PHP CRUD controllers.

## Standards

Schema.org `Grade`; NL VO PTA/SE convention as a `CurriculumPlan` profile + `GradeScale` 1.0–10.0; ECTS A–F; AVG-Onderwijs (parent vs 18+-learner notification rights); Open Onderwijs API `results` endpoint shape for HE result publication (follow-up, out of scope here).

## Data Model

All in OpenRegister. New: `GradeScale`, `GradeEntry`, `FinalGrade`, (`NotificationPreference` if OR doesn't already provide one). Consumes: `CurriculumPlan` (`school-structure`), `Submission` (`assignments`), `AssessmentResult` (`assessment`), `Session` (participation). One ADR-031 PHP exception: `GradeFormulaEvaluator` (only if a formula exceeds JSON-logic). See `docs/ARCHITECTURE.md`.

## Out of Scope

- Centraal-Examen (CE) result import and the CE+SE→eindcijfer combination — that's a DUO/`data-exchange` concern.
- Cross-school cohort analytics and benchmarking (handled by mydash via runtime GraphQL).
- Transcript / diploma-supplement document generation (the `certification` spec issues the credential; DocuDesk does templating).
- AI-assisted grading (would be an `AiFeature` registration; not in scope).
