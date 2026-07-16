# Design: groepsplan

## Context

Dutch PO/SBO schools run their entire year around one recurring cycle: `handelingsgericht werken` (HGW),
operationalised as the `1-zorgroute`. A teacher analyses a group's results (methodetoetsen, Cito/LVS
toetsen), splits the group into `instructieniveau` subgroups — conventionally `intensief` (extra support),
`basis`, `verdiept` (extra challenge) — sets a differentiated goal and approach per subgroup for a period
(typically 8-12 weeks / a `blok`), teaches accordingly, and evaluates at period end, which feeds the next
cycle's analysis. This is the **groepsplan**. Both ESIS and ParnasSys ship it as core PO functionality.
Scholiq has none of it: `LearningPlan` (the OPP) is strictly per-learner (see `proposal.md` "Why" for the
schema citations), and there is no schema anywhere for a group-level plan, a subgroup, or a group-level
evaluation. This design adds that instrument as a `learning-plan` delta, reusing every adjacent building
block that already exists (`Cohort`, `GradeEntry`, `AssessmentResult`, `SupportRequest`) rather than
duplicating any of them.

## Goals / Non-Goals

**Goals**
- Model the groepsplan cycle: group-level results analysis → differentiated subgroups → period goals →
  evaluation → next period.
- Reference existing results evidence (`GradeEntry`, `AssessmentResult`, `FinalGrade`) rather than
  re-entering data or inventing a parallel results schema.
- Link cleanly to the per-learner `LearningPlan` (OPP) and to the `SupportRequest` escalation chain without
  duplicating either.
- Reuse the exact declarative machinery `LearningPlan` already established (lifecycle shape, version-chain
  `supersedesId`, declared notification/calculation pattern) rather than inventing new mechanisms.

**Non-Goals**
- Importing actual Cito/LVS toets results. No import path exists anywhere in this repo today (verified by
  full-repo grep, see `proposal.md`); this change's `resultsAnalysis.evidenceRefs` is schema-ready to
  reference such results once they land as `GradeEntry` rows via a future `data-exchange`/`openconnector`
  connector, but building that connector is explicitly out of scope here.
- Auto-clustering learners into subgroups from results data. A human teacher decides subgroup membership;
  this change models the record of that decision, not a recommendation/classification algorithm (which
  would also raise `AiFeature`-gate questions this change does not need to answer).
- Co-signing / DigiD assurance for the groepsplan. Unlike the OPP, a groepsplan is an internal
  teaching-team planning artefact with no statutory co-sign requirement — see "Rejected Alternatives" below.

## Data Model

```
Cohort ──< GroupPlan (cohortId, subject, period, periodEndDate, coordinatorId)
              │  resultsAnalysis { narrative, evidenceRefs[] } ── UUIDs of GradeEntry / AssessmentResult /
              │                                                    FinalGrade / prior GroupPlanEvaluation
              │  goals[] (group-wide period goals; same shape as LearningPlan.goals)
              │  x-openregister-calculations: periodEndDue
              │  x-openregister-notifications: periodEndReminder → coordinatorId
              │  lifecycle: draft → active → under-evaluation → closed | superseded
              │  supersedesId ──► prior period's GroupPlan (same field LearningPlan already has)
              │
              ├──< GroupPlanSubgroup (groupPlanId, name, instructieniveau, learnerIds[],
              │                       differentiatedGoal, approach, intendedOutcome)
              │        │
              │        │  NO learningPlanId / learningPlanIds field — resolved at read time by
              │        │  GroupPlanSubgroupLearnerContext.vue (learnerIds → LearningPlan lookup)
              │        │
              │        └──  SupportRequest.originGroupPlanSubgroupId (reverse link, on SupportRequest)
              │
              └──< GroupPlanEvaluation (groupPlanId, evaluatedAt, evaluatedBy,
                                          outcomes[]: { subgroupId, outcome, narrative }, narrative)
```

### `GroupPlan`

One row per `(cohortId, subject, period)` — mirrors how `LearningPlan` is one row per `(learnerId,
kind, period)`-ish granularity, just one level up. `subject` is required (a groepsplan is always scoped to
a leerlijn/vak — "rekenen-wiskunde", "technisch lezen" — never school-wide), unlike `LearningPlan.courseId`
which is optional context. `resultsAnalysis` is the field that makes this results-driven rather than a
blank planning form: `evidenceRefs` is the same "array of UUIDs of evidence objects" shape
`LearningPlan.goals[].evidenceRefs` already uses, just referenced at the plan level instead of per-goal,
because a groepsplan's whole premise (unlike an individual OPP goal) is "here is the toets round this plan
responds to."

