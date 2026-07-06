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

		<ScholiqSettings v-if="storesReady" />
		<DataExchangeSettingsSection />
		<ActionAuthMatrix />
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import ScholiqSettings from '../ScholiqSettings.vue'
import DataExchangeSettingsSection from './DataExchangeSettingsSection.vue'
import ActionAuthMatrix from '../../components/admin/ActionAuthMatrix.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		ScholiqSettings,
		DataExchangeSettingsSection,
		ActionAuthMatrix,
	},
	data() {
		return {
			storesReady: false,
			appVersion: loadState('scholiq', 'version', 'Unknown'),
		}
	},
	/**
	 * Initialise the Pinia stores at boot before rendering settings.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
	 */
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
