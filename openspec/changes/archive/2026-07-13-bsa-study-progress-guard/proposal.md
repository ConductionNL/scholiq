---
kind: code
depends_on: []
---

## Why

Scholiq has no `bindend studieadvies` (BSA) capability at all, and the one piece of infrastructure BSA
needs — a first-year credit total — was never built even though `course-management` claims it:

- **ECTS workload is prose, not schema.** `openspec/specs/course-management/spec.md:18` ("What") says the
  app supports "ECTS workload declaration for HE", but the `course-management` Requirements section
  (`openspec/specs/course-management/spec.md:35-58`) never turns that into a field, and the `Course` object
  in `lib/Settings/scholiq_register.json:764-903` has no `ectsCredits`/`workload`/`credit` property at all —
  confirmed by a full-file case-insensitive grep for `ects|ECTS|credit|workload`: the only hit is the
  `ects` band-*kind* enum value on `GradeScale` (`lib/Settings/scholiq_register.json:5226`), which labels a
  grading scale, not a credit weight. Nothing in the register lets a course declare how many EC it is worth,
  so no downstream feature — BSA included — can sum a learner's earned credits.
- **`FinalGrade` computes pass/fail per Course, nothing about a study-progress decision.** `FinalGrade`
  (`lib/Settings/scholiq_register.json:5554-5709`) is a calculated roll-up per `(learnerId, curriculumPlanId)`
  via `x-openregister-aggregations` over published `GradeEntry`s and an `engine`-based calculation
  (`GradeFormulaEvaluator`, `openspec/specs/grading/spec.md:63-64`) — the exact precedent this change reuses
  for summing credits — but there is no concept anywhere of a *programme-level, year-scoped* progress check,
  a warning, or a decision.
- **`enrolment` stops at intake.** `openspec/specs/enrolment/spec.md` (read in full) covers Studielink
  intake, bulk enrolment, prerequisite checks, and account provisioning; it has no requirement touching
  study-progress monitoring or a BSA-style decision.
- **Zero hits repo-wide.** The gap report's own scope-check confirms it: "grep `BSA` in specs and register
  JSON: zero hits; enrolment spec covers Studielink intake but nothing on study-progress decisions"
  (`scholiq-gap-report.md:99`).
- **The pattern to mirror already exists and works.** `AttendanceThreshold` + `AttendanceFlag`
  (`lib/Settings/scholiq_register.json:6732-7075`, `openspec/specs/attendance/spec.md:25,45-61`) is a
  declared-calculation threshold rule (`x-openregister-calculations`, `calculatedChange` trigger — **not** a
  PHP `TimedJob`, per ADR-022) that materialises a per-learner metric and fires an append-only `Flag` object
  with a workflow lifecycle when a limit is crossed. `Attestation`
  (`lib/Settings/scholiq_register.json:1893-2035`, `openspec/specs/compliance-audit/spec.md:39-57`) is the
  signed-evidence muscle: `appendOnly: true`, an HMAC `signature`/`signingKeyId` pair stamped by a
  lifecycle-transition guard (`AttestationSigningGuard`) at the `drafted → signed` transition. Both patterns
  generalise cleanly to BSA: the threshold pattern over `FinalGrade`/credits instead of attendance records,
  and the signed-evidence pattern for the warning and the decision.

