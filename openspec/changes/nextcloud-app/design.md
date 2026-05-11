# Design — Nextcloud App Shell

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

Hard declaring `openregister` and `openconnector` in `<dependencies>` causes NC to block activation if either is absent, satisfying REQ-NA-001.

### 1.2 `lib/AppInfo/Application.php`

Registers all DI services in `register(IRegistrationContext $context)`:

| Service | Class | Scope |
|---|---|---|
| `AuditTrail` | `Scholiq\Service\AuditTrail` | singleton |
| `AiFeatureRegistry` | `Scholiq\Service\AiFeatureRegistry` | singleton |
| `NotificationService` | `Scholiq\Service\NotificationService` | singleton |
| `OpenRegisterGuard` | `Scholiq\Service\OpenRegisterGuard` | singleton |
| Admin settings | `Scholiq\Settings\AdminSettings` | n/a |
| Personal settings | `Scholiq\Settings\PersonalSettings` | n/a |

Route registration in `registerRoutes()`:
```php
['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
['name' => 'admin_settings#save', 'url' => '/api/settings/admin', 'verb' => 'POST'],
['name' => 'user_settings#save',  'url' => '/api/settings/user',  'verb' => 'POST'],
['name' => 'ai_feature#dossier',  'url' => '/api/ai-features/{slug}/dossier', 'verb' => 'GET'],
```

Downstream specs add their routes in subsequent PRs; the route file is an index-file pattern (one `routes.php` include per capability).

---

## 2. OpenRegister Dependency Guard

`Scholiq\Service\OpenRegisterGuard` is injected into `PageController::index()`. It calls `OCP\App\IAppManager::isInstalled('openregister')`. If false:

- PHP renders `templates/main.php` with a `guardFailed = true` flag.
- Vue SPA reads `window.scholiq_config.guard_failed` and renders `<OpenRegisterGuard />` instead of the router view.
- `<OpenRegisterGuard />` is a full-page `NcEmptyContent` with:
  - Title (NL): "OpenRegister is verplicht"
  - Description: "Scholiq vereist de OpenRegister app. Installeer OpenRegister om door te gaan."
  - Action button (admin only): "Installeer OpenRegister" — links to `/index.php/settings/apps/app/openregister`.
  - Non-admin: "Neem contact op met uw beheerder."

---

## 3. Vue SPA Architecture

### 3.1 Entry point

```
src/
  main.js               -- Vue.createApp, router, pinia, i18n registration
  App.vue               -- <template><NcContent><router-view /></NcContent></template>
  router/
    index.js            -- createWebHashHistory; base routes only; downstream specs import-extend
  stores/
    app.js              -- global app state (user, role, config)
  components/
    OpenRegisterGuard.vue
```

### 3.2 Router structure

```js
// src/router/index.js
import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  { path: '/', redirect: '/dashboard' },
  // Loaded by downstream spec PRs:
  // { path: '/courses', ... }
  // { path: '/enrolments', ... }
  // { path: '/compliance', ... }
  // { path: '/dashboard', ... }
]

export default createRouter({ history: createWebHashHistory(), routes })
```

Hash mode is non-negotiable (REQ-NA-002): Nextcloud's PHP routing owns the path before `#`; everything after is Vue Router's domain.

### 3.3 webpack.config.js — alias + dedup

```js
config.resolve.alias = {
  ...config.resolve.alias,
  // conditional: use nc-shipped vue if present, else node_modules
  vue$: path.resolve(__dirname, 'node_modules/vue/dist/vue.esm-bundler.js'),
}
config.resolve.dedupe = ['vue', 'pinia', '@nextcloud/vue']
```

This prevents duplicate Vue instances when `@conduction/nextcloud-vue` packages its own `vue` peer.

---

## 4. Settings

### 4.1 Admin Settings (`lib/Settings/AdminSettings.php`)

Implements `OCP\Settings\ISettings`. Section: `OCP\Settings\IManager::KEY_ADMIN_SETTINGS`. Priority: 50. Template: `templates/admin-settings.php` (bootstraps a micro-Vue component `admin-settings.js`).

Admin settings fields (v0.1 wedge scope):

