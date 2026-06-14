---
status: draft
---

# Nextcloud App Shell

## Purpose

Align the settings surfaces with their correct audiences: instance-wide configuration (default register, AI features, credential-signing key) belongs in the registered Nextcloud **Admin** settings panel behind `#[AuthorizedAdminSetting]`, not in the per-user app settings dialog. The per-user dialog instead exposes the things a user should control — their notification preferences. Also normalise the navigation icons onto a single monochrome family.

## MODIFIED Requirements

### Requirement: Configure default register and AI features via OpenRegister-backed pickers
The default-register picker and the AI-features review table MUST live in the Nextcloud **Admin** settings panel, registered through `appinfo/info.xml` `<settings>` (an admin `IDelegatedSettings` class plus an admin `IIconSection`) and guarded so only administrators can reach the mutating endpoints. They MUST NOT be rendered in the per-user app "User settings" dialog. The register options MUST be loaded from OpenRegister's `/apps/openregister/api/registers` endpoint; the AI feature list MUST be read from the Scholiq Settings API response (`aiFeatures`). Selecting a default register MUST persist it via the Settings API. Loading failures MUST be caught and logged without breaking the panel.

#### Scenario: Admin panel hosts the pickers
- **WHEN** an administrator opens Nextcloud Settings → Administration → Scholiq
- **THEN** the default-register picker and AI-features table are shown and the register/AI lists load from OpenRegister and the Settings API

#### Scenario: Non-admin cannot reach the pickers
- **GIVEN** a signed-in non-admin user
- **WHEN** they open the Scholiq app's per-user "User settings" dialog
- **THEN** no register picker, AI-features table, or credential-signing control is present

### Requirement: Allow the credential signing key to be rotated from settings
The credential-signing key rotation action MUST live in the Nextcloud **Admin** settings panel and MUST be invokable only by an administrator. It MUST rotate the tenant's RS256 credential signing key and surface a localized success/failure message.

#### Scenario: Admin rotates the signing key
- **WHEN** an administrator triggers the rotate-signing-key action in the admin panel
- **THEN** the key-rotation endpoint is called and a localized success or failure message is shown

## ADDED Requirements

### Requirement: Per-user notification preferences in the user settings dialog
The per-user app "User settings" dialog MUST present the user's Scholiq notification preferences as toggles and MUST read and write them through OpenRegister's override-only notification-preferences endpoint (`GET`/`PUT /apps/openregister/api/notification-preferences`), so a toggle genuinely gates delivery via OpenRegister's dispatcher. The dialog MUST NOT introduce a parallel scholiq-local preference store. Each toggle MUST correspond to a declared `(schema, notification)` rule and MUST be labelled with an English source string (Dutch via l10n).

#### Scenario: User disables a notification type
- **GIVEN** the per-user settings dialog listing Scholiq notification types
- **WHEN** the user turns off "Credential issued" and saves
- **THEN** a `PUT /apps/openregister/api/notification-preferences` records the override for that `(schema, notification)` pair
- **AND** the user no longer receives that notification

#### Scenario: Preferences reflect current overrides
- **WHEN** the per-user settings dialog opens
- **THEN** it loads the current overrides via `GET /apps/openregister/api/notification-preferences` and renders each toggle in its stored state (default on)

### Requirement: Consistent monochrome navigation icons
Every `menu[]` entry in `src/manifest.json` MUST use an icon from the monochrome Nextcloud `icon-*` family so the navigation renders in a single consistent colour; coloured `icon-category-*` glyphs MUST NOT be mixed into the menu.

#### Scenario: All menu icons are monochrome
- **WHEN** the manifest `menu` array is inspected
- **THEN** every entry's `icon` value is a monochrome `icon-*` class and none is an `icon-category-*` value
