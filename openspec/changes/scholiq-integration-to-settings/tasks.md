# Tasks — Relocate Scholiq integration surfaces under Settings

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm no overlap with OpenRegister abstractions: this is a scholiq-local
      navigation IA edit (`menu[]` metadata in `src/manifest.json`); it does NOT add
      schemas, controllers, or services, so there is nothing for ObjectService /
      RegisterService / SchemaService / ConfigurationService to duplicate (ADR-022).
- [ ] Confirm `@conduction/nextcloud-vue` already provides the Settings/gear grouping
      via the manifest `section: "settings"` flag (used by `AdminHealthMenu`,
      `XapiStatementsMenu`, `AiFeaturesMenu`, `AssistantMenu`) — no new component is
      needed (ADR-012: reuse, don't reinvent).
- [ ] Confirm scholiq has NO `src/menu-layout.json` and NO `src/manifest.d/`
      fragments → follow scholiq's in-place `menu[]` edit pattern (the docudesk
      relocate-entry-keep-page precedent), not a separate menu-layout file.
- [ ] Verify Phase 0 against the manifest: `DataExchange` is integration plumbing
      (mapping profiles / jobs / OSO review), `XapiStatements` is a read-only
      integration log; `Compliance` is officer/audit transactional surface and is
      therefore OUT of scope. Document findings even if "no overlap found".

## Phase 1: Relocate Data exchange into the Settings group

- [ ] In `src/manifest.json`, change the `DataExchange` menu entry: add
      `"section": "settings"` and set an `order` placing it within the settings group
      (before xAPI statements). Keep `"route": "DataExchangeJobs"` and the label
      "Data exchange" unchanged.
- [ ] Do NOT alter any `pages[]` entry for `/data-exchange/...`
      (`DataMappingProfiles`, `DataMappingProfileDetail`, `DataExchangeJobs`,
      `DataExchangeJobDetail`, `RequestExportModal`, `OsoDossierReviewView`).

## Phase 2: Align xAPI statements within the integrations cluster

- [ ] In `src/manifest.json`, keep `XapiStatementsMenu` as `section: "settings"` and
      set its `order` immediately after `DataExchange` so Data exchange + xAPI read as
      one coherent "Integrations" cluster under the gear.
- [ ] Leave the `XapiStatements` / `XapiStatementDetail` pages (`readOnly`) untouched.

## Phase 3: Verify routability and gating

- [ ] Assert every relocated entry's pages still resolve as deep links:
      `/data-exchange/jobs`, `/data-exchange/jobs/:id`,
      `/data-exchange/jobs/:id/oso-review`, `/data-exchange/mapping-profiles`,
      `/data-exchange/request`, `/xapi-statements`, `/xapi-statements/:id`.
- [ ] Assert `Compliance` (and all other learner/instructor/officer entries) remain in
      the primary navigation and that `Compliance` keeps its
      `compliance-officer`/`hr` `visibleIf` gate.
- [ ] Assert no `pages[]`, schema, register fragment, route, or controller changed
      (git diff scoped to the `menu[]` array of `src/manifest.json`).

## Phase 4: Validate

- [ ] `cd scholiq && openspec validate scholiq-integration-to-settings --strict` → exit 0.
