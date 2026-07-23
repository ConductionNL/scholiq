---
status: done
---

# external-training-recording Specification

## Purpose

Capture externally-completed training (classroom, third-party e-learning, conferences, on-the-job) per learner as `ExternalTrainingRecord` OpenRegister objects with evidence file attachments and an officer verification gate — a separate, clearly-labelled evidence class that feeds compliance coverage and audit packs without diluting the signed-attestation model.
## Requirements
### Requirement: ExternalTrainingRecord MUST be an OpenRegister object with evidence attachments

The system MUST persist `ExternalTrainingRecord` objects (learnerId, title, provider, kind `classroom | external-elearning | conference | on-the-job | other`, optional regulationSlug, optional courseId, completedAt, optional validUntil, submittedBy, verifiedBy, rejectionReason, optional credentialId, optional batchId, tenant_id) with lifecycle `submitted → verified | rejected`. Evidence files (certificate scan, signed attendance list) MUST be OpenRegister file attachments on the record; the app MUST NOT store file bytes itself. Records MUST NOT be persisted as Attestations or Enrolments.

#### Scenario: Learner self-reports a conference with a certificate
<!-- @e2e tests/e2e/spec-coverage/external-training-recording.spec.ts -->
<!-- Record-creation + no-Attestation/Enrolment invariants verified by PHPUnit (ExternalTrainingServiceTest) and OR's object API; the e2e asserts the declarative index page renders so the learner can reach the form. -->

- **GIVEN** an authenticated learner
- **WHEN** they submit an ExternalTrainingRecord for "NIS2 voor bestuurders (extern)" with a PDF certificate attached
- **THEN** the record persists in lifecycle `submitted` with `submittedBy` = the learner
- **AND** the certificate is an OR file attachment on the record
- **AND** no Attestation or Enrolment object is created

### Requirement: Verification MUST be gated and tamper-resistant

The `submitted → verified` transition MUST require: the actor is in `compliance-officer`, `hr`, or `admin`; at least one evidence attachment is present; and `verifiedBy ≠ submittedBy` when the submitter is the learner the record is about. Rejection MUST require a `rejectionReason`. Every transition MUST emit an OR audit-trail entry (ADR-008). Unverified records MUST never influence coverage.

#### Scenario: Verification without evidence is refused
<!-- @e2e exclude Lifecycle-guard behaviour (ExternalTrainingVerificationGuard); verified by PHPUnit ExternalTrainingVerificationGuardTest::testNoEvidenceAttachmentDenied. The transition is an OR engine call with no scholiq DOM surface. -->

- **GIVEN** a `submitted` record with no evidence attachment
- **WHEN** a compliance officer attempts the `verify` transition
- **THEN** the lifecycle guard rejects the transition naming the missing evidence

#### Scenario: No self-verification
<!-- @e2e exclude Lifecycle-guard behaviour; verified by PHPUnit ExternalTrainingVerificationGuardTest::testSelfVerificationDenied. No scholiq DOM surface. -->

- **GIVEN** a record submitted by learner `anna` about herself, with `anna` also in the `hr` group
- **WHEN** `anna` attempts to verify her own record
- **THEN** the guard rejects the transition

### Requirement: Bulk entry MUST create per-learner records sharing one evidence attachment

The system MUST support recording one external training (title, provider, completedAt, regulationSlug) for multiple selected learners at once (multi-select or CSV), creating one ExternalTrainingRecord per learner referencing the same evidence attachment. Batch verification MUST transition all records in the batch and emit one audit-trail entry per record. Scheduling of the classroom event itself is NC Calendar territory; no Scholiq event schema is introduced.

#### Scenario: Classroom session recorded for the whole board
<!-- @e2e exclude Multi-object bulk creation (ExternalTrainingService::bulkRecord) verified by PHPUnit ExternalTrainingServiceTest::testBulkRecordCreatesOnePerLearnerWithSharedBatch; batch verify and audit entries are OR-engine behaviour. No single drivable DOM scenario. -->

