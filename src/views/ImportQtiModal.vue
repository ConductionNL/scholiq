<!--
  ImportQtiModal.vue
  Custom page component for the ImportQtiModal manifest page (type: custom).

  Allows an item author to upload a QTI 2.x / 3.0 or Common Cartridge ZIP package
  and import its items into a selected ItemBank. POSTs the file to the
  QtiImportController endpoint and shows the created-item count.

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="import-qti-modal">
		<header class="import-qti-modal__header">
			<h2 class="import-qti-modal__title">
				{{ t('scholiq', 'Import QTI package') }}
			</h2>
			<p class="import-qti-modal__subtitle">
				{{ t('scholiq', 'Upload a QTI 2.x / 3.0 or IMS Common Cartridge ZIP file to import items into an Item Bank.') }}
			</p>
		</header>

		<!-- Success -->
		<div v-if="importResult"
			class="import-qti-modal__success"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'Import complete: {n} item(s) created.', { n: importResult.itemCount }) }}</p>
			<button class="button-vue" @click="reset">
				{{ t('scholiq', 'Import another package') }}
			</button>
		</div>

		<template v-else>
			<!-- ItemBank selection -->
			<div class="import-qti-modal__field">
				<label class="import-qti-modal__label" for="item-bank-select">
					{{ t('scholiq', 'Target Item Bank') }}
				</label>
				<div v-if="loadingBanks" class="import-qti-modal__loading">
					<span class="icon-loading" aria-hidden="true" />
					<span>{{ t('scholiq', 'Loading item banks...') }}</span>
				</div>
				<select
					v-else
					id="item-bank-select"
					v-model="selectedBankId"
					class="import-qti-modal__select">
					<option disabled value="">
						{{ t('scholiq', 'Select an item bank...') }}
					</option>
					<option v-for="bank in itemBanks" :key="bank.uuid" :value="bank.uuid">
						{{ bank.name }}
					</option>
				</select>
				<p v-if="bankError" class="import-qti-modal__error-inline">
					{{ bankError }}
				</p>
			</div>

			<!-- File input -->
			<div class="import-qti-modal__field">
				<label class="import-qti-modal__label" for="qti-file-input">
					{{ t('scholiq', 'QTI / CC package (.zip)') }}
				</label>
				<input
					id="qti-file-input"
					ref="fileInput"
					class="import-qti-modal__file-input"
					type="file"
					accept=".zip"
					:disabled="importing"
					@change="handleFileChange" />
				<p v-if="selectedFileName" class="import-qti-modal__filename">
					{{ selectedFileName }}
				</p>
			</div>

			<!-- Error -->
			<div v-if="importError" class="import-qti-modal__error" role="alert">
				<span class="icon-error" aria-hidden="true" />
				<p>{{ importError }}</p>
			</div>

			<!-- Submit -->
			<div class="import-qti-modal__actions">
				<button
					class="button-vue button-vue--primary"
					:disabled="!selectedBankId || !selectedFile || importing"
					@click="doImport">
					<span v-if="importing" class="icon-loading" aria-hidden="true" />
					{{ importing ? t('scholiq', 'Importing...') : t('scholiq', 'Import') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ImportQtiModal',

	props: {
		/**
		 * Optional pre-selected ItemBank UUID from route :itemBankId param.
		 */
		itemBankId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			/** @type {object[]} */
			itemBanks: [],
			selectedBankId: '',
			/** @type {File|null} */
			selectedFile: null,
			selectedFileName: '',
			loadingBanks: false,
			importing: false,
			importError: null,
			bankError: null,
			/** @type {object|null} */
			importResult: null,
		}
	},

	created() {
		if (this.itemBankId) {
			this.selectedBankId = this.itemBankId
		}

		this.loadItemBanks()
	},

	methods: {
		/**
		 * Fetch available ItemBanks from OR.
		 *
		 * @return {Promise<void>}
		 */
		async loadItemBanks() {
			this.loadingBanks = true
			this.bankError = null

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/ItemBank?limit=100')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) throw new Error(`ItemBank fetch failed: ${resp.status}`)
				const json = await resp.json()
				this.itemBanks = json.results ?? json.objects ?? []
			} catch (err) {
				this.bankError = this.t('scholiq', 'Failed to load item banks.')
				// eslint-disable-next-line no-console
				console.error('[ImportQtiModal] loadItemBanks error', err)
			} finally {
				this.loadingBanks = false
			}
		},

		/**
		 * Handle file input change.
		 *
		 * @param {Event} event
		 * @return {void}
		 */
		handleFileChange(event) {
			const file = event.target.files?.[0] ?? null
			this.selectedFile = file
			this.selectedFileName = file ? file.name : ''
			this.importError = null
		},

		/**
		 * Upload the package and call the QtiImportController endpoint.
		 *
		 * @return {Promise<void>}
		 */
		async doImport() {
			if (!this.selectedBankId || !this.selectedFile) return

			this.importing = true
			this.importError = null

			try {
				const formData = new FormData()
				formData.append('file', this.selectedFile)
				formData.append('itemBankId', this.selectedBankId)

				const url = generateUrl(`/apps/scholiq/api/assessment/qti-import?itemBankId=${encodeURIComponent(this.selectedBankId)}`)
				const resp = await fetch(url, {
					method: 'POST',
					headers: { 'OCS-APIREQUEST': 'true' },
					body: formData,
				})

				const json = await resp.json()

				if (!resp.ok) {
					throw new Error(json.error ?? `Import failed: ${resp.status}`)
				}

				this.importResult = json
			} catch (err) {
				this.importError = this.t('scholiq', 'Import failed: {msg}', { msg: err.message })
				// eslint-disable-next-line no-console
				console.error('[ImportQtiModal] doImport error', err)
			} finally {
				this.importing = false
			}
		},

		/**
		 * Reset the form for another import.
		 *
		 * @return {void}
		 */
		reset() {
			this.importResult = null
			this.selectedFile = null
			this.selectedFileName = ''
			this.importError = null
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},
	},
}
</script>

<style scoped>
.import-qti-modal {
	max-width: 600px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.import-qti-modal__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.import-qti-modal__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.import-qti-modal__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.import-qti-modal__field {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.import-qti-modal__label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.import-qti-modal__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
	box-sizing: border-box;
}

.import-qti-modal__file-input {
	display: block;
	margin-top: 4px;
}

.import-qti-modal__filename {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.import-qti-modal__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
}

.import-qti-modal__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	color: var(--color-error);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.import-qti-modal__error-inline {
	color: var(--color-error);
	font-size: 0.85em;
	margin-top: 4px;
}

.import-qti-modal__actions {
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.import-qti-modal__success {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	text-align: center;
}

.import-qti-modal__success .icon-checkmark {
	font-size: 2em;
	color: var(--color-success);
	display: block;
	margin-bottom: var(--default-grid-baseline, 8px);
}
</style>
