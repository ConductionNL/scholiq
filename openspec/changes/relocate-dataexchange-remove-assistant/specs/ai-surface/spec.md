# ai-surface Specification (delta)

Phase B removes the inherited generic **Assistant** AI-chat surface from Scholiq. The `AssistantMenu` nav entry, the `Assistant` chat page, its settings-foldout entry and the nc-vue "Open AI chat" floating action button are all removed. The EU AI Act `AiFeature` governance register (REQ-SAI-002 / REQ-SAI-004) is a separate concern and is unaffected; REQ-SAI-003 is narrowed to drop the now-removed Assistant page from its retained-pages set.

## REMOVED Requirements

### Requirement: REQ-SAI-001 — The system SHALL expose exactly one interactive AI nav entry, "Assistant"

**Reason**: The interactive "Assistant" chat is the generic AI companion every Conduction app inherits from nc-vue; it is not part of Scholiq's education scope (ADR-009) and duplicates capability better served by the EU AI Act governance register. Phase B removes the Assistant chat surface entirely, so a requirement mandating exactly one Assistant nav entry no longer applies.

**Migration**: No user data is affected. The `AssistantMenu` menu entry, the `Assistant` (`/assistant`) chat page and the nc-vue chat FAB are removed (see REQ-SAI-005). The AiFeature governance register and its reachability (REQ-SAI-002 / REQ-SAI-003 / REQ-SAI-004) are unchanged.

## MODIFIED Requirements

### Requirement: REQ-SAI-003 — The system SHALL keep the AI features register and Assistant pages routable
The system SHALL retain the `AiFeatures` (`/ai-features`) and `AiFeatureDetail` (`/ai-features/:id`) page objects in `src/manifest.json.pages[]` unchanged, so deep links and the `KpiSchemasWidget` link to `/ai-features` continue to resolve even though the "AI features" menu entry is removed. The `Assistant` (`/assistant`) page is no longer part of this retained set — it is removed by this change (see REQ-SAI-005).
<!-- @e2e exclude AI-features governance reachability is unchanged by this change; covered by the existing ai-surface e2e. This requirement is re-affirmed here only to drop the now-removed Assistant page from the retained-pages set. -->

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

## ADDED Requirements

### Requirement: REQ-SAI-005 — The system SHALL NOT present an inherited Assistant AI-chat surface
The system SHALL NOT present the inherited generic "Assistant" AI-chat surface. Specifically: `src/manifest.json.menu[]` SHALL contain no entry with `id: "AssistantMenu"`; `src/manifest.json.pages[]` SHALL contain no page with `id: "Assistant"` (`route: "/assistant"`, `type: "chat"`); `src/menu-layout.json#settingsSection` SHALL NOT list `AssistantMenu`; and consequently the nc-vue `CnAppRoot` floating "Open AI chat" FAB — which renders only while a `type: "chat"` page is declared — SHALL NOT be rendered. This removal does not touch the EU AI Act `AiFeature` governance register.
<!-- @e2e exclude Absence / static-manifest / nc-vue-FAB assertions — verified by the manifest unit test (no `AssistantMenu` menu id, no `Assistant` page, no `AssistantMenu` in settingsSection) and an in-browser check of the removed FAB at apply; not positive route-smoke DOM behaviours. -->

#### Scenario: Assistant menu entry is absent
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `menu[]` array is inspected
- **THEN** no entry with `id: "AssistantMenu"` is present

#### Scenario: Assistant chat page is absent
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `pages[]` array is inspected
- **THEN** no page with `id: "Assistant"` (`route: "/assistant"`, `type: "chat"`) is present
- **AND** `src/menu-layout.json#settingsSection` does not list `AssistantMenu`

#### Scenario: No "Open AI chat" FAB is rendered
- **GIVEN** the Scholiq app shell has no `type: "chat"` page declared
- **WHEN** any Scholiq page is rendered
- **THEN** nc-vue's `CnAppRoot` renders no floating "Open AI chat" action button
