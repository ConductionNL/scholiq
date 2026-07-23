# Tasks: peer-and-self-assessment

## 1. Schema — Assignment delta

- [x] 1.1 Add `peerReviewEnabled` (boolean, default `false`), `selfAssessmentEnabled` (boolean, default
  `false`), `peerReviewersPerSubmission` (integer, nullable, minimum 1, default 2),
  `peerReviewAnonymity` (enum `open|blind|double-blind`, default `blind`), `peerReviewAllocationStrategy`
  (enum `round-robin|random|manual`, default `round-robin`), `peerReviewDueAt` (nullable date-time),
  `peerReviewWeightPercent` (nullable number, minimum 0, maximum 100), and `selfAssessmentTiming` (enum
  `before-submission|after-submission|both`, default `after-submission`) to the `Assignment` object in
  `lib/Settings/scholiq_register.json` (`~3930` region). All additive; do not touch `required`.
  - **spec_ref**: `specs/assignments/spec.md#requirement-persist-assignment-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Existing `Assignment` rows validate unchanged (new fields absent/default)
    - Every new property has an English `title` + `description`
- [x] 1.2 Extend `AssignmentPublishGuard` (`OCA\Scholiq\Lifecycle\AssignmentPublishGuard`,
  `lib/Settings/scholiq_register.json:4087`): on `draft → published`, additionally block when
  `peerReviewEnabled` or `selfAssessmentEnabled` is `true` and `rubricId` is unset.
  - **spec_ref**: `specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric`
  - **acceptance_criteria**:
    - Unit test: publish blocked when peer/self enabled without rubricId
    - Unit test: existing publish behaviour (rubricId set, or peer/self both disabled) unaffected

## 2. Schema — new objects

- [x] 2.1 Add `PeerReview` to `lib/Settings/scholiq_register.json`: `assignmentId` ($ref `Assignment`),
  `submissionId` ($ref `Submission`), `reviewerId` (string), `rubricScores` (array, `{criterionId, levelId,
  points}[]`, same item shape as `Submission.rubricScores`), `totalScore` (nullable number), `comments`
  (nullable string), `lifecycle` (`assigned|submitted|released`, default `assigned`), `tenant_id`.
  `x-openregister-lifecycle`: `submit` (`assigned → submitted`, `requires:
  OCA\Scholiq\Lifecycle\RubricScoresCompletionGuard`), `release` (`submitted → released`, no guard).
  `x-openregister-authorization.create: [admin]`. `x-property-rbac.read`: `anyOf: [{role: admin}, {match:
  {field: reviewerId, operator: eq, value: $userId}}]`. `x-openregister-notifications.assigned` →
  `recipients: [{kind: field, field: reviewerId}]`, NL/EN subject.
  - **spec_ref**: `specs/assignments/spec.md#requirement-peerreview-captures-one-reviewers-rubric-based-assessment-with-its-own-lifecycle`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `x-property-rbac.read` matches the fixed admin/reviewer-self shape exactly (not conditioned on
      `peerReviewAnonymity`)
- [x] 2.2 Add `SelfAssessment` to `lib/Settings/scholiq_register.json`: `assignmentId` ($ref `Assignment`),
  `submissionId` ($ref `Submission`), `learnerId` (string), `timing`
  (`before-submission|after-submission`), `rubricScores` (same shape as 2.1), `totalScore` (nullable
  number), `comments` (nullable string), `lifecycle` (`draft|submitted`, default `draft`), `tenant_id`.
  `x-openregister-lifecycle`: `submit` (`draft → submitted`, `requires:
  OCA\Scholiq\Lifecycle\RubricScoresCompletionGuard`). No `x-openregister-authorization.create` restriction
  (mirrors `Submission`). `x-property-rbac.read`: `anyOf: [{role: admin}, {match: {field: learnerId,
  operator: eq, value: $userId}}]`.
  - **spec_ref**: `specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `learnerId` documented as required to be one of the linked `Submission.learnerIds` (enforced by the
      guard in task 3.3, not JSON Schema)
- [x] 2.3 Add `PeerFeedbackSummary` to `lib/Settings/scholiq_register.json`: `x-openregister.readOnly: true`;
  `submissionId` ($ref `Submission`), `assignmentId` ($ref `Assignment`), `reviewCount` (nullable integer),
  `averageScore` (nullable number), `feedbackItems` (array of `{comments, rubricScores, reviewerId}`,
  `reviewerId` nullable), `lastComputedAt` (nullable date-time), `tenant_id`.
  `x-openregister-aggregations.reviewCount`: `from: peer-review`, `metric: count`, `where: {submissionId:
  "@self.submissionId", lifecycle: released}` (mirrors `FinalGrade.publishedEntries`,
  `lib/Settings/scholiq_register.json:5721` region). `x-openregister-triggers.calculatedChange.handler:
  OCA\Scholiq\Listener\PeerFeedbackAggregator`.
  - **spec_ref**: `specs/assignments/spec.md#requirement-reviewer-identity-is-hidden-from-the-submission-author-via-a-server-enforced-feedback-projection`
  - **acceptance_criteria**:
    - `x-openregister.readOnly: true` set; no lifecycle (fully derived, mirrors `FinalGrade`)
    - `reviewCount`'s aggregation `where` clause matches the confirmed `@self.<field>` syntax used elsewhere
    - No `"engine"` key is used anywhere in this schema (not a real keyword in this register at HEAD)

