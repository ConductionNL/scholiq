# app-metadata Specification

## Purpose
TBD - created by archiving change fix-manifest-licence-eupl. Update Purpose after archive.
## Requirements
### Requirement: The manifest licence matches the repository's actual licence

`appinfo/info.xml` `<licence>` MUST declare the licence the code is actually distributed
under. Because the `LICENSE` file, `composer.json`, `publiccode.yml`, and every `lib/**`
SPDX header declare **EUPL-1.2**, the manifest MUST declare `EUPL-1.2` (valid in
Nextcloud's `app-info.xsd` for the app's declared `min-version="32"`), not `agpl`.

#### Scenario: The manifest licence is consistent with the source of truth

- **WHEN** the app manifest is validated against the repository's licence signals
- **THEN** `info.xml` `<licence>` MUST equal `EUPL-1.2`
- **AND** it MUST match `LICENSE`, `composer.json`, `publiccode.yml`, and the `lib/**` SPDX headers

@e2e exclude static metadata consistency check, not a UI flow.

