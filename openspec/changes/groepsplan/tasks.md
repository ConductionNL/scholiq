# Tasks: groepsplan

## 1. Schema — learning-plan delta

- [ ] 1.1 Add `GroupPlan` to `lib/Settings/scholiq_register.json`: `cohortId` ($ref `Cohort`, required),
  `subject` (required), `period`, `periodEndDate` (nullable date), `coordinatorId` (required),
  `resultsAnalysis` (`{ narrative, evidenceRefs: uuid[] }`), `goals[]` (same shape as
  `LearningPlan.goals[]`), `supersedesId` (nullable $ref `GroupPlan`), `tenant_id`, `lifecycle`
  (`draft → active → under-evaluation → closed | superseded`, `x-openregister-lifecycle` matching
  `LearningPlan`'s transitions exactly), `x-openregister-calculations.periodEndDue` (same
  `and([ne(prop,null), lte(prop, now)])` shape as `LearningPlan.nextReviewDue`),
  `x-openregister-notifications.periodEndReminder` (recipient: `coordinatorId`, idempotency-keyed,
  NL/EN subject).
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-persist-groupplan-groupplansubgroup-and-groupplanevaluation-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Schema validates against the OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `lifecycle` transitions and `periodEndDue` calculation structurally mirror `LearningPlan`'s
- [ ] 1.2 Add `GroupPlanSubgroup`: `groupPlanId` ($ref `GroupPlan`, required), `name` (required),
  `instructieniveau` (`intensief | basis | verdiept | custom`, required), `learnerIds` (array of NC user
  IDs, same convention as `Cohort.learnerIds`), `differentiatedGoal` (required), `approach` (required),
  `intendedOutcome` (nullable), `tenant_id`. No `learningPlanId`/`learningPlanIds` field — see
  `design.md` "Why no stored link".
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-groupplansubgroup-differentiates-instructieniveau-and-links-to-without-duplicating-learningplan-and-supportrequest`
  - **acceptance_criteria**:
    - `learnerIds` array present; no denormalised `LearningPlan`/`SupportRequest` reference field added
- [ ] 1.3 Add `GroupPlanEvaluation`: `groupPlanId` ($ref `GroupPlan`, required), `evaluatedAt`,
  `evaluatedBy`, `outcomes[]` (`{ subgroupId, outcome: met|partially-met|not-met, narrative }`),
  `narrative`, `tenant_id`.
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-groupplanevaluation-closes-the-hgw-cycle-and-the-next-periods-plan-supersedes-the-prior-one`
  - **acceptance_criteria**:
    - `outcomes[]` supports one entry per subgroup with an `outcome` enum
- [ ] 1.4 Add nullable `originGroupPlanSubgroupId` ($ref `GroupPlanSubgroup`) to the existing
  `SupportRequest` schema (`lib/Settings/scholiq_register.json:7151-7181` region), alongside the existing
  `learningPlanId`. Purely additive; do not touch `required`.
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-persist-supportrequest-tlvapplication-and-deliberationrecord-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Existing `SupportRequest` rows validate unchanged (`originGroupPlanSubgroupId` absent/`null`)
    - A `SupportRequest` can be created with `originGroupPlanSubgroupId` set and `learningPlanId` null, or
      vice versa, or both, or neither

## 2. Frontend — manifest pages

- [ ] 2.1 Add `src/manifest.json` index+detail pages for `GroupPlan`, `GroupPlanSubgroup`,
  `GroupPlanEvaluation`, mirroring the existing `LearningPlan`/`LearningPlanEvaluation`/`Signature` page
  triad: `GroupPlan` detail's body leads with plan data (2-column) plus a stats-block/object-list of its
  `GroupPlanSubgroup`s and `GroupPlanEvaluation`s (filter `groupPlanId: @objectId`, mirroring `lp-evals`
  at `src/manifest.json:5188-5225`); `GroupPlanSubgroup` detail's Related panel resolves `groupPlanId`
  plus an object-list of `SupportRequest`s filtered `originGroupPlanSubgroupId: @objectId`.
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-groupplan-frontend-is-declarative-with-one-named-custom-view-for-the-cross-schema-learner-lookup`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added
    - `GroupPlanSubgroup` detail's SupportRequest list uses a standard equality-filtered `object-list`
      widget, not a custom view
- [ ] 2.2 Add a nav entry ("Group plans") in `src/manifest.json`'s nav section near the existing
  "Learning plans" entry (`src/manifest.json:92-97`).
  - **spec_ref**: `specs/learning-plan/spec.md#requirement-groupplan-frontend-is-declarative-with-one-named-custom-view-for-the-cross-schema-learner-lookup`
  - **acceptance_criteria**:
    - Nav entry routes to the `GroupPlans` index page

