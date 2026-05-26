// SPDX-License-Identifier: EUPL-1.2
import { defineStore } from 'pinia'
import { getRequestToken } from '@nextcloud/auth'

/**
 * Generic OpenRegister object store.
 * Configure it with baseUrl and schemaBaseUrl, then register object types.
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		baseUrl: '',
		schemaBaseUrl: '',
		objectTypes: {},
		objects: {},
		loading: {},
	}),

	actions: {
		/**
		 * Configure the OpenRegister object + schema base URLs.
		 *
		 * @param {object} opts Config { baseUrl, schemaBaseUrl }
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		configure({ baseUrl, schemaBaseUrl }) {
			this.baseUrl = baseUrl
			this.schemaBaseUrl = schemaBaseUrl
		},

		/**
		 * Register a named object type with its schema + register slugs.
		 *
		 * @param {string} type     Logical type name
		 * @param {string} schema   OR schema slug
		 * @param {string} register OR register slug
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		registerObjectType(type, schema, register) {
			this.objectTypes[type] = { schema, register }
			if (!this.objects[type]) {
				this.objects[type] = []
			}
		},

		/**
		 * Fetch objects of a registered type from OpenRegister.
		 *
		 * @param {string} type   Registered object type
		 * @param {object} params Optional query params
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		async fetchObjects(type, params = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return []
			}

			this.loading[type] = true
			const { schema, register } = this.objectTypes[type]

			try {
				const url = new URL(this.baseUrl, window.location.origin)
				url.searchParams.set('register', register)
				url.searchParams.set('schema', schema)
				Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

				const response = await fetch(url.toString(), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.objects[type] = data.results || data
					return this.objects[type]
				}
			} catch (error) {
				console.error(`Failed to fetch ${type} objects:`, error)
			} finally {
				this.loading[type] = false
			}
			return []
		},
	},
})
