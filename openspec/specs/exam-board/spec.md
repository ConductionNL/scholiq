# exam-board Specification

## Purpose
TBD - created by archiving change exam-board-case-handling. Update Purpose after archive.
## Requirements
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

