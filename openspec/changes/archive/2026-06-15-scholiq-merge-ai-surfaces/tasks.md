# Tasks — Merge Scholiq AI surfaces

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm there is exactly one chat companion surface: `menu[].AssistantMenu` → page `Assistant` (`/assistant`, type `chat`). No second chat page exists.
- [x] Confirm "AI features" is a governance/config register, not a second chat: `menu[].AiFeaturesMenu` → page `AiFeatures` (`/ai-features`, type `index`) backed by schema `AiFeature` (EU AI Act high-risk feature declaration; slug/name/riskCategory/lifecycle; DPO-ack-gated via `AiFeatureDpoAckGuard`).
- [x] Confirm the AiFeature register is already partially surfaced under Settings: `ScholiqSettings.vue` calls `fetchAiFeatures()` and renders the `scholiq-ai-features` AVG Art. 30 processing block; `KpiSchemasWidget` links to `/ai-features`.
- [x] Confirm scope is pure IA: NO new schema, page, route, or component is created; NO `AiFeature` data is migrated. The change only deletes the redundant `AiFeaturesMenu` nav entry and re-homes its content under the existing Settings surface, keeping all pages routable.
- [x] Confirm the app has NO `src/menu-layout.json` and NO `src/manifest.d/` split — nav lives directly in `src/manifest.json.menu[]` (decidesk-style direct edit).

## Phase 1: Consolidate the AI nav into one interactive entry

- [x] In `src/manifest.json`, REMOVE the `menu[]` entry `AiFeaturesMenu` (label "AI features", route `AiFeatures`, section `settings`, order 94).
- [x] In `src/manifest.json`, MODIFY the `menu[]` entry `AssistantMenu`: drop `"section": "settings"` so Assistant becomes a primary nav entry; keep `id`, `label` ("Assistant"), `icon` (`icon-comment`), `route` (`Assistant`).
- [x] Verify the menu now shows exactly ONE AI-labelled entry ("Assistant") and no "AI features" top-level item.

## Phase 2: Keep the AI features register reachable from Settings

- [x] Confirm `pages[]` entries `AiFeatures` (`/ai-features`), `AiFeatureDetail` (`/ai-features/:id`), and `Assistant` (`/assistant`) are UNCHANGED and remain routable.
- [x] In `src/views/ScholiqSettings.vue`, add a "Manage AI features" sub-section / link under the `section-scholiq` content that deep-links to `/ai-features` (the register already loaded by `fetchAiFeatures()`), so the EU AI Act feature register is discoverable from Settings now that its top-level menu item is gone.
- [x] Confirm `KpiSchemasWidget`'s `link="/ai-features"` still resolves (route retained).

## Phase 3: Verify

- [x] `cd scholiq && openspec validate scholiq-merge-ai-surfaces --strict` passes.
- [x] Manually verify: one "Assistant" nav entry, no "AI features" nav entry; `/assistant`, `/ai-features`, `/ai-features/:id` all load; Settings shows the AI features affordance.
