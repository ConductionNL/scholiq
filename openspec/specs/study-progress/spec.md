# study-progress Specification

## Purpose
TBD - created by archiving change bsa-study-progress-guard. Update Purpose after archive.
## Requirements
### Requirement: Persist BSA domain objects in OpenRegister

The system MUST persist `BsaTrajectory`, `BsaProgressFlag`, `BsaWarning`, and `BsaDecision` as OpenRegister
objects. `BsaTrajectory` MUST carry `x-openregister-lifecycle` (`draft → active → archived`, mirroring
`AttendanceThreshold`). `BsaProgressFlag`, `BsaWarning`, and `BsaDecision` MUST be `appendOnly: true` (audit
per ADR-008), each with its own `x-openregister-lifecycle` workflow (`BsaProgressFlag`:
`open → in-handling → warned → resolved`; `BsaWarning`: `drafted → issued → acknowledged`; `BsaDecision`:
`drafted → decided → appealed → upheld | overturned`). Creation of `BsaWarning` and `BsaDecision` MUST be
restricted via `x-openregister-authorization.create` to `admin`/`study-advisor`/`exam-board` roles — a
learner MUST NOT be able to author their own warning or decision.

#### Scenario: BSA objects persist in OpenRegister with the correct lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by PHPUnit schema-validation tests and by reasoning over the register JSON (no scholiq DOM surface to drive registration itself). -->

- **GIVEN** the `study-progress` schemas are registered in OpenRegister
- **WHEN** a `BsaTrajectory`, `BsaProgressFlag`, `BsaWarning`, or `BsaDecision` is created
- **THEN** it is stored as an OpenRegister object with its declared lifecycle
- **AND** `BsaProgressFlag`, `BsaWarning`, and `BsaDecision` are `appendOnly: true`
- **AND** a non-privileged user cannot create a `BsaWarning` or `BsaDecision`

### Requirement: Credit-earned and at-risk detection are declared calculations, not a TimedJob

