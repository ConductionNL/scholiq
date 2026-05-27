---
slug: learning-plan
title: Individual Learning Plan
status: implemented
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opp-passend-onderwijs, handelingsplan, iep, pdp-he, idp-corporate]
replaces: [opp-cycle]
---

# Individual Learning Plan

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, version-chain immutability, and notification config — no `#### Scenario:` headings exist in this spec.

## Why

Some learners need an individualised plan: a school pupil with extra ondersteuningsbehoeften, a university student on a remediation track, an employee on a personal-development plan. The structure is the same everywhere — a set of **goals**, the **support measures** in place to reach them, a **review cycle** with dated **evaluations**, and **signatures** (the learner / parent / coordinator co-sign each version). In the Netherlands the **Wet Passend Onderwijs** makes the **Ontwikkelingsperspectief (OPP)** mandatory for every pupil with extra needs, and `handelingsplannen` sit underneath it; ParnasSys owns ~65% of the PO market but the OPP UI is widely criticised. This spec generalises it: `LearningPlan` is the abstract document, the Dutch **OPP** is one profile (with its sector-template structure and DigiD parent-signing), an **IEP** (US), a higher-ed **PDP**, and a corporate **IDP** are others.

## What

- **LearningPlan** — per learner: `kind` (the profile: `opp` | `handelingsplan` | `iep` | `pdp` | `idp`), a `templateId` (sector/institution template that pre-structures the sections), `goals` (`{ goalId, description, targetDate, domain, baseline, target, status }[]`), `supportMeasures` (`{ measureId, description, responsibleId, startDate, endDate }[]`), `period`, `lifecycle` (draft → active → under-evaluation → closed | superseded), and a `version` chain (each material revision is a new version that must be re-signed).
- **LearningPlanEvaluation** — a dated review point: which goals were met / adjusted / dropped, narrative, attendees, next-review date. Created on the plan's review cadence (a `quarterlyReviewReminder` notification driven off the plan's `period` — no TimedJob).
- **Signature** — a co-sign on a specific LearningPlan version by the learner, a parent/guardian, and the coordinator. Signing may go through an external authenticated flow (Dutch: DigiD) — the *signing-strength* is configurable; the plan only records who signed which version when and with what assurance level.
- Linking to evidence: a LearningPlan goal may reference `AssessmentResult`s, `GradeEntry`s, attendance, or uploaded artefacts as progress evidence.

## User Stories

- As a learning-support coordinator, I want to create a LearningPlan from a sector template so the required sections are pre-structured and I just fill them in.
- As a coordinator, I want a reminder when a plan's quarterly evaluation is due, and a place to record the evaluation outcome against each goal.
- As a parent, I want to review a new plan version and co-sign it (via DigiD where required) before it becomes active.
- As a teacher, I want to see the active LearningPlan goals for a pupil in my cohort so my teaching reflects the support measures.
- As an auditor (inspectie / accreditation body), I want the full version + signature history of a plan so I can verify it was co-signed and reviewed on cadence.

## Acceptance Criteria

- GIVEN a sector template `opp-vo`, WHEN a coordinator creates a LearningPlan with `kind=opp` and that template, THEN the plan is pre-populated with the template's sections and goal domains.
- GIVEN an active LearningPlan with a quarterly review cadence, WHEN the next-review date arrives, THEN a `quarterlyReviewReminder` notification fires to the coordinator (idempotency-keyed so a re-tick doesn't double-fire).
- GIVEN a plan moves to a new version, WHEN it is presented for signing, THEN it is `draft` until all required co-signers have signed; only then does it become `active` and supersede the prior version.
- GIVEN a DigiD-required signature, WHEN a parent signs, THEN the Signature records the assurance level; a signature without the required level does not satisfy the co-sign requirement.
- GIVEN an evaluation records a goal as "met", WHEN the plan is viewed, THEN that goal shows `status: met` with the evaluation that closed it linked.

## Requirements

### Requirement: Persist LearningPlan domain objects in OpenRegister
The system MUST persist `LearningPlan`, `LearningPlanEvaluation`, `Signature` as OpenRegister objects with `x-openregister-lifecycle` (LearningPlan: draft → active → under-evaluation → closed | superseded), `x-openregister-relations` (LearningPlan↔learner/template/cohort, Evaluation↔LearningPlan, Signature↔LearningPlan-version), `x-openregister-calculations` (LearningPlan `goalsMetCount`, `nextReviewDue`, `isFullySigned`), and `x-openregister-notifications` (`quarterlyReviewReminder`, `signatureRequested`, idempotency-keyed).

### Requirement: Review reminder is a declared notification
The review-reminder MUST be a declared notification off the plan's period — not a PHP TimedJob.

### Requirement: Append-on-version with immutable prior versions
LearningPlan MUST be effectively append-on-version: a material change creates a new version requiring re-sign; prior versions and their signatures are immutable (`appendOnly: true` on the version records, or an OR versioning mechanism if available).

### Requirement: Signing assurance level is declarative config
Signing assurance-level capture MUST be declarative config; the actual DigiD/eIDAS handshake is an external auth concern (see `data-exchange` / openconnector), not implemented here.

### Requirement: Frontend is declarative with named custom views
Frontend declarative: `src/manifest.json` pages for LearningPlan index/detail (with a version-history + signature tab) and LearningPlanEvaluation; a custom `SignPlanModal` Vue component for the co-sign flow. No PHP CRUD controllers; the version-immutability is enforced by the schema's `appendOnly` / lifecycle.

## Standards

Schema.org `EducationalOccupationalProgram` (loosely) — there is no clean schema.org type, so the canonical form is OpenRegister-native; NL Wet Passend Onderwijs / OPP sector templates as a `LearningPlan` profile; eIDAS / DigiD assurance levels for the signing strength.

## Data Model

All in OpenRegister. New: `LearningPlan`, `LearningPlanEvaluation`, `Signature`, `LearningPlanTemplate`. Consumes: `AssessmentResult`, `GradeEntry`, `AttendanceRecord` (as evidence). No PHP service classes — fully declarative; the only seam is whatever the DigiD signing flow needs, which lives outside scholiq. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The DigiD / eIDAS authentication handshake itself (openconnector / NC auth — see `data-exchange`).
- Sector-wide OPP analytics (mydash).
- Auto-generation of goals from assessment results (would be an `AiFeature` registration).
- The samenwerkingsverband (collaboration-network) funding flow that some OPPs trigger — out of scope; a follow-up if a buyer needs it.
