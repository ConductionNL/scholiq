---
kind: code
depends_on: []
---

## Why

The exam board (examencommissie) is a legally-required governance body for MBO/HE, and today Scholiq has
**zero** support for its two core casework flows — verified end-to-end at HEAD:

- **No exemption (vrijstelling) concept exists anywhere.** `grep -rniE "vrijstell|exemption|examencommissie|exam.?board"
  openspec/specs/ lib/Settings/scholiq_register.json` returns zero hits. `GradeEntry.sourceKind`
  (`lib/Settings/scholiq_register.json:5410-5419`) enumerates exactly `assignment-submission | assessment-result |
  participation | manual` — there is no way for a component to be marked "satisfied by prior diploma/certificate/work
  experience" without a numeric mark, so an exemption today can only be faked as a `manual` GradeEntry with an
  invented passing value, which is neither auditable nor honest evidence.
- **No fraud/plagiarism case-file concept exists anywhere.** Same grep, zero hits for `fraud|plagiari|academic
  misconduct`. The only adjacent hook is `assignments/spec.md:68-74`'s pluggable `x-plagiarism.provider` — a
  detection-signal hook only ("no built-in provider... the actual plagiarism-detection algorithm" is explicitly out
  of scope, `assignments/spec.md:96`). Nothing consumes a plagiarism hit into a case file, and nothing blocks
  publication of the graded work while a case is open.
