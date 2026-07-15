# Adopt live updates in app-local UI (nc-vue beta.212)

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 installs `liveUpdatesPlugin` by
default on every `createObjectStore` store (lazy — inert until the first
`subscribe()` call) and fixes the first-subscription transport. OpenRegister
already pushes `or-collection-*` / `or-object-*` events for every
OpenRegister-backed object, so adopting live updates is a frontend-only change:
views subscribe while mounted and re-render from the store's refetched cache.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- Wire a live object subscription (`or-object-{uuid}`) into
  `RegulationDetailPage.vue` — the one app-local view backed by the shared
  library's `createObjectStore` store: subscribe after slug resolution, bridge
  the plugin-refetched object cache into the provided `cnDetailObjectContext`,
  re-scope on slug change, release on destroy with epoch-guarded in-flight
  handling (openregister reference pattern).
- Add the `realtime-updates` adoption spec.

## Out of Scope (documented skips)

- The app-local `useObjectStore` in `src/store/modules/object.js` is a
  hand-rolled `defineStore('object', …)`, NOT `createObjectStore` — it has no
  `subscribe()` capability, so nothing consuming it can be wired without first
  migrating the store to the shared factory (a separate, larger change).
- Manifest-driven index/detail pages are rendered by the shared library
  (`CnPageRenderer` → `CnIndexPage` / `CnDetailPage`). `CnIndexPage` has no
  subscription support and `CnPageRenderer` does not pass an `objectStore`
  instance to `CnDetailPage` (whose auto-subscribe requires it), so live
  updates for manifest pages must land in `nextcloud-vue`, not per-app.
- The remaining custom views (dashboards, timetable, portfolio, proctoring, …)
  fetch through bespoke `src/api/` services or app endpoints, not through a
  `createObjectStore` store — no store cache to subscribe against.

## Impact

- Affected specs: `realtime-updates` (new)
- Affected code: `package.json`, `src/views/RegulationDetailPage.vue`
