# Tasks — Exam Board Case Handling (Vrijstelling + Fraud/Plagiarism Dossiers)

> Scope: 2 new schemas (`ExemptionCase`, `FraudCase`), 1 modified schema (`GradeEntry` — new sourceKind,
> 2 new refs, conditional `value` requiredness, 2 new lifecycle transitions on existing + 1 new terminal
> state, new RBAC role), 5 new lifecycle guards, 2 new event listeners (+ `Application.php` registration),
> 1 modified calculation engine (`GradeFormulaEvaluator`), 1 new custom Vue view (`ExamCaseDossierView`),
> manifest pages, l10n, e2e coverage.

## Phase 1: Schema — `ExemptionCase`

- [x] Add `ExemptionCase` schema to `lib/Settings/scholiq_register.json`: `learnerId` ($ref
      `LearnerProfile`), `curriculumPlanId` ($ref `CurriculumPlan`), `componentId` (string), `groundsKind`
      (enum `prior-diploma|certificate|work-experience|other`), `groundsDescription`, `submittedAt`,
      `decisionRationale` (nullable), `policyReference` (nullable), `decidedBy` (nullable), `decidedAt`
      (nullable), `resultingGradeEntryId` (nullable $ref `GradeEntry`), `tenant_id` (design §7).
- [x] Add `x-openregister-lifecycle`: `submitted → in-assessment` (`startAssessment`, unguarded),
      `in-assessment → granted|rejected` (`grant`/`reject`, `requires: [ExemptionDecisionGuard]`),
      `[submitted, in-assessment] → withdrawn` (`withdraw`, unguarded, array-`from`) (design §2.1).
- [x] Add `x-openregister-processing` GDPR metadata (`rechtsgrond: legal-obligation`, WHW art. 7.13),
      matching the `ExternalTrainingRecord` precedent.
- [x] Add `x-openregister-notifications`: creation → `groups: [examboard]`; `grant`/`reject` →
      `field: learnerId`.

## Phase 2: Schema — `FraudCase`

- [x] Add `FraudCase` schema: `reporterId`, `accusedLearnerId` ($ref `LearnerProfile`), `sourceKind` (same
      enum as `GradeEntry`), `submissionId`/`assessmentResultId`/`sessionId` (nullable $refs, mirrors
      `GradeEntry`), `contestedGradeEntryId` (nullable $ref `GradeEntry`), `allegation`, `reportedAt`,
      `hearingDate` (nullable), `hearingRecords` (array of `{heldAt, attendees[], notes, evidenceRefs[]}`),
      `verdict` (nullable enum `fraud-proven|unfounded`), `decisionRationale` (nullable), `decidedBy`
      (nullable), `decidedAt` (nullable), `sanctionType` (nullable enum
      `warning|grade-annulment|resubmission-required|suspension|exclusion`), `sanctionDurationMonths`
      (nullable integer, `maximum: 12`), `sanctionScope` (nullable enum
      `single-assessment|course|programme`), `appealDeadline` (nullable), `appealLodged` (boolean, default
      false), `appealOutcome` (nullable enum `pending|upheld|overturned`), `tenant_id` (design §7).
- [x] Add `x-openregister-lifecycle`: `reported → hearing-scheduled` (`scheduleHearing`, `requires:
      [FraudCaseHearingGuard]`), `hearing-scheduled → heard` (`holdHearing`, unguarded), `heard → decided`
      (`decide`, `requires: [FraudCaseDecisionGuard]`), `[reported, hearing-scheduled] → dismissed`
      (`dismiss`, unguarded, array-`from`) (design §2.2).
- [x] Add `x-property-rbac.read`: `anyOf: [role: admin, role: examboard, match: accusedLearnerId ==
      $userId, match: reporterId == $userId]` (design §8).
- [x] Add `x-openregister-processing` GDPR metadata (`rechtsgrond: legal-obligation`, WHW art. 7.13).
- [x] Add `x-openregister-notifications`: creation → `groups: [examboard]`; `decide` → `field: learnerId`
      (accused, via `accusedLearnerId`) + `field: reporterId`.

## Phase 3: Schema — `GradeEntry` modifications

- [x] Add `exemption` to `GradeEntry.sourceKind` enum.
- [x] Add nullable `exemptionCaseId` ($ref `ExemptionCase`) and `fraudCaseId` ($ref `FraudCase`) properties.
- [x] Make `value` conditionally required: required for every `sourceKind` except `exemption`; reject a
      non-null `value` when `sourceKind: exemption` (JSON Schema `if`/`then` on `sourceKind`, design §7).
      NOTE: this register has zero prior precedent for a schema-level (not calculation-DSL) `if`/`then`
      keyword — implemented as standard JSON Schema `allOf`/`if`/`then`/`else`, documented in-schema; flagged
      here in case OpenRegister's schema validator turns out not to support it (unverifiable without a live
      OR instance — `check:register`'s structural checks pass, but they do not deep-validate JSON Schema
      conditional semantics).