| Field | IAppConfig key | Type | Default |
|---|---|---|---|
| OpenRegister default register | `default_register` | string (OR register id) | empty |
| cmi5 enabled | `cmi5_enabled` | bool | true |
| Compliance regulations (seeded list) | `compliance_regulations` | JSON | AVG,BIO,NIS2 |
| AI Act feature flags | `ai_act_high_risk_features` | JSON list | [] |
| Audit retention override | `audit_retention_days` | int | 2555 (7y) |

### 4.2 Personal Settings (`lib/Settings/PersonalSettings.php`)

Implements `OCP\Settings\ISettings`. Section: `IManager::KEY_PERSONAL_SETTINGS`.

Personal settings fields (v0.1 scope):

| Field | IConfig key | Type | Default |
|---|---|---|---|
| Default view | `default_view` | enum: list/cards | `list` |
| Items per page | `items_per_page` | int | 25 |
| Default sort | `default_sort` | string | `-updated_at` |
| Notify compliance due | `notify_compliance_renewal` | bool | true |
| Notify assignments | `notify_assignments` | bool | true |
| UI language override | `language` | enum: nl/en | account default |

---

## 5. AuditTrail Service (ADR-008 implementation)

### 5.1 Interface

```php
namespace Scholiq\Service;

interface AuditTrailInterface
{
    public function record(string $eventType, array $payload): string; // returns event UUID
}
```

### 5.2 Implementation

```php
class AuditTrail implements AuditTrailInterface
{
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IRequest $request,
        private readonly ObjectService $objectService, // OpenRegister
    ) {}

    public function record(string $eventType, array $payload): string
    {
        AuditEventTypes::assertKnown($eventType); // throws on unknown type

        $event = [
            'event_type'    => $eventType,
            'actor_type'    => 'user',
            'actor_id'      => $this->userSession->getUser()?->getUID() ?? 'system',
            'actor_ip'      => $this->request->getRemoteAddress(),
            'subject_type'  => $payload['subject_type'] ?? null,
            'subject_id'    => $payload['subject_id'] ?? null,
            'verb'          => $payload['verb'] ?? null,
            'before'        => $payload['before'] ?? null,
            'after'         => $payload['after'] ?? null,
            'reason'        => $payload['reason'] ?? null,
            'lawful_basis'  => $payload['lawful_basis'] ?? null,
            'correlation_id'=> $payload['correlation_id'] ?? Uuid::v4(),
            'tenant_id'     => $payload['tenant_id'] ?? null,
            'created_at'    => (new \DateTime())->format('c'),
        ];
        // signature added by openregister schema (HMAC-SHA256 via ICrypto) if tenant opt-in
        return $this->objectService->saveObject('scholiq-audit-event', $event)['id'];
    }
}
```

### 5.3 AuditEventTypes bootstrap

```php
// lib/Bootstrap/AuditEventTypes.php
class AuditEventTypes
{
    public const KNOWN = [
        // Enrolment lifecycle
        'enrolment.created', 'enrolment.completed', 'enrolment.withdrawn',
        // Credential lifecycle
        'credential.issued', 'credential.revoked', 'credential.expired', 'credential.verified',
        // Attestation
        'attestation.signed', 'attestation.revoked',
        // Compliance
        'compliance.audit_pack.exported', 'compliance.regulation.published',
        // AI Act (ADR-005)
        'ai.decision.recorded', 'ai.feature.flag.toggled',
        // Course lifecycle
        'course.published', 'course.archived',
        // Security / NIS2
        'security.login.failed', 'security.role.changed', 'security.config.changed',
        // xAPI (ADR-002)
        'xapi.statement.received',
        // App shell
        'settings.admin.saved', 'settings.user.saved',
    ];
}
```

---

## 6. AiFeatureRegistry Skeleton (ADR-005 implementation)

```php
// lib/Service/AiFeatureRegistry.php
class AiFeatureRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $features = [];  // empty in v0.1

    public function all(): array { return $this->features; }

    public function register(string $slug, array $metadata): void
    {
        $this->features[$slug] = $metadata;
    }
}
```

