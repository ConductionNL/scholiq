<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyTimetable — the signed-in user's personal timetable (personal-timetable).

 A read-only week view over the caller's OWN scheduled Session objects. The
 backend (TimetableController::mine) resolves the caller's cohorts (as a teacher
 via Cohort.teacherIds, as a learner via Cohort.learnerIds / Enrolment.cohortId)
 and returns only the sessions of those cohorts within the requested window,
 RBAC-scoped through OpenRegister's ObjectService. This view never creates or
 mutates anything — it renders the returned sessions as day-column blocks
 (title / time / location) with a click-through to the session detail page and
 a today/week toggle. A caller with no cohorts sees the empty state.

 @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
-->
<template>
	<div class="my-timetable">
		<div class="my-timetable__header">
			<h2 class="my-timetable__title">
				{{ t('scholiq', 'My timetable') }}
			</h2>
			<div class="my-timetable__controls">
				<NcButton type="tertiary"
					:aria-label="t('scholiq', 'Previous week')"
					:disabled="loading"
					@click="shiftWeek(-1)">
					‹
				</NcButton>
				<span class="my-timetable__range">{{ rangeLabel }}</span>
				<NcButton type="tertiary"
					:aria-label="t('scholiq', 'Next week')"
					:disabled="loading"
					@click="shiftWeek(1)">
					›
				</NcButton>
				<div class="my-timetable__toggle" role="group" :aria-label="t('scholiq', 'View mode')">
					<NcButton :type="mode === 'today' ? 'primary' : 'secondary'"
						:disabled="loading"
						@click="setMode('today')">
						{{ t('scholiq', 'Today') }}
					</NcButton>
					<NcButton :type="mode === 'week' ? 'primary' : 'secondary'"
						:disabled="loading"
						@click="setMode('week')">
						{{ t('scholiq', 'Week') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="my-timetable__loading" />

		<NcEmptyContent v-else-if="error"
			:name="t('scholiq', 'Could not load your timetable')"
			:description="error">
			<template #icon>
				<span class="icon-error" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="sessions.length === 0"
			:name="t('scholiq', 'No sessions')"
			:description="emptyDescription">
			<template #icon>
				<span class="icon-calendar" />
			</template>
		</NcEmptyContent>

		<div v-else class="my-timetable__grid" :class="{ 'my-timetable__grid--single': mode === 'today' }">
			<section v-for="day in visibleDays"
				:key="day.iso"
				class="my-timetable__day"
				:class="{ 'my-timetable__day--today': day.isToday }">
				<header class="my-timetable__day-head">
					<span class="my-timetable__day-name">{{ day.weekday }}</span>
					<span class="my-timetable__day-date">{{ day.dateLabel }}</span>
				</header>
				<ul class="my-timetable__sessions">
					<li v-if="day.sessions.length === 0" class="my-timetable__none">
						{{ t('scholiq', 'No sessions') }}
					</li>
					<li v-for="session in day.sessions"
						:key="session.id"
						class="my-timetable__session"
						tabindex="0"
						role="button"
						:aria-label="sessionAria(session)"
						@click="openSession(session)"
						@keyup.enter="openSession(session)">
						<span class="my-timetable__session-time">{{ timeRange(session) }}</span>
						<span class="my-timetable__session-name">{{ session.title || t('scholiq', 'Untitled session') }}</span>
						<span v-if="session.location" class="my-timetable__session-loc">{{ session.location }}</span>
					</li>
				</ul>
			</section>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { fetchMyTimetable } from '../api/timetable.js'

/**
 * Compute the Monday (00:00, local) of the week containing `date`.
 *
 * @param {Date} date Any date within the target week.
 *
 * @return {Date} Monday 00:00 of that week.
 */
function mondayOf(date) {
	const d = new Date(date.getFullYear(), date.getMonth(), date.getDate())
	const dow = (d.getDay() + 6) % 7 // 0 = Monday
	d.setDate(d.getDate() - dow)
	d.setHours(0, 0, 0, 0)
	return d
}

export default {
	name: 'MyTimetable',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: true,
			error: '',
			sessions: [],
			// Monday of the currently viewed week.
			weekStart: mondayOf(new Date()),
			mode: 'week',
		}
	},
	computed: {
		/**
		 * Exclusive end of the current week (next Monday 00:00).
		 *
		 * @return {Date} The window end.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		weekEnd() {
			const end = new Date(this.weekStart)
			end.setDate(end.getDate() + 7)
			return end
		},
		/**
		 * The seven day-buckets of the current week, each with its sessions.
		 *
		 * @return {Array<object>} Day descriptors.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		days() {
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const out = []
			for (let i = 0; i < 7; i++) {
				const day = new Date(this.weekStart)
				day.setDate(day.getDate() + i)
				const next = new Date(day)
				next.setDate(next.getDate() + 1)
				const daySessions = this.sessions.filter((s) => {
					const ts = Date.parse(s.startsAt)
					return !Number.isNaN(ts) && ts >= day.getTime() && ts < next.getTime()
				})
				out.push({
					iso: day.toISOString().slice(0, 10),
					weekday: day.toLocaleDateString(undefined, { weekday: 'short' }),
					dateLabel: day.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
					isToday: day.getTime() === today.getTime(),
					sessions: daySessions,
				})
			}
			return out
		},
		/**
		 * Days rendered given the today/week toggle.
		 *
		 * @return {Array<object>} The visible day descriptors.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		visibleDays() {
			if (this.mode === 'today') {
				const todayCol = this.days.find((d) => d.isToday)
				return todayCol ? [todayCol] : []
			}
			return this.days
		},
		/**
		 * Human-readable label for the viewed range.
		 *
		 * @return {string} The range label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		rangeLabel() {
			const opts = { day: 'numeric', month: 'short' }
			const last = new Date(this.weekEnd)
			last.setDate(last.getDate() - 1)
			return this.weekStart.toLocaleDateString(undefined, opts)
				+ ' – ' + last.toLocaleDateString(undefined, opts)
		},
		/**
		 * Empty-state description — distinguishes "no cohorts" from "no sessions this week".
		 *
		 * @return {string} The description.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		emptyDescription() {
			return this.mode === 'today'
				? t('scholiq', 'You have no sessions scheduled for today.')
				: t('scholiq', 'You have no sessions scheduled for this week. If you are not enrolled in or teaching any cohort, your timetable stays empty.')
		},
	},
	watch: {
		/**
		 * Reload the timetable whenever the viewed week changes.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		weekStart() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/**
		 * Load the caller's sessions for the current week window.
		 *
		 * @return {Promise<void>} Resolves once the sessions are loaded.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const result = await fetchMyTimetable(this.weekStart.toISOString(), this.weekEnd.toISOString())
				this.sessions = result.sessions
			} catch (e) {
				this.error = t('scholiq', 'The timetable service is unavailable. Please try again later.')
				this.sessions = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Move the viewed week by `delta` weeks.
		 *
		 * @param {number} delta Number of weeks to shift (±).
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		shiftWeek(delta) {
			const next = new Date(this.weekStart)
			next.setDate(next.getDate() + (delta * 7))
			this.weekStart = next
		},
		/**
		 * Switch the today/week toggle.
		 *
		 * @param {string} mode Either 'today' or 'week'.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		setMode(mode) {
			this.mode = mode
			if (mode === 'today') {
				// Snap the viewed week to the week containing today.
				this.weekStart = mondayOf(new Date())
			}
		},
		/**
		 * Deep-link to the session detail page.
		 *
		 * @param {object} session The clicked session.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		openSession(session) {
			if (!session || !session.id) {
				return
			}
			if (this.$router) {
				this.$router.push({ name: 'SessionDetail', params: { id: session.id } }).catch(() => {})
			}
		},
		/**
		 * Format a session's time range for display.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} A `HH:MM–HH:MM` label (or just the start).
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		timeRange(session) {
			const start = this.formatTime(session.startsAt)
			const end = this.formatTime(session.endsAt)
			if (start && end) {
				return start + '–' + end
			}
			return start
		},
		/**
		 * Format an ISO timestamp as a local `HH:MM` time.
		 *
		 * @param {string} iso The ISO 8601 timestamp.
		 *
		 * @return {string} The formatted time, or '' when unparseable.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		formatTime(iso) {
			if (!iso) {
				return ''
			}
			const ts = Date.parse(iso)
			if (Number.isNaN(ts)) {
				return ''
			}
			return new Date(ts).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		},
		/**
		 * Accessible label for a session block.
		 *
		 * @param {object} session The session.
		 *
		 * @return {string} The aria-label.
		 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
		 */
		sessionAria(session) {
			const parts = [session.title || t('scholiq', 'Untitled session'), this.timeRange(session)]
			if (session.location) {
				parts.push(session.location)
			}
			return parts.filter(Boolean).join(', ')
		},
	},
}
</script>

