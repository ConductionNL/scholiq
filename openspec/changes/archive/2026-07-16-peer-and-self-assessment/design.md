# Design: peer-and-self-assessment

## Context

`assignments` (`openspec/specs/assignments/spec.md`) explicitly deferred peer review as "a follow-up"
(`:95`). The building blocks it left behind are genuinely reusable: `Rubric` (`lib/Settings/scholiq_register.json:3791`)
is already schema-first and criterion/level-weighted; `Submission` (`:4143`) already has a `rubricScores`
array shape (`criterionId`/`levelId`/`points`) that a peer or self review can copy verbatim. What's missing
is: (1) a way to configure peer/self assessment per `Assignment`, (2) a way to *allocate* reviewers (OR has
no batch-matching primitive), (3) a place to store a review that is structurally distinct from the teacher's
authoritative mark, and (4) a way to keep a reviewer's identity hidden from the person they reviewed without
a field-level RBAC primitive that this register does not have at HEAD.

## Goals / Non-Goals

**Goals**
- Per-Assignment toggles for peer review and self-assessment, with reviewer count, anonymity mode, and
  allocation strategy.
- A dedicated allocation service (round-robin / random / manual) scoped to an Assignment's own submitters.
- `PeerReview` and `SelfAssessment` as first-class objects with their own lifecycles, scored against the
  Assignment's existing `Rubric`.
- A grade-authority boundary that is structural, not conventional: peer/self scores cannot become a
  `GradeEntry` by any code path this change adds.
- Server-side (not UI-only) enforcement of reviewer anonymity, to the extent the platform's RBAC primitive
  allows — and an explicit, honest statement of where that enforcement runs out.

