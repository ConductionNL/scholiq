# Design — Nextcloud App Shell

> **Per [ADR-024](../../architecture/../../../.claude/openspec/architecture/adr-024-app-manifest.md) §9** — this change IS Scholiq's manifest-adoption change, scoping to Tier-4 (`CnAppRoot` full shell).
>
> **Per [ADR-022](../../../.claude/openspec/architecture/adr-022-apps-consume-or-abstractions.md)** — Scholiq consumes OR's audit-trail, RBAC, archival, and lifecycle abstractions. This change does NOT introduce a parallel audit-trail substrate, `AiFeatureRegistry` singleton, or `NotificationService`.
>
> **Per [ADR-031](../../../.claude/openspec/architecture/adr-031-schema-declarative-business-logic.md)** — there is no `AuditTrail` service, no `AuditedController` base class, no `AiFeatureRegistry` singleton. Settings state machines (`TenantSetting` lifecycle) are declared via `x-openregister-lifecycle` in `lib/Settings/scholiq_register.json`.

---

## 1. PHP Bootstrap

### 1.1 `appinfo/info.xml`

```xml
<info>
  <id>scholiq</id>
  <name>Scholiq</name>
  <namespace>Scholiq</namespace>
  <version>0.1.0</version>
  <licence>EUPL-1.2</licence>
  <category>organization</category>
  <dependencies>
    <app>openregister</app>
    <app>openconnector</app>
    <nextcloud min-version="33" max-version="34"/>
    <php min-version="8.3" max-version="8.4"/>
  </dependencies>
</info>
```

Declaring `openregister` + `openconnector` in `<dependencies>` causes Nextcloud to block activation if either is absent. **No app-local `OpenRegisterGuard` service** — `CnAppRoot` resolves `manifest.dependencies` at runtime and renders the install-CTA `NcEmptyContent` empty state automatically (per ADR-024 §8, Tier 4).

### 1.2 `lib/AppInfo/Application.php`

DI bindings limited to what ADR-031 §"What apps SHOULD still write in PHP" permits:

| Service | Class | ADR-031 category |
|---|---|---|
| `Cmi5LaunchTokenService` | `Scholiq\Service\Cmi5LaunchTokenService` | Cryptographic operation (JWT signing) |
| `AiFeatureDpoAckGuard` | `Scholiq\Lifecycle\AiFeatureDpoAckGuard` | Lifecycle guard (PHP seam) |

No `AuditTrail`, no `AuditedController`, no `AiFeatureRegistry`, no `NotificationService`, no `OpenRegisterGuard`, no `AdminSettings`, no `PersonalSettings`.

### 1.3 `appinfo/routes.php` — wedge route table

```php
['name' => 'page#index',           'url' => '/',                               'verb' => 'GET'],
['name' => 'page#manifest',        'url' => '/api/manifest',                   'verb' => 'GET'],
['name' => 'lrs#postStatements',   'url' => '/api/lrs/statements',             'verb' => 'POST'],
['name' => 'lrs#getStatements',    'url' => '/api/lrs/statements',             'verb' => 'GET'],
['name' => 'scorm#launch',         'url' => '/api/scorm/{lessonId}/launch',    'verb' => 'GET'],
['name' => 'scorm#api',            'url' => '/api/scorm/{lessonId}/api',       'verb' => 'POST'],
['name' => 'cmi5_launch#token',    'url' => '/api/lessons/{lessonId}/launch',  'verb' => 'GET'],
['name' => 'credential#verify',    'url' => '/api/credentials/{id}/verify',    'verb' => 'GET'],
['name' => 'audit_pack#export',    'url' => '/api/compliance/audit/export',    'verb' => 'POST'],
['name' => 'audit_pack#dossier',   'url' => '/api/ai-features/{slug}/dossier', 'verb' => 'GET'],
```

Specific routes before wildcard entries per ADR-003.

### 1.4 `lib/Service/Cmi5LaunchTokenService.php`

`mintLaunchToken(string $learnerId, string $lessonId, string $registrationId): string` — returns RS256 JWT with claims `{sub: $learnerId, lesson_id: $lessonId, registration_id: $registrationId, exp: now+8h}`. Private key stored in `OCP\Security\ICrypto` under key `scholiq.cmi5.launch.private`. Cryptographic operation, legitimate PHP per ADR-031.

