<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RegulationDetailPage — custom page component for the RegulationDetail
 manifest page (type: custom).

 The route is business-slug-based (`/compliance/regulations/:slug`, e.g.
 `/compliance/regulations/AVG`) so compliance officers can share readable
 links. OpenRegister's generic single-object lookup (used by the library's
 `type:"detail"` dispatch) only matches an object's numeric id, UUID,
 `@self.slug` or `@self.uri` — NOT an arbitrary schema property like
 Regulation's own `slug` field ("AVG"). Routing straight through the
 library's generic detail-page resolver therefore left `objectData` null
 for a business-slug link (crash: `TypeError: Cannot read properties of
 null (reading 'id')` inside CnRelatedObjectsWidget's `resolvedId` /
 `useTabs`, which read `this.objectData.id` unguarded) and, separately,
 the resolver never even looked at the `:slug` route param at all (it only
 reads `:id` / `config.idParam`), so `@self.slug`-style links
 ("reg-avg") were never picked up either.

 This component resolves the object itself before rendering anything:
 1. Try OpenRegister's native id/uuid/`@self.slug`/`@self.uri` match
    (covers "reg-avg"-style links).
 2. Fall back to a collection lookup filtered by the schema's own `slug`
    property (covers "AVG"-style business-key links).
 3. Loading / not-found states are rendered explicitly — CnDetailPage
    (and its widget grid) is only ever mounted once a real object has
    been resolved, so the null-deref this page used to crash with can no
    longer happen.

 Once resolved, it mounts the SAME `CnDetailPage` component the generic
 `type:"detail"` dispatch would have used, forwarding the widgets/layout/
 sidebar/lifecycleActions declared on this page's manifest `config` (via
 props, exactly as `CnPageRenderer` would) and publishing the
 `cnDetailObjectContext` the widget grid needs for `@object.<field>`
 filter-token resolution (e.g. the KPI/related-collection widgets' `"@object.slug"`
 filters).

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
-->

<template>
	<div class="regulation-detail">
		<NcLoadingIcon v-if="loading" :size="44" class="regulation-detail__loading" />

		<NcEmptyContent v-else-if="notFound"
			:name="t('scholiq', 'Regulation not found')"
			:description="t('scholiq', 'No regulation matches this link. It may have been removed or the link is out of date.')">
			<template #icon>
				<span class="icon-error" />
			</template>
		</NcEmptyContent>

		<CnDetailPage v-else
			:title="t('scholiq', 'Regulation')"
			:widgets="widgets"
			:layout="layout"
			:sidebar="sidebar"
			:lifecycle-actions="lifecycleActions"
			:object-type="schema"
			:object-id="objectId"
			:register="register"
			:schema="schema" />
	</div>
</template>

<script>
import { CnDetailPage, useObjectStore } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

const REGISTER = 'scholiq'
const SCHEMA = 'Regulation'
const OBJECT_TYPE = `${REGISTER}-${SCHEMA}`

