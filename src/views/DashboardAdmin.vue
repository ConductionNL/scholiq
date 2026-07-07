<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Administration dashboard route wrapper — renders the shared
     ScholiqDashboards in its admin view. Nav visibility is gated on the
     `scholiq-admin` group (runtime `user.canAdminDashboard`); admins see all
     three dashboards. Replaces the old single role-switcher dashboard. -->
<template>
	<ScholiqDashboards role="admin" />
</template>

<script>
import ScholiqDashboards from './ScholiqDashboards.vue'

export default {
	name: 'DashboardAdmin',
	components: { ScholiqDashboards },
}
</script>
