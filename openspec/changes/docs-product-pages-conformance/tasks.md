## 1. Folder taxonomy — create canonical directories

- [ ] 1.1 Create `docs/Features/` directory (will hold features.md)
- [ ] 1.2 Create `docs/Technical/` directory (will hold architecture, admin-guide, api, design-decisions, specs)
- [ ] 1.3 Create `docs/UseCases/` directory (will hold stub index.md)
- [ ] 1.4 Create `docs/Integrations/` directory (will hold stub index.md)

## 2. File moves — root MDs into canonical folders

- [ ] 2.1 `git mv docs/FEATURES.md docs/Features/features.md`
- [ ] 2.2 `git mv docs/ARCHITECTURE.md docs/Technical/architecture.md`
- [ ] 2.3 `git mv docs/ADMIN-GUIDE.md docs/Technical/admin-guide.md`
- [ ] 2.4 `git mv docs/API.md docs/Technical/api.md`
- [ ] 2.5 `git mv docs/DESIGN-REFERENCES.md docs/Technical/design-decisions.md`
- [ ] 2.6 `git mv docs/SPECS.md docs/Technical/specs.md`
- [ ] 2.7 `git mv docs/USER-GUIDE.md docs/user-guide/index.md` (after tutorials→user-guide rename in task 3)

## 3. Rename tutorials/ → user-guide/

- [ ] 3.1 `git mv docs/tutorials docs/user-guide`
- [ ] 3.2 Update `docs/user-guide/_category_.json` — change label from "Tutorials" to "User Guide" and update description
- [ ] 3.3 Update `docs/user-guide/user/_category_.json` if label references "Tutorials"
- [ ] 3.4 Update `docs/user-guide/admin/_category_.json` if label references "Tutorials"

## 4. Create stub and new content files

- [ ] 4.1 Create `docs/UseCases/index.md` — `draft: true` frontmatter, placeholder body pointing to #73
- [ ] 4.2 Create `docs/Integrations/index.md` — `draft: true` frontmatter, placeholder body pointing to #73
- [ ] 4.3 Create `docs/installation.md` — real install steps: prerequisites (Nextcloud 28+, OpenRegister, OpenConnector), App Store install, initial register configuration, first-login checklist, troubleshooting

## 5. Em-dash sweep (74+ hits — largest step)

- [ ] 5.1 Em-dash sweep `docs/Features/features.md` (66 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.2 Em-dash sweep `docs/Technical/architecture.md` (55 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.3 Em-dash sweep `docs/intro.md` (11 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.4 Em-dash sweep `docs/Technical/admin-guide.md` (10 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.5 Em-dash sweep `docs/Technical/api.md` (11 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.6 Em-dash sweep `docs/Technical/design-decisions.md` (15 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.7 Em-dash sweep `docs/Technical/specs.md` (6 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.8 Em-dash sweep `docs/user-guide/index.md` (17 hits) — `Edit replace_all` ` — ` → `, `
- [ ] 5.9 Em-dash sweep `docs/user-guide/_category_.json` — fix any em-dash in description
- [ ] 5.10 Em-dash sweep `docs/user-guide/user/_category_.json` — fix any em-dash in description
- [ ] 5.11 Em-dash sweep `docs/user-guide/admin/_category_.json` — fix any em-dash in description
- [ ] 5.12 Em-dash sweep each tutorial file in `docs/user-guide/` that has hits (87 lines across 9 files)
- [ ] 5.13 Verify: `git grep -E '—' docs/` returns zero matches

## 6. Fix internal links

- [ ] 6.1 Update `docs/intro.md` links: `./ARCHITECTURE` → `./Technical/architecture`, `./FEATURES` → `./Features/features`, `./DESIGN-REFERENCES` → `./Technical/design-decisions`

## 7. Redocusaurus setup (Tier-2)

- [ ] 7.1 Add `"redocusaurus": "^2.0.0"` to `docs/package.json` dependencies
- [ ] 7.2 Create `docs/static/oas/` directory
- [ ] 7.3 Create `docs/static/oas/scholiq.json` — minimal valid OAS 3.0.3 stub (title: Scholiq API, version: 0.1.0)
- [ ] 7.4 Update `docs/docusaurus.config.js` — add redocusaurus plugin config pointing at `./static/oas/scholiq.json`, route `/api`
- [ ] 7.5 Add "API Documentation" navbar item (href: `/api`, position: right) to `docs/docusaurus.config.js`

## 8. Dutch locale scaffold (Tier-2)

- [ ] 8.1 Create directory `docs/i18n/nl/docusaurus-plugin-content-docs/current/` (empty, ready for #74)
- [ ] 8.2 Update `docs/docusaurus.config.js` — change `locales: ['en']` to `locales: ['en', 'nl']`, add NL locale config with label, add escape-hatch comment citing #74

## 9. Build verification

- [ ] 9.1 Run `npm install --legacy-peer-deps` in `docs/` — verify exits 0
- [ ] 9.2 Run `npm run build` in `docs/` — verify exits 0; if NL locale breaks SSR, revert to `['en']` with `// TODO #74` comment
- [ ] 9.3 Confirm `git grep -E '—' docs/` returns zero matches (em-dash gate)
