# realtime-updates Specification

## Purpose

Adopt the live-updates capability of `@conduction/nextcloud-vue` (>= 1.0.0-beta.212,
where `liveUpdatesPlugin` is installed by default on every `createObjectStore`
store) so app-local views backed by the shared object store refresh without a
manual reload. The canonical realtime-updates contract (event keys
`or-collection-{register-slug}-{schema-slug}` and `or-object-{uuid}`, notify_push
transport with visibility-gated polling fallback, events as refetch hints only)
is owned by OpenRegister (`openregister/openspec/specs/realtime-updates/spec.md`);
this spec covers only ScholiQ's frontend adoption.

## Requirements

### Requirement: Store-backed app-local detail views MUST subscribe to live object updates

App-local detail views resolving an OpenRegister object through the shared library object store MUST subscribe to `or-object-{uuid}` via `objectStore.subscribe(type, uuid)` while the object is displayed, and MUST release the subscription when another object is resolved or the view is destroyed. Events are refetch hints only — the view MUST NOT apply event payloads directly, but re-render from the store's refetched object cache.

#### Scenario: Regulation detail refreshes when the regulation changes elsewhere

@e2e exclude Push-transport timing is not deterministically observable in e2e; the subscribe/unsubscribe lifecycle is covered by the shared library's unit tests and the page itself by the existing compliance e2e flows.

- **GIVEN** a regulation detail page is open and subscribed to `or-object-{uuid}` for the resolved regulation
- **WHEN** that regulation object is updated by another session
- **THEN** the liveUpdatesPlugin refetches the object and the page's provided detail-object context re-renders from the fresh data without a manual reload

#### Scenario: Subscription is re-scoped on slug change and released on unmount

@e2e exclude Subscription-handle bookkeeping is internal state with no UI surface; covered by the shared library's unit tests.

- **GIVEN** the regulation detail page holds a live object subscription
- **WHEN** the route slug resolves to a different regulation, resolution fails (not found), or the component is destroyed
- **THEN** the previous subscription handle is released and any in-flight subscribe resolution is dropped via the epoch guard instead of leaking
