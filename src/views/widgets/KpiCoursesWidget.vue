<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- KPI tile: total course count. -->
<template>
	<KpiCard
		schema="Course"
		:label="t('scholiq', 'Courses')"
		link="/courses"
		variant="primary" />
</template>

<script>
import KpiCard from './KpiCard.vue'

export default {
	name: 'KpiCoursesWidget',
	components: { KpiCard },
}
</script>
