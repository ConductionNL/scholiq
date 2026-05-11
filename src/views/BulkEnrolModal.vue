<!--
  BulkEnrolModal.vue
  3-step modal for bulk-enrolling an audience into a Course.

  Step 1 — Audience picker: NC group selector (via OCS API) or CSV upload (browser-side).
  Step 2 — Section + config: Course picker, mandatory toggle, dueDate picker.
  Step 3 — Confirm + submit: POSTs directly to OR batch endpoint; polls for progress.

  No Scholiq backend involvement. Talks only to:
    - NC OCS: GET /ocs/v2.php/cloud/groups
    - OR REST: GET /api/openregister/scholiq/Course?lifecycle=published
    - OR REST: POST /api/openregister/scholiq/Enrolment/batch
    - OR REST: GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid>

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="bulk-enrol-modal">
		<div class="bulk-enrol-modal__header">
			<h2>{{ t('scholiq', 'Bulk Enrolment') }}</h2>
			<p class="bulk-enrol-modal__subtitle">
				{{ t('scholiq', 'Step {current} of {total}', { current: step, total: 3 }) }}
			</p>
		</div>

		<!-- Step indicator -->
		<div class="bulk-enrol-modal__steps" aria-label="progress">
			<span
				v-for="s in 3"
				:key="s"
				class="bulk-enrol-modal__step-dot"
				:class="{ 'bulk-enrol-modal__step-dot--active': step === s, 'bulk-enrol-modal__step-dot--done': step > s }"
				:aria-current="step === s ? 'step' : undefined" />
		</div>

		<!-- ===== STEP 1: Audience picker ===== -->
		<section v-if="step === 1" class="bulk-enrol-modal__section">
			<h3>{{ t('scholiq', 'Select audience') }}</h3>

			<div class="bulk-enrol-modal__field">
				<label for="group-select">{{ t('scholiq', 'Nextcloud group') }}</label>
				<select
					id="group-select"
					v-model="selectedGroupId"
					:disabled="csvUsers.length > 0"
					@change="clearCsv">
					<option value="">{{ t('scholiq', '— choose a group —') }}</option>
					<option v-for="group in groups" :key="group.id" :value="group.id">
						{{ group.displayName || group.id }}
					</option>
				</select>
				<span v-if="groupsError" class="bulk-enrol-modal__error">{{ groupsError }}</span>
			</div>

			<div class="bulk-enrol-modal__divider">
				<span>{{ t('scholiq', 'or') }}</span>
			</div>

			<div class="bulk-enrol-modal__field">
				<label for="csv-upload">{{ t('scholiq', 'Upload CSV of user IDs') }}</label>
				<input
					id="csv-upload"
					type="file"
					accept=".csv,text/csv"
					:disabled="selectedGroupId !== ''"
					@change="parseCsv" />
				<span v-if="csvUsers.length > 0" class="bulk-enrol-modal__hint">
					{{ n('scholiq', '{count} user found', '{count} users found', csvUsers.length, { count: csvUsers.length }) }}
				</span>
			</div>

			<div class="bulk-enrol-modal__actions">
				<button
					class="button-vue button-vue--primary"
					:disabled="selectedGroupId === '' && csvUsers.length === 0"
					@click="goToStep2">
					{{ t('scholiq', 'Next') }}
				</button>
			</div>
		</section>

		<!-- ===== STEP 2: Section + config ===== -->
		<section v-if="step === 2" class="bulk-enrol-modal__section">
			<h3>{{ t('scholiq', 'Course and settings') }}</h3>

			<div class="bulk-enrol-modal__field">
				<label for="course-select">{{ t('scholiq', 'Course') }}</label>
				<select id="course-select" v-model="selectedCourseId">
					<option value="">{{ t('scholiq', '— choose a course —') }}</option>
					<option v-for="course in courses" :key="course.uuid" :value="course.uuid">
						{{ course.title || course.uuid }}
					</option>
				</select>
				<span v-if="coursesError" class="bulk-enrol-modal__error">{{ coursesError }}</span>
			</div>

			<div class="bulk-enrol-modal__field bulk-enrol-modal__field--toggle">
				<label>
					<input v-model="mandatory" type="checkbox" />
					{{ t('scholiq', 'Mandatory training') }}
				</label>
			</div>

			<div class="bulk-enrol-modal__field">
				<label for="due-date">{{ t('scholiq', 'Due date') }}</label>
				<input
					id="due-date"
					v-model="dueDate"
					type="date"
					:min="todayIso" />
				<span class="bulk-enrol-modal__hint">{{ t('scholiq', 'Leave empty for no deadline') }}</span>
			</div>

			<div class="bulk-enrol-modal__actions">
				<button class="button-vue" @click="step = 1">
					{{ t('scholiq', 'Back') }}
				</button>
				<button
					class="button-vue button-vue--primary"
					:disabled="selectedCourseId === ''"
					@click="goToStep3">
					{{ t('scholiq', 'Next') }}
				</button>
			</div>
		</section>

		<!-- ===== STEP 3: Confirm + submit ===== -->
		<section v-if="step === 3" class="bulk-enrol-modal__section">
			<h3>{{ t('scholiq', 'Confirm and submit') }}</h3>

			<dl class="bulk-enrol-modal__summary">
				<dt>{{ t('scholiq', 'Audience') }}</dt>
				<dd v-if="selectedGroupId">
					{{ t('scholiq', 'Group: {group}', { group: selectedGroupId }) }}
				</dd>
				<dd v-else>
					{{ n('scholiq', '{count} user from CSV', '{count} users from CSV', csvUsers.length, { count: csvUsers.length }) }}
				</dd>

				<dt>{{ t('scholiq', 'Course') }}</dt>
				<dd>{{ selectedCourseTitle }}</dd>

				<dt>{{ t('scholiq', 'Mandatory') }}</dt>
				<dd>{{ mandatory ? t('scholiq', 'Yes') : t('scholiq', 'No') }}</dd>

				<dt>{{ t('scholiq', 'Due date') }}</dt>
				<dd>{{ dueDate || t('scholiq', 'None') }}</dd>
			</dl>

			<div v-if="submitting" class="bulk-enrol-modal__progress">
				<span class="loading-icon" aria-live="polite">
					{{ t('scholiq', 'Submitting…') }}
				</span>
			</div>

			<div v-if="submitError" class="bulk-enrol-modal__error" role="alert">
				{{ submitError }}
			</div>

			<div v-if="pollResult" class="bulk-enrol-modal__result" role="status">
				{{
					t('scholiq', '{enrolled} enrolments created, {skipped} skipped.', {
						enrolled: pollResult.enrolled,
						skipped: pollResult.skipped,
					})
				}}
			</div>

			<div class="bulk-enrol-modal__actions">
				<button class="button-vue" :disabled="submitting" @click="step = 2">
					{{ t('scholiq', 'Back') }}
				</button>
				<button
					class="button-vue button-vue--primary"
					:disabled="submitting || pollResult !== null"
					@click="submit">
					{{ t('scholiq', 'Enrol') }}
				</button>
			</div>
		</section>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

