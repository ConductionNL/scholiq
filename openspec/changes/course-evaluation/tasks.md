# Tasks: course-evaluation

## 1. Schema — course-evaluation capability

- [ ] 1.1 Add `EvaluationCampaign` to `lib/Settings/scholiq_register.json`: `name`, `courseIds[]`
  (`$ref` `Course`), `cohortIds[]` (`$ref` `Cohort`, at least one of `courseIds`/`cohortIds` populated —
  documented in `description`, not a JSON-Schema conditional, matching the register's existing convention
  of documenting cross-field constraints rather than encoding them via `if`/`then`), `academicYear`/
  `period` (same shape as `Cohort.academicYear`/`period`), `instrumentKind` (`built-in`|`external-form`,
  default `built-in`), `questions[]` (`{questionId, text: {nl, en}, kind: likert-5|free-text, required}`,
  only meaningful when `instrumentKind: built-in`), `externalFormUrl` (nullable, `format: uri`, only
  meaningful when `instrumentKind: external-form`), `anonymityPolicy` (fixed enum `fully-anonymous`,
  documentation field), `reminderSchedule` (`{enabled, leadDays}`, `leadDays` currently informational —
  see design.md Caveats), `lifecycle` (`draft → open → closed → archived`), `tenant_id`.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-a-campaign-scopes-its-courses-cohorts-academic-period-and-instrument`
  - **acceptance_criteria**:
    - Schema validates against the register's existing OpenAPI 3.0.0 conventions
    - `instrumentKind` enum and the `questions[]`/`externalFormUrl` shape match design.md Decision 1
- [ ] 1.2 Add `EvaluationInvitation` to `lib/Settings/scholiq_register.json`: `campaignId` (`$ref`
  `EvaluationCampaign`), `courseId` (`$ref` `Course`), `cohortId` (nullable `$ref` `Cohort`), `learnerId`
  (NC user id string), `hasResponded` (boolean, default `false`), `respondedAt` (nullable date-time),
  `campaignClosesAt` (date-time, denormalised copy of the campaign's close date), `tenant_id`.
  `x-openregister-notifications.reminder`: `trigger.type: scheduled`, `filter: {hasResponded: false,
  campaignClosesAt: {operator: withinNext, value: P5D}}`, `channels: [nc-notification]`,
  `recipients: [{kind: field, field: learnerId}]`, NL/EN `subject` — mirrors
  `Enrolment.dueReminder` (`lib/Settings/scholiq_register.json:1736-1761`) exactly.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-non-responder-reminders-reuse-the-verified-notification-dialects-scheduledfilter-shape`
  - **acceptance_criteria**:
    - `reminder` rule uses only verified-dialect keys (per the `scholiq-notifications` spec)
    - `recipients` resolves via `learnerId`, matching the `kind:field` requirement
- [ ] 1.3 Add `CourseEvaluationResponse` to `lib/Settings/scholiq_register.json` (`appendOnly: true`):
  `campaignId` (`$ref` `EvaluationCampaign`), `courseId` (`$ref` `Course`), `cohortId` (nullable `$ref`
  `Cohort`), `teacherId` (nullable, NC user id string), `overallScore` (nullable number, 1–5),
  `answers[]` (`{questionId, ratingValue (nullable 1-5), textValue (nullable string)}`), `submittedAt`
  (date-time), `tenant_id`. **MUST NOT** declare any `learnerId`/`submittedBy`/identity-bearing property.
  `x-openregister-lifecycle`: `draft → submitted`, `submitted` transition `requires:
  OCA\Scholiq\Lifecycle\CourseEvaluationEligibilityGuard`.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac`
  - **acceptance_criteria**:
    - `appendOnly: true`
    - No property on this schema references a learner identity (verified by full-schema grep for
      `learnerId|submittedBy|ncUserId|userId` returning zero hits within this schema's `properties` block)
    - `submitted` transition declares `requires: CourseEvaluationEligibilityGuard`
- [ ] 1.4 Add `CourseQualityScore` to `lib/Settings/scholiq_register.json`: `courseId` (`$ref` `Course`),
  `teacherId` (nullable, NC user id string), `academicYear`, `period`, `responseCount`, `invitationCount`,
  `averageOverallScore` (nullable), `responseRate` (nullable), `lastRecomputedAt`, `tenant_id`.
  `x-openregister-aggregations`: `responseCount` (`count`, `from: course-evaluation-response`, `where:
  {courseId: @self.courseId, academicYear: @self.academicYear, period: @self.period,
  lifecycle: submitted}`), `invitationCount` (`count`, `from: evaluation-invitation`, `where:
  {courseId: @self.courseId}`) — mirrors `FinalGrade.publishedEntries`
  (`lib/Settings/scholiq_register.json:5830-5841`). `x-openregister-calculations.averageOverallScore` and
  `.responseRate`: `engine`-keyed, `CourseQualityScoreEvaluator`.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-coursesteacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob`
  - **acceptance_criteria**:
    - `responseCount`/`invitationCount` use the register's proven `count` metric (no `avg`/`sum` in the
      declarative dialect)
    - `averageOverallScore`/`responseRate` are `engine`-keyed calculations