### 1.5 `lib/Lifecycle/AiFeatureDpoAckGuard.php`

Single `check(TransitionContext $ctx): GuardResult` method. Reads `IAppConfig::getValueBool('scholiq', 'dpo_ack.' . $ctx->getObject()->propertyName, false)`. Returns `Reject('DPO acknowledgement required to enable this AI feature')` when false. Legitimate lifecycle guard per ADR-031 §"PHP guards remain a legitimate seam".

---

## 2. Schema Patches — `lib/Settings/scholiq_register.json`

This change introduces two schemas. All downstream changes extend this same file.

### 2.1 `TenantSetting`

Stores admin-level, per-tenant configuration items that require lifecycle management (IdP settings, OpenConnector adapter endpoints, AI feature flags). Configuration that has no state-machine behaviour (e.g. a simple default register slug) may live in `IAppConfig` instead.

```jsonc
"TenantSetting": {
  "slug": "tenant-setting",
  "icon": "CogOutline",
  "version": "0.1.0",
  "title": "Tenant instelling",
  "description": "Beheerdersinstelling op tenantniveau voor Scholiq",
  "type": "object",
  "x-openregister": {
    "active": true,
    "searchable": true
  },
  "required": ["propertyName", "propertyValue", "category"],
  "properties": {
    "propertyName":  { "type": "string",  "description": "Instellingssleutel (dot-notatie, bijv. idp.entity_id)" },
    "propertyValue": { "type": "string",  "description": "Instellingswaarde (platte tekst of JSON-string)" },
    "category":      { "type": "string",  "enum": ["idp", "connector", "ai-feature", "general"],
                       "description": "Categorie van de instelling" },
    "isSecret":      { "type": "boolean", "default": false,
                       "description": "Verberg waarde in UI en audit-uitvoer" },
    "description":   { "type": ["string", "null"], "description": "Beheerderstoelichting" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "disabled",
    "transitions": {
      "enable":  { "from": "disabled", "to": "enabled" },
      "disable": { "from": "enabled",  "to": "disabled" }
    }
  }
}
```

The `enable` transition for `category: ai-feature` items is guarded by `AiFeatureDpoAckGuard` — declared on the individual `AiFeature` schema seeds (introduced in downstream specs). OR's lifecycle engine emits `security.config.changed` audit entries on every `enable`/`disable` transition automatically; no app-local audit call needed (ADR-008 + ADR-022).

### 2.2 `UserSetting`

Stores user-level display and notification preferences per Nextcloud user ID. Simple enough that it could live in `OCP\IConfig`; OR schema is used here to leverage OR's audit trail for GDPR inzageverzoek (data subject access requests) and to keep all Scholiq personal data queryable in one place.

```jsonc
"UserSetting": {
  "slug": "user-setting",
  "icon": "AccountCogOutline",
  "version": "0.1.0",
  "title": "Gebruikersvoorkeur",
  "description": "Persoonlijke voorkeur van een Nextcloud-gebruiker voor Scholiq",
  "type": "object",
  "x-openregister": {
    "active": true,
    "searchable": false
  },
  "required": ["userId", "propertyName", "propertyValue", "category"],
  "properties": {
    "userId":        { "type": "string",  "description": "Nextcloud gebruikers-ID" },
    "propertyName":  { "type": "string",  "description": "Voorkeursleutel (dot-notatie, bijv. notification.preference)" },
    "propertyValue": { "type": "string",  "description": "Voorkeurwaarde" },
    "category":      { "type": "string",  "enum": ["notification", "display", "general"],
                       "description": "Categorie van de voorkeur" },
    "description":   { "type": ["string", "null"], "description": "Toelichting" }
  }
}
```

---

## 3. Seed Data

Per ADR-001 §Seed Data — 3-5 realistic objects per schema, Dutch values, loaded via `importFromApp()` at install time using the `@self` envelope.

