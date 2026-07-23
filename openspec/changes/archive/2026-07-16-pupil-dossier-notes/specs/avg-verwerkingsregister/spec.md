# AVG Verwerkingsregister — Pupil Dossier Catalogue Delta

**Spec refs**: `avg-verwerkingsregister`; `pupil-dossier` (this change's new capability).

## MODIFIED Requirements

### Requirement: Scholiq MUST ship its processing catalogue as draft seed content

Scholiq SHALL declare its own processing activities via the `x-openregister-processing` dialect (OR-PA-2) in
`lib/Settings/scholiq_register.json` — at minimum: learner administration (LearnerProfile incl.
`bsnEncrypted`, `eckId`, `schoolId`), attendance and leerplicht reporting, grading and assessment,
compliance training and signed attestations (incl. `actorIp`), credentialing, data exchange (DUO/OSO/
municipality/HR), AI features, and **everyday pupil-dossier notes, behaviour incidents, and wellbeing
check-ins** (`DossierNote`, `BehaviourIncident`, `WellbeingCheckIn` — the `pupil-dossier` capability) — each
entry carrying the full Art. 30(1) field set keyed by `code`, plus `ownerUserId`/`reviewIntervalMonths`/
`nextReviewAt` so the platform's review-due notification (OR-PA-1) fires. Seeds arrive as drafts; activation
is the privacy officer's explicit decision in the platform lifecycle. No seed entry copies personal-data
values, and scholiq ships no notification rule, schema definition, or validation code for this capability.

#### Scenario: Fresh install seeds the register as drafts

<!-- @e2e exclude OR-side seed-import mechanics (OR-PA-2); catalogue content verified by
     ProcessingActivityCatalogueTest. The admin compliance section deep-link is covered by
     avg-verwerkingsregister.spec.ts -->

- **GIVEN** a fresh scholiq install completing its register import
- **WHEN** the privacy officer opens the verwerkingsregister
- **THEN** the seeded entries are listed as drafts, including "Leerlingadministratie" naming BSN (encrypted),
  ECK iD, and SchoolID among the personal-data categories
- **AND** the seeded entries also include the three new `pupil-dossier` processing activities
  (`scholiq-pupil-dossier-notes`, `scholiq-behaviour-incidents`, `scholiq-wellbeing-checkins`)
- **AND** no seeded entry is active until an explicit platform-lifecycle activation

#### Scenario: Review reminders come from the platform

<!-- @e2e exclude OR-PA-1 review-due notification is OpenRegister's; the absence of a scholiq notification
     rule + presence of owner/review fields is verified by
     ProcessingActivityCatalogueTest::testOwnerReviewFieldsPresentAndNoScholiqNotificationRule -->

- **GIVEN** an activated entry whose `nextReviewAt` falls within the review window
- **WHEN** OpenRegister's review-due evaluation runs (OR-PA-1)
- **THEN** the entry's `ownerUserId` receives the notification
- **AND** `scholiq_register.json` contains no notification rule for processing-activity reviews

#### Scenario: Officer edits survive re-import

<!-- @e2e exclude upsert-by-code / never-overwrite-officer-edits is OR-PA-2 mechanics; asserted in
     OpenRegister's suite, not a scholiq UI surface -->

- **GIVEN** the privacy officer amended an activated entry
- **WHEN** scholiq's register configuration is re-imported
- **THEN** the upsert-by-code semantics (OR-PA-2) MUST preserve the officer's edits rather than resetting the
  entry to the seed

## Data Model

New (this delta, additive-only, `pupil-dossier` capability): three `x-openregister-processing` blocks, one
per new schema (matching the register's established one-block-per-schema granularity, e.g. `ExemptionCase`/
`FraudCase` each carry their own block despite sharing a capability):

- `DossierNote.x-openregister-processing`: `code: scholiq-pupil-dossier-notes`, `rechtsgrond: public-task`
  (duty-of-care monitoring, same basis as `LearnerProfile`'s own `scholiq-learner-administration` entry),
  `dataCategories: [learnerId, authorId, category, confidentiality, body]`.
- `BehaviourIncident.x-openregister-processing`: `code: scholiq-behaviour-incidents`, `rechtsgrond:
  public-task`, `dataCategories: [learnerId, reportedBy, what, location, involvedUserIds, severity,
  followUpActions, resolution, escalatedSupportRequestId]`.
- `WellbeingCheckIn.x-openregister-processing`: `code: scholiq-wellbeing-checkins`, `rechtsgrond:
  public-task` (see design.md's open question on whether mood/wellbeing data warrants a stricter AVG Art. 9
  special-category basis instead — flagged for the privacy officer, not resolved by this spec).
