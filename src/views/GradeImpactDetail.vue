<!--
  GradeImpactDetail.vue
  Custom page component for the GradeImpactDetail manifest page (type: custom).

  Read-only impact view for one published GradeEntry. Shows:
  1. The entry's value, effectiveWeight, pointsContributed.
  2. The current period average (average of published entries in the same period
     for the same learner + curriculumPlan).
  3. The delta to the learner's FinalGrade (fetched from the FinalGrade schema).

  Route param: :id (GradeEntry UUID).
  Uses Options API + direct fetch (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
-->

<template>
	<div class="grade-impact">
		<!-- Loading -->
		<div v-if="loading" class="grade-impact__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading grade impact...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="grade-impact__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Content -->
		<template v-else-if="entry">
			<header class="grade-impact__header">
				<h2>{{ t('scholiq', 'Grade impact') }}</h2>
				<p class="grade-impact__meta">
					{{ t('scholiq', 'Component: {id}', { id: entry.componentId || '' }) }}
					<span v-if="entry.period"> — {{ t('scholiq', 'Period: {period}', { period: entry.period }) }}</span>
				</p>
				<span
					class="grade-impact__lifecycle-badge"
					:class="`grade-impact__lifecycle-badge--${entry.lifecycle}`">
					{{ entry.lifecycle }}
				</span>
			</header>

			<!-- Grade value block -->
			<section class="grade-impact__section">
				<h3>{{ t('scholiq', 'This grade') }}</h3>
				<dl class="grade-impact__dl">
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Value') }}</dt>
						<dd class="grade-impact__value">
							{{ formatValue(entry.value) }}
						</dd>
					</div>
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Effective weight') }}</dt>
						<dd>{{ entry.weight !== null && entry.weight !== undefined ? entry.weight : planComponentWeight }}</dd>
					</div>
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Points contributed') }}</dt>
						<dd>{{ formatValue(pointsContributed) }}</dd>
					</div>
					<div v-if="entry.grader" class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Grader') }}</dt>
						<dd>{{ entry.grader }}</dd>
					</div>
					<div v-if="entry.gradedAt" class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Graded at') }}</dt>
						<dd>{{ formatDate(entry.gradedAt) }}</dd>
					</div>
					<div v-if="entry.comment" class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Comment') }}</dt>
						<dd>{{ entry.comment }}</dd>
					</div>
				</dl>
			</section>

			<!-- Period average block -->
			<section v-if="periodEntries.length > 0" class="grade-impact__section">
				<h3>{{ t('scholiq', 'Period {period} average', { period: entry.period || '' }) }}</h3>
				<dl class="grade-impact__dl">
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Published grades in period') }}</dt>
						<dd>{{ periodEntries.length }}</dd>
					</div>
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Period average') }}</dt>
						<dd class="grade-impact__value">
							{{ formatValue(periodAverage) }}
						</dd>
					</div>
				</dl>
			</section>

			<!-- Final grade impact block -->
			<section v-if="finalGrade" class="grade-impact__section">
				<h3>{{ t('scholiq', 'Final grade') }}</h3>
				<dl class="grade-impact__dl">
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Current final grade') }}</dt>
						<dd class="grade-impact__value">
							{{ formatValue(finalGrade.value) }}
						</dd>
					</div>
					<div class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Pass status') }}</dt>
						<dd>
							<span
								v-if="finalGrade.passed !== null"
								:class="finalGrade.passed ? 'grade-impact__pass' : 'grade-impact__fail'">
								{{ finalGrade.passed ? t('scholiq', 'Passed') : t('scholiq', 'Not passed') }}
							</span>
							<span v-else class="grade-impact__pending">{{ t('scholiq', 'Pending') }}</span>
						</dd>
					</div>
					<div v-if="finalGrade.lastRecomputedAt" class="grade-impact__dl-row">
						<dt>{{ t('scholiq', 'Last recomputed') }}</dt>
						<dd>{{ formatDate(finalGrade.lastRecomputedAt) }}</dd>
					</div>
				</dl>
			</section>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'GradeImpactDetail',

	props: {
		/**
		 * GradeEntry UUID injected by vue-router from :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			entry: null,
			/** @type {object|null} */
			plan: null,
			/** @type {Array<object>} */
			periodEntries: [],
			/** @type {object|null} */
			finalGrade: null,
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * The weight of the plan component matching this entry's componentId.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		planComponentWeight() {
			if (!this.plan || !this.entry) {
				return 1
			}

			const component = (this.plan.components ?? []).find(
				(c) => c.componentId === this.entry.componentId,
			)
			return component?.weight ?? 1
		},

		/**
		 * Effective weight: per-entry override or plan component default.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		effectiveWeight() {
			if (this.entry?.weight !== null && this.entry?.weight !== undefined) {
				return Number(this.entry.weight)
			}

			return this.planComponentWeight
		},

		/**
		 * Points contributed by this entry to the weighted sum.
		 *
		 * @return {number|null}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		pointsContributed() {
			if (!this.entry || this.entry.value === null || this.entry.value === undefined) {
				return null
			}

			return Number(this.entry.value) * this.effectiveWeight
		},

		/**
		 * Weighted average of published entries in the same period.
		 *
		 * @return {number|null}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		periodAverage() {
			if (this.periodEntries.length === 0) {
				return null
			}

			let weightedSum = 0
			let totalWeight = 0
			for (const e of this.periodEntries) {
				const w = e.weight !== null && e.weight !== undefined ? Number(e.weight) : 1
				weightedSum += Number(e.value) * w
				totalWeight += w
			}

			return totalWeight > 0 ? Math.round((weightedSum / totalWeight) * 100) / 100 : null
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the GradeEntry id prop changing by loading all data.
			 *
			 * @param {string} newId New GradeEntry UUID
			 * @return {void}
			 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
			 */
			handler(newId) {
				if (newId) {
					this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the GradeEntry, then related plan, period entries, and FinalGrade.
		 *
		 * @param {string} entryId GradeEntry UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		async loadData(entryId) {
			this.loading = true
			this.error = null

			try {
				await this.loadEntry(entryId)
				if (this.entry) {
					await Promise.all([
						this.loadPlan(this.entry.curriculumPlanId),
						this.loadPeriodEntries(),
						this.loadFinalGrade(),
					])
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load grade impact. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GradeImpactDetail] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the GradeEntry from OR.
		 *
		 * @param {string} entryId GradeEntry UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		async loadEntry(entryId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/GradeEntry/${entryId}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				throw new Error(`GradeEntry fetch failed: ${resp.status}`)
			}

			const json = await resp.json()
			this.entry = json.object ?? json ?? null
		},

		/**
		 * Fetch the CurriculumPlan from OR.
		 *
		 * @param {string} planId CurriculumPlan UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		async loadPlan(planId) {
			if (!planId) {
				return
			}

			const url = generateUrl(`/apps/openregister/api/objects/scholiq/CurriculumPlan/${planId}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				return
			}

			const json = await resp.json()
			this.plan = json.object ?? json ?? null
		},

		/**
		 * Fetch other published GradeEntries in the same period for this learner + plan.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		async loadPeriodEntries() {
			if (!this.entry) {
				return
			}

			const { learnerId, curriculumPlanId, period } = this.entry
			if (!learnerId || !curriculumPlanId || !period) {
				return
			}

			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/GradeEntry?learnerId=${encodeURIComponent(learnerId)}&curriculumPlanId=${encodeURIComponent(curriculumPlanId)}&period=${encodeURIComponent(period)}&lifecycle=published&limit=100`,
			)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				return
			}

			const json = await resp.json()
			this.periodEntries = json.results ?? json.objects ?? []
		},

		/**
		 * Fetch the FinalGrade for this learner + curriculumPlan.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		async loadFinalGrade() {
			if (!this.entry) {
				return
			}

			const { learnerId, curriculumPlanId } = this.entry
			if (!learnerId || !curriculumPlanId) {
				return
			}

			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/FinalGrade?learnerId=${encodeURIComponent(learnerId)}&curriculumPlanId=${encodeURIComponent(curriculumPlanId)}&limit=1`,
			)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) {
				return
			}

			const json = await resp.json()
			const results = json.results ?? json.objects ?? []
			this.finalGrade = results[0] ?? null
		},

		/**
		 * Format a numeric grade value to 2dp.
		 *
		 * @param {number|null} value Grade value.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		formatValue(value) {
			if (value === null || value === undefined) {
				return '—'
			}

			return Number(value).toFixed(2)
		},

		/**
		 * Format an ISO-8601 datetime for display.
		 *
		 * @param {string} isoString ISO-8601 datetime string.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-30
		 */
		formatDate(isoString) {
			if (!isoString) {
				return '—'
			}

			try {
				return new Date(isoString).toLocaleString()
			} catch {
				return isoString
			}
		},
	},
}
</script>

<style scoped>
.grade-impact {
	max-width: 640px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.grade-impact__loading,
.grade-impact__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.grade-impact__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.grade-impact__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.grade-impact__lifecycle-badge {
	display: inline-block;
	margin-top: 4px;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 0.8em;
	font-weight: 500;
	text-transform: capitalize;
	background: var(--color-background-dark);
}

.grade-impact__lifecycle-badge--concept {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.grade-impact__lifecycle-badge--published {
	background: var(--color-success);
	color: var(--color-main-background);
}

.grade-impact__lifecycle-badge--revised {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.grade-impact__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.grade-impact__dl {
	margin: 0;
}

.grade-impact__dl-row {
	display: flex;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border-light);
	gap: calc(var(--default-grid-baseline, 8px) * 2);
}

.grade-impact__dl-row:last-child {
	border-bottom: none;
}

.grade-impact__dl-row dt {
	flex: 0 0 180px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.grade-impact__dl-row dd {
	margin: 0;
	font-size: 0.9em;
}

.grade-impact__value {
	font-weight: bold;
	font-size: 1.1em !important;
}

.grade-impact__pass {
	color: var(--color-success);
	font-weight: 500;
}

.grade-impact__fail {
	color: var(--color-error);
	font-weight: 500;
}

.grade-impact__pending {
	color: var(--color-text-maxcontrast);
}
</style>
