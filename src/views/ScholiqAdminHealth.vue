<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqAdminHealth — app health dashboard for admins.
 Shows the existing health-stats widget plus a KPI schema count.
-->
<template>
	<CnDashboardPage
		:title="t('scholiq', 'App health')"
		:widgets="widgets"
		:layout="layout">
		<template #widget-health-stats>
			<div class="admin-health__placeholder">
				{{ t('scholiq', 'App health statistics') }}
			</div>
		</template>
		<template #widget-kpi-schemas>
			<KpiSchemasWidget />
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiSchemasWidget from './widgets/KpiSchemasWidget.vue'

export default {
	name: 'ScholiqAdminHealth',

	components: {
		CnDashboardPage,
		KpiSchemasWidget,
	},

	data() {
		return {
			widgets: [
				{ id: 'health-stats', title: 'App health', type: 'custom' },
				{ id: 'kpi-schemas', title: 'Schemas', type: 'custom' },
			],
			layout: [
				{ id: 1, widgetId: 'health-stats', gridX: 0, gridY: 0, gridWidth: 8, gridHeight: 4 },
				{ id: 2, widgetId: 'kpi-schemas', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
			],
		}
	},
}
</script>

<style scoped>
.admin-health__placeholder {
	padding: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
