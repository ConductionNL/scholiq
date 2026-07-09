# data-exchange Specification (delta)

Phase B relocates only the data-exchange **entry point**. Data-exchange is core Scholiq education (ADR-009 §3/§4: OSO / DUO-BRON / RIO aanleveringen), and its backend — the `DataExchangeJob` / `DataMappingProfile` register schemas, `DataExchangeRunGuard` / `DataExchangeRunHandler`, `OsoDossierReviewGuard`, the OSO parent-review lifecycle gate and OpenConnector delegation — is unchanged. The in-app left-nav gear-foldout entry is removed and replaced by a "Data exchange" section on the Nextcloud Admin Settings page; every data-exchange page stays registered and routable.

## ADDED Requirements

### Requirement: Data-exchange management is reached from the Admin Settings page
The data-exchange entry point MUST move from the in-app settings foldout to the Nextcloud Admin Settings page. The `DataExchange` leaf id MUST be removed from `src/menu-layout.json#settingsSection`, and the Admin Settings page (mounted by `lib/Settings/AdminSettings.php` + `src/settings.js` → `src/views/settings/AdminRoot.vue`) MUST render a "Data exchange" settings section that links to the still-routable Data-exchange **jobs** (`#/data-exchange/jobs`) and **mapping profiles** (`#/data-exchange/mapping-profiles`) SPA pages, mirroring the "Manage AI features" affordance in `ScholiqSettings.vue`. Because the Admin Settings mount has no in-app vue-router, the links MUST navigate out via full navigation (hash-form SPA URL), not by embedding router pages. All data-exchange pages (`DataExchangeJobs`, `DataExchangeJobDetail`, `DataMappingProfiles`, `DataMappingProfileDetail`, `RequestExportModal`, `OsoDossierReviewView`) MUST remain registered in `src/manifest.json.pages[]` and routable. No backend, register schema, lifecycle guard, OSO gate or OpenConnector delegation is changed.

#### Scenario: Admin Settings shows a Data exchange section
<!-- @e2e exclude Admin Settings is rendered by the Nextcloud settings framework outside the SPA route-smoke harness (tests/e2e/pages.spec.ts); the section render + link targets are verified in-browser at apply. -->
- **GIVEN** an admin on the Scholiq Admin Settings page (`AdminRoot.vue`)
- **WHEN** the page renders
- **THEN** a "Data exchange" settings section is shown with a link to Data-exchange jobs (`#/data-exchange/jobs`) and a link to mapping profiles (`#/data-exchange/mapping-profiles`)

#### Scenario: The in-app Data exchange foldout entry is removed
<!-- @e2e exclude Static / absence assertion — verified by the manifest/menu-layout unit test (no `DataExchange` id in settingsSection); not a positive route-smoke DOM behaviour. -->
- **GIVEN** the parsed `src/menu-layout.json`
- **WHEN** its `settingsSection` array is inspected
- **THEN** it does not list `DataExchange`

#### Scenario: Data-exchange jobs page remains routable via deep link
- **GIVEN** the `DataExchangeJobs` page is no longer in the nav
- **WHEN** a user navigates directly to `#/data-exchange/jobs`
- **THEN** the `DataExchangeJobs` index page renders without a fatal error

#### Scenario: Data-exchange mapping profiles page remains routable via deep link
- **GIVEN** the `DataMappingProfiles` page is no longer in the nav
- **WHEN** a user navigates directly to `#/data-exchange/mapping-profiles`
- **THEN** the `DataMappingProfiles` index page renders without a fatal error
