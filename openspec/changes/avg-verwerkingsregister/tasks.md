# Tasks

> BLOCKED_EXTERNAL: all implementation tasks require `openregister/processing-activity-register` (OR-PA-1..9) to land first. T1 (dependency check) can run now.

- [ ] **T1**: Confirm the OR change is merged and the `x-openregister-processing` dialect is available in the deployed OR version; record the minimum OR version in `appinfo/info.xml` dependencies.
- [ ] **T2**: Author the seed catalogue (~7 activities: learner administration incl. `bsnEncrypted`/`eckId`/`schoolId`, attendance/leerplicht, grading & assessment, attestations incl. `actorIp`, credentialing, data exchange DUO/OSO/municipality/HR, AI features) as `x-openregister-processing` entries in `lib/Settings/scholiq_register.json` — full Art. 30(1) field set, keyed by `code`, with `ownerUserId`/`reviewIntervalMonths`/`nextReviewAt` set so OR-PA-1 review notifications fire. No scholiq notification rule, no schema definition.
- [ ] **T3**: Add manifest index + detail pages for the scholiq verwerkingsregister slice and a Compliance navigation entry in `src/manifest.json`, consuming the platform API with the scholiq register filter (OR-PA-8). No PHP CRUD controllers.
- [ ] **T4**: Extend the compliance audit-pack writer with one fetch-and-include step: `verwerkingsregister.csv` from the OR-PA-7 export scoped to the scholiq slice, with a loud "platform capability missing" warning path when OR lacks the capability.
- [ ] **T5**: nl + en i18n for new UI strings (English keys); document the privacy-officer delegation alongside the existing tenant-role→group table.
- [ ] **T6**: PHPUnit for the audit-pack artefact step (incl. missing-capability warning); Playwright e2e for the Compliance pages; re-import test proving officer edits survive (upsert-by-code).
- [ ] **T7**: Bump `appinfo/info.xml` version.

## Acceptance criteria

- A fresh install seeds drafts via the dialect; activation is an explicit platform-lifecycle transition; re-import never resets officer edits.
- Review reminders are delivered by OR-PA-1 with zero scholiq notification rules.
- The audit-pack ZIP contains the platform-generated `verwerkingsregister.csv`; a missing platform capability warns loudly.
- No PHP CRUD controllers, no app-side schema/validation/export/RBAC code for this capability.
