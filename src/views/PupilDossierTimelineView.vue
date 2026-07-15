<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PupilDossierTimelineView — custom page component for the
 PupilDossierTimelineView manifest page (type: custom).

 The ONE genuine custom-view exception for the pupil-dossier capability
 (design.md §4): chronologically merges DossierNote + BehaviourIncident +
 WellbeingCheckIn with the existing LearningPlan/SupportRequest/
 DeliberationRecord care-chain objects for one learner. No object-list
 widget filter can merge more than one schema, so a manifest detail page
 cannot express this — every other pupil-dossier object is a declarative
 manifest index/detail page.

 Reached from LearnerProfileDetail's "Dossier timeline" KPI tile
 (lprof-kpis-dossier-timeline), which resolves @objectId into this route's
 `learnerId` query parameter via CnStatsBlockWidget's entry.route token
 resolution — the only token-resolved deep link from a detail page to a
 named route the v2 manifest action dialect supports at HEAD (open-page /
 navigate header actions do not carry the current object id; verified
 against actionsDispatcher.js). When `learnerId` is absent (e.g. direct
 navigation), an inline learner-id picker is shown instead of a blank page.

 DeliberationRecord carries no learnerId field of its own (only
 supportRequestId / tlvApplicationId) — it is included in the merge by
 first resolving the learner's own SupportRequests, then keeping only the
 DeliberationRecords whose supportRequestId is one of those (client-side
 join over a bounded fetch, mirroring BsaRiskDashboard/ConferenceScheduleBoard's
 fetch-broad-then-filter-client-side precedent elsewhere in this app).

 Uses Options API + direct fetch calls (no custom Pinia store modules),
 mirroring BsaRiskDashboard.vue / ExamCaseDossierView.vue.

 @spec openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-frontend-is-declarative-surfaced-on-the-learner-dossier-page-with-one-shared-custom-timeline-view
 @spec openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain
-->

