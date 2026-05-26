// SPDX-License-Identifier: EUPL-1.2
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
	},

	actions: {
		/**
		 * Fetch the Scholiq settings (incl. openregisters + isAdmin metadata) from the Settings API.
		 *
		 * @return {Promise<object|null>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
		 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/scholiq/api/settings'), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.hasOpenRegisters = !!data?.openregisters
					this.isAdmin = !!data?.isAdmin
					return data
				}
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Persist settings via the Settings API and update local state.
		 *
		 * @param {object} settings Settings payload to persist
		 * @return {Promise<object|null>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
		 */
		async saveSettings(settings) {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/scholiq/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify(settings),
				})
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					return data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
