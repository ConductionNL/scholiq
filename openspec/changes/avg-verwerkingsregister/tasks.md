# Tasks

- [ ] Add `ProcessingActivity` schema to `lib/Settings/scholiq_register.json` with Art. 30(1) fields (name, purposes, role, categoriesOfDataSubjects, categoriesOfPersonalData, specialCategories + specialCategoriesBasis, legalBasis enum + legitimateInterestAssessment, recipients, thirdCountryTransfers + safeguards, retentionPeriod, securityMeasures, linkedSchemas, dpiaRequired/dpiaReference, ownerUserId, reviewIntervalMonths, nextReviewAt, tenant_id)
- [ ] Declare `x-openregister-lifecycle` on ProcessingActivity (`draft → active → retired`; transitions `activate`, `retire`, `amend`) and relations to covered schemas
- [ ] Add the seed catalogue (~7 draft ProcessingActivity objects covering learner administration, attendance/leerplicht, grading & assessment, attestations incl. actorIp, credentialing, data exchange, AI features) to the register import payload
- [ ] Add one `scheduled` review-reminder rule on ProcessingActivity in the verified notification dialect (`trigger.type: scheduled`, daily, filter lifecycle=active, recipient kind:field ownerUserId, subject{nl,en}) — verified keys only, per `scholiq-notifications`
- [ ] Add manifest index + detail pages for ProcessingActivity and a Compliance navigation entry in `src/manifest.json` (no PHP CRUD controllers)
- [ ] Implement the Art. 30 CSV/JSON export and include `verwerkingsregister.csv` in the compliance audit-pack ZIP
- [ ] Wire OR-delegated RBAC: `privacy-officer` + `admin` groups manage; document group provisioning alongside the existing tenant-role→group table
- [ ] Verify mutations of an `active` entry emit OR audit-trail entries and prior versions are retrievable (ADR-008 consumption, no shadow history schema)
- [ ] nl + en i18n for all UI strings (English keys)
- [ ] PHPUnit for the export artefact writer; Playwright e2e for the index/detail pages and export action (or `@e2e exclude` with reason per scenario where genuinely backend-only)
- [ ] Bump `appinfo/info.xml` version

## Acceptance criteria

- A fresh install shows the seeded draft entries; activating one is an explicit lifecycle transition by a privacy-officer/admin user.
- An `active` entry's edit produces an OR audit-trail entry; its previous version is retrievable.
- The Art. 30 export contains every active entry with the full Art. 30(1) column set; the audit-pack ZIP contains `verwerkingsregister.csv`.
- The review-reminder rule uses only verified dialect keys (`trigger.type`/`channels[]`/`recipients[]`/`subject{nl,en}`).
- No new PHP CRUD controllers; ProcessingActivity is served by manifest pages over the OR objects API.
