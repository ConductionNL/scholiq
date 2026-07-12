# Tasks — Exam Board Case Handling (Vrijstelling + Fraud/Plagiarism Dossiers)

> Scope: 2 new schemas (`ExemptionCase`, `FraudCase`), 1 modified schema (`GradeEntry` — new sourceKind,
> 2 new refs, conditional `value` requiredness, 2 new lifecycle transitions on existing + 1 new terminal
> state, new RBAC role), 5 new lifecycle guards, 2 new event listeners (+ `Application.php` registration),
> 1 modified calculation engine (`GradeFormulaEvaluator`), 1 new custom Vue view (`ExamCaseDossierView`),
> manifest pages, l10n, e2e coverage.

## Phase 1: Schema — `ExemptionCase`

- [ ] Add `ExemptionCase` schema to `lib/Settings/scholiq_register.json`: `learnerId` ($ref
      `LearnerProfile`), `curriculumPlanId` ($ref `CurriculumPlan`), `componentId` (string), `groundsKind`
      (enum `prior-diploma|certificate|work-experience|other`), `groundsDescription`, `submittedAt`,
      `decisionRationale` (nullable), `policyReference` (nullable), `decidedBy` (nullable), `decidedAt`
      (nullable), `resultingGradeEntryId` (nullable $ref `GradeEntry`), `tenant_id` (design §7).
- [ ] Add `x-openregister-lifecycle`: `submitted → in-assessment` (`startAssessment`, unguarded),
      `in-assessment → granted|rejected` (`grant`/`reject`, `requires: [ExemptionDecisionGuard]`),
      `[submitted, in-assessment] → withdrawn` (`withdraw`, unguarded, array-`from`) (design §2.1).
- [ ] Add `x-openregister-processing` GDPR metadata (`rechtsgrond: legal-obligation`, WHW art. 7.13),
      matching the `ExternalTrainingRecord` precedent.
- [ ] Add `x-openregister-notifications`: creation → `groups: [examboard]`; `grant`/`reject` →
      `field: learnerId`.

## Phase 2: Schema — `FraudCase`

- [ ] Add `FraudCase` schema: `reporterId`, `accusedLearnerId` ($ref `LearnerProfile`), `sourceKind` (same
      enum as `GradeEntry`), `submissionId`/`assessmentResultId`/`sessionId` (nullable $refs, mirrors
      `GradeEntry`), `contestedGradeEntryId` (nullable $ref `GradeEntry`), `allegation`, `reportedAt`,
      `hearingDate` (nullable), `hearingRecords` (array of `{heldAt, attendees[], notes, evidenceRefs[]}`),
      `verdict` (nullable enum `fraud-proven|unfounded`), `decisionRationale` (nullable), `decidedBy`
      (nullable), `decidedAt` (nullable), `sanctionType` (nullable enum
      `warning|grade-annulment|resubmission-required|suspension|exclusion`), `sanctionDurationMonths`
      (nullable integer, `maximum: 12`), `sanctionScope` (nullable enum
      `single-assessment|course|programme`), `appealDeadline` (nullable), `appealLodged` (boolean, default
      false), `appealOutcome` (nullable enum `pending|upheld|overturned`), `tenant_id` (design §7).
- [ ] Add `x-openregister-lifecycle`: `reported → hearing-scheduled` (`scheduleHearing`, `requires:
      [FraudCaseHearingGuard]`), `hearing-scheduled → heard` (`holdHearing`, unguarded), `heard → decided`
      (`decide`, `requires: [FraudCaseDecisionGuard]`), `[reported, hearing-scheduled] → dismissed`
      (`dismiss`, unguarded, array-`from`) (design §2.2).
- [ ] Add `x-property-rbac.read`: `anyOf: [role: admin, role: examboard, match: accusedLearnerId ==
      $userId, match: reporterId == $userId]` (design §8).
- [ ] Add `x-openregister-processing` GDPR metadata (`rechtsgrond: legal-obligation`, WHW art. 7.13).
- [ ] Add `x-openregister-notifications`: creation → `groups: [examboard]`; `decide` → `field: learnerId`
      (accused, via `accusedLearnerId`) + `field: reporterId`.