<style scoped lang="scss">
.my-timetable {
	padding: 16px;

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 16px;
	}

	&__title {
		margin: 0;
	}

	&__controls {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__range {
		min-width: 140px;
		text-align: center;
		font-weight: 600;
	}

	&__toggle {
		display: flex;
		gap: 4px;
		margin-inline-start: 12px;
	}

	&__loading {
		margin: 48px auto;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(7, minmax(0, 1fr));
		gap: 8px;

		&--single {
			grid-template-columns: minmax(0, 480px);
		}
	}

	&__day {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-main-background);
		min-height: 120px;
		display: flex;
		flex-direction: column;

		&--today {
			border-color: var(--color-primary-element);
		}
	}

	&__day-head {
		display: flex;
		flex-direction: column;
		padding: 8px;
		border-bottom: 1px solid var(--color-border);
	}

	&__day-name {
		font-weight: 600;
		text-transform: capitalize;
	}

	&__day-date {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__sessions {
		list-style: none;
		margin: 0;
		padding: 6px;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__none {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		padding: 4px;
	}

	&__session {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 8px;
		border-radius: var(--border-radius, 4px);
		background: var(--color-primary-element-light);
		cursor: pointer;

		&:hover,
		&:focus {
			background: var(--color-primary-element-light-hover, var(--color-background-hover));
			outline: 2px solid var(--color-primary-element);
		}
	}

	&__session-time {
		font-size: 0.8em;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__session-name {
		font-weight: 600;
	}

	&__session-loc {
		font-size: 0.8em;
		color: var(--color-text-maxcontrast);
	}
}
</style>
