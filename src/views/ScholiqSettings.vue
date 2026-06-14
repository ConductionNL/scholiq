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

		<!-- Section 4: AVG Art. 30 processing-activity register (provided by OpenRegister) -->
		<NcSettingsSection
			v-if="isAdmin"
			:name="t('scholiq', 'Processing Activity Register (AVG Art. 30)')"
			:description="t('scholiq', 'Scholiq\'s personal-data processing activities are recorded in OpenRegister\'s platform processing-activity register. The Art. 30 register, per-access logging, exports, and access control are provided by OpenRegister; Scholiq contributes its activity catalogue and surfaces it here. Access is restricted to administrators and the privacy officer (FG); non-privileged users are denied by OpenRegister.')">
			<div v-if="!openRegisterInstalled" class="scholiq-settings__field">
				<NcNoteCard type="warning">
					{{ t('scholiq', 'OpenRegister is not installed. The processing-activity register and Art. 30 export are provided by OpenRegister and are unavailable until it is installed.') }}
				</NcNoteCard>
			</div>
			<template v-else>
				<!-- Controller-identity record state + accountability prompt (OR-PA-1) -->
				<div class="scholiq-settings__field">
					<NcNoteCard type="info">
						{{ t('scholiq', 'The verwerkingsverantwoordelijke (controller) identity for the Art. 30 register is maintained centrally in OpenRegister. The school is the controller; configure it once in OpenRegister so it appears on every export and accountability report.') }}
					</NcNoteCard>
					<NcButton type="secondary" @click="openProcessingAccountability">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('scholiq', 'View controller identity & accountability in OpenRegister') }}
					</NcButton>
				</div>

				<!-- Activity catalogue (the seven Scholiq categories) -->
				<div class="scholiq-settings__field">
					<div class="scholiq-settings__catalogue-label">
						{{ t('scholiq', 'Scholiq processing activities') }}
					</div>
					<div class="scholiq-settings__message">
						{{ t('scholiq', 'Scholiq declares seven processing activities. They are seeded into OpenRegister as drafts when the Scholiq register configuration is imported; the privacy officer reviews, amends, and activates them in OpenRegister to make Scholiq processing attributable in the Art. 30 register.') }}
					</div>
					<ul class="scholiq-settings__activities">
						<li v-for="activity in processingActivities" :key="activity.code">
							<strong>{{ activity.name }}</strong>
							<span class="scholiq-settings__activity-meta">{{ activity.purpose }}</span>
							<span class="scholiq-settings__activity-meta">{{ t('scholiq', 'Legal basis: {basis}', { basis: activity.basis }) }}</span>
						</li>
					</ul>
				</div>

				<!-- Per-access log + per-subject extract (delegates to OpenRegister, OR-PA-7/8) -->
				<div class="scholiq-settings__field">
					<div class="scholiq-settings__catalogue-label">
						{{ t('scholiq', 'Processing log & Art. 30 export') }}
					</div>
					<div class="scholiq-settings__message">
						{{ t('scholiq', 'The per-access processing log and the per-subject (betrokkene) inzage extract are produced by OpenRegister, scoped to Scholiq\'s register, and never contain literal personal data beyond what the data subject is entitled to.') }}
					</div>
					<div class="scholiq-settings__activity-actions">
						<NcButton type="primary" @click="openProcessingLog">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							{{ t('scholiq', 'Open processing log in OpenRegister') }}
						</NcButton>
						<NcButton type="secondary" @click="openSubjectExtract">
							<template #icon>
								<AccountSearchOutline :size="20" />
							</template>
							{{ t('scholiq', 'Per-subject (betrokkene) extract') }}
						</NcButton>
					</div>
					<div class="scholiq-settings__message">
						<em>{{ t('scholiq', 'Note: the per-access read log and per-subject extract are available now. The aggregate Art. 30 register export to JSON/CSV/PDF is a forthcoming OpenRegister capability; until it lands, the compliance audit pack includes the read-log query result as verwerkingsregister.csv.') }}</em>
					</div>
				</div>
			</template>
		</NcSettingsSection>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'

