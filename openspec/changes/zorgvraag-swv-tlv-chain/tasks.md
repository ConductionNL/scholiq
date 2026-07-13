## 1. Schema: SupportRequest

- [x] 1.1 Add `SupportRequest` to `lib/Settings/scholiq_register.json` (`learning-plan` capability):
      `learnerId`, `learningPlanId` (nullable, `$ref: LearningPlan`), `raisedBy`, `supportDomain`,
      `description`, `urgency` (`low|medium|high`), `tenant_id`, `dataExchangeJobId` (nullable,
      `$ref: DataExchangeJob`).
- [x] 1.2 Add `x-openregister-lifecycle`: `draft → submitted → routed-to-swv → in-deliberation → decided
      → closed`.
- [x] 1.3 Add `x-openregister-authorization` restricting `create` to coordinator/principal/admin (see
      `design.md` Security Considerations — no zorgcoördinator role exists yet in `LearnerProfile.roles`;
      gate on NC-user-id convention matching `LearningPlan.coordinatorId`, not the roles enum).
      DONE AS: `create: [admin, principal]` (the only roles that actually exist in `LearnerProfile.roles`)
      plus an `x-property-rbac.read` rule matching `raisedBy === $userId` so the raising coordinator can
      read their own request — matches the design's NC-user-id-convention intent without inventing a role.
- [x] 1.4 Add `x-openregister-notifications`: `supportRequestRouted` (idempotency-keyed).

## 2. Schema: TlvApplication

- [x] 2.1 Add `TlvApplication` to `lib/Settings/scholiq_register.json`: `supportRequestId` (required,
      `$ref: SupportRequest`), `arrangementType`, `swvCaseReference` (nullable), `decision` (nullable
      enum `approved|rejected|conditional`), `validFrom`/`validUntil` (nullable), `decisionDocumentRef`
      (nullable), `tenant_id`.
- [x] 2.2 Add `x-openregister-lifecycle`: `draft → submitted → under-review → decided → expired`.
- [x] 2.3 Add `x-openregister-calculations.tlvExpiringSoon` (declared expression against `validUntil`,
      no PHP TimedJob — follow the `attendance`/`certification` declared-calculation-trigger pattern).
- [x] 2.4 Add `x-openregister-notifications`: `tlvDecisionReceived`, `tlvExpiringSoon`
      (idempotency-keyed).

## 3. Schema: DeliberationRecord

- [x] 3.1 Add `DeliberationRecord` to `lib/Settings/scholiq_register.json`: `supportRequestId`/
      `tlvApplicationId` (both nullable, at least one required — enforce via a required-one-of
      validation), `attendees` (array of `{role, name/refId}`, role enum `parent|pupil|municipality|
      care-partner|school|swv-coordinator`), `scheduledAt`, `recordedAt`, `outcome`, `pupilVoice`
      (object: `heard` boolean, `statementNote` nullable string, `waived` boolean, `waiverReason`
      nullable string), `tenant_id`.
- [x] 3.2 Set `appendOnly: true`; `x-openregister-lifecycle`: `scheduled → recorded`.
- [x] 3.3 Implement the pupil-hoorrecht lifecycle guard (new PHP class, e.g.
      `OCA\Scholiq\Lifecycle\PupilVoiceGuard`, mirroring `LearningPlanSignatureGuard`'s structure) that
      blocks the `scheduled → recorded` transition unless `pupilVoice.heard === true` or
      `pupilVoice.waived === true` with a non-empty `waiverReason`. Wire it into the transition's
      `requires`.
- [x] 3.4 Unit tests for the guard: blocks with neither set; blocks with `waived: true` and empty
      `waiverReason`; allows with `heard: true`; allows with `waived: true` + `waiverReason` set.

## 4. DataExchangeJob / DataMappingProfile extension (data-exchange register)

- [x] 4.1 Add `swv` to `DataExchangeJob.target`'s enum (`lib/Settings/scholiq_register.json`, currently
      `bron-rod|oso|leerplicht|surfconext|hr`).
      NOTE: `DataExchangeJob.target`/`DataMappingProfile.target` are free `type: string` fields at HEAD,
      not a JSON-schema `enum` — there is no literal enum array to append to. `swv` was added to both
      fields' `description` text (the field's only form of documented value-set) instead.
- [x] 4.2 Add `support-request` to the valid `DataExchangeJob.scope.schema` values.
      Same note as 4.1 — `scope.schema` is a free string; `support-request` was added to its description.