export default {
	name: 'RegulationDetailPage',

	components: {
		CnDetailPage,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** `config.widgets` forwarded by CnPageRenderer (grid widget defs). */
		widgets: {
			type: Array,
			default: () => [],
		},
		/** `config.layout` forwarded by CnPageRenderer (12-col grid layout). */
		layout: {
			type: Array,
			default: () => [],
		},
		/** `config.sidebar` forwarded by CnPageRenderer (tabs incl. audit trail). */
		sidebar: {
			type: [Boolean, Object],
			default: () => ({ enabled: false }),
		},
		/** `config.lifecycleActions` forwarded by CnPageRenderer. */
		lifecycleActions: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			notFound: false,
			/** Real OpenRegister object id once resolved (never the route slug). */
			objectId: '',
			register: REGISTER,
			schema: SCHEMA,
			/**
			 * Published to descendants as `cnDetailObjectContext` (same shape
			 * CnPageRenderer publishes for `type:"detail"` pages), so the
			 * widget grid's `@object.<field>` filter tokens resolve.
			 */
			detailObjectContext: { value: null },
			// Live-updates handle for the or-object-{uuid} subscription of
			// the currently resolved regulation (nc-vue beta.212,
			// liveUpdatesPlugin default-on). Managed by
			// syncLiveSubscription(); liveKey is `${type}::${uuid}` so a
			// re-resolve of the same object is a no-op. livePendingKey marks
			// an in-flight subscribe so a concurrent same-key call doesn't
			// double-subscribe; liveEpoch invalidates in-flight resolutions
			// after a release (slug change / destroy).
			liveHandle: null,
			liveKey: '',
			livePendingKey: '',
			liveEpoch: 0,
			liveUnwatch: null,
		}
	},

	provide() {
		return {
			cnDetailObjectContext: this.detailObjectContext,
		}
	},

	// CnPageRenderer forwards every `config.*` key (register, schema, title, …)
	// as an individual prop — this component only declares the ones it
	// actually consumes (widgets/layout/sidebar/lifecycleActions) and takes
	// register/schema as fixed constants, so the rest must not fall through
	// onto the root <div> as raw DOM attributes.
	inheritAttrs: false,

	watch: {
		'$route.params.slug': {
			immediate: true,
			handler() {
				this.resolve()
			},
		},
	},

	/**
	 * Lifecycle hook: release the live object subscription on unmount.
	 *
	 * @return {void}
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	beforeDestroy() {
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for the resolved regulation:
		 * or-object-{uuid} via notify_push with visibility-gated polling
		 * fallback. Events are refetch hints only — the liveUpdatesPlugin
		 * re-runs fetchObject(type, uuid), which lands in the library
		 * store's objects[type][uuid] cache; the watcher installed here
		 * bridges that fresh data into `detailObjectContext.value.objectData`
		 * so the provided context (and the widget grid reading it)
		 * re-renders. Idempotent per (type, uuid); releases the previous
		 * subscription when another regulation is resolved.
		 *
		 * @param {object} store The library object store instance.
		 * @param {object} object The resolved regulation object.
		 * @return {Promise<void>}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		async syncLiveSubscription(store, object) {
			if (typeof store.subscribe !== 'function') {
				return
			}
			// The push event key is or-object-{uuid} — prefer the uuid over
			// a numeric id when both are present.
			const uuid = (object['@self'] && object['@self'].uuid) ?? object.uuid ?? this.objectId
			if (!uuid) {
				this.releaseLiveSubscription()
				return
			}
			const key = `${OBJECT_TYPE}::${uuid}`
			if (this.liveHandle && this.liveKey === key) {
				return
			}
			if (this.livePendingKey === key) {
				// A subscribe for this exact object is already in flight —
				// re-subscribing here would leak the first handle + watcher.
				return
			}
			this.releaseLiveSubscription()
			try {
				const epoch = this.liveEpoch
				this.livePendingKey = key
				this.liveKey = key
				const handle = await store.subscribe(OBJECT_TYPE, uuid)
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (another slug resolved, or the
					// component was destroyed) — drop the stale subscription.
					store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
				// Bridge: event → plugin refetch → objects[type][uuid] cache →
				// detailObjectContext (which descendants render from).
				this.liveUnwatch = this.$watch(
					() => (typeof store.getObject === 'function' ? store.getObject(OBJECT_TYPE, uuid) : null),
					(fresh) => {
						if (fresh && this.liveKey === key && this.detailObjectContext.value) {
							this.detailObjectContext.value = {
								...this.detailObjectContext.value,
								objectData: fresh,
							}
						}
					},
				)
			} catch (e) {
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				this.liveHandle = null
				this.liveKey = ''
				// eslint-disable-next-line no-console
				console.warn('[RegulationDetailPage] live subscription failed:', e?.message ?? e)
			}
		},

		/**
		 * Release the current live object subscription and its cache
		 * watcher, and invalidate any in-flight subscribe (its resolution
		 * unsubscribes itself via the epoch check).
		 *
		 * @return {void}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingKey = ''
			if (this.liveUnwatch) {
				this.liveUnwatch()
				this.liveUnwatch = null
			}
			if (this.liveHandle) {
				const store = useObjectStore()
				if (typeof store.unsubscribe === 'function') {
					store.unsubscribe(this.liveHandle)
				}
			}
			this.liveHandle = null
			this.liveKey = ''
		},

		/**
		 * Resolve the Regulation behind the current `:slug` route param and
		 * publish it as the detail-object context, or show the not-found
		 * state when nothing matches.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
		 */
		async resolve() {
			const param = this.$route.params.slug

			this.loading = true
			this.notFound = false
			this.objectId = ''
			this.detailObjectContext.value = null

			if (!param) {
				this.loading = false
				this.notFound = true
				this.releaseLiveSubscription()
				return
			}

			const store = useObjectStore()
			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(OBJECT_TYPE, SCHEMA, REGISTER)
			}
			// Schema fetch runs alongside the object lookup — CnObjectDataWidget
			// (the "data" grid widget) needs it for field labels/order, same as
			// CnPageRenderer's loadDetailObject does for a `type:"detail"` page.
			const schemaPromise = typeof store.fetchSchema === 'function'
				? store.fetchSchema(OBJECT_TYPE).catch(() => null)
				: Promise.resolve(null)

			let object = null

			// 1) OpenRegister's native id/uuid/@self.slug/@self.uri matcher —
			//    covers links built from the OR-generated slug ("reg-avg").
			if (typeof store.fetchObject === 'function') {
				object = await store.fetchObject(OBJECT_TYPE, param).catch(() => null)
			}

			// 2) Fall back to the schema's own `slug` business-key property —
			//    OpenRegister's generic matcher does not know about arbitrary
			//    schema properties, so a business-key link ("AVG") needs an
			//    explicit filtered lookup.
			if (!object && typeof store.fetchCollection === 'function') {
				const results = await store.fetchCollection(OBJECT_TYPE, { slug: param, _limit: 1 }).catch(() => [])
				object = Array.isArray(results) && results.length > 0 ? results[0] : null
			}

			if (!object) {
				this.loading = false
				this.notFound = true
				this.releaseLiveSubscription()
				return
			}

			const schema = await schemaPromise

			const realId = object.id ?? (object['@self'] && object['@self'].id)
			this.objectId = realId !== undefined && realId !== null ? String(realId) : ''

			this.detailObjectContext.value = {
				objectData: object,
				schema: schema || (typeof store.getSchema === 'function' && store.getSchema(OBJECT_TYPE)) || null,
				objectType: OBJECT_TYPE,
				objectId: this.objectId,
				register: REGISTER,
				store,
			}

			this.loading = false

			// Live updates for the resolved object (refetch hints only).
			this.syncLiveSubscription(store, object)
		},
	},
}
</script>

<style scoped lang="scss">
.regulation-detail {
	display: flex;
	flex-direction: column;
	min-height: 200px;

	&__loading {
		margin: var(--default-grid-baseline, 8px) auto;
	}
}
</style>