export default {
	name: 'ScholiqSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		OpenInNew,
		FileExportOutline,
		AccountSearchOutline,
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
			isAdmin: false,
			openRegisterInstalled: false,
		}
	},

	computed: {
		/**
		 * The seven Scholiq processing activities surfaced in the AVG Art. 30
		 * compliance section. Mirrors the x-openregister-processing catalogue
		 * annotations in lib/Settings/scholiq_register.json (authoring source of
		 * truth); the register itself is owned and rendered by OpenRegister.
		 *
		 * @return {Array<{code: string, name: string, purpose: string, basis: string}>} Catalogue rows.
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		processingActivities() {
			return [
				{
					code: 'scholiq-learner-administration',
					name: t('scholiq', 'Learner administration (leerlingadministratie)'),
					purpose: t('scholiq', 'Maintain the learner record (incl. encrypted BSN, ECK iD, SchoolID) to deliver education and meet statutory reporting.'),
					basis: t('scholiq', 'public-task'),
				},
				{
					code: 'scholiq-attendance-leerplicht',
					name: t('scholiq', 'Attendance and leerplicht reporting'),
					purpose: t('scholiq', 'Register attendance and report verzuim to the leerplichtambtenaar / DUO.'),
					basis: t('scholiq', 'legal-obligation'),
				},
				{
					code: 'scholiq-grading-assessment',
					name: t('scholiq', 'Grading and assessment'),
					purpose: t('scholiq', 'Administer assessments and record grades and final marks.'),
					basis: t('scholiq', 'public-task'),
				},
				{
					code: 'scholiq-attestations',
					name: t('scholiq', 'Compliance training and signed attestations'),
					purpose: t('scholiq', 'Record completed mandatory training and capture signed attestations (incl. actor IP) as legal evidence.'),
					basis: t('scholiq', 'legal-obligation'),
				},
				{
					code: 'scholiq-credentialing',
					name: t('scholiq', 'Credentialing and certification'),
					purpose: t('scholiq', 'Issue, verify, and revoke verifiable credentials (EDCI / Open Badges 3.0).'),
					basis: t('scholiq', 'contract'),
				},
				{
					code: 'scholiq-data-exchange',
					name: t('scholiq', 'Data exchange with external parties'),
					purpose: t('scholiq', 'Exchange learner and result data with DUO/BRON-ROD, OSO, municipality, and HR systems.'),
					basis: t('scholiq', 'legal-obligation'),
				},
				{
					code: 'scholiq-ai-features',
					name: t('scholiq', 'AI-assisted learning features'),
					purpose: t('scholiq', 'Operate adaptive learning paths and record EU AI Act high-risk decision traces.'),
					basis: t('scholiq', 'consent'),
				},
			]
		},
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
					// The settings API also reports admin status and whether
					// OpenRegister is installed; both gate the AVG Art. 30 section.
					this.isAdmin = !!data.isAdmin
					this.openRegisterInstalled = !!data.openregisters
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

		/**
		 * Open OpenRegister's AVG controller-identity & accountability report
		 * (verantwoording). The record and report are OpenRegister's (OR-PA-1);
		 * Scholiq only deep-links.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openProcessingAccountability() {
			window.open(generateUrl('/apps/openregister/api/avg/verantwoording'), '_blank')
		},

		/**
		 * Open OpenRegister's AVG per-access processing log (verwerkingenlogging)
		 * scoped to Scholiq's register. The log, export, and access control are
		 * provided by OpenRegister (OR-PA-7/OR-PA-8); Scholiq only deep-links.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openProcessingLog() {
			window.open(generateUrl('/apps/openregister/api/avg/verwerkingen?register=scholiq'), '_blank')
		},

		/**
		 * Open OpenRegister's per-subject (betrokkene) inzage extract endpoint,
		 * scoped to Scholiq's register. Produced and gated by OpenRegister.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openSubjectExtract() {
			window.open(generateUrl('/apps/openregister/api/avg/verwerkingen/betrokkene?register=scholiq'), '_blank')
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

.scholiq-settings__catalogue-label {
	font-weight: 600;
	margin-top: 8px;
}

.scholiq-settings__activities {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.scholiq-settings__activities li {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.scholiq-settings__activity-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.scholiq-settings__activity-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 4px 0;
}
</style>
