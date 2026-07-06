# Tasks: ai-feature-delegate-to-hermiq

<!-- Hydra cap: MAX 20 unindented `- [ ]` lines. This file has 7 tasks × 2 = 14. -->
<!-- Delegation of EU AI Act AiFeature governance from scholiq to hermiq. -->

## Implementation Tasks

### Task 1: Remove the local AiFeature governance pages
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-006-the-system-shall-delegate-ai-feature-governance-to-hermiq`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json.pages[]` WHEN inspected THEN no page with `id: "AiFeatures"` (`/ai-features`, `type: "index"`) or `id: "AiFeatureDetail"` (`/ai-features/:id`, `type: "detail"`) remains
  - GIVEN the manifest WHEN validated THEN it parses and the page count drops by two (95 pages)
- [x] Implement
- [x] Test

### Task 2: Strip the AiFeature schema to the AVG Art. 30 carrier
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-004-the-system-shall-surface-ai-feature-governance-from-settings-via-hermiq`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the `AiFeature` schema WHEN inspected THEN it carries no `x-openregister-lifecycle` and no governance properties (`riskCategory`, `lifecycle` governance, DPO-ack), only `slug`/`name`/`description`
  - GIVEN the `AiFeature` schema WHEN inspected THEN it retains its `x-openregister-processing` catalogue annotation with `code: "scholiq-ai-features"` (verwerkingsregister stays at seven activities)
  - GIVEN `ProcessingActivityCatalogueTest` WHEN run THEN it passes (seven activities, all catalogue fields present)
- [x] Implement
- [x] Test

### Task 3: Remove the DPO-acknowledgement guard
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-006-the-system-shall-delegate-ai-feature-governance-to-hermiq`
- **files**: `lib/Lifecycle/AiFeatureDpoAckGuard.php` (removed), `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the repo WHEN inspected THEN `lib/Lifecycle/AiFeatureDpoAckGuard.php` no longer exists
  - GIVEN `lib/AppInfo/Application.php` WHEN inspected THEN its lifecycle-guard comment references `AssessmentPublishGuard`, not the removed `AiFeatureDpoAckGuard`
- [x] Implement
- [x] Test

### Task 4: Delegate the AI-proctoring DPO gate to Hermiq
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-006-the-system-shall-delegate-ai-feature-governance-to-hermiq`
- **files**: `lib/Lifecycle/AssessmentPublishGuard.php`, `lib/Proctoring/ProvidesProctoring.php`, `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN an Assessment with `proctoring.flagReviewMode: "ai-assisted"` WHEN published THEN the guard queries Hermiq's register (`register=hermiq`, `schema=agentaifeature`, `slug=assessment-ai-proctor-review`, `lifecycle=enabled`), not scholiq's
  - GIVEN Hermiq is not installed WHEN such an assessment is published THEN the guard blocks and logs an "install Hermiq" message (fail closed); GIVEN Hermiq is installed but the feature is not enabled THEN it blocks and logs a "DPO-enable it in Hermiq" message
  - GIVEN `flagReviewMode: "manual"` (or unset) WHEN published THEN only the itemRefs check applies (no Hermiq lookup) — behaviour unchanged
- [x] Implement
- [x] Test

### Task 5: Rework the Admin Settings "AI Features" section to delegate to Hermiq
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-004-the-system-shall-surface-ai-feature-governance-from-settings-via-hermiq`
- **files**: `src/views/ScholiqSettings.vue`
- **acceptance_criteria**:
  - GIVEN the Admin Settings page WHEN Hermiq is installed THEN the "AI Features" section shows an "Open the AI-feature register in Hermiq" button that full-navigates to `generateUrl('/apps/hermiq') + '/ai-features'`
  - GIVEN Hermiq is not installed THEN the section shows an "install and enable Hermiq" `NcNoteCard` (no hard dependency; no crash)
  - GIVEN the same page THEN the AVG Art. 30 `scholiq-ai-features` processing block remains rendered; per ADR-004 all strings use `t('scholiq', …)`, no local AiFeature table or fetch remains
- [x] Implement
- [x] Test

### Task 6: Add nl + en strings for the delegated section
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-004-the-system-shall-surface-ai-feature-governance-from-settings-via-hermiq`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the new section strings ("AI Features", the delegation description, "Open the AI-feature register in Hermiq", the install notice) WHEN added THEN English (`en`) and Dutch (`nl`) values exist for each
  - GIVEN i18n keys WHEN authored THEN keys are the ENGLISH source string, never Dutch
- [x] Implement
- [x] Test

### Task 7: Update ADR-005, e2e smoke list, demo seed, and remove the dead widget
- **spec_ref**: `openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-006-the-system-shall-delegate-ai-feature-governance-to-hermiq`
- **files**: `openspec/architecture/ADR-005-eu-ai-act-gating.md`, `tests/e2e/index-pages.spec.ts`, `tests/e2e/seed-example-data.mjs`, `src/views/widgets/KpiSchemasWidget.vue` (removed)
- **acceptance_criteria**:
  - GIVEN `ADR-005` WHEN read THEN a 2026-07-06 amendment records that AI-feature governance is delegated to Hermiq, what remains in Scholiq (AVG carrier + proctoring gate + settings link), and that the pattern below is now Hermiq's
  - GIVEN `tests/e2e/index-pages.spec.ts` WHEN inspected THEN `AiFeature` is no longer in the index-page smoke set; GIVEN `tests/e2e/seed-example-data.mjs` THEN no `ai-feature` object is seeded
  - GIVEN the repo WHEN inspected THEN `src/views/widgets/KpiSchemasWidget.vue` (whose only link targeted `/ai-features`) no longer exists
- [x] Implement
- [x] Test

## Quality checklist

<!-- Reminders for the builder — plain bullets, NOT tracked checkboxes. -->

- Acceptance: no local `/ai-features` pages, no `AiFeatureDpoAckGuard`, no local admin AI-features table; minimal `AiFeature` AVG carrier retained; `AssessmentPublishGuard` reads from Hermiq and fails closed gracefully; settings link/notice depends on `hermiqInstalled`.
- **AVG compliance preserved**: `scholiq-ai-features` processing activity retained; seven activities; `ProcessingActivityCatalogueTest` + `SchemaSlugRegressionTest` pass (16 tests, 389 assertions).
- **No hard dependency**: no `<app>hermiq</app>` in `appinfo/info.xml`; Scholiq boots and runs with Hermiq absent.
- ADR-031: the proctoring gate keeps its lifecycle-guard seam (legitimate PHP); no new service class.
- ADR-004 for the Vue: `NcSettingsSection` idiom, `t()` for all strings, full-navigation link (no `$router` on the router-less Admin Settings mount).
- Dutch + English strings for all new labels; i18n keys are English source (ADR-005/ADR-007).
- `nc-immutable` cache-bust: bump `appinfo/info.xml` `<version>` so the rebuilt bundle is served.
- `openspec validate ai-feature-delegate-to-hermiq --strict` passes.

## Verification

- [x] All tasks checked off
- [x] `composer lint` + `phpcs` (errors) clean on changed PHP; `AiFeature`-contract unit tests pass (16 tests, 389 assertions)
- [x] `npm run lint` (0 errors) + `npm run build` (green) — delegation settings bundle compiles
- [ ] Manual browser check: Admin Settings "AI Features" section links to Hermiq (installed) / shows install notice (absent); `/ai-features` no longer a Scholiq page; AVG Art. 30 AI block still shown