### 3.1 TenantSetting seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "TenantSetting", "slug": "tenant-setting-surfconext-idp" },
    "propertyName":  "idp.entity_id",
    "propertyValue": "https://idp.surfconext.nl",
    "category":      "idp",
    "isSecret":      false,
    "description":   "SURFconext IdP entity ID voor onderwijsinstellingen",
    "lifecycle":     "enabled"
  },
  {
    "@self": { "register": "scholiq", "schema": "TenantSetting", "slug": "tenant-setting-rod-base-url" },
    "propertyName":  "connector.rod.base_url",
    "propertyValue": "https://onderwijsdata.nl/rod/api/v2",
    "category":      "connector",
    "isSecret":      false,
    "description":   "ROD (Register Onderwijsdeelnemers) API endpoint",
    "lifecycle":     "enabled"
  },
  {
    "@self": { "register": "scholiq", "schema": "TenantSetting", "slug": "tenant-setting-oso-endpoint" },
    "propertyName":  "connector.oso.endpoint",
    "propertyValue": "https://oso.nl/api/v1",
    "category":      "connector",
    "isSecret":      false,
    "description":   "OSO (Overstapservice Onderwijs) endpoint — nog niet geconfigureerd",
    "lifecycle":     "disabled"
  },
  {
    "@self": { "register": "scholiq", "schema": "TenantSetting", "slug": "tenant-setting-ai-adaptive-learning" },
    "propertyName":  "ai.adaptive_learning.enabled",
    "propertyValue": "false",
    "category":      "ai-feature",
    "isSecret":      false,
    "description":   "AI-gestuurde adaptieve leerpaden (vereist DPO-goedkeuring per ADR-005)",
    "lifecycle":     "disabled"
  },
  {
    "@self": { "register": "scholiq", "schema": "TenantSetting", "slug": "tenant-setting-default-register" },
    "propertyName":  "openregister.default_register",
    "propertyValue": "scholiq",
    "category":      "general",
    "isSecret":      false,
    "description":   "Standaard OpenRegister voor Scholiq-objecten",
    "lifecycle":     "enabled"
  }
]
```

### 3.2 UserSetting seed objects

```jsonc
[
  {
    "@self": { "register": "scholiq", "schema": "UserSetting", "slug": "user-setting-jan-devries-notification" },
    "userId":        "jan.de.vries",
    "propertyName":  "notification.preference",
    "propertyValue": "instant",
    "category":      "notification",
    "description":   "Direct notificaties bij trainingsdeadlines"
  },
  {
    "@self": { "register": "scholiq", "schema": "UserSetting", "slug": "user-setting-anja-bakker-default-view" },
    "userId":        "anja.bakker",
    "propertyName":  "display.default_view",
    "propertyValue": "dashboard",
    "category":      "display",
    "description":   "Start op het compliance dashboard"
  },
  {
    "@self": { "register": "scholiq", "schema": "UserSetting", "slug": "user-setting-piet-smit-notification" },
    "userId":        "piet.smit",
    "propertyName":  "notification.preference",
    "propertyValue": "digest",
    "category":      "notification",
    "description":   "Dagelijkse samenvatting van trainingsstatus"
  },
  {
    "@self": { "register": "scholiq", "schema": "UserSetting", "slug": "user-setting-lisa-vdberg-default-view" },
    "userId":        "lisa.van.den.berg",
    "propertyName":  "display.default_view",
    "propertyValue": "courses",
    "category":      "display",
    "description":   "Start op de cursusoverzichtpagina"
  },
  {
    "@self": { "register": "scholiq", "schema": "UserSetting", "slug": "user-setting-kees-janssen-per-page" },
    "userId":        "kees.janssen",
    "propertyName":  "display.items_per_page",
    "propertyValue": "50",
    "category":      "display",
    "description":   "Voorkeur voor 50 items per overzichtspagina"
  }
]
```

---

## 4. Frontend — `CnAppRoot` Tier-4 Manifest

### 4.1 `src/manifest.json`

```jsonc
{
  "$schema": "https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest.schema.json",
  "version": "0.1.0",
  "dependencies": ["openregister", "openconnector"],
  "theme": {
    "primary": "var(--scholiq-primary)",
    "accent":  "var(--scholiq-accent)",
    "logoUrl": "/apps/scholiq/img/logo.svg"
  },
  "menu": [
    { "id": "Dashboard",    "label": "scholiq.menu.dashboard",    "icon": "icon-category-dashboard", "route": "Dashboard",    "order": 10 },
    { "id": "Courses",      "label": "scholiq.menu.courses",      "icon": "icon-folder",             "route": "Courses",      "order": 20 },
    { "id": "Enrolments",   "label": "scholiq.menu.enrolments",   "icon": "icon-group",              "route": "Enrolments",   "order": 30 },
    { "id": "Credentials",  "label": "scholiq.menu.credentials",  "icon": "icon-checkmark",          "route": "Credentials",  "order": 40 },
    { "id": "Compliance",   "label": "scholiq.menu.compliance",   "icon": "icon-category-files",     "route": "Compliance",   "order": 50 },
    { "id": "Docs",         "label": "scholiq.menu.docs",         "icon": "icon-info",               "href":  "https://docs.scholiq.nl", "section": "settings", "order": 90 },
    { "id": "Settings",     "label": "scholiq.menu.settings",     "icon": "icon-settings",           "route": "Settings",     "section": "settings", "order": 99 }
  ],
  "pages": [
    { "id": "Dashboard",   "route": "/",            "type": "dashboard", "title": "scholiq.page.dashboard.title" },
    { "id": "Courses",     "route": "/courses",     "type": "index",     "config": { "register": "scholiq", "schema": "Course" } },
    { "id": "Enrolments",  "route": "/enrolments",  "type": "index",     "config": { "register": "scholiq", "schema": "Enrolment" } },
    { "id": "Credentials", "route": "/credentials", "type": "index",     "config": { "register": "scholiq", "schema": "Credential" } },
    { "id": "Compliance",  "route": "/compliance",  "type": "dashboard", "title": "scholiq.page.compliance.title" },
    { "id": "Settings",    "route": "/settings",    "type": "custom",    "config": { "component": "ScholiqSettings" } }
  ]
}
```

The `Course`, `Enrolment`, and `Credential` index pages are scaffolded here; their schema definitions and widgets are declared in the respective downstream changes. Page contents for `Dashboard` and `Compliance` are filled in by `dashboard` and `compliance-audit` changes.

**No `src/router/index.js`** — `CnAppRoot` derives hash-mode Vue Router entries from `manifest.pages` (Tier 4 per ADR-024 §8).
**No `src/components/OpenRegisterGuard.vue`** — `CnAppRoot` reads `manifest.dependencies` and renders the `NcEmptyContent` dependency-missing empty state automatically.

### 4.2 `src/main.js`

```js
import Vue from 'vue'
import { useAppManifest, CnAppRoot } from '@conduction/nextcloud-vue'
import bundled from './manifest.json'
import ScholiqSettings from './views/ScholiqSettings.vue'