- [ ] 1.5 Add `ImprovementAction` to `lib/Settings/scholiq_register.json`: `campaignId` (`$ref`
  `EvaluationCampaign`), `courseId` (`$ref` `Course`), `reviewedBy` (NC user id string), `reviewedAt`
  (date-time), `findings` (text), `actionDescription` (text), `targetPeriod` (string), `status`
  (`planned`|`in-progress`|`done`|`dropped`), `tenant_id`. `x-openregister-lifecycle`:
  `planned → in-progress → done | dropped`. No PHP guard.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-the-evaluation-cycle-closes-the-loop-with-a-recorded-improvement-action`
  - **acceptance_criteria**:
    - Schema validates; lifecycle transitions require no PHP class

## 2. Backend — guard, listeners, calculation engine

- [ ] 2.1 Add `OCA\Scholiq\Listener\EvaluationInvitationProvisioningHandler` (SPDX; `@spec` tag): listens
  for `EvaluationCampaign`'s `open` transition and creates one `EvaluationInvitation` per learner in scope
  (resolved from `courseIds`/`cohortIds` via the referenced `Cohort.learnerIds`), stamping
  `campaignClosesAt` from the campaign's close-date field. Idempotency-keyed so re-opening or a duplicate
  event does not create duplicate invitations for the same `(campaignId, learnerId)`.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Unit tests cover: one invitation per learner across a multi-cohort campaign; no duplicate invitation
      on a repeated `open` event
- [ ] 2.2 Add `OCA\Scholiq\Lifecycle\CourseEvaluationEligibilityGuard` (SPDX; `@spec` tag; mirrors
  `ConferenceSignupGuardianGuard`): on `CourseEvaluationResponse`'s `draft → submitted` transition,
  resolves the caller via `IUserSession`, queries `EvaluationInvitation` via `ObjectService::findAll()`
  for `(campaignId, learnerId: callerUid)`, and blocks the transition unless exactly one matching
  invitation exists with `hasResponded: false`. Never reads or writes any identity field onto the
  `CourseEvaluationResponse` object itself.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard`
  - **acceptance_criteria**:
    - Unit tests cover: no invitation blocks submit; already-responded invitation blocks submit; eligible
      invitation allows submit; the guard never mutates the `CourseEvaluationResponse` payload it receives
- [ ] 2.3 Add `OCA\Scholiq\Listener\CourseEvaluationResponseSubmittedHandler` (SPDX; `@spec` tag; mirrors
  `GradeRollupHandler`): listens for `CourseEvaluationResponse`'s `submit` transition, re-resolves the
  caller via `IUserSession`, finds that learner's `EvaluationInvitation` for the same `campaignId`, and
  updates it to `hasResponded: true`, `respondedAt: now`. Does not add any field to `EvaluationInvitation`
  referencing the response's identity or content.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-a-successful-submission-flips-the-invitation-without-linking-to-the-response`
  - **acceptance_criteria**:
    - Unit tests cover: submitting flips only the caller's own invitation; a second learner's invitation
      for the same campaign is untouched; the updated invitation gains no response-referencing field
