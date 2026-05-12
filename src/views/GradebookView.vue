<!--
  GradebookView.vue
  Custom page component for the GradebookView manifest page (type: custom).

  Cohort grade grid — the teacher's batch-entry and soft-publish surface:
  1. Fetch the Cohort (learner list) + CurriculumPlan (components).
  2. Fetch all existing GradeEntries for this cohort + plan (all lifecycle states).
  3. Render a learner × component grid. Each cell shows the current value
     (concept / published / revised) or a blank input for missing entries.
     Teachers enter/edit values directly in the grid.
  4. Show a simple distribution histogram below the grid (bucket count by value
     band, computed from all current values).
  5. "Publish all" button: transitions every concept GradeEntry for this
     cohort + plan to `published`. Only published entries trigger the
     gradePublished notification (per the learner's OR notification preference).
     Until "Publish all", no notification fires.

  Uses Options API + direct fetch (no custom Pinia store modules).
  Route params: :cohortId (Cohort UUID), :planId (CurriculumPlan UUID).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="gradebook-view">
		<!-- Loading -->
		<div v-if="loading" class="gradebook-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading gradebook...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="gradebook-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<!-- Header -->
			<header class="gradebook-view__header">
				<h2>{{ t('scholiq', 'Gradebook') }}</h2>
				<p class="gradebook-view__meta">
					{{ t('scholiq', 'Cohort: {name}', { name: cohort.name || cohortId }) }}
					— {{ t('scholiq', 'Plan: {name}', { name: plan.name || planId }) }}
				</p>
			</header>

			<!-- Grade grid -->
			<div class="gradebook-view__grid-wrap">
				<table class="gradebook-view__grid" aria-label="gradebook">
					<thead>
						<tr>
							<th class="gradebook-view__learner-col">{{ t('scholiq', 'Learner') }}</th>
							<th
								v-for="component in components"
								:key="component.componentId"
								class="gradebook-view__component-col">
								<span class="gradebook-view__component-label">{{ component.label }}</span>
								<span class="gradebook-view__component-weight">
									{{ t('scholiq', '(w:{w})', { w: component.weight }) }}
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="learner in learners"
							:key="learner.id"
							class="gradebook-view__learner-row">
							<td class="gradebook-view__learner-cell">{{ learner.displayName || learner.id }}</td>
							<td
								v-for="component in components"
								:key="component.componentId"
								class="gradebook-view__entry-cell">
								<div class="gradebook-view__cell-wrap">
									<input
										:value="getCellValue(learner.id, component.componentId)"
										class="gradebook-view__value-input"
										type="number"
										step="0.1"
										:aria-label="t('scholiq', 'Grade for {learner} / {component}', { learner: learner.id, component: component.label })"
										:disabled="saving"
										:class="getCellClass(learner.id, component.componentId)"
										@change="onCellChange(learner.id, component, $event.target.value)">
									<span
										v-if="getCellLifecycle(learner.id, component.componentId)"
										class="gradebook-view__cell-status"
										:title="getCellLifecycle(learner.id, component.componentId)">
										{{ lifecycleIcon(getCellLifecycle(learner.id, component.componentId)) }}
									</span>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Distribution histogram -->
			<section class="gradebook-view__distribution" aria-label="grade distribution">
				<h3>{{ t('scholiq', 'Distribution preview') }}</h3>
				<div v-if="histogramBuckets.length > 0" class="gradebook-view__histogram">
					<div
						v-for="bucket in histogramBuckets"
						:key="bucket.label"
						class="gradebook-view__histogram-bar-wrap">
						<span class="gradebook-view__histogram-label">{{ bucket.label }}</span>
						<div
							class="gradebook-view__histogram-bar"
							:style="{ width: barWidth(bucket.count) + '%' }"
							:title="t('scholiq', '{count} grade(s)', { count: bucket.count })" />
						<span class="gradebook-view__histogram-count">{{ bucket.count }}</span>
					</div>
				</div>
				<p v-else class="gradebook-view__no-data">
					{{ t('scholiq', 'No grades entered yet.') }}
				</p>
			</section>

			<!-- Actions -->
			<div class="gradebook-view__actions">
				<p v-if="conceptCount > 0" class="gradebook-view__concept-count">
					{{ t('scholiq', '{count} concept grade(s) ready to publish', { count: conceptCount }) }}
				</p>
				<button
					class="button-vue button-vue--primary gradebook-view__publish-btn"
					:disabled="saving || conceptCount === 0"
					@click="publishAll">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Publish all') }}
				</button>
			</div>
			<p v-if="saveError" role="alert" class="gradebook-view__save-error">{{ saveError }}</p>
			<p v-if="publishedMessage" role="status" class="gradebook-view__published-message">{{ publishedMessage }}</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

const BUCKET_COUNT = 10

export default {
	name: 'GradebookView',

	props: {
		/**
		 * Cohort UUID injected by vue-router from :cohortId param.
		 */
		cohortId: {
			type: String,
			required: true,
		},
		/**
		 * CurriculumPlan UUID injected by vue-router from :planId param.
		 */
		planId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object} */
			cohort: {},
			/** @type {object} */
			plan: {},
			/** @type {Array<{id: string, displayName: string}>} */
			learners: [],
			/** @type {Array<object>} */
			components: [],
			/**
			 * Map of `${learnerId}:${componentId}` → GradeEntry object.
			 *
			 * @type {Record<string, object>}
			 */
			entryMap: {},
			loading: false,
			saving: false,
			error: null,
			saveError: null,
			publishedMessage: null,
		}
	},

	computed: {
		/**
		 * All current entry values (for histogram computation).
		 *
		 * @return {number[]}
		 */
		allValues() {
			return Object.values(this.entryMap)
				.map((e) => e.value)
				.filter((v) => v !== null && v !== undefined)
				.map(Number)
				.filter((v) => !isNaN(v))
		},

		/**
		 * Number of concept entries (available to publish).
		 *
		 * @return {number}
		 */
		conceptCount() {
			return Object.values(this.entryMap)
				.filter((e) => e.lifecycle === 'concept')
				.length
		},

		/**
		 * Histogram buckets: BUCKET_COUNT evenly spaced ranges over min–max.
		 *
		 * @return {Array<{label: string, count: number}>}
		 */
		histogramBuckets() {
			if (this.allValues.length === 0) {
				return []
			}

			const min = Math.min(...this.allValues)
			const max = Math.max(...this.allValues)

			if (min === max) {
				return [{ label: String(min), count: this.allValues.length }]
			}

			const step = (max - min) / BUCKET_COUNT
			const buckets = []
			for (let i = 0; i < BUCKET_COUNT; i++) {
				const lo = min + i * step
				const hi = lo + step
				const count = this.allValues.filter((v) => v >= lo && (i === BUCKET_COUNT - 1 ? v <= hi : v < hi)).length
				buckets.push({ label: `${lo.toFixed(1)}–${hi.toFixed(1)}`, count })
			}

			return buckets.filter((b) => b.count > 0)
		},
	},

	watch: {
		cohortId: {
			immediate: true,
			handler() {
				this.loadAll()
			},
		},
		planId: {
			immediate: false,
			handler() {
				this.loadAll()
			},
		},
	},

	methods: {
		/**
		 * Load cohort, plan, learners, and existing GradeEntries.
		 *
		 * @return {Promise<void>}
		 */
		async loadAll() {
			this.loading = true
			this.error = null

			try {
				await Promise.all([
					this.loadCohort(),
					this.loadPlan(),
				])
				this.components = this.plan.components ?? []
				await this.loadLearners()
				await this.loadEntries()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load gradebook. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GradebookView] loadAll error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Cohort from OR.
		 *
		 * @return {Promise<void>}
		 */
		async loadCohort() {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Cohort/${this.cohortId}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				throw new Error(`Cohort fetch failed: ${resp.status}`)
			}

			const json = await resp.json()
			this.cohort = json.object ?? json ?? {}
		},

		/**
		 * Fetch the CurriculumPlan from OR.
		 *
		 * @return {Promise<void>}
		 */
		async loadPlan() {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/CurriculumPlan/${this.planId}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				throw new Error(`CurriculumPlan fetch failed: ${resp.status}`)
			}

			const json = await resp.json()
			this.plan = json.object ?? json ?? {}
		},

		/**
		 * Build the learner list from the Cohort's learnerIds.
		 *
		 * @return {Promise<void>}
		 */
		async loadLearners() {
			const ids = this.cohort.learnerIds ?? []
			this.learners = ids.map((id) => ({ id, displayName: id }))
		},

		/**
		 * Fetch all GradeEntries for this cohort + plan and build the entryMap.
		 *
		 * @return {Promise<void>}
		 */
		async loadEntries() {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/GradeEntry?cohortId=${encodeURIComponent(this.cohortId)}&curriculumPlanId=${encodeURIComponent(this.planId)}&limit=500`,
			)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				return
			}

			const json = await resp.json()
			const entries = json.results ?? json.objects ?? []
			const map = {}
			for (const entry of entries) {
				const key = `${entry.learnerId}:${entry.componentId}`
				// Prefer published over concept if multiple entries exist per cell.
				if (!map[key] || entry.lifecycle === 'published') {
					map[key] = entry
				}
			}

			this.entryMap = map
		},

		/**
		 * Get the display key for a learner + component cell.
		 *
		 * @param {string} learnerId    Learner ID.
		 * @param {string} componentId  Component ID.
		 * @return {string}
		 */
		cellKey(learnerId, componentId) {
			return `${learnerId}:${componentId}`
		},

		/**
		 * Get the current value for a cell.
		 *
		 * @param {string} learnerId   Learner ID.
		 * @param {string} componentId Component ID.
		 * @return {number|string}
		 */
		getCellValue(learnerId, componentId) {
			return this.entryMap[this.cellKey(learnerId, componentId)]?.value ?? ''
		},

		/**
		 * Get the lifecycle state for a cell.
		 *
		 * @param {string} learnerId   Learner ID.
		 * @param {string} componentId Component ID.
		 * @return {string}
		 */
		getCellLifecycle(learnerId, componentId) {
			return this.entryMap[this.cellKey(learnerId, componentId)]?.lifecycle ?? ''
		},

		/**
		 * Get CSS class(es) for a cell based on its lifecycle state.
		 *
		 * @param {string} learnerId   Learner ID.
		 * @param {string} componentId Component ID.
		 * @return {object}
		 */
		getCellClass(learnerId, componentId) {
			const lc = this.getCellLifecycle(learnerId, componentId)
			return {
				'gradebook-view__value-input--concept': lc === 'concept',
				'gradebook-view__value-input--published': lc === 'published',
				'gradebook-view__value-input--revised': lc === 'revised',
			}
		},

		/**
		 * Return a short status icon for a lifecycle state.
		 *
		 * @param {string} lifecycle Lifecycle state.
		 * @return {string}
		 */
		lifecycleIcon(lifecycle) {
			if (lifecycle === 'published') {
				return '✓'
			}

			if (lifecycle === 'revised') {
				return '↺'
			}

			return '●'
		},

		/**
		 * Bar width percentage for histogram display.
		 *
		 * @param {number} count Bucket count.
		 * @return {number}
		 */
		barWidth(count) {
			const max = Math.max(...this.histogramBuckets.map((b) => b.count), 1)
			return Math.round((count / max) * 100)
		},

		/**
		 * Handle a cell value change — create or update the concept GradeEntry.
		 *
		 * @param {string} learnerId  Learner ID.
		 * @param {object} component  Component object.
		 * @param {string} rawValue   New value from the input.
		 * @return {Promise<void>}
		 */
		async onCellChange(learnerId, component, rawValue) {
			const value = parseFloat(rawValue)
			if (isNaN(value)) {
				return
			}

			this.saving = true
			this.saveError = null

			const key = this.cellKey(learnerId, component.componentId)
			const existing = this.entryMap[key]

			try {
				let savedEntry
				if (existing && existing.id) {
					// Update existing concept entry. Published entries should not be edited in place.
					const url = generateUrl(`/apps/openregister/api/objects/scholiq/GradeEntry/${existing.id}`)
					const resp = await fetch(url, {
						method: 'PUT',
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({ value }),
					})
					if (!resp.ok) {
						throw new Error(`GradeEntry update failed: ${resp.status}`)
					}

					const json = await resp.json()
					savedEntry = json.object ?? json
				} else {
					// Create new concept GradeEntry.
					const url = generateUrl('/apps/openregister/api/objects/scholiq/GradeEntry')
					const resp = await fetch(url, {
						method: 'POST',
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							learnerId,
							curriculumPlanId: this.planId,
							componentId: component.componentId,
							cohortId: this.cohortId,
							sourceKind: 'manual',
							value,
							gradeScaleId: this.plan.gradeScaleId ?? '',
							period: String(component.period ?? ''),
							grader: '',
							gradedAt: new Date().toISOString(),
							lifecycle: 'concept',
							tenant_id: this.cohort.tenant_id ?? '',
						}),
					})
					if (!resp.ok) {
						throw new Error(`GradeEntry create failed: ${resp.status}`)
					}

					const json = await resp.json()
					savedEntry = json.object ?? json
				}

				if (savedEntry) {
					this.entryMap = { ...this.entryMap, [key]: savedEntry }
				}
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save grade. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GradebookView] onCellChange error', err)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Publish all concept GradeEntries for this cohort + plan.
		 *
		 * Dispatches the `publish` lifecycle transition on each concept entry.
		 * After all publish calls complete, reloads the entry map.
		 *
		 * @return {Promise<void>}
		 */
		async publishAll() {
			const conceptEntries = Object.values(this.entryMap).filter((e) => e.lifecycle === 'concept')
			if (conceptEntries.length === 0) {
				return
			}

			this.saving = true
			this.saveError = null
			this.publishedMessage = null

			try {
				const results = await Promise.allSettled(
					conceptEntries.map((entry) => this.publishEntry(entry)),
				)

				const failed = results.filter((r) => r.status === 'rejected').length
				if (failed > 0) {
					this.saveError = this.t('scholiq', '{count} grade(s) could not be published. The others were published successfully.', { count: failed })
				} else {
					this.publishedMessage = this.t('scholiq', '{count} grade(s) published successfully.', { count: conceptEntries.length })
				}

				await this.loadEntries()
			} catch (err) {
				this.saveError = this.t('scholiq', 'Publish failed. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GradebookView] publishAll error', err)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Dispatch the `publish` transition on a single GradeEntry.
		 *
		 * @param {object} entry GradeEntry object with an id.
		 * @return {Promise<void>}
		 */
		async publishEntry(entry) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/GradeEntry/${entry.id}/transition/publish`,
			)
			const resp = await fetch(url, {
				method: 'POST',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({}),
			})
			if (!resp.ok) {
				throw new Error(`Publish transition failed for ${entry.id}: ${resp.status}`)
			}
		},
	},
}
</script>

