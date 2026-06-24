# Tasks: adopt-shared-menu-pipeline

## 1. Introduce the ADR-037 modular fragment pipeline

- [x] 1.1 Create `src/manifest.d/` directory (add a `.gitkeep` so it is tracked).
- [x] 1.2 In `src/main.js`, add a `require.context('./manifest.d', true, /\.json$/)` call (or equivalent webpack import pattern) that collects all `.json` files in `manifest.d/` into a `fragments` array.
- [x] 1.3 Ensure an empty `manifest.d/` yields an empty `fragments` array without error.

## 2. Wire buildManifest

- [x] 2.1 Import `buildManifest` from `@conduction/nextcloud-vue` in `src/main.js`.
- [x] 2.2 Create `src/menu-layout.json` with an empty `relocations: []`, `removals: []`, and `settingsSection: []` skeleton (populated in phase 3).
- [x] 2.3 Replace the direct `bundledManifest` variable used as the manifest prop with `buildManifest(bundledManifest, fragments, menuLayout)` — where `menuLayout` is the parsed `src/menu-layout.json`.
- [x] 2.4 Confirm the `manifest` prop passed to the Vue root and `routesFromManifest` is the `buildManifest` return value, not the raw `bundledManifest`.

## 3. Lift settings-foldout leaves into menu-layout.json#settingsSection

- [x] 3.1 Populate `menu-layout.json#settingsSection` with the four leaf ids: `DataExchange`, `XapiStatementsMenu`, `AssistantMenu`, `FeaturesRoadmapMenu`.
- [x] 3.2 Verify the resulting foldout is equivalent to the prior `section: "settings"` entries; individual `section` flags on those entries MAY be removed once `settingsSection` is the authoritative source.
- [x] 3.3 Confirm `AdminHealthMenu` (Insight group, `visibleIf` admin gate) is NOT inadvertently added to `settingsSection` — it belongs in the Insight group, not the foldout.

## 4. Verify the hard invariant — no route or reachable entry dropped

- [x] 4.1 Assert `routesFromManifest(effectiveManifest)` contains every route that `routesFromManifest(bundledManifest)` contained before the refactor.
- [x] 4.2 Assert every leaf previously reachable (primary nav, footer, foldout) is reachable in the effective manifest.
- [x] 4.3 Manually verify (or automate via Playwright) that `/courses`, `/enrolments`, `/attendance/records`, `/grades/entries`, `/learning-plans`, `/assessments`, `/credentials`, `/learner-profiles`, `/data-exchange/jobs`, `/xapi-statements`, `/structure/rollover`, and `/` all load their page components.

## 5. Quality gates

- [x] 5.1 `openspec validate adopt-shared-menu-pipeline --type change --strict` passes with no errors.
- [x] 5.2 `composer check:strict` passes (no new PHP violations introduced).
- [x] 5.3 Webpack build succeeds; bundle size delta is documented in the PR description.
