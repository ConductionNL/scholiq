<!--
  BsaRiskDashboard.vue
  Custom page component for the BsaRiskDashboard manifest page (type: custom).

  Coordinator/study-advisor view of learners at risk of a negative bindend
  studieadvies (BSA): lists open BsaProgressFlags with the learner's
  ectsEarned against their BsaTrajectory's interimNormEcts/normEcts, and lets
  the study-advisor navigate to draft a BsaWarning for a listed learner.

  This is the ONE genuine custom-view exception for the study-progress
  capability (story bsa-risico-dashboard, 10071) — every other BSA object is a
  declarative manifest index/detail page.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring ProctoringReviewQueue.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-risk-dashboard
-->

<template>
	<div class="bsa-risk-dashboard">
		<header class="bsa-risk-dashboard__header">
			<h2 class="bsa-risk-dashboard__title">
				{{ t('scholiq', 'BSA risk dashboard') }}
			</h2>
			<p class="bsa-risk-dashboard__subtitle">
				{{ t('scholiq', 'Learners currently flagged at risk of a negative bindend studieadvies (BSA), against their trajectory\'s norm and interim-check window.') }}
			</p>
		</header>

		<!-- Loading -->
		<div v-if="loading" class="bsa-risk-dashboard__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading at-risk learners...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="bsa-risk-dashboard__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Empty -->
		<div v-else-if="openFlags.length === 0" class="bsa-risk-dashboard__empty" role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'No learners currently flagged at risk.') }}</p>
		</div>

		<!-- Flag list -->
		<ul v-else class="bsa-risk-dashboard__flags">
			<li
				v-for="flag in openFlags"
				:key="flag.id || flag.uuid"
				class="bsa-risk-dashboard__flag">
				<div class="bsa-risk-dashboard__flag-info">
					<span class="bsa-risk-dashboard__learner">
						{{ t('scholiq', 'Learner: {id}', { id: flag.learnerId }) }}
					</span>
					<span class="bsa-risk-dashboard__academic-year">
						{{ flag.academicYear }}
					</span>
					<span class="bsa-risk-dashboard__progress">
						{{ t('scholiq', '{earned} EC earned / {required} EC required at check', { earned: formatEcts(flag.ectsEarned), required: formatEcts(flag.ectsRequiredAtCheck) }) }}
					</span>
					<span class="bsa-risk-dashboard__flagged-at">
						{{ t('scholiq', 'Flagged {when}', { when: formatDate(flag.flaggedAt) }) }}
					</span>
				</div>

				<div class="bsa-risk-dashboard__actions">
					<a
						class="button-vue button-vue--vue-primary"
						:href="draftWarningHref(flag)">
						{{ t('scholiq', 'Draft warning') }}
					</a>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BsaRiskDashboard',

	data() {
		return {
			/** @type {object[]} */
			flags: [],
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Flags still in `open` lifecycle state — the ones a study-advisor
		 * needs to act on. `in-handling`/`warned`/`resolved` flags are no
		 * longer "currently at risk and unactioned".
		 *
		 * @return {object[]}
		 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard
		 */
		openFlags() {
			return this.flags.filter((f) => f.lifecycle === 'open')
		},
	},

	created() {
		this.loadFlags()
	},

	methods: {
		/**
		 * Fetch all BsaProgressFlag objects.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard
		 */
		async loadFlags() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/BsaProgressFlag?limit=100')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) throw new Error(`BsaProgressFlag fetch failed: ${resp.status}`)
				const json = await resp.json()
				this.flags = json.results ?? json.objects ?? json ?? []
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load at-risk learners. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[BsaRiskDashboard] loadFlags error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Build the href to draft a new BsaWarning pre-scoped to this flag's
		 * learner/programme/academicYear/flag.
		 *
		 * @param {object} flag BsaProgressFlag object
		 * @return {string}
		 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard
		 */
		draftWarningHref(flag) {
			const params = new URLSearchParams({
				learnerId: flag.learnerId ?? '',
				programmeId: flag.programmeId ?? '',
				academicYear: flag.academicYear ?? '',
				bsaProgressFlagId: flag.id ?? flag.uuid ?? '',
			})
			return `#/study-progress/warnings/new?${params.toString()}`
		},

		/**
		 * Format an EC value for display (1 decimal, trims trailing .0).
		 *
		 * @param {number|null} value EC value
		 * @return {string}
		 */
		formatEcts(value) {
			if (value === null || value === undefined) return '-'
			return Number(value).toFixed(1).replace(/\.0$/, '')
		},

		/**
		 * Format a datetime string for display.
		 *
		 * @param {string} dt ISO datetime string
		 * @return {string}
		 */
		formatDate(dt) {
			if (!dt) return ''
			try {
				return new Intl.DateTimeFormat(navigator.language, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				}).format(new Date(dt))
			} catch {
				return dt
			}
		},
	},
}
</script>

<style scoped>
.bsa-risk-dashboard {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.bsa-risk-dashboard__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.bsa-risk-dashboard__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.bsa-risk-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.bsa-risk-dashboard__loading,
.bsa-risk-dashboard__error,
.bsa-risk-dashboard__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.bsa-risk-dashboard__flags {
	list-style: none;
	padding: 0;
}

.bsa-risk-dashboard__flag {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
}

.bsa-risk-dashboard__flag-info {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	align-items: baseline;
	font-size: 0.9em;
}

.bsa-risk-dashboard__learner {
	font-weight: bold;
}

.bsa-risk-dashboard__academic-year,
.bsa-risk-dashboard__flagged-at {
	color: var(--color-text-maxcontrast);
}

.bsa-risk-dashboard__progress {
	color: var(--color-warning);
	font-weight: 500;
}

.bsa-risk-dashboard__actions {
	flex-shrink: 0;
}
</style>
