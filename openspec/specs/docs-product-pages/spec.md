@e2e exclude All scenarios verify filesystem layout (docs/ folder existence, file presence) and build tool execution (npm run build exit code) — these are CI checks, not browser-observable behaviors. Covered by CI build pipeline.

## ADDED Requirements

### Requirement: Canonical folder taxonomy
The docs site SHALL organise content into four canonical top-level folders: `Features/`, `UseCases/`, `Integrations/`, and `Technical/`, matching the Conduction product-pages spec (ADR-030). All legacy root-level Markdown files SHALL be moved into the appropriate canonical folder.

#### Scenario: Features folder present
- **WHEN** the docs site is built
- **THEN** `docs/Features/` exists and contains at least one Markdown file

#### Scenario: Technical folder present
- **WHEN** the docs site is built
- **THEN** `docs/Technical/` exists and contains `architecture.md`, `admin-guide.md`, `api.md`, `design-decisions.md`, and `specs.md`

#### Scenario: UseCases stub present
- **WHEN** the docs site is built
- **THEN** `docs/UseCases/index.md` exists with `draft: true` frontmatter

#### Scenario: Integrations stub present
- **WHEN** the docs site is built
- **THEN** `docs/Integrations/index.md` exists with `draft: true` frontmatter

### Requirement: User guide folder name
The tutorial/how-to content folder SHALL be named `user-guide/` (canonical name per ADR-030), not `tutorials/`.

#### Scenario: user-guide folder present
- **WHEN** the docs site is built
- **THEN** `docs/user-guide/` exists and `docs/tutorials/` does NOT exist

### Requirement: Installation guide
The docs site SHALL contain a root-level `installation.md` page with step-by-step instructions for installing Scholiq from the Nextcloud App Store, including prerequisites and initial configuration.

#### Scenario: Installation page renders
- **WHEN** a user navigates to `/docs/installation`
- **THEN** the page renders with prerequisites, install steps, and initial configuration sections

### Requirement: Em-dash-free content
All Markdown files under `docs/` SHALL contain zero occurrences of the em-dash character (U+2014) used as a prose separator (` — `). Hyphens and en-dashes in code blocks, URLs, or technical identifiers are exempt.

#### Scenario: Em-dash sweep passes
- **WHEN** `git grep -E '—' docs/` is run after the migration
- **THEN** the command returns no matches

### Requirement: Redocusaurus API route
The docs site SHALL mount a Redocusaurus-powered API documentation route at `/api`, fed by `static/oas/scholiq.json`.

#### Scenario: API route accessible
- **WHEN** the site is built and served
- **THEN** navigating to `/api` renders the Redocusaurus API documentation page

#### Scenario: Navbar API link present
- **WHEN** any page on the docs site is viewed
- **THEN** the navbar contains an "API Documentation" link that navigates to `/api`

### Requirement: Dutch locale scaffold
The docs site SHALL have `nl` re-enabled in `locales` and SHALL have the `i18n/nl/docusaurus-plugin-content-docs/current/` directory scaffolded (empty), ready for translated Markdown content (tracked via #74).

#### Scenario: NL locale in config
- **WHEN** `docs/docusaurus.config.js` is read
- **THEN** the `locales` array contains both `'en'` and `'nl'`

#### Scenario: i18n/nl directory exists
- **WHEN** the repo is checked out
- **THEN** `docs/i18n/nl/docusaurus-plugin-content-docs/current/` directory exists

### Requirement: Docs build passes
The docs site SHALL build without errors (`npm run build` exits 0) after all conformance changes are applied.

#### Scenario: Clean build
- **WHEN** `npm install --legacy-peer-deps && npm run build` is run in `docs/`
- **THEN** the process exits with code 0
