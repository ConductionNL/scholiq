<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Scholiq settings page — the single custom Vue view in the v0.1 wedge.
 Declared in manifest.json as type: "custom", component: "ScholiqSettings".
 CnAppRoot resolves this name against the customComponents registry at runtime.

 Sections:
   1. OpenRegister default register picker (IAppConfig key: default_register)
   2. AI features read-only table (sourced from AiFeature schema objects via OR)
   3. Credential signing key widget (calls CredentialSigningController — ADR-031)
-->
<template>
	<div class="scholiq-settings">
		<h2 v-if="!inDialog" class="scholiq-settings__title">
			{{ t('scholiq', 'Scholiq Settings') }}
		</h2>

		<!-- Section 1: OpenRegister default register -->
		<NcSettingsSection
			:name="t('scholiq', 'OpenRegister')"
			:description="t('scholiq', 'Configure the default register used by Scholiq for data storage.')">
			<div class="scholiq-settings__field">
				<label for="scholiq-default-register">{{ t('scholiq', 'Default register') }}</label>
				<NcSelect
					id="scholiq-default-register"
					v-model="defaultRegister"
					:options="registerOptions"
					:loading="registersLoading"
					:placeholder="t('scholiq', 'Select a register…')"
					:aria-label-combobox="t('scholiq', 'Default register')"
					label="title"
					@input="saveDefaultRegister" />
			</div>
		</NcSettingsSection>

		<!-- Section 2: AI features (read-only, sourced from AiFeature schema objects) -->
		<NcSettingsSection
			:name="t('scholiq', 'AI Features')"
			:description="t('scholiq', 'EU AI Act high-risk features declared in this app. Toggle via lifecycle transitions — DPO acknowledgement required.')">
			<div v-if="aiFeaturesLoading" class="scholiq-settings__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<table v-else class="scholiq-settings__table">
				<thead>
					<tr>
						<th>{{ t('scholiq', 'Feature') }}</th>
						<th>{{ t('scholiq', 'Status') }}</th>
						<th>{{ t('scholiq', 'Description') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="aiFeatures.length === 0">
						<td colspan="3" class="scholiq-settings__empty">
							{{ t('scholiq', 'No AI features declared yet.') }}
						</td>
					</tr>
					<tr v-for="feature in aiFeatures"
						:key="feature.id">
						<td>{{ feature.name || feature.slug }}</td>
						<td>
							<span :class="['scholiq-settings__badge', 'scholiq-settings__badge--' + (feature.lifecycle || 'disabled')]">
								{{ feature.lifecycle || 'disabled' }}
							</span>
						</td>
						<td>{{ feature.description }}</td>
					</tr>
				</tbody>
			</table>
		</NcSettingsSection>

		<!-- Section 3: Credential signing key -->
		<NcSettingsSection
			:name="t('scholiq', 'Credential Signing')"
			:description="t('scholiq', 'RS256 key pair used to sign verifiable credentials. Stored encrypted in Nextcloud\'s keystore.')">
			<div class="scholiq-settings__field">
				<NcButton
					:disabled="signingKeyLoading"
					type="secondary"
					@click="rotateSigningKey">
					<template #icon>
						<NcLoadingIcon v-if="signingKeyLoading" :size="20" />
					</template>
					{{ t('scholiq', 'Rotate signing key') }}
				</NcButton>
				<p v-if="signingKeyMessage" class="scholiq-settings__message">
					{{ signingKeyMessage }}
				</p>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon, NcSelect, NcSettingsSection } from '@nextcloud/vue'

export default {
	name: 'ScholiqSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
	},

	props: {
		/**
		 * When true, suppresses the standalone page `<h2>` title because the
		 * NcAppSettingsDialog already displays the app name in its own header.
		 */
		inDialog: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			defaultRegister: null,
			registerOptions: [],
			registersLoading: false,
			aiFeatures: [],
			aiFeaturesLoading: false,
			signingKeyLoading: false,
			signingKeyMessage: '',
		}
	},

	/**
	 * Load the register options and AI features in parallel on mount.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-2
	 */
	async created() {
		await Promise.all([
			this.fetchRegisters(),
			this.fetchAiFeatures(),
		])
	},

	methods: {
		/**
		 * Load available registers from OpenRegister for the default-register picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-2
		 */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await fetch(generateUrl('/apps/openregister/api/registers'), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.registerOptions = data.results || data
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[ScholiqSettings] fetchRegisters failed:', error)
			} finally {
				this.registersLoading = false
			}
		},

		/**
		 * Load AiFeature schema objects from OpenRegister via the Scholiq settings API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-2
		 */
		async fetchAiFeatures() {
			this.aiFeaturesLoading = true
			try {
				const response = await fetch(generateUrl('/apps/scholiq/api/settings'), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.aiFeatures = data.aiFeatures || []
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[ScholiqSettings] fetchAiFeatures failed:', error)
			} finally {
				this.aiFeaturesLoading = false
			}
		},

		/**
		 * Persist the selected default register to IAppConfig.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-2
		 */
		async saveDefaultRegister() {
			if (!this.defaultRegister) return
			try {
				await fetch(generateUrl('/apps/scholiq/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({ default_register: this.defaultRegister.slug || this.defaultRegister }),
				})
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[ScholiqSettings] saveDefaultRegister failed:', error)
			}
		},

		/**
		 * Rotate the RS256 credential signing key pair via the backend endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-3
		 */
		async rotateSigningKey() {
			this.signingKeyLoading = true
			this.signingKeyMessage = ''
			try {
				const response = await fetch(generateUrl('/apps/scholiq/api/settings/load'), {
					method: 'POST',
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					this.signingKeyMessage = this.t('scholiq', 'Signing key rotated successfully.')
				} else {
					this.signingKeyMessage = this.t('scholiq', 'Failed to rotate signing key.')
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[ScholiqSettings] rotateSigningKey failed:', error)
				this.signingKeyMessage = this.t('scholiq', 'An error occurred while rotating the signing key.')
			} finally {
				this.signingKeyLoading = false
			}
		},
	},
}
</script>

<style scoped>
.scholiq-settings {
	padding: 20px;
	max-width: 900px;
}

.scholiq-settings__title {
	font-size: 1.5em;
	font-weight: bold;
	margin-bottom: 24px;
}

.scholiq-settings__field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 400px;
}

.scholiq-settings__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.scholiq-settings__table {
	width: 100%;
	border-collapse: collapse;
}

.scholiq-settings__table th,
.scholiq-settings__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.scholiq-settings__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.scholiq-settings__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 500;
}

.scholiq-settings__badge--enabled {
	background: var(--color-success);
	color: #fff;
}

.scholiq-settings__badge--disabled {
	background: var(--color-border-dark);
	color: var(--color-text-light);
}

.scholiq-settings__message {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
