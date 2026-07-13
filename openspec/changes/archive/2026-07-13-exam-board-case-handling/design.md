# Design — Exam Board Case Handling (Vrijstelling + Fraud/Plagiarism Dossiers)

## 1. Architecture Overview

Two new OpenRegister schemas (`ExemptionCase`, `FraudCase`), five new lifecycle guards, two new event
listeners, one existing-schema modification (`GradeEntry` gains a fraud-guarded publish path + a new
terminal `invalidated` state), one existing-engine extension (`GradeFormulaEvaluator` learns to treat
`sourceKind: exemption` as non-numeric-but-satisfying), and one shared custom detail view
(`ExamCaseDossierView`). No new PHP interfaces, no new external providers, no portal audience — this is an
entirely internal (NC-user-facing) governance workflow, unlike `bpv`'s praktijkopleider portal reach.

```
ExemptionCase ──(grant)──> ExemptionGrantHandler ──creates+publishes──> GradeEntry (sourceKind: exemption)
                                                                              │
FraudCase ──(decide, fraud-proven)──> FraudCaseDecisionHandler ──invalidates─┘
                                                                              │
GradeEntry.publish/republish ──guarded by──> FraudCaseBlockGuard ────reads───┘ (fraudCaseId link)
```

## 2. Case state machines — verified against the register's existing multi-`from` transition precedent

Both are OR `x-openregister-lifecycle` declarations. The array-`from` shape (`"from": ["pending",
"active"]`) is already used for `withdraw`-style transitions elsewhere in this register
(`lib/Settings/scholiq_register.json:1407-1413`), so `dismiss` reuses that precedent rather than inventing a
new pattern.

### 2.1 ExemptionCase

```
submitted ──(startAssessment)──> in-assessment ──(grant)──> granted   [terminal]
                                        │
                                        └────────(reject)──> rejected  [terminal]

submitted, in-assessment ──(withdraw)──> withdrawn  [terminal, learner-initiated]
```

- `startAssessment` (`submitted → in-assessment`): no guard — any exam-board member may pick up a submitted
  case. Unguarded because "who may triage" is a role check better expressed at the UI/RBAC layer (read
  access already gates who can even see the case — see §5) than as a lifecycle precondition; there is no
  fleet precedent for a guard whose sole job is a role check with no additional business rule (contrast
  `ExemptionDecisionGuard` below, which combines the role concern with a data-completeness precondition).
- `grant` / `reject` (`in-assessment → granted | rejected`): guarded by `ExemptionDecisionGuard` —
  `decisionRationale` + `policyReference` must be set on the payload before either terminal transition is
  allowed. Mirrors `ExternalTrainingVerificationGuard`'s precondition-guard shape
  (`lib/Lifecycle/ExternalTrainingVerificationGuard.php`).
- `withdraw` (`[submitted, in-assessment] → withdrawn`): unguarded — a learner may withdraw their own request
  at any point before a decision. No cross-object side effect (unlike `grant`).

### 2.2 FraudCase

```
reported ──(scheduleHearing)──> hearing-scheduled ──(holdHearing)──> heard ──(decide)──> decided [terminal]

reported, hearing-scheduled ──(dismiss)──> dismissed  [terminal]
```

- `scheduleHearing` (`reported → hearing-scheduled`): guarded by `FraudCaseHearingGuard` — a `hearingDate`
  must be set on the payload (a scheduled hearing with no date is not meaningfully "scheduled").