## Phase 3: Schema — `GradeEntry` modifications

- [ ] Add `exemption` to `GradeEntry.sourceKind` enum.
- [ ] Add nullable `exemptionCaseId` ($ref `ExemptionCase`) and `fraudCaseId` ($ref `FraudCase`) properties.
- [ ] Make `value` conditionally required: required for every `sourceKind` except `exemption`; reject a
      non-null `value` when `sourceKind: exemption` (JSON Schema `if`/`then` on `sourceKind`, design §7).
- [ ] Add `requires: [FraudCaseBlockGuard]` to the existing `publish` and `republish` transitions.
- [ ] Add new terminal state `invalidated` + transition `invalidate` (`concept → invalidated`, `requires:
      [FraudCaseInvalidationGuard]`).
- [ ] Add `role: examboard` to `GradeEntry.x-property-rbac.read`'s `anyOf` (alongside existing
      `admin`/self-match).
- [ ] Add `role: examboard` to `FinalGrade.x-property-rbac.read`'s `anyOf` (same reasoning as GradeEntry).
- [ ] Add a documentation-level note (schema `description` or comment) on `FinalGrade.breakdown` that
      `components[componentId]` may carry `exempt: true`.
- [ ] Bump `lib/Settings/scholiq_register.json` top-level `info.version` (0.3.1 → next minor — check for a
      collision with any sibling in-flight change's version bump before merging, design §7 note).
- [ ] Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); run
      `npm run check:json-strict` and `npm run check:register` — both PASS, no duplicate slugs, no broken
      lifecycle/`requires` references.

## Phase 4: Backend — lifecycle guards (`lib/Lifecycle/`)

- [ ] `ExemptionDecisionGuard.php`: guards `ExemptionCase.grant`/`.reject`. Blocks unless
      `decisionRationale` and `policyReference` are set on the transition payload. No `Application.php`
      registration needed (FQCN-resolved by OR, design §4).
- [ ] `FraudCaseHearingGuard.php`: guards `FraudCase.scheduleHearing`. Blocks unless `hearingDate` is set.
- [ ] `FraudCaseDecisionGuard.php`: guards `FraudCase.decide`. Blocks unless `verdict` +
      `decisionRationale` are set; when `verdict: fraud-proven`, additionally blocks unless `sanctionType`,
      `sanctionDurationMonths` (≤ 12), and `sanctionScope` are set. On success, stamps `decidedAt` (now) and
      `appealDeadline` (`decidedAt` + 42 days, via `DateTimeImmutable::modify('+42 days')`) onto the payload
      — not a declarative calculation (design §3).
