<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Teaching dashboard route wrapper — renders the shared ScholiqDashboards
     in its teacher view. Nav visibility is gated on the `scholiq-teacher`
     group (runtime `user.canTeachDashboard`); admins see it too.
     @visual exclude Thin pass-through wrapper (renders ScholiqDashboards role="teacher"); the visual surface is ScholiqDashboards, which carries the baseline. -->
<template>
	<ScholiqDashboards role="teacher" />
</template>

<script>
import ScholiqDashboards from './ScholiqDashboards.vue'

export default {
	name: 'DashboardTeacher',
	components: { ScholiqDashboards },
}
</script>
