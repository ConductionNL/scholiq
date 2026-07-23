<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TimetableConflictQueue.vue
 Custom page component for the TimetableConflictQueue manifest page (type: custom).

 Scheduling-coordinator review queue for `TimetableConflict` rows
 (teacher/room/cohort/learner-double-booking, room-capacity-exceeded,
 exam-clash) created by TimetableConflictDetector. Lists `open` and
 `acknowledged` conflicts and lets a coordinator acknowledge or resolve each
 — this view NEVER edits the referenced Sessions itself (the detector never
 auto-resolves; a human decides what to actually change on the Session
 elsewhere, e.g. via SubstitutionModal).

 Mirrors ProctoringReviewQueue's Options API + direct fetch/axios shape (no
 custom Pinia store module).

 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-detected-conflicts-are-queued-for-coordinator-review
-->

<template>
	<div class="timetable-conflict-queue">
		<header class="timetable-conflict-queue__header">
			<h2 class="timetable-conflict-queue__title">
				{{ t('scholiq', 'Timetable conflicts') }}
			</h2>
			<p class="timetable-conflict-queue__subtitle">
				{{ t('scholiq', 'Detected double-bookings and capacity overruns. Nothing here is auto-resolved — review each conflict and act on the affected Sessions directly.') }}
			</p>
		</header>

		<div v-if="loading" class="timetable-conflict-queue__loading" aria-live="polite">
			<NcLoadingIcon :size="32" />
			<span>{{ t('scholiq', 'Loading conflicts…') }}</span>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-else-if="visibleConflicts.length === 0"
			:name="t('scholiq', 'No open conflicts')"
			:description="t('scholiq', 'The timetable has no unresolved conflicts right now.')">
			<template #icon>
				<span class="icon-checkmark" />
			</template>
		</NcEmptyContent>

		<ul v-else class="timetable-conflict-queue__list">
			<li v-for="conflict in visibleConflicts"
				:key="conflict.id"
				class="timetable-conflict-queue__item"
				:class="'timetable-conflict-queue__item--' + conflict.severity">
				<div class="timetable-conflict-queue__info">
					<span class="timetable-conflict-queue__kind">{{ kindLabel(conflict.kind) }}</span>
					<span class="timetable-conflict-queue__severity">{{ conflict.severity }}</span>
					<span class="timetable-conflict-queue__sessions">
						{{ t('scholiq', 'Sessions: {ids}', { ids: (conflict.sessionIds || []).join(', ') }) }}
					</span>
					<span class="timetable-conflict-queue__detected">{{ formatDate(conflict.detectedAt) }}</span>
				</div>

				<div class="timetable-conflict-queue__actions">
					<template v-if="conflict.lifecycle === 'open'">
						<NcButton :disabled="savingId === conflict.id" @click="transition(conflict, 'acknowledged')">
							{{ t('scholiq', 'Acknowledge') }}
						</NcButton>
					</template>
					<NcButton type="primary" :disabled="savingId === conflict.id" @click="transition(conflict, 'resolved')">
						{{ t('scholiq', 'Resolve') }}
					</NcButton>
				</div>

				<p v-if="itemError[conflict.id]" role="alert" class="timetable-conflict-queue__error-inline">
					{{ itemError[conflict.id] }}
				</p>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'TimetableConflictQueue',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			conflicts: [],
			loading: false,
			error: '',
			savingId: null,
			itemError: {},
		}
	},

	computed: {
		/**
		 * Open + acknowledged conflicts, ordered newest-first.
		 *
		 * @return {Array<object>}
		 */
		visibleConflicts() {
			return this.conflicts
				.filter((c) => c.lifecycle === 'open' || c.lifecycle === 'acknowledged')
				.slice()
				.sort((a, b) => String(b.detectedAt || '').localeCompare(String(a.detectedAt || '')))
		},
	},

	created() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Fetch every TimetableConflict object.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/timetable-conflict?limit=200')
				const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
				if (!resp.ok) throw new Error(`Conflicts fetch failed: ${resp.status}`)
				const json = await resp.json()
				this.conflicts = json.results ?? json.objects ?? json ?? []
			} catch (err) {
				this.error = t('scholiq', 'Failed to load timetable conflicts. Please try again.')
				console.error('[TimetableConflictQueue] load error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Human-readable label for a conflict kind.
		 *
		 * @param {string} kind The raw kind value.
		 * @return {string}
		 */
		kindLabel(kind) {
			const labels = {
				'teacher-double-booking': t('scholiq', 'Teacher double-booked'),
				'room-double-booking': t('scholiq', 'Room double-booked'),
				'cohort-double-booking': t('scholiq', 'Cohort scheduled twice'),
				'learner-double-booking': t('scholiq', 'Learner double-booked'),
				'room-capacity-exceeded': t('scholiq', 'Room capacity exceeded'),
				'exam-clash': t('scholiq', 'Exam clash'),
			}
			return labels[kind] ?? kind
		},

		/**
		 * Move a conflict to `acknowledged` or `resolved` — this NEVER touches
		 * the referenced Sessions.
		 *
		 * @param {object} conflict The TimetableConflict object.
		 * @param {string} lifecycle Target lifecycle value.
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-coordinator-sees-a-newly-detected-conflict-in-their-review-queue
		 */
		async transition(conflict, lifecycle) {
			this.savingId = conflict.id
			this.itemError = { ...this.itemError, [conflict.id]: null }

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/timetable-conflict/{id}', { id: conflict.id })
				await axios.put(url, { lifecycle })

				const idx = this.conflicts.findIndex((c) => c.id === conflict.id)
				if (idx >= 0) {
					this.conflicts[idx] = { ...this.conflicts[idx], lifecycle }
					this.conflicts = [...this.conflicts]
				}
			} catch (err) {
				this.itemError = {
					...this.itemError,
					[conflict.id]: t('scholiq', 'Failed to update this conflict. Please try again.'),
				}
				console.error('[TimetableConflictQueue] transition error', err)
			} finally {
				this.savingId = null
			}
		},

		/**
		 * Format a datetime string for display.
		 *
		 * @param {string} dt ISO datetime string.
		 * @return {string}
		 */
		formatDate(dt) {
			if (!dt) return ''
			try {
				return new Intl.DateTimeFormat(navigator.language, {
					year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
				}).format(new Date(dt))
			} catch {
				return dt
			}
		},
	},
}
</script>

<style scoped>
.timetable-conflict-queue {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.timetable-conflict-queue__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.timetable-conflict-queue__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.timetable-conflict-queue__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.timetable-conflict-queue__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.timetable-conflict-queue__list {
	list-style: none;
	padding: 0;
}

.timetable-conflict-queue__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.timetable-conflict-queue__item--error {
	border-left: 4px solid var(--color-error);
}

.timetable-conflict-queue__item--warning {
	border-left: 4px solid var(--color-warning);
}

.timetable-conflict-queue__info {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
}

.timetable-conflict-queue__kind {
	font-weight: 600;
}

.timetable-conflict-queue__severity {
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	font-size: 0.8em;
}

.timetable-conflict-queue__detected {
	color: var(--color-text-maxcontrast);
}

.timetable-conflict-queue__actions {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	flex-shrink: 0;
}

.timetable-conflict-queue__error-inline {
	width: 100%;
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
