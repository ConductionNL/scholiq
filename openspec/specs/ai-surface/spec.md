# ai-surface Specification

## Purpose
TBD - created by archiving change scholiq-merge-ai-surfaces. Update Purpose after archive.
## Requirements
### Requirement: REQ-SAI-001 — The system SHALL expose exactly one interactive AI nav entry, "Assistant"
The system SHALL present exactly one top-level navigation entry for the interactive AI companion, the `AssistantMenu` entry labelled "Assistant" routing to the `Assistant` chat page (`/assistant`), per ADR-034. The system SHALL NOT present a second top-level AI-labelled nav entry alongside it.

#### Scenario: Only one AI nav entry is shown
- **GIVEN** the Scholiq app navigation menu is rendered
- **WHEN** a user scans the menu
- **THEN** exactly one AI-labelled entry, "Assistant", is present
- **AND** no "AI features" top-level menu entry appears

#### Scenario: Assistant opens the chat companion
- **GIVEN** the "Assistant" nav entry
- **WHEN** the user activates it
- **THEN** the `Assistant` chat page at `/assistant` renders the LLM chat companion

### Requirement: REQ-SAI-002 — The system SHALL remove the standalone "AI features" nav entry
The system SHALL remove the `AiFeaturesMenu` menu array entry from `src/manifest.json.menu[]` so that the "AI features" governance register is no longer a standalone top-level (or settings-section) navigation item.

#### Scenario: AiFeaturesMenu is absent from the menu
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `menu[]` array is inspected
- **THEN** no entry with `id: "AiFeaturesMenu"` is present

### Requirement: REQ-SAI-003 — The system SHALL keep the AI features register and Assistant pages routable
The system SHALL retain the `AiFeatures` (`/ai-features`), `AiFeatureDetail` (`/ai-features/:id`), and `Assistant` (`/assistant`) page objects in `src/manifest.json.pages[]` unchanged, so deep links and the `KpiSchemasWidget` link to `/ai-features` continue to resolve even though the "AI features" menu entry is removed.

#### Scenario: AI features deep link still resolves
- **GIVEN** the "AI features" menu entry has been removed
- **WHEN** a user navigates directly to `/ai-features`
- **THEN** the `AiFeatures` index page renders the `AiFeature` register

#### Scenario: AI feature detail deep link still resolves
- **GIVEN** an `AiFeature` object id
- **WHEN** a user navigates to `/ai-features/:id`
- **THEN** the `AiFeatureDetail` page renders that feature's DPO-ack lifecycle context

#### Scenario: KpiSchemasWidget link still works
- **GIVEN** the dashboard `KpiSchemasWidget` whose `link` targets `/ai-features`
- **WHEN** the link is followed
- **THEN** the `AiFeatures` register page loads

### Requirement: REQ-SAI-004 — The system SHALL surface the AI features register from Settings
The system SHALL make the EU AI Act `AiFeature` register reachable from the existing `Settings` page (`section-scholiq` slot, `ScholiqSettings.vue`), which already loads the AI features, by providing a "Manage AI features" affordance that deep-links to `/ai-features`. This keeps the governance register discoverable now that its standalone menu entry is gone, consistent with the IA model that config/governance belongs under Settings.

#### Scenario: AI features reachable from Settings
- **GIVEN** a user on the Scholiq `Settings` page
- **WHEN** they view the `section-scholiq` content
- **THEN** a "Manage AI features" affordance is shown that links to `/ai-features`

#### Scenario: Settings still shows the AVG Art. 30 AI processing block
- **GIVEN** the Scholiq `Settings` page is open
- **WHEN** the AVG Art. 30 processing register is rendered
- **THEN** the `scholiq-ai-features` AI-assisted learning processing block remains visible

