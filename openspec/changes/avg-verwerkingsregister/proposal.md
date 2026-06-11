## Why

Scholiq's App Store description makes an explicit compliance claim: *"Immutable audit trail backs compliance evidence, **AVG record-of-processing**, and AI Act decision traces"* (`appinfo/info.xml`, EN + NL). Nothing backs the middle claim. No spec, no schema, no change mentions a verwerkingsregister or record of processing anywhere — `compliance-audit` covers the evidence log and audit-pack export only, and ADR-008 merely cites AVG Art. 30 as a *driver* of the audit trail. The feature re-evaluation of 2026-06-11 flagged this as the top MISSING item: a compliance claim in the store listing with zero coverage, in an app whose wedge buyer is literally a compliance officer.

An AVG Art. 30 record of processing activities (verwerkingsregister) is also intrinsically the right feature for this app: every Dutch school board (bevoegd gezag) and every corporate controller is legally required to maintain one, schools demonstrably struggle with it (it is a standing item in Kennisnet's Aanpak IBP), and Scholiq itself processes exactly the kind of data (BSN, ECK iD, attendance, grades, attestation IP addresses) that must appear in one. Competing LVS vendors ship a privacy bijsluiter describing their processing; Scholiq can do better by shipping the school's register as a living, audit-trail-backed object — including pre-seeded entries for everything Scholiq itself processes.

## What Changes

- **New OR schema `ProcessingActivity`** (verwerkingsactiviteit) in `lib/Settings/scholiq_register.json`, carrying the AVG Art. 30(1) mandatory elements: name, purposes, controller/processor role, categories of data subjects, categories of personal data (with a bijzondere/special-category flag), legal basis (Art. 6 enum), recipients, third-country transfers + safeguards, retention period, security measures (TOMs), plus Scholiq-specific linkage: `linkedSchemas[]` (which Scholiq OR schemas the activity covers), `dpiaRequired`/`dpiaReference`, `ownerUserId`, `reviewIntervalMonths`/`nextReviewAt`, lifecycle (`draft → active → retired`), `tenant_id`.
- **Seed catalogue**: the register import ships ~7 pre-filled `ProcessingActivity` drafts describing Scholiq's own processing — learner administration (LearnerProfile incl. `bsnEncrypted`/`eckId`/`schoolId`), attendance + leerplicht reporting, grading & assessment, compliance training & signed attestations (incl. `actorIp`), credentialing, data exchange to DUO/OSO/municipality/HR, AI features (AI Act decision traces). Seeded as `draft`; the privacy officer reviews, amends, and activates. Seeding follows the fleet `lib/Repair/InitializeRegister.php` pattern (already in place).
- **Audit-trail-backed versioning**: every mutation of an `active` ProcessingActivity emits an OR audit-trail entry (ADR-008) and prior versions remain retrievable — this is what makes the info.xml sentence true.
- **Art. 30 export**: export the register (active entries, Art. 30 column set) as CSV/JSON on demand for the toezichthouder (AP) or an auditor; the export is also included in the existing compliance audit-pack ZIP. PDF rendering is deferred to DocuDesk per the grading precedent ("DocuDesk does templating").
- **Review reminders**: a `scheduled` notification rule in the **verified engine dialect** on `ProcessingActivity` (owner notified when `nextReviewAt` is within the review window). Dialect rules per the in-flight `scholiq-notifications` migration — this change adds one rule in that dialect; it does not re-specify the dialect.
- **Declarative UI**: `src/manifest.json` index + detail pages for ProcessingActivity (ADR-022/ADR-024 — no PHP CRUD controllers); a small custom view only for the export trigger if a manifest action cannot express it.
- **RBAC**: OR-delegated. A `privacy-officer` group (FG/functionaris gegevensbescherming) manages entries; `admin` reads/writes; other roles have no access by default.

## Capabilities

### New Capabilities

- `avg-verwerkingsregister`: AVG Art. 30 record of processing activities as OpenRegister objects — seeded with Scholiq's own processing, audit-trail-backed, reviewable on a cycle, exportable for the supervisory authority and the audit pack.

### Modified Capabilities

*(none — `compliance-audit`'s audit-pack export gains one more artefact, but its existing requirements are untouched; the inclusion is specified in this capability's spec)*

## Impact

- `lib/Settings/scholiq_register.json` — new `ProcessingActivity` schema + seed objects + one verified-dialect notification rule.
- `src/manifest.json` — ProcessingActivity index/detail pages, navigation entry under Compliance.
- Audit-pack export service — include the Art. 30 CSV in the ZIP (one additional artefact writer).
- No change to LearnerProfile or any existing schema; the register *describes* processing of `bsnEncrypted`/`eckId`/`schoolId`, it does not move or duplicate those fields.
- Depends on: nothing new. Notification rule uses the dialect landed by `scholiq-notifications` (if that change has not merged yet, ship the rule in the verified dialect anyway — it is inert until the engine sees it, exactly like the migrated rules).
