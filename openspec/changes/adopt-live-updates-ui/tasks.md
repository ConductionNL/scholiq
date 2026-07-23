# Tasks — adopt-live-updates-ui

## 1. Dependency bump

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` and reinstall

## 2. Wire subscriptions

- [x] 2.1 Subscribe `RegulationDetailPage.vue` to `or-object-{uuid}` after slug resolution (refetch-hint semantics, cache → `cnDetailObjectContext` bridge watcher)
- [x] 2.2 Re-scope on slug change / not-found, release on destroy with epoch guard for in-flight subscribes
- [x] 2.3 Audit remaining `src/` consumers — the app-local object store is hand-rolled (`defineStore`, no `subscribe()`), manifest pages are library-rendered, other custom views use bespoke `src/api/` services. Skips documented in the proposal.

## 3. Verify

- [x] 3.1 `npm run lint` clean on touched files
- [x] 3.2 `npm run build` green (no JS unit-test lane; e2e requires a live instance)
