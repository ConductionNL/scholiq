# Proposal: scholiq-merge-ai-surfaces

kind: information-architecture (nav consolidation) — cites ADR-034 (AI chat companion), ADR-037 (modular config fragments / canonical REQ-ID), ADR-012 (deduplication)

## Summary

Scholiq currently exposes **two** top-level navigation entries that both read as "the AI section":

- **`AiFeaturesMenu` → "AI features"** (route `AiFeatures`, page type `index`, schema `AiFeature`) — a **governance / compliance register**. `AiFeature` is an EU AI Act high-risk *feature declaration* (slug, name, riskCategory, lifecycle) whose `disabled → enabled` transition is gated by a DPO acknowledgement (`AiFeatureDpoAckGuard`). It is `section: "settings"` already, it is *already half-surfaced inside* `ScholiqSettings.vue` (`fetchAiFeatures()`, the AVG Art. 30 / `scholiq-ai-features` processing block), and `KpiSchemasWidget` links to `/ai-features`.
- **`AssistantMenu` → "Assistant"** (route `Assistant`, page type `chat`) — the **interactive LLM chat companion** (ADR-034). This is a conversational surface, not a register.

These two are **not two views of the same capability**, but to a user they collide: two entries that both say "AI", both filed under the `settings` section, sitting next to each other (`order: 92` and `94`). "AI features" is *governance config* about which AI features are switched on and acknowledged; "Assistant" is the *thing the user talks to*. Per the docudesk IA model (config/definitions/governance belong under a Settings group; transactional/interactive surfaces stay top-level) the clean split is:

- **Keep one interactive AI entry: "Assistant"** (the chat companion) as the single AI nav item users see.
- **Fold "AI features" governance into Settings** — remove the standalone `AiFeaturesMenu` nav entry and surface the AiFeature register from within the existing `Settings` page (`section-scholiq` → `ScholiqSettings`), which already loads it. The `AiFeatures` / `AiFeatureDetail` pages stay fully routable (`/ai-features`, `/ai-features/:id`) so the KpiSchemasWidget link and any deep links keep working.

Net effect: from two AI-labelled nav items down to **one** ("Assistant"), with the EU AI Act feature register living where governance config belongs (Settings), and zero pages removed from the router.

**Depends on:** scholiq `AiFeature` schema (`lib/Settings/scholiq_register.json`), `src/manifest.json` (single-file manifest — this app has no `src/manifest.d/` split and no `src/menu-layout.json`; nav entries are edited directly in `manifest.json.menu[]` like the decidesk pattern), `ScholiqSettings.vue` (already fetches `aiFeatures`). No backend/schema change.

## Deduplication rationale (ADR-012)

Phase 0 (see `tasks.md`) confirmed there is exactly one `AiFeature` schema and one `Assistant` chat page — this change does **not** create any new schema, page, route, or component. It is a pure information-architecture consolidation: it deletes one redundant menu array entry (`AiFeaturesMenu`) and re-homes its content under the existing Settings surface that already renders it. No data is migrated, no capability is duplicated, no route is dropped. The two surfaces are *kept distinct in function* (governance register vs. chat) but *merged in IA* so the user sees a single coherent "AI" area: Assistant to interact, Settings → AI features to govern.