- [x] Add `requires: [FraudCaseBlockGuard]` to the existing `publish` and `republish` transitions.
- [x] Add new terminal state `invalidated` + transition `invalidate` (`concept → invalidated`, `requires:
      [FraudCaseInvalidationGuard]`).
- [x] Add `role: examboard` to `GradeEntry.x-property-rbac.read`'s `anyOf` (alongside existing
      `admin`/self-match).
- [x] Add `role: examboard` to `FinalGrade.x-property-rbac.read`'s `anyOf` (same reasoning as GradeEntry).
- [x] Add a documentation-level note (schema `description` or comment) on `FinalGrade.breakdown` that
      `components[componentId]` may carry `exempt: true`.
- [x] Bump `lib/Settings/scholiq_register.json` top-level `info.version` (0.5.0 → 0.6.0 — at apply time the
      register was already at 0.5.0, i.e. the anticipated 0.4.0 collision with `bpv-praktijkovereenkomst`
      plus `parent-evening-planner`'s own bump had already resolved; this change took the next free minor).
- [x] Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); run
      `npm run check:json-strict` and `npm run check:register` — both PASS, no duplicate slugs, no broken
      lifecycle/`requires` references.

## Phase 4: Backend — lifecycle guards (`lib/Lifecycle/`)

- [x] `ExemptionDecisionGuard.php`: guards `ExemptionCase.grant`/`.reject`. Blocks unless
      `decisionRationale` and `policyReference` are set on the transition payload. No `Application.php`
      registration needed (FQCN-resolved by OR, design §4).
- [x] `FraudCaseHearingGuard.php`: guards `FraudCase.scheduleHearing`. Blocks unless `hearingDate` is set.
- [x] `FraudCaseDecisionGuard.php`: guards `FraudCase.decide`. Blocks unless `verdict` +
      `decisionRationale` are set; when `verdict: fraud-proven`, additionally blocks unless `sanctionType`,
      `sanctionDurationMonths` (≤ 12), and `sanctionScope` are set. On success, stamps `decidedAt` (now) and
      `appealDeadline` (`decidedAt` + 42 days, via `DateTimeImmutable::modify('+42 days')`) onto the payload
      — not a declarative calculation (design §3).