## 3. Frontend — the one custom view

- [ ] 3.1 Add `src/views/GroupPlanSubgroupLearnerContext.vue`: given the current `GroupPlanSubgroup`'s
  `learnerIds`, look up each learner's active `LearningPlan` (`learnerId` match, `lifecycle: active`) via
  the OpenRegister object API through the existing Pinia object store (confirm at implementation time
  whether `ObjectService::findAll` supports a multi-value `learnerId` filter for a single batched query, or
  whether per-learner lookups are required — see `design.md`'s "to be confirmed" note), and render each
  member learner with a link to their active `LearningPlan` detail page, or "No active learning plan" if
  none. Strings via `t()`; no DOM reads for data (loadState/object API only); any `NcSelect` carries
  `inputLabel`. Register it as the `GroupPlanSubgroup` detail page's learner-context widget slot in
  `src/manifest.json`.
  - **spec_ref**: `specs/learning-plan/spec.md#scenario-a-subgroup-members-existing-learningplan-is-surfaced-without-a-duplicate-field`
  - **acceptance_criteria**:
    - Renders seeded subgroup members, correctly distinguishing "has an active LearningPlan" from "does
      not"
    - No `learningPlanId`/`learningPlanIds` field is read from `GroupPlanSubgroup` itself (the lookup is
      live)

## 4. Tests and docs

- [ ] 4.1 Add `tests/e2e/spec-coverage/groepsplan.spec.ts` (Playwright): a coordinator opens a seeded
  `GroupPlan`, views its subgroups, opens a `GroupPlanSubgroup` detail page, and sees
  `GroupPlanSubgroupLearnerContext.vue` correctly show an active `LearningPlan` link for a seeded member
  learner who has one.
  - **spec_ref**: `specs/learning-plan/spec.md#scenario-a-subgroup-members-existing-learningplan-is-surfaced-without-a-duplicate-field`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` reference in the spec scenarios
- [ ] 4.2 Add Dutch and English translations for all new i18n keys (notification subjects, view strings,
  manifest page titles).
  - **spec_ref**: all `learning-plan` groepsplan requirements
  - **acceptance_criteria**:
    - No hardcoded strings; `nl`/`en` both populated
- [ ] 4.3 Add `x-openregister-seed` fixtures for `GroupPlan`/`GroupPlanSubgroup`/`GroupPlanEvaluation`
  covering: an active plan with an `intensief`/`basis`/`verdiept` split, one `intensief`-subgroup learner
  who already has an active `LearningPlan`, and a closed prior-period plan referenced via `supersedesId`.
  - **spec_ref**: all `learning-plan` groepsplan requirements
  - **acceptance_criteria**:
    - Seed data exercises the cross-lookup scenario (task 3.1) and the version-chain scenario end to end

## 5. Verify

- [ ] 5.1 `openspec validate groepsplan --strict` clean.
- [ ] 5.2 `composer check:strict` on any touched PHP (none anticipated — this change is declarative schema
  + manifest + one Vue view; if implementation surfaces a need for PHP, re-check against `design.md`'s
  "Non-Goals"/"Rejected Alternatives" before adding it).
  - **acceptance_criteria**:
    - Strict validation passes; no dangling `$ref`s in the register JSON; PHPUnit suite remains green
      (no PHP added or modified by this change unless a genuine gap is found during apply)
