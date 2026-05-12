# Design — Nextcloud App Shell (manifest adoption per ADR-024)

> **Per [hydra ADR-024 §9](../../../../hydra/openspec/architecture/adr-024-app-manifest.md)** — each app gets its own openspec change `{app}-adopt-manifest` referencing the manifest ADR. This `nextcloud-app/` change IS scholiq's manifest-adoption change; it deliberately scopes itself to Tier 4 (`CnAppRoot` full shell) per ADR-024 §8.
>
> **Per [hydra ADR-022](../../../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md)** — Scholiq consumes OR's audit-trail / RBAC / archival abstractions. This change does NOT bring up a parallel substrate.
>
> **Per [hydra ADR-031](../../../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md)** — there is no `AuditTrail` service, no `AuditedController` base class, no `AiFeatureRegistry` singleton, no `NotificationService`. State machines + aggregations + notifications are declared via `x-openregister-*` in `lib/Settings/scholiq_register.json`.

## 1. PHP Application Bootstrap

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

Hard declaring `openregister` + `openconnector` in `<dependencies>` causes NC to block activation if either is absent. **No app-local `OpenRegisterGuard` service** — `CnAppRoot` resolves `manifest.dependencies` and renders the install-CTA empty state for missing apps automatically (per ADR-024).

### 1.2 `lib/AppInfo/Application.php`

Tiny. The wedge brings up only what ADR-031 §"What apps SHOULD still write in PHP" allows:

| Service | Class | Scope | ADR-031 category |
|---|---|---|---|
| `Cmi5LaunchTokenService` | `Scholiq\Service\Cmi5LaunchTokenService` | singleton | Cryptographic operation (JWT signing) |
| Lifecycle guards | `Scholiq\Lifecycle\*Guard` | per-class | Lifecycle guards (PHP seam) |

No `AuditTrail`, no `AuditedController`, no `AiFeatureRegistry`, no `NotificationService`, no `OpenRegisterGuard` — all replaced by OR abstractions per ADRs 022 + 031.

Route registration is the route table from `appinfo/routes.php`; downstream specs append their routes there. The wedge route list:

```php
['name' => 'page#index',                  'url' => '/',                              'verb' => 'GET'],
['name' => 'lrs#postStatements',          'url' => '/api/lrs/statements',            'verb' => 'POST'],   // ADR-002
['name' => 'lrs#getStatements',           'url' => '/api/lrs/statements',            'verb' => 'GET'],
['name' => 'scorm#launch',                'url' => '/api/scorm/{lessonId}/launch',   'verb' => 'GET'],    // ADR-002
['name' => 'scorm#api',                   'url' => '/api/scorm/{lessonId}/api',      'verb' => 'POST'],
['name' => 'cmi5_launch#token',           'url' => '/api/lessons/{lessonId}/launch', 'verb' => 'GET'],    // JWT mint
['name' => 'credential#verify',           'url' => '/api/credentials/{id}/verify',   'verb' => 'GET'],    // public
['name' => 'audit_pack#export',           'url' => '/api/compliance/audit/export',   'verb' => 'POST'],   // ZIP
['name' => 'audit_pack#dossier',          'url' => '/api/ai-features/{slug}/dossier','verb' => 'GET'],    // AI Act
['name' => 'page#manifest',               'url' => '/api/manifest',                  'verb' => 'GET'],    // ADR-024 §4
```

Settings — admin + personal — are handled by the manifest's `Settings` page declaration, not by per-spec controllers. Per-tenant overrides live in `IAppConfig` under keys read by OR (Scholiq does not add an `IAppConfig`-bound `AdminSettings.php` service for state-machine flags; the AI Act feature flags are `AiFeature` schema objects with `x-openregister-lifecycle`).

---

## 2. Frontend manifest — the heart of this change

Per ADR-024 §1–§9, the wedge ships `src/manifest.json` as the single source of truth for the app's UI shell.

### 2.1 `src/manifest.json` (full Tier 4)

