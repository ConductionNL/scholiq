<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqNotificationSettings — the per-user "User settings" dialog content.

 Lets a user control which Scholiq notifications they receive. Reads and writes
 OpenRegister's override-only notification-preferences endpoint
 (GET/PUT /apps/openregister/api/notification-preferences); OpenRegister's
 dispatcher honors each override (preference-off gate). No scholiq-local
 preference store — ADR-022 (apps consume OR abstractions).

 Also surfaces a quiet-hours / delivery-window control, consuming whatever
 preference shape the (not-yet-shipped, cross-repo) OpenRegister
 `notification-delivery-windows` change exposes on this same endpoint family.
 Until that engine change ships, the GET response simply omits `quietHours`
 and the control degrades gracefully to its default (off) state; a PUT is
 still attempted so the value is captured the moment the endpoint starts
 honouring it — scholiq performs no local quiet-hours suppression itself.

 @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-per-user-notification-preferences-in-the-user-settings-dialog
 @spec openspec/changes/grade-visibility-scheduling/specs/scholiq-notifications/spec.md#requirement-notification-delivery-must-honor-the-per-user-override-preference
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

		<NcSettingsSection
			v-if="!loading"
			:name="t('scholiq', 'Quiet hours')"
			:description="t('scholiq', 'Defer Scholiq notifications during a daily quiet-hours window. Reminders with a deadline still arrive early enough to land before the deadline passes.')">
			<NcCheckboxRadioSwitch
				type="switch"
				:checked="quietHours.enabled"
				:disabled="quietHoursSaving"
				@update:checked="value => saveQuietHours({ ...quietHours, enabled: value })">
				{{ t('scholiq', 'Enable quiet hours') }}
			</NcCheckboxRadioSwitch>

			<div v-if="quietHours.enabled" class="scholiq-notif-settings__quiet-hours-times">
				<div class="scholiq-notif-settings__quiet-hours-field">
					<label for="scholiq-quiet-hours-start">{{ t('scholiq', 'Start') }}</label>
					<input
						id="scholiq-quiet-hours-start"
						type="time"
						:value="quietHours.start"
						:disabled="quietHoursSaving"
						@change="event => saveQuietHours({ ...quietHours, start: event.target.value })">
				</div>
				<div class="scholiq-notif-settings__quiet-hours-field">
					<label for="scholiq-quiet-hours-end">{{ t('scholiq', 'End') }}</label>
					<input
						id="scholiq-quiet-hours-end"
						type="time"
						:value="quietHours.end"
						:disabled="quietHoursSaving"
						@change="event => saveQuietHours({ ...quietHours, end: event.target.value })">
				</div>
			</div>

			<p v-if="quietHoursHint" class="scholiq-notif-settings__hint">
				{{ quietHoursHint }}
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
			// Quiet-hours / delivery-window preference. Populated from `data.quietHours`
			// on the notification-preferences GET response when the cross-repo OR
			// `notification-delivery-windows` dispatcher preference surface exposes it;
			// defaults to "off" until then (DEFERRED_QUESTIONS #3, grade-visibility-scheduling).
			quietHours: { enabled: false, start: '22:00', end: '07:00' },
			quietHoursSaving: false,
			quietHoursHint: '',
		}
	},

	created() {
		this.fetchPreferences()
	},

	methods: {
		/**
		 * Load the user's effective notification preferences from OpenRegister.
		 *
		 * Also reads an optional `quietHours` key off the same response — the
		 * shape the (not-yet-shipped) OR `notification-delivery-windows` change
		 * is expected to expose. Absent today, so the control simply keeps its
		 * default (off) state until that engine change ships.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/grade-visibility-scheduling/specs/scholiq-notifications/spec.md#requirement-notification-delivery-must-honor-the-per-user-override-preference
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

				if (data.quietHours && typeof data.quietHours === 'object') {
					this.quietHours = {
						enabled: data.quietHours.enabled === true,
						start: data.quietHours.start || '22:00',
						end: data.quietHours.end || '07:00',
					}
				}
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

		/**
		 * Persist the quiet-hours / delivery-window preference through OpenRegister's
		 * preference API — no scholiq-local persistence (ADR-022).
		 *
		 * The cross-repo OR `notification-delivery-windows` dispatcher preference
		 * surface has not shipped yet, so today's endpoint only accepts a
		 * `(schema, notification)` override body and rejects a quiet-hours-only
		 * payload. This degrades gracefully: the chosen value is kept in the UI
		 * and a neutral hint explains it is not yet enforced, rather than
		 * surfacing a scary error for a feature that is genuinely not live yet.
		 *
		 * @param {object} next The next quiet-hours value `{enabled, start, end}`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/grade-visibility-scheduling/specs/scholiq-notifications/spec.md#scenario-settings-panel-surfaces-the-quiet-hours-control
		 */
		async saveQuietHours(next) {
			this.quietHours = next
			this.quietHoursSaving = true
			this.quietHoursHint = ''
			try {
				const url = generateUrl('/apps/openregister/api/notification-preferences')
				await axios.put(url, { quietHours: next })
			} catch (error) {
				this.quietHoursHint = this.t(
					'scholiq',
					'Quiet hours are not yet enforced by your Nextcloud instance. Your preference is kept here and will take effect once support is enabled.',
				)
				// eslint-disable-next-line no-console
				console.warn('[ScholiqNotificationSettings] saveQuietHours: delivery-window endpoint not yet available:', error)
			} finally {
				this.quietHoursSaving = false
			}
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

.scholiq-notif-settings__quiet-hours-times {
	display: flex;
	gap: 16px;
	margin-top: 8px;
}

.scholiq-notif-settings__quiet-hours-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.scholiq-notif-settings__hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
