# avg-verwerkingsregister Specification

## Purpose
TBD - created by archiving change avg-verwerkingsregister. Update Purpose after archive.
## Requirements
### Requirement: Scholiq MUST ship its processing catalogue as draft seed content

Scholiq SHALL declare its own processing activities via the `x-openregister-processing` dialect (OR-PA-2) in `lib/Settings/scholiq_register.json` — at minimum: learner administration (LearnerProfile incl. `bsnEncrypted`, `eckId`, `schoolId`), attendance and leerplicht reporting, grading and assessment, compliance training and signed attestations (incl. `actorIp`), credentialing, data exchange (DUO/OSO/municipality/HR), and AI features — each entry carrying the full Art. 30(1) field set keyed by `code`, plus `ownerUserId`/`reviewIntervalMonths`/`nextReviewAt` so the platform's review-due notification (OR-PA-1) fires. Seeds arrive as drafts; activation is the privacy officer's explicit decision in the platform lifecycle. No seed entry copies personal-data values, and scholiq ships no notification rule, schema definition, or validation code for this capability.

#### Scenario: Fresh install seeds the register as drafts

<!-- @e2e exclude OR-side seed-import mechanics (OR-PA-2); catalogue content verified by ProcessingActivityCatalogueTest. The admin compliance section deep-link is covered by avg-verwerkingsregister.spec.ts -->

- **GIVEN** a fresh scholiq install completing its register import
- **WHEN** the privacy officer opens the verwerkingsregister
- **THEN** the seeded entries are listed as drafts, including "Leerlingadministratie" naming BSN (encrypted), ECK iD, and SchoolID among the personal-data categories
- **AND** no seeded entry is active until an explicit platform-lifecycle activation

#### Scenario: Review reminders come from the platform

<!-- @e2e exclude OR-PA-1 review-due notification is OpenRegister's; the absence of a scholiq notification rule + presence of owner/review fields is verified by ProcessingActivityCatalogueTest::testOwnerReviewFieldsPresentAndNoScholiqNotificationRule -->

- **GIVEN** an activated entry whose `nextReviewAt` falls within the review window
- **WHEN** OpenRegister's review-due evaluation runs (OR-PA-1)
- **THEN** the entry's `ownerUserId` receives the notification
- **AND** `scholiq_register.json` contains no notification rule for processing-activity reviews

#### Scenario: Officer edits survive re-import

<!-- @e2e exclude upsert-by-code / never-overwrite-officer-edits is OR-PA-2 mechanics; asserted in OpenRegister's suite, not a scholiq UI surface -->

- **GIVEN** the privacy officer amended an activated entry
- **WHEN** scholiq's register configuration is re-imported
- **THEN** the upsert-by-code semantics (OR-PA-2) MUST preserve the officer's edits rather than resetting the entry to the seed

### Requirement: Scholiq MUST surface its register slice in declarative Compliance UI

Scholiq SHALL provide manifest index and detail pages for its verwerkingsregister slice under a Compliance navigation entry, consuming the platform verwerkingsactiviteiten API with the scholiq register filter (OR-PA-8 scoping). No PHP CRUD controllers and no app-side authorization code; write access is the platform's admin-default plus privacy-officer delegation.

#### Scenario: Privacy officer browses scholiq's register slice

<!-- @e2e tests/e2e/spec-coverage/avg-verwerkingsregister.spec.ts -->

- **GIVEN** a user holding the privacy-officer delegation in OpenRegister
- **WHEN** they open the Compliance → Verwerkingsregister page in scholiq
- **THEN** they see exactly the scholiq-slice activities (index + detail), served by manifest pages over the platform API

#### Scenario: Non-privileged user cannot edit

<!-- @e2e exclude access gating is OR-PA-8 (ProcessingLogController fails closed for non-admin/non-FG); asserted in OpenRegister's suite, not a scholiq UI surface (the section is admin-gated client-side as defence-in-depth) -->

- **GIVEN** an authenticated user without the privacy-officer or admin delegation
- **WHEN** they attempt to update a processing activity via the platform API
- **THEN** the request is rejected by OpenRegister's RBAC (OR-PA-8); scholiq enforces nothing itself

### Requirement: The compliance audit pack MUST include the platform-generated Art. 30 export

The compliance audit-pack ZIP SHALL include `verwerkingsregister.csv`, obtained from the platform's Art. 30 export (OR-PA-7) scoped to the scholiq register slice. Scholiq implements only the fetch-and-include artefact step — no export engine, serialisation, or column logic.

#### Scenario: Audit pack includes the register

<!-- @e2e exclude ZIP-stream backend artefact verified by ProcessingActivityCatalogueTest::testAuditPackIncludesVerwerkingsregisterAndFailsLoudly; no UI surface to drive -->

- **GIVEN** a compliance audit-pack export for any regulation and date range
- **WHEN** the ZIP is produced
- **THEN** it contains `verwerkingsregister.csv` whose content equals the OR-PA-7 export for the scholiq slice at generation time

#### Scenario: Platform capability absent fails loudly

<!-- @e2e exclude backend ZIP-stream warning path verified by ProcessingActivityCatalogueTest::testAuditPackIncludesVerwerkingsregisterAndFailsLoudly; no UI surface to drive -->

- **GIVEN** the deployed OpenRegister version lacks the processing-activity register
- **WHEN** an audit-pack export is requested
- **THEN** the pack generation MUST surface a clear "platform capability missing" warning for the verwerkingsregister artefact rather than silently omitting it

