---
kind: code
depends_on: []
---

## Why

Scholiq's only planning/support instrument today is `LearningPlan`, and it is strictly **per-learner** —
confirmed at HEAD: `LearningPlan.required` is `["learnerId", "kind", "coordinatorId", "tenant_id"]`
(`lib/Settings/scholiq_register.json:6644-6649`), `learnerId` is "NC user ID of the learner this plan
belongs to" (`lib/Settings/scholiq_register.json:6650-6653`), and the one group-shaped field it carries,
`cohortId`, is documented as "UUID of the Cohort the learner belongs to (**optional context**)"
(`lib/Settings/scholiq_register.json:6676-6681`) — a plan cannot be authored *for* a cohort, only annotated
with which cohort its one learner happens to sit in. `openspec/specs/learning-plan/spec.md` (read in full)
confirms this in prose: the spec's own "What" section describes `LearningPlan` as "per learner" throughout,
and every one of its Requirements ("Persist LearningPlan domain objects", "Append-on-version...",
"Signing assurance level...") is phrased around a single learner's document and its co-signers. There is no
schema, requirement, or UI page anywhere in the register (`lib/Settings/scholiq_register.json`, full-file
grep for `group.?plan|subgroup|instructieniveau` — zero hits) or in `src/manifest.json` (same grep — zero
hits) that models a **group-level** plan.

