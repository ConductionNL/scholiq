## 1. Schema: SupportRequest

- [ ] 1.1 Add `SupportRequest` to `lib/Settings/scholiq_register.json` (`learning-plan` capability):
      `learnerId`, `learningPlanId` (nullable, `$ref: LearningPlan`), `raisedBy`, `supportDomain`,
      `description`, `urgency` (`low|medium|high`), `tenant_id`, `dataExchangeJobId` (nullable,
      `$ref: DataExchangeJob`).
- [ ] 1.2 Add `x-openregister-lifecycle`: `draft → submitted → routed-to-swv → in-deliberation → decided
      → closed`.
- [ ] 1.3 Add `x-openregister-authorization` restricting `create` to coordinator/principal/admin (see
      `design.md` Security Considerations — no zorgcoördinator role exists yet in `LearnerProfile.roles`;
      gate on NC-user-id convention matching `LearningPlan.coordinatorId`, not the roles enum).
- [ ] 1.4 Add `x-openregister-notifications`: `supportRequestRouted` (idempotency-keyed).

## 2. Schema: TlvApplication

- [ ] 2.1 Add `TlvApplication` to `lib/Settings/scholiq_register.json`: `supportRequestId` (required,
      `$ref: SupportRequest`), `arrangementType`, `swvCaseReference` (nullable), `decision` (nullable
      enum `approved|rejected|conditional`), `validFrom`/`validUntil` (nullable), `decisionDocumentRef`
      (nullable), `tenant_id`.
- [ ] 2.2 Add `x-openregister-lifecycle`: `draft → submitted → under-review → decided → expired`.
- [ ] 2.3 Add `x-openregister-calculations.tlvExpiringSoon` (declared expression against `validUntil`,
      no PHP TimedJob — follow the `attendance`/`certification` declared-calculation-trigger pattern).
- [ ] 2.4 Add `x-openregister-notifications`: `tlvDecisionReceived`, `tlvExpiringSoon`
      (idempotency-keyed).

## 3. Schema: DeliberationRecord

- [ ] 3.1 Add `DeliberationRecord` to `lib/Settings/scholiq_register.json`: `supportRequestId`/
      `tlvApplicationId` (both nullable, at least one required — enforce via a required-one-of
      validation), `attendees` (array of `{role, name/refId}`, role enum `parent|pupil|municipality|
      care-partner|school|swv-coordinator`), `scheduledAt`, `recordedAt`, `outcome`, `pupilVoice`
      (object: `heard` boolean, `statementNote` nullable string, `waived` boolean, `waiverReason`
      nullable string), `tenant_id`.
- [ ] 3.2 Set `appendOnly: true`; `x-openregister-lifecycle`: `scheduled → recorded`.
- [ ] 3.3 Implement the pupil-hoorrecht lifecycle guard (new PHP class, e.g.
      `OCA\Scholiq\Lifecycle\PupilVoiceGuard`, mirroring `LearningPlanSignatureGuard`'s structure) that
      blocks the `scheduled → recorded` transition unless `pupilVoice.heard === true` or
      `pupilVoice.waived === true` with a non-empty `waiverReason`. Wire it into the transition's
      `requires`.
- [ ] 3.4 Unit tests for the guard: blocks with neither set; blocks with `waived: true` and empty
      `waiverReason`; allows with `heard: true`; allows with `waived: true` + `waiverReason` set.

## 4. DataExchangeJob / DataMappingProfile extension (data-exchange register)

- [ ] 4.1 Add `swv` to `DataExchangeJob.target`'s enum (`lib/Settings/scholiq_register.json`, currently
      `bron-rod|oso|leerplicht|surfconext|hr`).
- [ ] 4.2 Add `support-request` to the valid `DataExchangeJob.scope.schema` values.
- [ ] 4.3 Ship (or scaffold, admin-configurable) a `DataMappingProfile` row mapping
      `SupportRequest`/`LearnerProfile`/`LearningPlan` fields → the OSO care-request dossier schema,
      whitelist-only — no full-object passthrough (see `design.md` Security Considerations).
- [ ] 4.4 Extend (or generalise) the existing `OsoDossierReviewGuard`/`pendingParentReview` /
      `approveDossier` transitions so `target: swv` jobs pass through the identical
      `pending-parent-review` gate as `target: oso` jobs — reuse the guard class, do not fork it, unless
      the existing implementation is hard-coded to `target === 'oso'`, in which case generalise the
      condition to match any OSO-format dossier target.
- [ ] 4.5 Wire the `SupportRequest.submit` transition to auto-queue the `DataExchangeJob` (an
      OR-event-driven handler, per the existing ADR-031 "external-system bridge" exception already
      granted to `data-exchange`'s job-execution handler — extend that handler's target switch, do not
      add a new PHP service).
- [ ] 4.6 File/confirm `openconnector#753` scope covers the `swv` named connection (extends the existing
      OSO/Edukoppeling adapter work) — do not implement the wire protocol in Scholiq.

## 5. Frontend

- [ ] 5.1 Add `src/manifest.json` index+detail pages: `SupportRequests`/`SupportRequestDetail`,
      `TlvApplications`/`TlvApplicationDetail`, `DeliberationRecords`/`DeliberationRecordDetail` —
      follow the existing `LearningPlans`/`LearningPlanDetail` page-id convention.
- [ ] 5.2 Wire the SWV dossier review step to reuse the existing `OsoDossierReviewView`
      (`CnStructuredDocReview`, `src/manifest.json:6094`) rather than adding a new component; confirm it
      renders correctly for `target: swv` jobs (not just `target: oso`).
- [ ] 5.3 Add a `pupilVoice` capture UI on the `DeliberationRecord` detail/edit form — a boolean toggle
      pair (`heard`/`waived`) with the corresponding note/reason fields, surfaced clearly enough that a
      coordinator cannot miss it (per the hard lifecycle guard in task 3.3).
- [ ] 5.4 Surface `SupportRequest`/`TlvApplication` status on the existing `LearningPlanDetail` page when
      a link exists (read-only summary widget/tab), so a coordinator sees the full chain from the OPP
      side too.

## 6. Tests + docs + traceability

- [ ] 6.1 Integration test: submit a `SupportRequest` → assert a `DataExchangeJob(target: swv,
      scope.schema: support-request)` is auto-queued and enters `pending-parent-review`.
- [ ] 6.2 Integration test: approve the dossier → job proceeds to `running`/`succeeded`; `SupportRequest`
      moves to `routed-to-swv`.
- [ ] 6.3 Integration test: record a `DeliberationRecord` and a `TlvApplication` decision with
      `validFrom`/`validUntil`; assert `tlvExpiringSoon` fires when the calculated window is entered.
- [ ] 6.4 Security test: attempt the `swv` `DataExchangeJob` dossier composition and assert only
      whitelisted `DataMappingProfile` fields appear in the payload (no full-object passthrough).
- [ ] 6.5 Add `@spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-...`
      docblock tags to the new lifecycle guard and the extended job-execution handler branch.
- [ ] 6.6 Update `openspec/specs/learning-plan/spec.md`'s Out of Scope line (`:99`) once this change
      lands, so it no longer claims the SWV chain is deferred.
- [ ] 6.7 Run `composer check:strict` on all new/touched PHP files and fix any pre-existing warnings
      encountered in them.
- [ ] 6.8 Run `openspec validate zorgvraag-swv-tlv-chain --strict` and resolve any errors.
