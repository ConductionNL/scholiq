<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: active enrolment count (lifecycle=active). -->
<template>
	<KpiCard
		schema="Enrolment"
		:label="t('scholiq', 'Active enrolments')"
		:filter="{ lifecycle: 'active' }"
		link="/enrolments"
		variant="primary" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiActiveEnrolmentsWidget',
	components: { KpiCard },
}
</script>