This is a real gap for Dutch PO/SBO, not a nice-to-have: the **groepsplan** is the core planning cycle of
Dutch primary education under `handelingsgericht werken` (HGW) / the `1-zorgroute` — a teacher analyses a
group's results (Cito/LVS toets outcomes, methodetoetsen), splits the group into instructieniveau
subgroups (typically `intensief` / `basis` / `verdiept`), sets a differentiated goal and approach per
subgroup for a period, and evaluates at the period's end to seed the next cycle. Both ESIS and ParnasSys —
the two dominant Dutch PO administratiesystemen (`openspec/WEDGE-PLAN.md:15` already cites "ParnasSys 65%
... market share" for the K-12 segment) — ship this as standard functionality; a K-12-facing LVS without it
cannot support the statutory HGW cycle schools actually run. Scholiq already has every building block a
groepsplan needs to *reference* without duplicating:

- **`Cohort`** (the group/klas) — `lib/Settings/scholiq_register.json:3132-3230`, `openspec/specs/
  school-structure/spec.md` — already the `$ref` target `LearningPlan.cohortId` points at, so reusing it as
  the groepsplan's group reference is the established convention, not a new one.
- **Results to analyse** — `GradeEntry` (`lib/Settings/scholiq_register.json:5422-5423`, one mark per
  learner per component, `grading` capability) and `AssessmentResult` (`lib/Settings/
  scholiq_register.json:4942-4943`, a learner's structured-test attempt, `assessment` capability) both
  exist and are already the precedent `LearningPlan.goals[].evidenceRefs` points at ("UUIDs of evidence
  objects (e.g. GradeEntries, LearningPlanEvaluations)"). **No Cito/LVS-specific result schema or import
  exists anywhere in this repo** — confirmed by a case-insensitive full-repo grep for `cito|LVS|
  leerlingvolgsysteem`: the only hits are prose (`openspec/specs/dashboard/spec.md:19,28` — "principal Cito
  overview" as a dashboard *story*, not a schema; `openspec/WEDGE-PLAN.md:13-129` — K-12 LVS is explicitly
  named **Phase 2**, gated on BRON/UWLR/OSO/SchoolID work that hasn't started). A groepsplan's results
  analysis in this change therefore references the generic `GradeEntry`/`AssessmentResult` evidence pattern
  already established; landing actual Cito/LVS toets scores as `GradeEntry` rows is a separate,
  out-of-scope `openconnector`/`data-exchange` import follow-up (see "What Changes").
- **The escalation target** — `SupportRequest` (`lib/Settings/scholiq_register.json:7151-7181`), added in
  the wave-1 zorgvraag chain, already has a nullable `learningPlanId` documented as "a zorgvraag frequently
  precedes the OPP that follows from it" (`lib/Settings/scholiq_register.json:7182`) — the exact nullable,
  precedes-or-follows link shape a groepsplan-to-zorgvraag escalation needs, one level up the chain.
- **The declarative machinery to reuse** — `LearningPlan`'s own version-chain (`supersedesId`, `version`),
  lifecycle (`draft → active → under-evaluation → closed | superseded`), and evaluation sub-object
  (`LearningPlanEvaluation`) are exactly the HGW cycle shape (analyse → plan per subgroup → evaluate → seed
  next period) a groepsplan needs, one level up from an individual learner to a `Cohort`.

The manifest's declarative filter DSL has one confirmed limitation this change must design around: every
`object-list` widget filter in `src/manifest.json` is a single-value equality match (e.g.
`"templateId": "@objectId"`, `src/manifest.json:5030-5032`) — there is no "value is one of an array"
operator anywhere in the file (grepped). A subgroup's `learnerIds` is a multi-value array, so "does this
learner already have an active `LearningPlan`" cannot be expressed as a manifest filter and needs one
narrowly-scoped custom view (see `design.md`).

## What Changes

- **New `GroupPlan` object** (delta on `learning-plan`): the groepsplan itself — `cohortId` ($ref `Cohort`,
  required — the group this plan governs), `subject` (required — the leerlijn/vak the plan covers, e.g.
  "technisch lezen", "rekenen-wiskunde"), `period` + `periodEndDate`, `coordinatorId` (the responsible
  teacher/IB'er), a `resultsAnalysis` block (narrative + `evidenceRefs` — UUIDs of `GradeEntry`/
  `AssessmentResult`/`FinalGrade`/a prior `GroupPlanEvaluation` the analysis draws on), a `goals` array
  (group-wide period goals, same shape as `LearningPlan.goals`), `lifecycle`
  (`draft → active → under-evaluation → closed | superseded`, identical to `LearningPlan`'s), and
  `supersedesId` (the version-chain link — a new period's plan supersedes the prior period's, reusing
  `LearningPlan.supersedesId`'s exact pattern instead of inventing a second forward-pointing "seeds next
  plan" field).
- **New `GroupPlanSubgroup` object**: `groupPlanId` ($ref `GroupPlan`, required), `name` +
  `instructieniveau` (`intensief | basis | verdiept | custom`), `learnerIds` (array of NC user IDs, same
  convention as `Cohort.learnerIds`), `differentiatedGoal`, `approach` (the instruction/aanpak for this
  subgroup), and `intendedOutcome`.
- **New `GroupPlanEvaluation` object**: dated review at period end — `groupPlanId` ($ref `GroupPlan`,
  required), `evaluatedAt`/`evaluatedBy`, a per-subgroup `outcomes` array (`subgroupId`, `outcome`
  `met | partially-met | not-met`, narrative), and an overall narrative — same append-only-evidence shape as
  `LearningPlanEvaluation`.
- **MODIFIED `SupportRequest`** (existing `learning-plan` requirement): add nullable
  `originGroupPlanSubgroupId` ($ref `GroupPlanSubgroup`) alongside the existing nullable `learningPlanId` —
  the reverse link for "the group-level differentiated approach proved insufficient for this learner, so a
  zorgvraag was raised from that subgroup context." One field, on the existing schema, following the exact
  nullable-optional-context convention `learningPlanId` already established — no new relation mechanism.
- **Link, don't duplicate, to `LearningPlan`**: a learner in an `intensief` `GroupPlanSubgroup` may already
  have (or later get) a per-learner `LearningPlan` (OPP). No field is added to `GroupPlanSubgroup` to store
  this — `learnerIds` already exists and `LearningPlan.learnerId` already exists; storing a third
  denormalised link would drift. Instead, one narrowly-scoped custom view resolves "does any of this
  subgroup's learners have an active `LearningPlan`" at read time, because the manifest's equality-only
  filter DSL cannot express an array-membership lookup (see `design.md`).
- **Frontend**: declarative `src/manifest.json` index/detail pages for `GroupPlan`, `GroupPlanSubgroup`,
  `GroupPlanEvaluation` (mirroring the existing `LearningPlan`/`LearningPlanEvaluation`/`Signature` page
  triad exactly — sub-object-list widgets on the `GroupPlan` detail page, own index+detail pages for each
  sub-object). One named custom view, `GroupPlanSubgroupLearnerContext.vue`, on the `GroupPlanSubgroup`
  detail page, for the LearningPlan cross-lookup above. No PHP CRUD controller.
- **Out of scope**: importing actual Cito/LVS toets results (a future `data-exchange`/`openconnector`
  connector that would land scores as `GradeEntry` rows — tracked as a follow-up, not built here); any
  DigiD/co-signing flow (groepsplan is an internal teaching-team planning artefact, not a legally co-signed
  document like the OPP — see `design.md` "Rejected Alternatives"); automatic subgroup assignment from
  results (a human teacher decides subgroup membership; this change models the *record*, not an
  auto-clustering algorithm).

## Impact

- **`openspec/specs/learning-plan/spec.md`** — ADDED Requirements for `GroupPlan`, `GroupPlanSubgroup`,
  `GroupPlanEvaluation`; MODIFIED Requirement for `SupportRequest`'s new `originGroupPlanSubgroupId` field.
- **`lib/Settings/scholiq_register.json`** (future apply) — three new schemas; one additive nullable field
  on the existing `SupportRequest` schema.
- **`src/manifest.json`** (future apply) — six new index/detail pages, sub-object-list widgets on
  `GroupPlanDetail`, one new custom view file.
- **No new PHP controller, no new route, no wire protocol.**
