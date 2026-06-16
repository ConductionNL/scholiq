<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 KpiCard widget — a single KPI stat tile.
 Fetches the count of objects from OpenRegister (scholiq register) via the
 REST objects endpoint with _limit=1 to read the `total` field. Shows a
 CnStatsBlock (big number + label); NcLoadingIcon while fetching; "0" on failure.
-->
<template>
	<div class="kpi-card" :class="link ? 'kpi-card--linkable' : ''" @click="navigate">
		<CnStatsBlock
			:title="label"
			:count="count"
			:loading="loading"
			:icon="icon"
			:variant="variant"
			:clickable="!!link"
			horizontal />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CnStatsBlock } from '@conduction/nextcloud-vue'

export default {
	name: 'KpiCard',

	components: {
		CnStatsBlock,
	},

	props: {
		/** OR schema slug, e.g. "course" */
		schema: {
			type: String,
			required: true,
		},
		/** Human-readable label */
		label: {
			type: String,
			required: true,
		},
		/** MDI icon component (optional) */
		icon: {
			type: [Object, Function],
			default: null,
		},
		/** Router-link target path (optional) */
		link: {
			type: String,
			default: null,
		},
		/** Additional query filters, e.g. { lifecycle: 'active' } */
		filter: {
			type: Object,
			default: () => ({}),
		},
		/** CnStatsBlock colour variant */
		variant: {
			type: String,
			default: 'default',
		},
	},

	data() {
		return {
			count: 0,
			loading: true,
		}
	},

	created() {
		this.fetchCount()
	},

	methods: {
		/**
		 * Fetch the object count for this schema from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		async fetchCount() {
			this.loading = true
			try {
				const params = new URLSearchParams({ _limit: '1', ...this.filter })
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/' + this.schema + '?' + params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				// OR paginated envelope uses `total`, `count`, or object array length
				this.count = data.total ?? data.count ?? (Array.isArray(data.results) ? data.results.length : 0)
			} catch {
				this.count = 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to the configured router link when the card is clickable.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		navigate() {
			if (this.link) {
				this.$router.push(this.link).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.kpi-card {
	height: 100%;
	display: flex;
	align-items: center;
	padding: 4px;
}

.kpi-card--linkable {
	cursor: pointer;
}

/* Long KPI titles (e.g. "Active enrolments", "Open attendance flags") must
   wrap to a second line rather than ellipsis-clip in the narrow stat tiles —
   CnStatsBlock's title defaults to nowrap + ellipsis. */
.kpi-card :deep(.cn-stats-block__header h4) {
	white-space: normal;
	overflow: visible;
	line-height: 1.2;
}
</style>