**Demand:** stories `bsa-risico-dashboard` (10071, critical) and `bsa-waarschuwing-vastleggen` (10072,
critical), journey 1746 (critical) — `scholiq-gap-report.md:92-99`. Two critical-priority stories plus a
critical journey, in a gap the existing compliance-wedge muscle (append-only signed evidence, "no negative
outcome without documented process") was purpose-built to solve for a new domain.

**Legal grounding** (fetched 2026-07-12):
- [rijksoverheid.nl — Wat is het bindend studieadvies (BSA) in het hoger onderwijs?](https://www.rijksoverheid.nl/onderwerpen/hoger-onderwijs/vraag-en-antwoord/wat-is-het-bindend-studieadvies-bsa-in-het-hoger-onderwijs-ho):
  the credit **norm is institution-set**, not a fixed national number (a proposed statutory 30-EC national
  cap was paused by the incoming cabinet and never took effect — institutions retain freedom to set their
  own norm, capped at the first-year total). The **"1 februari-regel"** requires a *timely warning* so that,
  if the advice ends up negative, discontinuation does not cost the student their `studiefinanciering` for
  that period — the trigger for warning ahead of ~1 February is protecting the student's funding, not an
  arbitrary date. Before a valid negative advice: "sufficient study guidance" from the student dean,
  recognition of personal circumstances (disability, family situation, student-council membership), and the
  student's **right to be heard** (`hoorplicht`).
- [rijksoverheid.nl — In beroep gaan tegen een negatief BSA](https://www.rijksoverheid.nl/wetten-en-regelingen/productbeschrijvingen/in-beroep-gaan-tegen-negatief-bindend-studieadvies-bsa-hbo-of-wo):
  a student can lodge `bezwaar`; the procedural safeguards above (timely warning, guidance offered,
  personal circumstances considered, right to be heard) are exactly what the institution's evidence trail
  is judged against on appeal — the "no negative BSA without a logged warning" guard in this change encodes
  the first of those safeguards structurally.
- MBO's equivalent (WEB art. 8.1.7a, per search of `honoreadvocaten.nl`/`lexscholaris.nl`/`rijksoverheid.nl`)
  runs on a **different clock**: advice within 4 months (not earlier than 3) of the start of a one-year
  programme, or after ≥9 months up to the end of the first academic year for longer programmes — not the HE
  1-February/`studiefinanciering` rule. The design MUST NOT hardcode a single "1 February" constant; the
  check window is configurable per trajectory profile (mirrors `AttendanceThreshold.kind` /
  `CurriculumPlan.kind` profile pattern, `lib/Settings/scholiq_register.json:6758-6769`,
  `openspec/specs/course-management/spec.md` `CurriculumPlan.kind` at `lib/Settings/scholiq_register.json:2694-2706`).

## What Changes

- **Close the ECTS-schema gap** (`course-management` delta): add an `ectsCredits` (nullable number) field to
  `Course` — the value each course/module contributes toward the Bologna-style first-year credit total.
  Purely additive; existing rows leave it `null`.
- **New `study-progress` capability** with four new OpenRegister objects, mirroring the
  `AttendanceThreshold`/`AttendanceFlag` and `Attestation` patterns:
  - **`BsaTrajectory`** — the norm config per Programme/academicYear: `normEcts` (institution-set, no
    hardcoded default — see legal grounding), a dual-mode interim-check window (`fixed-date` for the HE
    1-February/`studiefinanciering` profile, `relative-months` for the MBO art. 8.1.7a profile, mirroring
    `AttendanceThreshold.window`'s `rolling-weeks`/`fixed-term` duality), `interimNormEcts` (pace-adjusted
    threshold for the "ahead of the guideline" flag), and an `onAtRisk` action block (mirrors
    `AttendanceThreshold.onCross`).
  - **`BsaProgressFlag`** — append-only, created when a learner's calculated `ectsEarned` falls below
    `interimNormEcts` ahead of the interim-check window (mirrors `AttendanceFlag`'s workflow:
    `open → in-handling → warned → resolved`).
  - **`BsaWarning`** — the formal warning: append-only, HMAC-signed at `drafted → issued` by a new
    `BsaWarningSigningGuard` (mirrors `AttestationSigningGuard`), carrying `improvementPeriod`,
    `offeredGuidance` (required — the "sufficient study guidance" safeguard), and an optional
    `personalCircumstancesNote`.
  - **`BsaDecision`** — the year-end decision: append-only, signed, `decisionType` (`positive` |
    `negative` | `negative-with-recommendation` | `postponed`), `warningIds` evidence trail,
    `personalCircumstancesConsidered`/Note, `studentHeardAt`/`studentResponse` (the `hoorplicht`
    safeguard), and an appeal sub-lifecycle (`decided → appealed → upheld | overturned`).
  - **Hard guard**: a new `BsaDecisionGuard` lifecycle-transition guard on `BsaDecision`'s
    `drafted → decided` transition BLOCKS any `negative*` decision unless at least one `issued`
    `BsaWarning` exists for the same `(learnerId, programmeId, academicYear)` — "no negative BSA without a
    logged warning," enforced structurally, not by convention.
- **Credit-earned calculation reuses the `FinalGrade`/`GradeFormulaEvaluator` precedent**: a new
  `BsaProgressEvaluator` engine class (declared via `x-openregister-aggregations` + an `engine`-based
  `x-openregister-calculations` entry, exactly like `FinalGrade.value`) sums `Course.ectsCredits` for the
  learner's `passed: true` `FinalGrade`s in the trajectory's scope — a cross-schema join the pure JSON-logic
  `sum` operator (as used by `AttendanceThreshold.unexcusedLesuren`) cannot express, which is the same
  reason `GradeFormulaEvaluator` exists as an ADR-031 PHP exception. `isAtRisk` reuses the plain `@now`-vs-
  field JSON-logic idiom already used by `Enrolment.isOverdue`
  (`lib/Settings/scholiq_register.json:1420-1452`) — no new engine needed for that half.
- **Frontend**: declarative `src/manifest.json` index/detail pages for all four new objects, plus one named
  custom view — `BsaRiskDashboard` (story 10071, "bsa-risico-dashboard") — the only genuine custom UI
  (a coordinator/study-advisor view of at-risk learners against their trajectory).
- **No wire protocol, no PHP CRUD controller** — everything is declarative OpenRegister config plus the
  three narrowly-scoped PHP exceptions (`BsaProgressEvaluator`, `BsaWarningSigningGuard`,
  `BsaDecisionGuard`) that ADR-031 already permits when JSON-logic can't express the rule.

## Impact

- **`lib/Settings/scholiq_register.json`** — `Course.ectsCredits` added (additive); four new schemas
  (`BsaTrajectory`, `BsaProgressFlag`, `BsaWarning`, `BsaDecision`).
- **New PHP** — `OCA\Scholiq\StudyProgress\BsaProgressEvaluator` (calculation engine),
  `OCA\Scholiq\Listener\BsaProgressFlagHandler` (calculatedChange → flag creation, mirrors
  `GradeRollupHandler`), `OCA\Scholiq\Lifecycle\BsaWarningSigningGuard`,
  `OCA\Scholiq\Lifecycle\BsaDecisionGuard`. No new controller, no new route.
- **`src/manifest.json`** — index/detail pages for the four new objects; one new custom view
  `BsaRiskDashboard.vue`.
- **Affected specs**: `course-management` (MODIFIED-by-addition: `ectsCredits`), new `study-progress`
  capability spec. `grading` and `attendance` are read-only precedents, not modified.
- **Out of scope**: exam-board exemption/fraud case handling (shortlist item #5, separate change), any
  DUO/`studielink`/OOAPI reporting of BSA outcomes (a `data-exchange` follow-up if a buyer needs it), and a
  configurable statutory appeal-deadline calculator (the `appealed` transition is modeled; computing the
  exact `bezwaartermijn` per institution's `studentenstatuut` is a follow-up).