## 3. Backend — allocation, aggregation, guard

- [x] 3.1 Add `OCA\Scholiq\PeerReview\PeerReviewAllocationService` (SPDX docblock; `@spec` tag): resolves the
  reviewer pool as the `learnerIds` of all Submissions for a given `assignmentId`, excludes each
  Submission's own `learnerIds` from its candidate reviewers, and creates `PeerReview` rows (`assigned`) per
  `peerReviewAllocationStrategy` (`round-robin`: deterministic cyclic assignment ordered by `submittedAt`
  then object id; `random`: shuffled with the same exclusion rule; `manual`: no-op). Idempotent: only tops
  up Submissions short of `peerReviewersPerSubmission` non-`released`-excluded reviewers; never duplicates
  an existing (reviewer, submission) pair.
  - **spec_ref**: `specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies`
  - **acceptance_criteria**:
    - Unit tests cover: round-robin assigns exactly `peerReviewersPerSubmission` reviewers per Submission
      excluding self; random excludes self; manual creates nothing; re-running allocate() is a no-op once
      every Submission has its full complement; group-submission Submissions exclude every group member
- [x] 3.2 Add `OCA\Scholiq\Controller\PeerReviewController::allocate(string $assignmentId)`: explicit auth
  attribute, per-object authorization (caller is `admin` or holds write access to the Assignment's
  Course/Cohort — not a bare authenticated-user check), route in `appinfo/routes.php`. Delegates to
  `PeerReviewAllocationService`.
  - **spec_ref**: `specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies`
  - **acceptance_criteria**:
    - Unauthorized caller (not admin, no Course/Cohort write access) receives a 403
    - Route registered; controller method exists and matches the route target
- [x] 3.3 Add `OCA\Scholiq\Lifecycle\RubricScoresCompletionGuard` (SPDX): shared by `PeerReview.submit` and
  `SelfAssessment.submit`; blocks the transition unless `rubricScores` covers every `criterionId` in the
  linked Assignment's `Rubric`; for `SelfAssessment`, additionally blocks if `learnerId` is not one of the
  linked `Submission.learnerIds`.
  - **spec_ref**: `specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete`
  - **acceptance_criteria**:
    - Unit tests cover: incomplete rubric coverage blocked (both schemas); complete coverage allowed (both
      schemas); `SelfAssessment` with `learnerId` not in `Submission.learnerIds` blocked
