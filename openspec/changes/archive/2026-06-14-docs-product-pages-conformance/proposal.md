## Why

Scholiq's docs site is non-conformant with the canonical Conduction product-pages spec (ADR-030): it is missing the four required top-level folders (`Features/`, `UseCases/`, `Integrations/`, `Technical/`), has 7 legacy root-level MDs in wrong locations, `tutorials/` has the wrong canonical name, `installation.md` is absent, and the site has 74+ em-dash violations — the worst in the fleet. No Redocusaurus API route or Dutch locale scaffold exists. This PR ships the mechanical Tier-1 + Tier-2 conformance work; Tier-3 content authoring is tracked via issues #73, #74, #75.

## What Changes

- **Create** four canonical top-level folders: `docs/Features/`, `docs/UseCases/`, `docs/Integrations/`, `docs/Technical/`
- **Move** `docs/FEATURES.md` → `docs/Features/features.md`
- **Move** `docs/ARCHITECTURE.md` → `docs/Technical/architecture.md`
- **Move** `docs/ADMIN-GUIDE.md` → `docs/Technical/admin-guide.md`
- **Move** `docs/API.md` → `docs/Technical/api.md`
- **Move** `docs/DESIGN-REFERENCES.md` → `docs/Technical/design-decisions.md`
- **Move** `docs/SPECS.md` → `docs/Technical/specs.md`
- **Move** `docs/USER-GUIDE.md` → `docs/user-guide/index.md`
- **Rename** `docs/tutorials/` → `docs/user-guide/` (11 MD files + category JSONs + screenshots)
- **Create** `docs/UseCases/index.md` stub (draft: true, refs #73)
- **Create** `docs/Integrations/index.md` stub (draft: true, refs #73)
- **Create** `docs/installation.md` with real Nextcloud App Store + initial config steps for scholiq
- **Fix** internal links in `docs/intro.md` (`./ARCHITECTURE` → `./Technical/architecture`, etc.)
- **Em-dash sweep**: replace ` — ` with `, ` across all docs MD files (74+ hits in root MDs + tutorials — closes #76)
- **Add** `redocusaurus@^2.0.0` to `docs/package.json`
- **Configure** Redocusaurus in `docusaurus.config.js` at `/api` route fed by `static/oas/scholiq.json`
- **Add** `API Documentation` navbar link pointing to `/api`
- **Create** `static/oas/scholiq.json` minimal OAS 3.0 placeholder stub (real spec via #75)
- **Scaffold** `i18n/nl/docusaurus-plugin-content-docs/current/` empty directory tree
- **Re-enable** `nl` locale in `docusaurus.config.js` with SSR escape-hatch comment

## Capabilities

### New Capabilities

- `docs-product-pages`: Canonical docs folder taxonomy, installation guide, Redocusaurus API route, NL locale scaffold, and em-dash-clean content across the scholiq docs site.

### Modified Capabilities

*(none — this is a docs-site conformance change; no PHP/Vue application capabilities are modified)*

## Impact

- `docs/` tree restructured — 22 files moved/created/renamed
- `docs/docusaurus.config.js` updated (Redocusaurus plugin, navbar, locales)
- `docs/package.json` gains `redocusaurus` dependency
- `docs/static/oas/scholiq.json` created (new, placeholder)
- `docs/i18n/nl/` scaffolded (empty, no translated content — ref #74)
- Internal link fixes in `docs/intro.md`
- Em-dash fixes across all docs MD files (closes #76)
- No PHP, no Vue, no tests, no Nextcloud app code touched
