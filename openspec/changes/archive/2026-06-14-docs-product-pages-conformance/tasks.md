## 1. Folder taxonomy and file moves

- [x] 1.1 Create canonical dirs `docs/Features/`, `docs/Technical/`, `docs/UseCases/`, `docs/Integrations/`
- [x] 1.2 `git mv` root MDs: `FEATURES.md` → `Features/features.md`; `ARCHITECTURE.md`, `ADMIN-GUIDE.md`, `API.md`, `DESIGN-REFERENCES.md` → `design-decisions.md`, `SPECS.md` → `Technical/`
- [x] 1.3 `git mv docs/tutorials docs/user-guide`; update all `_category_.json` labels from "Tutorials" to "User Guide"; then `git mv docs/USER-GUIDE.md docs/user-guide/index.md`

## 2. New content files

- [x] 2.1 Create `docs/UseCases/index.md` and `docs/Integrations/index.md` with `draft: true` frontmatter pointing at #73
- [x] 2.2 Create `docs/installation.md` with prerequisites (Nextcloud 28+, OpenRegister, OpenConnector), App Store install steps, register configuration, first-login checklist, troubleshooting

## 3. Em-dash sweep across docs/

- [x] 3.1 `Edit replace_all` ` — ` → `, ` across all moved/new docs (`Features/`, `Technical/`, `user-guide/`, `intro.md`, `_category_.json` files)
- [x] 3.2 Fix internal links in `docs/intro.md`: `./ARCHITECTURE` → `./Technical/architecture`, `./FEATURES` → `./Features/features`, `./DESIGN-REFERENCES` → `./Technical/design-decisions`
- [x] 3.3 Verify `git grep -E '—' docs/` returns zero matches

## 4. Redocusaurus (Tier-2)

- [x] 4.1 Add `"redocusaurus": "^2.0.0"` to `docs/package.json`; create `docs/static/oas/scholiq.json` minimal OAS 3.0.3 stub
- [x] 4.2 Wire redocusaurus plugin + `/api` route + "API Documentation" navbar item (right) in `docs/docusaurus.config.js`

## 5. Dutch locale scaffold (Tier-2)

- [x] 5.1 Create `docs/i18n/nl/docusaurus-plugin-content-docs/current/` (empty, ready for #74)
- [x] 5.2 Update `docs/docusaurus.config.js` locales `['en']` → `['en','nl']` with NL config and `// TODO #74` escape-hatch comment (escape hatch applied: SSR race condition on Docusaurus 3.9.2 reverted to `['en']` with TODO #74)

## 6. Build verification

- [x] 6.1 `npm install --legacy-peer-deps && npm run build` in `docs/` exits 0; if NL locale breaks SSR, revert to `['en']` with `// TODO #74`