<style scoped>
.gradebook-view {
	max-width: 1200px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.gradebook-view__loading,
.gradebook-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.gradebook-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.gradebook-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.gradebook-view__grid-wrap {
	overflow-x: auto;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.gradebook-view__grid {
	border-collapse: collapse;
	width: 100%;
	font-size: 0.9em;
}

.gradebook-view__grid th,
.gradebook-view__grid td {
	border: 1px solid var(--color-border);
	padding: 4px 6px;
}

.gradebook-view__learner-col {
	min-width: 140px;
	background: var(--color-background-hover);
}

.gradebook-view__component-col {
	min-width: 90px;
	text-align: center;
	background: var(--color-background-hover);
}

.gradebook-view__component-label {
	display: block;
	font-weight: bold;
	font-size: 0.85em;
}

.gradebook-view__component-weight {
	display: block;
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}

.gradebook-view__learner-cell {
	font-weight: 500;
}

.gradebook-view__entry-cell {
	padding: 2px;
}

.gradebook-view__cell-wrap {
	display: flex;
	align-items: center;
	gap: 2px;
}

.gradebook-view__value-input {
	width: 70px;
	padding: 2px 4px;
	border: 1px solid var(--color-border);
	border-radius: 3px;
	text-align: right;
	font-size: 0.9em;
}

.gradebook-view__value-input--concept {
	border-color: var(--color-warning);
}

.gradebook-view__value-input--published {
	border-color: var(--color-success);
	background: var(--color-success-hover);
}

.gradebook-view__value-input--revised {
	border-color: var(--color-primary);
}

.gradebook-view__cell-status {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.gradebook-view__distribution {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.gradebook-view__histogram {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 500px;
}

.gradebook-view__histogram-bar-wrap {
	display: flex;
	align-items: center;
	gap: 6px;
}

.gradebook-view__histogram-label {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	min-width: 80px;
	text-align: right;
}

.gradebook-view__histogram-bar {
	height: 14px;
	background: var(--color-primary-element-light);
	border-radius: 2px;
	min-width: 4px;
	transition: width 0.2s;
}

.gradebook-view__histogram-count {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.gradebook-view__no-data {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.gradebook-view__actions {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.gradebook-view__concept-count {
	color: var(--color-warning);
	font-size: 0.9em;
}

.gradebook-view__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
}

.gradebook-view__published-message {
	color: var(--color-success);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
}
</style>
