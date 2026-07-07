# Tasks: fix-manifest-licence-eupl

- [ ] 1.1 In `appinfo/info.xml`, change `<licence>agpl</licence>` to `<licence>EUPL-1.2</licence>` (Edit tool).
  - **spec_ref**: `specs/app-metadata/spec.md#requirement-the-manifest-licence-matches-the-repositorys-actual-licence`
  - **acceptance_criteria**:
    - `info.xml <licence>` is `EUPL-1.2`
    - Matches LICENSE / composer.json / publiccode.yml / lib SPDX headers
    - The manifest still validates against `app-info.xsd` at `min-version="32"`
- [ ] 1.2 Verify: `grep -r '<licence>agpl' appinfo/` returns nothing; `openspec validate fix-manifest-licence-eupl --strict` is clean.
  - **spec_ref**: `specs/app-metadata/spec.md#requirement-the-manifest-licence-matches-the-repositorys-actual-licence`
  - **acceptance_criteria**:
    - No `agpl` licence token remains in the manifest
