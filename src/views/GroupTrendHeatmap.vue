<!--
  GroupTrendHeatmap.vue
  Custom page component for the GroupTrendHeatmap manifest page (type: custom).

  Cohort x period grade-trend heat map, sourced entirely from EXISTING
  GradeEntry data (cohortId, gradedAt, period, value) via OpenRegister's
  existing list API — no new schema for the trend itself. Closes the
  `dashboard` capability spec's Acceptance Criteria claim of a "skill-area
  heat map" that had no implementation anywhere in src/views/** at HEAD
  (learning-progress-and-analytics).

  This is the ONE genuine custom-view exception this change adds, mirroring
  BsaRiskDashboard.vue's "the only genuine custom UI" precedent. Scoped to
  teacher/admin roles. Rendered as its own manifest page (NOT a nested
  CnDashboardPage inside another dashboard route — hydra-gate-dashboard-
  antipattern).

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring BsaRiskDashboard.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-cohortgroup-test-score-trend-renders-as-a-heat-map-over-existing-data
-->

<template>
	<div class="group-trend-heatmap">
		<header class="group-trend-heatmap__header">
			<h2 class="group-trend-heatmap__title">
				{{ t('scholiq', 'Group trend heat map') }}
			</h2>
			<p class="group-trend-heatmap__subtitle">
				{{ t('scholiq', 'Average published grade per cohort and grading period, sourced from existing grade data.') }}
			</p>
		</header>

		<!-- Loading -->
		<div v-if="loading" class="group-trend-heatmap__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading grade trends...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="group-trend-heatmap__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Empty -->
		<div v-else-if="cohortRows.length === 0" class="group-trend-heatmap__empty" role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'No published grade entries found yet — the heat map will fill in as cohorts are graded.') }}</p>
		</div>

		<!-- Heat map grid -->
		<div v-else class="group-trend-heatmap__table-wrap">
			<table class="group-trend-heatmap__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('scholiq', 'Cohort') }}
						</th>
						<th v-for="period in periods" :key="period" scope="col">
							{{ t('scholiq', 'Period {period}', { period }) }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in cohortRows" :key="row.cohortId">
						<th scope="row" class="group-trend-heatmap__cohort-name">
							{{ row.cohortName }}
						</th>
						<td
							v-for="period in periods"
							:key="period"
							class="group-trend-heatmap__cell"
							:class="cellClass(row.averagesByPeriod[period])">
							<span v-if="row.averagesByPeriod[period] !== undefined">
								{{ formatAverage(row.averagesByPeriod[period]) }}
							</span>
							<span v-else class="group-trend-heatmap__cell-empty">—</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'GroupTrendHeatmap',

	data() {
		return {
			/** @type {object[]} */
			cohorts: [],
			/** @type {object[]} */
			gradeEntries: [],
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Every distinct period present across the fetched GradeEntries,
		 * sorted.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map
		 */
		periods() {
			const set = new Set(this.gradeEntries.map((e) => e.period).filter(Boolean))
			return Array.from(set).sort()
		},

		/**
		 * Overall min/max of every cell average, used to colour-band cells
		 * relative to this dataset's own spread (grade scales vary per
		 * CurriculumPlan/GradeScale, so an absolute 1-10 banding would be
		 * wrong for e.g. a 0-100 scale).
		 *
		 * @return {{min: number, max: number}}
		 */
		valueRange() {
			const values = []
			for (const row of this.cohortRows) {
				for (const period of this.periods) {
					const avg = row.averagesByPeriod[period]
					if (avg !== undefined) values.push(avg)
				}
			}
			if (values.length === 0) return { min: 0, max: 1 }
			return { min: Math.min(...values), max: Math.max(...values) }
		},

		/**
		 * One row per Cohort that has at least one published GradeEntry,
		 * each carrying its average value per period.
		 *
		 * @return {Array<{cohortId: string, cohortName: string, averagesByPeriod: object}>}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map
		 */
		cohortRows() {
			const cohortNameById = {}
			for (const cohort of this.cohorts) {
				cohortNameById[cohort.id ?? cohort.uuid] = cohort.name
			}

			const byCohort = {}
			for (const entry of this.gradeEntries) {
				const cohortId = entry.cohortId
				const period = entry.period
				const value = entry.value
				if (!cohortId || !period || value === null || value === undefined) continue

				if (!byCohort[cohortId]) {
					byCohort[cohortId] = {}
				}
				if (!byCohort[cohortId][period]) {
					byCohort[cohortId][period] = { sum: 0, count: 0 }
				}
				byCohort[cohortId][period].sum += Number(value)
				byCohort[cohortId][period].count += 1
			}

			return Object.keys(byCohort).map((cohortId) => {
				const averagesByPeriod = {}
				for (const period of Object.keys(byCohort[cohortId])) {
					const { sum, count } = byCohort[cohortId][period]
					averagesByPeriod[period] = count > 0 ? sum / count : undefined
				}
				return {
					cohortId,
					cohortName: cohortNameById[cohortId] ?? cohortId,
					averagesByPeriod,
				}
			}).sort((a, b) => a.cohortName.localeCompare(b.cohortName))
		},
	},

	created() {
		this.loadData()
	},

	methods: {
		/**
		 * Fetch every Cohort and every published GradeEntry that carries a
		 * cohortId, via OpenRegister's existing object API — no new schema.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map
		 */
		async loadData() {
			this.loading = true
			this.error = null

			try {
				const [cohortsResp, entriesResp] = await Promise.all([
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/Cohort?limit=200'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/GradeEntry?limit=500&lifecycle=published'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
				])

				if (!cohortsResp.ok) throw new Error(`Cohort fetch failed: ${cohortsResp.status}`)
				if (!entriesResp.ok) throw new Error(`GradeEntry fetch failed: ${entriesResp.status}`)

				const cohortsJson = await cohortsResp.json()
				const entriesJson = await entriesResp.json()

				this.cohorts = cohortsJson.results ?? cohortsJson.objects ?? cohortsJson ?? []
				this.gradeEntries = (entriesJson.results ?? entriesJson.objects ?? entriesJson ?? [])
					.filter((e) => e.lifecycle === 'published' && !!e.cohortId)
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load grade trend data. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GroupTrendHeatmap] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a cell average to one decimal place.
		 *
		 * @param {number} value Average value.
		 * @return {string}
		 */
		formatAverage(value) {
			return Number(value).toFixed(1)
		},

		/**
		 * Colour-band a cell relative to the dataset's own min/max spread.
		 *
		 * @param {number|undefined} value Cell average, or undefined for an empty cell.
		 * @return {string}
		 */
		cellClass(value) {
			if (value === undefined) return ''
			const { min, max } = this.valueRange
			const span = max - min
			const ratio = span > 0 ? (value - min) / span : 1
			if (ratio < 0.34) return 'group-trend-heatmap__cell--low'
			if (ratio < 0.67) return 'group-trend-heatmap__cell--mid'
			return 'group-trend-heatmap__cell--high'
		},
	},
}
</script>

<style scoped>
.group-trend-heatmap {
	max-width: 1100px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.group-trend-heatmap__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.group-trend-heatmap__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.group-trend-heatmap__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.group-trend-heatmap__loading,
.group-trend-heatmap__error,
.group-trend-heatmap__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.group-trend-heatmap__table-wrap {
	overflow-x: auto;
}

.group-trend-heatmap__table {
	border-collapse: collapse;
	width: 100%;
}

.group-trend-heatmap__table th,
.group-trend-heatmap__table td {
	border: 1px solid var(--color-border);
	padding: calc(var(--default-grid-baseline, 8px) / 2) var(--default-grid-baseline, 8px);
	text-align: center;
}

.group-trend-heatmap__cohort-name {
	text-align: left;
	white-space: nowrap;
}

.group-trend-heatmap__cell {
	font-weight: 500;
}

.group-trend-heatmap__cell--low {
	background-color: color-mix(in srgb, var(--color-error) 25%, transparent);
}

.group-trend-heatmap__cell--mid {
	background-color: color-mix(in srgb, var(--color-warning) 25%, transparent);
}

.group-trend-heatmap__cell--high {
	background-color: color-mix(in srgb, var(--color-success) 25%, transparent);
}

.group-trend-heatmap__cell-empty {
	color: var(--color-text-maxcontrast);
}
</style>
