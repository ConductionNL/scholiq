<!--
  MarkAttendanceView.vue
  Custom page component for the MarkAttendanceView manifest page (type: custom).

  Teacher's bulk attendance-marking surface for a Session:
  1. Fetch the Session to show title, startsAt, cohortId.
  2. Fetch the Cohort to get learnerIds.
  3. For each learner, check for an existing AttendanceRecord for this session.
  4. Render a roster grid: one row per learner with a status dropdown
     (present / absent-unexcused / absent-excused / late / left-early)
     and an optional minutesAttended field.
  5. "Mark all present" shortcut fills all rows to `present`.
  6. Save: POST (create) or PUT (update) one AttendanceRecord per learner.

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Session/:sessionId
    - GET  /api/objects/scholiq/Cohort/:cohortId
    - GET  /api/objects/scholiq/AttendanceRecord?sessionId=:id&limit=500
    - POST /api/objects/scholiq/AttendanceRecord  (create)
    - PUT  /api/objects/scholiq/AttendanceRecord/:id  (update)

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="mark-attendance-view">
		<!-- Loading -->
		<div v-if="loading" class="mark-attendance-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading session...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="mark-attendance-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Saved confirmation -->
		<div v-else-if="saved"
			class="mark-attendance-view__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Attendance saved!') }}</h2>
			<p>{{ t('scholiq', 'Attendance has been recorded for all learners.') }}</p>
			<button class="button-vue" @click="saved = false">
				{{ t('scholiq', 'Back to roster') }}
			</button>
		</div>

		<!-- Main roster -->
		<template v-else-if="session">
			<header class="mark-attendance-view__header">
				<h2 class="mark-attendance-view__title">
					{{ session.title }}
				</h2>
				<p class="mark-attendance-view__meta">
					{{ formatDateTime(session.startsAt) }}
					<span v-if="cohort"> — {{ cohort.name }}</span>
				</p>
			</header>

			<!-- Shortcut -->
			<div class="mark-attendance-view__actions">
				<button class="button-vue button-vue--primary" @click="markAllPresent">
					{{ t('scholiq', 'Mark all present') }}
				</button>
			</div>

			<!-- Roster table -->
			<div class="mark-attendance-view__roster" role="table">
				<div class="mark-attendance-view__roster-head" role="row">
					<span role="columnheader">{{ t('scholiq', 'Learner') }}</span>
					<span role="columnheader">{{ t('scholiq', 'Status') }}</span>
					<span role="columnheader">{{ t('scholiq', 'Minutes attended') }}</span>
					<span role="columnheader">{{ t('scholiq', 'Note') }}</span>
				</div>

				<div v-for="row in roster"
					:key="row.learnerId"
					class="mark-attendance-view__roster-row"
					role="row">
					<span class="mark-attendance-view__learner-id" role="cell">
						{{ row.learnerId }}
					</span>

					<span role="cell">
						<select v-model="row.status"
							class="mark-attendance-view__status-select"
							:aria-label="t('scholiq', 'Attendance status for {id}', { id: row.learnerId })">
							<option value="present">{{ t('scholiq', 'Present') }}</option>
							<option value="absent-unexcused">{{ t('scholiq', 'Absent (unexcused)') }}</option>
							<option value="absent-excused">{{ t('scholiq', 'Absent (excused)') }}</option>
							<option value="late">{{ t('scholiq', 'Late') }}</option>
							<option value="left-early">{{ t('scholiq', 'Left early') }}</option>
						</select>
					</span>

					<span role="cell">
						<input v-if="row.status !== 'absent-unexcused' && row.status !== 'absent-excused'"
							v-model.number="row.minutesAttended"
							type="number"
							min="0"
							:max="sessionDurationMinutes"
							class="mark-attendance-view__minutes-input"
							:aria-label="t('scholiq', 'Minutes attended for {id}', { id: row.learnerId })">
						<span v-else class="mark-attendance-view__absent-placeholder">—</span>
					</span>

					<span role="cell">
						<input v-model="row.reason"
							type="text"
							class="mark-attendance-view__reason-input"
							:placeholder="t('scholiq', 'Optional note...')"
							:aria-label="t('scholiq', 'Note for {id}', { id: row.learnerId })">
					</span>
				</div>
			</div>

			<!-- Save -->
			<div class="mark-attendance-view__footer">
				<button class="button-vue button-vue--primary"
					:disabled="saving"
					@click="saveAttendance">
					<span v-if="saving" class="icon-loading-small" aria-hidden="true" />
					{{ saving ? t('scholiq', 'Saving...') : t('scholiq', 'Save attendance') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'

const OR_BASE = '/index.php/apps/openregister/api/objects/scholiq'

export default {
	name: 'MarkAttendanceView',

	props: {
		pageContext: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			loading: true,
			saving: false,
			saved: false,
			error: null,
			session: null,
			cohort: null,
			/** @type {{learnerId:string,status:string,minutesAttended:number|null,reason:string,existingId:string|null}[]} */
			roster: [],
			/** @type {{[learnerId: string]: string}} learnerId → existing AttendanceRecord id */
			existingRecordMap: {},
		}
	},

	computed: {
		sessionId() {
			return this.pageContext?.params?.sessionId
				?? this.$route?.params?.sessionId
				?? null
		},
		sessionDurationMinutes() {
			if (!this.session?.startsAt || !this.session?.endsAt) return 120
			const diff = new Date(this.session.endsAt) - new Date(this.session.startsAt)
			return Math.round(diff / 60000)
		},
	},

	async created() {
		await this.loadSession()
	},

	methods: {
		async loadSession() {
			if (!this.sessionId) {
				this.error = this.t('scholiq', 'No session ID provided.')
				this.loading = false
				return
			}
			try {
				const sessionRes = await fetch(`${OR_BASE}/Session/${this.sessionId}`, {
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				if (!sessionRes.ok) throw new Error(sessionRes.statusText)
				this.session = await sessionRes.json()

				if (this.session.cohortId) {
					const cohortRes = await fetch(`${OR_BASE}/Cohort/${this.session.cohortId}`, {
						headers: { 'OCS-APIREQUEST': 'true' },
					})
					if (cohortRes.ok) {
						this.cohort = await cohortRes.json()
					}
				}

				await this.loadExistingRecords()
				this.buildRoster()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load session. Please try again.')
				console.error('[MarkAttendanceView] loadSession error:', err)
			} finally {
				this.loading = false
			}
		},

		async loadExistingRecords() {
			const url = `${OR_BASE}/AttendanceRecord?sessionId=${this.sessionId}&limit=500`
			const res = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true' } })
			if (!res.ok) return

			const data = await res.json()
			const results = data.results ?? data ?? []

			this.existingRecordMap = {}
			for (const rec of results) {
				this.existingRecordMap[rec.learnerId] = rec.id ?? rec.uuid
			}
		},

		buildRoster() {
			const learnerIds = this.cohort?.learnerIds ?? []
			this.roster = learnerIds.map((learnerId) => {
				const existingId = this.existingRecordMap[learnerId] ?? null
				return {
					learnerId,
					status: 'present',
					minutesAttended: this.sessionDurationMinutes,
					reason: '',
					existingId,
				}
			})
		},

		markAllPresent() {
			for (const row of this.roster) {
				row.status = 'present'
				row.minutesAttended = this.sessionDurationMinutes
			}
		},

		async saveAttendance() {
			this.saving = true
			const currentUser = getCurrentUser()
			const markedBy = currentUser?.uid ?? 'unknown'
			const markedAt = new Date().toISOString()

			try {
				await Promise.all(this.roster.map((row) => this.saveRecord(row, markedBy, markedAt)))
				this.saved = true
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to save attendance. Please try again.')
				console.error('[MarkAttendanceView] saveAttendance error:', err)
			} finally {
				this.saving = false
			}
		},

		async saveRecord(row, markedBy, markedAt) {
			const payload = {
				sessionId: this.sessionId,
				learnerId: row.learnerId,
				cohortId: this.session.cohortId ?? null,
				status: row.status,
				minutesAttended: (row.status === 'absent-unexcused' || row.status === 'absent-excused')
					? null
					: (row.minutesAttended ?? null),
				markedBy,
				markedAt,
				reason: row.reason || null,
			}

			if (row.existingId) {
				const res = await fetch(`${OR_BASE}/AttendanceRecord/${row.existingId}`, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(payload),
				})
				if (!res.ok) throw new Error(`PUT failed for learner ${row.learnerId}: ${res.statusText}`)
			} else {
				const res = await fetch(`${OR_BASE}/AttendanceRecord`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(payload),
				})
				if (!res.ok) throw new Error(`POST failed for learner ${row.learnerId}: ${res.statusText}`)
				const created = await res.json()
				row.existingId = created.id ?? created.uuid ?? null
			}
		},

		formatDateTime(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleString()
		},
	},
}
</script>

<style scoped>
.mark-attendance-view {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--body-container-margin, 20px);
}

