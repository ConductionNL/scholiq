---
slug: assignments
title: Assignments & Submissions
status: implemented
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opdracht-vo, opdracht-he, werkstuk, portfolio-item]
---

# Assignments & Submissions

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, lifecycle guards (late-submission enforcement), and a pluggable plagiarism PHP interface — no `#### Scenario:` headings exist in this spec.

## Why

Learners hand work in; teachers grade it. That loop is universal — a vmbo `opdracht`, an HBO `werkstuk`, a university `portfolio-item`, a corporate `case study`. Scholiq can hold courses and lessons but has nowhere for a learner to *submit* anything and nowhere for a teacher to mark it against criteria. This spec adds the deliverable side of assessment (the structured-test side is the `assessment` spec): an `Assignment` belongs to a Course or Session, has a due date and a `Rubric`; a learner files a `Submission` (one or more attachments) which moves through draft → submitted → returned; the grade a teacher gives a Submission becomes a `GradeEntry` (see `grading`) so it can roll up into a final grade per the CurriculumPlan.

## What

- **Assignment** — a deliverable: title, instructions (rich text + attached briefing `Material`s), Course/Session it belongs to, CurriculumPlan component it scores (`componentId`), `dueAt`, `maxPoints`, `allowLateSubmission` + late penalty, `rubricId`, `groupSubmission` (bool), visibility window.
- **Submission** — a learner's (or group's) hand-in for an Assignment: learnerId(s), attached files (OpenRegister attachments), `submittedAt`, `lifecycle` (draft → submitted → late → returned), teacher feedback text, `gradeEntryId` once marked.
- **Rubric** — reusable marking scheme: criteria, each with weighted levels (`{ criterionId, label, weight, levels: [{ label, points }] }[]`). A teacher marking a Submission picks a level per criterion → the points sum is the proposed grade.
- Submission window enforcement, late-flagging + penalty application, plagiarism-check hook (`x-plagiarism` provider config — pluggable, like proctoring; no built-in checker).

## User Stories

- As a teacher, I want to publish an Assignment with a due date and a rubric, so learners know exactly what's expected and how it's marked.
- As a learner, I want to upload my work, save it as a draft, and submit when ready — and see whether it landed before the deadline.
- As a teacher, I want to mark a Submission against the rubric and have the points feed the learner's grade automatically.
- As a learner, I want my returned Submission to show the rubric levels I scored and the teacher's comments.
- As a teacher, I want late submissions flagged and the configured penalty applied to the proposed grade.

## Acceptance Criteria

- GIVEN an Assignment with `dueAt` in the future, WHEN a learner submits, THEN the Submission lifecycle is `submitted` and `submittedAt` is recorded; submitting after `dueAt` sets lifecycle `late`.
- GIVEN `allowLateSubmission=false` and `dueAt` in the past, WHEN a learner attempts to submit, THEN the system rejects it (HTTP 422) and creates no Submission.
- GIVEN a teacher marks a Submission against a Rubric, WHEN they save, THEN a `GradeEntry` is created/updated with the summed points and linked to the Submission; the learner's view shows the per-criterion levels.
- GIVEN a Submission is `late` and the Assignment has a 10% penalty, WHEN the teacher marks it, THEN the proposed grade is reduced by 10% before becoming the GradeEntry value.

## Requirements

### Requirement: Persist Assignment domain objects in OpenRegister
The system MUST persist `Assignment`, `Submission`, `Rubric` as OpenRegister objects with `x-openregister-lifecycle` (Submission: draft → submitted → late → returned), `x-openregister-relations` (Assignment↔Course/Session/Rubric, Submission↔Assignment/learner), and `x-openregister-calculations` (Submission `isLate`, `effectiveGrade`).

### Requirement: Submission attachments use OpenRegister file attachments
Submission attachments MUST use OpenRegister file attachments; no app-local file storage.

### Requirement: Marking a Submission emits a GradeEntry
Marking a Submission MUST emit (or update) a `GradeEntry` consumed by the `grading` spec; this spec MUST NOT compute final grades itself.

### Requirement: Plagiarism check is a pluggable provider
The plagiarism-check hook MUST be a declared `x-plagiarism.provider` config on `Assignment` resolving to a pluggable PHP interface (no bundled provider) — analogous to proctoring providers in the `assessment` spec.

### Requirement: Frontend is declarative with named custom views
Frontend declarative: `src/manifest.json` pages for Assignment index/detail and a custom `SubmitWorkModal` + `MarkSubmissionView` Vue component (genuine UI that a manifest index/detail page can't express). No PHP CRUD controllers; the late-window enforcement is an `x-openregister-lifecycle` guard.

## Standards

Schema.org `CreativeWork` / `MediaObject` for submissions; IMS Caliper for submission events; QTI is *not* used here (that's `assessment`); plagiarism providers (Turnitin/Ouriginal/Compilatio) behind an interface.

## Data Model

All in OpenRegister. New: `Assignment`, `Submission`, `Rubric`. Touches: `GradeEntry` (from `grading`), `Material` (from `school-structure`). One ADR-031 PHP exception: the late-submission lifecycle guard. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The structured-test / exam path (QTI items, scoring engine, proctoring) — that's the `assessment` spec.
- Peer review / peer grading (a follow-up).
- The actual plagiarism-detection algorithm (provider behind the hook only).
- Final-grade computation (the `grading` spec).
