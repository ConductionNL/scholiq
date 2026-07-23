<!--
  AdmissionsReviewBoard.vue
  Custom page component for the AdmissionsReviewBoard manifest page (type: custom).

  Coordinator's queue of Applications in `intake-completed`, cross-referencing
  each Application against its AdmissionsRound's deadline, kind, and
  remaining capacity -- a join a generic manifest list widget cannot express.
  Lets the coordinator navigate from a listed application to record its
  decision (place/waitlist/reject) on the standard ApplicationDetail page,
  where AdmissionsDecisionGuard enforces the toelatingsrecht/schooladvies/
  capacity rules server-side.

  This is the ONE genuine custom-view exception for admissions (task 5.2) --
  every other Application/AdmissionsRound screen is a declarative manifest
  index/detail page.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring BsaRiskDashboard.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-frontend-is-declarative-with-one-named-admissions-review-exception
-->

<template>
	<div class="admissions-review-board">
		<header class="admissions-review-board__header">
			<h2 class="admissions-review-board__title">
				{{ t('scholiq', 'Admissions review board') }}
			</h2>
			<p class="admissions-review-board__subtitle">
				{{ t('scholiq', 'Applications that have completed intake and are ready for a placement decision, with their round\'s deadline, kind, and remaining capacity.') }}
			</p>
		</header>

		<!-- Loading -->
		<div v-if="loading" class="admissions-review-board__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading pending applications...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="admissions-review-board__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Empty -->
		<div v-else-if="pendingApplications.length === 0" class="admissions-review-board__empty" role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'No applications are currently awaiting a decision.') }}</p>
		</div>

		<!-- Application list -->
		<ul v-else class="admissions-review-board__list">
			<li
				v-for="application in pendingApplications"
				:key="application.id || application.uuid"
				class="admissions-review-board__item">
				<div class="admissions-review-board__info">
					<span class="admissions-review-board__applicant">
						{{ applicantName(application) }}
					</span>
					<span class="admissions-review-board__round-kind">
						{{ roundKindLabel(application) }}
					</span>
					<span class="admissions-review-board__deadline">
						{{ t('scholiq', 'Deadline: {deadline}', { deadline: formatDate(roundFor(application) && roundFor(application).applicationDeadline) }) }}
					</span>
					<span class="admissions-review-board__capacity">
						{{ capacityLabel(application) }}
					</span>
					<span class="admissions-review-board__submitted">
						{{ t('scholiq', 'Submitted {when}', { when: formatDate(application.submittedAt) }) }}
					</span>
				</div>

				<div class="admissions-review-board__actions">
					<a
						class="button-vue button-vue--vue-primary"
						:href="decisionHref(application)">
						{{ t('scholiq', 'Record decision') }}
					</a>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AdmissionsReviewBoard',

	data() {
		return {
			/** @type {object[]} */
			applications: [],
			/** @type {object[]} */
			rounds: [],
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Applications still in `intake-completed` -- the ones a coordinator
		 * needs to record a decision for. Earlier/later lifecycle states are
		 * not yet ready, or already decided.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
		 */
		pendingApplications() {
			return this.applications.filter((a) => a.lifecycle === 'intake-completed')
		},
	},

	created() {
		this.loadData()
	},

	methods: {
		/**
		 * Fetch open Applications and their AdmissionsRounds.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
		 */
		async loadData() {
			this.loading = true
			this.error = null

			try {
				const [applicationsResp, roundsResp] = await Promise.all([
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/Application?limit=200'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/AdmissionsRound?limit=200'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
				])

				if (!applicationsResp.ok) throw new Error(`Application fetch failed: ${applicationsResp.status}`)
				if (!roundsResp.ok) throw new Error(`AdmissionsRound fetch failed: ${roundsResp.status}`)

				const applicationsJson = await applicationsResp.json()
				const roundsJson = await roundsResp.json()

				this.applications = applicationsJson.results ?? applicationsJson.objects ?? applicationsJson ?? []
				this.rounds = roundsJson.results ?? roundsJson.objects ?? roundsJson ?? []
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load pending applications. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[AdmissionsReviewBoard] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the AdmissionsRound an Application belongs to.
		 *
		 * @param {object} application Application object
		 * @return {object|null}
		 */
		roundFor(application) {
			const roundId = application.admissionsRoundId
			return this.rounds.find((r) => (r.id || r.uuid) === roundId) || null
		},

		/**
		 * Display name for the applicant.
		 *
		 * @param {object} application Application object
		 * @return {string}
		 */
		applicantName(application) {
			const given = application.applicantGivenName || ''
			const family = application.applicantFamilyName || ''
			return `${given} ${family}`.trim() || application.id || application.uuid
		},

		/**
		 * Human-readable round kind label.
		 *
		 * @param {object} application Application object
		 * @return {string}
		 */
		roundKindLabel(application) {
			const round = this.roundFor(application)
			if (!round) return this.t('scholiq', 'Unknown round')

			const labels = {
				'mbo-toelatingsrecht': this.t('scholiq', 'MBO toelatingsrecht'),
				'vo-schooladvies-doorstroomtoets': this.t('scholiq', 'VO schooladvies/doorstroomtoets'),
				generic: this.t('scholiq', 'Generic'),
			}
			return labels[round.kind] || round.kind || ''
		},

		/**
		 * Remaining-capacity label, computed from the round's capacity and the
		 * count of already placed/converted applications for that round.
		 *
		 * @param {object} application Application object
		 * @return {string}
		 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board
		 */
		capacityLabel(application) {
			const round = this.roundFor(application)
			if (!round || round.capacity === null || round.capacity === undefined) {
				return this.t('scholiq', 'Capacity: uncapped')
			}

			const placedCount = this.applications.filter((a) => {
				return a.admissionsRoundId === (round.id || round.uuid)
					&& (a.lifecycle === 'placed' || a.lifecycle === 'converted')
			}).length

			const remaining = Math.max(0, round.capacity - placedCount)
			return this.t('scholiq', '{remaining} of {capacity} seat(s) remaining', { remaining, capacity: round.capacity })
		},

		/**
		 * Build the href to record a decision for this application on its detail page.
		 *
		 * @param {object} application Application object
		 * @return {string}
		 */
		decisionHref(application) {
			const id = application.id || application.uuid
			return `#/admissions/applications/${id}`
		},

		/**
		 * Format a date/datetime string for display.
		 *
		 * @param {string|null} dt ISO date(-time) string
		 * @return {string}
		 */
		formatDate(dt) {
			if (!dt) return this.t('scholiq', 'not set')
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
.admissions-review-board {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.admissions-review-board__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.admissions-review-board__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.admissions-review-board__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.admissions-review-board__loading,
.admissions-review-board__error,
.admissions-review-board__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.admissions-review-board__list {
	list-style: none;
	padding: 0;
}

.admissions-review-board__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius, 4px);
}

.admissions-review-board__info {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	align-items: baseline;
	font-size: 0.9em;
}

.admissions-review-board__applicant {
	font-weight: bold;
}

.admissions-review-board__round-kind,
.admissions-review-board__deadline,
.admissions-review-board__submitted {
	color: var(--color-text-maxcontrast);
}

.admissions-review-board__capacity {
	color: var(--color-warning);
	font-weight: 500;
}

.admissions-review-board__actions {
	flex-shrink: 0;
}
</style>