.mark-attendance-view__loading,
.mark-attendance-view__error,
.mark-attendance-view__confirmation {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 48px 0;
	color: var(--color-text-maxcontrast);
}

.mark-attendance-view__error {
	color: var(--color-error);
}

.mark-attendance-view__header {
	margin-bottom: 20px;
}

.mark-attendance-view__title {
	font-size: 1.4em;
	font-weight: 600;
	margin-bottom: 4px;
}

.mark-attendance-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.mark-attendance-view__actions {
	margin-bottom: 16px;
}

.mark-attendance-view__roster {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.mark-attendance-view__roster-head,
.mark-attendance-view__roster-row {
	display: grid;
	grid-template-columns: 1fr 180px 140px 1fr;
	gap: 0;
	align-items: center;
}

.mark-attendance-view__roster-head {
	background: var(--color-background-dark);
	font-weight: 600;
	padding: 8px 12px;
	gap: 12px;
}

.mark-attendance-view__roster-row {
	padding: 8px 12px;
	gap: 12px;
	border-top: 1px solid var(--color-border);
}

.mark-attendance-view__roster-row:nth-child(odd) {
	background: var(--color-background-hover);
}

.mark-attendance-view__status-select,
.mark-attendance-view__minutes-input,
.mark-attendance-view__reason-input {
	width: 100%;
	padding: 4px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.mark-attendance-view__absent-placeholder {
	color: var(--color-text-maxcontrast);
}

.mark-attendance-view__footer {
	margin-top: 20px;
	display: flex;
	justify-content: flex-end;
}
</style>
