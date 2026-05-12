<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: total learner-profile count. -->
<template>
	<KpiCard
		schema="LearnerProfile"
		:label="t('scholiq', 'Learners')"
		link="/learner-profiles"
		variant="success" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiLearnersWidget',
	components: { KpiCard },
}
</script>
