<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LeaderboardView.vue
 Custom page component for the LeaderboardView manifest page (type: custom).

 Renders LeaderboardController's ranked response for a cohort with an active
 Leaderboard, with an inline "hide me from this leaderboard" toggle wired to
 the existing preferences-api endpoints. This is the ONE genuine custom-view
 exception for the engagement capability (mirrors BsaRiskDashboard.vue's
 status as the sole custom-UI exception in study-progress) — every other
 engagement object is a declarative manifest index/detail page. No manifest
 declarative page can render this: the ranking is computed live by a narrow
 authorizing controller because the register has no cross-object "cohort-mate"
 RBAC primitive (design.md "Why the RBAC gap forces a controller").

 Uses Options API + direct fetch/axios calls (no custom Pinia store modules),
 mirroring BsaRiskDashboard.vue / RolloverWizard.vue.

 @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-frontend-surfaces-a-private-points-level-widget-and-one-opt-in-leaderboard-view
-->

<template>
	<div class="leaderboard-view">
		<header class="leaderboard-view__header">
			<h2 class="leaderboard-view__title">
				{{ t('scholiq', 'Leaderboard') }}
			</h2>
			<p class="leaderboard-view__subtitle">
				{{ t('scholiq', 'Ranked points for cohorts that have opted in to a leaderboard. Your own points and level are always visible to you regardless of this setting.') }}
			</p>
		</header>

		<!-- Loading the list of active leaderboards -->
		<div v-if="loadingLeaderboards" class="leaderboard-view__loading" aria-live="polite">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- No cohort currently has an active, opted-in leaderboard -->
		<NcEmptyContent
			v-else-if="leaderboardOptions.length === 0"
			:name="t('scholiq', 'No active leaderboards')"
			:description="t('scholiq', 'A coordinator has not opted any cohort into a leaderboard yet.')" />

		<template v-else>
			<div class="leaderboard-view__field">
				<label for="leaderboard-cohort-select">{{ t('scholiq', 'Leaderboard') }}</label>
				<NcSelect id="leaderboard-cohort-select"
					v-model="selectedCohortId"
					:options="leaderboardOptions"
					:reduce="(o) => o.cohortId"
					label="name"
					:input-label="t('scholiq', 'Leaderboard')"
					:aria-label-combobox="t('scholiq', 'Leaderboard')"
					@input="loadRankings" />
			</div>

			<NcCheckboxRadioSwitch
				type="switch"
				:checked="optedOut"
				:disabled="optOutSaving"
				class="leaderboard-view__opt-out"
				@update:checked="toggleOptOut">
				{{ t('scholiq', 'Hide me from this leaderboard') }}
			</NcCheckboxRadioSwitch>

			<div v-if="loadingRankings" class="leaderboard-view__loading" aria-live="polite">
				<NcLoadingIcon :size="32" />
			</div>

			<p v-else-if="rankingsError" class="leaderboard-view__error" role="alert">
				{{ rankingsError }}
			</p>

			<NcEmptyContent
				v-else-if="rankings.length === 0"
				:name="t('scholiq', 'No ranked learners')"
				:description="t('scholiq', 'Every member of this cohort has opted out, or nobody has earned points yet.')" />

			<ol v-else class="leaderboard-view__rankings">
				<li
					v-for="entry in rankings"
					:key="entry.learnerId"
					class="leaderboard-view__entry">
					<span class="leaderboard-view__rank">#{{ entry.rank }}</span>
					<span class="leaderboard-view__learner">{{ entry.learnerId }}</span>
					<span v-if="entry.level" class="leaderboard-view__level">{{ entry.level }}</span>
					<span class="leaderboard-view__points">{{ entry.totalPoints }}</span>
				</li>
			</ol>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'

const OPT_OUT_PREFERENCE_KEY = 'leaderboardoptout'

