# Tasks

> Status: implemented 2026-06-15 (info.xml 0.2.1 → 0.2.2). Verification gate,
> coverage predicate, bulk entry, manual-credential issuance, and the
> audit-pack CSV are real and PHPUnit-covered (18 new tests, 75/75 green).
> Notes on scope below each item.

- [x] Add `ExternalTrainingRecord` schema to `lib/Settings/scholiq_register.json` (learnerId, title, provider, kind enum, regulationSlug?, courseId?, completedAt, validUntil?, evidenceNote, submittedBy, verifiedBy, rejectionReason, credentialId?, batchId?, tenant_id) with lifecycle `submitted → verified | rejected`
- [x] Declare the verification transition guard: actor in `compliance-officer`/`hr`/`admin`, ≥1 OR evidence file attachment present, `verifiedBy ≠ submittedBy` for learner self-submissions — `lib/Lifecycle/ExternalTrainingVerificationGuard.php`, stamps verifiedBy/verifiedAt
- [x] Add verified-dialect notification rules: `created` → groups [compliance-officer, hr]; `transition` verify/reject → field submittedBy (subject{nl,en}, verified keys only per `scholiq-notifications`)
- [x] Extend the compliance coverage predicate: covered = signed Attestation ∨ valid Credential ∨ verified ExternalTrainingRecord with matching regulationSlug and unexpired validUntil — `ExternalTrainingService::isLearnerCovered`; evidence class per learner exposed via `coveringEvidenceClass` + the `externalTraining#learnerCoverage` endpoint
- [x] Extend the audit-pack ZIP: `external-training.csv` (verified records + evidence-file references) for the selected regulation/date range, labelled separately from in-app attestations — `AuditPackExportController::buildExternalTrainingCsv`
- [x] Implement bulk entry: one training for N selected learners sharing one batchId — `ExternalTrainingService::bulkRecord` + `externalTraining#bulkRecord` (officer/HR/admin via the ADR-023 action matrix). The shared evidence attachment is linked via OR's file-attachment API; batch verification transitions each record through the same lifecycle guard (one OR audit entry per record). [~] CSV-upload variant of the multi-select input deferred — multi-select via the declarative index page; CSV import is a thin follow-up.
- [x] Implement optional manual Credential issuance on verify (`source: manual`, `expiresAt = validUntil`, `credentialId` stored back on the record) — `ExternalTrainingService::buildManualCredentialPayload` + `externalTraining#issueCredential`
- [x] Add manifest index/detail pages + entry point — `ExternalTrainingRecords`/`ExternalTrainingRecordDetail` pages, role-gated `ExternalTraining` menu entry, and the `KpiExternalTrainingWidget` on the Compliance dashboard. [~] A bespoke "Record external training" action button embedded on the learner/regulation detail views is deferred; the records are reachable via the menu entry + index page and created via the bulk endpoint.
- [x] Evidence attachments inherit OR object RBAC via `x-property-rbac` (admin/compliance-officer/hr + learner-own + submitter-own; never public)
- [x] nl + en i18n (English keys); PHPUnit on the coverage predicate (all three evidence classes + expiry) and the transition guard (group / evidence / self-verification). [~] Playwright e2e is scoped to the index-page render (`tests/e2e/spec-coverage/external-training-recording.spec.ts`); the full submit → verify → coverage-flip browser flow is covered at the unit level (guard + predicate) because it spans OR lifecycle + OR notification delivery that have no single scholiq DOM surface.
- [x] Bump `appinfo/info.xml` version (0.2.1 → 0.2.2)

## Acceptance criteria

- An unverified (submitted) record never changes coverage; verifying it flips the learner to covered for the matching regulation; an expired `validUntil` drops them again. — covered by `ExternalTrainingServiceTest` (unverified → empty result; verified covers; expired does not).
- Verification without an evidence attachment, or self-verification by the submitting learner, is rejected by the lifecycle guard. — `ExternalTrainingVerificationGuardTest::testNoEvidenceAttachmentDenied` / `testSelfVerificationDenied`.
- The audit-pack ZIP for a regulation shows external records and their evidence as a separately-labelled evidence class (`external-training.csv`); signed Attestation artefacts are untouched. — `AuditPackExportController` adds the file alongside the existing artefacts.
- Bulk entry for N learners yields N records sharing one batchId. — `testBulkRecordCreatesOnePerLearnerWithSharedBatch`.
- Both notification rules use only verified dialect keys. — register-validation suite (`tests/validate-register.js`) passes.