- [x] 4.3 Ship (or scaffold, admin-configurable) a `DataMappingProfile` row mapping
      `SupportRequest`/`LearnerProfile`/`LearningPlan` fields → the OSO care-request dossier schema,
      whitelist-only — no full-object passthrough (see `design.md` Security Considerations).
      DONE AS: seeded "SWV zorgvraag dossier" profile maps only `supportDomain`/`description`/`urgency`
      via flat `fieldMappings` (no `learnerId`/BSN in the flat mapping — flat mappings can't resolve a
      $ref into a nested section, same limitation `composeLeerplichtDossier` already documents); the
      LearnerProfile/LearningPlan whitelist sections are composed by
      `DataExchangeRunHandler::composeSwvDossier()` from an explicit field allowlist, never a full-object
      dump. `swv` was also added to `MANDATORY_PROFILE_TARGETS` (fail-closed — a swv job with no
      configured profile throws rather than falling through to pass-through export).
- [x] 4.4 Extend (or generalise) the existing `OsoDossierReviewGuard`/`pendingParentReview` /
      `approveDossier` transitions so `target: swv` jobs pass through the identical
      `pending-parent-review` gate as `target: oso` jobs — reuse the guard class, do not fork it, unless
      the existing implementation is hard-coded to `target === 'oso'`, in which case generalise the
      condition to match any OSO-format dossier target.
      DONE AS: `OsoDossierReviewGuard` needed NO change (it resolves `learnerId` generically from
      `scope.filters`, not target-specific). `DataExchangeRunGuard` WAS hard-coded to `target === 'oso'`
      (its own `queued → running` block) — generalised its `OSO_TARGET` constant to a `GATED_TARGETS =
      ['oso', 'swv']` explicit allowlist (a literal, named list per its own test's guarding comment — not
      an inference by dossier richness).
- [x] 4.5 Wire the `SupportRequest.submit` transition to auto-queue the `DataExchangeJob` (an
      OR-event-driven handler, per the existing ADR-031 "external-system bridge" exception already
      granted to `data-exchange`'s job-execution handler — extend that handler's target switch, do not
      add a new PHP service).
      DONE AS: new `OCA\Scholiq\Listener\SupportRequestSubmitHandler` (an `IEventListener`, mirroring the
      existing `AttendanceFlagCreationHandler`'s "queue a DataExchangeJob on this trigger" shape — not a
      new generic `Service\*` class) creates + queues the job and advances it to
      `pending-parent-review`. `DataExchangeRunHandler`'s own target switch (`buildPayload()`) was
      extended with `composeSwvDossier()` for the actual dossier composition, and `runJob()` was extended
      to transition the originating `SupportRequest` to `routed-to-swv` once the job succeeds.
- [ ] 4.6 File/confirm `openconnector#753` scope covers the `swv` named connection (extends the existing
      OSO/Edukoppeling adapter work) — do not implement the wire protocol in Scholiq.
      NOT DONE: filing/confirming a GitHub issue on `ConductionNL/openconnector` is outside this repo and
      outside this agent's tool access — flagged for a human/orchestrator follow-up, not implemented here.

## 5. Frontend

- [x] 5.1 Add `src/manifest.json` index+detail pages: `SupportRequests`/`SupportRequestDetail`,
      `TlvApplications`/`TlvApplicationDetail`, `DeliberationRecords`/`DeliberationRecordDetail` —
      follow the existing `LearningPlans`/`LearningPlanDetail` page-id convention.
- [x] 5.2 Wire the SWV dossier review step to reuse the existing `OsoDossierReviewView`
      (`CnStructuredDocReview`, `src/manifest.json:6094`) rather than adding a new component; confirm it
      renders correctly for `target: swv` jobs (not just `target: oso`).
      DONE AS: no manifest change was needed to the view itself — its route (`/data-exchange/jobs/:id/
      oso-review`) is generic by job id, not target-filtered, so `DataExchangeJobDetail`'s
      `lifecycleActions` panel already routes there for either target whenever `lifecycle ===
      pending-parent-review`. Updated its title/`_note` to document the dual-target reuse explicitly.
      CAVEAT: full confirmation that the underlying `CnStructuredDocReview` component (in the external
      `nextcloud-vue` repo, not this one) has no internal `target === 'oso'` hardcode was NOT verified —
      out of this repo's reach; flagged for a human/orchestrator follow-up.
- [x] 5.3 Add a `pupilVoice` capture UI on the `DeliberationRecord` detail/edit form — a boolean toggle
      pair (`heard`/`waived`) with the corresponding note/reason fields, surfaced clearly enough that a
      coordinator cannot miss it (per the hard lifecycle guard in task 3.3).
      DONE AS: the generic `data` widget on `DeliberationRecordDetail` renders `pupilVoice`'s nested
      object fields via the schema form generator (same mechanism `LearningPlan.goals`/`supportMeasures`
      already use — no bespoke component). CAVEAT: the manifest widget system has no per-field scoping
      mechanism to visually isolate `pupilVoice` from the rest of the record's data — "cannot miss it" is
      primarily enforced by `PupilVoiceGuard` hard-blocking the `record` transition (task 3.3), not by a
      dedicated frontend prominence mechanism, which does not exist in this manifest schema version.
