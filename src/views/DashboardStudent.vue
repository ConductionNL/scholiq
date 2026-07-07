<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- My learning dashboard route wrapper — renders the shared ScholiqDashboards
     in its student view. Everyone with a Scholiq role can see it (the resolved
     view set always includes 'student'), so a learner, teacher or admin all
     reach their own mandatory-training dashboard. -->
<template>
	<ScholiqDashboards role="student" />
</template>

<script>
import ScholiqDashboards from './ScholiqDashboards.vue'

export default {
	name: 'DashboardStudent',
	components: { ScholiqDashboards },
}
</script>
