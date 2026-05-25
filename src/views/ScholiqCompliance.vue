<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqCompliance — compliance dashboard page.
 Renders KPI tiles for regulations and signed attestations, plus a
 "View in MyDash" header action.

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
-->
<template>
	<CnDashboardPage
		:title="t('scholiq', 'Compliance')"
		:widgets="widgets"
		:layout="layout">
		<template #header-actions>
			<NcButton type="secondary" @click="viewInMydash">
				{{ t('scholiq', 'View in MyDash') }}
			</NcButton>
		</template>
		<template #widget-kpi-regulations>
			<KpiRegulationsWidget />
		</template>
		<template #widget-kpi-attestations>
			<KpiAttestationsWidget />
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import KpiRegulationsWidget from './widgets/KpiRegulationsWidget.vue'
import KpiAttestationsWidget from './widgets/KpiAttestationsWidget.vue'

export default {
	name: 'ScholiqCompliance',

	components: {
		CnDashboardPage,
		NcButton,
		KpiRegulationsWidget,
		KpiAttestationsWidget,
	},

	data() {
		return {
			widgets: [
				{ id: 'kpi-regulations', title: 'Regulations', type: 'custom' },
				{ id: 'kpi-attestations', title: 'Signed attestations', type: 'custom' },
			],
			layout: [
				{ id: 1, widgetId: 'kpi-regulations', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'kpi-attestations', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
			],
		}
	},

	methods: {
		/**
		 * Open MyDash in a new tab for heavier compliance analytics.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
		 */
		viewInMydash() {
			// Open MyDash in a new tab — URL is installation-specific.
			window.open('/apps/mydash', '_blank')
		},
	},
}
</script>
