## ADDED Requirements

### Requirement: Persist GroupPlan, GroupPlanSubgroup, and GroupPlanEvaluation domain objects in OpenRegister

The system MUST persist `GroupPlan`, `GroupPlanSubgroup`, and `GroupPlanEvaluation` as OpenRegister objects.
`GroupPlan` MUST carry `x-openregister-lifecycle` (`draft → active → under-evaluation → closed |
superseded`, identical to the existing `LearningPlan` lifecycle), `x-openregister-relations`
(`GroupPlan`↔`Cohort`, `GroupPlanSubgroup`↔`GroupPlan`, `GroupPlanEvaluation`↔`GroupPlan`), and
`x-openregister-calculations` (`periodEndDue`, mirroring `LearningPlan.nextReviewDue`'s pattern). A
`periodEndReminder` notification MUST be declared via `x-openregister-notifications`, idempotency-keyed,
firing off `GroupPlan.periodEndDate` — reusing the same declared-notification/calculation-trigger machinery
as `LearningPlan.quarterlyReviewReminder`, NOT a PHP `TimedJob` (ADR-022).

#### Scenario: GroupPlan objects persisted with the same lifecycle shape as LearningPlan

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface exercises schema registration itself — verified by reasoning over the register JSON plus PHPUnit schema-validation coverage at apply time. -->

- **GIVEN** the `learning-plan` domain schemas are registered
- **WHEN** a coordinator saves a `GroupPlan` with its subgroups and evaluations
- **THEN** `GroupPlan`, `GroupPlanSubgroup`, and `GroupPlanEvaluation` are stored as OpenRegister objects
  carrying the declared lifecycle, relations, and calculation config
- **AND** `GroupPlan.lifecycle` starts `draft` and follows `draft → active → under-evaluation → closed |
  superseded`

#### Scenario: Period-end reminder is a declared notification, not a TimedJob

<!-- @e2e exclude Declared-notification trigger behaviour is backend/lifecycle logic with no DOM surface; verified by PHPUnit at apply time, mirroring the existing quarterlyReviewReminder coverage. -->

- **GIVEN** an active `GroupPlan` with a `periodEndDate` set
- **WHEN** `periodEndDate` arrives
- **THEN** a `periodEndReminder` notification fires to the `coordinatorId` via the declared notification
  mechanism, idempotency-keyed so a re-tick does not double-fire, with no PHP `TimedJob` involved

### Requirement: GroupPlan's results analysis references existing grade/assessment evidence, not a duplicate results schema

`GroupPlan.resultsAnalysis` MUST be a narrative plus `evidenceRefs` — an array of UUIDs of existing
evidence objects (`GradeEntry`, `AssessmentResult`, `FinalGrade`, or a prior `GroupPlanEvaluation`) the
analysis is based on, following the exact pattern already established by
`LearningPlan.goals[].evidenceRefs`. The system MUST NOT introduce a Cito/LVS-specific results schema in
this change: no such schema exists anywhere in the register at the time of this change, and importing
actual Cito/LVS toets scores is out of scope here — it is a follow-up `data-exchange`/`openconnector`
connector that would land scores as ordinary `GradeEntry` rows (`sourceKind`), reusable by this same
`evidenceRefs` mechanism without any schema change to `GroupPlan`.

#### Scenario: Results analysis references existing GradeEntry/AssessmentResult evidence

<!-- @e2e exclude Reference-array persistence with no cross-schema validation logic beyond the existing evidenceRefs pattern; no new DOM surface beyond the standard object-list/data widgets already covered by the frontend requirement below. -->

- **GIVEN** a `Cohort` with published `GradeEntry`s and `AssessmentResult`s for its learners
- **WHEN** a teacher creates a `GroupPlan` for that cohort and records a `resultsAnalysis`
- **THEN** the `evidenceRefs` array stores UUIDs of the referenced `GradeEntry`/`AssessmentResult`/
  `FinalGrade` objects
- **AND** no new results/toets schema is created — the existing objects are referenced, not re-entered

#### Scenario: No Cito/LVS import exists yet — analysis degrades to the generic evidence reference, not a blocked create

<!-- @e2e exclude Absence-of-a-feature assertion with no behaviour to drive through the DOM; verified by the repo-wide grep recorded in proposal.md "Why" and re-checked at apply time. -->

- **GIVEN** a school whose Cito/LVS toets results have not been imported into Scholiq (no import path
  exists at the time of this change)
- **WHEN** a teacher creates a `GroupPlan`'s `resultsAnalysis`
- **THEN** the analysis MAY still be recorded, referencing whatever `GradeEntry`/`AssessmentResult`
  evidence already exists, or as narrative-only with an empty `evidenceRefs`
- **AND** the `GroupPlan` creation is NOT blocked on a Cito/LVS import that does not exist

### Requirement: GroupPlanSubgroup differentiates instructieniveau and links to, without duplicating, LearningPlan and SupportRequest

`GroupPlanSubgroup` MUST carry `learnerIds` (array of Nextcloud user IDs, same convention as
`Cohort.learnerIds`), `instructieniveau` (`intensief | basis | verdiept | custom`), a `differentiatedGoal`,
an `approach`, and an `intendedOutcome`. The system MUST NOT store a denormalised link from
`GroupPlanSubgroup` to a learner's `LearningPlan` or `SupportRequest` — a learner already carries both via
their own `learnerId`; `GroupPlanSubgroup` MUST instead be resolvable to those objects at read time via a
learner-ID lookup (see the frontend requirement below for the one named view this requires, since the
manifest's equality-only filter DSL cannot express an array-membership match against `learnerIds`).

#### Scenario: A subgroup member's existing LearningPlan is surfaced without a duplicate field

<!-- @e2e tests/e2e/spec-coverage/groepsplan.spec.ts -->

- **GIVEN** a `GroupPlanSubgroup` with `instructieniveau: intensief` whose `learnerIds` includes a learner
  who already has an `active` `LearningPlan`
- **WHEN** a coordinator opens the `GroupPlanSubgroup` detail page
- **THEN** that learner's active `LearningPlan` is shown as context
- **AND** `GroupPlanSubgroup`'s own schema carries no `learningPlanId`/`learningPlanIds` field — the link is
  resolved, not stored

#### Scenario: Insufficient group-level differentiation escalates to a SupportRequest, linked back to its origin

<!-- @e2e exclude Reuses the existing SupportRequest submit flow (already covered by the wave-1 zorgvraag-chain change); this scenario only asserts the new originGroupPlanSubgroupId field is populated, which is plain object-list-filter behaviour with no new DOM interaction. -->

- **GIVEN** a learner in a `GroupPlanSubgroup` for whom the subgroup's differentiated approach is judged
  insufficient
- **WHEN** a coordinator raises a `SupportRequest` for that learner from the subgroup context
- **THEN** the `SupportRequest` is created with `originGroupPlanSubgroupId` set to that `GroupPlanSubgroup`
- **AND** the `GroupPlanSubgroup` detail page can list SupportRequests raised from it via a standard
  equality-filtered object-list widget (`originGroupPlanSubgroupId: @objectId`) — no schema change to
  `GroupPlanSubgroup` itself

### Requirement: GroupPlanEvaluation closes the HGW cycle and the next period's plan supersedes the prior one

`GroupPlanEvaluation` MUST record, at period end, a per-subgroup `outcomes` array (`subgroupId`, `outcome`:
`met | partially-met | not-met`, narrative) plus an overall narrative, `evaluatedBy`, and `evaluatedAt`.
Starting the next period's `GroupPlan` MUST reuse `GroupPlan.supersedesId` (the same version-chain field
`LearningPlan` already has) to link forward from the prior period's plan — the system MUST NOT introduce a
second, forward-pointing "seeds next plan" field on `GroupPlanEvaluation`; the new period's `GroupPlan`
typically references the prior plan's final `GroupPlanEvaluation` in its own `resultsAnalysis.evidenceRefs`
instead.

#### Scenario: Evaluating a GroupPlan records a per-subgroup outcome

<!-- @e2e exclude Pure object-create/lifecycle-transition behaviour, no new DOM interaction beyond the standard object-list/data widgets already exercised by the frontend requirement's e2e scenario. -->

- **GIVEN** an active `GroupPlan` with two `GroupPlanSubgroup`s, moved to `under-evaluation`
- **WHEN** a coordinator records a `GroupPlanEvaluation` with an `outcome` for each subgroup
- **THEN** the `GroupPlanEvaluation` persists the per-subgroup outcomes and overall narrative
- **AND** the `GroupPlan` can transition to `closed`

#### Scenario: A new period's GroupPlan supersedes the prior one and can cite its evaluation as evidence

<!-- @e2e exclude Version-chain assertion identical in shape to LearningPlan.supersedesId, already the established pattern; no new DOM surface. -->

- **GIVEN** a `closed` `GroupPlan` for a cohort/subject/period with a recorded `GroupPlanEvaluation`
- **WHEN** a coordinator creates the next period's `GroupPlan` for the same cohort/subject
- **THEN** the new `GroupPlan.supersedesId` references the prior plan
- **AND** the new plan's `resultsAnalysis.evidenceRefs` MAY include the prior plan's
  `GroupPlanEvaluation` UUID as analysis evidence

### Requirement: GroupPlan frontend is declarative with one named custom view for the cross-schema learner lookup

Frontend MUST be declarative: `src/manifest.json` index+detail pages for `GroupPlan`/`GroupPlanSubgroup`/
`GroupPlanEvaluation`, following the same page-and-sub-object-list-widget convention already used by
`LearningPlan`/`LearningPlanEvaluation`/`Signature`. Exactly one named custom view,
`GroupPlanSubgroupLearnerContext.vue`, MUST render on the `GroupPlanSubgroup` detail page to resolve each
member learner's active `LearningPlan` (if any) — the one lookup the manifest's equality-only `object-list`
filter DSL cannot express, since `learnerIds` is a multi-value array and no filter in `src/manifest.json`
supports array-membership matching. There MUST be no PHP CRUD controller.

#### Scenario: Pages are manifest-declared; the one array-membership lookup uses a named custom view

<!-- @e2e tests/e2e/spec-coverage/groepsplan.spec.ts -->

- **GIVEN** the `learning-plan` frontend is configured
- **WHEN** the app renders `GroupPlan`/`GroupPlanSubgroup`/`GroupPlanEvaluation` index and detail screens
- **THEN** index/detail pages come from `src/manifest.json`
- **AND** the `GroupPlanSubgroup` detail page's learner-context panel is the one named custom view,
  `GroupPlanSubgroupLearnerContext.vue`
- **AND** there is no PHP CRUD controller for any of the three new schemas

## MODIFIED Requirements

### Requirement: Persist SupportRequest, TlvApplication, and DeliberationRecord domain objects in OpenRegister

The system MUST persist `SupportRequest`, `TlvApplication`, and `DeliberationRecord` as OpenRegister
objects with `x-openregister-lifecycle` (`SupportRequest`: draft → submitted → routed-to-swv →
in-deliberation → decided → closed; `TlvApplication`: draft → submitted → under-review → decided
(approved | rejected | conditional) → expired; `DeliberationRecord`: `appendOnly: true`, scheduled →
recorded), `x-openregister-relations` (`SupportRequest`↔learner/optional-`LearningPlan`/optional-
`GroupPlanSubgroup`, `TlvApplication`↔`SupportRequest`, `DeliberationRecord`↔`SupportRequest`/
`TlvApplication`), `x-openregister-calculations` (`TlvApplication.tlvExpiringSoon`), and
`x-openregister-notifications` (`supportRequestRouted`, `tlvDecisionReceived`, `tlvExpiringSoon`,
idempotency-keyed). `DeliberationRecord` MUST be `appendOnly: true` for audit (ADR-008), matching the
existing `LearningPlanEvaluation`/`Signature` pattern. `SupportRequest` MUST additionally carry a nullable
`originGroupPlanSubgroupId` ($ref `GroupPlanSubgroup`), alongside the existing nullable `learningPlanId`,
recording that this zorgvraag was raised because a group-level differentiated approach proved insufficient
for this learner — nullable and independent of `learningPlanId`, since a request may originate from a
`GroupPlanSubgroup`, from an existing `LearningPlan`, from both, or from neither.

#### Scenario: Support-chain objects persisted in OpenRegister

- **GIVEN** the learning-plan domain schemas are registered
- **WHEN** a coordinator creates a `SupportRequest`, a `TlvApplication`, and records a `DeliberationRecord`
- **THEN** all three are stored as OpenRegister objects carrying their declared lifecycle, relations,
  calculations, and notification config, and `DeliberationRecord` is `appendOnly: true`

#### Scenario: SupportRequest raised from a GroupPlanSubgroup carries its origin, independent of any LearningPlan link

<!-- @e2e exclude Reuses the existing SupportRequest submit flow; this scenario only asserts the new field is populated independently of learningPlanId, which is plain schema/relation behaviour with no new DOM interaction. -->

- **GIVEN** a learner with no `LearningPlan` but who is a member of a `GroupPlanSubgroup`
- **WHEN** a coordinator raises a `SupportRequest` for that learner from the subgroup context
- **THEN** the `SupportRequest` is created with `originGroupPlanSubgroupId` set and `learningPlanId` left
  null
- **AND** the request is not blocked by the absence of a `LearningPlan`