export default {
	name: 'LeaderboardView',

	components: {
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			/** @type {Array<{cohortId: string, name: string}>} */
			leaderboardOptions: [],
			loadingLeaderboards: false,
			selectedCohortId: null,

			/** @type {Array<{learnerId: string, totalPoints: number, level: string|null, rank: number}>} */
			rankings: [],
			loadingRankings: false,
			rankingsError: null,

			optedOut: false,
			optOutSaving: false,
		}
	},

	created() {
		this.loadLeaderboards()
		this.loadOptOutState()
	},

	methods: {
		/**
		 * Fetch every `active` Leaderboard row and build the cohort picker options.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
		 */
		async loadLeaderboards() {
			this.loadingLeaderboards = true
			try {
				const params = new URLSearchParams({ lifecycle: 'active', _limit: '100' })
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/Leaderboard?' + params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				const rows = data.results ?? (Array.isArray(data) ? data : [])
				this.leaderboardOptions = rows
					.filter((row) => !!row.cohortId)
					.map((row) => ({ cohortId: row.cohortId, name: row.name || row.cohortId }))

				if (this.leaderboardOptions.length > 0) {
					this.selectedCohortId = this.leaderboardOptions[0].cohortId
					await this.loadRankings()
				}
			} catch {
				this.leaderboardOptions = []
			} finally {
				this.loadingLeaderboards = false
			}
		},

		/**
		 * Fetch the ranked leaderboard for the selected cohort via LeaderboardController.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
		 */
		async loadRankings() {
			if (!this.selectedCohortId) {
				this.rankings = []
				return
			}

			this.loadingRankings = true
			this.rankingsError = null
			try {
				const url = generateUrl(
					'/apps/scholiq/api/leaderboard/{cohortId}',
					{ cohortId: this.selectedCohortId },
				)
				const response = await axios.get(url)
				this.rankings = response.data?.results ?? []
			} catch (err) {
				this.rankings = []
				this.rankingsError = err?.response?.status === 403
					? this.t('scholiq', 'You are not a member of this cohort.')
					: this.t('scholiq', 'Failed to load the leaderboard. Please try again.')
			} finally {
				this.loadingRankings = false
			}
		},

		/**
		 * Load the caller's own standing leaderboard opt-out preference.
		 *
		 * @return {Promise<void>}
		 */
		async loadOptOutState() {
			try {
				const url = generateUrl(
					'/apps/scholiq/api/preferences/{key}',
					{ key: OPT_OUT_PREFERENCE_KEY },
				)
				const response = await axios.get(url)
				this.optedOut = !!response.data?.value
			} catch {
				this.optedOut = false
			}
		},

		/**
		 * Persist the opt-out toggle via preferences-api and refresh the ranking
		 * so the change is reflected on the next load.
		 *
		 * @param {boolean} value New opt-out state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
		 */
		async toggleOptOut(value) {
			this.optOutSaving = true
			try {
				const url = generateUrl(
					'/apps/scholiq/api/preferences/{key}',
					{ key: OPT_OUT_PREFERENCE_KEY },
				)
				await axios.put(url, { value: value ? 'true' : '' })
				this.optedOut = value
				await this.loadRankings()
			} finally {
				this.optOutSaving = false
			}
		},
	},
}
</script>

<style scoped>
.leaderboard-view {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.leaderboard-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.leaderboard-view__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.leaderboard-view__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.leaderboard-view__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 400px;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.leaderboard-view__opt-out {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.leaderboard-view__loading,
.leaderboard-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.leaderboard-view__error {
	color: var(--color-error);
}

.leaderboard-view__rankings {
	list-style: none;
	padding: 0;
}

.leaderboard-view__entry {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	border-bottom: 1px solid var(--color-border);
}

.leaderboard-view__rank {
	font-weight: bold;
	min-width: 32px;
}

.leaderboard-view__learner {
	flex: 1;
}

.leaderboard-view__level {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.leaderboard-view__points {
	font-weight: 500;
}
</style>
