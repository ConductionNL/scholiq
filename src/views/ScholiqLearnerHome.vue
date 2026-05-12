<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqLearnerHome — "My learning" dashboard for learners.
 Shows the current user's mandatory training obligations.
-->
<template>
	<CnDashboardPage
		:title="t('scholiq', 'My learning')"
		:widgets="widgets"
		:layout="layout">
		<template #widget-my-mandatory-training>
			<MyMandatoryTrainingWidget />
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import MyMandatoryTrainingWidget from './widgets/MyMandatoryTrainingWidget.vue'

export default {
	name: 'ScholiqLearnerHome',

	components: {
		CnDashboardPage,
		MyMandatoryTrainingWidget,
	},

	data() {
		return {
			widgets: [
				{ id: 'my-mandatory-training', title: 'My mandatory training', type: 'custom' },
			],
			layout: [
				{ id: 1, widgetId: 'my-mandatory-training', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 5 },
			],
		}
	},
}
</script>