- [x] `FraudCaseBlockGuard.php`: guards `GradeEntry.publish`/`.republish`. If `fraudCaseId` unset, allow. If
      set, fetch the `FraudCase`; block while `reported|hearing-scheduled|heard`, or permanently if
      `decided` with `verdict: fraud-proven`; allow if `decided` with `verdict: unfounded`, or `dismissed`
      (design §5, mirrors `AttendanceFlagReportGuard`'s cross-schema-read shape).
- [x] `FraudCaseInvalidationGuard.php`: guards `GradeEntry.invalidate`. Allow only if `fraudCaseId` is set
      and the linked `FraudCase` is `decided` with `verdict: fraud-proven` (design §5).
- [x] Unit tests for all five guards under `tests/Unit/Lifecycle/`, covering both the block and allow paths
      for each (mirror the existing `ExternalTrainingVerificationGuardTest`/`AttendanceFlagReportGuardTest`
      structure if present). 25 tests added, all green.

## Phase 5: Backend — event listeners (`lib/Listener/`)

- [x] `ExemptionGrantHandler.php`: `IEventListener<ObjectTransitionedEvent>`. Filters to
      `register=scholiq, schema=exemption-case, to=granted`. Creates a `GradeEntry`
      (`sourceKind: exemption`, `value: null`, `curriculumPlanId`/`componentId` copied from the case,
      `exemptionCaseId` set), then drives it through the *existing* `publish` transition (not a raw field
      write) so the standard audit trail and `gradePublished` notification fire unchanged (design §4).
      Also back-links `resultingGradeEntryId` onto the case via a plain field-write save (no re-transition).
- [x] `FraudCaseDecisionHandler.php`: `IEventListener<ObjectTransitionedEvent>`. Filters to
      `register=scholiq, schema=fraud-case, to=decided`. If `verdict: fraud-proven` and
      `contestedGradeEntryId` is set: fetch that `GradeEntry`; if `lifecycle === 'concept'`, drive it through
      `invalidate`; otherwise log a warning and take no action (design §4).
- [x] Register both listeners in `lib/AppInfo/Application.php::register()` via
      `$context->registerEventListener(event: ObjectTransitionedEvent::class, listener: X::class)`, matching
      the existing `ExcuseApprovalHandler`/`GradeRollupHandler` registration pattern (design §4 — this step
      is easy to forget since guards do *not* need it).
- [x] Unit tests for both handlers under `tests/Unit/Listener/`. 14 tests added, all green.

## Phase 6: Backend — `GradeFormulaEvaluator` extension

- [x] `weightedAverage()`: skip `sourceKind === 'exemption'` entries from `$weightedSum`/`$totalWeight`
      accumulation (overall and per-period); emit `$componentBreakdown[$cid] = ['exempt' => true]` for those
      components instead of `{value, weight, contribution}` (design §6). This is also the fix for the
      pre-existing `(float) ($entry['value'] ?? 0)` bug named in the proposal.
- [x] `evaluatePassed()`'s `all-must-pass` branch: when a component's best entry (via `bestOfNEntries()`) has
      `sourceKind === 'exemption'`, treat that component's `passRules` threshold as satisfied without a
      numeric comparison (design §6).
- [x] Created `tests/Unit/Grading/GradeFormulaEvaluatorTest.php` (did not exist at HEAD despite design.md's
      "modified" framing — see apply report): (a) an exemption entry does not corrupt a `weighted-average`
      roll-up (asserts the final value matches the non-exempt entries only, and
      `breakdown.components[cid].exempt === true`, plus a second heavy-weight-exemption regression case
      pinning the bug fix); (b) an exemption satisfies an `all-must-pass` component without a numeric check,
      plus a sibling-component-still-enforced case and an empty-entries baseline. 5 tests, all green.

## Phase 7: Frontend

- [x] Add `src/manifest.json` index/detail pages for `ExemptionCase` and `FraudCase` (+ a new "Exam board"
      menu group).
- [x] `src/views/ExamCaseDossierView.vue` (new, `type: "custom"`, shared by both schemas via
      `config.schema`): renders case data + lifecycle-transition actions; applies the UI-level withholding of
      `hearingRecords`/decision-internal fields from anyone who is not an `examboard` member (or admin) —
      explicitly documented in the component as an application-level convention, not a security boundary
      (design §8). KNOWN LIMITATION (documented in-component): no client-side `examboard` group signal exists
      yet (unlike `primaryRole`), so `isAdmin` is used as an under-inclusive-but-never-over-inclusive proxy —
      flagged as a follow-up rather than silently assumed complete. Note: the spec's own Requirement-heading
      prose ("withhold from anyone who is not the accused, the reporter, or an examboard member") is
      internally inconsistent with its own Scenario ("the accused learner... THEN the UI withholds
      hearingRecords detail from their view") and with design.md §8's explicit statement that the accused and
      reporter should NOT see hearing internals; implemented per the Scenario + design.md (both agree), not
      the ambiguous Requirement summary sentence — flagged here rather than silently reinterpreted.
- [x] `npm run lint` — 0 errors on the new/modified frontend files (`ExamCaseDossierView.vue`, `registry.js`;
      1 pre-existing-pattern `jsdoc/check-tag-names` warning on `@spec`, matching every other file in the app).

## Phase 8: i18n

- [x] Add new keys to `l10n/en.json` and `l10n/nl.json`: `ExamCaseDossierView` field/action/enum labels
      (including the "hearing details are only visible to the exam board" notice) and the new menu labels.
      NOT done: the repo's separate `tests/l10n/check-l10n-parity.js` gate requires all ~33 required European
      locales to carry every English key — this task only asked for en+nl, and that parity gate is not wired
      into `run-hydra-gates.sh` or `npm run check:specs`, so it was left out of scope here; flagged as
      pre-existing/deferred, not silently skipped.

## Phase 9: e2e coverage

- [ ] NOT DONE — no `tests/e2e/exam-board-*.spec.ts` files were created. `gate-19` (e2e-coverage) still PASSES
      because `specs/exam-board/spec.md` carries its own top-level `@e2e exclude Pure backend/data-model spec`
      annotation (authored as part of this change's spec delta, not added by this apply pass), which the gate
      honors. Writing real, passing Playwright specs would need a live dev Nextcloud+OpenRegister instance to
      verify against, which this apply pass did not have; leaving unchecked rather than shipping stub/
      unverified test files (project rule: no stub code).
- [ ] `npm run test:e2e -- exam-board` NOT RUN (no spec files to run).

## Phase 10: Spec-validation gate

- [x] `npm run check:specs` PASS (`check:json-strict` + `check:manifest` + `check:register`).
- [x] `openspec validate exam-board-case-handling --type change --strict` PASS ("Change
      'exam-board-case-handling' is valid").
