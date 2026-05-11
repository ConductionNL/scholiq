<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  AuditPackExportModal — Compliance Audit Pack Export (ADR-008 §6)

  Custom page component registered via manifest.json pages[].component = "AuditPackExportModal".
  Allows compliance officers to export an on-demand ZIP audit pack filtered by
  regulation and date range.

  Flow:
    1. Component mounts → fetches published Regulations from OR REST API.
    2. Officer selects regulation + date range → clicks Export.
    3. POSTs to /api/compliance/audit/export (AuditPackExportController).
    4. Server streams ZIP; browser triggers file download via Blob URL.
    5. Inline error shown on HTTP 4xx/5xx.

  Per ADR-031: no app-local store, no Vuex, no custom router entry.
  Per ADR-008: audit-pack format (ndjson + csv + manifest + verification) is
               produced by the server; this component only triggers the download.
-->
<template>
	<div class="audit-pack-export">
		<h2 class="audit-pack-export__title">
			{{ t('scholiq', 'Export Compliance Audit Pack') }}
		</h2>
		<p class="audit-pack-export__lead">
			{{ t('scholiq', 'Generate an ADR-008 audit-pack ZIP for a regulation and date range. The pack contains an immutable evidence log in NDJSON + CSV format with HMAC signature verification.') }}
		</p>

		<div class="audit-pack-export__form">
			<!-- Regulation selector -->
			<div class="audit-pack-export__field">
				<label class="audit-pack-export__label" for="regulation-select">
					{{ t('scholiq', 'Regulation') }}
				</label>
				<NcSelect
					id="regulation-select"
					v-model="selectedRegulation"
					:options="regulationOptions"
					:loading="loadingRegulations"
					:placeholder="t('scholiq', 'Select a regulation…')"
					label="label"
					track-by="value"
					:disabled="exporting" />
				<span v-if="regulationLoadError" class="audit-pack-export__error">
					{{ regulationLoadError }}
				</span>
			</div>

			<!-- Date from -->
			<div class="audit-pack-export__field">
				<label class="audit-pack-export__label" for="date-from">
					{{ t('scholiq', 'From') }}
				</label>
				<input
					id="date-from"
					v-model="dateFrom"
					type="date"
					class="audit-pack-export__date-input"
					:max="dateTo || undefined"
					:disabled="exporting" />
			</div>

			<!-- Date to -->
			<div class="audit-pack-export__field">
				<label class="audit-pack-export__label" for="date-to">
					{{ t('scholiq', 'To') }}
				</label>
				<input
					id="date-to"
					v-model="dateTo"
					type="date"
					class="audit-pack-export__date-input"
					:min="dateFrom || undefined"
					:disabled="exporting" />
			</div>

			<NcButton
				type="primary"
				:disabled="!canExport || exporting"
				:aria-label="t('scholiq', 'Export audit pack')"
				@click="triggerExport">
				<template #icon>
					<span v-if="exporting" class="icon-loading-small" />
					<DownloadOutline v-else />
				</template>
				{{ exporting ? t('scholiq', 'Exporting…') : t('scholiq', 'Export ZIP') }}
			</NcButton>
		</div>

		<div v-if="exportError" class="audit-pack-export__error audit-pack-export__error--block">
			{{ exportError }}
		</div>

		<div v-if="lastExportFilename" class="audit-pack-export__success">
			{{ t('scholiq', 'Downloaded: {filename}', { filename: lastExportFilename }) }}
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'

export default {
	name: 'AuditPackExportModal',

	components: {
		NcButton,
		NcSelect,
		DownloadOutline,
	},

	data() {
		return {
			// Regulation dropdown state.
			regulationOptions: [],
			selectedRegulation: null,
			loadingRegulations: false,
			regulationLoadError: null,

			// Date range.
			dateFrom: '',
			dateTo: '',

			// Export state.
			exporting: false,
			exportError: null,
			lastExportFilename: null,
		}
	},

	computed: {
		/**
		 * Export button is enabled only when all three required fields are filled.
		 *
		 * @return {boolean}
		 */
		canExport() {
			return (
				this.selectedRegulation !== null
				&& this.dateFrom !== ''
				&& this.dateTo !== ''
			)
		},
	},

	mounted() {
		this.loadRegulations()
	},

	methods: {
		/**
		 * Fetch published Regulations from OR REST API and populate the dropdown.
		 */
		async loadRegulations() {
			this.loadingRegulations = true
			this.regulationLoadError = null

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/Regulation') + '?lifecycle=published'
				const response = await fetch(url, {
					headers: { 'Accept': 'application/json' },
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`)
				}

				const data = await response.json()
				const items = data.results ?? data ?? []

				this.regulationOptions = items.map((reg) => ({
					value: reg.slug ?? reg.id,
					label: reg.name ?? reg.slug ?? reg.id,
				}))
			} catch (err) {
				this.regulationLoadError = t('scholiq', 'Failed to load regulations: {error}', { error: err.message })
			} finally {
				this.loadingRegulations = false
			}
		},

		/**
		 * POST to AuditPackExportController and trigger a browser file download.
		 */
		async triggerExport() {
			if (!this.canExport) {
				return
			}

			this.exporting = true
			this.exportError = null
			this.lastExportFilename = null

			const regulationSlug = this.selectedRegulation.value
			const filename = `audit-pack_${regulationSlug}_${this.dateFrom}_${this.dateTo}.zip`

			try {
				const url = generateUrl('/apps/scholiq/api/compliance/audit/export')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/zip',
					},
					body: JSON.stringify({
						regulationSlug,
						dateFrom: this.dateFrom,
						dateTo: this.dateTo,
					}),
				})

				if (!response.ok) {
					const errorBody = await response.json().catch(() => ({}))
					throw new Error(errorBody.error ?? `HTTP ${response.status}`)
				}

				// Trigger download via Blob URL.
				const blob = await response.blob()
				const blobUrl = URL.createObjectURL(blob)
				const anchor = document.createElement('a')
				anchor.href = blobUrl
				anchor.download = filename
				document.body.appendChild(anchor)
				anchor.click()
				document.body.removeChild(anchor)
				URL.revokeObjectURL(blobUrl)

				this.lastExportFilename = filename
			} catch (err) {
				this.exportError = t('scholiq', 'Export failed: {error}', { error: err.message })
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.audit-pack-export {
	padding: 16px 4px 32px;
	max-width: 600px;
}

.audit-pack-export__title {
	margin: 0 0 8px;
	font-size: 20px;
	font-weight: 600;
}

.audit-pack-export__lead {
	margin: 0 0 24px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.audit-pack-export__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.audit-pack-export__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.audit-pack-export__label {
	font-weight: 500;
	font-size: 14px;
}

.audit-pack-export__date-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	width: 100%;
	max-width: 240px;
}

.audit-pack-export__date-input:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.audit-pack-export__error {
	color: var(--color-error);
	font-size: 13px;
}

.audit-pack-export__error--block {
	margin-top: 12px;
	padding: 10px 14px;
	background: var(--color-background-error);
	border-radius: var(--border-radius);
}

.audit-pack-export__success {
	margin-top: 12px;
	padding: 10px 14px;
	background: var(--color-background-success);
	border-radius: var(--border-radius);
	color: var(--color-success);
	font-size: 13px;
}
</style>