- [ ] 2.4 Add `OCA\Scholiq\CourseEvaluation\CourseQualityScoreEvaluator` (SPDX; `@spec` tag): given a
  `(courseId, teacherId, academicYear, period)` scope, resolves the matching `submitted`
  `CourseEvaluationResponse`s and `EvaluationInvitation`s (via `x-openregister-aggregations`-declared
  counts plus a direct query for the response set), computes `averageOverallScore` (mean of
  `overallScore`, ignoring `null`) and `responseRate` (`responseCount / invitationCount`, `0` when
  `invitationCount` is `0`). Wired as the `x-openregister-calculations`/`engine` implementation declared
  in task 1.4.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-coursesteacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob`
  - **acceptance_criteria**:
    - Unit tests cover: average recomputes correctly across multiple responses; a response with `null`
      `overallScore` does not skew the average incorrectly; `responseRate` divides by `invitationCount`;
      zero invitations returns `responseRate: 0`, not a division error
- [ ] 2.5 Add `OCA\Scholiq\Listener\CourseQualityScoreRollupHandler` (SPDX; `@spec` tag; mirrors
  `GradeRollupHandler`'s find-or-create shape): listens for `CourseEvaluationResponse`'s `submit`
  transition and find-or-creates the matching `CourseQualityScore` row for
  `(courseId, teacherId, academicYear, period)`, invoking `CourseQualityScoreEvaluator` and stamping
  `lastRecomputedAt`.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-coursesteacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob`
  - **acceptance_criteria**:
    - Unit tests cover: first response for a course/period creates the `CourseQualityScore` row; a
      subsequent response updates the existing row rather than creating a duplicate

## 3. Frontend

- [ ] 3.1 Add `src/manifest.json` index/detail pages for `EvaluationCampaign`, `CourseEvaluationResponse`
  (read-only list/detail, respecting `appendOnly` — no edit/delete actions exposed), and
  `ImprovementAction` (list/create/edit/detail per the standard declarative pattern used elsewhere in the
  manifest).
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-quality-report`
  - **acceptance_criteria**:
    - Pages render seeded objects; `CourseEvaluationResponse`'s detail page shows no edit/delete action
    - No PHP CRUD controller added
- [ ] 3.2 Add `src/views/CourseQualityReport.vue`: given a `courseId` (and optional `teacherId`), lists
  the course's `CourseQualityScore` rows across periods as a trend (score, response rate), lists raw
  `CourseEvaluationResponse.answers[].textValue` free-text entries, and links to draft a new
  `ImprovementAction` referencing the campaign. Strings via `t()`, data via the OpenRegister object API (no
  DOM reads); any `NcSelect` carries `inputLabel`. Add a manifest menu entry.
  - **spec_ref**: `specs/course-evaluation/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-quality-report`
  - **acceptance_criteria**:
    - Report renders seeded `CourseQualityScore`/`CourseEvaluationResponse` data; empty state shown when
      none exist
    - "Draft improvement action" link navigates to a new `ImprovementAction` pre-filled with the
      `campaignId`/`courseId`

## 4. Tests and docs

- [ ] 4.1 PHPUnit for `EvaluationInvitationProvisioningHandler`, `CourseEvaluationEligibilityGuard`,
  `CourseEvaluationResponseSubmittedHandler`, `CourseQualityScoreEvaluator`,
  `CourseQualityScoreRollupHandler` per the acceptance criteria in tasks 2.1–2.5 (minimum 75% coverage for
  new code per ADR-009).
  - **spec_ref**: all `course-evaluation` requirements
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 4.2 Add `tests/e2e/spec-coverage/course-evaluation.spec.ts` (Playwright): a coordinator opens the
  course quality report and sees a seeded score trend, then navigates to draft an `ImprovementAction`;
  covers the manifest CRUD flow for `ImprovementAction` itself.
  - **spec_ref**: `specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend`,
    `specs/course-evaluation/spec.md#scenario-a-reviewer-records-an-improvement-action-against-a-campaigns-results`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches the `@e2e` reference in both spec scenarios
- [ ] 4.3 Add Dutch and English translations for all new i18n keys (ADR-005).
  - **spec_ref**: all `course-evaluation` requirements
  - **acceptance_criteria**:
    - No hardcoded strings; `nl`/`en` both populated for the `reminder` notification subject and
      `CourseQualityReport.vue`'s UI strings

## 5. Verify

- [ ] 5.1 `openspec validate course-evaluation --strict` clean; PHPUnit green for all five new PHP classes;
  Playwright `course-evaluation.spec.ts` green; no dangling `$ref`s in the register JSON; the anonymity
  invariant re-verified against seeded fixtures (a submitted `CourseEvaluationResponse` payload contains no
  learner-identifying field; a second submission attempt from the same learner is refused).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; anonymity + eligibility invariants re-verified end-to-end