- `holdHearing` (`hearing-scheduled → heard`): unguarded — recording that the hearing took place is a factual
  event, not a decision; at least one `hearingRecords[]` entry SHOULD exist by convention but this is not a
  hard precondition (a hearing can be held with notes added afterward — see design note in §6, "Out of
  scope" analogue for why this isn't over-engineered into a guard).
- `decide` (`heard → decided`): guarded by `FraudCaseDecisionGuard` — `verdict` + `decisionRationale` always
  required; when `verdict: fraud-proven`, `sanctionType` + `sanctionDurationMonths` (≤ 12) + `sanctionScope`
  additionally required. On success, the guard also stamps `appealDeadline = decidedAt + 42 days` onto the
  payload (see §3).
- `dismiss` (`[reported, hearing-scheduled] → dismissed`): unguarded, the array-`from` precedent from §2. A
  case can be dismissed before or during scheduling — e.g. the reporter withdraws the allegation, or a
  triage review finds no basis to proceed to a hearing. Not reachable from `heard` — once a hearing has been
  held, the only outcomes are a full `decide` (which can itself land on `verdict: unfounded` — functionally
  equivalent to dismissal, but with the formality of a recorded hearing and rationale, which matters for the
  student's due-process record per Universiteit Leiden's fraud process, source 6597).

`FraudCaseDecisionHandler` (§4) listens for `decided` transitions where `verdict: fraud-proven` and drives
the linked `GradeEntry` (if still `concept`) through its own new `invalidate` transition — the cross-schema
bridge, same shape as `ExcuseApprovalHandler`.

## 3. The 42-day appeal deadline is a guard-stamped field, not a declarative calculation

Checked at HEAD: this register's `x-openregister-calculations` expression DSL has confirmed precedent for
equality/comparison logic and a `today()` function (`LearningPlanEvaluation.nextReviewDue`:
`"nextReviewAt != null && nextReviewAt <= today()"`, `lib/Settings/scholiq_register.json:6130-6133`) and for
`sum`/`coalesce`/`lookup`/`length` style aggregate expressions (`GradeEntry.effectiveWeight`,
`CurriculumPlan`-related lookups). **No date-arithmetic primitive** (e.g. a `date_add(field, days)` function)
appears anywhere in the register at HEAD — grepping the entire file for `date_add`/`dateAdd`/`addDays`
returns zero hits. Rather than assume an unverified primitive exists, `appealDeadline` is computed and
written into the transition payload by `FraudCaseDecisionGuard` itself (`decidedAt` is captured at guard-run
time via `DateTimeImmutable`, `appealDeadline = decidedAt->modify('+42 days')`), exactly the pattern
`ExternalTrainingVerificationGuard` already uses to stamp `verifiedBy`/`verifiedAt`
(`lib/Lifecycle/ExternalTrainingVerificationGuard.php:154-157`). This is a guard side-effect, not a
declarative calculation — documented here so a future engineer doesn't "simplify" it into an
`x-openregister-calculations` entry that silently breaks because the DSL can't do date math yet.

## 4. Cross-schema handlers — registration is explicit, guards are not

Checked at HEAD (`lib/AppInfo/Application.php:177-248`): every existing `ObjectTransitionedEvent` listener
(`ExcuseApprovalHandler`, `GradeRollupHandler`, `CredentialIssuanceHandler`, etc.) is explicitly registered
via `$context->registerEventListener(event: ObjectTransitionedEvent::class, listener: X::class)` in
`Application::register()`. **Lifecycle guards are not** — `OsoDossierReviewGuard`'s docblock states plainly
"OR resolves guards by fully-qualified class name from the schema — no Application.php registration needed"
(`lib/Lifecycle/OsoDossierReviewGuard.php:16-17`), and every `requires: [...]` entry in the register
(`AssessmentGradeGuard`, `SubmissionWindowGuard`, `AttendanceFlagReportGuard`) confirms this — guards are
resolved by FQCN string alone. Consequence for this change: `ExemptionGrantHandler` and
`FraudCaseDecisionHandler` **must** be added to `Application::register()`; `ExemptionDecisionGuard`,
`FraudCaseHearingGuard`, `FraudCaseDecisionGuard`, `FraudCaseBlockGuard`, `FraudCaseInvalidationGuard`
**must not** — they only need their `requires: [...]` FQCN reference in the schema.

`ExemptionGrantHandler.handle()` filters to `register=scholiq, schema=exemption-case, to=granted`, reads
`curriculumPlanId`/`componentId`/`learnerId` off the event object, then calls `ObjectService::saveObject()` to
create the `GradeEntry`, then a second call to drive its `publish` transition — **not** a raw
`lifecycle: published` field write, so the existing `x-openregister-lifecycle` engine (not this handler)
remains the single place that fires the `gradePublished` notification and writes the audit-trail entry (per
ADR-008, same discipline `ExemptionGrantHandler`'s proposal.md description already commits to).

`FraudCaseDecisionHandler.handle()` filters to `register=scholiq, schema=fraud-case, to=decided`, checks
`verdict === 'fraud-proven'` and `contestedGradeEntryId` is set, fetches that `GradeEntry`, and — only if its
`lifecycle === 'concept'` — drives it through `invalidate`. If the `GradeEntry` somehow already reached
`published` (should be structurally impossible while `FraudCaseBlockGuard` is wired correctly, but defensive
coding matters here — see §7), the handler logs a warning and takes no action rather than mutating a
published, notified grade out from under a learner; that scenario would need a manual, out-of-band
correction, which this change deliberately does not attempt to automate (see §8 Out of Scope).

## 5. `FraudCaseBlockGuard` and `FraudCaseInvalidationGuard` — cross-schema read guards

Both follow the exact shape of `AttendanceFlagReportGuard`
(`lib/Lifecycle/AttendanceFlagReportGuard.php:24-27` — "When a [x]Id is set... verifies the linked [Y] has
reached [state]. When no [x] is linked, allows the transition unconditionally"):

- **`FraudCaseBlockGuard`** guards `GradeEntry.publish` and `GradeEntry.republish`. If `fraudCaseId` is
  unset, allow unconditionally. If set, fetch the `FraudCase` via `ObjectService::find()`; block if its
  lifecycle is `reported`, `hearing-scheduled`, or `heard` (case still open), or if it is `decided` with
  `verdict: fraud-proven` (permanently blocked — the only forward path for that `GradeEntry` is
  `invalidate`, driven by `FraudCaseDecisionHandler`, never by a user retrying `publish`). Allow if `decided`
  with `verdict: unfounded`, or if `dismissed`.
- **`FraudCaseInvalidationGuard`** guards the new `GradeEntry.invalidate` transition (`concept →
  invalidated`). Allow only if `fraudCaseId` is set and the linked `FraudCase` is `decided` with `verdict:
  fraud-proven`. This guard's sole caller in practice is `FraudCaseDecisionHandler` (§4) — a user cannot
  invoke `invalidate` on their own initiative in any scenario where the guard would pass, because the guard's
  precondition is exactly the state `FraudCaseDecisionHandler` reacts to.

## 6. `GradeFormulaEvaluator` extension — verified against the current implementation

Checked at HEAD (`lib/Grading/GradeFormulaEvaluator.php:396-449`): `weightedAverage()` currently does
`$value = (float) ($entry['value'] ?? 0)` unconditionally for every entry — an exemption entry's `value: null`
would cast to `0.0` and be summed with full weight, dragging the average down exactly as the proposal's "Why"
section describes. The fix, scoped to two methods:

- **`weightedAverage()`**: skip any entry with `sourceKind === 'exemption'` from `$weightedSum`/`$totalWeight`
  accumulation (both the overall totals and the per-period `$periodTotals` accumulation at
  lines 412-418). Still emit a `$componentBreakdown[$cid]` entry for it, but shaped `{ exempt: true }`
  instead of `{ value, weight, contribution }` — the roll-up UI reads `breakdown.components[componentId]` to
  render "why does this count," and an `exempt: true` marker is more honest than a fabricated
  `value: 0`/`contribution: 0` pair that would visually read as "the learner scored zero here."
- **`evaluatePassed()`**'s `all-must-pass` branch (lines 480-496): `bestOfNEntries()` already reduces to one
  entry per `componentId`; if that best entry is `sourceKind === 'exemption'`, treat the corresponding
  `passRules` check for that `componentId` as satisfied without comparing `(float) $bestValue` against the
  rule's threshold (an exemption has no numeric value to compare — the exam board's decision *is* the pass
  signal). Every other component's `passRules` check is unaffected.

No other formula path (`last-attempt`, `best-of-n` as the *plan's* formula rather than the internal reduction
helper) needs a code change beyond flowing through the same `weightedAverage()` fix, since both call it as
their final step (`applyFormula()`, line 292-306).

## 7. Data Model

All in OpenRegister, `lib/Settings/scholiq_register.json` (top-level `info.version` 0.3.1 → 0.4.0 — **note**:
sibling in-flight changes in this worktree, e.g. `bpv-praktijkovereenkomst`, independently plan the same
0.3.1 → 0.4.0 bump; whichever change lands first should take 0.4.0, the second retargets 0.5.0 at apply
time — flagged here rather than silently assumed away).

**New: `ExemptionCase`**
`learnerId` ($ref LearnerProfile), `curriculumPlanId` ($ref CurriculumPlan), `componentId` (string, matches a
`CurriculumPlan.components[].componentId`), `groundsKind` (enum `prior-diploma | certificate |
work-experience | other`), `groundsDescription` (string), `submittedAt` (date-time), `decisionRationale`
(nullable string, required by `ExemptionDecisionGuard` on grant/reject), `policyReference` (nullable string,
same guard), `decidedBy` (nullable NC user id), `decidedAt` (nullable date-time), `resultingGradeEntryId`
(nullable $ref GradeEntry, set by `ExemptionGrantHandler`), `tenant_id`, `lifecycle`
(`submitted|in-assessment|granted|rejected|withdrawn`). Evidence: OR native file attachments, no schema
property (§2.1 rationale).

**New: `FraudCase`**
`reporterId` (NC user id), `accusedLearnerId` ($ref LearnerProfile), `sourceKind` (same enum as `GradeEntry`:
`assignment-submission|assessment-result|participation|manual`), `submissionId`/`assessmentResultId`/
`sessionId` (nullable $refs, mirrors `GradeEntry`'s shape), `contestedGradeEntryId` (nullable $ref
GradeEntry), `allegation` (string), `reportedAt` (date-time), `hearingDate` (nullable date-time, required by
`FraudCaseHearingGuard` on `scheduleHearing`), `hearingRecords` (array of `{ heldAt, attendees[], notes,
evidenceRefs[] }`, append-style — new records added, not overwritten), `verdict` (nullable enum
`fraud-proven|unfounded`), `decisionRationale` (nullable string), `decidedBy` (nullable NC user id),
`decidedAt` (nullable date-time), `sanctionType` (nullable enum `warning|grade-annulment|
resubmission-required|suspension|exclusion`), `sanctionDurationMonths` (nullable integer, max 12),
`sanctionScope` (nullable enum `single-assessment|course|programme`), `appealDeadline` (nullable date-time,
guard-stamped — §3), `appealLodged` (boolean, default false), `appealOutcome` (nullable enum
`pending|upheld|overturned`), `tenant_id`, `lifecycle`
(`reported|hearing-scheduled|heard|decided|dismissed`).

**Modified: `GradeEntry`** — `sourceKind` enum gains `exemption`; `exemptionCaseId`/`fraudCaseId` (nullable
$refs) added; `value`'s `required`-ness becomes conditional (required for every `sourceKind` except
`exemption`, where the schema must instead reject a non-null value — implemented as a JSON Schema
`if`/`then` on `sourceKind`, the same conditional-requiredness idiom already used elsewhere in this register
for optional-scope fields, e.g. `courseId`/`cohortId` nullability on the same schema); `publish`/`republish`
transitions gain `requires: [FraudCaseBlockGuard]`; new `invalidated` terminal state + `invalidate` transition
(`concept → invalidated`, `requires: [FraudCaseInvalidationGuard]`); `x-property-rbac.read` gains the
`examboard` role.

**Modified: `FinalGrade`** — `breakdown.components[componentId]` shape note only (documentation-level; no new
top-level property) — an `exempt: true` marker appears on components satisfied by an exemption.

## 8. Security Considerations — fraud dossiers are highly sensitive

- **Object-level read RBAC (server-enforced)**: `FraudCase.x-property-rbac.read` is `anyOf: [role: admin,
  role: examboard, match: accusedLearnerId == $userId, match: reporterId == $userId]` — the same
  role-plus-self-match `anyOf` shape as `ExternalTrainingRecord`
  (`lib/Settings/scholiq_register.json:2281-2304`). Anyone outside that set gets a fail-closed denial from
  OpenRegister itself, before any Scholiq code runs.
- **Field-level read RBAC (NOT server-enforced — documented gap, not silently assumed)**: within the
  readable set above, "the accused and reporter should not see internal hearing deliberation notes, only the
  case outline and eventual outcome" is a real requirement from journey 1745 and the UI section of
  proposal.md. Checked at HEAD: **this register has zero field-level read/write RBAC primitive** —
  `x-property-rbac` everywhere in this register (including the two precedents checked, `GradeEntry` and
  `ExternalTrainingRecord`) scopes the *entire object's* readability, never individual properties. This is
  the identical residual gap already flagged in `secure-exam-test-mode/design.md` §4.2 for
  `ProctoringSession.flags[].reviewDecision` — restated here rather than silently assumed fixed, because a
  fraud dossier is a materially higher-stakes place for that gap to bite than a proctoring flag. The
  practical consequence: `ExamCaseDossierView` withholds `hearingRecords`/decision-internal fields from the
  accused/reporter in its own rendering logic, but any of those users retains full field-level read access to
  the raw object via the generic OpenRegister object API (e.g. a direct API call, not just the Scholiq UI).
  This is an application-level UI convention, not a security boundary — documented explicitly in the spec
  delta's RBAC requirement (see `specs/exam-board/spec.md`, "FraudCase read access is restricted; hearing/
  decision internals are UI-gated within that set") so it is never mistaken for one. Closing it properly
  would need OpenRegister engine work on field-level RBAC, out of scope for this M-sized change (§10).
- **`examboard` role/group naming**: proposal.md's notification section already commits to `groups:
  [examboard]` (no hyphen) for both new schemas' creation notifications. This design uses the identical
  string `examboard` for the `x-property-rbac.read` role clause, so RBAC role membership and notification
  group membership are the same NC group — one group to administer, not two that could drift apart. (Other
  roles in this register, e.g. `compliance-officer`/`hr`, are hyphenated; `examboard` deliberately follows
  proposal.md's own already-fixed choice rather than "fixing" it to `exam-board` mid-change and creating a
  mismatch between the spec delta's prose and the actual notification config.)
- **Sanction cap is schema-enforced, not just guard-enforced**: `sanctionDurationMonths` gets both a JSON
  Schema `maximum: 12` (defense in depth — rejects any direct API write above the cap, not just one that goes
  through `decide`) and `FraudCaseDecisionGuard`'s check that it is set at all when `verdict: fraud-proven`.
  Neither alone is sufficient: the schema-level `maximum` doesn't enforce "must be set for a proven verdict,"
  and the guard alone wouldn't stop a direct object PUT bypassing `decide` after the fact — OpenRegister's
  generic object-update path is not itself lifecycle-transition-gated for non-lifecycle fields, matching the
  same residual-write-path caveat already documented for `ProctoringSession` in the previous bullet.
- **No secrets, tokens, or new external-facing endpoints** in this change — everything is internal
  NC-authenticated users through the existing OpenRegister object API + `ExamCaseDossierView`.
- **EU AI Act**: not applicable — every decision in both state machines is made by a human exam-board member;
  there is no AI-assisted scoring, detection, or recommendation anywhere in this change (the plagiarism
  *detection* signal, if any, comes from the pre-existing `assignments/spec.md` pluggable
  `x-plagiarism.provider` hook, which is out of this change's scope — a `FraudCase` can equally originate from
  a teacher's manual observation).

## 9. File Structure

```
lib/Lifecycle/ExemptionDecisionGuard.php        (new)
lib/Lifecycle/FraudCaseHearingGuard.php         (new)
lib/Lifecycle/FraudCaseDecisionGuard.php        (new)
lib/Lifecycle/FraudCaseBlockGuard.php           (new)
lib/Lifecycle/FraudCaseInvalidationGuard.php    (new)
lib/Listener/ExemptionGrantHandler.php          (new — registered in Application.php)
lib/Listener/FraudCaseDecisionHandler.php       (new — registered in Application.php)
lib/Grading/GradeFormulaEvaluator.php           (modified — exemption-aware weightedAverage/evaluatePassed)
lib/AppInfo/Application.php                     (modified — +2 registerEventListener calls)
lib/Settings/scholiq_register.json              (+2 schemas; GradeEntry modified; info.version bump)
src/manifest.json                               (+ index/detail pages, + ExamCaseDossierView)
src/views/ExamCaseDossierView.vue               (new — shared, tab-switched)
tests/Unit/Lifecycle/                           (new — 5 guard unit tests)
tests/Unit/Listener/                            (new — 2 handler unit tests)
tests/Unit/Grading/GradeFormulaEvaluatorTest.php (modified — exemption scenarios)
openspec/
  changes/exam-board-case-handling/             (this change)
  specs/exam-board/spec.md                      (new capability, created on archive)
  specs/grading/spec.md                         (modified on archive)
```

## 10. Out of Scope

- The plagiarism-detection algorithm or vendor integration itself — `assignments/spec.md`'s
  `x-plagiarism.provider` remains a signal-only hook; this change consumes a suspected-fraud report however
  it originates and does not add or require a concrete detector.
- CBE appeal *adjudication* (the external body's own hearing/decision process) — this change only records
  `appealLodged`/`appealOutcome` as case facts and stamps the 42-day `appealDeadline`.
- Field-level read/write RBAC on `FraudCase` (§8) — a cross-cutting OpenRegister capability gap, restated
  here as a known, not silently assumed, limitation; not fixable within this change's scope.
- Automatic reminders/SLA tracking on open cases or approaching appeal deadlines — a follow-up notification
  concern.
- Retroactive GPA-wide recalculation or cascading academic-standing effects triggered by a sanction beyond
  invalidating the single contested `GradeEntry` — a school-policy decision outside this spec.
- A dedicated `withdrawGuard`/`dismissGuard` for `ExemptionCase.withdraw` / `FraudCase.dismiss` — both remain
  intentionally unguarded (§2); adding role-scoping to them, if a future need arises, is a small, isolated
  follow-up, not bundled into this M-sized change.
