<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnSettingsSection
		:name="t('scholiq', 'Configuration')"
		:description="t('scholiq', 'Configure the app settings')">
		<form @submit.prevent="save">
			<div class="form-group">
				<label for="register">{{ t('scholiq', 'Register') }}</label>
				<input
					id="register"
					v-model="form.register"
					type="text"
					:placeholder="t('scholiq', 'OpenRegister register ID')">
			</div>

			<div v-if="successMessage" class="success-message">
				{{ successMessage }}
			</div>

			<NcButton
				type="primary"
				native-type="submit"
				:disabled="saving">
				{{ saving ? t('scholiq', 'Saving...') : t('scholiq', 'Save') }}
			</NcButton>
		</form>
	</CnSettingsSection>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		CnSettingsSection,
	},
	data() {
		return {
			form: {
				register: '',
			},
			saving: false,
			successMessage: '',
		}
	},
	/**
	 * Seed the form from the current settings store state.
	 *
	 * @return {void}
	 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
	 */
	created() {
		const settingsStore = useSettingsStore()
		this.form.register = settingsStore.settings?.register || ''
	},
	methods: {
		/**
		 * Persist the form through the settings store and show a success message.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
		 */
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings(this.form)
			if (result) {
				this.successMessage = t('scholiq', 'Settings saved successfully')
			}
			this.saving = false
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 12px;
}
.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}
.success-message {
	color: var(--color-success);
	margin-bottom: 8px;
}
</style>
