<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: total schema count in the scholiq register. -->
<template>
	<KpiCard
		schema="AiFeature"
		:label="t('scholiq', 'Schemas')"
		link="/ai-features" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiSchemasWidget',
	components: { KpiCard },
}
</script>
