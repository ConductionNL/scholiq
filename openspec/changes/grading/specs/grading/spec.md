---
slug: grading
title: Grading — Grade Entries, Scales, Final Grades, Soft-Publish
status: implemented
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

### REQ-GRD-001 — Persist grade schemas as OpenRegister objects

GIVEN a scholiq register exists, WHEN the app initialises, THEN `GradeScale`, `GradeEntry`, and `FinalGrade` schemas MUST be present in `lib/Settings/scholiq_register.json` as OpenRegister schemas.

### REQ-GRD-002 — GradeEntry lifecycle (concept → published → revised)

GIVEN a GradeEntry in `concept` state, WHEN a teacher publishes it, THEN its lifecycle transitions to `published`; no back-transition to `concept` is permitted once `published`.

### REQ-GRD-003 — Declarative publish notification with idempotency

GIVEN a GradeEntry transitions to `published`, WHEN the `gradePublished` notification fires, THEN it MUST use idempotencyKey `"${@self.id}-${@self.lifecycle}"` so that a re-publish or backfill does not double-notify the learner.

### REQ-GRD-004 — Per-entry weight override

GIVEN a CurriculumPlan component with a declared weight, WHEN a GradeEntry has its own `weight` field set (non-null), THEN the GradeEntry's own weight overrides the plan component default for all calculations on that entry.

### REQ-GRD-005 — FinalGrade roll-up via formula

GIVEN a learner has one or more published GradeEntries for a CurriculumPlan, WHEN the FinalGrade is computed, THEN the `GradeFormulaEvaluator` MUST apply the plan's `formula` field: `weighted-average`, `last-attempt`, `best-of-n`, or `all-must-pass`.

### REQ-GRD-006 — Roll-up triggers on publish, not on a TimedJob

GIVEN a GradeEntry transitions to `published`, WHEN the `ObjectTransitionedEvent` fires, THEN the `GradeRollupHandler` MUST recompute and persist the matching FinalGrade within the same request cycle. A PHP `TimedJob` walking `findAll()` MUST NOT be used as the trigger mechanism.

### REQ-GRD-007 — all-must-pass verdict

GIVEN a CurriculumPlan with `formula: all-must-pass`, WHEN any required component's best published value is below its `passRules` threshold, THEN `FinalGrade.passed` MUST be `false` regardless of the weighted average.

### REQ-GRD-008 — Notification preference dispatch

GIVEN a `gradePublished` notification is ready to dispatch, WHEN the recipient's OR notification preference is `instant`, THEN the notification MUST fire immediately; WHEN the preference is `daily-digest`, THEN it MUST be queued for OR's `BatchNotificationJob`; WHEN the preference is `off`, THEN no notification is sent.

### REQ-GRD-009 — 18+ learner controls own notification preference

GIVEN a learner is 18 or older, WHEN they set their own notification preference via OR's `UserService::setNotificationPreferences`, THEN that preference MUST take precedence over any parent-set preference for that learner, per AVG-Onderwijs.

### REQ-GRD-010 — Soft-publish: no notification before batch publish

GIVEN a teacher has saved one or more GradeEntries as `concept`, WHEN the teacher opens the distribution preview in GradebookView, THEN no `gradePublished` notification MUST have fired for any of those entries.

### REQ-GRD-011 — Batch publish fires exactly one notification per recipient

GIVEN a teacher clicks "Publish all" for a cohort, WHEN all `concept` entries transition to `published`, THEN exactly one `gradePublished` notification per recipient MUST fire (deduplicated by idempotencyKey).

### REQ-GRD-012 — GradeImpact detail shows weight + contribution + deltas

GIVEN a learner opens a published GradeEntry, WHEN the GradeImpactDetail view renders, THEN it MUST display: `value`, `effectiveWeight`, `pointsContributed`, the period-average delta, and the FinalGrade delta.

### REQ-GRD-013 — Manifest pages for all new schemas

GIVEN the grading change is deployed, WHEN a user navigates to the Grades section, THEN `src/manifest.json` MUST declare pages for GradeScale (index + detail), GradeEntry (index + detail), FinalGrade (index + detail, readOnly), GradebookView (custom), and GradeImpactDetail (custom). No PHP CRUD controllers.

### REQ-GRD-014 — AssessmentResult bridge creates concept GradeEntry

GIVEN an AssessmentResult transitions to `graded`, WHEN `GradeRollupHandler` handles the event, THEN it MUST create a `concept` GradeEntry with `sourceKind: assessment-result` and set `AssessmentResult.gradeEntryId`.

### REQ-GRD-015 — MarkSubmissionView TODO fulfilled

GIVEN a teacher marks a Submission and clicks "Save and Return", WHEN `saveAndReturn()` executes, THEN a `concept` GradeEntry with `sourceKind: assignment-submission` MUST be created and `Submission.gradeEntryId` MUST be set.

## Standards

Schema.org `Grade`; NL VO PTA/SE convention as a `CurriculumPlan` profile + `GradeScale` 1.0–10.0; ECTS A–F; AVG-Onderwijs (parent vs 18+-learner notification rights); Open Onderwijs API `results` endpoint shape for HE result publication (follow-up, out of scope here).

## Data Model

All in OpenRegister. New: `GradeScale`, `GradeEntry`, `FinalGrade`. (`NotificationPreference` not added — OR already exposes this via `UserService::getNotificationPreferences` / `setNotificationPreferences` + `BatchNotificationJob`.) Consumes: `CurriculumPlan` (`school-structure`), `Submission` (`assignments`), `AssessmentResult` (`assessment`), `Session` (participation). One ADR-031 PHP exception: `GradeFormulaEvaluator` (weighted-average/last-attempt/best-of-n/all-must-pass formulas exceed JSON-logic). See `docs/ARCHITECTURE.md`.

## Out of Scope

- Centraal-Examen (CE) result import and the CE+SE→eindcijfer combination — that's a DUO/`data-exchange` concern.
- Cross-school cohort analytics and benchmarking (handled by mydash via runtime GraphQL).
- Transcript / diploma-supplement document generation (the `certification` spec issues the credential; DocuDesk does templating).
- AI-assisted grading (would be an `AiFeature` registration; not in scope).
