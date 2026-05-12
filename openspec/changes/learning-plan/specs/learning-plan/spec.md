---
slug: learning-plan
title: Individual Learning Plan
status: implemented
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [opp-passend-onderwijs, handelingsplan, iep, pdp-he, idp-corporate]
---

# Individual Learning Plan

## Why

Some learners need an individualised plan: a school pupil with extra ondersteuningsbehoeften, a university student on a remediation track, an employee on a personal-development plan. The structure is the same — a set of goals, the support measures in place, a review cycle with dated evaluations, and co-signatures (learner / parent / coordinator co-sign each version). In the Netherlands the Wet Passend Onderwijs makes the Ontwikkelingsperspectief (OPP) mandatory for pupils with extra needs. This generalises it: `LearningPlan` is the abstract document; the Dutch OPP is the `kind: "opp"` profile, a `handelingsplan`, a US `IEP`, an HE `PDP`, a corporate `IDP` are others. The DigiD/eIDAS authentication handshake is out of scope — a `Signature` records *that* someone signed which version with what assurance; the auth flow is a `data-exchange`/openconnector concern.

## ADDED Requirements

### Requirement: LearningPlanTemplate schema persistence

The system SHALL persist `LearningPlanTemplate` objects with a `kind` enum, a `sections` list, `goalDomains`, `requiredSignerRoles`, and a default review cadence, with lifecycle states draft, active, and archived.

#### Scenario: An OPP template structures a new plan

GIVEN a `LearningPlanTemplate` with `kind: "opp"`, `goalDomains: ["leren-en-ontwikkeling","werkhouding","sociaal-emotioneel","fysiek-medisch"]`, and `requiredSignerRoles: ["learner","parent","coordinator"]`
WHEN a coordinator creates a `LearningPlan` with `kind: "opp"` and that `templateId`
THEN the plan SHALL be pre-structured with the template's sections and goal domains, and its `requiredSignerRoles` for activation SHALL be the template's.

### Requirement: LearningPlan schema persistence, versioning, and co-sign guard

The system SHALL persist `LearningPlan` objects with lifecycle states draft, active, under-evaluation, closed, and superseded; SHALL block the `activate` transition via `LearningPlanSignatureGuard` unless every role in the template's `requiredSignerRoles` has a `Signature` on that plan version with at least the required eIDAS assurance level (`substantial` for a parent when `kind === "opp"`, `basic` otherwise); and SHALL, when a new version is activated, transition the version it supersedes to `superseded`.

#### Scenario: Activation blocked without all required signatures

GIVEN a `LearningPlan` in `draft` whose template requires learner, parent, and coordinator signatures, with only the coordinator having signed this version
WHEN the `activate` transition is requested
THEN the transition SHALL be blocked and the plan SHALL remain in `draft`.

#### Scenario: Parent signature on an OPP must be at least substantial

GIVEN an OPP `LearningPlan` in `draft` with learner and coordinator signatures at `basic` assurance and a parent signature at `basic` assurance
WHEN the `activate` transition is requested
THEN the transition SHALL be blocked because the parent signature does not meet the `substantial` minimum for an OPP.

#### Scenario: New version supersedes the prior one

GIVEN an `active` `LearningPlan` version 1 and a `draft` version 2 with `supersedesId` pointing at version 1 and all required signatures present on version 2
WHEN version 2's `activate` transition succeeds
THEN version 2 SHALL be `active` and version 1 SHALL be `superseded`.

#### Scenario: Quarterly review reminder fires when due — not on a timer

GIVEN an `active` `LearningPlan` whose `nextReviewAt` reaches today
WHEN the calculated `nextReviewDue` flips to true
THEN the `quarterlyReviewReminder` notification SHALL fire to the `coordinatorId` exactly once for that `nextReviewAt` (idempotency-keyed), driven by the `calculatedChange` trigger and not by a TimedJob.

#### Scenario: Goal counts are calculated

GIVEN a `LearningPlan` with four goals, two of them `status: met`
WHEN the plan is read
THEN `goalsMetCount` SHALL be 2 and `goalsTotalCount` SHALL be 4.

### Requirement: LearningPlanEvaluation schema persistence and goal-status update

The system SHALL persist `LearningPlanEvaluation` objects as append-only evidence with lifecycle states draft and recorded; and SHALL, on `record`, update the parent `LearningPlan`'s `goals[].status` per the evaluation's `goalOutcomes` (met → met, adjusted → adjusted, dropped → dropped, continued → unchanged) and set the plan's `nextReviewAt` from the evaluation's `nextReviewAt`.

#### Scenario: Recording an evaluation closes a goal

GIVEN an `active` `LearningPlan` with an `open` goal G and a `draft` `LearningPlanEvaluation` whose `goalOutcomes` includes `{ goalId: G, outcome: "met" }` and a `nextReviewAt` three months out
WHEN the evaluation's `record` transition completes
THEN goal G's `status` SHALL be `met` and the plan's `nextReviewAt` SHALL be the evaluation's `nextReviewAt`.

#### Scenario: Evaluations are immutable

GIVEN a `recorded` `LearningPlanEvaluation`
WHEN an update or delete of that object is attempted
THEN OpenRegister SHALL reject it because the schema is `appendOnly: true`.

### Requirement: Signature schema persistence

The system SHALL persist `Signature` objects as append-only facts recording the signer, the signer's role, the subject (a `LearningPlan`) and subject version signed, the timestamp, the eIDAS assurance level, and the method; and SHALL NOT perform the DigiD/eIDAS authentication handshake itself.

#### Scenario: A DigiD sign records substantial assurance

GIVEN a parent co-signing a `LearningPlan` version via the `digid` method
WHEN the signature is recorded
THEN a `Signature` SHALL be created with `signerRole: "parent"`, `method: "digid"`, `assuranceLevel: "substantial"`, and the `subjectId` + `subjectVersion` of the signed plan; the actual DigiD redirect is out of scope of this app.

### Requirement: Declarative frontend

The system SHALL expose `LearningPlanTemplate`, `LearningPlan`, `LearningPlanEvaluation`, and `Signature` via manifest-declared pages, and SHALL provide the co-sign and goal-editing flows as custom Vue components registered through `CnAppRoot`, with no bespoke CRUD controllers.

#### Scenario: Manifest validates

GIVEN the app's `src/manifest.json`
WHEN it is validated against the `@conduction/nextcloud-vue` manifest schema
THEN validation SHALL pass with zero errors.
