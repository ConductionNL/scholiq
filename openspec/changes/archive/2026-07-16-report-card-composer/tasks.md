# Tasks: report-card-composer

## 1. Schema: ReportPeriod

- [x] 1.1 Add `ReportPeriod` schema to `lib/Settings/scholiq_register.json`: `name`, `academicYear`
      (string, `"YYYY-YYYY"`, matching `Cohort.academicYear`/`BsaTrajectory.academicYear` convention),
      `periodCode` (string — documented as matching `GradeEntry.period`/`CurriculumPlan.components[].period`),
      `startDate`/`endDate` (date), `curriculumPlanIds[]` (`$ref: CurriculumPlan`), `cohortIds[]`
      (`$ref: Cohort`, same shape as `ConferenceRound.cohortIds[]`), `lockDate` (date-time, nullable),
      `attendanceIncluded` (boolean, default `true`), `tenant_id`, `lifecycle` (`open → composed →
      archived`). English `title`/`description` on every property per house convention.
- [x] 1.2 Add `x-openregister-lifecycle` transitions: `compose` (`open → composed`, `requires:
      ReportPeriodComposeGuard`), `archive` (`composed → archived`).
- [x] 1.3 Add `x-openregister-calculations.isLocked` (`materialise: true`): `lockDate` set AND has passed
      `@now`, mirroring `ConferenceRound.isBookingClosed`'s `lt`/`now` JSON-logic shape
      (`lib/Settings/scholiq_register.json:11234-11249`).
- [x] 1.4 Add `x-openregister-notifications.lockDatePassed`: `scheduled` trigger, `intervalSec: 86400`,
      `filter: {lifecycle: "open", lockDate: {operator: "olderThan", value: "PT0S"}}`, recipients
      `kind: "groups", groups: ["mentor", "coordinator"]`, NL/EN subject — mirrors
      `ConferenceRound.bookingAutoClosed` exactly (`lib/Settings/scholiq_register.json:11272-11300`).
      **Confirm at implementation time** this trigger only ever fires the reminder and never
      auto-transitions `lifecycle` (verified precedent: `ConferenceRound.isBookingClosed`'s own
      description states scheduled triggers cannot drive a transition).