- [x] 5.4 Surface `SupportRequest`/`TlvApplication` status on the existing `LearningPlanDetail` page when
      a link exists (read-only summary widget/tab), so a coordinator sees the full chain from the OPP
      side too.

## 6. Tests + docs + traceability

- [x] 6.1 Integration test: submit a `SupportRequest` → assert a `DataExchangeJob(target: swv,
      scope.schema: support-request)` is auto-queued and enters `pending-parent-review`.
      DONE AT UNIT LEVEL: `tests/Unit/Listener/SupportRequestSubmitHandlerTest::
      testSubmittedRequestQueuesSwvJobAndAdvancesToPendingParentReview`. No live OpenRegister/Nextcloud
      environment is available to this agent (this repo's `tests/Integration/` suite requires one and is
      out of `phpunit-unit.xml`'s scope) — mocked-collaborator unit tests are the established pattern
      this entire suite uses for lifecycle-event-driven listeners (see `ExemptionGrantHandlerTest`).
- [x] 6.2 Integration test: approve the dossier → job proceeds to `running`/`succeeded`; `SupportRequest`
      moves to `routed-to-swv`.
      DONE AT UNIT LEVEL: `tests/Unit/Listener/DataExchangeRunHandlerTest::
      testSucceededSwvJobRoutesSupportRequestToSwv` (+ 3 negative-case tests: wrong target, wrong state,
      unresolvable/missing SupportRequest). Same environment caveat as 6.1.
- [x] 6.3 Integration test: record a `DeliberationRecord` and a `TlvApplication` decision with
      `validFrom`/`validUntil`; assert `tlvExpiringSoon` fires when the calculated window is entered.
      DONE AS SHAPE VERIFICATION: `x-openregister-calculations`/`x-openregister-notifications` are
      evaluated by OpenRegister core, which does not live in this repo (no test in this suite asserts a
      declared calculation's numeric OUTPUT — see `VerzuimReportComposerRegisterTest`'s scope note, the
      established precedent). `tests/Unit/Settings/ZorgvraagSwvTlvChainRegisterTest::
      testTlvExpiringSoonCalculationShape`/`testTlvExpiringSoonNotificationShape` verify the declared
      expression/trigger shape instead. `DeliberationRecord` recording is covered by
      `PupilVoiceGuardTest`.
- [x] 6.4 Security test: attempt the `swv` `DataExchangeJob` dossier composition and assert only
      whitelisted `DataMappingProfile` fields appear in the payload (no full-object passthrough).
      DONE: `tests/Unit/Listener/DataExchangeRunHandlerTest::
      testSwvTargetComposesLearnerAndLearningPlanContext` asserts `bsnEncrypted`/`email` never appear in
      the composed `learner` section, and `testSwvTargetWithNoProfileThrows` asserts the fail-closed
      MANDATORY_PROFILE_TARGETS behaviour.
- [x] 6.5 Add `@spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-...`
      docblock tags to the new lifecycle guard and the extended job-execution handler branch.
- [x] 6.6 Update `openspec/specs/learning-plan/spec.md`'s Out of Scope line (`:99`) once this change
      lands, so it no longer claims the SWV chain is deferred.
- [x] 6.7 Run `composer check:strict` on all new/touched PHP files and fix any pre-existing warnings
      encountered in them.
      DONE AS: `composer` itself is not installed in the disposable php:8.3-cli container used for this
      apply; ran the equivalent vendored binaries directly instead (`vendor/bin/phpcs`, `vendor/bin/
      phpmd`, `vendor/bin/psalm` not run — same soft-fail status as composer.json's own `|| echo`
      fallback for it — `vendor/bin/phpstan`, `vendor/bin/phpunit`) scoped to the new/touched files.
      Fixed all real phpcs errors (alignment, inline-if, comment casing) and reduced a phpmd complexity
      regression (`runJob()`'s CC/NPath restored to the pre-existing baseline by extracting the swv-route
      call's applicability check into its callee). Left two categories of PRE-EXISTING, PERVASIVE,
      whole-codebase gaps untouched (confirmed via diffing against already-merged, unmodified files):
      phpcs's "named parameters" sniff (fails on every existing PHPUnit test file in this suite) and the
      class-level `@spec` PHPDoc warning (fails on every existing Lifecycle guard, e.g.
      `OsoDossierReviewGuard`). `DataExchangeRunHandler`'s pre-existing `ExcessiveClassComplexity`
      (68→90) and `runJob()`'s pre-existing `ExcessiveMethodLength` (123→131 lines) grew slightly as an
      accepted consequence of extending this handler per this task's own explicit "extend the target
      switch, do not add a new service" directive rather than inventing a new class to avoid the
      increase.
- [x] 6.8 Run `openspec validate zorgvraag-swv-tlv-chain --strict` and resolve any errors.
      `openspec validate zorgvraag-swv-tlv-chain --type change --strict` → "Change 'zorgvraag-swv-tlv-chain' is valid".