**Non-Goals**
- Reciprocal-pair avoidance (guaranteeing A and B don't review each other) for the `random` strategy — a
  refinement, not required at this scope.
- Redacting identifying content *inside* attachments (filenames, watermarks, in-document names) for
  double-blind review — needs a `docudesk`-style content-processing step this change doesn't build.
- Any automatic write path from peer/self scores into `GradeEntry` or `FinalGrade` — deliberately excluded,
  see "Grade Authority" below.
- Reviewer-load balancing across multiple Assignments/Cohorts — out of scope; the allocator only ever
  balances within one Assignment's own submitter pool.

## Data Model

```
Assignment (existing, +7 properties)
  peerReviewEnabled, selfAssessmentEnabled,
  peerReviewersPerSubmission, peerReviewAnonymity, peerReviewAllocationStrategy,
  peerReviewDueAt, peerReviewWeightPercent (advisory only),
  selfAssessmentTiming
      │
      │  PeerReviewAllocationService.allocate(assignmentId)  ── new PHP, admin-only action
      ▼
  PeerReview (new)                              SelfAssessment (new)
    assignmentId, submissionId, reviewerId         assignmentId, submissionId, learnerId, timing
    rubricScores[], totalScore, comments           rubricScores[], totalScore, comments
    lifecycle: assigned → submitted → released     lifecycle: draft → submitted
    requires RubricScoresCompletionGuard@submit     requires RubricScoresCompletionGuard@submit
    x-property-rbac.read: admin, reviewer-self      x-property-rbac.read: admin, learner-self
      │  released
      ▼
  PeerFeedbackSummary (new, computed, readOnly)  ── one row per Submission
    submissionId, assignmentId
    reviewCount (x-openregister-aggregations, metric: count, where: released)
    averageScore, feedbackItems[] (comments, rubricScores, reviewerId?)  ── PeerFeedbackAggregator listener
    x-openregister-triggers.calculatedChange.handler: PeerFeedbackAggregator (fires on PeerReview→released)

Submission (existing, UNCHANGED)  ──  MarkSubmissionView (existing, extended)
  rubricScores[], proposedGrade      reads PeerFeedbackSummary + SelfAssessment as READ-ONLY context;
      │  teacher marks, unchanged    the teacher's own rubricScores/proposedGrade entry is the only write path
      ▼
  GradeEntry (grading spec, UNCHANGED — sourceKind enum NOT extended)
```

### `Assignment` additions

Flat fields, matching the existing style (`allowLateSubmission`/`latePenaltyPercent`/`groupSubmission` are
already flat booleans/enums on `Assignment`, not a nested config object) — additive, all seven default to
`false`/`null`/a safe enum default, so every existing `Assignment` row validates unchanged:

| Field | Type | Default | Notes |
|---|---|---|---|
| `peerReviewEnabled` | boolean | `false` | |
| `selfAssessmentEnabled` | boolean | `false` | |
| `peerReviewersPerSubmission` | integer, nullable, min 1 | `2` | ignored when `allocationStrategy: manual` |
| `peerReviewAnonymity` | enum `open\|blind\|double-blind` | `blind` | see "Anonymity Enforcement" |
| `peerReviewAllocationStrategy` | enum `round-robin\|random\|manual` | `round-robin` | |
| `peerReviewDueAt` | date-time, nullable | `null` | display-only deadline; no lifecycle guard blocks a late peer review in this change (unlike `Submission.dueAt`) — a soft deadline, not a hard one, matching the lower stakes of a review vs. a submission |
| `peerReviewWeightPercent` | number, nullable, 0–100 | `null` | advisory display weight only — see "Grade Authority" |
| `selfAssessmentTiming` | enum `before-submission\|after-submission\|both` | `after-submission` | |

`AssignmentPublishGuard` (existing, `lib/Settings/scholiq_register.json:4087`, guards `draft → published`) is
extended with one new check: if `peerReviewEnabled` or `selfAssessmentEnabled` is `true`, `rubricId` MUST be
set — publish is blocked otherwise. Without this, an Assignment could go live promising peer/self assessment
against a rubric that doesn't exist.

### `PeerReview`

One row per (reviewer, submission) pair. `rubricScores` and `totalScore` are the exact shape already used by
`Submission.rubricScores`/`proposedGrade` (`lib/Settings/scholiq_register.json:4143` region) — same
`{criterionId, levelId, points}[]` item shape, same "teacher/reviewer enters it, not a declared calculation"
convention (`Submission.proposedGrade`'s own description says "as entered by the teacher", it is not a
materialised `x-openregister-calculations` expression either).

`x-openregister-authorization.create: [admin]` — mirrors `BsaWarning`/`BsaDecision`
(`lib/Settings/scholiq_register.json:6*`, per `openspec/specs/study-progress/spec.md`): a reviewer never
creates their own `PeerReview` row; only `PeerReviewAllocationService` (running with admin-equivalent
context, the same way `BsaProgressFlagHandler` creates `BsaProgressFlag` rows despite the schema's own create
restriction) or a teacher's manual `manual`-strategy creation puts one there. A reviewer only ever
*transitions* an existing row.

### `SelfAssessment`

No create restriction — a learner creates their own `SelfAssessment` the same way they create their own
`Submission` (`assignments` spec: `Submission` has no `x-openregister-authorization.create` restriction
either). `learnerId` MUST be one of the linked `Submission.learnerIds` (checked by
`RubricScoresCompletionGuard`, see below — reusing the same guard rather than adding a second one).

### `PeerFeedbackSummary`

Computed, `x-openregister.readOnly: true` (mirrors `FinalGrade`,
`lib/Settings/scholiq_register.json:5721`: "Read-only from the frontend"). `reviewCount` uses the confirmed
declarative `x-openregister-aggregations` shape (`metric: count`, `where: {submissionId: "@self.submissionId",
lifecycle: "released"}` — the exact pattern `FinalGrade.publishedEntries` already uses). `averageScore` and
`feedbackItems` are **not** attempted as pure JSON-logic or a second aggregation — this register's confirmed
`x-openregister-aggregations.metric` vocabulary at HEAD is `count`/`count_distinct` only (verified: a
full-file grep for `"metric":` finds no `avg`/`sum` value anywhere), and `feedbackItems` needs to *copy and
conditionally redact* a field per matching row, not reduce to a scalar — beyond what any declarative
aggregation in this register does today. Both are computed together by one PHP listener,
`PeerFeedbackAggregator`, the same ADR-031 "declarative can't express it" exception `GradeFormulaEvaluator`
and `BsaProgressEvaluator` already are, wired via `x-openregister-triggers.calculatedChange.handler` (the
confirmed real keyword — see "Rejected Alternatives" below on why this design does *not* use a fabricated
`"engine"` key).

## Reviewer Allocation

`PeerReviewAllocationService::allocate(string $assignmentId)`, invoked by
`PeerReviewController::allocate()` (`#[NoAdminRequired]` + an explicit per-object check that the caller is
`admin` or holds write access to the Assignment's `Course`/`Cohort` — no bare "authenticated user" gate, per
the architecture rule on new controller methods).

1. **Pool** = the `learnerIds` of every `Submission` for this `assignmentId` (mutual peer review among
   people who actually did the work — not the whole cohort roster, since a non-submitter has nothing to
   contribute and Moodle Workshop's own two-phase model draws the same boundary).
2. **Self-exclusion**: for a given `Submission`, every learner in *that submission's own* `learnerIds` is
   excluded from its reviewer pool (covers group submissions — no group member reviews their own group's
   work).
3. **`round-robin`**: submissions and eligible reviewers are each ordered (by `submittedAt`, ties by object
   id, for determinism); reviewers are assigned to submissions cyclically until each submission has
   `peerReviewersPerSubmission` reviewers, skipping self-matches by advancing to the next candidate in the
   ring.
4. **`random`**: same pool and self-exclusion rule, shuffled instead of cyclic. No reciprocal-pair
   guarantee (see Non-Goals).
5. **`manual`**: no-op — the endpoint returns immediately; the teacher creates `PeerReview` rows through the
   ordinary manifest create form.
6. **Idempotency**: re-running `allocate()` only tops up submissions that have fewer than
   `peerReviewersPerSubmission` *non-cancelled* `PeerReview`s — it never duplicates an existing
   (reviewer, submission) pair.

This is a service, not a declarative rule, because it is a batch-matching problem over a *set* of objects
(which OR's per-object calculation/aggregation engine does not do) — the same rationale the brief states
directly and the same shape `GradeFormulaEvaluator`/`BsaProgressEvaluator` already establish for "cross-object
logic JSON-logic can't express."

## Grade Authority

The teacher's mark is the only path to a `GradeEntry`. This change does not touch
`GradeEntry.sourceKind` (`assignment-submission | assessment-result | participation | manual | exemption |
lti-ags`, `openspec/specs/grading/spec.md:52-58`) and does not add a `peer-review` value to it. Concretely:

- `PeerReview.rubricScores`/`totalScore` and `SelfAssessment.rubricScores`/`totalScore` are stored on their
  own objects — there is no code path in this change that writes into `Submission.rubricScores` or
  `Submission.proposedGrade`.
- `Assignment.peerReviewWeightPercent`, when set, is read by `MarkSubmissionView` (existing view, extended)
  to compute a *suggested* number — `teacherScore × (1 − w) + PeerFeedbackSummary.averageScore × w` — shown
  next to, never instead of, the teacher's own entry fields. It is display arithmetic in the Vue component,
  not a stored or calculated schema value. The teacher still has to type/confirm `proposedGrade` themselves;
  nothing auto-fills it.
- `peerReviewWeightPercent: null` (the default) means peer scores are shown for reference only, with no
  blended number suggested at all.

This mirrors every competitor cited in `proposal.md`'s "Why": Moodle Workshop keeps "grade for submission"
and "grade for assessment" as two distinct numbers; Open edX ORA's peer step feeds a visible score but the
course's grading policy — configured by course staff — decides if/how it counts; Gibbon keeps peer/self forms
as a separate data type from the markbook. None of them let a peer score silently become the transcript
value, and this design doesn't either — which matters concretely because `GradeEntry`/`FinalGrade` already
feed exam-board fraud/exemption appeal dossiers (`openspec/specs/grading/spec.md:159-169`); an appeal
reviewer must be able to trust that every `GradeEntry` traces to a `sourceKind` a human institution deliberately
authorised, not a classmate's rubric click.

## Anonymity Enforcement

This is the part of the brief that demands the most honesty. **This register has no field-level RBAC
primitive at HEAD** — confirmed, not assumed: it is the register's own documented residual limitation on
three unrelated schemas already (`FraudCase`, `lib/Settings/scholiq_register.json:6462`;
`ProctoringSession.flags[].reviewDecision`, `:5269`; `parent-conferences` `pupilVoice.statementNote`,
`:7776`). `x-property-rbac.read` only supports whole-object `role`/`match` clauses (`operator: eq` against a
scalar field, or `role` — confirmed: a full-file grep for `"operator":` finds only `eq`, `olderThan`,
`withinNext`; no array-containment operator exists to match, e.g., "$userId is in `Submission.learnerIds`").
Given that, this design does **not** pretend a config-conditional field-level rule can be declared. Instead:

**The reviewer-identity axis (hiding *who* reviewed you from the author) is fully server-enforced, for every
anonymity mode, via object-shape projection rather than a field-level permission:**

- `PeerReview.x-property-rbac.read` is a *fixed* rule, independent of `Assignment.peerReviewAnonymity`:
  `anyOf: [{role: admin}, {match: {field: reviewerId, operator: eq, value: $userId}}]`. The submission's
  author can never read a raw `PeerReview` row — not because of a mode setting, but structurally, always.
  (`admin` is the same staff-access convention `GradeEntry`/`FinalGrade`/`BsaDecision` already use — this
  register has no separate "teacher" RBAC role at HEAD; a full grep of every `"role":` value in the register
  confirms the only roles are `admin`, `compliance-officer`, `exam-board`/`examboard`, `hr`, `principal`,
  `study-advisor`. Inventing a "teacher" role here would be ungrounded.)
- The author instead reads `PeerFeedbackSummary` — a schema that, when `peerReviewAnonymity` is `blind` or
  `double-blind`, has `feedbackItems[].reviewerId` computed as `null` by `PeerFeedbackAggregator`; when
  `open`, the aggregator populates it. The identity is **structurally absent from the object the author can
  read**, not merely permission-gated on a field that's still present — the strongest guarantee this
  register's declarative layer can offer, and a genuine field-level-equivalent outcome achieved through
  object-level RBAC + a narrower projection object (the same "field-projection" concept already named, as a
  future need, in the `parent-conferences` gap comment at `:7776` — this change is the first to actually build
  it).

**The reviewee-identity axis (hiding *whose work you're reviewing* from the reviewer, for `double-blind`) is
NOT fully server-enforced, and this design says so rather than glossing over it:**

- A reviewer must open the `Submission` object itself to see the work (`attachmentRefs`) they're grading.
  `Submission` carries `learnerIds` and has no `x-property-rbac` restriction at all today (confirmed: neither
  `Assignment` nor `Submission` declares an `x-property-rbac` block at HEAD) — widening `Submission`'s RBAC
  posture for *every* reader, just to solve this one case, is a materially bigger blast-radius change than
  this scope, and this register has no operator to express "hide this one field from this one caller" even
  if it were in scope.
- `PeerReviewMarkingView` (the reviewer's UI) is written to never *display* `Submission.learnerIds` or a
  learner display name when `peerReviewAnonymity: double-blind` — but that is a UI convention, exactly like
  `ExamCaseDossierView`'s documented withholding of `FraudCase.hearingRecords`
  (`openspec/specs/exam-board/spec.md:131-139`, "not a server-enforced field-level RBAC guarantee ... anyone
  holding object-level read access can retrieve the full object via the generic object API"). The same
  sentence applies here verbatim.
- **Consequence, stated plainly**: `double-blind` in this change means "the author never learns who reviewed
  them" (hard guarantee) **and** "the reviewer's UI doesn't show them who they're reviewing" (soft,
  UI-level only — a reviewer with API access, or reading a downloaded file's own metadata/filename, could
  still identify the author). A true double-blind server guarantee needs either a field-level RBAC primitive
  in OpenRegister, or a content-redaction step (stripped filenames, redacted document headers) via a
  `docudesk`-style pipeline — both out of scope here and flagged as follow-ups in `proposal.md`.

## Rejected Alternatives

- **Add `peer-review` to `GradeEntry.sourceKind`.** Rejected — every competitor cited in "Why" keeps peer
  scores as a second signal, and `GradeEntry` already feeds exam-board appeal dossiers where provenance
  matters. Silently widening the sole authoritative-grade schema's origin enum is exactly the kind of
  "silently become the GradeEntry" outcome the brief explicitly forbids.
- **Field-level RBAC via a fabricated `"engine"` key or a conditional `x-property-rbac` rule keyed off a
  related object's config.** Rejected — neither is a real capability at HEAD (confirmed by grep: no
  `"engine"` key exists anywhere in the register; `x-property-rbac.match` only supports `eq` against a field
  on the *same* object, never a cross-object lookup). Asserting either would be inventing schema/engine
  behavior the codebase doesn't have, which the ground-truth rule for this change forbids.
  - **Reconsider if**: OpenRegister ships a genuine field-level read-projection or per-role field-mask
    primitive — at that point `PeerReview` could drop the separate `PeerFeedbackSummary` object entirely and
    mask `reviewerId` directly, and `Submission` could mask `learnerIds` for double-blind reviewers without a
    parallel schema.
- **Skip `PeerFeedbackSummary` and let the author read `PeerReview` directly with `reviewerId` client-side
  hidden.** Rejected — this is exactly the `ExamCaseDossierView` pattern the exam-board spec itself flags as
  *not* a server-enforced guarantee (`openspec/specs/exam-board/spec.md:136-139`). The brief explicitly asks
  for server-side enforcement; a separate, narrower object is the only way this register can deliver that
  today.
- **One combined `RubricAssessment` schema for teacher marking, peer review, and self-assessment
  (discriminated by an `assessorRole` field) instead of three objects.** Rejected — `Submission.rubricScores`
  is the teacher's authoritative field and already exists with its own RBAC/lifecycle posture; folding peer
  and self scores into the same schema would either require loosening `Submission`'s implicit teacher-only
  write path or duplicating guard logic to re-derive "is this row the authoritative one" per read — more
  fragile than three small, single-purpose objects with their own lifecycles.
- **Two near-duplicate completion guards, one per schema.** Rejected — `PeerReview.submit` and
  `SelfAssessment.submit` need the identical check (rubricScores covers every criterionId in the linked
  Assignment's Rubric); one shared `RubricScoresCompletionGuard`, parameterized by the object's own
  `assignmentId`/`submissionId` relation, avoids the duplication `BsaWarningSigningGuard`/`BsaDecisionGuard`
  accepted as two separate classes only because their actual guard logic differs (signature stamping vs.
  cross-object warning existence) — here it doesn't differ.

## Security / Privacy Posture

- `PeerReview.x-property-rbac.read`: `admin` + reviewer-self only — see "Anonymity Enforcement".
- `SelfAssessment.x-property-rbac.read`: `admin` + `{match: {field: learnerId, operator: eq, value:
  $userId}}` — a learner reads only their own self-assessment; mirrors `GradeEntry`'s learner-self pattern.
- `PeerFeedbackSummary` carries no explicit `x-property-rbac` block, matching the existing (looser) default
  posture already accepted on `Assignment`/`Submission`/`Rubric` at HEAD — this change does not widen or
  narrow that baseline; it only guarantees the one thing it's asked to guarantee (reviewer identity), via
  object shape, as described above.
- `x-openregister-authorization.create: [admin]` on `PeerReview` — a reviewer can never author their own
  assignment row, only transition an allocated one.
- No new i18n risk beyond the standard NL/EN pair on the one notification this change adds
  (`PeerReview.assigned` → reviewer, `recipients: [{kind: field, field: reviewerId}]` — a confirmed-safe
  scalar-field recipient, the same shape `FraudCase`'s `reporterId` notification already uses). A
  "feedback released" push notification to the author was considered and deliberately deferred: it would
  need a recipient field resolved through `Submission.learnerIds`, which is an array — no confirmed
  precedent in this register demonstrates array-field notification fan-out, and `Submission.learnerIds`
  supporting group submissions makes a single-recipient guess unreliable. The author instead discovers new
  feedback by opening their `Submission` detail page (pull, not push) where the `PeerFeedbackSummary` panel
  now renders — a documented scope decision, not a silent gap.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects; no new database tables (ADR-001).
- No pass-through CRUD controller — the only new controller method (`PeerReviewController::allocate`) is
  genuine batch-matching business logic OR's object API cannot perform, exactly the class of exception
  ADR-022 permits.
- Declarative first: `x-openregister-aggregations` used for `reviewCount` (confirmed real shape); the two
  PHP exceptions (`PeerReviewAllocationService`, `PeerFeedbackAggregator`) and two guard extensions
  (`AssignmentPublishGuard` extended, new shared `RubricScoresCompletionGuard`) are the narrowest set that
  covers what JSON-logic/aggregations verifiably cannot express at HEAD (ADR-031).
- Notifications via the `x-openregister-notifications` dialect only, one rule, confirmed-safe scalar
  recipient (ADR-031).
- UI is manifest-driven; the two new custom views (`PeerReviewMarkingView`, `SelfAssessmentView`) are genuine
  scoring UI a manifest form can't express (rubric-level picking, same bar `MarkSubmissionView` was already
  held to); `MarkSubmissionView`'s extension is a read-only context panel, not new CRUD.
- i18n keys in English; SPDX headers on all new PHP files.
