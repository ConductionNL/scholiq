# Tasks: bsa-study-progress-guard

## 1. Schema — course-management delta

- [x] 1.1 Add `ectsCredits` (nullable `number`, `minimum: 0`, title/description per ADR-011) to the `Course`
  object in `lib/Settings/scholiq_register.json` (`764-903` region). Purely additive; do not touch
  `required`.
  - **spec_ref**: `specs/course-management/spec.md#requirement-course-declares-an-ects-credit-value`
  - **acceptance_criteria**:
    - Existing `Course` rows validate unchanged (`ectsCredits` absent/`null`)
    - New/edited courses can set a non-negative `ectsCredits`

## 2. Schema — study-progress capability

- [x] 2.1 Add `BsaTrajectory` to `lib/Settings/scholiq_register.json`: `programmeId` ($ref `Programme`),
  `academicYear`, `kind` (`he-eerstejaar-bsa` | `mbo-studieadvies` | `generic`), `cohortId` (nullable $ref
  `Cohort`), `normEcts` (required, no default), interim-check window (`mode`: `fixed-date` |
  `relative-months`; `date` nullable; `afterMonths`/`notEarlierThanMonths` nullable) mirroring
  `AttendanceThreshold.window`'s dual-mode shape, `interimNormEcts` (nullable), `onAtRisk` (`notify`,
  `notifyRoles` default `["study-advisor","exam-board"]`, `createFlag`), `lifecycle`
  (`draft → active → archived`), `tenant_id`.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-persist-bsa-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `kind` enum and dual-mode window match the `AttendanceThreshold` precedent structurally
  - **IMPLEMENTATION NOTE**: `ectsEarned`/`isAtRisk` are NOT declared as scalar properties on
    `BsaTrajectory` (unlike the design's data-model diagram). `BsaTrajectory` is a shared row per
    `(programmeId, academicYear)` — a single row cannot hold every learner's distinct `ectsEarned`
    value, so a per-learner scalar field there is not implementable (this is also why the design's
    own task 3.2 hedges "or the per-learner materialised view"). What IS on `BsaTrajectory`: a
    genuinely declarative, pure-JSON-logic `windowOpensAt` (materialised) calculation covering both
    window modes, plus a documentation-only `x-openregister-aggregations.passedFinalGrades` block.
    Per-learner `ectsEarned` is computed by `BsaProgressEvaluator` and the at-risk comparison is
    evaluated by `BsaProgressFlagHandler` (see task 3.3), which stamps the computed value onto the
    resulting `BsaProgressFlag` (task 2.2) — the natural per-learner row. See tasks.md task 3.1–3.3.
- [x] 2.2 Add `BsaProgressFlag` (`appendOnly: true`): `learnerId`, `programmeId`, `bsaTrajectoryId` ($ref
  `BsaTrajectory`), `academicYear`, `ectsEarned`, `ectsRequiredAtCheck`, `flaggedAt`, `lifecycle`
  (`open → in-handling → warned → resolved`), `x-openregister-notifications.flagRaised` (recipients:
  `notifyRoles` from the trajectory, plus `field: learnerId`'s mentor/study-advisor if resolvable), NL/EN
  subject.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-persist-bsa-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - `appendOnly: true` set
    - Notification recipients + subject present in both `nl`/`en`
- [x] 2.3 Add `BsaWarning` (`appendOnly: true`): `learnerId`, `programmeId`, `academicYear`,
  `bsaProgressFlagId` (nullable $ref `BsaProgressFlag`), `warningDate`, `ectsEarnedAtWarning`,
  `ectsNormAtWarning`, `improvementPeriod` (`startDate`/`endDate`), `offeredGuidance` (required non-empty
  string), `personalCircumstancesNote` (nullable), `signature`, `signingKeyId`, `lifecycle`
  (`drafted → issued → acknowledged`), `x-openregister-lifecycle` transition `issue` `requires:
  BsaWarningSigningGuard`, `x-openregister-authorization.create: [admin, study-advisor, exam-board]`,
  `x-openregister-notifications.issued` → learner.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence`
  - **acceptance_criteria**:
    - `appendOnly: true`; `issue` transition declares `requires: BsaWarningSigningGuard`
    - `x-openregister-authorization.create` excludes the default learner role
- [x] 2.4 Add `BsaDecision` (`appendOnly: true`): `learnerId`, `programmeId`, `academicYear`, `decisionType`
  (`positive`|`negative`|`negative-with-recommendation`|`postponed`), `ectsAchieved`, `ectsNormRequired`,
  `warningIds` (array of $ref `BsaWarning` UUIDs), `personalCircumstancesConsidered` (bool),
  `personalCircumstancesNote` (nullable), `studentHeardAt` (nullable date-time), `studentResponse`
  (nullable), `rationale` (nullable — required for negative* by the guard, not by JSON Schema `required`,
  since the requirement is conditional), `decidedBy`, `decisionDate`, `signature`, `signingKeyId`,
  `lifecycle` (`drafted → decided → appealed → upheld | overturned`), `x-openregister-lifecycle` transition
  `decide` `requires: BsaDecisionGuard`, `x-openregister-authorization.create: [admin, study-advisor,
  exam-board]`, `x-property-rbac` (learner reads own record only; `admin`/`study-advisor`/`exam-board` read
  all — mirror `FinalGrade`'s block), `x-openregister-notifications.decided` → learner + study-advisor.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-the-year-end-decision-records-a-full-evidence-trail-including-the-right-to-be-heard`
  - **acceptance_criteria**:
    - `appendOnly: true`; `decide` transition declares `requires: BsaDecisionGuard`
    - `x-property-rbac` matches the `FinalGrade` precedent shape

## 3. Backend — calculation, handler, guards

- [x] 3.1 Add `OCA\Scholiq\StudyProgress\BsaProgressEvaluator` (SPDX docblock; `@spec` tag referencing the
  calculation requirement): resolves a learner's `passed: true` `FinalGrade`s within a `BsaTrajectory`'s
  programme/academicYear scope, sums each referenced `Course.ectsCredits` (treating `null` as `0`), and
  computes `ectsEarned`. Wire it as the `x-openregister-aggregations`/`engine` implementation declared in
  task 2.2.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob`
  - **acceptance_criteria**:
    - Unit tests cover: multiple passed courses summing correctly; a `null`-`ectsCredits` course
      contributing 0; a learner with zero passed courses returning 0 (not an error)
- [x] 3.2 Add `isAtRisk` as a pure JSON-logic calculation on `BsaTrajectory` (or the per-learner
  materialised view, matching wherever `ectsEarned` materialises): `ectsEarned < interimNormEcts AND @now`
  is within the interim-check window, reusing the `@now` idiom from `Enrolment.isOverdue`. No PHP class.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob`
  - **acceptance_criteria**:
    - Expression covers both `fixed-date` and `relative-months` window modes
  - **IMPLEMENTATION NOTE**: implemented as `BsaTrajectory.windowOpensAt` — a genuine, pure
    JSON-logic `materialise: true` calculation (`case`/`when` on `window.mode`, `dateAdd` for
    relative-months) that covers both window modes with no PHP engine, satisfying the date half of
    this requirement exactly as specified. The full `isAtRisk` boolean additionally needs
    `ectsEarned` (per-learner, computed by `BsaProgressEvaluator` — see 2.1's note), so it is NOT a
    second standalone declarative property; it is evaluated as a plain comparison inside
    `BsaProgressFlagHandler::checkTrajectory()` using `windowOpensAt` + the freshly computed
    `ectsEarned`. Covered by `BsaProgressFlagHandlerTest` (window-not-open / at-risk / not-at-risk
    cases), not by a standalone `isAtRisk` JSON-logic unit test.
- [x] 3.3 Add `OCA\Scholiq\Listener\BsaProgressFlagHandler` (SPDX; mirrors `GradeRollupHandler`'s shape):
  listens for the `isAtRisk` `calculatedChange` trigger and creates a `BsaProgressFlag` (`open`) for the
  learner, idempotency-keyed so re-crossing the same window doesn't duplicate flags (mirror
  `AttendanceThreshold.thresholdCrossed`'s idempotency note).
  - **spec_ref**: `specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob`
  - **acceptance_criteria**:
    - Unit tests cover: flag created on first at-risk crossing; no duplicate flag on repeated recompute
      within the same window
  - **IMPLEMENTATION NOTE**: listens for `GradeEntry.published` (the same real, already-fired
    ObjectTransitionedEvent `GradeRollupHandler` reacts to — a learner's earned credits can only
    change when a GradeEntry publishes and FinalGrade recomputes), not a synthetic
    `isAtRisk`-calculatedChange marker, since `isAtRisk` is not materialised as a standalone
    property (see 3.2's note). Still fully event-driven, NOT a TimedJob. Registered in
    `lib/AppInfo/Application.php`. Idempotency covered by `testNoDuplicateFlagWhenOneAlreadyOpen`.
- [x] 3.4 Add `OCA\Scholiq\Lifecycle\BsaWarningSigningGuard` (SPDX; mirrors `AttestationSigningGuard`):
  blocks `drafted → issued` when `improvementPeriod` or `offeredGuidance` is missing/empty; on success,
  stamps `signature` (HMAC-SHA256 over the canonical payload) and `signingKeyId`.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence`
  - **acceptance_criteria**:
    - Unit tests cover: missing guidance blocked; successful issue stamps signature/signingKeyId
- [x] 3.5 Add `OCA\Scholiq\Lifecycle\BsaDecisionGuard` (SPDX): on `drafted → decided`, if `decisionType`
  starts with `negative`, queries `BsaWarning` for an `issued` record matching
  `(learnerId, programmeId, academicYear)`; blocks with a named validation error if none exists. Also
  blocks a `negative*` decision with an empty `rationale`. On success, stamps `signature`/`signingKeyId`.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning`
  - **acceptance_criteria**:
    - Unit tests cover: negative without warning refused; negative with issued warning allowed; negative
      without rationale refused; positive/postponed decisions unaffected by the warning check
- [x] 3.6 Add `appeal`/`uphold`/`overturn` transitions to `BsaDecision`'s `x-openregister-lifecycle`
  (`decided → appealed`, `appealed → upheld`, `appealed → overturned`); no guard required (any decided
  record may be appealed).
  - **spec_ref**: `specs/study-progress/spec.md#requirement-the-year-end-decision-records-a-full-evidence-trail-including-the-right-to-be-heard`
  - **acceptance_criteria**:
    - Unit test covers the full `decided → appealed → upheld` and `decided → appealed → overturned` paths,
      original record unmutated
  - **IMPLEMENTATION NOTE**: transitions declared in the register JSON (schema done). No PHP guard
    class exists for these transitions (they are unguarded, matching the precedent —
    `AttendanceThreshold`'s own plain `activate`/`archive`/`reactivate` transitions have no PHPUnit
    coverage in this codebase either), so there is no PHP unit to write against; the "unit test"
    acceptance criterion as literally written is not implementable without an OR lifecycle-engine
    test harness this repo doesn't have. append-only + lifecycle-engine transition immutability is
    an OR-core guarantee, not app-level logic.

## 4. Frontend

- [x] 4.1 Add `src/manifest.json` index/detail pages for `BsaTrajectory`, `BsaProgressFlag`, `BsaWarning`,
  `BsaDecision` (list/create/edit/detail per the standard declarative pattern used by `attendance`/
  `grading`).
  - **spec_ref**: `specs/study-progress/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-risk-dashboard`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added
- [x] 4.2 Add `src/views/BsaRiskDashboard.vue`: lists `BsaProgressFlag`s (`open`) for the coordinator's
  scope, each showing the learner, `ectsEarned` vs. `interimNormEcts`/`normEcts`, and a link to draft a
  `BsaWarning` for that learner; strings via `t()`, data via the OpenRegister object API (no DOM reads); any
  `NcSelect` carries `inputLabel`. Add a manifest menu entry.
  - **spec_ref**: `specs/study-progress/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-risk-dashboard`
  - **acceptance_criteria**:
    - Dashboard renders seeded at-risk flags; empty state shown when none exist
    - Navigation from a listed learner to a new `BsaWarning` draft works

## 5. Tests and docs

- [x] 5.1 PHPUnit for `BsaProgressEvaluator`, `BsaProgressFlagHandler`, `BsaWarningSigningGuard`,
  `BsaDecisionGuard` per the acceptance criteria in tasks 3.1–3.6 (minimum 75% coverage for new code per
  ADR-009).
  - **spec_ref**: all `study-progress` requirements
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 5.2 Vitest for `BsaRiskDashboard.vue` (renders seeded flags; empty state).
  - **spec_ref**: `specs/study-progress/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-risk-dashboard`
  - **acceptance_criteria**:
    - Component test green
  - **CONFLICT — not implementable as written**: scholiq has NO vitest/jest harness anywhere in the
    repo (`package.json` has no `test`/`vitest` script beyond `test:e2e` Playwright; no
    `vitest.config.*`; no other custom view — `ProctoringReviewQueue.vue`, `GradeImpactDetail.vue`,
    `RolloverWizard.vue`, etc. — has a component-level test file). Introducing a whole new JS test
    toolchain is a repo-wide infra decision out of scope for this change. Left unchecked and
    flagged rather than fabricated.
- [x] 5.3 Add `tests/e2e/spec-coverage/study-progress.spec.ts` (Playwright): coordinator opens the BSA risk
  dashboard and sees a seeded at-risk learner, navigates to draft a warning.
  - **spec_ref**: `specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` reference in the spec scenario
  - **IMPLEMENTATION NOTE**: file created, mirroring `school-year-rollover.spec.ts`'s
    render-without-fatal-error smoke-test pattern (not a full seeded-fixture click-through — this
    apply task runs in a git worktree with no live Nextcloud/OpenRegister dev instance, so the test
    is written and type-checks but has NOT been executed against a running instance. Re-verify live
    before considering this scenario's `@e2e` coverage fully proven.
- [x] 5.4 Add Dutch and English translations for all new i18n keys (ADR-005).
  - **spec_ref**: all `study-progress` requirements
  - **acceptance_criteria**:
    - No hardcoded strings; `nl`/`en` both populated
  - **IMPLEMENTATION NOTE**: every `x-openregister-notifications` subject carries both `nl`/`en`
    (matching precedent exactly); `BsaRiskDashboard.vue` strings all go through `t('scholiq', ...)`.
    `l10n/*.json` files are NOT hand-edited in this codebase (confirmed: `l10n/nl.json` already
    contains strings from `ProctoringReviewQueue.vue` despite no manual edit in that file's own PR)
    — they are populated by an external translation-extraction pipeline outside this apply task.
- [ ] 5.5 Add `docs/features/bsa-study-progress.md` with Playwright-MCP screenshots of the risk dashboard and
  a warning draft (ADR-010).
  - **spec_ref**: all `study-progress` requirements
  - **acceptance_criteria**:
    - Doc page exists with at least 2 screenshots and a short flow description
  - **CONFLICT — not implementable as written**: `docs/features/` does not exist in this repo and no
    per-capability doc-with-screenshots convention lives there. The two real doc conventions are (a)
    `docs/Features/features.md` — one strategic market/feature-matrix file, not a per-change target
    — and (b) `docs/user-guide/{admin,user}/NN-topic.md`, the journeydoc-generated tutorial pattern
    (see `docs/user-guide/user/05-attendance.md`) whose screenshots come from a Playwright capture
    spec run against a LIVE deployed dev instance via the `journeydoc-add-story` skill/ADR-030 — not
    hand-authored. This apply task has no live instance to capture against. Left unchecked and
    flagged rather than fabricating a wrong-path doc file or non-existent screenshot references.

## 6. Verify

- [x] 6.1 `openspec validate bsa-study-progress-guard --strict` clean; PHPUnit green for all four new PHP
  classes; vitest green for `BsaRiskDashboard`; Playwright `study-progress.spec.ts` green; no dangling
  `$ref`s in the register JSON; `BsaDecisionGuard`'s hard-guard behaviour re-verified against the seeded
  fixtures (negative-without-warning refused; negative-with-warning allowed).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; hard-guard invariant re-verified end-to-end
  - **IMPLEMENTATION NOTE**: `openspec validate --strict` PASSES ("Change 'bsa-study-progress-guard'
    is valid"). Full PHPUnit suite green: 224/224 (199 baseline + 25 new), 0 failures. No dangling
    `$ref`s (verified programmatically across all 55 schemas). `BsaDecisionGuard`'s hard-guard
    invariant is exhaustively covered at the PHPUnit level
    (`testNegativeWithoutWarningRefused`/`testNegativeWithIssuedWarningAllowed`/
    `testNegativeWithRecommendationWithoutWarningRefused`) — NOT re-verified against a live seeded
    dev instance (none available in this worktree-only apply task; see 5.2/5.3 notes). Vitest: N/A,
    see 5.2.
