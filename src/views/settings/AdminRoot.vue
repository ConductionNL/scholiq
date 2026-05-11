<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="scholiq-admin">
		<CnVersionInfoCard
			:app-name="'Scholiq'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('scholiq', 'Version Information')"
			:description="t('scholiq', 'Information about the current Scholiq installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('scholiq', 'Support') }}</h4>
					<p>{{ t('scholiq', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings v-if="storesReady" />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		Settings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: document.getElementById('scholiq-settings')?.dataset?.version || 'Unknown',
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.scholiq-admin {
	max-width: 900px;
}
</style>