Bootstrap file `lib/Bootstrap/AiFeatures.php` calls `register()` for each feature. In v0.1, this file has zero `register()` calls. Phase 3 PRs add entries here.

---

## 7. OpenRegister Schema — `scholiq-audit-event`

Location: `openregister/schemas/scholiq-audit-event.json`

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "scholiq-audit-event",
  "append_only": true,
  "properties": {
    "id":            { "type": "string", "format": "uuid" },
    "tenant_id":     { "type": "string", "format": "uuid" },
    "event_type":    { "type": "string" },
    "actor_type":    { "type": "string", "enum": ["user","system","external","ai-system"] },
    "actor_id":      { "type": "string" },
    "actor_ip":      { "type": "string" },
    "subject_type":  { "type": ["string","null"] },
    "subject_id":    { "type": ["string","null"], "format": "uuid" },
    "verb":          { "type": ["string","null"], "format": "uri" },
    "before":        { "type": ["object","null"] },
    "after":         { "type": ["object","null"] },
    "reason":        { "type": ["string","null"] },
    "lawful_basis":  { "type": ["string","null"] },
    "correlation_id":{ "type": "string", "format": "uuid" },
    "created_at":    { "type": "string", "format": "date-time" },
    "signature":     { "type": ["string","null"] }
  },
  "required": ["event_type","actor_type","actor_id","created_at"],
  "indexes": [
    ["tenant_id","event_type","created_at"],
    ["subject_id","created_at"],
    ["actor_id","created_at"],
    ["correlation_id"]
  ]
}
```

---

## 8. Base Controller

`Scholiq\Controllers\AuditedController` extends `OCP\AppFramework\Controller`:

```php
abstract class AuditedController extends Controller
{
    private bool $auditRecorded = false;

    protected function afterController(Request $request, Response $response): Response
    {
        if ($this->isStateChangingVerb($request->getMethod())
            && $response->getStatus() < 300
            && !$this->auditRecorded
        ) {
            // In dev: throw 500; in production: Sentry warning
            \OC::$server->getLogger()->warning('State-changing endpoint returned 2xx without audit record', [
                'controller' => static::class,
                'path' => $request->getPathInfo(),
            ]);
        }
        return $response;
    }

    protected function markAuditRecorded(): void { $this->auditRecorded = true; }
}
```

All downstream spec controllers extend `AuditedController`.

---

## 9. i18n Structure

```
l10n/
  nl.js    -- Dutch translations (canonical)
  en.js    -- English translations (fallback)
  nl.json  -- PHP-side translations
  en.json  -- PHP-side translations
```

Tooling: `tx` (Transifex CLI) for translation sync. i18n-ci check in GitHub Actions validates that every key in `en.json` has a corresponding entry in `nl.json`.

---

## 10. Integration Points

| Integration | OCP Interface | Usage |
|---|---|---|
| User auth | `OCP\IUserSession` | actor_id in audit events; admin check |
| App config | `OCP\IAppConfig` | admin settings persistence |
| User config | `OCP\IConfig` | personal settings persistence |
| Group management | `OCP\IGroupManager` | isAdmin() check |
| Activity | `OCP\Activity\IManager` | audit-trail tab feed |
| Notifications | `OCP\Notification\IManager` | notification dispatch (downstream specs call this) |
| Background jobs | `OCP\BackgroundJob\IJobList` | TimedJob registrations (downstream specs add jobs) |
| Crypto | `OCP\Security\ICrypto` | HMAC key for audit event signatures |
| Root folder | `OCP\Files\IRootFolder` | course content folder creation (course-management spec) |

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| OpenRegister schema `append_only` flag may not be supported in all OR versions | Pin OR dependency to the version that ships append_only support; CI test validates the flag |
| Duplicate Vue instances from @conduction/nextcloud-vue peer | webpack dedup alias (§3.3); CI bundle-size check catches regressions |
| PHPStan custom rule for AuditTrail enforcement adds CI complexity | Use an existing PHPStan extension base class; rule is simple AST visitor on method call matching |
| Admin settings saving wrong config key breaks downstream specs | Integration test per config key; seeded test config in test fixtures |
