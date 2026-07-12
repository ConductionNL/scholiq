# Design: bsa-study-progress-guard

## Context

Binding study advice (`bindend studieadvies`, BSA) is the year-one progress-and-decision gate for Dutch HE
(HBO/WO) and, on a different statutory clock, MBO (WEB art. 8.1.7a). Two things must both be true for a
negative BSA decision to survive an appeal (`bezwaar`): the institution warned the student in time with
guidance offered, and it considered personal circumstances and heard the student before deciding
(rijksoverheid.nl, see `proposal.md` "Why"). Scholiq already has the two building blocks this needs —
`AttendanceThreshold`/`AttendanceFlag`'s declarative threshold-and-flag pattern, and `Attestation`'s
append-only signed-evidence pattern — but nothing wires them together over credits instead of attendance,
and the credit total itself (`Course.ectsCredits`) doesn't exist yet. This document works out the data
model, the calculation path, and the guard that makes the hard invariant ("no negative BSA without a logged
warning") structural rather than conventional.

## Goals / Non-Goals

**Goals**
- Monitor first-year cumulative EC against an institution-set norm, ahead of the interim warning guideline.
- Generate and immutably log a formal warning with improvement period, offered guidance, and an optional
  personal-circumstances note.
- Record the year-end decision with a full evidence trail (credits, norm, referenced warnings, personal
  circumstances, right-to-be-heard) that survives an appeal.
- Structurally block a negative decision that has no logged, issued warning.
- Reuse existing declarative machinery (calculation engine, notification dialect, append-only audit) — no
  new parallel mechanism.

**Non-Goals**
- Reporting BSA outcomes to DUO/Studielink or any external system (a `data-exchange` follow-up).
- Computing the exact statutory appeal deadline per institution (`studentenstatuut`-specific; modeled as a
  lifecycle state, not a computed date, in this change).
- Exam-board exemption/fraud dossiers (shortlist item #5 — a separate capability with its own publication
  guard on `GradeEntry`, unrelated data shape).
- A generic "any threshold over any metric" engine refactor of `AttendanceThreshold` — mirroring the pattern
  is cheaper and lower-risk than generalising it in this change; a future refactor could unify them, but is
  out of scope here.

## Data Model

```
Programme ──< BsaTrajectory (norm config; kind: he-eerstejaar-bsa | mbo-studieadvies | generic)
                  │  x-openregister-calculations: ectsEarned (engine), isAtRisk (JSON-logic @now idiom)
                  │  x-openregister-triggers.calculatedChange → BsaProgressFlagHandler
                  ▼
            BsaProgressFlag (appendOnly; open → in-handling → warned → resolved)
                  │  x-openregister-notifications: flagRaised → study-advisor/exam-board
                  ▼
            BsaWarning (appendOnly, signed; drafted → issued → acknowledged)
                  │  requires BsaWarningSigningGuard at drafted → issued
                  │  x-openregister-notifications: issued → learner
                  ▼  (warningIds evidence trail)
            BsaDecision (appendOnly, signed; drafted → decided → appealed → upheld | overturned)
                  │  requires BsaDecisionGuard at drafted → decided
                  │    (BLOCKS negative* without ≥1 issued BsaWarning for learner+programme+academicYear)
                  └  x-openregister-notifications: decided → learner + study-advisor

Course.ectsCredits (new field) ──< FinalGrade (existing; learnerId, courseId, passed)
                  └─ summed by BsaProgressEvaluator into BsaTrajectory.ectsEarned per learner
```

### `Course.ectsCredits` (course-management delta)

`number`, nullable, `minimum: 0`, no unit conversion — plain EC per Bologna convention. Additive: existing
`Course` rows default to `null`, which the evaluator treats as `0` (a course with no declared credit value
contributes nothing to a learner's total rather than blocking the calculation). Only meaningful for
`level: hbo | wo | mbo` courses; `po`/`vo`/`corporate` courses simply never set it.

### `BsaTrajectory`

The rule object, one row per `(programmeId, academicYear)` (or a `cohortId`-scoped override, mirroring
`AttendanceThreshold.cohortId`). `kind` selects the check-window profile:

| `kind` | Interim check | Legal basis |
|---|---|---|
| `he-eerstejaar-bsa` | `fixed-date` — one calendar date per academic year (institution's "1 februari"-adjacent policy date; NOT hardcoded to Feb 1 in the schema — the field is set by the institution because the actual legal driver is protecting `studiefinanciering`, which depends on the institution's own academic calendar) | `studiefinanciering` protection, rijksoverheid.nl |
| `mbo-studieadvies` | `relative-months` — `afterMonths`/`notEarlierThanMonths` relative to each cohort's own start date | WEB art. 8.1.7a |
| `generic` | either mode, institution's choice | fallback for corporate/other profiles that want the same guard machinery |

This mirrors `AttendanceThreshold.window`'s `rolling-weeks`/`fixed-term` duality exactly
(`lib/Settings/scholiq_register.json:6788-6819`) — same shape, new field names.

`normEcts` has **no default value** in the schema. This is a deliberate reading of the legal grounding: the
rijksoverheid.nl BSA page states the norm is institution-set (capped at the first-year credit total); a
statutory ≤30-EC minimum was proposed and paused, never enacted. Shipping a hardcoded `30` would silently
misstate the law for any institution that sets a higher (or lower, where still permitted) norm. The
`interimNormEcts` is a separate, optional pace-adjustment field (typically roughly proportional to how far
through the year the interim check falls) used only to decide "at risk ahead of the guideline," never to
gate the actual year-end decision — that always compares against `normEcts`.

### Calculation: `ectsEarned` (engine) + `isAtRisk` (JSON-logic)

`ectsEarned` cannot be expressed as the same single-schema `sum` operator `AttendanceThreshold` uses
(`unexcusedLesuren` sums `AttendanceRecord.lesuren` directly — one schema, one field). Summing credits
requires resolving each of the learner's passed `FinalGrade`s to its `Course.ectsCredits` — a cross-schema
join. `FinalGrade.value` already solved exactly this shape (formula evaluation needs `CurriculumPlan` +
`GradeEntry` data) via `x-openregister-aggregations` (declares the cross-schema pull) plus an `engine`-keyed
`x-openregister-calculations` entry (`GradeFormulaEvaluator`, `lib/Settings/scholiq_register.json:5661-5691`).
`BsaProgressEvaluator` follows the identical shape: `x-openregister-aggregations.passedFinalGrades` pulls
the learner's `passed: true` `FinalGrade`s scoped to the programme's course list, and
`x-openregister-calculations.ectsEarned.engine` resolves each to `Course.ectsCredits` and sums. This is the
same ADR-031 PHP-exception rationale already accepted for `GradeFormulaEvaluator` — not a new precedent.

`isAtRisk` (boolean) stays pure JSON-logic: `ectsEarned < interimNormEcts AND @now >= (window start)`,
directly reusing the `@now` comparison idiom already exercised by `Enrolment.isOverdue`
(`lib/Settings/scholiq_register.json:1420-1452`) — no engine class needed for the date comparison itself,
and no scholiq `TimedJob` is introduced (ADR-022: reuse the same threshold/`calculatedChange` machinery,
matching the invariant `attendance` and `grading` already hold).

### The hard guard: `BsaDecisionGuard`

Modeled exactly like `Attestation`'s `AttestationSigningGuard` and `CurriculumPlan`'s
`ProgrammePublishGuard` — a `requires` clause on an `x-openregister-lifecycle` transition
(`lib/Settings/scholiq_register.json:2018-2031`, `:2635-2645`), i.e. a PHP class the OR lifecycle engine
invokes before allowing `drafted → decided`. Its rule:

> If `decisionType` is `negative` or `negative-with-recommendation`, at least one `BsaWarning` with
> `lifecycle: issued` MUST exist for the same `(learnerId, programmeId, academicYear)`. Otherwise the
> transition is refused with a validation error naming the missing warning.

This is the one requirement in this change that MUST be enforced in code, not just documented — everything
else in `study-progress` is declarative schema config, but "no negative BSA without a logged warning" is a
cross-object invariant no JSON-logic expression on a single schema can check (it needs to query the
`BsaWarning` collection), so it is the fourth PHP exception in this change, alongside `BsaProgressEvaluator`,
`BsaWarningSigningGuard`, and the flag-creation handler.

## Rejected Alternatives

- **Hardcode `normEcts: 30` as the schema default.** Rejected — the fetched rijksoverheid.nl page is explicit
  that the norm is institution-set and that a national 30-EC floor was a paused, unenacted proposal.
  Shipping it as a default would present unenacted policy as fact and silently apply the wrong norm to any
  institution running a higher figure.
  - **Reconsider if:** the paused legislation is enacted and rijksoverheid.nl documents a hard national
    floor — at that point a schema-level default (with per-institution override still allowed) becomes
    defensible.
- **Fold BSA warning/decision into `Attestation`.** Rejected — `Attestation` is "training completed and
  understood," a positive-evidence record; BSA warnings/decisions are progress-monitoring outcomes that can
  be negative and are appealable. Reusing the schema would force an awkward `regulationSlug`/`lessonId`
  shape onto a domain that has neither, and would make the appeal sub-lifecycle (`appealed → upheld |
  overturned`) a strange fit on a schema whose only terminal states are `signed`/`revoked`.
- **A scholiq `TimedJob` that nightly recomputes every first-year's progress.** Rejected — violates the
  invariant `attendance` and `grading` both hold (ADR-022: threshold/roll-up detection is a declared
  calculation + `calculatedChange` trigger, never a PHP `TimedJob`) and would be a parallel mechanism next to
  the one OR's calculation engine already provides (the same engine `Enrolment.isOverdue` already relies on
  for date-driven recompute).
- **Store `ectsCredits` on `CurriculumPlan.components` instead of `Course`.** Rejected — `CurriculumPlan`
  governs grade-component weighting (kolommen) within one course/programme's assessment plan; ECTS is a
  per-course/module credit *value* that exists independent of any curriculum plan's component structure
  (and `course-management`'s own "What" section already frames it as a course-level declaration). Placing it
  on `Course` also matches where `parentCourseId` already models the module-as-a-course recursion
  (`lib/Settings/scholiq_register.json:876-883`).
- **New `study-progress` capability vs. a `grading`/`enrolment` delta.** `grading` computes component
  roll-ups within one course/curriculum-plan; `enrolment` is intake. Neither owns "sum credits across a
  programme's courses, compare to a year-end norm, warn, decide, allow appeal" — that is exactly the shape
  `attendance` chose to be its own capability for (rather than folding into `school-structure`), and BSA's
  decision/appeal lifecycle has no analogue in either existing spec. A new capability, consuming both via
  read-only cross-schema aggregation, keeps each spec's ownership boundary clean (ADR-022: apps/capabilities
  consume, they don't duplicate).

## Security / Privacy Posture

- `BsaWarning` and `BsaDecision` are `appendOnly: true` (ADR-008) — no mutation path, only new lifecycle
  transitions, matching `Attestation`/`AttendanceFlag`.
- Both carry an HMAC `signature`/`signingKeyId` pair stamped by their respective guards, verifiable offline
  exactly like `Attestation.signature` — the audit-pack export precedent (`compliance-audit`) could extend
  to BSA evidence later without a schema change.
- `x-property-rbac` on `BsaProgressFlag`/`BsaWarning`/`BsaDecision` mirrors `FinalGrade`'s existing block
  (`lib/Settings/scholiq_register.json:5692-5708`, "learner sees own final grade; admins see all"): a
  learner reads only their own records; `admin`/`study-advisor`/`exam-board` roles read all. This matters
  because a BSA decision is sensitive academic-progress PII and the subject of a possible appeal — the
  learner must be able to see their own record to appeal it, and must not see anyone else's.
- `x-openregister-authorization.create` on `BsaWarning`/`BsaDecision` restricts creation to
  `["admin", "study-advisor", "exam-board"]` — a learner can never author their own warning or decision
  (mirrors the pattern already used for `xapi-statement`'s stopgap admin-only create,
  `lib/Settings/scholiq_register.json:1281-1286`, minus the "stopgap" framing — this restriction is
  permanent by design, not a placeholder).

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects (`lib/Settings/scholiq_register.json`); no new database tables (ADR-001).
- No pass-through CRUD controller — every new object is declarative; the only PHP is the calculation engine,
  two lifecycle guards, and one event-listener handler, all narrowly scoped exceptions the same way
  `GradeFormulaEvaluator`/`AttestationSigningGuard`/`GradeRollupHandler` already are (ADR-022, ADR-031).
- Notifications via the `x-openregister-notifications` dialect only (ADR-031) — no imperative dispatch code.
- Threshold/calculation detection via declared `calculatedChange` triggers, never a `TimedJob` (ADR-022,
  matching `attendance`'s own invariant).
- UI is manifest-driven; the one custom view (`BsaRiskDashboard`) is a genuine dashboard surface, not a CRUD
  form — same bar `attendance`'s `MarkAttendanceView` and `grading`'s `GradebookView` were held to.
- i18n keys in English; SPDX headers on the four new PHP files.
