<!--
  LearningRecordImportView.vue
  Custom page component for the LearningRecordImportView manifest page (type: custom).

  Uploads another institution's exported learning record (or a bare
  ELM/Europass credential set) as evidence for one Application during
  admissions intake, then renders the resulting LearningRecordImport's
  verificationStatus and entries[] table — modeled directly on
  CoursePackageImportView.vue's upload+live-report shape.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  same pattern as CoursePackageImportView.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
-->

<template>
	<div class="learning-record-import">
		<header class="learning-record-import__header">
			<h2 class="learning-record-import__heading">
				{{ t('scholiq', 'Import prior learning record') }}
			</h2>
			<p class="learning-record-import__intro">
				{{ t('scholiq', 'Upload the applicant\'s exported Scholiq learning record, or a bare ELM/Europass credential set. Every record found in the bundle is reported — nothing is silently lost. This is evidence for your own decision, not an automatic write.') }}
			</p>
		</header>

		<!-- Upload form -->
		<div v-if="!report" class="learning-record-import__upload">
			<label class="learning-record-import__label" for="lri-source-format">
				{{ t('scholiq', 'Bundle format') }}
			</label>
			<select id="lri-source-format" v-model="sourceFormat" class="learning-record-import__select">
				<option value="scholiq-learning-record">
					{{ t('scholiq', 'Scholiq learning record export') }}
				</option>
				<option value="elm-europass">
					{{ t('scholiq', 'Bare ELM / Europass credential set') }}
				</option>
			</select>

			<label class="learning-record-import__label" for="lri-file">
				{{ t('scholiq', 'Bundle file') }}
			</label>
			<input
				id="lri-file"
				ref="fileInput"
				class="learning-record-import__file-input"
				type="file"
				accept=".json"
				:disabled="uploading"
				@change="onFileSelected">

			<p v-if="selectedFileName" class="learning-record-import__selected">
				{{ t('scholiq', 'Selected: {name}', { name: selectedFileName }) }}
			</p>

			<button
				class="button-vue button-vue--primary"
				:disabled="uploading || !selectedFile"
				@click="uploadBundle">
				<span v-if="uploading" class="icon-loading" aria-hidden="true" />
				{{ uploading ? t('scholiq', 'Uploading…') : t('scholiq', 'Upload and parse') }}
			</button>

			<p v-if="uploadError" role="alert" class="learning-record-import__error">
				{{ uploadError }}
			</p>
		</div>

		<!-- Report -->
		<div v-else class="learning-record-import__report">
			<div
				class="learning-record-import__summary"
				:class="`learning-record-import__summary--${verificationSeverity}`"
				role="status">
				<p class="learning-record-import__summary-status">
					{{ verificationLabel }}
				</p>
				<p v-if="report.errorMessage" class="learning-record-import__error">
					{{ report.errorMessage }}
				</p>
			</div>

			<!-- Outcome filter -->
			<div class="learning-record-import__filter">
				<label class="learning-record-import__label" for="lri-outcome-filter">
					{{ t('scholiq', 'Filter by outcome') }}
				</label>
				<select id="lri-outcome-filter" v-model="outcomeFilter" class="learning-record-import__select">
					<option value="all">
						{{ t('scholiq', 'All records') }}
					</option>
					<option value="recognized">
						{{ t('scholiq', 'Recognized') }}
					</option>
					<option value="unrecognized">
						{{ t('scholiq', 'Unrecognized') }}
					</option>
				</select>
			</div>

			<!-- Entries table -->
			<table class="learning-record-import__table">
				<thead>
					<tr>
						<th>{{ t('scholiq', 'Title') }}</th>
						<th>{{ t('scholiq', 'Source schema') }}</th>
						<th>{{ t('scholiq', 'Outcome') }}</th>
						<th>{{ t('scholiq', 'Reason') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(entry, idx) in filteredEntries" :key="idx">
						<td>{{ entry.sourceTitle }}</td>
						<td>{{ entry.sourceSchema || '—' }}</td>
						<td>
							<span :class="`learning-record-import__badge learning-record-import__badge--${entry.outcome}`">
								{{ entry.outcome }}
							</span>
						</td>
						<td>{{ entry.reason || '—' }}</td>
					</tr>
					<tr v-if="filteredEntries.length === 0">
						<td colspan="4" class="learning-record-import__empty">
							{{ t('scholiq', 'No records match this filter.') }}
						</td>
					</tr>
				</tbody>
			</table>

			<button class="button-vue" @click="reset">
				{{ t('scholiq', 'Upload another bundle') }}
			</button>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'LearningRecordImportView',

	data() {
		return {
			sourceFormat: 'scholiq-learning-record',
			selectedFile: null,
			selectedFileName: '',
			uploading: false,
			uploadError: null,
			report: null,
			outcomeFilter: 'all',
		}
	},

	computed: {
		/**
		 * The Application UUID this import is evidence for, from the route.
		 *
		 * @return {string}
		 */
		applicationId() {
			return this.$route?.params?.applicationId ?? ''
		},

		/**
		 * Human-readable verification summary line.
		 *
		 * @return {string} Localised summary text.
		 */
		verificationLabel() {
			if (!this.report) return ''
			const labels = {
				verified: this.t('scholiq', 'Verified — the signing tenant is recognised by this school.'),
				unverifiable: this.t('scholiq', 'Unverifiable — a well-formed bundle from a system this school does not recognise. This is expected, not an error.'),
				invalid: this.t('scholiq', 'Signature invalid — this bundle may have been tampered with.'),
			}
			return labels[this.report.verificationStatus] ?? this.t('scholiq', 'Could not be parsed.')
		},

		/**
		 * CSS modifier matching the verification severity.
		 *
		 * @return {string}
		 */
		verificationSeverity() {
			const map = { verified: 'success', unverifiable: 'warning', invalid: 'error' }
			return map[this.report?.verificationStatus] ?? 'error'
		},

		/**
		 * Report entries filtered by the selected outcome.
		 *
		 * @return {Array<object>} Filtered entries.
		 */
		filteredEntries() {
			const entries = this.report?.entries ?? []
			if (this.outcomeFilter === 'all') return entries
			return entries.filter((entry) => entry.outcome === this.outcomeFilter)
		},
	},

	methods: {
		/**
		 * Capture the selected file from the native file input.
		 *
		 * @param {Event} event The change event.
		 * @return {void}
		 */
		onFileSelected(event) {
			const file = event.target.files?.[0] ?? null
			this.selectedFile = file
			this.selectedFileName = file ? file.name : ''
			this.uploadError = null
		},

		/**
		 * Upload the selected bundle and render the resulting report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
		 */
		async uploadBundle() {
			if (!this.selectedFile || !this.applicationId) return

			this.uploading = true
			this.uploadError = null

			const formData = new FormData()
			formData.append('file', this.selectedFile)
			formData.append('sourceFormat', this.sourceFormat)

			try {
				const url = generateUrl(`/apps/scholiq/api/applications/${this.applicationId}/learning-record-imports`)
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						requesttoken: window.OC?.requestToken ?? '',
					},
					body: formData,
				})

				const json = await resp.json()
				if (!resp.ok) {
					throw new Error(json.error ?? `Import failed: ${resp.status}`)
				}

				this.report = json
			} catch (err) {
				this.uploadError = this.t('scholiq', 'Failed to upload the bundle. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LearningRecordImportView] uploadBundle error', err)
			} finally {
				this.uploading = false
			}
		},

		/**
		 * Reset the view to upload another bundle.
		 *
		 * @return {void}
		 */
		reset() {
			this.report = null
			this.selectedFile = null
			this.selectedFileName = ''
			this.outcomeFilter = 'all'
			this.uploadError = null
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},
	},
}
</script>

<style scoped>
.learning-record-import {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.learning-record-import__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px));
}

.learning-record-import__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.learning-record-import__label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.learning-record-import__select {
	width: 100%;
	max-width: 320px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.learning-record-import__file-input {
	margin-bottom: var(--default-grid-baseline, 8px);
}

.learning-record-import__selected {
	color: var(--color-text-maxcontrast);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.learning-record-import__error {
	color: var(--color-error);
	margin-top: var(--default-grid-baseline, 8px);
}

.learning-record-import__summary {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.learning-record-import__summary--success {
	border-left: 4px solid var(--color-success);
}

.learning-record-import__summary--warning {
	border-left: 4px solid var(--color-warning);
}

.learning-record-import__summary--error {
	border-left: 4px solid var(--color-error);
}

.learning-record-import__summary-status {
	font-weight: 600;
}

.learning-record-import__filter {
	margin-bottom: var(--default-grid-baseline, 8px);
	max-width: 260px;
}

.learning-record-import__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.learning-record-import__table th,
.learning-record-import__table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.learning-record-import__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.85em;
	font-weight: 600;
}

.learning-record-import__badge--recognized {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.learning-record-import__badge--unrecognized {
	background: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}

.learning-record-import__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}
</style>
