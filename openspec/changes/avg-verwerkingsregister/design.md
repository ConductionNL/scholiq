## Context

AVG/GDPR Art. 30 obliges every controller (school board, corporate employer) to maintain a record of processing activities with a fixed minimum content (Art. 30(1)(a)–(g)) and to make it available to the supervisory authority on request (Art. 30(4)). Dutch schools handle this today in spreadsheets or in Kennisnet's Aanpak-IBP templates. Scholiq already has everything needed to do it properly: OpenRegister object storage with lifecycle + immutable audit trail (ADR-008), a declarative manifest UI (ADR-024), OR-delegated RBAC, and a notification engine (verified dialect, per the in-flight `scholiq-notifications` migration).

Constraints honoured:

- Storage, RBAC, versioning, notifications all come from OpenRegister (ADR-022); no app-local store, no PHP CRUD controllers.
- The notification dialect is owned by `scholiq-notifications` — this change *uses* it (one `scheduled` rule), it does not redefine it.
- LearnerProfile's pseudonymisation fields (`bsnEncrypted`, `eckId`, `schoolId`) are referenced descriptively in seed entries (categories of personal data); the register never copies their values.
- Document generation (a styled PDF verwerkingsregister) is DocuDesk's job, mirroring grading's "DocuDesk does templating" stance; in-app export is CSV/JSON.

## Goals / Non-Goals

**Goals:**
- Make the info.xml "AVG record-of-processing" claim true before the production notice lifts.
- Art. 30(1)-complete `ProcessingActivity` schema with lifecycle and audit-trail-backed history.
- Ship Scholiq's own processing as reviewable seed entries (the "privacy bijsluiter, but live" differentiator).
- Cyclical review with a verified-dialect reminder.
- Art. 30(4) export: CSV/JSON on demand + inclusion in the compliance audit-pack ZIP.

**Non-Goals:**
- DPIA authoring/workflow (only a `dpiaRequired` flag + external reference).
- Verwerkersovereenkomst (DPA) contract management.
- Consent management / datalek (breach) register — separate capabilities if ever needed.
- Styled PDF output (DocuDesk).
- Auto-discovery of processing activities from schema introspection (V2 idea; seeds are hand-curated).

## Decisions

**D1: One schema, jurisdiction-neutral fields, Dutch profile in labels.**
`ProcessingActivity` carries the Art. 30(1) elements with English field names (per fleet convention; i18n keys are English source strings) and nl/en UI labels. Controller-vs-processor role is a field (`role: controller|processor`) so a corporate tenant acting as processor can keep its Art. 30(2) register in the same schema.

**D2: Legal basis is an Art. 6 enum, special categories flagged.**
`legalBasis: consent|contract|legal-obligation|vital-interests|public-task|legitimate-interest` plus `legitimateInterestAssessment` free text when `legitimate-interest` is chosen, and `specialCategories: bool` + `specialCategoriesBasis` (Art. 9 ground) when special data is processed. Schools mostly run on `legal-obligation`/`public-task`; the seeds set this correctly.

**D3: Seeds are drafts, never auto-active.**
Pre-filled entries describe what *Scholiq* does, but the *school* is the controller and must own the register's content. Activation is an explicit lifecycle transition by the privacy officer; the transition is the point where audit-trail-backed immutability semantics start to matter.

**D4: Versioning = OR audit trail + object versions, no shadow history schema.**
ADR-008's rule is "consume the OR audit trail"; mutations of an `active` entry are ordinary OR updates whose before/after live in the trail. No `ProcessingActivityVersion` schema.

**D5: Review reminder is a single `scheduled` rule in the verified dialect.**
`trigger: {type: "scheduled", intervalSec: 86400, filter: {lifecycle: "active"}}`, recipient `{kind: "field", field: "ownerUserId"}`, with the engine evaluating the `nextReviewAt` window per run — the same collapse-to-daily-scheduled pattern `scholiq-notifications` chose for enrolment due reminders. No legacy-dialect keys.

**D6: Export lives next to the audit-pack exporter.**
The audit-pack ZIP writer (compliance-audit) gains one artefact: `verwerkingsregister.csv` over active entries. The standalone Art. 30(4) export is the same writer invoked from the ProcessingActivity index page.

**D7: RBAC via OR groups, new `privacy-officer` group.**
Mirrors the `compliance-officer` tenant-role→NC-group mapping convention from `scholiq-notifications`. Deployment must provision the group; documented in the same place as the other group mappings.

## Risks / Trade-offs

- **Claim-vs-scope creep** — Art. 30 is a register, not a full IBP suite; the Non-Goals fence (no DPIA workflow, no DPA management) keeps this a thin, shippable slice. If review pushes for more, the claim is already satisfied by this slice.
- **Seed accuracy** — pre-filled entries that misdescribe Scholiq's processing are worse than none. Mitigation: seeds are derived from the actual register schemas (`linkedSchemas[]` pointing at LearnerProfile, AttendanceRecord, GradeEntry, Attestation, DataExchangeJob, AiFeature) and reviewed against ADR-008's data inventory before merge.
- **Engine absence** — on instances without the OR notification engine the review reminder is silently inert (same posture as every `scholiq-notifications` rule). Acceptable; the `nextReviewAt` date is still visible in the UI.
- **Group provisioning** — `privacy-officer` group may not exist in a deployment; same caveat and remedy as `scholiq-notifications`' group mappings.

## Migration Plan

1. Add `ProcessingActivity` schema (+ lifecycle, relations, notification rule) to `lib/Settings/scholiq_register.json`.
2. Add seed objects to the register import payload (draft lifecycle).
3. Add manifest pages (index + detail) and Compliance navigation entry.
4. Extend the audit-pack exporter with the verwerkingsregister CSV artefact + expose the standalone export action.
5. Bump `appinfo/info.xml` version (bundle-affecting; NC immutable-cache rule).
6. Existing installs: register re-import on upgrade adds the schema and seeds (Repair-step path); no data migration — the schema is new.

Rollback: remove the schema + seeds from the register JSON and the manifest pages; no existing data is touched.

## Open Questions

- Should `retired` entries appear in the Art. 30 export? (Lean: no for the standalone export, yes in the audit pack with their retirement date — auditors care about history.) Decide at apply time with the compliance-audit owner.