`periodEndDue` (`x-openregister-calculations`) and `periodEndReminder`
(`x-openregister-notifications`) are the direct structural copy of `LearningPlan.nextReviewDue` /
`quarterlyReviewReminder` — same `and([ne(prop, null), lte(prop, now)])` JSON-logic shape, same
idempotency-keyed declared notification, no PHP `TimedJob`. There is no new mechanism here to justify or
defend; it is the existing pattern applied to a new date field.

### `GroupPlanSubgroup`

`learnerIds` is a plain array of Nextcloud user IDs, the same convention `Cohort.learnerIds` already uses
(not a `$ref` to a `LearnerProfile` relation object — this codebase's convention throughout is
`learnerId`/`learnerIds` as NC-user-id strings, e.g. `LearningPlan.learnerId`,
`AssessmentResult.learnerId`). `instructieniveau` defaults to the three standard levels but allows `custom`
because not every school uses exactly `intensief`/`basis`/`verdiept` naming.

### Why no stored link from GroupPlanSubgroup to LearningPlan or SupportRequest

Two candidate designs were considered for "a learner in the intensief subgroup may already have an OPP":

1. **Store `learningPlanIds` (array) on `GroupPlanSubgroup`.** Rejected — this is exactly the kind of
   denormalised duplication ADR-022 warns against: the authoritative link is already
   `LearningPlan.learnerId`; a second array on the subgroup would need to be kept in sync (a `LearningPlan`
   created, closed, or superseded after the subgroup is saved would silently desync the array) with no
   event wired to maintain it.
2. **Resolve at read time via a lookup from `learnerIds` → `LearningPlan.learnerId`.** Chosen. The
   authoritative link (`LearningPlan.learnerId`) is queried live; there is nothing to desync.

