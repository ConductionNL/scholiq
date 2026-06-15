# Design — Merge Scholiq AI surfaces

## Problem

Two top-level nav entries both present as "the AI section" and sit adjacent under the `settings` section:

| Menu id          | Label       | Route       | Page type | Backing            | What it actually is |
|------------------|-------------|-------------|-----------|--------------------|---------------------|
| `AiFeaturesMenu` | AI features | `AiFeatures`| `index`   | schema `AiFeature` | EU AI Act high-risk **feature-declaration register** (governance/config); DPO-ack-gated lifecycle; already partly inside ScholiqSettings.vue |
| `AssistantMenu`  | Assistant   | `Assistant` | `chat`    | none (chat UI)     | Interactive **LLM chat companion** (ADR-034) |

A user scanning the menu sees "AI features" and "Assistant" next to each other and cannot tell which one is "the AI thing". They are different in *function* but redundant in *information architecture* — both claim the AI label, both are filed under `settings`.

## Decision

Apply the docudesk IA model: **interactive surfaces stay as a nav entry; config/governance/definitions move under Settings.**

1. **Single interactive AI entry = "Assistant".** Keep `AssistantMenu` → `Assistant` (the `chat` page). This is the one AI item users see in the menu. Promote it out of the `settings` section onto the main nav list so it reads as a primary interactive surface rather than a settings sub-item.
2. **AI features → Settings.** Delete the standalone `AiFeaturesMenu` array entry. The `AiFeature` register becomes a sub-section of the existing `Settings` page (`section-scholiq` slot, `ScholiqSettings.vue`), which already calls `fetchAiFeatures()` and renders the `scholiq-ai-features` AVG Art. 30 block. Surface a "Manage AI features" affordance there that deep-links to the still-routable `/ai-features` register view.
3. **Keep every page routable.** No page object is removed from `manifest.json.pages[]`. `AiFeatures` (`/ai-features`), `AiFeatureDetail` (`/ai-features/:id`) and `Assistant` (`/assistant`) all remain. `KpiSchemasWidget`'s `link="/ai-features"` and any bookmarked deep links continue to resolve.

### Exact manifest edits (`src/manifest.json`)

| File | Location | Edit |
|------|----------|------|
| `src/manifest.json` | `menu[]` entry `AiFeaturesMenu` (label "AI features", route `AiFeatures`, section `settings`, order 94) | **REMOVE** the menu array entry |
| `src/manifest.json` | `menu[]` entry `AssistantMenu` (route `Assistant`, order 92, section `settings`) | **MODIFY** — drop `"section": "settings"` so Assistant is a primary nav entry; keep `id`/`label`/`icon`/`route` |
| `src/manifest.json` | `pages[]` `AiFeatures` (`/ai-features`), `AiFeatureDetail` (`/ai-features/:id`), `Assistant` (`/assistant`) | **UNCHANGED** — stay routable |
| `src/views/ScholiqSettings.vue` | `section-scholiq` content (already loads `aiFeatures`) | **MODIFY** — add a "Manage AI features" sub-section / link to `/ai-features` so the register is reachable from Settings now that its top-level menu item is gone |

No `AiFeature` schema change, no new page, no new route, no new component.

## Alternatives considered

- **Merge into a single "AI" entry that hosts both chat and the register.** Rejected: conflates an interactive chat surface with a governance register; the `chat` page type and an `index` register page are different page types and cannot be one page without inventing a new composite component (scope creep, and it would *hide* the EU AI Act governance register behind a chat UI, which is the wrong place for compliance config).
- **Keep "AI features" top-level, remove "Assistant".** Rejected: Assistant is the user-facing interactive value (ADR-034); the feature register is administrator/DPO governance config that, per the IA model, belongs under Settings.
- **Delete the `AiFeatures` pages entirely and only keep the ScholiqSettings block.** Rejected: `KpiSchemasWidget` deep-links to `/ai-features` and the detail route gives per-feature DPO-ack context; dropping the routes would break links and lose the detail view. Pages stay routable.

## Migration / rollout

Pure IA — no data migration, no repair step. The `AiFeature` objects in OpenRegister are untouched. Rollout is a manifest edit + a Settings UI affordance:

1. Remove `AiFeaturesMenu` from `manifest.json.menu[]`.
2. Unset `section: "settings"` on `AssistantMenu`.
3. Add the "Manage AI features" link/sub-section in `ScholiqSettings.vue` pointing at `/ai-features`.

Fully reversible (re-add the menu entry). No backend deploy required.

## Risks

- **Discoverability of the AI features register drops** once its top-level menu item is gone. Mitigation: the Settings page already loads and shows the AVG Art. 30 AI block; this change adds an explicit "Manage AI features" affordance there, and the KpiSchemasWidget link is unchanged.
- **Deep links** to `/ai-features` must keep resolving. Mitigation: the `AiFeatures` + `AiFeatureDetail` page objects are explicitly retained — verified routable.
- **Assistant promoted to primary nav** could surprise users who expected it under settings. Acceptable: ADR-034 frames the chat companion as a primary interactive surface; this aligns the menu with that intent.
