<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: open attendance flags (lifecycle=open). -->
<template>
	<KpiCard
		schema="AttendanceFlag"
		:label="t('scholiq', 'Open attendance flags')"
		:filter="{ lifecycle: 'open' }"
		link="/attendance/flags"
		variant="warning" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiOpenFlagsWidget',
	components: { KpiCard },
}
</script>
