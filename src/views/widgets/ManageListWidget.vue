<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ManageListWidget — a parameterised list widget for manage/admin dashboards.
 Fetches the top-N objects from OR for a given schema, renders a compact list,
 and provides a "+ New" action link to the index page.

 Props:
   schema     — OR schema slug (e.g. "Course", "Cohort", "Programme")
   title      — widget title string
   columns    — array of field names to display per item (first becomes item title)
   indexRoute — router path for the "+ New" link (e.g. "/courses")
   limit      — max items to show (default 5)
   filter     — optional extra filter params (e.g. { lifecycle: 'published' })
-->
<template>
	<div class="manage-list-widget">
		<!-- Loading state -->
		<div v-if="loading" class="manage-list-widget__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Error / empty -->
		<div v-else-if="items.length === 0" class="manage-list-widget__empty">
			{{ t('scholiq', 'No items found') }}
		</div>

		<!-- Item list -->
		<ul v-else class="manage-list-widget__list">
			<li
				v-for="item in items"
				:key="item._id || item.uuid || item.id"
				class="manage-list-widget__item">
				<span class="manage-list-widget__item-name">{{ itemName(item) }}</span>
				<span
					v-for="col in extraColumns"
					:key="col"
					class="manage-list-widget__item-meta">
					{{ formatField(item, col) }}
				</span>
			</li>
		</ul>

		<!-- Footer: new-item action -->
		<div class="manage-list-widget__footer">
			<a class="manage-list-widget__new-link" @click.prevent="navigate">
				+ {{ t('scholiq', 'New') }} {{ schemaLabel }}
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ManageListWidget',

	components: {
		NcLoadingIcon,
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
		/** All columns after the first (displayed as secondary metadata) */
		extraColumns() {
			return this.columns.slice(1)
		},
	},

	created() {
		this.fetchItems()
	},

	methods: {
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
		 * Extract the display name from an item using the first column.
		 *
		 * @param {object} item OR object record.
		 * @return {string} Display name.
		 */
		itemName(item) {
			const firstCol = this.columns[0] ?? 'name'
			return item[firstCol] ?? item.name ?? item.title ?? item.id ?? '—'
		},

		/**
		 * Format a secondary field value for display.
		 *
		 * @param {object} item OR object record.
		 * @param {string} field Field name to format.
		 * @return {string} Formatted value or empty string.
		 */
		formatField(item, field) {
			const val = item[field]
			if (val === null || val === undefined) return ''
			return String(val)
		},

		navigate() {
			this.$router.push(this.indexRoute).catch(() => {})
		},
	},
}
</script>

<style scoped>
.manage-list-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 4px 0;
}

.manage-list-widget__loading {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
}

.manage-list-widget__empty {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.manage-list-widget__list {
	flex: 1;
	list-style: none;
	margin: 0;
	padding: 0;
	overflow-y: auto;
}

.manage-list-widget__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.manage-list-widget__item:last-child {
	border-bottom: none;
}

.manage-list-widget__item-name {
	flex: 1;
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.manage-list-widget__item-meta {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
}

.manage-list-widget__footer {
	padding: 6px 8px 0;
	border-top: 1px solid var(--color-border);
}

.manage-list-widget__new-link {
	font-size: 12px;
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: none;
}

.manage-list-widget__new-link:hover {
	text-decoration: underline;
}
</style>
