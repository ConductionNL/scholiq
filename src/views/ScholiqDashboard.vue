<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqDashboard — main management dashboard.
 Renders CnDashboardPage with KPI tiles + manage-* list widgets.
 All widgets are custom (fetched via OR REST API).
-->
<template>
	<CnDashboardPage
		:title="t('scholiq', 'Dashboard')"
		:widgets="widgets"
		:layout="layout">
		<template #widget-kpi-courses>
			<KpiCoursesWidget />
		</template>
		<template #widget-kpi-cohorts>
			<KpiCohortsWidget />
		</template>
		<template #widget-kpi-learners>
			<KpiLearnersWidget />
		</template>
		<template #widget-kpi-active-enrolments>
			<KpiActiveEnrolmentsWidget />
		</template>
		<template #widget-kpi-open-flags>
			<KpiOpenFlagsWidget />
		</template>
		<template #widget-manage-courses>
			<ManageCoursesWidget />
		</template>
		<template #widget-manage-cohorts>
			<ManageCohortsWidget />
		</template>
		<template #widget-manage-programmes>
			<ManageProgrammesWidget />
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiCoursesWidget from './widgets/KpiCoursesWidget.vue'
import KpiCohortsWidget from './widgets/KpiCohortsWidget.vue'
import KpiLearnersWidget from './widgets/KpiLearnersWidget.vue'
import KpiActiveEnrolmentsWidget from './widgets/KpiActiveEnrolmentsWidget.vue'
import KpiOpenFlagsWidget from './widgets/KpiOpenFlagsWidget.vue'
import ManageCoursesWidget from './widgets/ManageCoursesWidget.vue'
import ManageCohortsWidget from './widgets/ManageCohortsWidget.vue'
import ManageProgrammesWidget from './widgets/ManageProgrammesWidget.vue'

export default {
	name: 'ScholiqDashboard',

	components: {
		CnDashboardPage,
		KpiCoursesWidget,
		KpiCohortsWidget,
		KpiLearnersWidget,
		KpiActiveEnrolmentsWidget,
		KpiOpenFlagsWidget,
		ManageCoursesWidget,
		ManageCohortsWidget,
		ManageProgrammesWidget,
	},

	data() {
		return {
			widgets: [
				{ id: 'kpi-courses', title: 'Courses', type: 'custom' },
				{ id: 'kpi-cohorts', title: 'Cohorts', type: 'custom' },
				{ id: 'kpi-learners', title: 'Learners', type: 'custom' },
				{ id: 'kpi-active-enrolments', title: 'Active enrolments', type: 'custom' },
				{ id: 'kpi-open-flags', title: 'Open attendance flags', type: 'custom' },
				{ id: 'manage-courses', title: 'Courses', type: 'custom' },
				{ id: 'manage-cohorts', title: 'Cohorts', type: 'custom' },
				{ id: 'manage-programmes', title: 'Programmes', type: 'custom' },
			],
			layout: [
				{ id: 1, widgetId: 'kpi-courses', gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'kpi-cohorts', gridX: 2, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
				{ id: 3, widgetId: 'kpi-learners', gridX: 4, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
				{ id: 4, widgetId: 'kpi-active-enrolments', gridX: 6, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
				{ id: 5, widgetId: 'kpi-open-flags', gridX: 8, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
				{ id: 6, widgetId: 'manage-courses', gridX: 0, gridY: 2, gridWidth: 4, gridHeight: 4 },
				{ id: 7, widgetId: 'manage-cohorts', gridX: 4, gridY: 2, gridWidth: 4, gridHeight: 4 },
				{ id: 8, widgetId: 'manage-programmes', gridX: 8, gridY: 2, gridWidth: 4, gridHeight: 4 },
			],
		}
	},
}
</script>
