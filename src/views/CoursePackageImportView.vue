<!--
  CoursePackageImportView.vue
  Custom page component for the CoursePackageImportView manifest page (type: custom).

  Uploads an IMS Common Cartridge 1.3 (.imscc/.zip) or Moodle backup (.mbz)
  course package via CoursePackageImportController, then renders the returned
  CoursePackageImportReport's entries table — the structural anti-Canvas
  promise: every source-package resource's outcome (imported/degraded/dropped)
  is shown, filterable, never a silent omission.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  same pattern as ItemAuthorView.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-course-package-frontend-is-declarative-with-one-named-custom-view-for-the-import-report
  @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-instructional-designer-uploads-a-package-and-sees-the-report
-->

<template>
	<div class="course-package-import">
		<header class="course-package-import__header">
			<h2 class="course-package-import__heading">
				{{ t('scholiq', 'Import course package') }}
			</h2>
			<p class="course-package-import__intro">
				{{ t('scholiq', 'Upload an IMS Common Cartridge 1.3 (.imscc/.zip) or Moodle backup (.mbz) package. Every resource in the package is reported — imported, degraded, or dropped — nothing is silently lost.') }}
			</p>
		</header>

		<!-- Upload form -->
		<div v-if="!report" class="course-package-import__upload">
			<label class="course-package-import__label" for="course-package-file">
				{{ t('scholiq', 'Course package file') }}
			</label>
			<input
				id="course-package-file"
				ref="fileInput"
				class="course-package-import__file-input"
				type="file"
				accept=".imscc,.zip,.mbz"
				:disabled="uploading"
				@change="onFileSelected">

			<p v-if="selectedFileName" class="course-package-import__selected">
				{{ t('scholiq', 'Selected: {name}', { name: selectedFileName }) }}
			</p>

			<button
				class="button-vue button-vue--primary"
				:disabled="uploading || !selectedFile"
				@click="uploadPackage">
				<span v-if="uploading" class="icon-loading" aria-hidden="true" />
				{{ uploading ? t('scholiq', 'Importing…') : t('scholiq', 'Import package') }}
			</button>

			<p v-if="uploadError" role="alert" class="course-package-import__error">
				{{ uploadError }}
			</p>
		</div>

		<!-- Report -->
		<div v-else class="course-package-import__report">
			<div
				class="course-package-import__summary"
				:class="`course-package-import__summary--${report.lifecycle}`"
				role="status">
				<p class="course-package-import__summary-lifecycle">
					{{ lifecycleLabel }}
				</p>
				<p v-if="report.errorMessage" class="course-package-import__error">
					{{ report.errorMessage }}
				</p>
				<ul v-else class="course-package-import__counts">
					<li>{{ t('scholiq', '{n} imported', { n: report.resourcesImported }) }}</li>
					<li>{{ t('scholiq', '{n} degraded', { n: report.resourcesDegraded }) }}</li>
					<li>{{ t('scholiq', '{n} dropped', { n: report.resourcesDropped }) }}</li>
				</ul>
			</div>

			<!-- Outcome filter -->
			<div class="course-package-import__filter">
				<label class="course-package-import__label" for="outcome-filter">
					{{ t('scholiq', 'Filter by outcome') }}
				</label>
				<select id="outcome-filter" v-model="outcomeFilter" class="course-package-import__select">
					<option value="all">
						{{ t('scholiq', 'All resources') }}
					</option>
					<option value="imported">
						{{ t('scholiq', 'Imported') }}
					</option>
					<option value="degraded">
						{{ t('scholiq', 'Degraded') }}
					</option>
					<option value="dropped">
						{{ t('scholiq', 'Dropped') }}
					</option>
				</select>
			</div>

			<!-- Entries table -->
			<table class="course-package-import__table">
				<thead>
					<tr>
						<th>{{ t('scholiq', 'Title') }}</th>
						<th>{{ t('scholiq', 'Resource type') }}</th>
						<th>{{ t('scholiq', 'Outcome') }}</th>
						<th>{{ t('scholiq', 'Target') }}</th>
						<th>{{ t('scholiq', 'Reason') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(entry, idx) in filteredEntries" :key="idx">
						<td>{{ entry.title }}</td>
						<td>{{ entry.resourceType }}</td>
						<td>
							<span :class="`course-package-import__badge course-package-import__badge--${entry.outcome}`">
								{{ entry.outcome }}
							</span>
						</td>
						<td>{{ entry.targetType || '—' }}</td>
						<td>{{ entry.reason || '—' }}</td>
					</tr>
					<tr v-if="filteredEntries.length === 0">
						<td colspan="5" class="course-package-import__empty">
							{{ t('scholiq', 'No resources match this filter.') }}
						</td>
					</tr>
				</tbody>
			</table>

			<button class="button-vue" @click="reset">
				{{ t('scholiq', 'Import another package') }}
			</button>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CoursePackageImportView',

	data() {
		return {
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
		 * Human-readable lifecycle summary line.
		 *
		 * @return {string} Localised summary text.
		 */
		lifecycleLabel() {
			if (!this.report) return ''
			const labels = {
				succeeded: this.t('scholiq', 'Import succeeded — every resource was imported.'),
				partial: this.t('scholiq', 'Import completed with some resources degraded or dropped.'),
				failed: this.t('scholiq', 'Import failed.'),
				running: this.t('scholiq', 'Import in progress…'),
			}
			return labels[this.report.lifecycle] ?? this.report.lifecycle
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
		 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-instructional-designer-uploads-a-package-and-sees-the-report
		 */
		onFileSelected(event) {
			const file = event.target.files?.[0] ?? null
			this.selectedFile = file
			this.selectedFileName = file ? file.name : ''
			this.uploadError = null
		},

		/**
		 * Upload the selected package and render the resulting report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#scenario-an-instructional-designer-uploads-a-package-and-sees-the-report
		 */
		async uploadPackage() {
			if (!this.selectedFile) return

			this.uploading = true
			this.uploadError = null

			const formData = new FormData()
			formData.append('file', this.selectedFile)

			try {
				const url = generateUrl('/apps/scholiq/api/course-management/course-package-import')
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
				this.uploadError = this.t('scholiq', 'Failed to import the package. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CoursePackageImportView] uploadPackage error', err)
			} finally {
				this.uploading = false
			}
		},

		/**
		 * Reset the view to upload another package.
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
.course-package-import {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.course-package-import__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px));
}

.course-package-import__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-package-import__label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.course-package-import__file-input {
	margin-bottom: var(--default-grid-baseline, 8px);
}

.course-package-import__selected {
	color: var(--color-text-maxcontrast);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.course-package-import__error {
	color: var(--color-error);
	margin-top: var(--default-grid-baseline, 8px);
}

.course-package-import__summary {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-package-import__summary--succeeded {
	border-left: 4px solid var(--color-success);
}

.course-package-import__summary--partial {
	border-left: 4px solid var(--color-warning);
}

.course-package-import__summary--failed {
	border-left: 4px solid var(--color-error);
}

.course-package-import__summary-lifecycle {
	font-weight: 600;
	margin-bottom: 4px;
}

.course-package-import__counts {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	list-style: none;
	padding: 0;
	margin: 0;
}

.course-package-import__filter {
	margin-bottom: var(--default-grid-baseline, 8px);
	max-width: 260px;
}

.course-package-import__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.course-package-import__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-package-import__table th,
.course-package-import__table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.course-package-import__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.85em;
	font-weight: 600;
}

.course-package-import__badge--imported {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.course-package-import__badge--degraded {
	background: var(--color-warning);
	color: var(--color-primary-element-text, #fff);
}

.course-package-import__badge--dropped {
	background: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}

.course-package-import__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}
</style>
