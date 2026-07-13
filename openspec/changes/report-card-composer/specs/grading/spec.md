# Grading — Report Period Lock Delta

**Spec refs**: `grading`, `report-card`, ADR-022 (apps consume OR abstractions), ADR-031 (declarative-first;
PHP only for legitimate exceptions)

## MODIFIED Requirements

### Requirement: Persist grading domain objects in OpenRegister

The system MUST persist `GradeScale`, `GradeEntry`, `FinalGrade` as OpenRegister objects. `GradeEntry` has
`x-openregister-lifecycle` (`concept → published → revised`, plus the existing terminal `invalidated` state
reachable only via `invalidate`, guarded by `FraudCaseInvalidationGuard`) and `x-openregister-notifications`
keyed so a re-publish/backfill doesn't double-notify. `GradeEntry.sourceKind` includes `lti-ags` alongside
`assignment-submission`, `assessment-result`, `participation`, `manual`, and `exemption`. `FinalGrade` is
computed via `x-openregister-calculations` + cross-schema aggregation over the learner's published
`GradeEntry`s, parameterised by the `CurriculumPlan.formula` + component weights.

`publish` and `republish` gain a **second** `requires` entry, `ReportPeriodLockGuard` (alongside the
existing `FraudCaseBlockGuard`), mirroring how `certification`'s `Credential.revoke` transition gained
`WalletRevocationPropagationService` as a second `requires` entry without disturbing its first. Before
allowing the transition, `ReportPeriodLockGuard` resolves whether the entry's `period` and its
`curriculumPlanId`'s owning `CurriculumPlan` match any `report-card` `ReportPeriod` (by `periodCode` +
`curriculumPlanIds` containment + `academicYear`) whose declared `isLocked` calculation is `true`. If no
such `ReportPeriod` exists, the guard allows the transition unconditionally — fail-open, mirroring
`AttendanceFlagReportGuard`'s "no linked job → allow unconditionally" posture — so a school not using report
cards, or a `GradeEntry` outside any declared `ReportPeriod`'s scope, is completely unaffected. If a
matching, locked `ReportPeriod` exists, the guard blocks the transition unless the acting user holds the
`admin`, `mentor`, or `principal` role (an explicit override, e.g. a genuine post-lock correction agreed at
the rapportvergadering) — there is no dedicated "coordinator" role in `LearnerProfile.roles` to gate on
(documented platform gap, same as `SupportRequest`/`TlvApplication`'s creation restriction).

#### Scenario: Grading objects persisted in OpenRegister

- **GIVEN** the grading domain schemas are registered
- **WHEN** a `GradeEntry` is published for a learner
- **THEN** `GradeScale`, `GradeEntry`, and `FinalGrade` are stored as OpenRegister objects and the
  `FinalGrade` is computed via `x-openregister-calculations` over the learner's published entries

#### Scenario: publish/republish proceeds unaffected when no ReportPeriod governs the entry

<!-- @e2e exclude Fail-open guard path is backend logic verified by PHPUnit ReportPeriodLockGuardTest; no scholiq DOM surface — the publish action behaves identically to today whenever no ReportPeriod matches. -->

- **GIVEN** a `GradeEntry` whose `period`/`curriculumPlanId` matches no `ReportPeriod` anywhere, or matches
  one that is not `isLocked`
- **WHEN** `publish` or `republish` is attempted
- **THEN** `ReportPeriodLockGuard` allows the transition unconditionally

#### Scenario: An ordinary teacher cannot publish a grade for a locked report period

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `GradeEntry` whose `period` and `curriculumPlanId` match a `ReportPeriod` whose `isLocked`
  calculation is `true`, and the acting user holds no `admin`/`mentor`/`principal` role
- **WHEN** `publish` or `republish` is attempted
- **THEN** `ReportPeriodLockGuard` blocks the transition

#### Scenario: A mentor override publishes a grade for a locked report period

<!-- @e2e exclude Override-role guard path verified by PHPUnit ReportPeriodLockGuardTest; no distinct scholiq DOM surface beyond the existing publish action. -->

- **GIVEN** the same locked-period `GradeEntry`, with the acting user holding the `mentor` role
- **WHEN** `publish` or `republish` is attempted
- **THEN** `ReportPeriodLockGuard` allows the transition
