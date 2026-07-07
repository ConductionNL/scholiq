# Design: ai-feature-delegate-to-hermiq

## Context

Scholiq inherited a full EU AI Act governance surface (ADR-005): an `AiFeature` schema with a `disabled → enabled` DPO-acknowledgement lifecycle, the `AiFeatureDpoAckGuard`, `/ai-features` register + detail pages, a dossier/monitoring story, and a "Manage AI features" admin affordance. Hermiq is now the fleet-wide home for AI-feature governance (its `agentaifeature` register already ships the DPO-ack lifecycle). This change moves the governance home to Hermiq while keeping Scholiq's two genuinely-local obligations: the AVG Art. 30 declaration of its AI processing, and the domain rule gating AI-proctored assessment publication.

## Key decisions

### 1. Retain a minimal `AiFeature` schema as the AVG carrier (do not delete)
The `AiFeature` schema carries the `scholiq-ai-features` `x-openregister-processing` annotation — one of Scholiq's seven AVG Art. 30 activities, asserted by `ProcessingActivityCatalogueTest`. Deleting the schema would drop the verwerkingsregister to six activities and fail the test. **Decision:** strip only the governance body (`x-openregister-lifecycle`, `-notifications`, governance properties) and keep `slug`/`name`/`description` + the processing annotation. The schema becomes a pure Art. 30 declaration; no governance objects are seeded (`x-openregister-seed: []`).

### 2. The proctoring DPO gate sources approval from Hermiq (fail closed, graceful)
`AssessmentPublishGuard` enforces that an `ai-assisted` proctored Assessment may not be published unless the `assessment-ai-proctor-review` high-risk feature is DPO-`enabled`. That approval object no longer lives in Scholiq. **Decision:** the guard queries Hermiq's central register (`register=hermiq`, `schema=agentaifeature`) for the same slug + `lifecycle=enabled`, and injects `IAppManager` to distinguish two block reasons:
- Hermiq **not installed** → block, "install and enable Hermiq" (fail closed: high-risk AI proctoring requires the governance app).
- Hermiq installed, feature **not enabled** → block, "DPO-enable it in Hermiq".

Fail-closed is correct for a high-risk gate and matches the prior behaviour (which required a local `enabled` object). Only the `ai-assisted` path is gated; `manual` proctoring (the default) and every other transition are untouched, so the app never fatals when Hermiq is absent. The coupling to Hermiq's register/schema slug is localised to two `private const`s with docs; a future Hermiq capability API can replace the direct OR query without changing this contract.

### 3. Settings delegates by full navigation, no hard dependency
The Admin Settings mount has no in-app vue-router, so the "AI Features" section links out with a full navigation to `generateUrl('/apps/hermiq') + '/ai-features'`, gated on a runtime `hermiqInstalled` computed (`window.OC?.appswebroots?.hermiq !== undefined`) — mirroring the prior "Manage AI features" affordance. When Hermiq is absent, an `NcNoteCard` invites installing it. `appinfo/info.xml` gains **no** `<app>hermiq</app>` dependency; Hermiq is an optional peer.

## Alternatives considered

- **Keep AI-feature governance local (status quo).** Rejected: duplicates the register + DPO workflow across every app and fragments the source of truth; Hermiq exists to consolidate it.
- **Drop the proctoring DPO gate entirely.** Rejected: silently weakens an EU AI Act Art. 14 enforcement in a refactor. The gate is preserved, its data source relocated.
- **Delete the `AiFeature` schema outright.** Rejected: it carries an AVG Art. 30 processing activity (compliance regression + test failure). Only the governance body is stripped.
- **Add a hard `<app>hermiq</app>` dependency.** Rejected: the fleet delegation model is graceful — apps opt in and degrade cleanly when the governance app is absent.

## Compliance / test impact

- `ProcessingActivityCatalogueTest` + `SchemaSlugRegressionTest` — pass unchanged (seven activities; `AiFeature` PascalCase-slug carve-out still valid). 16 tests, 389 assertions.
- No unit test references `AssessmentPublishGuard` or `AiFeatureDpoAckGuard`, so the guard re-point + guard deletion break no test.
- Note: running the unit suite inside the live NC container surfaces pre-existing, unrelated real-OR-vs-stub signature failures in `RolloverServiceTest` / `ExternalTrainingServiceTest` (the documented CI≠container gotcha); CI runs the stub-backed standalone env. None are touched by this change.