/**
 * Generate a simple UUID v4 browser-side.
 *
 * @return {string} UUID v4 string.
 */
function uuidv4() {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r = (Math.random() * 16) | 0
		const v = c === 'x' ? r : (r & 0x3) | 0x8
		return v.toString(16)
	})
}

export default {
	name: 'BulkEnrolModal',

	data() {
		return {
			step: 1,

			// Step 1
			groups: [],
			groupsError: null,
			selectedGroupId: '',
			csvUsers: [],

			// Step 2
			courses: [],
			coursesError: null,
			selectedCourseId: '',
			mandatory: true,
			dueDate: '',

			// Step 3
			submitting: false,
			submitError: null,
			pollResult: null,
		}
	},

	computed: {
		/**
		 * Today as ISO date string for min-date on dueDate input.
		 *
		 * @return {string}
		 */
		todayIso() {
			return new Date().toISOString().slice(0, 10)
		},

		/**
		 * Human-readable title for the selected course.
		 *
		 * @return {string}
		 */
		selectedCourseTitle() {
			const course = this.courses.find((c) => c.uuid === this.selectedCourseId)
			return course ? (course.title || course.uuid) : this.selectedCourseId
		},
	},

	created() {
		this.fetchGroups()
	},

	methods: {
		/**
		 * Fetch Nextcloud groups from OCS API.
		 *
		 * @return {Promise<void>}
		 */
		async fetchGroups() {
			try {
				const url = generateUrl('/ocs/v2.php/cloud/groups?format=json')
				const response = await axios.get(url, {
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				const rawGroups = response.data?.ocs?.data?.groups ?? []
				// OCS returns an array of group id strings — normalise to objects.
				this.groups = rawGroups.map((g) =>
					typeof g === 'string' ? { id: g, displayName: g } : g,
				)
			} catch (err) {
				this.groupsError = this.t('scholiq', 'Failed to load groups')
				// eslint-disable-next-line no-console
				console.error('[BulkEnrolModal] fetchGroups error', err)
			}
		},

		/**
		 * Fetch published courses from OR REST API.
		 *
		 * @return {Promise<void>}
		 */
		async fetchCourses() {
			try {
				const url = generateUrl('/api/openregister/scholiq/Course?lifecycle=published')
				const response = await axios.get(url)
				this.courses = response.data?.results ?? response.data ?? []
			} catch (err) {
				this.coursesError = this.t('scholiq', 'Failed to load courses')
				// eslint-disable-next-line no-console
				console.error('[BulkEnrolModal] fetchCourses error', err)
			}
		},

		/**
		 * Parse an uploaded CSV file browser-side and extract user IDs from the first column.
		 *
		 * @param {Event} event File input change event.
		 * @return {void}
		 */
		parseCsv(event) {
			const file = event.target.files?.[0]
			if (!file) {
				this.csvUsers = []
				return
			}

			const reader = new FileReader()
			reader.onload = (e) => {
				const text = e.target.result
				const lines = text.split(/\r?\n/).filter((l) => l.trim() !== '')
				// First column of each row is the NC user ID; skip header if it looks like one.
				const users = lines
					.map((l) => l.split(',')[0].trim())
					.filter((u) => u !== '' && u.toLowerCase() !== 'userid' && u.toLowerCase() !== 'user_id')
				this.csvUsers = users
			}
			reader.readAsText(file)
		},

		/**
		 * Clear CSV selection when a group is chosen.
		 *
		 * @return {void}
		 */
		clearCsv() {
			this.csvUsers = []
		},

		/**
		 * Advance to step 2 and load courses.
		 *
		 * @return {void}
		 */
		goToStep2() {
			this.step = 2
			if (this.courses.length === 0) {
				this.fetchCourses()
			}
		},

		/**
		 * Advance to step 3 for the confirmation screen.
		 *
		 * @return {void}
		 */
		goToStep3() {
			this.step = 3
			this.pollResult = null
			this.submitError = null
		},

		/**
		 * Resolve the audience to a flat array of NC user IDs.
		 * Group members are fetched from the OCS API; CSV users are already resolved browser-side.
		 *
		 * @return {Promise<string[]>}
		 */
		async resolveAudience() {
			if (this.csvUsers.length > 0) {
				return [...this.csvUsers]
			}

			const url = generateUrl(`/ocs/v2.php/cloud/groups/${encodeURIComponent(this.selectedGroupId)}/members?format=json`)
			const response = await axios.get(url, {
				headers: { 'OCS-APIREQUEST': 'true' },
			})
			return response.data?.ocs?.data?.users ?? []
		},

		/**
		 * Build the batch payload and POST directly to OR's batch endpoint.
		 * Polls for progress using bulkJobId.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			this.submitting = true
			this.submitError = null
			this.pollResult = null

			try {
				const users = await this.resolveAudience()
				if (users.length === 0) {
					this.submitError = this.t('scholiq', 'No users found for the selected audience.')
					return
				}

				const bulkJobId = uuidv4()

				const objects = users.map((userId) => ({
					learnerId: userId,
					courseId: this.selectedCourseId,
					mandatory: this.mandatory,
					dueDate: this.dueDate || null,
					source: 'bulk',
					bulkJobId,
				}))

				const batchUrl = generateUrl('/api/openregister/scholiq/Enrolment/batch')
				await axios.post(batchUrl, { objects })

				// Poll until all expected Enrolments are visible.
				await this.pollProgress(bulkJobId, users.length)
			} catch (err) {
				this.submitError = this.t('scholiq', 'Submission failed: {message}', {
					message: err.response?.data?.message ?? err.message,
				})
				// eslint-disable-next-line no-console
				console.error('[BulkEnrolModal] submit error', err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Poll GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid> until
		 * the returned count matches expectedCount or 30 attempts are exhausted.
		 *
		 * @param {string} bulkJobId     The UUID used for this batch.
		 * @param {number} expectedCount Total users submitted.
		 * @return {Promise<void>}
		 */
		async pollProgress(bulkJobId, expectedCount) {
			const pollUrl = generateUrl(`/api/openregister/scholiq/Enrolment?bulkJobId=${encodeURIComponent(bulkJobId)}`)
			const maxAttempts = 30
			const delayMs = 2000

			for (let attempt = 0; attempt < maxAttempts; attempt++) {
				await new Promise((resolve) => setTimeout(resolve, delayMs))
				const response = await axios.get(pollUrl)
				const results = response.data?.results ?? response.data ?? []
				const enrolled = Array.isArray(results) ? results.length : (response.data?.total ?? 0)

				if (enrolled >= expectedCount) {
					this.pollResult = { enrolled, skipped: expectedCount - enrolled }
					return
				}
			}

			// Timed-out — report whatever we know.
			const finalResponse = await axios.get(pollUrl)
			const finalResults = finalResponse.data?.results ?? finalResponse.data ?? []
			const enrolled = Array.isArray(finalResults) ? finalResults.length : 0
			this.pollResult = { enrolled, skipped: expectedCount - enrolled }
		},
	},
}
</script>

<style scoped>
.bulk-enrol-modal {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 560px;
}

.bulk-enrol-modal__header {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.bulk-enrol-modal__subtitle {
	color: var(--color-text-maxcontrast, #6b7280);
	font-size: 0.875rem;
	margin-top: 0;
}

.bulk-enrol-modal__steps {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);
}

.bulk-enrol-modal__step-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background-color: var(--color-border-dark, #d1d5db);
	display: inline-block;
}

.bulk-enrol-modal__step-dot--active {
	background-color: var(--color-primary, #4376fc);
}

.bulk-enrol-modal__step-dot--done {
	background-color: var(--color-success, #46ba61);
}

.bulk-enrol-modal__section h3 {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.bulk-enrol-modal__field {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.bulk-enrol-modal__field label {
	font-weight: 500;
}

.bulk-enrol-modal__field select,
.bulk-enrol-modal__field input[type="date"],
.bulk-enrol-modal__field input[type="file"] {
	width: 100%;
}

.bulk-enrol-modal__field--toggle {
	flex-direction: row;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.bulk-enrol-modal__divider {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	color: var(--color-text-maxcontrast, #6b7280);
}

.bulk-enrol-modal__divider::before,
.bulk-enrol-modal__divider::after {
	content: '';
	flex: 1;
	border-top: 1px solid var(--color-border, #e5e7eb);
}

.bulk-enrol-modal__hint {
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast, #6b7280);
}

.bulk-enrol-modal__error {
	color: var(--color-error, #e9322d);
	font-size: 0.875rem;
}

.bulk-enrol-modal__summary {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 4);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);
}

.bulk-enrol-modal__summary dt {
	font-weight: 500;
	color: var(--color-text-maxcontrast, #6b7280);
}

.bulk-enrol-modal__progress {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.bulk-enrol-modal__result {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	color: var(--color-success, #46ba61);
	font-weight: 500;
}

.bulk-enrol-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
}
</style>
