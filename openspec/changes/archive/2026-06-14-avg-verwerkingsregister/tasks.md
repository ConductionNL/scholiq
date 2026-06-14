# Tasks

> UNBLOCKED 2026-06-14: `openregister/processing-activity-register` per-access read-logging delta shipped (OR >= 0.2.14: ProcessingLogService reads `x-openregister-processing`, ProcessingLogController + `/api/avg/verwerkingen[/betrokkene]` + `/api/avg/verantwoording`). This is now a thin CONSUMER build (mirrors docudesk PR #111). The aggregate Art. 30 register export to JSON/CSV/PDF (OR-PA-7) and the full OR-side catalogue seeder are still OR-deferred → marked `[~]` below.

- [x] **T1**: Confirmed the OR per-access read-logging capability is deployed (OR >= 0.2.14). Recorded the minimum OR version in `appinfo/info.xml` (dependency comment) and as the `openregister: ^v0.2.14` constraint in `lib/Settings/scholiq_register.json`.
- [x] **T2**: Authored the seed catalogue (7 activities: learner administration incl. `bsnEncrypted`/`eckId`/`schoolId`, attendance/leerplicht, grading & assessment, attestations incl. `actorIp`, credentialing, data exchange DUO/OSO/municipality/HR, AI features) as `x-openregister-processing` entries in `lib/Settings/scholiq_register.json` — full Art. 30(1) field set, keyed by `code`, `logReads:true` + self-attribution, with `ownerUserId`/`reviewIntervalMonths`/`nextReviewAt` set so OR-PA-1 review notifications fire. No scholiq notification rule, no schema definition.
- [x] **T3**: Surfaced the scholiq verwerkingsregister slice as an admin-gated AVG Art. 30 compliance section in the manifest `Settings` page (`ScholiqSettings.vue`), consuming the platform API (`/api/avg/verwerkingen?register=scholiq`, `/betrokkene`, `/verantwoording`) — OR-PA-8 scoping. No PHP CRUD controllers, no app-side auth. NOTE: OR stores verwerkingsactiviteiten in a dedicated platform table (`oc_openregister_verwerkingsactiviteiten`), not in a scholiq register/schema, so a manifest `index`/`detail` page (which targets a register+schema via `/api/objects`) cannot render them; the admin Settings surface that deep-links to the platform API is the faithful equivalent (mirrors docudesk PR #111 exactly).
- [x] **T4**: Extended the compliance audit-pack writer (`AuditPackExportController`) with one fetch-and-include step: `verwerkingsregister.csv` fetched from OR's `/api/avg/verwerkingen` (OR-PA-7/8) scoped to the scholiq register and included verbatim (no export engine, no column logic), with a loud "PLATFORM CAPABILITY MISSING" warning artefact when OR lacks the capability (route absent / unreachable / unexpected response).
- [x] **T5**: nl + en i18n for all new UI strings (English keys, lossless additions). Privacy-officer delegation is OR-PA-8 (admin + FG group); documented inline in the compliance section copy.
- [x] **T6**: PHPUnit `ProcessingActivityCatalogueTest` (catalogue shape, read-log opt-in/self-attribution, owner/review fields + no-notification-rule, OR-version, audit-pack include + loud warning, NO-scholiq-export-engine guard incl. no ProcessingActivity schema). Playwright e2e for the compliance section. The re-import / upsert-by-code "officer edits survive" guarantee is OR-PA-2 mechanics — asserted OR-side, not duplicated here (`[~]` in spec scenario).
- [x] **T7**: Bumped `appinfo/info.xml` version 0.1.4 → 0.2.0 and `scholiq_register.json` 0.1.0 → 0.2.0.

## OR-deferred (not built in scholiq — ADR-022)

- [~] Aggregate Art. 30 register export to JSON/CSV/PDF (OR-PA-7): the deployed OR exposes the per-access read log + per-subject extract now, but not the aggregate register export engine. Scholiq's audit-pack includes the read-log query result as `verwerkingsregister.csv` today and will carry the aggregate export verbatim once OR-PA-7 lands. Scholiq builds NO export engine.
- [~] Full OR-side catalogue seeder + upsert-by-code / never-overwrite-officer-edits + lifecycle activation (OR-PA-1/OR-PA-2): scholiq supplies the catalogue content; the seed-import mechanics, versioning, and review-due notifications are OpenRegister's.

## Acceptance criteria

- A fresh install seeds drafts via the dialect; activation is an explicit platform-lifecycle transition; re-import never resets officer edits.
- Review reminders are delivered by OR-PA-1 with zero scholiq notification rules.
- The audit-pack ZIP contains the platform-generated `verwerkingsregister.csv`; a missing platform capability warns loudly.
- No PHP CRUD controllers, no app-side schema/validation/export/RBAC code for this capability.
