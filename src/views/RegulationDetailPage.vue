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

	methods: {
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
