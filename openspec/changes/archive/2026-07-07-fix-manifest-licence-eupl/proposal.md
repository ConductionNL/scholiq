---
kind: config
---

# Proposal: fix-manifest-licence-eupl

## Why

`appinfo/info.xml` declares `<licence>agpl</licence>`, but every other licence signal in
the repository says **EUPL-1.2**: the `LICENSE` file ("EUROPEAN UNION PUBLIC LICENCE v.
1.2"), `composer.json` (`"license": "EUPL-1.2"`), `publiccode.yml` (`license: EUPL-1.2`),
and the `lib/**` SPDX headers. The `<licence>` element is the value the Nextcloud App
Store and SPDX scanners publish, so scholiq currently advertises the wrong licence — a
substantive, machine-read misstatement, not a cosmetic one.

Unlike some fleet apps, scholiq is **eligible to fix this in the manifest today**:
`info.xml` declares `<nextcloud min-version="32" max-version="34"/>`, and Nextcloud's
`app-info.xsd` accepts `EUPL-1.2` as a `<licence>` value since NC 31 — so there is no
older-NC-store constraint forcing the `agpl` workaround here. The manifest can carry the
true licence.

## What Changes

- `appinfo/info.xml`: change `<licence>agpl</licence>` to `<licence>EUPL-1.2</licence>`.
  Nothing else changes; `min-version="32"` already satisfies the xsd's EUPL support.
- No `lib/`/SPDX change (headers are already `EUPL-1.2`); no LICENSE/composer/publiccode
  change (already correct). This change only removes the single contradictory signal.

## Impact

- Affected: `appinfo/info.xml` (one element). The App Store listing and SPDX/REUSE
  scanners will report EUPL-1.2, matching the actual licence the code is distributed
  under.
- No runtime, dependency, or behavioural impact.
