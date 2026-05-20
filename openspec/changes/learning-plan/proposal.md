## Why

Some learners need an individualised plan: a school pupil with extra **ondersteuningsbehoeften**, a university student on a remediation track, an employee on a personal-development plan. The structure is the same everywhere — a set of goals, the support measures in place to reach them, a review cycle with dated evaluations, and signatures (learner / parent / coordinator co-sign each version).

In the Netherlands the **Wet Passend Onderwijs** makes the **Ontwikkelingsperspectief (OPP)** mandatory for every pupil with extra support needs; `handelingsplannen` sit underneath it. ParnasSys owns ~65% of the PO market but the OPP UI is widely criticised. Without this spec, Scholiq cannot compete for the LVS buyer who has legal obligations under Passend Onderwijs.

This spec generalises the pattern: `LearningPlan` is the abstract document; `opp`, `handelingsplan`, `iep` (US), `pdp` (higher-ed), and `idp` (corporate) are profiles. A single implementation serves all five markets and future profiles land via template configuration, not code.

## What Changes

- Add OpenRegister schema **`LearningPlan`** with `kind` (profile: `opp` | `handelingsplan` | `iep` | `pdp` | `idp`), `templateId` (sector template), `learnerId`, `cohortId`, `goals[]` (`goalId`, `description`, `targetDate`, `domain`, `baseline`, `target`, `status`), `supportMeasures[]` (`measureId`, `description`, `responsibleId`, `startDate`, `endDate`), `period` (`{ startDate, endDate, reviewCadence }`), `version` (integer, 1-based), `appendOnly: true` on versioned records. Lifecycle: `draft → active → under-evaluation → closed | superseded`.
- Add OpenRegister schema **`LearningPlanEvaluation`** capturing: `planId`, `planVersion`, `evaluationDate`, `attendees[]`, `goalOutcomes[]` (`goalId`, `outcome`: met | adjusted | dropped | continued, `narrative`), `overallNarrative`, `nextReviewDate`. Created on the plan's review cadence.
- Add OpenRegister schema **`Signature`** for co-sign on a specific plan version: `planId`, `planVersion`, `signerRole` (learner | parent | coordinator), `signedAt`, `assuranceLevel` (none | low | substantial | high — eIDAS vocabulary), `appendOnly: true`. Plan becomes `active` only when all required co-signers have signed.
- Add OpenRegister schema **`LearningPlanTemplate`** storing sector-template structures: `slug` (e.g. `opp-vo`, `opp-po`), `kind`, `sectorCode`, `goalDomains[]`, `sections[]`. Referenced by `LearningPlan.templateId`.
- Declare `x-openregister-lifecycle` on `LearningPlan` (draft → active → under-evaluation → closed | superseded) and on `Signature` (pending → signed).
- Declare `x-openregister-calculations` on `LearningPlan`: `goalsMetCount`, `nextReviewDue`, `isFullySigned`.
- Declare `x-openregister-notifications` on `LearningPlan`: `quarterlyReviewReminder` (off `period.nextReviewDate`, idempotency-keyed) and `signatureRequested` (on new version creation). These are declared notifications — NOT a PHP TimedJob.
- Declare `x-openregister-relations` on all schemas (LearningPlan↔learner/template/cohort, Evaluation↔LearningPlan, Signature↔LearningPlan-version).
- Extend **`src/manifest.json`** with LearningPlan index and detail pages (version-history + signature tab), LearningPlanEvaluation index/detail, and a custom `SignPlanModal.vue` component for the co-sign flow.
- No PHP CRUD controllers; version-immutability enforced by schema's `appendOnly` and lifecycle.

## Capabilities

### New Capabilities

- **`individual-learning-plan`**: Coordinator creates a LearningPlan from a sector template (pre-populating goal domains and sections); learner/parent/coordinator co-sign each version via a dedicated signing modal (with configurable DigiD assurance-level capture); quarterly evaluation reminders fire automatically off the plan's period; evaluations record per-goal outcomes and close met goals; auditors see the full version chain and signature history; teachers see active plan goals for their cohort.

### Modified Capabilities

(none — all prerequisite specs already landed)

## Impact

- **`LearningPlan` version chain**: a material change creates a new version record (version integer incremented) and sets `lifecycle: draft` on the new record, leaving the prior version immutable (`appendOnly: true`). The new version requires all co-signers to re-sign before transitioning to `active`. OR's lifecycle engine enforces the transition guard `LearningPlanVersionGuard` — the only PHP in this change.
- **Signing assurance level**: declarative `requiredAssuranceLevel` config field on `LearningPlanTemplate`. The actual DigiD / eIDAS handshake is out of scope (openconnector / data-exchange); this spec only records the assurance level claimed and validates it meets the configured minimum.
- **`quarterlyReviewReminder`**: declared as `x-openregister-notifications` with a `scheduledOffset` trigger off `LearningPlan.period.nextReviewDate` — NOT a PHP TimedJob (explicit requirement).
- **Evidence linking**: `LearningPlan.goals[].evidenceRefs[]` may reference `AssessmentResult`, `GradeEntry`, or `AttendanceRecord` object UUIDs via OR relations — consumed schemas, no new schemas.
- **Frontend**: `SignPlanModal.vue` is the only custom Vue component; all index / detail views are `CnAppRoot` pages fed by the manifest.