The read-time lookup cannot be expressed as a standard manifest `object-list` widget, though, because every
filter in `src/manifest.json` is a single-value equality match against a static value or `@objectId`
(confirmed by grep — no operator besides implicit equality exists anywhere in the file). `learnerIds` is a
multi-value array, so "find every `LearningPlan` whose `learnerId` is one of these N values" is an
array-membership query the manifest DSL has no syntax for. This is the one place in the change that needs a
named custom view per the architecture rule ("named custom views only where the manifest can't express
it") — `GroupPlanSubgroupLearnerContext.vue`, rendered on the `GroupPlanSubgroup` detail page, which reads
`learnerIds` and issues per-learner (or, if the OpenRegister object API's `findAll` supports multi-value
`in`-style query params at apply time — to be confirmed against `ObjectService::findAll`'s actual filter
support rather than assumed here — a single batched) lookups against `LearningPlan` scoped to `lifecycle:
active`, and renders each member learner alongside their plan link (or "no active plan").

The `SupportRequest` reverse-link is different in shape and does NOT need a custom view: it is a single
scalar field (`originGroupPlanSubgroupId`) on `SupportRequest` pointing at exactly one `GroupPlanSubgroup`,
so "list SupportRequests where `originGroupPlanSubgroupId` equals this subgroup's id" is a perfectly ordinary
single-value equality filter — the same shape as the existing `lpt-plans` widget
(`src/manifest.json:5023-5032`, `filter: { templateId: "@objectId" }`). No custom view is needed for that
half; it is a standard `object-list` widget on the `GroupPlanSubgroup` detail page.

### `GroupPlanEvaluation` and cycle-seeding via `supersedesId`

Two designs were considered for "evaluation can seed the next period's plan":

1. **A forward-pointing `seedsGroupPlanId` field on `GroupPlanEvaluation`.** Rejected — this would be a
   second version-chain mechanism running alongside `GroupPlan.supersedesId`, which already exists on
   `LearningPlan` and already expresses exactly this "new version supersedes the prior one" relationship.
   Two chains pointing in opposite directions for the same real-world relationship (this evaluation → led to
   → this new plan, vs. this new plan → supersedes → that prior plan) is the reject-pattern this design
   avoids: same information, two places to keep in sync.
2. **Reuse `GroupPlan.supersedesId`, no new field.** Chosen. The next period's `GroupPlan` is created with
   `supersedesId` pointing at the prior period's `GroupPlan` — identical to how `LearningPlan.supersedesId`
   already works. The prior plan's `GroupPlanEvaluation` naturally becomes evidence for the new plan's own
   `resultsAnalysis.evidenceRefs` (the "evidence this new analysis is based on" already includes "how the
   last period went"), which is the same `evidenceRefs` mechanism every other results reference in this
   change uses — no special case.

## Rejected Alternatives

- **A `Signature` co-sign requirement on `GroupPlan`, mirroring the OPP.** Rejected — the `LearningPlan`
  spec's `Signature` requirement exists because the OPP is a legally significant, DigiD-signable document
  under Wet Passend Onderwijs, co-signed by parent/learner/coordinator. A groepsplan is an internal
  teaching-team instrument (a klas's differentiated instruction plan) with no statutory co-sign
  requirement anywhere in the HGW/1-zorgroute methodology. Forcing the `Signature` schema onto it would add
  an authentication-assurance concept (DigiD strength) that has no real-world referent here.
  - **Reconsider if:** a specific institution's beleid requires principal sign-off on groepsplannen — at
    that point a lightweight `approvedBy`/`approvedAt` pair on `GroupPlan` (not the full `Signature`
    assurance-level machinery) would be the proportionate addition.
- **A single `GroupPlan`-level `evidenceRefs` shared by every subgroup, with no room for subgroup-specific
  evidence.** Rejected in favour of keeping `evidenceRefs` at the `GroupPlan` level (the analysis that
  produced the subgroup split) while each `GroupPlanSubgroup` carries its own `differentiatedGoal`/
  `approach`/`intendedOutcome` — the analysis is one act over the whole group's results; the differentiation
  it produces is per-subgroup. Splitting `evidenceRefs` per subgroup would fragment one coherent analysis
  into N copies with no clear boundary (a Cito toets round doesn't cleanly partition by subgroup before the
  subgroups are decided from it).
- **New `group-plan` capability instead of a `learning-plan` delta.** Rejected — `learning-plan` already
  grew, in the wave-1 zorgvraag chain, into the umbrella for "individualised and needs-based planning
  instruments that feed into each other" (`LearningPlan` → `SupportRequest` → `TlvApplication` →
  `DeliberationRecord`). The groepsplan is one level up the same HGW/1-zorgroute chain (group analysis can
  escalate to an individual `SupportRequest`, which may already reference or later produce a `LearningPlan`)
  — keeping it in the same capability keeps the whole "needs-based planning cascade" ownership boundary in
  one spec, matching how `attendance` stayed its own capability rather than being folded elsewhere because
  its ownership boundary was already clean; here the reverse is true — `GroupPlan` has no ownership boundary
  independent of the chain it feeds into.

## Security / Privacy Posture

- `GroupPlan`/`GroupPlanSubgroup`/`GroupPlanEvaluation` are not `appendOnly` — unlike `SupportRequest`'s
  chain, a groepsplan is a living planning document during its `active`/`under-evaluation` states (a teacher
  edits goals and approach as the period progresses), matching `LearningPlan` itself (not append-only; only
  its `Signature`/`LearningPlanEvaluation` records are). No `x-openregister-authorization` restriction is
  added — same posture as `LearningPlan`, which has none; groepsplan data is pedagogical planning data, not
  the "most sensitive category" reasoning that justified `SupportRequest`'s `admin`/`principal`-only create
  restriction (`lib/Settings/scholiq_register.json:7266-7269`).
- `originGroupPlanSubgroupId` on `SupportRequest` is additive and nullable — existing `SupportRequest` rows
  are unaffected, and `SupportRequest`'s existing `x-property-rbac` block (admin/principal/`raisedBy`-match
  read) is untouched; the new field carries no new read-exposure since it points at a `GroupPlanSubgroup`
  which itself has no elevated sensitivity.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects (`lib/Settings/scholiq_register.json`); no new database tables
  (ADR-001).
- Declarative-first (ADR-022, ADR-031): `periodEndDue`/`periodEndReminder` reuse the exact
  `LearningPlan.nextReviewDue`/`quarterlyReviewReminder` JSON-logic + declared-notification shape; no PHP
  guard classes, no calculation `engine`, no `TimedJob` anywhere in this change.
- No pass-through CRUD controller — every new object is declarative; the only new frontend code is one named
  custom view for the one read-time array-membership lookup the manifest DSL cannot express.
- UI is manifest-driven (`src/manifest.json`), mirroring the existing `LearningPlan`/`LearningPlanEvaluation`/
  `Signature` index/detail/sub-object-list-widget pattern exactly.
- i18n keys in English (en + nl catalogues); SPDX docblock on the one new Vue file's accompanying tooling if
  any PHP is later found necessary (none is anticipated for this change).