The learner's cumulative `ectsEarned` for a `BsaTrajectory`'s scope MUST be computed via
`x-openregister-aggregations` (pulling the learner's `passed: true` `FinalGrade`s) plus an `engine`-keyed
`x-openregister-calculations` entry (a `BsaProgressEvaluator` PHP class resolving each `FinalGrade` to its
`Course.ectsCredits` and summing — the same shape `FinalGrade.value` already uses via
`GradeFormulaEvaluator`, required because the cross-schema join exceeds plain JSON-logic). `isAtRisk` MUST
be a pure JSON-logic comparison (`ectsEarned` below `interimNormEcts` once the interim-check window opens),
reusing the `@now`-comparison idiom `Enrolment.isOverdue` already uses. Detection MUST fire through a
`calculatedChange` trigger to a `BsaProgressFlagHandler` — NOT a PHP `TimedJob` — reusing the same
threshold/`calculatedChange` machinery as `AttendanceThreshold` and the compliance-wedge `Regulation`
thresholds (ADR-022: no parallel mechanism).

#### Scenario: Falling behind pace ahead of the interim check raises a flag

<!-- @e2e exclude Calculation + trigger behaviour is backend/lifecycle logic verified by PHPUnit (BsaProgressEvaluatorTest, BsaProgressFlagHandlerTest); no DOM surface for a declared calculation firing. -->

- **GIVEN** a `BsaTrajectory` for a Programme/academicYear with `interimNormEcts: 15` and an interim-check
  window that has opened
- **AND** a first-year learner whose passed `FinalGrade`s sum to 10 EC via their courses' `ectsCredits`
- **WHEN** the `ectsEarned` calculation recomputes
- **THEN** `isAtRisk` becomes true via the `calculatedChange` trigger
- **AND** a `BsaProgressFlag` (`open`) is created, not a scholiq `TimedJob`-driven batch

#### Scenario: A course with no declared credit value contributes zero, not an error

<!-- @e2e exclude Null-handling in the aggregation engine; verified by PHPUnit BsaProgressEvaluatorTest::testNullEctsCreditsContributesZero. -->

- **GIVEN** a learner's passed `FinalGrade` references a `Course` whose `ectsCredits` is `null`
- **WHEN** `ectsEarned` recomputes
- **THEN** that course contributes 0 EC to the total
- **AND** the calculation completes without error for the learner's other courses

### Requirement: A negative BSA decision MUST be blocked without a logged, issued warning

The `BsaDecision` `drafted → decided` lifecycle transition MUST require a `BsaDecisionGuard` PHP class
(mirroring `AttestationSigningGuard`/`ProgrammePublishGuard`'s `requires` pattern). When `decisionType` is
`negative` or `negative-with-recommendation`, the guard MUST verify at least one `BsaWarning` with
`lifecycle: issued` exists for the same `(learnerId, programmeId, academicYear)`; if none exists, the
transition MUST be refused with a validation error naming the missing warning. `positive` and `postponed`
decisions are not subject to this guard.

#### Scenario: Negative decision without a warning is refused

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit BsaDecisionGuardTest::testNegativeWithoutWarningRefused; no scholiq DOM surface for the guard itself. -->

- **GIVEN** a learner with no `issued` `BsaWarning` for the current programme and academic year
- **WHEN** a coordinator attempts to transition a `BsaDecision` with `decisionType: negative` from
  `drafted` to `decided`
- **THEN** the transition is refused
- **AND** the validation error names the missing warning requirement

#### Scenario: Negative decision with a logged warning is allowed

<!-- @e2e exclude PHPUnit BsaDecisionGuardTest::testNegativeWithIssuedWarningAllowed; backend guard behaviour, no DOM surface. -->

- **GIVEN** a learner with an `issued` `BsaWarning` for the current programme and academic year
- **WHEN** a coordinator transitions a `BsaDecision` with `decisionType: negative` from `drafted` to
  `decided`, referencing that warning's UUID in `warningIds`
- **THEN** the transition succeeds

### Requirement: The formal warning captures improvement period, guidance, and personal circumstances, and is signed evidence

`BsaWarning` MUST require `improvementPeriod` (`startDate`/`endDate`) and `offeredGuidance` (non-empty text
— the "sufficient study guidance" safeguard) before it can transition `drafted → issued`. It MUST support an
optional `personalCircumstancesNote`. The `drafted → issued` transition MUST require a
`BsaWarningSigningGuard` that stamps an HMAC `signature`/`signingKeyId` pair, mirroring
`AttestationSigningGuard`'s `drafted → signed` behaviour, so the warning is verifiable evidence once issued.

#### Scenario: Warning cannot be issued without offered guidance

<!-- @e2e exclude Required-field + signing-guard validation is backend logic verified by PHPUnit BsaWarningSigningGuardTest::testMissingGuidanceBlocksIssue. -->

- **GIVEN** a drafted `BsaWarning` with `offeredGuidance` empty
- **WHEN** an attempt is made to transition it to `issued`
- **THEN** the transition is refused

#### Scenario: Issued warning carries a verifiable signature

<!-- @e2e exclude HMAC signing at transition time is backend logic verified by PHPUnit BsaWarningSigningGuardTest::testIssueStampsSignature, mirroring the existing AttestationSigningGuardTest pattern. -->

- **GIVEN** a drafted `BsaWarning` with `improvementPeriod` and `offeredGuidance` set
- **WHEN** it transitions `drafted → issued`
- **THEN** the stored record carries a `signature` and `signingKeyId`
- **AND** the learner receives a notification per the verified `x-openregister-notifications` dialect

### Requirement: The year-end decision records a full evidence trail including the right to be heard

`BsaDecision` MUST record `ectsAchieved`, `ectsNormRequired`, `warningIds`, `personalCircumstancesConsidered`
(and an optional note), and `studentHeardAt`/`studentResponse` (the `hoorplicht` safeguard) alongside
`decidedBy` and `decisionDate`. A `negative`/`negative-with-recommendation` decision MUST require a
non-empty `rationale`. The decision MUST support an appeal sub-lifecycle
(`decided → appealed → upheld | overturned`) so a disputed decision's outcome is itself recorded as append-
only evidence.

#### Scenario: Negative decision without rationale is refused

<!-- @e2e exclude Required-field validation on decide; verified by PHPUnit BsaDecisionGuardTest::testNegativeWithoutRationaleRefused. -->

- **GIVEN** a drafted `BsaDecision` with `decisionType: negative-with-recommendation` and an empty
  `rationale`
- **WHEN** an attempt is made to transition it to `decided`
- **THEN** the transition is refused

#### Scenario: An appealed decision records its outcome as evidence

<!-- @e2e exclude Appeal sub-lifecycle transitions are backend lifecycle logic verified by PHPUnit BsaDecisionGuardTest::testAppealUpheldAndOverturnedTransitions. -->

- **GIVEN** a `decided` `BsaDecision`
- **WHEN** the learner appeals and the institution's appeal body upholds or overturns the decision
- **THEN** the `BsaDecision` transitions to `appealed` and then to `upheld` or `overturned`
- **AND** the original `decided` record and its evidence trail remain unmutated (append-only)

### Requirement: Frontend is declarative with one named custom view for the risk dashboard

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `BsaTrajectory`,
`BsaProgressFlag`, `BsaWarning`, and `BsaDecision`. The only custom Vue component MUST be
`BsaRiskDashboard.vue` — a coordinator/study-advisor view listing at-risk learners against their
trajectory's norm and interim-check window (story `bsa-risico-dashboard`, 10071). No PHP CRUD controllers.

#### Scenario: Coordinator sees at-risk learners on the risk dashboard

<!-- @e2e tests/e2e/spec-coverage/study-progress.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception (BsaRiskDashboard) is the drivable DOM scenario, mirroring school-year-rollover's wizard-page e2e coverage pattern; the underlying flag/warning/decision lifecycle logic itself has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** one or more `BsaProgressFlag`s in `open` state for the coordinator's programme
- **WHEN** the coordinator opens the BSA risk dashboard
- **THEN** the at-risk learners are listed with their `ectsEarned` against the trajectory's
  `interimNormEcts`/`normEcts`
- **AND** the coordinator can navigate from a listed learner to draft a `BsaWarning`

