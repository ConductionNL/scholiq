# Retrofit — app-shell settings, config & observability (nextcloud-app)

Describes the observed behavior of 21 methods that make up Scholiq's app-shell
settings, configuration state, and observability surface as 5 new REQs on the
`nextcloud-app` capability. Code already exists — this change retroactively
specifies it.

## Affected code units
- lib/Controller/SettingsController.php::index, ::create
- lib/Service/SettingsService.php::getSettings, ::updateSettings
- src/store/modules/settings.js::fetchSettings, ::saveSettings
- src/views/settings/Settings.vue::created, ::save
- src/views/ScholiqSettings.vue::created, ::fetchRegisters, ::fetchAiFeatures, ::saveDefaultRegister, ::rotateSigningKey
- src/views/settings/AdminRoot.vue::created
- src/store/store.js::initializeStores
- src/store/modules/object.js::configure, ::registerObjectType, ::fetchObjects
- lib/Controller/HealthController.php::index
- lib/Controller/PageController.php::manifest

## Approach
- For each method: describe observed inputs, outputs, pre/postconditions, failure modes.
- Draft REQs that match behavior (not aspirational).
- Notes sections surface observed-but-suspicious behavior (e.g. the AdminHealth
  endpoint returns placeholder 0/null for audit-trail counts pending an
  OpenRegister instrumentation API; rotateSigningKey reuses the settings/load
  re-import route rather than a dedicated key-rotation endpoint).

Source: openspec/coverage-report (gate-16 spec-coverage) generated 2026-05-25.
See the retrofit playbook in `.github/docs/claude/retrofit.md`.
