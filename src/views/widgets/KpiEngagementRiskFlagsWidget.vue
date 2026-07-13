<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: open engagement-risk-flag count (lifecycle=open).
     learning-progress-and-analytics — mirrors KpiOpenFlagsWidget.vue. -->
<template>
	<KpiCard
		schema="engagement-risk-flag"
		:label="t('scholiq', 'Open engagement flags')"
		:filter="{ lifecycle: 'open' }"
		link="/progress/engagement-flags"
		variant="warning" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiEngagementRiskFlagsWidget',
	components: { KpiCard },
}
</script>
