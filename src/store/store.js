// SPDX-License-Identifier: EUPL-1.2
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * Initialise the object + settings Pinia stores at app boot.
 *
 * @return {Promise<{settingsStore: object, objectStore: object}>}
 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
