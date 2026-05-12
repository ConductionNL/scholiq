<!--
  CohortTimetable.vue
  Custom page component for the CohortTimetable manifest page (type: custom).
  Renders all Sessions for a given Cohort as a date-grouped timetable with
  attached Materials and linked Assignments displayed inline.

  Talks only to OpenRegister's REST API:
    - GET /api/openregister/scholiq/Cohort/:id
    - GET /api/openregister/scholiq/Session?cohortId=:id&sort=startsAt:asc
    - GET /api/openregister/scholiq/Material?sessionId=:sessionId

  Uses Options API + createObjectStore per ADR-024 / store-pattern rule.
  No custom Pinia store modules.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="cohort-timetable">
		<!-- Loading state -->
		<div v-if="loading" class="cohort-timetable__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading timetable...') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="cohort-timetable__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Timetable content -->
		<template v-else>
			<!-- Header: cohort name + lifecycle badge -->
			<div class="cohort-timetable__header">
				<h2 class="cohort-timetable__title">
					{{ cohort.name || t('scholiq', 'Cohort') }}
				</h2>
				<span
					class="cohort-timetable__badge"
					:class="`cohort-timetable__badge--${cohort.lifecycle}`">
					{{ cohort.lifecycle }}
				</span>
				<p v-if="cohort.academicYear" class="cohort-timetable__meta">
					{{ cohort.academicYear }}
					<template v-if="cohort.period">
						— {{ cohort.period }}
					</template>
				</p>
			</div>

			<!-- Empty state -->
			<div v-if="groupedSessions.length === 0" class="cohort-timetable__empty">
				<span class="icon-calendar" aria-hidden="true" />
				<p>{{ t('scholiq', 'No sessions scheduled for this cohort yet.') }}</p>
			</div>

			<!-- Date-grouped session list -->
			<section
				v-for="group in groupedSessions"
				:key="group.date"
				class="cohort-timetable__day">
				<h3 class="cohort-timetable__day-header">
					{{ formatDate(group.date) }}
				</h3>

				<article
					v-for="session in group.sessions"
					:key="session.uuid"
					class="cohort-timetable__session"
					:class="`cohort-timetable__session--${session.lifecycle}`">
					<!-- Session header row -->
					<div class="cohort-timetable__session-header">
						<span class="cohort-timetable__session-time">
							{{ formatTime(session.startsAt) }}–{{ formatTime(session.endsAt) }}
							<span v-if="session.durationMinutes" class="cohort-timetable__session-duration">
								({{ session.durationMinutes }} min)
							</span>
						</span>
						<span
							class="cohort-timetable__session-status"
							:class="`cohort-timetable__session-status--${session.lifecycle}`">
							{{ session.lifecycle }}
						</span>
					</div>

					<!-- Session title + location -->
					<h4 class="cohort-timetable__session-title">
						{{ session.title }}
					</h4>
					<p v-if="session.location" class="cohort-timetable__session-location">
						<span class="icon-location" aria-hidden="true" />
						{{ session.location }}
					</p>

					<!-- Materials list -->
					<div
						v-if="getMaterials(session.uuid).length > 0"
						class="cohort-timetable__materials">
						<h5 class="cohort-timetable__materials-heading">
							{{ t('scholiq', 'Materials') }}
						</h5>
						<ul class="cohort-timetable__materials-list">
							<li
								v-for="material in getMaterials(session.uuid)"
								:key="material.uuid"
								class="cohort-timetable__material-item">
								<span
									class="cohort-timetable__material-icon"
									:class="materialIconClass(material.kind)"
									aria-hidden="true" />
								<a
									v-if="material.url"
									:href="material.url"
									target="_blank"
									rel="noopener noreferrer"
									class="cohort-timetable__material-link">
									{{ material.title }}
									<span class="sr-only">{{ t('scholiq', '(opens in new tab)') }}</span>
								</a>
								<span v-else class="cohort-timetable__material-name">
									{{ material.title }}
								</span>
								<span v-if="material.kind" class="cohort-timetable__material-kind">
									{{ material.kind }}
								</span>
							</li>
						</ul>
					</div>

					<!-- Assignments (forward-reference UUIDs only) -->
					<div
						v-if="session.assignmentIds && session.assignmentIds.length > 0"
						class="cohort-timetable__assignments">
						<h5 class="cohort-timetable__assignments-heading">
							{{ t('scholiq', 'Assignments') }}
						</h5>
						<p class="cohort-timetable__assignments-count">
							{{ n('scholiq', '{count} assignment', '{count} assignments', session.assignmentIds.length, { count: session.assignmentIds.length }) }}
						</p>
					</div>
				</article>
			</section>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CohortTimetable',

	props: {
		/**
		 * Cohort UUID injected by the vue-router (from :id param).
		 * CnPageRenderer passes route params as props when page.route contains ':'.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object} */
			cohort: {},
			/** @type {Array<object>} */
			sessions: [],
			/**
			 * Map of sessionUuid → Material[]
			 *
			 * @type {Record<string, object[]>}
			 */
			materialsBySession: {},
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Sessions grouped by calendar date (YYYY-MM-DD), sorted ascending.
		 *
		 * @return {Array<{date: string, sessions: object[]}>}
		 */
		groupedSessions() {
			const groups = {}
			for (const session of this.sessions) {
				const date = session.startsAt ? session.startsAt.substring(0, 10) : 'unknown'
				if (!groups[date]) {
					groups[date] = []
				}
				groups[date].push(session)
			}
			return Object.keys(groups)
				.sort()
				.map((date) => ({ date, sessions: groups[date] }))
		},
	},

	watch: {
		id: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the cohort, its sessions, and all session materials.
		 *
		 * @param {string} cohortId Cohort UUID
		 * @return {Promise<void>}
		 */
		async loadData(cohortId) {
			this.loading = true
			this.error = null
			this.sessions = []
			this.materialsBySession = {}

			try {
				await Promise.all([
					this.loadCohort(cohortId),
					this.loadSessions(cohortId),
				])
				await this.loadAllMaterials()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load timetable. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CohortTimetable] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Cohort object from OR.
		 *
		 * @param {string} cohortId Cohort UUID
		 * @return {Promise<void>}
		 */
		async loadCohort(cohortId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Cohort/${cohortId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Cohort fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.cohort = json.object ?? json ?? {}
		},

		/**
		 * Fetch all Sessions for the cohort, sorted by startsAt ascending.
		 *
		 * @param {string} cohortId Cohort UUID
		 * @return {Promise<void>}
		 */
		async loadSessions(cohortId) {
			const url = generateUrl(
				'/apps/openregister/api/objects/scholiq/Session'
				+ `?filters[cohortId]=${encodeURIComponent(cohortId)}&sort=startsAt:asc&limit=500`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Sessions fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.sessions = json.results ?? json.objects ?? []
		},

		/**
		 * For each session that has materialIds, fetch the Material objects.
		 * Fires requests in parallel; gracefully skips sessions with no materials.
		 *
		 * @return {Promise<void>}
		 */
		async loadAllMaterials() {
			const sessionIds = this.sessions
				.filter((s) => s.materialIds && s.materialIds.length > 0)
				.map((s) => s.uuid)

			await Promise.all(sessionIds.map((sid) => this.loadMaterialsForSession(sid)))
		},

		/**
		 * Fetch materials attached to a specific session.
		 *
		 * @param {string} sessionId Session UUID
		 * @return {Promise<void>}
		 */
		async loadMaterialsForSession(sessionId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/Material?filters[sessionId]=${encodeURIComponent(sessionId)}&sort=order:asc&limit=100`,
			)
			try {
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) return
				const json = await resp.json()
				this.$set(this.materialsBySession, sessionId, json.results ?? json.objects ?? [])
			} catch {
				// Non-fatal: missing materials don't break the timetable.
			}
		},

		/**
		 * Get materials for a given session UUID.
		 *
		 * @param {string} sessionUuid Session UUID
		 * @return {object[]} Array of Material objects, empty array if none loaded.
		 */
		getMaterials(sessionUuid) {
			return this.materialsBySession[sessionUuid] ?? []
		},

		/**
		 * Format an ISO 8601 date string to a localised date label.
		 *
		 * @param {string} dateStr YYYY-MM-DD
		 * @return {string} Localised date string
		 */
		formatDate(dateStr) {
			if (!dateStr || dateStr === 'unknown') return this.t('scholiq', 'Unknown date')
			try {
				return new Intl.DateTimeFormat(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(dateStr))
			} catch {
				return dateStr
			}
		},

		/**
		 * Format an ISO 8601 datetime string to a short HH:mm time label.
		 *
		 * @param {string} datetimeStr ISO datetime
		 * @return {string} HH:mm formatted time
		 */
		formatTime(datetimeStr) {
			if (!datetimeStr) return '—'
			try {
				return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(datetimeStr))
			} catch {
				return datetimeStr
			}
		},

		/**
		 * Map a material kind to a Nextcloud icon CSS class.
		 *
		 * @param {string} kind Material kind enum value
		 * @return {string} CSS class string
		 */
		materialIconClass(kind) {
			const map = {
				slides: 'icon-category-files',
				reading: 'icon-text',
				video: 'icon-play',
				scorm: 'icon-workflow',
				cmi5: 'icon-workflow',
				lti: 'icon-external',
				link: 'icon-link',
				document: 'icon-file',
				other: 'icon-file',
			}
			return map[kind] ?? 'icon-file'
		},
	},
}
</script>

<style scoped>
.cohort-timetable {
	max-width: 800px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 3);
}

.cohort-timetable__loading,
.cohort-timetable__error,
.cohort-timetable__empty {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 1.5);
	color: var(--color-text-maxcontrast);
	padding: calc(var(--default-grid-baseline, 8px) * 3) 0;
}

.cohort-timetable__header {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	border-bottom: 1px solid var(--color-border);
}

.cohort-timetable__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
}

.cohort-timetable__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 100px);
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.cohort-timetable__badge--active {
	background-color: #d4edda;
	color: #155724;
}

.cohort-timetable__badge--planned {
	background-color: #fff3cd;
	color: #856404;
}

.cohort-timetable__badge--completed {
	background-color: #cce5ff;
	color: #004085;
}

.cohort-timetable__badge--archived {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.cohort-timetable__meta {
	width: 100%;
	margin: 0;
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
}

.cohort-timetable__day {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.cohort-timetable__day-header {
	font-size: 1em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
	margin: 0 0 var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) 0;
	border-bottom: 1px solid var(--color-border);
}

.cohort-timetable__session {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius, 4px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.cohort-timetable__session--cancelled {
	border-left-color: var(--color-error);
	opacity: 0.7;
}

.cohort-timetable__session--completed {
	border-left-color: var(--color-success);
}

.cohort-timetable__session-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline, 8px) / 2);
}

.cohort-timetable__session-time {
	font-size: 0.875em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.cohort-timetable__session-duration {
	font-weight: 400;
	margin-left: 4px;
}

.cohort-timetable__session-status {
	font-size: 0.7em;
	font-weight: 600;
	text-transform: uppercase;
	padding: 2px 6px;
	border-radius: var(--border-radius-pill, 100px);
	background-color: var(--color-background-dark);
}

.cohort-timetable__session-status--in-progress {
	background-color: #fff3cd;
	color: #856404;
}

.cohort-timetable__session-status--completed {
	background-color: #d4edda;
	color: #155724;
}

.cohort-timetable__session-status--cancelled {
	background-color: #f8d7da;
	color: #721c24;
}

.cohort-timetable__session-title {
	margin: 0 0 calc(var(--default-grid-baseline, 8px) / 2);
	font-size: 1em;
	font-weight: 600;
}

.cohort-timetable__session-location {
	margin: 0 0 var(--default-grid-baseline, 8px);
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	gap: 4px;
}

.cohort-timetable__materials,
.cohort-timetable__assignments {
	margin-top: var(--default-grid-baseline, 8px);
	padding-top: var(--default-grid-baseline, 8px);
	border-top: 1px solid var(--color-border);
}

.cohort-timetable__materials-heading,
.cohort-timetable__assignments-heading {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline, 8px) / 2);
}

.cohort-timetable__materials-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cohort-timetable__material-item {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.875em;
}

.cohort-timetable__material-link {
	color: var(--color-primary-element);
	text-decoration: none;
}

.cohort-timetable__material-link:hover {
	text-decoration: underline;
}

.cohort-timetable__material-kind {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 1px 5px;
	border-radius: var(--border-radius, 4px);
}

.cohort-timetable__assignments-count {
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Screen-reader only */
.sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
