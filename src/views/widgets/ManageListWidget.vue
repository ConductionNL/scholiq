<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ManageListWidget — a parameterised list widget for manage/admin dashboards.
 Fetches the top-N objects from OR for a given schema and renders them through
 the universal <CnDataTable> (headerless, borderless) as a compact
 name + trailing-status list, with a "+ New" footer link to the index page and
 row-click navigation to the item's detail page.

 Props:
   schema     — OR schema slug (e.g. "Course", "Cohort", "Programme")
   schemaLabel — human-readable label for the "+ New" link
   columns    — array of field names to display per item (first becomes item title)
   indexRoute — router path for the index page ("+ New" link + row-click base)
   limit      — max items to show (default 5)
   filter     — optional extra filter params (e.g. { lifecycle: 'published' })
-->
<template>
	<CnDataTable
		:rows="rows"
		:columns="cnColumns"
		:loading="loading"
		hide-header
		borderless
		row-key="id"
		:empty-text="t('scholiq', 'No items found')"
		:row-click-route="rowClickRoute">
		<template #footer>
			<a class="cn-data-table__view-all" @click.prevent="navigate">
				+ {{ t('scholiq', 'New') }} {{ schemaLabel }}
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CnDataTable } from '@conduction/nextcloud-vue'

export default {
	name: 'ManageListWidget',

	components: {
		CnDataTable,
	},

	props: {
		/** OR schema slug */
		schema: {
			type: String,
			required: true,
		},
		/** Human-readable schema label for the "+ New" button */
		schemaLabel: {
			type: String,
			default: '',
		},
		/** Fields to display per item; first field is used as the item title */
		columns: {
			type: Array,
			default: () => ['name', 'lifecycle'],
		},
		/** Router path for the index/new page */
		indexRoute: {
			type: String,
			required: true,
		},
		/** Maximum number of items to show */
		limit: {
			type: Number,
			default: 5,
		},
		/** Additional OR filter params */
		filter: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			items: [],
			loading: true,
		}
	},

	computed: {
		/**
		 * Rows for CnDataTable — the fetched OR objects, each guaranteed a stable
		 * `id` (the row key) resolved from the object's id/uuid variants.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		rows() {
			return this.items.map((item) => ({
				...item,
				id: item.id || item._id || item.uuid || item['@self']?.id,
			}))
		},

		/**
		 * Column definitions for CnDataTable — headerless name + trailing status.
		 * The first column renders bold (the item title); the last renders muted
		 * and right-aligned; any columns in between render muted.
		 *
		 * @return {Array<{key: string, cellClass: string}>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		cnColumns() {
			const cols = this.columns.length ? this.columns : ['name']
			return cols.map((key, i) => ({
				key,
				// Name column stays regular weight (matches the reference design);
				// only the trailing status/value column is muted + right-aligned.
				cellClass: i === 0
					? ''
					: (i === cols.length - 1 ? 'cn-cell--muted cn-cell--end' : 'cn-cell--muted'),
			}))
		},
	},

	created() {
		this.fetchItems()
	},

	methods: {
		/**
		 * Fetch the top-N objects of this schema from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		async fetchItems() {
			this.loading = true
			try {
				const params = new URLSearchParams({ _limit: String(this.limit), ...this.filter })
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/' + this.schema + '?' + params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				this.items = data.results ?? (Array.isArray(data) ? data : [])
			} catch {
				this.items = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Map a clicked row to its detail route (`{indexRoute}/{id}`).
		 *
		 * @param {object} row The clicked OR object row.
		 * @return {object} A vue-router location.
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		rowClickRoute(row) {
			return { path: `${this.indexRoute}/${row.id}` }
		},

		/**
		 * Navigate to the configured index/new route.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		navigate() {
			this.$router.push(this.indexRoute).catch(() => {})
		},
	},
}
</script>