<template>
	<div class="pupil-dossier-timeline">
		<header class="pupil-dossier-timeline__header">
			<h2 class="pupil-dossier-timeline__title">
				{{ t('scholiq', 'Pupil dossier timeline') }}
			</h2>
			<p class="pupil-dossier-timeline__subtitle">
				{{ t('scholiq', 'Notes, incidents, wellbeing check-ins, and the formal care chain for one learner, merged chronologically.') }}
			</p>
		</header>

		<!-- No learner selected: inline picker. -->
		<div v-if="!learnerId" class="pupil-dossier-timeline__picker" role="form">
			<label for="pupil-dossier-timeline-learner-id">
				{{ t('scholiq', 'Learner ID') }}
			</label>
			<div class="pupil-dossier-timeline__picker-row">
				<input
					id="pupil-dossier-timeline-learner-id"
					v-model="learnerIdInput"
					type="text"
					:placeholder="t('scholiq', 'Nextcloud user ID of the learner')"
					@keyup.enter="openLearner">
				<button
					type="button"
					class="button-vue button-vue--vue-primary"
					:disabled="!learnerIdInput"
					@click="openLearner">
					{{ t('scholiq', 'Open timeline') }}
				</button>
			</div>
			<p class="pupil-dossier-timeline__picker-hint">
				{{ t('scholiq', 'Usually opened from a learner\'s profile page ("Dossier timeline" tile) — this picker is a fallback for direct navigation.') }}
			</p>
		</div>

		<template v-else>
			<!-- Loading -->
			<div v-if="loading" class="pupil-dossier-timeline__loading" aria-live="polite">
				<span class="icon-loading" aria-hidden="true" />
				<span>{{ t('scholiq', 'Loading dossier timeline...') }}</span>
			</div>

			<!-- Error -->
			<div v-else-if="error" class="pupil-dossier-timeline__error" role="alert">
				<span class="icon-error" aria-hidden="true" />
				<p>{{ error }}</p>
			</div>

			<!-- Empty -->
			<div v-else-if="entries.length === 0" class="pupil-dossier-timeline__empty" role="status">
				<span class="icon-checkmark" aria-hidden="true" />
				<p>{{ t('scholiq', 'This learner has no dossier notes, incidents, check-ins, or care-chain records yet.') }}</p>
			</div>

			<!-- Timeline -->
			<ul v-else class="pupil-dossier-timeline__entries">
				<li
					v-for="entry in entries"
					:key="entry.kind + '-' + entry.id"
					class="pupil-dossier-timeline__entry"
					:class="'pupil-dossier-timeline__entry--' + entry.kind">
					<div class="pupil-dossier-timeline__entry-meta">
						<span class="pupil-dossier-timeline__entry-kind">{{ entry.kindLabel }}</span>
						<span class="pupil-dossier-timeline__entry-date">{{ formatDate(entry.date) }}</span>
					</div>
					<div class="pupil-dossier-timeline__entry-body">
						<router-link
							v-if="entry.route"
							:to="{ name: entry.route, params: { id: entry.id } }"
							class="pupil-dossier-timeline__entry-title">
							{{ entry.title }}
						</router-link>
						<span v-else class="pupil-dossier-timeline__entry-title">{{ entry.title }}</span>
						<p v-if="entry.summary" class="pupil-dossier-timeline__entry-summary">
							{{ entry.summary }}
						</p>
					</div>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PupilDossierTimelineView',

	data() {
		return {
			/** @type {string} Manual learner-id input for the empty-state picker. */
			learnerIdInput: '',
			loading: false,
			error: null,
			notes: [],
			incidents: [],
			checkIns: [],
			plans: [],
			requests: [],
			deliberations: [],
		}
	},

	computed: {
		/**
		 * The learner this timeline is scoped to, read from the route query
		 * (populated by LearnerProfileDetail's "Dossier timeline" KPI tile).
		 *
		 * @return {string}
		 */
		learnerId() {
			return (this.$route && this.$route.query && this.$route.query.learnerId) || ''
		},

		/**
		 * All six schemas normalised into one shape and merged chronologically
		 * (newest first).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain
		 */
		entries() {
			const rows = [
				...this.notes.map((o) => this.normaliseNote(o)),
				...this.incidents.map((o) => this.normaliseIncident(o)),
				...this.checkIns.map((o) => this.normaliseCheckIn(o)),
				...this.plans.map((o) => this.normalisePlan(o)),
				...this.requests.map((o) => this.normaliseRequest(o)),
				...this.deliberations.map((o) => this.normaliseDeliberation(o)),
			]
			return rows
				.filter((r) => r.date)
				.sort((a, b) => new Date(b.date) - new Date(a.date))
		},
	},

	watch: {
		learnerId: {
			immediate: true,
			handler() {
				if (this.learnerId) this.loadAll()
			},
		},
	},

	methods: {
		/**
		 * Navigate to this same route with the typed learner id set as the
		 * `learnerId` query parameter (the picker fallback for direct
		 * navigation without a pre-selected learner).
		 *
		 * @return {void}
		 */
		openLearner() {
			const id = this.learnerIdInput.trim()
			if (!id) return
			this.$router.replace({ name: 'PupilDossierTimelineView', query: { learnerId: id } }).catch(() => {})
		},

		/**
		 * Fetch one schema's objects filtered by the given query string.
		 *
		 * @param {string} schema OpenRegister schema title (e.g. "DossierNote").
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
		 * Load every schema contributing to the timeline for the current
		 * `learnerId`, then resolve DeliberationRecord by joining on the
		 * fetched SupportRequests' ids (DeliberationRecord carries no
		 * learnerId field of its own).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain
		 */
		async loadAll() {
			this.loading = true
			this.error = null
			const learnerQuery = `learnerId=${encodeURIComponent(this.learnerId)}&limit=200`

			try {
				const [notes, incidents, checkIns, plans, requests] = await Promise.all([
					this.fetchSchema('DossierNote', learnerQuery),
					this.fetchSchema('BehaviourIncident', learnerQuery),
					this.fetchSchema('WellbeingCheckIn', learnerQuery),
					this.fetchSchema('LearningPlan', learnerQuery),
					this.fetchSchema('SupportRequest', learnerQuery),
				])
				this.notes = notes
				this.incidents = incidents
				this.checkIns = checkIns
				this.plans = plans
				this.requests = requests

				const requestIds = new Set(requests.map((r) => r.id ?? r.uuid))
				if (requestIds.size > 0) {
					const allDeliberations = await this.fetchSchema('DeliberationRecord', 'limit=500')
					this.deliberations = allDeliberations.filter((d) => requestIds.has(d.supportRequestId))
				} else {
					this.deliberations = []
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load the dossier timeline. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[PupilDossierTimelineView] loadAll error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} o DossierNote object.
		 * @return {object} Normalised timeline entry.
		 */
		normaliseNote(o) {
			return {
				kind: 'dossier-note',
				kindLabel: this.t('scholiq', 'Dossier note'),
				id: o.id ?? o.uuid,
				date: o.date,
				title: o.category,
				summary: o.body,
				route: 'DossierNoteDetail',
			}
		},

		/**
		 * @param {object} o BehaviourIncident object.
		 * @return {object} Normalised timeline entry.
		 */
		normaliseIncident(o) {
			return {
				kind: 'behaviour-incident',
				kindLabel: this.t('scholiq', 'Behaviour incident'),
				id: o.id ?? o.uuid,
				date: o.occurredAt,
				title: this.t('scholiq', 'Incident ({severity})', { severity: o.severity }),
				summary: o.what,
				route: 'BehaviourIncidentDetail',
			}
		},

		/**
		 * @param {object} o WellbeingCheckIn object.
		 * @return {object} Normalised timeline entry.
		 */
		normaliseCheckIn(o) {
			return {
				kind: 'wellbeing-check-in',
				kindLabel: this.t('scholiq', 'Wellbeing check-in'),
				id: o.id ?? o.uuid,
				date: o.submittedAt,
				title: this.t('scholiq', 'Mood: {mood}/5', { mood: o.moodScale }),
				summary: o.comment,
				route: 'WellbeingCheckInDetail',
			}
		},

		/**
		 * @param {object} o LearningPlan object.
		 * @return {object} Normalised timeline entry.
		 */
		normalisePlan(o) {
			return {
				kind: 'learning-plan',
				kindLabel: this.t('scholiq', 'Learning plan'),
				id: o.id ?? o.uuid,
				date: o.created ?? o.startDate ?? o.nextReviewAt,
				title: o.kind,
				summary: o.period,
				route: 'LearningPlanDetail',
			}
		},

		/**
		 * @param {object} o SupportRequest object.
		 * @return {object} Normalised timeline entry.
		 */
		normaliseRequest(o) {
			return {
				kind: 'support-request',
				kindLabel: this.t('scholiq', 'Support request'),
				id: o.id ?? o.uuid,
				date: o.created ?? o.submittedAt,
				title: o.supportDomain,
				summary: o.description,
				route: 'SupportRequestDetail',
			}
		},

		/**
		 * @param {object} o DeliberationRecord object.
		 * @return {object} Normalised timeline entry.
		 */
		normaliseDeliberation(o) {
			return {
				kind: 'deliberation-record',
				kindLabel: this.t('scholiq', 'Deliberation record'),
				id: o.id ?? o.uuid,
				date: o.recordedAt ?? o.scheduledAt,
				title: o.outcome,
				summary: null,
				route: 'DeliberationRecordDetail',
			}
		},

		/**
		 * Format a date/datetime string for display.
		 *
		 * @param {string} dt ISO date or datetime string.
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
.pupil-dossier-timeline {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.pupil-dossier-timeline__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.pupil-dossier-timeline__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.pupil-dossier-timeline__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.pupil-dossier-timeline__picker {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.pupil-dossier-timeline__picker-row {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
}

.pupil-dossier-timeline__picker-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.pupil-dossier-timeline__loading,
.pupil-dossier-timeline__error,
.pupil-dossier-timeline__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.pupil-dossier-timeline__entries {
	list-style: none;
	padding: 0;
	border-left: 2px solid var(--color-border);
}

.pupil-dossier-timeline__entry {
	position: relative;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding: 0 0 0 calc(var(--default-grid-baseline, 8px) * 2);
}

.pupil-dossier-timeline__entry::before {
	content: '';
	position: absolute;
	left: -5px;
	top: 4px;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-primary-element, var(--color-primary));
}

.pupil-dossier-timeline__entry-meta {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: baseline;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.pupil-dossier-timeline__entry-kind {
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}

.pupil-dossier-timeline__entry-title {
	display: block;
	font-weight: 600;
	margin-top: 2px;
}

.pupil-dossier-timeline__entry-summary {
	margin-top: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
