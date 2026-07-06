---
kind: code
---

# Proposal: ai-feature-delegate-to-hermiq

## Summary

Delegate Scholiq's **EU AI Act high-risk AI-feature governance** to the fleet-wide **Hermiq** app. Scholiq stops owning an AI-feature governance register: the `AiFeature` governance schema (lifecycle + DPO-acknowledgement), the `AiFeatureDpoAckGuard`, the `/ai-features` register/detail pages, and the local admin "Manage AI features" table are removed. In their place, the Admin Settings "AI Features" section **links out to Hermiq's central register** when Hermiq is installed, or shows an "install and enable Hermiq" notice when it is not — a graceful delegation with **no hard dependency** (Scholiq never fatals when Hermiq is absent). A **minimal `AiFeature` schema is retained solely as the AVG Art. 30 processing-activity carrier** (`scholiq-ai-features`), so Scholiq's verwerkingsregister stays at seven activities. The `AssessmentPublishGuard`'s Art. 14 / ADR-005 DPO gate for AI-assisted proctoring is preserved but now **sources its approval from Hermiq's central register**, failing closed with clear guidance when Hermiq is unavailable.

## Motivation

Every Conduction app inherited a local EU AI Act governance surface. Governing high-risk AI per-app duplicates the register, its DPO-acknowledgement lifecycle, and its dossier/monitoring machinery across the fleet, and fragments the source of truth. Hermiq is now the fleet-wide home for AI governance and routing (its `agentaifeature` register already ships the DPO-ack lifecycle). Consolidating AI-feature governance there gives a single register, one DPO workflow, and one place to answer "is this high-risk feature approved?" — while Scholiq keeps only what is genuinely its own: the AVG Art. 30 declaration of the processing it performs, and the domain rule that an AI-proctored assessment may not be published until the feature is DPO-approved.

This stacks on the merged Phase A (`nav-restructure-dashboards`) and Phase B (`relocate-dataexchange-remove-assistant`) navigation cleanups. Phase B explicitly left the `AiFeature` governance register in place as a separate concern; this change is that separate concern.

## Affected Projects

- [ ] Project: `scholiq` — remove the local AiFeature governance register (schema governance fields, lifecycle, DPO-ack guard), the `/ai-features` pages, and the local admin AI-features table; retain a minimal `AiFeature` schema as the AVG Art. 30 carrier; re-point `AssessmentPublishGuard` at Hermiq's central register; rework the Admin Settings "AI Features" section to link to Hermiq (graceful, no hard dependency).

No other apps-extra project is touched by this change. Hermiq's `agentaifeature` register (the delegation target) already exists and is unchanged here. No OpenRegister, OpenConnector, or shared-schema change.

## Scope

### In Scope

1. **Remove the local AiFeature governance pages.** Drop `AiFeatures` (`/ai-features`) and `AiFeatureDetail` (`/ai-features/:id`) from `src/manifest.json.pages[]`.
2. **Strip the AiFeature schema to an AVG carrier.** In `lib/Settings/scholiq_register.json`, remove the governance body of the `AiFeature` schema (`x-openregister-lifecycle`, `x-openregister-notifications`, governance properties) and retain only `slug`/`name`/`description` + the `x-openregister-processing` catalogue annotation (`scholiq-ai-features`). Bump the schema version.
3. **Remove the DPO-ack guard.** `git rm lib/Lifecycle/AiFeatureDpoAckGuard.php` (the enable-transition guard for the now-removed governance lifecycle).
4. **Delegate the proctoring DPO gate to Hermiq.** `AssessmentPublishGuard` now looks up the `assessment-ai-proctor-review` feature in Hermiq's `agentaifeature` register (`register=hermiq`), fails closed when Hermiq is not installed (distinct "install Hermiq" vs "DPO-enable it" log), and leaves manual-proctoring and every other transition untouched.
5. **Rework the Admin Settings "AI Features" section.** `ScholiqSettings.vue` links out to Hermiq's `/ai-features` register when `hermiqInstalled`, else renders an "install and enable Hermiq" `NcNoteCard`. The AVG Art. 30 processing block on the same page is unchanged.
6. **Housekeeping.** Remove the unused `KpiSchemasWidget.vue` (its only link targeted `/ai-features`); update the `Application.php` and `ProvidesProctoring.php` doc comments; amend `ADR-005`; drop `AiFeature` from the e2e index-page smoke list and the demo `ai-feature` seed.

### Out of Scope

