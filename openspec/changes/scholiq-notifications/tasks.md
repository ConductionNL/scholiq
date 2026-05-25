# Tasks

- [x] Migrate `x-openregister-notifications` on `Credential` to verified dialect (issued/expiringSoon/expired/revoked)
- [x] Migrate `x-openregister-notifications` on `Enrolment` (activated/completed/dueReminder/overdue)
- [x] Migrate `x-openregister-notifications` on `ExcuseRequest` (approved/rejected)
- [x] Migrate `x-openregister-notifications` on `DataExchangeJob` (jobFinished)
- [x] Migrate `x-openregister-notifications` on `Regulation` (published; defer coverageDropped pending field-change engine)
- [x] Migrate `x-openregister-notifications` on `AttendanceThreshold` to numeric `calculatedChange` (thresholdCrossed)
- [x] Migrate `x-openregister-notifications` on `Course` (published/archived) to verified dialect
- [x] Migrate `x-openregister-notifications` on `Lesson` (published) and `AiFeature` (enable/disable)
- [x] Remove deferred `GradeEntry.gradePublished` and `LearningPlan.*` legacy blocks (engine has no equivalent)
- [x] Confirm each `transition` action name (issue/expire/revoke/activate/complete/publish/archive/approve/reject) exists in the schema lifecycle; swap to `scheduled`+filter where no named action exists
- [x] Provide inline `subject{nl,en}` for every migrated rule (replace legacy i18n-key/`template` strings)
- [x] Validate `lib/Settings/scholiq_register.json` parses as JSON and every block uses verified keys only (`trigger.type`/`channels[]`/`recipients[]`/`subject{nl,en}`)
- [ ] Confirm with engine owner whether `idempotencyKey`/`alsoDispatchLifecycle` de-dup is needed and whether the verified dialect provides it

## Acceptance criteria

- No legacy keys remain in `scholiq_register.json`: `channel` (singular), `recipient`/`recipientField`/`recipientFromTenantRole`/`fallbackRecipientFromTenantRole`, `@self.`, `lifecycleEnter`, `calculated` (boolean), `userPreferenceKey`, `idempotencyKey`, `alsoDispatchLifecycle`, `event`, `template`.
- Every migrated rule has `trigger.type` from the verified set, `channels[]`, `recipients[]` with `kind` of `field|groups|object-acl`, and inline `subject{nl,en}`.
- All `field` recipients reference confirmed Nextcloud-user-ID fields (`learnerId`, `managerId`, `requestedBy`, `submittedBy`).
- The register JSON validates against OpenRegister's register schema.
- Deferred rules (`Regulation.coverageDropped`, `GradeEntry`, `LearningPlan`) are documented in the proposal's Caveats, not left in a dialect the engine ignores.
