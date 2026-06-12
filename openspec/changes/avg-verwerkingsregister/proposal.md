---
status: BLOCKED_EXTERNAL
depends_on: [openregister/processing-activity-register]
---

## Why

Scholiq's App Store description makes an explicit compliance claim: *"Immutable audit trail backs compliance evidence, **AVG record-of-processing**, and AI Act decision traces"* (`appinfo/info.xml`, EN + NL). Nothing backs the middle claim. The feature re-evaluation of 2026-06-11 flagged this as the top MISSING item: a compliance claim in the store listing with zero coverage, in an app whose wedge buyer is literally a compliance officer.

**Abstraction decision (2026-06-11):** on the same day, procest, docudesk, and scholiq independently authored near-identical AVG processing-register changes. Per ADR-022 the AVG verwerkingsregister is now a **platform abstraction owned by OpenRegister** — authored as `openregister/openspec/changes/processing-activity-register` (requirements OR-PA-1..9, with a requirement-by-requirement supersession table in its `design.md`). This change is therefore reworked from "build a verwerkingsregister in scholiq" to a **thin consumer** of the platform capability:

- The `ProcessingActivity` schema, lifecycle, save-time validation, owner/review fields, and review-due notifications (former S1, S5) → superseded by **OR-PA-1**. The platform owns the entity and notifies owners of due reviews; scholiq only *sets* the owner/review fields on its seed entries.
- Seed-as-draft mechanics, upsert-by-code, never-overwrite-officer-edits (former S2 mechanics) → superseded by **OR-PA-2** (`x-openregister-processing` dialect). The catalogue *content* stays scholiq's.
- Audit-trail-backed versioning of active entries (former S3) → superseded by **OR-PA-1** (versioning scenario).
- The Art. 30(4) export engine (CSV/JSON generation, former S4 engine half) → superseded by **OR-PA-7**. The *inclusion* of that export in scholiq's compliance audit-pack ZIP stays app-side.
- RBAC enforcement (former S6 enforcement half) → superseded by **OR-PA-8** (admin-default, privacy-officer/FG delegation, org scoping, register filter). Scholiq's declarative UI pages over its register slice stay app-side.

What scholiq contributes is exactly what no platform can know: **its own catalogue content** (which processing scholiq performs, on which data — `bsnEncrypted`, `eckId`, `schoolId`, attestation `actorIp` — under which legal basis), **its UI surfacing** under the Compliance navigation, and **its audit-pack inclusion** of the platform-generated Art. 30 export.

## What Changes

- **Declare scholiq's processing catalogue via `x-openregister-processing`** (OR-PA-2) on the scholiq register in `lib/Settings/scholiq_register.json`: ~7 activity entries describing scholiq's own processing — learner administration (LearnerProfile incl. `bsnEncrypted`/`eckId`/`schoolId`), attendance + leerplicht reporting, grading & assessment, compliance training & signed attestations (incl. `actorIp`), credentialing, data exchange to DUO/OSO/municipality/HR, AI features (AI Act decision traces). Full Art. 30(1) field set per entry, keyed by `code`, including `ownerUserId`/`reviewIntervalMonths`/`nextReviewAt` so the platform's review-due notification (OR-PA-1) fires — scholiq ships **no** notification rule of its own. Seeded as `draft` per OR-PA-2; the privacy officer reviews, amends, and activates (the school is the controller).
- **Declarative UI**: `src/manifest.json` index + detail pages for scholiq's verwerkingsregister slice under a Compliance navigation entry, consuming the platform verwerkingsactiviteiten API with the scholiq register filter (OR-PA-8 scoping). No PHP CRUD controllers, no app-side auth code — access is the platform's admin-default + privacy-officer delegation.
- **Audit-pack inclusion**: the existing compliance audit-pack ZIP gains `verwerkingsregister.csv`, obtained from the platform's Art. 30 export (OR-PA-7) scoped to the scholiq slice — scholiq implements no export engine, only the fetch-and-include step.

## Capabilities

### New Capabilities

- `avg-verwerkingsregister`: scholiq as a thin consumer of the OpenRegister processing-activity register — contributing its own seed catalogue content via `x-openregister-processing`, surfacing its register slice in declarative Compliance UI, and including the platform-generated Art. 30 export in the compliance audit pack.

### Modified Capabilities

*(none — `compliance-audit`'s audit-pack export gains one more artefact, but its existing requirements are untouched; the inclusion is specified in this capability's spec)*

## Impact

- **Depends on `openregister/processing-activity-register` — BLOCKED_EXTERNAL until it lands.** Every layer this change consumes (annotation dialect, seed mechanics, validation, versioning, review notifications, export, RBAC) ships there.
- `lib/Settings/scholiq_register.json` — `x-openregister-processing` annotation with the seed catalogue (content only; mechanics are OR-PA-2).
- `src/manifest.json` — verwerkingsregister index/detail pages, Compliance navigation entry.
- Audit-pack export service — include the platform-generated Art. 30 CSV in the ZIP (one fetch-and-include artefact writer; no export engine).
- No change to LearnerProfile or any existing schema; the catalogue *describes* processing of `bsnEncrypted`/`eckId`/`schoolId`, it does not move or duplicate those fields, and no seed entry copies personal-data values.
- No notification rule, no lifecycle declaration, no versioning code, no RBAC code — all superseded platform-side per the 2026-06-11 abstraction decision.
