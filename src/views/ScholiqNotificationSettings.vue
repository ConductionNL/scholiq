<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqNotificationSettings — the per-user "User settings" dialog content.

 Lets a user control which Scholiq notifications they receive. Reads and writes
 OpenRegister's override-only notification-preferences endpoint
 (GET/PUT /apps/openregister/api/notification-preferences); OpenRegister's
 dispatcher honors each override (preference-off gate). No scholiq-local
 preference store — ADR-022 (apps consume OR abstractions).

 @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-per-user-notification-preferences-in-the-user-settings-dialog
-->
<template>
	<div class="scholiq-notif-settings">
		<NcSettingsSection
			:name="t('scholiq', 'Notifications')"
			:description="t('scholiq', 'Choose which Scholiq notifications you want to receive. These preferences apply to your account only.')">
			<div v-if="loading" class="scholiq-notif-settings__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcEmptyContent
				v-else-if="items.length === 0"
				:name="t('scholiq', 'No notifications available')"
				:description="t('scholiq', 'Scholiq has no notification types to configure for your account yet.')">
				<template #icon>
					<NcLoadingIcon v-if="false" />
				</template>
			</NcEmptyContent>

			<ul v-else class="scholiq-notif-settings__list">
				<li
					v-for="item in items"
					:key="item.schema + '/' + item.notification"
					class="scholiq-notif-settings__item">
					<NcCheckboxRadioSwitch
						type="switch"
						:checked="item.enabled"
						:disabled="item.saving"
						@update:checked="value => toggle(item, value)">
						{{ labelFor(item) }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>

			<p v-if="errorMessage" class="scholiq-notif-settings__error">
				{{ errorMessage }}
			</p>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getLanguage } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import bundledManifest from '../manifest.json'

/**
 * Normalise a schema identifier for comparison — lowercase and strip hyphens —
 * so it matches regardless of slug style (`attendance-flag`, `AttendanceFlag`,
 * `AiFeature`, `course` all collapse to a comparable key).
 *
 * @param {string} name Schema name or slug.
 * @return {string} Normalised comparison key.
 */
function normSchema(name) {
	return String(name).toLowerCase().replace(/-/g, '')
}

// Scholiq's own schemas, derived from the bundled manifest's pages plus the
// notification-only schemas that have no index page. Used to scope the
// notification-preferences list (which OpenRegister returns across every register
// the user can access) down to Scholiq's own notifications.
const SCHOLIQ_SCHEMAS = new Set([
	...bundledManifest.pages
		.filter(page => page?.config?.register === 'scholiq' && page?.config?.schema)
		.map(page => normSchema(page.config.schema)),
	// Notification-only schemas (no index page).
	normSchema('GradeNotification'),
])

export default {
	name: 'ScholiqNotificationSettings',

	components: {
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
	},

	data() {
		return {
			items: [],
			loading: true,
			errorMessage: '',
		}
	},

	created() {
		this.fetchPreferences()
	},

	methods: {
		/**
		 * Load the user's effective notification preferences from OpenRegister.
		 *
		 * @return {Promise<void>}
		 */
		async fetchPreferences() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/openregister/api/notification-preferences')
				const response = await axios.get(url)
				const data = response.data ?? {}
				const results = data.results ?? (Array.isArray(data) ? data : [])
				// Scope to Scholiq's own notifications — the endpoint returns
				// every notification across all registers the user can access.
				const scholiqResults = results.filter(row => SCHOLIQ_SCHEMAS.has(normSchema(row.schema)))
				this.items = scholiqResults.map(row => ({
					schema: row.schema,
					notification: row.notification,
					enabled: row.enabled !== false,
					subject: row.subject ?? null,
					saving: false,
				}))
			} catch (error) {
				this.errorMessage = this.t('scholiq', 'Could not load notification preferences.')
				// eslint-disable-next-line no-console
				console.error('[ScholiqNotificationSettings] fetchPreferences failed:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist a single notification toggle as a per-user override.
		 *
		 * @param {object} item The preference row being changed.
		 * @param {boolean} value The new enabled state.
		 * @return {Promise<void>}
		 */
		async toggle(item, value) {
			const previous = item.enabled
			item.enabled = value
			item.saving = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/openregister/api/notification-preferences')
				await axios.put(url, {
					schema: item.schema,
					notification: item.notification,
					enabled: value,
				})
			} catch (error) {
				item.enabled = previous
				this.errorMessage = this.t('scholiq', 'Could not save notification preference.')
				// eslint-disable-next-line no-console
				console.error('[ScholiqNotificationSettings] toggle failed:', error)
			} finally {
				item.saving = false
			}
		},

		/**
		 * Human label for a preference row — the localized subject when
		 * available, otherwise the schema + notification key.
		 *
		 * @param {object} item The preference row.
		 * @return {string}
		 */
		labelFor(item) {
			if (item.subject) {
				const lang = (getLanguage() || 'en').slice(0, 2)
				return item.subject[lang] || item.subject.en || item.subject.nl || item.notification
			}
			return item.schema + ' · ' + item.notification
		},
	},
}
</script>

<style scoped>
.scholiq-notif-settings__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.scholiq-notif-settings__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.scholiq-notif-settings__item {
	padding: 6px 0;
}

.scholiq-notif-settings__error {
	margin-top: 8px;
	color: var(--color-error);
}
</style>
