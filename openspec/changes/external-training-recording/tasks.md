# Tasks

- [ ] Add `ExternalTrainingRecord` schema to `lib/Settings/scholiq_register.json` (learnerId, title, provider, kind enum, regulationSlug?, courseId?, completedAt, validUntil?, evidenceNote, submittedBy, verifiedBy, rejectionReason, credentialId?, tenant_id) with lifecycle `submitted → verified | rejected`
- [ ] Declare the verification transition guard: actor in `compliance-officer`/`hr`/`admin`, ≥1 OR evidence file attachment present, `verifiedBy ≠ submittedBy` for learner self-submissions
- [ ] Add two verified-dialect notification rules: `created` → groups [compliance-officer, hr]; `transition` verify/reject → field submittedBy (subject{nl,en}, verified keys only per `scholiq-notifications`)
- [ ] Extend the compliance coverage predicate: covered = signed Attestation ∨ valid Credential ∨ verified ExternalTrainingRecord with matching regulationSlug and unexpired validUntil; expose the evidence class per learner in the coverage view
- [ ] Extend the audit-pack ZIP: `external-training.csv` + evidence-attachment folder for the selected regulation/date range, labelled separately from in-app attestations
- [ ] Implement bulk entry: one training (title/provider/date/regulation) for N selected learners (multi-select or CSV) sharing one evidence attachment; batch verification transitions all records with per-record audit entries
- [ ] Implement optional manual Credential issuance on verify (existing `source: manual` path, `expiresAt = validUntil`, store credentialId back on the record) so certification expiry alerts/renewal cover external certificates
- [ ] Add manifest index/detail pages + "Record external training" actions on learner and regulation views; custom Vue view only if the bulk form exceeds manifest expressiveness
- [ ] Verify evidence attachments inherit OR object RBAC (officer/HR/admin + the learner's own record; never public)
- [ ] nl + en i18n (English keys); PHPUnit on the coverage predicate (all three evidence classes) and transition guards; Playwright e2e on submit → verify → coverage-flip
- [ ] Bump `appinfo/info.xml` version

## Acceptance criteria

- An unverified (submitted) record never changes coverage; verifying it flips the learner to covered for the matching regulation; an expired `validUntil` drops them again.
- Verification without an evidence attachment, or self-verification by the submitting learner, is rejected by the lifecycle guard.
- The audit-pack ZIP for a regulation shows external records and their evidence as a separately-labelled evidence class; signed Attestation artefacts are untouched.
- Bulk entry for N learners yields N records sharing one attachment; batch verify produces one audit-trail entry per record.
- Both notification rules use only verified dialect keys.