- **`GradeEntry`'s publish path has no fraud guard.** `lib/Settings/scholiq_register.json:5502-5525` declares
  `x-openregister-lifecycle` with exactly three transitions — `publish` (concept→published), `revise`
  (published→revised), `republish` (revised→published) — and none of the three carries a `requires` guard. The
  `AssessmentGradeGuard`/`SubmissionWindowGuard`/`AttendanceFlagReportGuard` precedent
  (`lib/Settings/scholiq_register.json:4879-4881,4085-4086,7139-7140`) shows the fleet's established mechanism for
  blocking a transition (`x-openregister-lifecycle.transitions.<action>.requires: [FQCN]`, ADR-031 §"PHP guards
  remain a legitimate seam"); `GradeEntry.publish` simply has none today, so a contested grade publishes exactly
  like any other.
- **The roll-up engine has no concept of "exempt".** `FinalGrade` aggregates `publishedEntries` — a cross-schema
  aggregation filtered to `lifecycle: published` (`lib/Settings/scholiq_register.json:5661-5670`) — and feeds them
  through `CurriculumPlan.formula` via the already-documented ADR-031 PHP-exception class
  `OCA\Scholiq\Grading\GradeFormulaEvaluator` (`lib/Settings/scholiq_register.json:5672-5684`, and named as the
  ADR-031 exception in `grading/spec.md`'s "Roll-up is a declared calculation, not a TimedJob" requirement and
  Data-Model section). There is no `exempt` signal anywhere in `GradeEntry` or the evaluator's contract for it to
  branch on — an exemption cannot "count toward completion" today because there is no way to feed one into the
  roll-up without a fabricated numeric mark corrupting the weighted average.
- **Legal + market evidence.** Insight 1148 (WHW art. 7.13: every HO programme MUST have an OER and an independent
  examencommissie; the Inspectorate's Sept-2025 review found boards "function sufficiently but there are concerns")
  and Kennispunt MBO's exemption guidance (external source 6592, `onderwijsenexaminering.nl/examinering/vrijstellingen`
  — "exemptions are individual decisions of the exam board... requests preferably at the start of the school year...
  a *handreiking* supports reasoned, consistent decisions") establish this as MBO+HE legal governance, not a nice-to-have.
  Universiteit Leiden's fraud-reporting flow (source 6597) is the canonical process this spec encodes: "lecturer
  informs student and reports to exam board... grading suspended until the board decides... only the board imposes
  sanctions." Journeys 1744 ("Requests arrive by mail with loose evidence; decisions not propagated to progress
  systems") and 1745 ("Case dossiers assembled ad hoc from mails; the grade must stay blocked during the case;
  decisions must survive CBE appeal with full hearing records") name exactly the two gaps this change closes.
  Stories 10068 (`vrijstelling-aanvraag-indienen`, high), 10069 (`vrijstelling-besluit-doorwerking`, high), and
  10070 (`fraudezaak-dossier-blokkade`, high) give the acceptance criteria this spec's scenarios are built from.

## What Changes

- **New capability `exam-board`** with two new OpenRegister schemas:
  - **`ExemptionCase`** — a learner's exemption request against one `CurriculumPlan.components[]` entry
    (`curriculumPlanId` + `componentId`, the same pair `GradeEntry` already scores against), evidence file
    attachments (OR attachments, the `Material`/`ExternalTrainingRecord` pattern), `groundsKind`
    (`prior-diploma | certificate | work-experience | other`) + free-text grounds, and a lifecycle `submitted →
    in-assessment → granted | rejected | withdrawn`. `grant`/`reject` require a `decisionRationale` +
    `policyReference` via a new `ExemptionDecisionGuard` (mirrors `ExternalTrainingVerificationGuard`'s
    precondition-guard shape). On `grant`, a new `ExemptionGrantHandler` — a PHP event listener on the transition,
    the same cross-schema-side-effect pattern already used by `ExcuseApprovalHandler` for `ExcuseRequest→approve`
    flipping `AttendanceRecord`s — creates a `GradeEntry` with the new `sourceKind: exemption`, `value: null`
    (schema conditionally relaxes `value`'s `required`-ness for this one `sourceKind`), links `exemptionCaseId`,
    and drives it through the *existing* `publish` transition (not a raw field write) so the existing audit trail
    and `gradePublished` notification fire unchanged.
  - **`FraudCase`** — report → hearing record(s) → reasoned decision → sanction, linked to the contested work via
    the same `sourceKind`/`submissionId`/`assessmentResultId`/`sessionId` shape `GradeEntry` already uses (so a
    case can be filed before a `GradeEntry` exists), plus an optional `contestedGradeEntryId` once one does.
    Lifecycle `reported → hearing-scheduled → heard → decided` (+ `dismissed` from either of the first two states),
    with `decide` requiring a new `FraudCaseDecisionGuard` (verdict + rationale set; if `fraud-proven`, a sanction
    is set — `sanctionType`, capped `sanctionDurationMonths` (max 12, "up to one-year exclusion" per source 6597 /
    story 10070), `sanctionScope`). A calculated `appealDeadline` (`decidedAt` + 42 days — the CBE 6-week window
    named in journey 1745) plus `appealLodged`/`appealOutcome` fields record the appeal.
- **MODIFIED `grading` capability** — the publication guard:
  - `GradeEntry.sourceKind` gains `exemption`; new nullable `exemptionCaseId`/`fraudCaseId` refs.
  - `GradeEntry.x-openregister-lifecycle.transitions.publish` and `.republish` both gain
    `requires: [FraudCaseBlockGuard]` — blocks while a linked `FraudCase` is `reported|hearing-scheduled|heard`,
    or permanently once `decided` with `verdict: fraud-proven` (that path goes through `invalidate`, not
    `publish`).
  - `GradeEntry` gains a new terminal lifecycle state `invalidated` and transition `invalidate` (from `concept`,
    `requires: [FraudCaseInvalidationGuard]` — only reachable from a `decided`/`fraud-proven` `FraudCase`), fired
    by a new `FraudCaseDecisionHandler` event listener on `FraudCase→decide` when `verdict: fraud-proven` and a
    `concept` `GradeEntry` is linked.
  - `GradeFormulaEvaluator` (the existing ADR-031 PHP-exception class) is extended, not replaced: `sourceKind:
    exemption` entries are excluded from the `weighted-average` numeric sum/denominator but satisfy their
    component's `all-must-pass` requirement without a numeric check; `FinalGrade.breakdown.components[componentId]`
    gains an `exempt: true` marker so the roll-up UI can show *why* a component counts.
  - `GradeEntry`/`FinalGrade` `x-property-rbac.read` gains an `exam-board` role alongside the existing
    `admin`/self-match `anyOf`, so a board member can read the specific grade their case concerns.
- **Declarative UI**: `src/manifest.json` index/detail pages for both new schemas; one custom
  `ExamCaseDossierView` component (shared by both, tab-switched) because "who may see what" — hiding
  `hearingRecords`/decision internals from anyone but the accused, the reporter, and exam-board members — is
  genuine conditional-rendering logic a manifest detail page cannot express.
- **Notifications** (verified dialect only, ADR-031): `ExemptionCase` created → `groups: [examboard]`;
  `grant`/`reject` → `field: learnerId`. `FraudCase` created → `groups: [examboard]`; `decide` → `field: learnerId`
  + `field: reporterId`.

## Impact

- `lib/Settings/scholiq_register.json` — two new schemas (`ExemptionCase`, `FraudCase`); `GradeEntry` schema
  modified (new `sourceKind` value, two new nullable ref fields, conditional `value` requiredness, two new
  lifecycle transitions + guards on the existing three, new RBAC role); `FinalGrade.breakdown` shape note.
- `lib/Lifecycle/` — new `ExemptionDecisionGuard`, `FraudCaseHearingGuard`, `FraudCaseDecisionGuard`,
  `FraudCaseBlockGuard`, `FraudCaseInvalidationGuard`.
- `lib/Listener/` — new `ExemptionGrantHandler`, `FraudCaseDecisionHandler` (event listeners, same shape as the
  existing `ExcuseApprovalHandler`/`GradeRollupHandler`).
- `lib/Grading/GradeFormulaEvaluator.php` (existing ADR-031 exception class) — extended for the `exemption`
  sourceKind, not newly introduced.
- `src/manifest.json` — new index/detail pages + one custom `ExamCaseDossierView`.
- Reuses unchanged: OR file attachments (evidence), the verified notification dialect, OR audit trail, existing
  `GradeEntry.publish`/`republish` transitions and `gradePublished` notification rule.