- **Hermiq's `agentaifeature` register** — the delegation target already exists (its own change) and is not modified here.
- The **AVG Art. 30 verwerkingsregister** contract — the `scholiq-ai-features` processing activity is retained (still seven activities); `ProcessingActivityCatalogueTest` remains the guard.
- The **Assistant** chat surface — already removed by Phase B (`relocate-dataexchange-remove-assistant`).
- Any **runtime AI inference** feature — none ship; this change is governance-surface only.

## Approach

Declarative manifest + register edits, one Vue rework, one PHP guard re-point, and one guard deletion. The proctoring gate keeps its ADR-031 lifecycle-guard seam but reads cross-app from Hermiq; when Hermiq is absent the OR query naturally returns empty and the `IAppManager` check turns that into a precise "install Hermiq" log. The settings link uses full navigation (`generateUrl('/apps/hermiq') + '/ai-features'`), mirroring the prior "Manage AI features" affordance. See design.md.

## New Dependencies

None. Hermiq is an **optional** runtime peer: present → the proctoring gate and the settings link resolve against it; absent → the gate fails closed for the AI-proctoring path only and the settings section shows an install notice. Scholiq's `appinfo/info.xml` gains **no** `<app>hermiq</app>` hard dependency.

## Impact

- `src/manifest.json` — remove two `pages[]` entries (AiFeatures, AiFeatureDetail).
- `lib/Settings/scholiq_register.json` — strip the `AiFeature` schema to the AVG carrier; update two Assessment descriptions.
- `lib/Lifecycle/AssessmentPublishGuard.php` — re-point the DPO-gate lookup at Hermiq; inject `IAppManager`.
- `lib/Lifecycle/AiFeatureDpoAckGuard.php`, `src/views/widgets/KpiSchemasWidget.vue` — deleted.
- `src/views/ScholiqSettings.vue` — Hermiq-delegating AI-Features section (`hermiqInstalled`, `openHermiqAiFeatures`).
- `l10n/en.json`, `l10n/nl.json` — three new strings (section name, description, install notice + open button).
- `openspec/architecture/ADR-005-eu-ai-act-gating.md` — delegation amendment.
- `tests/e2e/index-pages.spec.ts`, `tests/e2e/seed-example-data.mjs` — drop the removed AiFeature index page + demo seed.

## Cross-Project Dependencies

None hard. Runtime **soft** dependency on Hermiq's `agentaifeature` register (`slug=assessment-ai-proctor-review`, `lifecycle=enabled`) for the proctoring DPO gate; absence is handled gracefully.

## Risks

### Risk 1: AI-proctored assessments cannot be published until Hermiq governs the feature
- **Severity**: Medium
- **Mitigation**: Intended and correct under the EU AI Act — high-risk AI proctoring must be DPO-governed, and that governance now lives in Hermiq. Fails closed for the `ai-assisted` path only; manual proctoring (the default) is unaffected. The block logs precise guidance ("install Hermiq" / "DPO-enable it in Hermiq"). Documented in ADR-005's amendment.

### Risk 2: Removing the AiFeature schema would drop an AVG Art. 30 activity
- **Severity**: High (compliance)
- **Mitigation**: The schema is **not** removed — only its governance body is. The `x-openregister-processing` (`scholiq-ai-features`) annotation is retained, keeping the verwerkingsregister at seven activities. `ProcessingActivityCatalogueTest` (16 tests, 389 assertions) passes.

### Risk 3: Cross-app coupling to Hermiq's register slug
- **Severity**: Low
- **Mitigation**: The coupling is localised to one guard with explicit constants and docs (`HERMIQ_REGISTER`, `HERMIQ_AI_FEATURE_SCHEMA`). A future Hermiq capability API can replace the direct OR query without changing the contract.

## Rollback Strategy

Revert the single frontend+backend commit. The retained minimal `AiFeature` schema means no register re-seed is needed to roll back the pages/guard. No user data is migrated (governance objects live in Hermiq, not Scholiq).

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `ai-surface`: the local EU AI Act `AiFeature` governance register, its `/ai-features` pages, and the local admin table are removed and delegated to Hermiq (REQ-SAI-002/003/004 removed or modified); a minimal `AiFeature` AVG-carrier schema and the `scholiq-ai-features` processing block are retained; the Admin Settings AI-Features section links to Hermiq or shows an install notice; the `AssessmentPublishGuard` DPO gate sources approval from Hermiq (new REQ-SAI-006).