- [x] 1.5 Add `x-openregister-authorization.create: ["admin", "mentor", "principal"]` and
      `x-property-rbac.read` restricted to the same roles (staff-only; `ReportPeriod` carries no
      learner/parent-readable content) — tightest-posture pattern, no fabricated "coordinator" role
      (mirrors `SupportRequest`'s documented rationale).

## 2. Schema: ReportCard

- [x] 2.1 Add `ReportCard` schema: `learnerId`/`learnerRef` (dual-identifier convention, mirrors
      `GradeEntry`), `reportPeriodId` (`$ref: ReportPeriod`, required), `cohortId` (nullable, `$ref: Cohort`),
      `subjectGrades[]` (array of `{curriculumPlanId: uuid $ref CurriculumPlan, courseId: uuid|null $ref
      Course, periodAverage: number|null, passed: boolean|null, teacherComment: string|null,
      sourceGradeEntryIds: uuid[] $ref GradeEntry}`), `attendanceSummary` (object:
      `{presentCount, absentUnexcusedCount, absentExcusedCount, lateCount, leftEarlyCount, attendancePercent:
      number|null}`, all integers except the percent), `mentorComment` (string, nullable),
      `competencyAttainment` (array/object, nullable — forward-compatible placeholder only, no schema
      shape assumed beyond "nullable"), `composedAt` (date-time, nullable), `docudeskRenderStatus`
      (enum `requested|rendered|failed`, nullable), `docudeskRequestedAt` (date-time, nullable),
      `docudeskDocumentRef` (string, nullable), `docudeskRenderError` (string, nullable), `tenant_id`,
      `lifecycle` (`draft → rapportvergadering-review → finalised → published-to-parents`, plus `reopen`
      back to `rapportvergadering-review`).
- [x] 2.2 Add `x-openregister-lifecycle` transitions: `pullIntoReview` (`draft → rapportvergadering-review`,
      no guard), `finalise` (`rapportvergadering-review → finalised`, `requires:
      ReportCardFinaliseGuard`), `reopen` (`finalised → rapportvergadering-review`, `requires:
      ReportCardReopenGuard`), `publishToParents` (`finalised → published-to-parents`, `requires:
      ReportCardVisibilityGuard`, `notifications: [reportCardPublished]`), `recompose` (`draft → draft`
      self-loop, `requires: ReportCardComposer` — **flag at implementation time**: whether OR's lifecycle
      engine permits a `from == to` self-loop transition is unverified in this repo, same open question
      `MunicipalityFeedbackGuard` already flagged for `DataExchangeJob.recordMunicipalityFeedback|`
      confirm against a live OpenRegister instance), `renderToPdf` (`finalised → finalised`, `requires:
      ReportCardPdfDelegationService`), `rerenderToPdf` (`published-to-parents → published-to-parents`,
      same `requires`).
- [x] 2.3 Add `x-openregister-notifications.reportCardPublished`: `transition` trigger,
      `action: "publishToParents"`, recipients `kind: "field", field: "learnerId"`, NL/EN subject —
      mirrors `ConferenceRound.invitationsSent`'s `transition`-trigger shape
      (`lib/Settings/scholiq_register.json:11252-11271`).
- [x] 2.4 Add `x-property-rbac.read`: `anyOf` `admin`/`mentor`/`principal` (staff) OR
      `learnerId == $userId` self-match, mirroring `GradeEntry`/`FinalGrade`'s existing shape. Add
      `x-openregister-authorization.create: ["admin", "mentor", "principal"]` (composer-created only in
      practice; declarative create restriction is still required per the tightest-posture pattern).

## 3. Schema: ReportCardParentNotification

- [x] 3.1 Add `ReportCardParentNotification` schema, structurally identical to `GradeNotification`
      (`lib/Settings/scholiq_register.json:10149-10260`): `appendOnly: true`,
      `{event: enum["reportCardPublished"], recipient: string, sourceId: uuid $ref ReportCard,
      learnerId: string, learnerRef: uuid|null $ref LearnerProfile, idempotencyKey: string
      (format "{sourceId}-parent-{recipient}"), visibleFrom: date-time|null, tenant_id}`. Add its own
      `x-openregister-notifications.reportCardPublished`: `scheduled` trigger, `intervalSec: 300`,
      `filter: {visibleFrom: {operator: "olderThan", value: "PT0S"}}`, recipients
      `kind: "field", field: "recipient"`, NL/EN subject — identical mechanism to `GradeNotification`'s.

## 4. Lifecycle guards

- [x] 4.1 `lib/Lifecycle/ReportPeriodComposeGuard.php` — blocks `compose` unless `isLocked` is `true`.
      Mirrors the `check(array &$transitionContext): bool` shape (`AttendanceFlagReportGuard`).
- [x] 4.2 `lib/Lifecycle/ReportPeriodLockGuard.php` — **DEVIATION FROM PLAN, documented in the class
      docblock**: verified at implementation time that OR's `LifecycleAnnotationValidator::validate()`
      requires `requires` to be a single non-empty string (`lifecycle-requires-malformed` otherwise) — a
      "second `requires` array entry" is not a supported shape (the design.md `Credential.revoke` precedent
      does not actually exist as an array either: `requires` there is a single string). Instead
      `ReportPeriodLockGuard` REPLACES `FraudCaseBlockGuard` as `GradeEntry.publish`/`republish`'s sole
      `requires` value and COMPOSES `FraudCaseBlockGuard` internally (constructor-injected, called first,
      unchanged behaviour byte-for-byte) — verified this does NOT regress the fraud-appeal guarantee
      (`ReportPeriodLockGuardTest::testFraudCaseBlockGuardShortCircuitsAndStaysBlocked`). Resolves matching
      `ReportPeriod` (via `ObjectService::findAll`, filtering `curriculumPlanIds` containment + `periodCode`
      + `tenant_id` — `GradeEntry` carries no `academicYear`, see class docblock), fails open when none
      match, blocks when matched + locked unless the acting user holds `admin`/`mentor`/`principal`.
- [x] 4.3 `lib/Lifecycle/ReportCardFinaliseGuard.php` — blocks `finalise` unless `mentorComment` is
      non-empty and `subjectGrades[]` is non-empty.
- [x] 4.4 `lib/Lifecycle/ReportCardReopenGuard.php` — blocks `reopen` unless the acting user holds
      `admin`/`mentor`/`principal`.
- [x] 4.5 `lib/Lifecycle/ReportCardVisibilityGuard.php` — resolves every `subjectGrades[].
      sourceGradeEntryIds[]` entry's current `GradeEntry.visibleFrom` (batch `ObjectService::findAll`),
      blocks `publishToParents` unless every resolved `visibleFrom` has passed `@now`; error message names
      the first subject still withheld.

## 5. Composer + publish-handler listeners

- [x] 5.1 `lib/Listener/ReportCardComposer.php` — OR-event-driven `Listener` (ADR-031 cross-object write
      bridge, mirrors `ConferenceScheduleGenerator`'s registration shape), triggered by
      `ObjectTransitionedEvent` where `schema=report-period, transition=compose`. For every learner in
      `cohortIds[] → Cohort.learnerIds`, for every `curriculumPlanId` in `curriculumPlanIds[]`, resolves
      the learner's `FinalGrade` (filter `learnerId`+`curriculumPlanId`), reads
      `breakdown.periods[periodCode]` and `passed`, resolves the contributing published `GradeEntry` ids
      for that (learner, curriculumPlanId, period) (query `grade-entry` filtered `learnerId`+
      `curriculumPlanId`+`period`+`lifecycle=published`) into `sourceGradeEntryIds[]`, and (when
      `attendanceIncluded`) aggregates `AttendanceRecord` within `[startDate, endDate]` into
      `attendanceSummary`. Writes one `draft` `ReportCard` per learner via `ObjectService`. No matching
      period-component for a subject → skip that `subjectGrades[]` row, not an error (per report-card
      spec's own scenario).
- [x] 5.2 `ReportCardComposer` also handles the `recompose` self-loop (single-learner variant of the same
      logic, overwriting `subjectGrades[]`/`attendanceSummary` on the existing `draft` `ReportCard`).
      Register both hooks in `lib/AppInfo/Application.php` alongside `GradeRollupHandler`/
      `ConferenceScheduleGenerator`.
- [x] 5.3 `lib/Listener/ReportCardPublishHandler.php` — listens for `ObjectTransitionedEvent` where
      `schema=report-card, transition=publishToParents`; resolves the learner's `LearnerProfile.
      parentIds[]` and creates one `ReportCardParentNotification` per parent, stamping
      `visibleFrom = now()` and `idempotencyKey = "{reportCardId}-parent-{recipient}"`. Mirrors
      `GradeRollupHandler::fanOutParentNotifications`'s reasoning and shape exactly.

## 6. docudesk PDF delegation (fail-soft, contract proposed)

- [x] 6.1 `lib/Service/ReportCardPdfDelegationService.php` — `check(array &$transitionContext): bool`,
      **always returns `true`** (fail-soft, mirrors `WalletRevocationPropagationService`). POSTs to a
      **proposed** `POST /apps/docudesk/api/v1/documents/render` contract (report-card UUID,
      `subjectGrades[]`, `mentorComment`, `attendanceSummary`, template slug) using the
      `IClientService` + `IURLGenerator` + `IAppConfig` bearer-token seam
      (`scholiq.docudesk_api_token`, mirroring `scholiq.openconnector_api_token`) established by
      `DataExchangeRunHandler::callOpenConnector()`. On 2xx: sets `docudeskRenderStatus=rendered`,
      `docudeskDocumentRef`, clears `docudeskRenderError`. On failure/absence/throw: catches, logs,
      sets `docudeskRenderStatus=failed` + `docudeskRenderError`, still returns `true`.
- [ ] 6.2 **STILL OUTSTANDING** — file a follow-up issue against docudesk (or the umbrella tracking
      process this repo uses) for the docudesk-side `/apps/docudesk/api/v1/documents/render` endpoint
      implementation — this change does not build it, mirroring the `bpv-praktijkovereenkomst` POK-PDF
      precedent. NOT filed by this apply pass — this agent has no git/issue-tracker write access (git is
      orchestrator-owned per this session's rules); flagged here for the orchestrator/human to file.

## 7. Portal exposure

- [x] 7.1 `lib/Portal/PortalContributionProvider.php` — add `parentReportCards` to
      `parentContribution()`'s `collections[]`: `register: self::REGISTER, schema: 'report-card',
      scopeField: 'learnerRef', scopeClaim: 'guardianRef', via: $childJoin` (the same `childJoin` the
      three existing collections already share), `label: "My child's report cards"`, `listable: true`,
      `minTrust: 'substantial'`, `fields: [learnerRef, reportPeriodId, subjectGrades, attendanceSummary,
      mentorComment, docudeskDocumentRef]`. **Confirmed at implementation time**: `portaliq/lib/Controller/
      ContributionController.php::collection()` reads `$collection['filter']` (singular, NOT `filters`),
      applied by `PortalObjectReader::readCollection()` before the scope filter — used `'filter' =>
      ['lifecycle' => 'published-to-parents']`, matching `pipelinq`'s own established `filter` usage.
- [x] 7.2 Update `tests/Unit/Portal/PortalContributionProviderTest.php` — add `parentReportCards`
      assertions (collection present, correct `via`/`scopeField`/`minTrust`, field allowlist), mirroring
      the existing `parentGrades`/`parentAttendance` test shape.

## 8. Grading spec delta application

- [x] 8.1 Apply the `specs/grading/spec.md` delta in this change to `lib/Settings/scholiq_register.json`:
      swap `GradeEntry.publish`/`republish`'s `requires` from `FraudCaseBlockGuard` to
      `ReportPeriodLockGuard` (single string, per 4.2's documented deviation — **confirmed** OR's
      `LifecycleAnnotationValidator::validate()` rejects a non-string `requires`; the `Credential.revoke`
      "second requires entry" precedent does not exist as an array at HEAD either — `Credential.revoke`
      has exactly one `requires` guard).
- [x] 8.2 Bump `lib/Settings/scholiq_register.json`'s `info.version` to `0.11.0` (was `0.10.0` at HEAD when
      this change started, not `0.7.0` as tasks.md originally assumed — 4 wave-2 changes landed first;
      confirmed the running number, not overwritten).

## 9. Frontend

- [x] 9.1 Add `src/manifest.json` index+detail pages for `ReportPeriod` and `ReportCard`, following the
      existing generic `type: "data"` widget convention (renders the full property/calculation set
      generically — no `content.fields` allowlist needed, per the established precedent that array-of-
      object fields like `DataMappingProfile.fieldMappings` already render this way).
- [x] 9.2 `src/dialogs/ComposeReportPeriodModal.vue` (NcDialog per ADR-004) — confirms scope
      (`curriculumPlanIds`/`cohortIds` summary) and `isLocked` state before triggering `compose`; blocks
      with an explanatory message if not yet locked. Opened by RapportvergaderingReviewView (props/emits,
      not its own route) — mirrors procest's own `src/dialogs/*.vue` shape.
- [x] 9.3 `src/views/RapportvergaderingReviewView.vue` — the cohort-wide grid used during the review
      meeting: one row per learner, one column per subject showing `periodAverage`/`passed`, inline
      `teacherComment`/`mentorComment` editing, and `finalise`/`reopen`/`publishToParents` lifecycle
      actions. Mirrors `GradebookView`'s existing precedent for "a manifest page can't render a cohort
      grid."
- [ ] 9.4 **N/A — design deviation, documented here**: `ComposeReportPeriodModal` confirms an EXISTING
      `ReportPeriod`'s already-declared scope (read-only summary of its `curriculumPlanIds`/`cohortIds`
      counts) rather than letting the user pick subjects/cohorts — scope selection happens when CREATING
      the `ReportPeriod` object itself, via the generic manifest create-form (no custom UI needed there).
      No `NcSelect` is used anywhere in this change's frontend; task left unchecked as genuinely
      inapplicable rather than falsely marked done.

## 10. Tests, l10n, verify

- [x] 10.1 `tests/Unit/Lifecycle/ReportPeriodComposeGuardTest.php`,
      `ReportPeriodLockGuardTest.php`, `ReportCardFinaliseGuardTest.php`, `ReportCardReopenGuardTest.php`,
      `ReportCardVisibilityGuardTest.php` — unit-test each guard's `check()` directly (same scope
      boundary as every other guard test in this suite — OR core's actual transition dispatch is not
      exercisable from this repo).
- [x] 10.2 `tests/Unit/Listener/ReportCardComposerTest.php` — composition logic (subject-row population,
      missing-period-component skip, attendance aggregation), `ReportCardPublishHandlerTest.php`
      (parent fan-out count + idempotency key shape).
- [x] 10.3 `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php` — fail-soft behaviour (always
      returns `true`), success/failure field writes, request-body shape assertion against the proposed
      contract.
- [x] 10.4 Register-JSON assertion tests (`ReportCardComposerRegisterTest.php` or similar, mirroring
      `VerzuimReportComposerRegisterTest`'s established pattern) — assert declared shapes: `ReportPeriod.
      isLocked` calculation, `ReportCard`/`ReportCardParentNotification` lifecycle + notification blocks.
- [x] 10.5 `tests/e2e/spec-coverage/report-card.spec.ts` — Playwright coverage for every scenario in
      `specs/report-card/spec.md` and `specs/grading/spec.md` carrying a `tests/e2e/spec-coverage/
      report-card.spec.ts` `@e2e` ref (compose flow, lock-date gating, rapportvergadering review grid,
      finalise/reopen, publish-visibility gating). Every data-dependent scenario uses the established
      "discover a real object via the OR API or `test.skip`" convention (`ReportPeriod`/`ReportCard` both
      declare `x-openregister-seed: []`, so most will skip on a freshly-seeded instance — not run against a
      live seeded/composed dataset by this apply pass).
- [x] 10.6 Add `l10n/nl.json` entries for every new UI string (`ComposeReportPeriodModal`,
      `RapportvergaderingReviewView`) — `l10n/en.json` gained the same 34 keys (identity-mapped, the source
      locale). **Confirmed**: `node tests/l10n/check-l10n-parity.js` shows `nl` at full parity (0
      missing/empty) both before and after this change — the other ~30 required locales already carried a
      pre-existing, unrelated 194-key gap at baseline (not touched/regressed by this change; out of scope
      per the literal task wording, which only asks for `nl.json`).
- [x] 10.7 Add `@spec` tags to every new/touched PHP class + its public methods (SPDX docblock already
      present on every new file). **Deviation from the literal task wording**: tags point at
      `openspec/changes/report-card-composer/specs/{report-card,grading}/spec.md#scenario-...`/
      `#requirement-...` anchors, NOT `tasks.md#task-N` — matching every other `@spec` tag already in this
      codebase (`FraudCaseBlockGuardTest`, `GradeRollupHandler`, etc. all anchor to a spec.md
      requirement/scenario, never to `tasks.md`); a `tasks.md#task-N` anchor would evaporate on archive per
      this worktree's own `@spec` convention (must target canonical `openspec/specs/`).
- [x] 10.8 Ran `phpstan analyse` (full-project, zero new errors — the 26 pre-existing errors are all
      OpenRegister AppHost stub/`ActionAuthService` gaps unrelated to any touched file) + `phpcs
      --standard=phpcs.xml` scoped to every touched PHP file (phpcbf auto-fixed 57 style violations; 8
      remaining manually fixed: 2 long-line log calls, 5 disallowed inline-`?:` ternaries rewritten as
      if/else, 1 missing class-level `@spec` tag) — clean. Full suite: `docker run ... phpunit -c
      phpunit-unit.xml` → **Tests: 455, Assertions: 2135, PHPUnit Warnings: 1** (baseline 412/1964/1, all
      +43 new tests green, zero regressions). `npm run build` succeeds (only the pre-existing, app-wide
      asset-size-limit webpack warnings). `npx eslint` clean (0 errors) on every new/touched frontend file.
- [x] 10.9 `openspec validate report-card-composer --type change --strict` → `Change 'report-card-composer'
      is valid`.