- [ ] `FraudCaseBlockGuard.php`: guards `GradeEntry.publish`/`.republish`. If `fraudCaseId` unset, allow. If
      set, fetch the `FraudCase`; block while `reported|hearing-scheduled|heard`, or permanently if
      `decided` with `verdict: fraud-proven`; allow if `decided` with `verdict: unfounded`, or `dismissed`
      (design §5, mirrors `AttendanceFlagReportGuard`'s cross-schema-read shape).
- [ ] `FraudCaseInvalidationGuard.php`: guards `GradeEntry.invalidate`. Allow only if `fraudCaseId` is set
      and the linked `FraudCase` is `decided` with `verdict: fraud-proven` (design §5).
- [ ] Unit tests for all five guards under `tests/Unit/Lifecycle/`, covering both the block and allow paths
      for each (mirror the existing `ExternalTrainingVerificationGuardTest`/`AttendanceFlagReportGuardTest`
      structure if present).

## Phase 5: Backend — event listeners (`lib/Listener/`)

- [ ] `ExemptionGrantHandler.php`: `IEventListener<ObjectTransitionedEvent>`. Filters to
      `register=scholiq, schema=exemption-case, to=granted`. Creates a `GradeEntry`
      (`sourceKind: exemption`, `value: null`, `curriculumPlanId`/`componentId` copied from the case,
      `exemptionCaseId` set), then drives it through the *existing* `publish` transition (not a raw field
      write) so the standard audit trail and `gradePublished` notification fire unchanged (design §4).
- [ ] `FraudCaseDecisionHandler.php`: `IEventListener<ObjectTransitionedEvent>`. Filters to
      `register=scholiq, schema=fraud-case, to=decided`. If `verdict: fraud-proven` and
      `contestedGradeEntryId` is set: fetch that `GradeEntry`; if `lifecycle === 'concept'`, drive it through
      `invalidate`; otherwise log a warning and take no action (design §4).
- [ ] Register both listeners in `lib/AppInfo/Application.php::register()` via
      `$context->registerEventListener(event: ObjectTransitionedEvent::class, listener: X::class)`, matching
      the existing `ExcuseApprovalHandler`/`GradeRollupHandler` registration pattern (design §4 — this step
      is easy to forget since guards do *not* need it).
- [ ] Unit tests for both handlers under `tests/Unit/Listener/`.

## Phase 6: Backend — `GradeFormulaEvaluator` extension

- [ ] `weightedAverage()`: skip `sourceKind === 'exemption'` entries from `$weightedSum`/`$totalWeight`
      accumulation (overall and per-period); emit `$componentBreakdown[$cid] = ['exempt' => true]` for those
      components instead of `{value, weight, contribution}` (design §6).
- [ ] `evaluatePassed()`'s `all-must-pass` branch: when a component's best entry (via `bestOfNEntries()`) has
      `sourceKind === 'exemption'`, treat that component's `passRules` threshold as satisfied without a
      numeric comparison (design §6).
- [ ] Extend `tests/Unit/Grading/GradeFormulaEvaluatorTest.php`: (a) an exemption entry does not corrupt a
      `weighted-average` roll-up (assert the final value matches the non-exempt entries only, and
      `breakdown.components[cid].exempt === true`); (b) an exemption satisfies an `all-must-pass` component
      without a numeric check.

## Phase 7: Frontend

- [ ] Add `src/manifest.json` index/detail pages for `ExemptionCase` and `FraudCase`.
- [ ] `src/views/ExamCaseDossierView.vue` (new, `type: "custom"`, tab-switched between the two schemas):
      renders the shared case-dossier layout; applies the UI-level withholding of `hearingRecords`/
      decision-internal fields from anyone who is not the accused, the reporter, or an `examboard` member —
      explicitly documented in the component as an application-level convention, not a security boundary
      (design §8).
- [ ] `npm run lint` — 0 errors on the new/modified frontend files.

## Phase 8: i18n

- [ ] Add new keys to `l10n/en.json` and `l10n/nl.json`: `ExemptionCase`/`FraudCase` field labels, lifecycle
      state display labels, `groundsKind`/`sanctionType`/`sanctionScope` enum display labels,
      `ExamCaseDossierView` copy (including the "hearing details are only visible to the exam board" notice
      shown to the accused/reporter).

## Phase 9: e2e coverage

- [ ] Create `tests/e2e/exam-board-exemption.spec.ts`: submit an `ExemptionCase` with evidence, assert
      `grant` is blocked without `decisionRationale`/`policyReference`, assert granting creates a published
      `GradeEntry` (`sourceKind: exemption`, `value: null`) and the learner receives a notification.
- [ ] Create `tests/e2e/exam-board-fraud-case.spec.ts`: file a `FraudCase` against a `GradeEntry`, assert
      `publish`/`republish` on that `GradeEntry` is blocked while the case is open, drive the case through
      `scheduleHearing → holdHearing → decide` with `verdict: fraud-proven` and a sanction, assert the
      linked `GradeEntry` transitions to `invalidated` and never publishes, and assert `appealDeadline` is
      42 days after `decidedAt`.
- [ ] Extend or add a case asserting a `verdict: unfounded` decision unblocks the linked `GradeEntry`'s
      `publish` transition.
- [ ] `npm run test:e2e -- exam-board` PASS.

## Phase 10: Spec-validation gate

- [ ] `npm run check:specs` PASS (`check:json-strict` + `check:manifest` + `check:register`).
- [ ] `openspec validate exam-board-case-handling` PASS.
