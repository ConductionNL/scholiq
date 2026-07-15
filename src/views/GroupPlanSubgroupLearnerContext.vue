<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 GroupPlanSubgroupLearnerContext — custom page component for the
 GroupPlanSubgroupLearnerContext manifest page (type: custom).

 The ONE genuine custom-view exception for the groepsplan capability
 (design.md "Why no stored link from GroupPlanSubgroup to LearningPlan or
 SupportRequest"): resolves, for each member learner of a GroupPlanSubgroup,
 whether they already have an active per-learner LearningPlan (OPP) — an
 array-membership lookup (learnerIds → LearningPlan.learnerId) the
 manifest's equality-only object-list filter DSL cannot express. No field is
 stored on GroupPlanSubgroup for this; the lookup is resolved live, every
 time the view loads, against the authoritative LearningPlan.learnerId.

 The reverse SupportRequest link (originGroupPlanSubgroupId) does NOT need a
 custom view — it is a single scalar equality filter, rendered as a standard
 object-list widget on GroupPlanSubgroupDetail (gps-support-requests).

 Reached from GroupPlanSubgroupDetail's "Learner context" KPI tile
 (gps-kpis), which resolves @objectId into this route's `subgroupId` query
 parameter via CnStatsBlockWidget's entry.route token resolution — the same
 token-resolved deep link mechanism as PupilDossierTimelineView (reached
 from LearnerProfileDetail's "Dossier timeline" tile). When `subgroupId` is
 absent (e.g. direct navigation), an inline id picker is shown instead of a
 blank page.

 Active LearningPlans are fetched broadly (lifecycle=active, bounded limit)
 and matched client-side against the subgroup's learnerIds — the same
 fetch-broad-then-filter-client-side idiom PupilDossierTimelineView already
 uses for its DeliberationRecord join, since OpenRegister's object list
 endpoint has no verified multi-value "learnerId in (...)" filter operator
 (design.md flags this as "to be confirmed against ObjectService::findAll's
 actual filter support" — OpenRegister core is not present in this repo).

 Uses Options API + direct fetch calls (no custom Pinia store modules),
 mirroring PupilDossierTimelineView.vue / BsaRiskDashboard.vue.

 @spec openspec/changes/groepsplan/specs/learning-plan/spec.md#requirement-groupplansubgroup-differentiates-instructieniveau-and-links-to-without-duplicating-learningplan-and-supportrequest
 @spec openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-a-subgroup-member-s-existing-learningplan-is-surfaced-without-a-duplicate-field
-->

<template>
	<div class="gp-subgroup-learner-context">
		<header class="gp-subgroup-learner-context__header">
			<h2 class="gp-subgroup-learner-context__title">
				{{ t('scholiq', 'Subgroup learner context') }}
			</h2>
			<p class="gp-subgroup-learner-context__subtitle">
				{{ t('scholiq', "For each learner in this subgroup, whether they already have an active LearningPlan (OPP) — resolved live, not stored on the subgroup.") }}
			</p>
		</header>

		<!-- No subgroup selected: inline picker. -->
		<div v-if="!subgroupId" class="gp-subgroup-learner-context__picker" role="form">
			<label for="gp-subgroup-learner-context-subgroup-id">
				{{ t('scholiq', 'Subgroup ID') }}
			</label>
			<div class="gp-subgroup-learner-context__picker-row">
				<input
					id="gp-subgroup-learner-context-subgroup-id"
					v-model="subgroupIdInput"
					type="text"
					:placeholder="t('scholiq', 'UUID of the GroupPlanSubgroup')"
					@keyup.enter="openSubgroup">
				<button
					type="button"
					class="button-vue button-vue--vue-primary"
					:disabled="!subgroupIdInput"
					@click="openSubgroup">
					{{ t('scholiq', 'Open learner context') }}
				</button>
			</div>
			<p class="gp-subgroup-learner-context__picker-hint">
				{{ t('scholiq', 'Usually opened from a subgroup\'s detail page ("Learner context" tile) — this picker is a fallback for direct navigation.') }}
			</p>
		</div>

		<template v-else>
			<!-- Loading -->
			<div v-if="loading" class="gp-subgroup-learner-context__loading" aria-live="polite">
				<span class="icon-loading" aria-hidden="true" />
				<span>{{ t('scholiq', 'Loading learner context...') }}</span>
			</div>

			<!-- Error -->
			<div v-else-if="error" class="gp-subgroup-learner-context__error" role="alert">
				<span class="icon-error" aria-hidden="true" />
				<p>{{ error }}</p>
			</div>

			<!-- Empty -->
			<div v-else-if="!subgroup" class="gp-subgroup-learner-context__empty" role="status">
				<span class="icon-error" aria-hidden="true" />
				<p>{{ t('scholiq', 'This subgroup could not be found.') }}</p>
			</div>
			<div v-else-if="members.length === 0" class="gp-subgroup-learner-context__empty" role="status">
				<span class="icon-checkmark" aria-hidden="true" />
				<p>{{ t('scholiq', 'This subgroup has no learners yet.') }}</p>
			</div>

			<!-- Learner list -->
			<template v-else>
				<h3 class="gp-subgroup-learner-context__subgroup-name">
					{{ subgroup.name }}
				</h3>
				<ul class="gp-subgroup-learner-context__members">
					<li
						v-for="member in members"
						:key="member.learnerId"
						class="gp-subgroup-learner-context__member">
						<span class="gp-subgroup-learner-context__member-id">{{ member.learnerId }}</span>
						<router-link
							v-if="member.learningPlan"
							:to="{ name: 'LearningPlanDetail', params: { id: member.learningPlan.id ?? member.learningPlan.uuid } }"
							class="gp-subgroup-learner-context__member-plan gp-subgroup-learner-context__member-plan--active">
							{{ t('scholiq', 'Active learning plan: {kind}', { kind: member.learningPlan.kind }) }}
						</router-link>
						<span v-else class="gp-subgroup-learner-context__member-plan gp-subgroup-learner-context__member-plan--none">
							{{ t('scholiq', 'No active learning plan') }}
						</span>
					</li>
				</ul>
			</template>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'GroupPlanSubgroupLearnerContext',

	data() {
		return {
			/** @type {string} Manual subgroup-id input for the empty-state picker. */
			subgroupIdInput: '',
			loading: false,
			error: null,
			/** @type {object|null} The current GroupPlanSubgroup object. */
			subgroup: null,
			/** @type {Array<object>} Active LearningPlans, broadly fetched (bounded). */
			activeLearningPlans: [],
		}
	},

	computed: {
		/**
		 * The subgroup this context view is scoped to, read from the route
		 * query (populated by GroupPlanSubgroupDetail's "Learner context" KPI
		 * tile).
		 *
		 * @return {string}
		 */
		subgroupId() {
			return (this.$route && this.$route.query && this.$route.query.subgroupId) || ''
		},

		/**
		 * Each subgroup member paired with their active LearningPlan, if any
		 * — resolved by matching learnerId against the broadly-fetched active
		 * LearningPlans, never from a stored field on the subgroup itself.
		 *
		 * @return {Array<{learnerId: string, learningPlan: object|null}>}
		 * @spec openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-a-subgroup-member-s-existing-learningplan-is-surfaced-without-a-duplicate-field
		 */
		members() {
			if (!this.subgroup) return []
			const learnerIds = Array.isArray(this.subgroup.learnerIds) ? this.subgroup.learnerIds : []
			return learnerIds.map((learnerId) => ({
				learnerId,
				learningPlan: this.activeLearningPlans.find((p) => p.learnerId === learnerId) || null,
			}))
		},
	},

	watch: {
		subgroupId: {
			immediate: true,
			handler() {
				if (this.subgroupId) this.loadAll()
			},
		},
	},

	methods: {
		/**
		 * Navigate to this same route with the typed subgroup id set as the
		 * `subgroupId` query parameter (the picker fallback for direct
		 * navigation without a pre-selected subgroup).
		 *
		 * @return {void}
		 */
		openSubgroup() {
			const id = this.subgroupIdInput.trim()
			if (!id) return
			this.$router.replace({ name: 'GroupPlanSubgroupLearnerContext', query: { subgroupId: id } }).catch(() => {})
		},

		/**
		 * Fetch a single OpenRegister object by id.
		 *
		 * @param {string} schema OpenRegister schema title (e.g. "GroupPlanSubgroup").
		 * @param {string} id Object UUID.
		 * @return {Promise<object|null>}
		 */
		async fetchObject(schema, id) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${id}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) return null
			return resp.json()
		},

		/**
		 * Fetch one schema's objects filtered by the given query string.
		 *
		 * @param {string} schema OpenRegister schema title.
		 * @param {string} query Query string, WITHOUT the leading "?" (already
		 *   `encodeURIComponent`-escaped by the caller).
		 * @return {Promise<Array<object>>}
		 */
		async fetchSchema(schema, query) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}?${query}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) throw new Error(`${schema} fetch failed: ${resp.status}`)
			const json = await resp.json()
			return json.results ?? json.objects ?? json ?? []
		},

		/**
		 * Load the current subgroup, then broadly fetch active LearningPlans
		 * and match them against the subgroup's learnerIds client-side (no
		 * verified multi-value learnerId filter on the object list endpoint —
		 * see design.md's "to be confirmed" note; mirrors
		 * PupilDossierTimelineView's fetch-broad-then-filter-client-side
		 * DeliberationRecord join).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-a-subgroup-member-s-existing-learningplan-is-surfaced-without-a-duplicate-field
		 */
		async loadAll() {
			this.loading = true
			this.error = null
			try {
				this.subgroup = await this.fetchObject('GroupPlanSubgroup', this.subgroupId)
				if (this.subgroup) {
					this.activeLearningPlans = await this.fetchSchema('LearningPlan', 'lifecycle=active&limit=500')
				} else {
					this.activeLearningPlans = []
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load the subgroup learner context. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[GroupPlanSubgroupLearnerContext] loadAll error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.gp-subgroup-learner-context {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.gp-subgroup-learner-context__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.gp-subgroup-learner-context__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.gp-subgroup-learner-context__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.gp-subgroup-learner-context__picker {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.gp-subgroup-learner-context__picker-row {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
}

.gp-subgroup-learner-context__picker-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.gp-subgroup-learner-context__loading,
.gp-subgroup-learner-context__error,
.gp-subgroup-learner-context__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.gp-subgroup-learner-context__subgroup-name {
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.gp-subgroup-learner-context__members {
	list-style: none;
	padding: 0;
}

.gp-subgroup-learner-context__member {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 1.5) 0;
	border-bottom: 1px solid var(--color-border);
}

.gp-subgroup-learner-context__member-id {
	font-weight: 600;
}

.gp-subgroup-learner-context__member-plan--active {
	color: var(--color-primary-element, var(--color-primary));
}

.gp-subgroup-learner-context__member-plan--none {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