useAppManifest('scholiq', bundled)

new Vue({
    el: '#content',
    render: h => h(CnAppRoot, {
        props: { customComponents: { ScholiqSettings } },
    }),
})
```

### 4.3 `src/views/ScholiqSettings.vue`

The single custom `Settings` page declared in the manifest. Hosts three sections:

1. **Default register picker** — `NcSelect` sourcing `GET /api/openregister/registers` (OR REST); on change writes `IAppConfig` key `default_register` via `POST /api/settings`. Uses `inputLabel` prop on `NcSelect` (never a manual `<label>`) per ADR-004.
2. **TenantSetting table** — `CnIndexPage` bound to `{ register: 'scholiq', schema: 'TenantSetting' }`. Inline lifecycle toggle (enable/disable) uses OR's transition endpoint; the `AiFeatureDpoAckGuard` intercepts `ai-feature` category toggles server-side. No custom toggle component.
3. **Credential signing key** — admin action that calls `POST /api/credentials/signing-key/rotate` (declared in `certification` change); renders current key fingerprint. Cryptographic widget, legitimate PHP per ADR-031.

All form controls use `@conduction/nextcloud-vue` components. No `window.confirm()`, no `window.alert()`, no hardcoded colour values.

### 4.4 NL Design double-fallback CSS

All colour and spacing references follow the double-fallback pattern:

```css
color: var(--cn-primary, var(--color-primary-element, #0082c9));
background: var(--cn-background, var(--color-main-background, #fff));
```

Hardcoded hex or `rgba()` values MUST NOT appear in component `<style>` blocks. The `nldesign` app resolves `--cn-*` tokens from the active NL Design token set; Nextcloud stock variables serve as the second fallback.

### 4.5 `webpack.config.js`

```js
const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')
const webpack = require('webpack')

// Vue alias + dedup
webpackConfig.resolve.alias = {
    ...webpackConfig.resolve.alias,
    vue$: path.resolve(__dirname, 'node_modules/vue/dist/vue.esm-bundler.js'),
}
webpackConfig.resolve.dedupe = ['vue', 'pinia', '@nextcloud/vue']

// DefinePlugin — required when plugins array is replaced (ADR-004)
webpackConfig.plugins.push(
    new webpack.DefinePlugin({ appName: JSON.stringify('scholiq') }),
    new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
)

// splitChunks: all (ADR-004 — 2+ entry-points)
webpackConfig.optimization = {
    ...(webpackConfig.optimization || {}),
    splitChunks: {
        chunks: 'all',
        cacheGroups: {
            default: false,
            defaultVendors: false,
            ncVue: {
                name: 'scholiq-shared-nc-vue',
                test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]/,
                priority: 30, reuseExistingChunk: true, enforce: true,
                filename: 'scholiq-shared-nc-vue.js',
            },
            vendor: {
                name: 'scholiq-shared-vendor',
                test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
                priority: 20, reuseExistingChunk: true, enforce: true,
                filename: 'scholiq-shared-vendor.js',
            },
        },
    },
}

module.exports = webpackConfig
```

---

## 5. i18n

```
l10n/
  en.json   PHP-side English translations (canonical key source)
  nl.json   PHP-side Dutch translations
  en.js     Vue-side English translations
  nl.js     Vue-side Dutch translations
```

Manifest `label`/`title` values are translation keys consumed by `t()` inside `CnAppRoot` (ADR-024 §6). `ScholiqSettings.vue` strings use `t('scholiq', 'key')`. The `i18n-ci` GitHub Actions job diffs `en.json` ↔ `nl.json` key sets and fails on any missing key.

---

## 6. Declarative-vs-Imperative Decision

| Behaviour | Decision | Rationale |
|---|---|---|
| TenantSetting state machine (disabled ↔ enabled) | **declarative** (`x-openregister-lifecycle`) | Standard lifecycle — OR's engine emits `security.config.changed` audit entries automatically |
| AI feature flag DPO acknowledgement precondition | **imperative** (PHP guard) | Lifecycle guard — thin PHP seam per ADR-031 §"PHP guards remain a legitimate seam" |
| OpenRegister dependency check at runtime | **declarative** (`manifest.dependencies`) | `CnAppRoot` renders `NcEmptyContent` install-CTA from manifest; no PHP guard class needed |
| Settings panel access control (admin-only) | **declarative** (NC settings framework) | `AdminSettings.php` is the NC-idiomatic contract; no custom RBAC middleware |
| Default register picker state | **imperative** (`IAppConfig`) | Single scalar config value; no state machine; OR would be overkill |
| cmi5 launch token signing | **imperative** (PHP service) | Cryptographic operation — legitimate per ADR-031 |
| Audit trail on TenantSetting transitions | **declarative** (OR lifecycle engine) | Emitted automatically from `x-openregister-lifecycle`; no `AuditTrail::record()` call |
| i18n key resolution | **declarative** (`l10n/*.json`) | NC framework handles resolution; no app-local i18n service |

---

## 7. Reuse Analysis

Per ADR-012 — deduplication check confirming which OpenRegister and `@conduction/nextcloud-vue` abstractions this change reuses:

| Abstraction | Source | Usage in this change |
|---|---|---|
| `CnAppRoot` | `@conduction/nextcloud-vue` | Full Tier-4 app shell — dependency guard, menu, routing, page dispatch |
| `useAppManifest` | `@conduction/nextcloud-vue` | Manifest loader in `src/main.js` |
| `CnIndexPage` | `@conduction/nextcloud-vue` | TenantSetting table in `ScholiqSettings.vue` |
| `CnSettingsSection` + `CnVersionInfoCard` | `@conduction/nextcloud-vue` | Admin settings layout |
| `x-openregister-lifecycle` (OR) | OpenRegister | TenantSetting disabled ↔ enabled state machine |
| OR audit-trail abstraction (ADR-022) | OpenRegister | `security.config.changed` events on TenantSetting transitions |
| `ObjectService::saveObject` | OpenRegister | TenantSetting + UserSetting persistence; no custom Mapper |
| OR's RBAC / `AuthorizationService` | OpenRegister | Field-level permissions on TenantSetting (isSecret masking) |
| `IAppConfig` (NC OCP) | Nextcloud OCP | Single scalar config key `default_register` |
| `OCP\Security\ICrypto` | Nextcloud OCP | cmi5 launch token private key storage |

No new OR service class, no new Mapper, no custom search endpoint, no custom pagination logic — all of these are provided by the platform.

---

## 8. PHP Files Shipped (ADR-031 Exceptions)

| File | Lines (target) | ADR-031 category |
|---|---|---|
| `appinfo/info.xml` | ~20 | NC manifest declaration |
| `appinfo/routes.php` | ~30 | NC framework requirement |
| `lib/AppInfo/Application.php` | ~40 | DI registration |
| `lib/Settings/scholiq_register.json` | declarative | Schema source for TenantSetting, UserSetting |
| `lib/Service/Cmi5LaunchTokenService.php` | ~80 | Cryptographic operation — JWT signing |
| `lib/Controller/PageController.php` | ~30 | NC framework requirement — renders main.php + manifest endpoint |
| `lib/Lifecycle/AiFeatureDpoAckGuard.php` | ~25 | Lifecycle guard — PHP seam |
| `templates/main.php` | ~20 | SPA bootstrap |
| `src/manifest.json` | declarative | ADR-024 manifest source |
| `src/main.js` | ~15 | Wire CnAppRoot |
| `src/views/ScholiqSettings.vue` | ~120 | Single custom settings page |
| `webpack.config.js` | ~50 | Build configuration |

**Not in this change** (ADR-022 + ADR-031 anti-patterns):
- `AuditTrail` service / `AuditedController` base / `MissingAuditTrailRule` PHPStan rule
- `AiFeatureRegistry` singleton
- `AdminSettings.php` / `PersonalSettings.php` `OCP\Settings\ISettings` classes
- `OpenRegisterGuard.vue`
- `src/router/index.js`

---

## 9. Integration Points

| Integration | OCP/OR Interface | Usage |
|---|---|---|
| User auth | `OCP\IUserSession` | Actor in OR audit entries (OR reads from session) |
| App config | `OCP\IAppConfig` | Single key `default_register` |
| Group management | `OCP\IGroupManager` | `isAdmin()` check in settings page |
| Crypto | `OCP\Security\ICrypto` | cmi5 JWT signing key storage |
| OR objects | `OCA\OpenRegister\Service\ObjectService` | TenantSetting + UserSetting persistence |
| OR lifecycle | `x-openregister-lifecycle` | TenantSetting disabled ↔ enabled state machine |

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| `CnAppRoot` `customComponents` prop not yet stable in pinned `@conduction/nextcloud-vue` version | Pin to a release that documents `customComponents` support; integration test asserts `ScholiqSettings` renders. |
| `validateManifest` false-positives on Scholiq-specific `theme` fields | Keep manifest strictly within canonical schema; any extension requires a library-level openspec change per ADR-024 §10. |
| `AiFeatureDpoAckGuard` not invoked when lifecycle transition originates from OR's admin UI | OR's lifecycle engine resolves `requires:` PHP class references via DI; integration test triggers transition through OR's REST API and asserts guard fires. |
| `splitChunks: { chunks: 'all' }` chunk filename drift across webpack version bumps | Stable explicit `filename` values in `cacheGroups`; CI build-output assertion on expected chunk names. |