- [x] 3.4 Add `OCA\Scholiq\Listener\PeerFeedbackAggregator` (SPDX; mirrors `GradeRollupHandler`'s shape):
  listens for `PeerReview` transitioning to `released`, recomputes the linked Submission's
  `PeerFeedbackSummary` — `reviewCount`, `averageScore` (mean of `released` `PeerReview.totalScore`), and
  `feedbackItems` (one per `released` `PeerReview`: `comments`, `rubricScores`, and `reviewerId` set to
  `null` when `Assignment.peerReviewAnonymity` is `blind`/`double-blind`, or the reviewer's identity when
  `open`). Registered in `lib/AppInfo/Application.php`.
  - **spec_ref**: `specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary`
  - **acceptance_criteria**:
    - Unit tests cover: `blind`/`double-blind` produce `reviewerId: null` in every `feedbackItems` entry;
      `open` populates `reviewerId`; `averageScore`/`reviewCount` recompute correctly as reviews release

## 4. Frontend

- [x] 4.1 Add `src/manifest.json` index/detail pages for `PeerReview`, `SelfAssessment`, and
  `PeerFeedbackSummary` (list/detail per the standard declarative pattern used by `assignments`' existing
  objects).
  - **spec_ref**: `specs/assignments/spec.md#requirement-frontend-is-declarative-with-named-custom-views`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added beyond the allocation endpoint
- [x] 4.2 Add `src/views/PeerReviewMarkingView.vue`: a reviewer scores their assigned `PeerReview` against
  the linked Assignment's Rubric and submits; when `Assignment.peerReviewAnonymity: double-blind`, the view
  MUST NOT display the Submission's learner identity anywhere. Strings via `t()`; data via the OpenRegister
  object API; any `NcSelect` carries `inputLabel`.
  - **spec_ref**: `specs/assignments/spec.md#scenario-double-blind-reviewee-identity-hiding-is-ui-level-only-and-this-is-documented`
  - **acceptance_criteria**:
    - Renders the Rubric's criteria/levels for scoring; submit blocked client-side until every criterion is
      scored (server-side enforced by `RubricScoresCompletionGuard` regardless)
    - No learner identity field rendered when `peerReviewAnonymity: double-blind`
- [x] 4.3 Add `src/views/SelfAssessmentView.vue`: a learner scores their own Submission against the linked
  Rubric, shown before or after submission per `Assignment.selfAssessmentTiming`.
  - **spec_ref**: `specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric`
  - **acceptance_criteria**:
    - Renders at the configured timing; submit transitions `SelfAssessment` to `submitted`
- [x] 4.4 Extend the existing `MarkSubmissionView.vue`: add a read-only `PeerFeedbackSummary`/
  `SelfAssessment` context panel; when `Assignment.peerReviewWeightPercent` is set, display a suggested
  blended score (`teacherScore × (1 − w) + averageScore × w`) as a hint, never pre-filling
  `Submission.proposedGrade`.
  - **spec_ref**: `specs/assignments/spec.md#scenario-a-configured-peer-review-weight-only-suggests-never-writes-a-blended-score`
  - **acceptance_criteria**:
    - Panel renders seeded `PeerFeedbackSummary`/`SelfAssessment` data
    - `Submission.proposedGrade` field is untouched by the suggested-score display

## 5. Tests and docs

- [x] 5.1 PHPUnit for `PeerReviewAllocationService`, `RubricScoresCompletionGuard`, `PeerFeedbackAggregator`,
  the extended `AssignmentPublishGuard`, and `PeerReviewController::allocate()` per the acceptance criteria
  in tasks 1.2 and 3.1–3.4 (minimum 75% coverage for new code per ADR-009).
  - **spec_ref**: all requirements added/modified in this change
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 5.2 Add `tests/e2e/spec-coverage/peer-and-self-assessment.spec.ts` (Playwright): teacher enables peer
  review + self-assessment on an Assignment and publishes it; allocation runs; a seeded reviewer opens
  `PeerReviewMarkingView` and submits; a seeded learner opens `SelfAssessmentView` and submits; the
  submission author opens their Submission detail and sees the `PeerFeedbackSummary` panel without a
  reviewer identity (blind mode).
  - **spec_ref**: `specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance
- [x] 5.3 Add Dutch and English translations for all new i18n keys (ADR-005), including the
  `PeerReview.assigned` notification subject.
  - **spec_ref**: all requirements added/modified in this change
  - **acceptance_criteria**:
    - No hardcoded strings; `nl`/`en` both populated

## 6. Verify

- [ ] 6.1 `openspec validate peer-and-self-assessment --strict` clean; PHPUnit green for all new/extended PHP
  classes; Playwright `peer-and-self-assessment.spec.ts` green; no dangling `$ref`s in the register JSON;
  re-verify the anonymity scenarios (blind/double-blind hide `reviewerId`; open reveals it; author cannot
  read a raw `PeerReview`) end-to-end against a seeded fixture.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; anonymity invariants re-verified end-to-end
