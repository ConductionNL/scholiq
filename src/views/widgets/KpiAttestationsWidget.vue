<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: signed attestation count. -->
<template>
	<KpiCard
		schema="Attestation"
		:label="t('scholiq', 'Signed attestations')"
		link="/compliance/attestations"
		variant="success" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiAttestationsWidget',
	components: { KpiCard },
}
</script>