- **GIVEN** a signed attendance list for a classroom NIS2 session attended by 7 board members
- **WHEN** HR bulk-records the training for the 7 learners with the attendance list attached
- **THEN** 7 `submitted` records exist, each referencing the same OR file attachment
- **AND** a single batch verify transitions all 7 to `verified` with 7 audit-trail entries

### Requirement: Verification MAY issue a linked manual Credential for expiring certificates

On verification of a record with `validUntil` set, the verifier MUST be offered issuance of a `Credential` via the existing manual issuance path (`source: manual`, `expiresAt = validUntil`, regulationSlug carried over), with `credentialId` stored back on the record — so the certification capability's existing expiry alerts and renewal auto-enrolment cover external certificates. No Credential schema change is made.

#### Scenario: External BHV certificate enters the expiry machinery
<!-- @e2e exclude Manual-credential payload (ExternalTrainingService::buildManualCredentialPayload) verified by PHPUnit ExternalTrainingServiceTest::testBuildManualCredentialPayload; the resulting Credential's expiry rules are OR/declarative. No scholiq DOM surface. -->

- **GIVEN** a verified record "BHV herhaling" with `validUntil` 2027-06-01
- **WHEN** the verifier opts to issue the linked credential
- **THEN** a Credential exists with `source: manual` and `expiresAt: 2027-06-01`, linked via `credentialId`
- **AND** the certification capability's expiry notification rules apply to it unchanged

### Requirement: Decision notifications MUST use the verified dialect

Two notification rules on ExternalTrainingRecord MUST be declared exclusively in the verified engine dialect (per the `scholiq-notifications` migration): on `created`, notify the `compliance-officer` and `hr` groups; on the `verify`/`reject` transitions, notify `submittedBy`. Inline `subject{nl,en}`; no legacy keys.

#### Scenario: Submitter learns the outcome
<!-- @e2e exclude Notification delivery is OpenRegister's dispatcher (app id openregister); scholiq only declares the verified-dialect rules (verified by the register-validation suite). No scholiq DOM surface drives NC notification fan-out. -->

- **GIVEN** a `submitted` record created by user `anna`
- **WHEN** a compliance officer rejects it with a reason
- **THEN** `anna` receives a Nextcloud notification with an nl/en subject
- **AND** both rule blocks in `scholiq_register.json` contain only verified dialect keys

### Requirement: `ExternalTrainingRecord` carries an additive ADR-046 portal-scoping reference

`ExternalTrainingRecord` MUST carry a nullable `learnerRef` (`format: uuid`, `$ref: LearnerProfile`)
alongside its existing `learnerId` (Nextcloud user id), additive and optional so existing rows stay valid
and an unset ref is fail-closed (invisible to any `learnerRef`-scoped read, including
`LearningRecordAggregationService`) until backfilled — the identical shape `portal-identity` established for
its first slice of eight schemas (`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`, `Submission`,
`ExcuseRequest`, `LearnerProfile`, `GradeNotification`) and every wave-2 capability that introduced a new
learner-scoped schema since (`Portfolio`, `CompetencyAttainment`, `BpvPlacement`, `LessonCompletion`,
`ReportCard` all carry the same field). `ExternalTrainingRecord` was not part of `portal-identity`'s original
slice and had no `learnerRef` at HEAD — this closes that gap so `portable-learning-record` can scope it like
every other consumed schema.

#### Scenario: `ExternalTrainingRecord` gains an additive, optional `learnerRef`

<!-- @e2e exclude Pure OpenRegister schema shape; no scholiq DOM surface for the field addition itself — covered by the register-validation test referenced in tasks.md, mirroring portal-identity's own equivalent scenario. -->

- **GIVEN** the shipped `scholiq_register.json`
- **WHEN** the register configuration is parsed
- **THEN** `ExternalTrainingRecord` defines a `learnerRef` property with `format: uuid` and `$ref:
  LearnerProfile`
- **AND** its existing `learnerId` property is unchanged and `learnerRef` is not `required`
- **AND** existing `ExternalTrainingRecord` rows with no `learnerRef` remain valid and stay invisible to any
  `learnerRef`-scoped read until backfilled

