<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 KPI tile: total cohort count.

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
-->
<template>
	<KpiCard
		schema="Cohort"
		:label="t('scholiq', 'Cohorts')"
		link="/cohorts" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiCohortsWidget',
	components: { KpiCard },
}
</script>
