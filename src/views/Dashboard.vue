<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="scholiq-dashboard">
		<header class="scholiq-dashboard__header">
			<h2>{{ t('scholiq', 'Dashboard') }}</h2>
			<p class="scholiq-dashboard__lead">
				{{ t('scholiq', 'Starter overview with sample KPIs and activity placeholders. Replace this view with your own data.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('scholiq', 'Open items')"
				:count="12"
				:count-label="t('scholiq', 'sample')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('scholiq', 'Due this week')"
				:count="5"
				:count-label="t('scholiq', 'sample')"
				:icon="CalendarClock"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('scholiq', 'Completed')"
				:count="48"
				:count-label="t('scholiq', 'sample')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('scholiq', 'Team members')"
				:count="7"
				:count-label="t('scholiq', 'sample')"
				:icon="AccountGroupOutline"
				variant="default"
				horizontal />
		</CnKpiGrid>

		<div class="scholiq-dashboard__columns">
			<CnConfigurationCard :title="t('scholiq', 'Recent activity')">
				<ul class="scholiq-dashboard__placeholder-list">
					<li>{{ t('scholiq', 'Placeholder: user opened a record') }}</li>
					<li>{{ t('scholiq', 'Placeholder: status changed to Review') }}</li>
					<li>{{ t('scholiq', 'Placeholder: comment added') }}</li>
				</ul>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('scholiq', 'Quick actions')">
				<p class="scholiq-dashboard__hint">
					{{ t('scholiq', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			FolderOutline,
			CalendarClock,
			CheckCircleOutline,
			AccountGroupOutline,
		}
	},
}
</script>

<style scoped>
.scholiq-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.scholiq-dashboard__header {
	margin-bottom: 20px;
}

.scholiq-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.scholiq-dashboard__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.scholiq-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}

@media (max-width: 900px) {
	.scholiq-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.scholiq-dashboard__placeholder-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.scholiq-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
