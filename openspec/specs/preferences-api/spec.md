---
slug: preferences-api
title: Preferences API — per-user key/value preference endpoints for scholiq
status: implemented
retrofit: true
created: 2026-05-26
updated: 2026-05-31
---

# Preferences API

## Why

This app exposes a generic per-user preferences endpoint (read/write a small key/value flag, backed by Nextcloud `IConfig` user values) consumed by shared `@conduction/nextcloud-vue` widgets that need to persist a cross-device UI flag without a bespoke endpoint per feature. This capability spec retroactively documents the observable contract of that endpoint.

## Requirements

### Requirement: Get user preference (REQ-PREF-001)
The system SHALL return a stored per-user preference value for a given key (scoped to the current user), or a default when unset. The get-preference endpoint MUST require an authenticated user, MUST sanitize the requested key to a safe charset within the `pref_` namespace, and MUST return the stored value (or null when unset). An unauthenticated request MUST be rejected and an invalid key MUST yield a bad-request response.

#### Scenario: Unauthenticated read rejected
- **GIVEN** no logged-in user
- **WHEN** get-preference is called
- **THEN** the response MUST be 401 Unauthorized

#### Scenario: Key is sanitized
- **GIVEN** a key containing unsafe characters
- **WHEN** the value is read
- **THEN** only the sanitized key within the `pref_` namespace MUST be consulted

### Requirement: Set user preference (REQ-PREF-002)
The system SHALL persist a per-user preference value for a given key scoped to the authenticated user. The set-preference endpoint MUST require an authenticated user, MUST sanitize the key, MUST store a non-empty value, and MUST clear the value when an empty string is supplied; an unauthenticated request MUST be rejected and an invalid key MUST yield a bad-request response.

#### Scenario: Empty value clears the preference
- **GIVEN** an existing stored preference
- **WHEN** set-preference is called with an empty value
- **THEN** the preference MUST be deleted and null returned

## OpenSpec changes

- [retrofit-2026-05-26-preferences-api](../../changes/archive/2026-05-31-retrofit-2026-05-26-preferences-api/) _(archived 2026-05-31)_

## Notes

- This spec captures observed behaviour of `lib/Controller/PreferencesController.php`. It is a retrofit — it does not propose changes.
