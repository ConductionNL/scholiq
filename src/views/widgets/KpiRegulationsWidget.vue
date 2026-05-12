<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: total regulation count. -->
<template>
	<KpiCard
		schema="Regulation"
		:label="t('scholiq', 'Regulations')"
		link="/compliance/regulations" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiRegulationsWidget',
	components: { KpiCard },
}
</script>
