<!--
  ItemAnalysisView.vue
  Custom page component for the ItemAnalysisView manifest page (type: custom).

  Renders ItemStatistics (p-value, item-total correlation, distractor bars —
  or an "insufficient data" state) for one Item across the Assessments it
  appears in, and AssessmentReliability (Cronbach's alpha) for one Assessment
  when reached via the assessmentId query param. This is a custom view
  because the register has no declarative chart/statistics-panel primitive
  (`grep '"type": "chart"' lib/Settings/scholiq_register.json` — zero hits);
  the ItemRevisionFlag review queue itself stays a plain manifest list+detail
  page (mirrors AttendanceFlag/BsaProgressFlag/EngagementRiskFlag).

  ItemStatistics/AssessmentReliability carry x-property-rbac restricting read
  to admin/teacher/examboard — a learner MUST NOT see an item's difficulty/
  discrimination statistics. That is enforced server-side by OR; this view
  ALSO gates its content client-side. Note: like ExamCaseDossierView, this
  register has no `examboard`/`teacher` membership flag exposed via
  loadState at HEAD — `isAdmin` is used as the best available client-side
  proxy (under-inclusive: a real examboard/teacher member without the admin
  flag sees the denied state here even though the server would allow the
  read; the server-side RBAC block is the actual security boundary either way).

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-and-assessment-statistics-are-read-restricted-to-staff-roles
-->

<template>
	<div class="item-analysis">
		<div v-if="!isStaff" class="item-analysis__denied" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ t('scholiq', 'You do not have permission to view item statistics.') }}</p>
		</div>

		<template v-else>
			<div v-if="loading" class="item-analysis__loading" aria-live="polite">
				<span class="icon-loading" aria-hidden="true" />
				<span>{{ t('scholiq', 'Loading item analysis...') }}</span>
			</div>

			<div v-else-if="error" class="item-analysis__error" role="alert">
				<span class="icon-error" aria-hidden="true" />
				<p>{{ error }}</p>
			</div>

			<template v-else>
				<header class="item-analysis__header">
					<h2 class="item-analysis__heading">
						{{ itemTitle || t('scholiq', 'Item analysis') }}
					</h2>
				</header>

				<section v-if="itemId" class="item-analysis__section">
					<h3 class="item-analysis__sub-heading">
						{{ t('scholiq', 'Statistics per assessment') }}
					</h3>
					<p v-if="itemStatisticsList.length === 0" class="item-analysis__empty">
						{{ t('scholiq', 'No statistics computed yet — an item needs graded attempts before statistics exist.') }}
					</p>
					<div
						v-for="stat in itemStatisticsList"
						:key="stat.assessmentId"
						class="item-analysis__stat-card">
						<h4 class="item-analysis__assessment-id">
							{{ t('scholiq', 'Assessment {id}', { id: stat.assessmentId }) }}
						</h4>

						<p v-if="stat.insufficientData" class="item-analysis__insufficient">
							{{ t('scholiq', 'Not enough attempts yet (n={n} of {min}).', { n: stat.sampleSize, min: minSampleSize }) }}
						</p>

						<dl v-else class="item-analysis__stat-list">
							<dt>{{ t('scholiq', 'Sample size') }}</dt>
							<dd>{{ stat.sampleSize }}</dd>
							<dt>{{ t('scholiq', 'p-value (difficulty)') }}</dt>
							<dd>{{ formatNumber(stat.pValue) }}</dd>
							<dt>{{ t('scholiq', 'Item-total correlation (discrimination)') }}</dt>
							<dd>{{ formatNumber(stat.itemTotalCorrelation) }}</dd>
						</dl>

						<ul v-if="stat.distractorAnalysis && stat.distractorAnalysis.length" class="item-analysis__distractor-list">
							<li v-for="option in stat.distractorAnalysis" :key="option.optionId" class="item-analysis__distractor">
								<span class="item-analysis__distractor-label">{{ option.optionId }}</span>
								<span class="item-analysis__distractor-bar item-analysis__distractor-bar--high" :title="t('scholiq', 'High-scoring group')">
									{{ option.selectedByHighGroup }}
								</span>
								<span class="item-analysis__distractor-bar item-analysis__distractor-bar--low" :title="t('scholiq', 'Low-scoring group')">
									{{ option.selectedByLowGroup }}
								</span>
							</li>
						</ul>
					</div>
				</section>

				<section v-if="assessmentId" class="item-analysis__section">
					<h3 class="item-analysis__sub-heading">
						{{ t('scholiq', 'Assessment reliability') }}
					</h3>
					<p v-if="!reliability" class="item-analysis__empty">
						{{ t('scholiq', 'No reliability figure computed yet.') }}
					</p>
					<p v-else-if="reliability.insufficientData" class="item-analysis__insufficient">
						{{ t('scholiq', 'Not enough graded attempts yet (n={n} of {min}).', { n: reliability.sampleSize, min: reliability.reliabilityMinSampleSize || 30 }) }}
					</p>
					<dl v-else class="item-analysis__stat-list">
						<dt>{{ t('scholiq', "Cronbach's alpha") }}</dt>
						<dd>{{ formatNumber(reliability.cronbachAlpha) }}</dd>
						<dt>{{ t('scholiq', 'Sample size') }}</dt>
						<dd>{{ reliability.sampleSize }}</dd>
						<dt>{{ t('scholiq', 'Item count') }}</dt>
						<dd>{{ reliability.itemCount }}</dd>
					</dl>
				</section>
			</template>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'ItemAnalysisView',

	props: {
		/**
		 * Item UUID from the :itemId route param. Null renders the
		 * assessment-reliability section only (when assessmentId is set).
		 */
		itemId: {
			type: String,
			default: null,
		},
		/**
		 * Assessment UUID from the ?assessmentId query param. Null skips the
		 * reliability section.
		 */
		assessmentId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			error: null,
			itemTitle: '',
			/** @type {object[]} */
			itemStatisticsList: [],
			/** @type {object|null} */
			reliability: null,
			minSampleSize: 20,
		}
	},

	computed: {
		/**
		 * Client-side proxy for "may view staff-only item statistics" — see
		 * file-header note. The server-side x-property-rbac block is the
		 * actual security boundary.
		 * @return {boolean}
		 */
		isStaff() {
			return !!getCurrentUser()?.isAdmin
		},
	},

	watch: {
		itemId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
		assessmentId: {
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the Item (for its title), its ItemStatistics rows, and — when
		 * assessmentId is set — the AssessmentReliability row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
		 */
		async load() {
			if (!this.isStaff) return
			if (!this.itemId && !this.assessmentId) return

			this.loading = true
			this.error = null

			try {
				if (this.itemId) {
					await this.loadItemTitle()
					await this.loadItemStatistics()
				}

				if (this.assessmentId) {
					await this.loadReliability()
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load item analysis. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[ItemAnalysisView] load error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Item's title for the page heading.
		 *
		 * @return {Promise<void>}
		 */
		async loadItemTitle() {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Item/${this.itemId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) return
			const json = await resp.json()
			const item = json.object ?? json ?? {}
			this.itemTitle = item.title ?? ''
		},

		/**
		 * Fetch every ItemStatistics row for this Item (fetch-all-then-filter,
		 * mirroring the established `checkExistingAttempt()` convention — no
		 * field-filter query parameter is assumed to exist server-side).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
		 */
		async loadItemStatistics() {
			const url = generateUrl('/apps/openregister/api/objects/scholiq/ItemStatistics?limit=200')
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`ItemStatistics fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			const all = json.results ?? json.objects ?? json ?? []
			this.itemStatisticsList = all.filter((s) => s.itemId === this.itemId)
		},

		/**
		 * Fetch the AssessmentReliability row for the given assessmentId
		 * (fetch-all-then-filter, same convention as loadItemStatistics()).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-assessment-reliability-cronbachs-alpha-is-computed-with-a-minimum-sample-size
		 */
		async loadReliability() {
			const url = generateUrl('/apps/openregister/api/objects/scholiq/AssessmentReliability?limit=200')
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`AssessmentReliability fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			const all = json.results ?? json.objects ?? json ?? []
			this.reliability = all.find((r) => r.assessmentId === this.assessmentId) ?? null
		},

		/**
		 * Format a nullable statistic to 2 decimal places, or an em-dash when null.
		 *
		 * @param {number|null} value Value to format
		 * @return {string}
		 */
		formatNumber(value) {
			if (value === null || value === undefined) return '—'
			return Number(value).toFixed(2)
		},
	},
}
</script>

<style scoped>
.item-analysis {
	max-width: 800px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.item-analysis__loading,
.item-analysis__error,
.item-analysis__denied {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-analysis__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-analysis__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.item-analysis__sub-heading {
	font-weight: 600;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.item-analysis__stat-card {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.item-analysis__assessment-id {
	font-weight: 600;
	margin-bottom: var(--default-grid-baseline, 8px);
	word-break: break-all;
}

.item-analysis__stat-list {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px calc(var(--default-grid-baseline, 8px) * 2);
}

.item-analysis__stat-list dt {
	color: var(--color-text-maxcontrast);
}

.item-analysis__stat-list dd {
	margin: 0;
	font-weight: 600;
}

.item-analysis__insufficient {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.item-analysis__empty {
	color: var(--color-text-maxcontrast);
}

.item-analysis__distractor-list {
	list-style: none;
	padding: 0;
	margin-top: var(--default-grid-baseline, 8px);
}

.item-analysis__distractor {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: 2px 0;
}

.item-analysis__distractor-label {
	min-width: 60px;
	font-weight: 600;
}

.item-analysis__distractor-bar {
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	font-size: 0.85em;
}

.item-analysis__distractor-bar--high {
	background: var(--color-success);
	color: var(--color-primary-text, #fff);
}

.item-analysis__distractor-bar--low {
	background: var(--color-warning);
	color: var(--color-primary-text, #fff);
}
</style>