```jsonc
{
  "$schema": "https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest.schema.json",
  "version": "0.1.0",
  "dependencies": ["openregister", "openconnector"],
  "theme": {
    "primary":  "var(--scholiq-primary)",
    "accent":   "var(--scholiq-accent)",
    "logoUrl":  "/apps/scholiq/img/logo.svg"
  },
  "menu": [
    { "id": "Dashboard",   "label": "scholiq.menu.dashboard",   "icon": "icon-category-dashboard", "route": "Dashboard",   "order": 10 },
    { "id": "Courses",     "label": "scholiq.menu.courses",     "icon": "icon-folder",             "route": "Courses",     "order": 20 },
    { "id": "Enrolments",  "label": "scholiq.menu.enrolments",  "icon": "icon-group",              "route": "Enrolments",  "order": 30 },
    { "id": "Credentials", "label": "scholiq.menu.credentials", "icon": "icon-checkmark",          "route": "Credentials", "order": 40 },
    { "id": "Compliance",  "label": "scholiq.menu.compliance",  "icon": "icon-checkmark",          "route": "Compliance",  "order": 50 },
    { "id": "Documentation","label": "scholiq.menu.docs",        "icon": "icon-info",               "href":  "https://docs.scholiq.nl", "section": "settings", "order": 90 },
    { "id": "Settings",     "label": "scholiq.menu.settings",    "icon": "icon-settings",           "route": "Settings",     "section": "settings", "order": 99 }
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

Page contents (widgets per Dashboard / Compliance) are filled in by the corresponding `compliance-audit` + `dashboard` change. This change ships the shell + the index pages bound to `Course` / `Enrolment` / `Credential` registers.

### 2.2 `src/main.js`

```js
import Vue from 'vue'
import { useAppManifest, CnAppRoot } from '@conduction/nextcloud-vue'
import bundled from './manifest.json'
import ScholiqSettings from './views/ScholiqSettings.vue'

useAppManifest('scholiq', bundled)

new Vue({
  el: '#content',
  render: h => h(CnAppRoot, {
    props: {
      customComponents: { ScholiqSettings },
    },
  }),
})
```

**There is no `src/router/index.js`** — `CnAppRoot` derives routes from `manifest.pages`. **There is no `src/components/OpenRegisterGuard.vue`** — `CnAppRoot` reads `manifest.dependencies` and renders the dependency-missing empty state via NC's `NcEmptyContent` automatically.

### 2.3 `webpack.config.js`

Standard NC alias + dedup pattern (unchanged from idiomatic Conduction setups):

```js
config.resolve.alias = {
  ...config.resolve.alias,
  vue$: path.resolve(__dirname, 'node_modules/vue/dist/vue.esm-bundler.js'),
}
config.resolve.dedupe = ['vue', 'pinia', '@nextcloud/vue']
```

### 2.4 `package.json` script

Per ADR-024 §5, every consumer adds the validation gate:

```json
{
  "scripts": {
    "check:manifest": "node node_modules/@conduction/nextcloud-vue/scripts/validate-manifest.js src/manifest.json"
  }
}
```

CI fails if the manifest does not validate.

### 2.5 Optional backend manifest endpoint (ADR-024 §4)

`page#manifest` route returns either the bundled manifest as-is (v0.1) or a partial override blob from `IAppConfig` (v0.2+ for admin-customised menu order / hidden pages). The endpoint is opt-in per ADR-024; v0.1 returns the bundled blob unchanged so the frontend loader's silent-fallback path is exercised.

---

## 3. `ScholiqSettings.vue` — the only custom Vue view in the wedge

Settings UI lives in a single `customComponents` page declared in the manifest. It hosts:

1. **OpenRegister default register** picker (single `IAppConfig` key `default_register`).
2. **AI features** read-only table — sourced from `AiFeature` schema objects via OR's REST API (one row per declared feature, lifecycle state shown). Toggling a feature opens the lifecycle-transition modal which writes through OR; the `AiFeatureDpoAckGuard` rejects toggles without DPO acknowledgement.
3. **Credential signing key** widget — calls a `CredentialSigningController` admin endpoint to mint a key. Cryptographic, legitimate PHP per ADR-031.

Every "settings field" that is really a state machine (cmi5 enabled, compliance regulations list, AI Act feature flags, audit retention overrides) lives as schema metadata in `lib/Settings/scholiq_register.json` — they don't appear as `IAppConfig` keys.

---

## 4. PHP files that ship in this change

The full list — every file justified by ADR-031:

