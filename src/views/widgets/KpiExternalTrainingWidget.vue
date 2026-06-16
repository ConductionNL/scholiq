<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: verified external-training record count. -->
<template>
	<KpiCard
		schema="external-training-record"
		:label="t('scholiq', 'External training')"
		link="/compliance/external-training"
		variant="success" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiExternalTrainingWidget',
	components: { KpiCard },
}
</script>
