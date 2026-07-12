---
slug: exam-board
title: Exam Board — Vrijstelling (Exemption) and Fraud/Plagiarism Case Handling
status: in-progress
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-07-12
updated: 2026-07-12
profiles: [examencommissie-mbo, examencommissie-he]
---

# Exam Board — Vrijstelling and Fraud Case Handling

@e2e exclude Pure backend/data-model spec. Requirements define OpenRegister schema shapes, lifecycle guards,
cross-schema event handlers, and a shared custom detail view — no `#### Scenario:` heading in this file
describes UI interaction beyond the declarative manifest pages already covered by other specs' UI
conventions.

## Purpose

The exam board (examencommissie) is a legally-required governance body for MBO/HE (WHW art. 7.13 mandates an
independent examencommissie for every HO programme; the Inspectorate's Sept-2025 review found boards
"function sufficiently but there are concerns" — insight 1148). Two of its core casework flows have zero
support in Scholiq today: granting an **exemption (vrijstelling)** from a curriculum component on the
strength of prior evidence (a diploma, certificate, or work experience), and running a **fraud/plagiarism
case** through report → hearing → reasoned decision → sanction while the contested grade stays blocked from
publication. Journeys 1744 ("requests arrive by mail with loose evidence; decisions not propagated to
progress systems") and 1745 ("case dossiers assembled ad hoc from mails; the grade must stay blocked during
the case; decisions must survive CBE appeal with full hearing records") name exactly the two gaps this spec
closes. Stories 10068 (`vrijstelling-aanvraag-indienen`), 10069 (`vrijstelling-besluit-doorwerking`), and
10070 (`fraudezaak-dossier-blokkade`) give this spec's acceptance criteria.

## What

- **ExemptionCase** — a learner's exemption request against one `CurriculumPlan.components[]` entry
  (`curriculumPlanId` + `componentId`, the same pair `GradeEntry` already scores against): `groundsKind`
  (`prior-diploma | certificate | work-experience | other`) + free-text grounds, OpenRegister file
  attachments as evidence (the `ExternalTrainingRecord` pattern — no `attachments` schema property, OR's
  native `@self.files` attachment list), and a lifecycle `submitted → in-assessment → granted | rejected |
  withdrawn`. `grant`/`reject` require a `decisionRationale` + `policyReference`
  (`ExemptionDecisionGuard`). On `grant`, `ExemptionGrantHandler` creates a `GradeEntry` with `sourceKind:
  exemption`, `value: null`, links `exemptionCaseId`, and drives it through the *existing* `publish`
  transition so the audit trail and `gradePublished` notification fire unchanged.
- **FraudCase** — report → hearing record(s) → reasoned decision → sanction. Linked to the contested work via
  the same `sourceKind`/`submissionId`/`assessmentResultId`/`sessionId` shape `GradeEntry` already uses (a
  case can be filed before a `GradeEntry` exists), plus an optional `contestedGradeEntryId` once one does.
  Lifecycle `reported → hearing-scheduled → heard → decided` (+ `dismissed` from either `reported` or
  `hearing-scheduled`). `decide` requires a verdict + rationale (`FraudCaseDecisionGuard`); when
  `verdict: fraud-proven`, a sanction is required (`sanctionType`, `sanctionDurationMonths` capped at 12 —
  "up to one-year exclusion" per source 6597 / story 10070 — and `sanctionScope`). `appealDeadline` is
  computed and stamped by `FraudCaseDecisionGuard` at `decidedAt + 42 days` (the CBE 6-week window named in
  journey 1745), alongside `appealLodged`/`appealOutcome` fields recording the appeal itself.
- **GradeEntry publication guard** (see `grading` capability delta for the full requirement text): a linked,
  undecided or fraud-proven `FraudCase` blocks `GradeEntry.publish`/`republish`; a `concept` `GradeEntry`
  linked to a `decided`/`fraud-proven` `FraudCase` is auto-invalidated via a new `invalidate` transition.
- **Roll-up awareness of exemptions** (see `grading` capability delta): `GradeFormulaEvaluator` excludes
  `sourceKind: exemption` entries from the weighted-average numeric sum/denominator while still satisfying
  their component's completion requirement; `FinalGrade.breakdown.components[componentId]` gains an
  `exempt: true` marker.
- **Declarative UI**: `src/manifest.json` index/detail pages for `ExemptionCase` and `FraudCase`; one custom
  `ExamCaseDossierView` (shared, tab-switched) because hiding `hearingRecords`/decision internals from
  everyone except the accused, the reporter, and exam-board members is genuine conditional-rendering logic a
  manifest detail page cannot express (see design.md's Security Considerations for the precise RBAC/UI
  split).

## User Stories

- As a learner, I want to submit an exemption request with my evidence attached and grounds stated, so the
  exam board can assess it without me having to email loose documents.
- As an exam-board member, I want to grant or reject an exemption with a recorded rationale and policy
  reference, so the decision is auditable and consistent with the school's exemption policy (handreiking).
- As a learner who is granted an exemption, I want it to count toward my curriculum completion without a
  fabricated numeric mark corrupting my average.
- As a teacher, I want to report a suspected fraud/plagiarism case against a submission, so it goes into a
  formal dossier rather than an ad-hoc email thread.
- As an exam-board member, I want the contested grade to stay blocked from publication for the full duration
  of the case, so a student never sees a grade later invalidated for fraud.
- As an accused learner, I want to know my case exists and its outcome, without necessarily seeing every
  internal hearing note before the board has decided.
- As an exam-board member, I want a decided fraud case to record an appeal deadline automatically, so the CBE
  6-week appeal window is never missed or miscalculated.

## Acceptance Criteria

- GIVEN a learner submits an `ExemptionCase` with evidence attachments and `groundsKind`, WHEN it is created,
  THEN its lifecycle is `submitted` and it is visible to exam-board members and the requesting learner.
- GIVEN an `ExemptionCase` is `in-assessment`, WHEN an exam-board member attempts `grant` or `reject` without
  a `decisionRationale` and `policyReference`, THEN `ExemptionDecisionGuard` blocks the transition.
- GIVEN an `ExemptionCase` is granted, WHEN the `grant` transition completes, THEN a `GradeEntry` with
  `sourceKind: exemption`, `value: null`, and `exemptionCaseId` set is created and published through the
  existing `publish` transition, triggering the existing `gradePublished` notification.
- GIVEN a `GradeEntry` has a linked `FraudCase` in `reported`, `hearing-scheduled`, or `heard`, WHEN a
  `publish` or `republish` transition is attempted, THEN `FraudCaseBlockGuard` blocks it.
- GIVEN a `FraudCase` reaches `decided` with `verdict: fraud-proven` and a linked `GradeEntry` is still
  `concept`, WHEN `FraudCaseDecisionHandler` processes the transition, THEN the `GradeEntry` transitions to
  `invalidated` via `invalidate`, guarded by `FraudCaseInvalidationGuard`.
- GIVEN a `FraudCase` is decided with `verdict: fraud-proven`, WHEN `decide` completes, THEN
  `sanctionType`/`sanctionDurationMonths` (≤ 12)/`sanctionScope` are set and `appealDeadline` is stamped at
  `decidedAt + 42 days`.
- GIVEN an accused learner, a reporter, or an exam-board member views a `FraudCase`, WHEN
  `ExamCaseDossierView` renders it, THEN the object is readable per `x-property-rbac`, and the UI — not the
  server — additionally withholds `hearingRecords`/decision-internal detail from anyone who is not the
  accused, the reporter, or an exam-board member.

## Requirements

## ADDED Requirements

### Requirement: Persist exam-board domain objects in OpenRegister
The system MUST persist `ExemptionCase` and `FraudCase` as OpenRegister objects. `ExemptionCase` has
`x-openregister-lifecycle` `submitted → in-assessment → granted | rejected | withdrawn`. `FraudCase` has
`x-openregister-lifecycle` `reported → hearing-scheduled → heard → decided`, with `dismissed` reachable from
`reported` or `hearing-scheduled`. Both carry `x-openregister-processing` GDPR metadata (`rechtsgrond:
legal-obligation`, per WHW art. 7.13) matching the fleet's `ExternalTrainingRecord` precedent for
sensitive-record schemas.

#### Scenario: Exam-board objects persisted in OpenRegister
- **GIVEN** the exam-board domain schemas are registered
- **WHEN** a learner creates an `ExemptionCase` and a teacher creates a `FraudCase`
- **THEN** each is stored as an OpenRegister object carrying its declared `x-openregister-lifecycle` and
  `x-openregister-processing` GDPR metadata

### Requirement: ExemptionCase evidence uses OpenRegister file attachments
`ExemptionCase` MUST attach evidence via OpenRegister's native file-attachment mechanism (`@self.files`), not
a bespoke schema property — the same convention `ExternalTrainingVerificationGuard` already reads for
`ExternalTrainingRecord`.

#### Scenario: Evidence attachments are read via the OR attachment list
- **GIVEN** an `ExemptionCase` with one or more uploaded evidence files
- **WHEN** `ExemptionDecisionGuard` or the detail view inspects the case
- **THEN** evidence is read from the object's OpenRegister attachment list (`@self.files`), not a schema
  property that duplicates file storage

### Requirement: ExemptionCase decisions require a rationale and policy reference
`ExemptionCase.grant` and `.reject` MUST NOT succeed unless `decisionRationale` and `policyReference` are set
on the transition payload, enforced by `ExemptionDecisionGuard` (an ADR-031 lifecycle-guard exception —
combines an evidence-completeness precondition with actor-role scoping, neither expressible declaratively).

#### Scenario: Grant or reject blocked without a rationale and policy reference
- **GIVEN** an `ExemptionCase` in `in-assessment` with no `decisionRationale`/`policyReference` supplied
- **WHEN** an exam-board member attempts `grant` or `reject`
- **THEN** `ExemptionDecisionGuard` blocks the transition
- **AND** once both fields are supplied, the transition succeeds

### Requirement: A granted exemption feeds grading through the existing publish path
On `ExemptionCase.grant`, the system MUST create a `GradeEntry` via `ExemptionGrantHandler` (an
`ObjectTransitionedEvent` listener, the same cross-schema-side-effect shape as `ExcuseApprovalHandler`) with
`sourceKind: exemption`, `value: null`, `curriculumPlanId`/`componentId` copied from the `ExemptionCase`, and
`exemptionCaseId` set to the case's id, then drive that `GradeEntry` through its *existing* `publish`
transition (not a raw field write), so the standard audit trail and `gradePublished` notification fire
unchanged.

#### Scenario: Granting an exemption creates and publishes a GradeEntry
- **GIVEN** an `ExemptionCase` for `curriculumPlanId`/`componentId` reaches `granted`
- **WHEN** `ExemptionGrantHandler` processes the transition
- **THEN** a `GradeEntry` with `sourceKind: exemption`, `value: null`, and `exemptionCaseId` set is created
  and published via the existing `publish` transition
- **AND** the existing `gradePublished` notification fires exactly as it would for any other published entry

### Requirement: FraudCase links to contested work before or after a GradeEntry exists
`FraudCase` MUST carry the same `sourceKind`/`submissionId`/`assessmentResultId`/`sessionId` shape as
`GradeEntry`, so a case can be filed the moment a submission or result exists, plus an optional nullable
`contestedGradeEntryId` set once a `GradeEntry` exists for the same work.

#### Scenario: A fraud case is filed before any GradeEntry exists
- **GIVEN** a `Submission` with no associated `GradeEntry` yet
- **WHEN** a teacher files a `FraudCase` with `sourceKind: assignment-submission` and `submissionId` set
- **THEN** the `FraudCase` is created with `contestedGradeEntryId: null`
- **AND WHEN** a `GradeEntry` for that submission is later created, it is possible to set
  `contestedGradeEntryId` to link them

### Requirement: FraudCase decisions require a verdict, rationale, and — when fraud is proven — a capped sanction
`FraudCase.decide` (`heard → decided`) MUST NOT succeed unless `verdict` (`fraud-proven | unfounded`) and
`decisionRationale` are set. When `verdict: fraud-proven`, `sanctionType`, `sanctionDurationMonths` (integer,
maximum 12 — "up to one-year exclusion" per source 6597 / story 10070), and `sanctionScope` MUST also be set.
Enforced by `FraudCaseDecisionGuard` (an ADR-031 lifecycle-guard exception).

#### Scenario: Decide blocked without a verdict and rationale
- **GIVEN** a `FraudCase` in `heard` with no `verdict`/`decisionRationale` supplied
- **WHEN** an exam-board member attempts `decide`
- **THEN** `FraudCaseDecisionGuard` blocks the transition

#### Scenario: A fraud-proven verdict requires a capped sanction
- **GIVEN** a `FraudCase` in `heard` with `verdict: fraud-proven` and `decisionRationale` set, but no
  sanction fields
- **WHEN** `decide` is attempted
- **THEN** `FraudCaseDecisionGuard` blocks it until `sanctionType`, `sanctionScope`, and a
  `sanctionDurationMonths` of at most 12 are supplied

### Requirement: A decided FraudCase stamps a 42-day appeal deadline
When `FraudCase.decide` succeeds, `FraudCaseDecisionGuard` MUST stamp `appealDeadline` onto the transition
payload as `decidedAt` + 42 days (the CBE 6-week appeal window, journey 1745) — a guard-stamped computed
field, the same pattern `ExternalTrainingVerificationGuard` uses for `verifiedBy`/`verifiedAt`, not a
declarative `x-openregister-calculations` expression (this register's calculation DSL has confirmed
precedent only for `today()` comparisons, not date arithmetic).

#### Scenario: Deciding a case stamps the appeal deadline
- **GIVEN** a `FraudCase` in `heard` with a valid decision payload
- **WHEN** `decide` succeeds
- **THEN** `decidedAt` is set to the current time and `appealDeadline` is stamped at `decidedAt + 42 days`

### Requirement: A fraud-proven decision invalidates a still-concept contested GradeEntry
The system MUST drive a linked, still-`concept` `GradeEntry` through its new `invalidate` transition
(`concept → invalidated`) when its `FraudCase` completes `decide` with `verdict: fraud-proven`, via
`FraudCaseDecisionHandler` (an `ObjectTransitionedEvent` listener), guarded by `FraudCaseInvalidationGuard`,
which verifies the linked `FraudCase` is `decided` with `verdict: fraud-proven`. This is the terminal path —
invalidated `GradeEntry`s are never published.

#### Scenario: A fraud-proven decision invalidates the blocked, still-concept entry
- **GIVEN** a `GradeEntry` in `concept` with `fraudCaseId` set, blocked from publishing by
  `FraudCaseBlockGuard` while its `FraudCase` is open
- **WHEN** the `FraudCase` reaches `decided` with `verdict: fraud-proven`
- **THEN** `FraudCaseDecisionHandler` drives the `GradeEntry` through `invalidate`
- **AND** the `GradeEntry` never becomes `published`

### Requirement: ExemptionCase and FraudCase notifications reach exam-board members and the affected parties
`ExemptionCase` creation MUST notify `groups: [examboard]`; `grant`/`reject` MUST notify the requesting
learner (`field: learnerId`). `FraudCase` creation MUST notify `groups: [examboard]`; `decide` MUST notify
both the accused learner and the reporter (`field: learnerId`, `field: reporterId`) — the verified notification
dialect only (ADR-031), no imperative dispatch.

#### Scenario: Exam-board members are notified of a new case
- **GIVEN** the `examboard` NC group has one or more members
- **WHEN** a learner creates an `ExemptionCase` or a teacher creates a `FraudCase`
- **THEN** every member of `examboard` receives a notification via the declared `x-openregister-notifications`
  rule

#### Scenario: Affected parties are notified of a decision
- **GIVEN** an `ExemptionCase` reaches `granted`/`rejected`, or a `FraudCase` reaches `decided`
- **WHEN** the transition completes
- **THEN** the requesting learner (exemption) or the accused learner and reporter (fraud case) receive a
  notification per the declared rule

### Requirement: FraudCase read access is restricted; hearing/decision internals are UI-gated within that set
`FraudCase.x-property-rbac.read` MUST restrict read access to `admin`, the `examboard` role, the accused
learner (`accusedLearnerId` match), and the reporter (`reporterId` match) — fail-closed for anyone else, the
same `anyOf`-role-plus-self-match shape as `ExternalTrainingRecord`. Within that readable set,
`ExamCaseDossierView` MUST additionally withhold `hearingRecords` and decision-internal detail from anyone
who is not the accused, the reporter, or an `examboard` member — this is an application-level UI convention,
**not** a server-enforced field-level RBAC guarantee (this register has no field-level read/write RBAC
primitive at HEAD, the same documented residual gap as `ProctoringSession.flags[].reviewDecision`); anyone
holding object-level read access can retrieve the full object via the generic object API.

#### Scenario: An uninvolved user cannot read a FraudCase at all
- **GIVEN** a user who is not `admin`, not an `examboard` member, not the accused learner, and not the
  reporter
- **WHEN** that user requests the `FraudCase` object
- **THEN** the request is denied by `x-property-rbac.read` (fail-closed)

#### Scenario: The accused learner can read the case but the UI withholds hearing internals pre-decision
- **GIVEN** the accused learner, who has object-level read access to their own `FraudCase`
- **WHEN** they open `ExamCaseDossierView` for a case still `hearing-scheduled` or `heard`
- **THEN** the UI withholds `hearingRecords` detail from their view
- **AND** this withholding is a client-side convention only — the accused learner's read grant at the
  OpenRegister API layer is unchanged and unrestricted at the field level

### Requirement: Frontend is declarative with one shared custom detail view
The frontend MUST be declarative: `src/manifest.json` index/detail pages for `ExemptionCase` and `FraudCase`.
The only custom page is `ExamCaseDossierView` (`type: "custom"`, tab-switched between the two schemas),
needed because the read-vs-display split in the previous requirement is genuine conditional-rendering logic a
manifest detail page cannot express. No PHP CRUD controllers.

#### Scenario: Pages are manifest-declared with one shared dossier-view exception
- **GIVEN** the exam-board frontend is configured
- **WHEN** the app renders `ExemptionCase` and `FraudCase` screens
- **THEN** index/detail pages come from `src/manifest.json` and the only custom page is
  `ExamCaseDossierView`, with no PHP CRUD controllers

## Standards

WHW art. 7.13 (independent examencommissie mandate); Kennispunt MBO exemption guidance (external source 6592,
`onderwijsenexaminering.nl/examinering/vrijstellingen` — reasoned, consistent, individual decisions);
Universiteit Leiden fraud-reporting process (source 6597 — report → suspend grading → board decides → board
alone sanctions, up to one-year exclusion); CBE (College van Beroep voor de Examens) 6-week (42-day) appeal
window.

## Data Model

All in OpenRegister. New: `ExemptionCase`, `FraudCase`. Touches: `GradeEntry`/`FinalGrade` (`grading` — see
that capability's delta), `CurriculumPlan` (`school-structure`), `Submission` (`assignments`),
`AssessmentResult` (`assessment`), `Session` (participation). Two ADR-031 PHP exceptions beyond the guards
already listed above: none additional — all case-handling logic lives in the named guards/handlers. See
`docs/Technical/architecture.md`.

## Out of Scope

- The actual plagiarism-detection algorithm or vendor integration — `assignments/spec.md`'s
  `x-plagiarism.provider` hook remains a detection-*signal* seam only; this spec consumes a suspected-fraud
  report however it originates (a plagiarism-provider flag, a teacher's manual observation, or otherwise) and
  is agnostic to its source.
- CBE (College van Beroep voor de Examens) appeal *adjudication* — this spec records `appealLodged` and
  `appealOutcome` as case facts; it does not model the CBE's own hearing/decision process, which is an
  external body outside Scholiq.
- Automatic escalation, reminders, or SLA tracking on open cases beyond the `appealDeadline` stamp — a
  follow-up notification/reminder concern, not in this M-sized change.
- Retroactive re-scoring or GPA-wide recalculation triggered by a sanction beyond the single invalidated
  `GradeEntry` — cascading effects on a learner's broader academic standing are a school-policy decision
  outside this spec's scope.