| File | Lines (target) | ADR-031 category |
|---|---|---|
| `appinfo/info.xml` | ~25 | Manifest declaration |
| `appinfo/routes.php` | ~30 | NC framework requirement |
| `lib/AppInfo/Application.php` | ~50 | DI registration for the two services below |
| `lib/Settings/scholiq_register.json` | declarative | Schema source for Course, Lesson, Enrolment, Credential, Regulation, Attestation, LearnerProfile, XapiStatement, AiFeature (full set lands across the spec changes) |
| `lib/Service/Cmi5LaunchTokenService.php` | ~80 | Cryptographic operation — JWT signing for cmi5 AU launches |
| `lib/Controller/PageController.php` | ~30 | NC framework requirement — renders main.php + manifest endpoint |
| `lib/Controller/Cmi5LaunchController.php` | ~40 | Wires the launch service into a route |
| `templates/main.php` | ~20 | Bootstrap the SPA |
| `src/manifest.json` | declarative | ADR-024 source of truth |
| `src/main.js` | ~15 | Wire CnAppRoot |
| `src/views/ScholiqSettings.vue` | ~150 | The single custom settings page |

No `AuditTrail`, no `AuditedController`, no `AiFeatureRegistry`, no `AdminSettings.php`, no `PersonalSettings.php`, no `OpenRegisterGuard` service.

---

## 5. i18n

```
l10n/
  nl.js    -- Dutch translations (canonical UI surface)
  en.js    -- English translations (fallback)
  nl.json  -- PHP-side translations
  en.json  -- PHP-side translations
```

Manifest `label` / `title` fields are translation keys (per ADR-024 §6); `t()` resolves them at render time inside `CnAppRoot`. `i18n-ci` GitHub Actions check validates that every key in `en.json` has a corresponding entry in `nl.json`.

---

## 6. Audit-trail consumption — what changed from v1

Per ADR-008 (rewritten 2026-05-11) and ADR-022, **Scholiq does not own an audit-trail substrate**. Every state-changing behaviour is declared via `x-openregister-lifecycle` / `x-openregister-notifications` on a Scholiq schema in `lib/Settings/scholiq_register.json`. OR's lifecycle engine emits audit entries automatically.

For this change specifically:
- Course / Lesson lifecycle audits — declared in the `course-management` change.
- Enrolment lifecycle audits — declared in the `enrolment` change.
- Credential lifecycle audits — declared in the `certification` change.
- Attestation + Regulation audits — declared in the `compliance-audit` change.
- AI feature flag audits — declared in this change's schema patch (the `AiFeature` schema with `x-openregister-lifecycle` + `x-openregister-notifications`).
- The xAPI LRS endpoint writes to the `XapiStatement` schema (declared in `course-management`); OR's lifecycle engine emits the `xapi.statement.received` audit entry automatically.

The `LrsController` IS legitimate PHP (external-system contract per ADR-031). It does not call any `AuditTrail::record()` — it calls `ObjectService::saveObject('XapiStatement', ...)` and OR handles the audit.

---

## 7. Integration Points

| Integration | OCP Interface | Usage |
|---|---|---|
| User auth | `OCP\IUserSession` | actor in OR audit entries (OR reads it from the session) |
| App config | `OCP\IAppConfig` | single key `default_register` |
| User config | `OCP\IConfig` | no v0.1 keys — user preferences are LearnerProfile schema fields |
| Group management | `OCP\IGroupManager` | isAdmin() check used by the settings page |
| Crypto | `OCP\Security\ICrypto` | cmi5 JWT signing key, credential signing key (both legitimate per ADR-031) |
| Root folder | `OCP\Files\IRootFolder` | course content folder creation (course-management change) |
| OR objects | `OCA\OpenRegister\Service\ObjectService` | every persistent write |

---

## 8. Risks

| Risk | Mitigation |
|---|---|
| `CnAppRoot` doesn't yet support `customComponents: { ScholiqSettings }` per latest manifest schema | Pin `@conduction/nextcloud-vue` to a version that does; integration test asserts the page renders. |
| `validateManifest` produces false-positives on Scholiq-specific fields | Manifest stays strictly within the canonical schema; if Scholiq needs an extension, follow ADR-024 §10 (closed `type` enum — library-level openspec change). |
| Backend `/api/manifest` returns shape that overrides incorrectly | v0.1 returns the bundled blob unchanged; the override hook is a deliberate v0.2 milestone. |
| `AiFeatureDpoAckGuard` cannot block transitions when triggered from OR's UI rather than Scholiq's | OR's lifecycle engine resolves `requires:` PHP class references via DI; integration test calls the transition through OR's REST API and asserts the guard fires. |
