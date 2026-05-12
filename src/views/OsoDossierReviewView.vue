<!--
  OsoDossierReviewView.vue
  Custom page component for the OsoDossierReviewView manifest page (type: custom).

  For a DataExchangeJob with target=oso in `pending-parent-review` state:
  1. Load the job (from route param :id).
  2. Load the learner's LearnerProfile + GradeEntry + AttendanceRecord data
     that would be included in the OSO dossier.
  3. Render the composed dossier content in read-only form for parent review.
  4. Approve button → trigger the `approveDossier` lifecycle transition
     (POST to OR lifecycle endpoint). Guard `OsoDossierReviewGuard` verifies
     the approving actor is in the learner's parentIds.
  5. Reject button → trigger the `fail` transition with a reason,
     setting the job to `failed` with an error message.

  When the job is NOT in `pending-parent-review`, the view is read-only
  (shows historical/final state).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/DataExchangeJob/:id
    - GET  /api/objects/scholiq/LearnerProfile?ncUserId=:learnerId
    - GET  /api/objects/scholiq/GradeEntry?learnerId=:learnerId
    - GET  /api/objects/scholiq/AttendanceRecord?learnerId=:learnerId
    - POST /api/objects/scholiq/DataExchangeJob/:id/transition/:action

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="oso-dossier-review">
		<!-- Loading -->
		<div v-if="loading"
			class="oso-dossier-review__loading"
			role="status"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<p>{{ t('scholiq', 'Loading OSO dossier…') }}</p>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="oso-dossier-review__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
			<button class="button-vue" @click="loadJob">
				{{ t('scholiq', 'Try again') }}
			</button>
		</div>

		<!-- Job not found / wrong state for non-OSO -->
		<div v-else-if="!job" class="oso-dossier-review__not-found">
			<p>{{ t('scholiq', 'DataExchangeJob not found.') }}</p>
		</div>

		<template v-else>
			<!-- Header -->
			<header class="oso-dossier-review__header">
				<h2>{{ t('scholiq', 'OSO Transfer Dossier Review') }}</h2>
				<p class="oso-dossier-review__job-meta">
					{{ t('scholiq', 'Job {id} · Target: {target} · Status: {status}', {
						id: job.id || job.uuid,
						target: job.target,
						status: job.lifecycle,
					}) }}
				</p>
			</header>

			<!-- Status banner for non-pending states -->
			<div v-if="job.lifecycle !== 'pending-parent-review'"
				class="oso-dossier-review__status-banner"
				role="note">
				<span class="icon-info" aria-hidden="true" />
				{{ t('scholiq', 'This dossier is in \'{state}\' state and can no longer be reviewed.', { state: job.lifecycle }) }}
			</div>

			<!-- Pending-parent-review banner -->
			<div v-else
				class="oso-dossier-review__pending-banner"
				role="note">
				<span class="icon-info" aria-hidden="true" />
				{{ t('scholiq', 'This OSO transfer dossier is awaiting parent approval. Review the data below and approve or reject.') }}
			</div>

			<!-- Learner section -->
			<section class="oso-dossier-review__section">
				<h3>{{ t('scholiq', 'Learner') }}</h3>
				<div v-if="learnerProfile" class="oso-dossier-review__data-grid">
					<dl>
						<dt>{{ t('scholiq', 'Name') }}</dt>
						<dd>{{ learnerProfile.givenName }} {{ learnerProfile.familyName }}</dd>
						<dt>{{ t('scholiq', 'ECK iD') }}</dt>
						<dd>{{ learnerProfile.eckId || t('scholiq', '—') }}</dd>
						<dt>{{ t('scholiq', 'School ID') }}</dt>
						<dd>{{ learnerProfile.schoolId || t('scholiq', '—') }}</dd>
						<dt>{{ t('scholiq', 'Date of birth') }}</dt>
						<dd>{{ learnerProfile.birthDate || t('scholiq', '—') }}</dd>
					</dl>
					<p class="oso-dossier-review__privacy-note">
						{{ t('scholiq', 'BSN is not shown or transmitted. The ECK iD pseudonym is used for identity.') }}
					</p>
				</div>
				<p v-else>
					{{ t('scholiq', 'Learner profile not found.') }}
				</p>
			</section>

			<!-- Grades section -->
			<section class="oso-dossier-review__section">
				<h3>{{ t('scholiq', 'Grade entries ({count})', { count: gradeEntries.length }) }}</h3>
				<div v-if="gradeEntries.length" class="oso-dossier-review__table-wrapper">
					<table class="oso-dossier-review__table">
						<thead>
							<tr>
								<th>{{ t('scholiq', 'Component') }}</th>
								<th>{{ t('scholiq', 'Value') }}</th>
								<th>{{ t('scholiq', 'Period') }}</th>
								<th>{{ t('scholiq', 'Status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="grade in gradeEntries" :key="grade.id">
								<td>{{ grade.componentId || '—' }}</td>
								<td>{{ grade.value }}</td>
								<td>{{ grade.periodId || '—' }}</td>
								<td>{{ grade.lifecycle || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else>
					{{ t('scholiq', 'No grade entries for this learner.') }}
				</p>
			</section>

			<!-- Attendance section -->
			<section class="oso-dossier-review__section">
				<h3>{{ t('scholiq', 'Attendance records ({count})', { count: attendanceRecords.length }) }}</h3>
				<div v-if="attendanceRecords.length" class="oso-dossier-review__table-wrapper">
					<table class="oso-dossier-review__table">
						<thead>
							<tr>
								<th>{{ t('scholiq', 'Date') }}</th>
								<th>{{ t('scholiq', 'Status') }}</th>
								<th>{{ t('scholiq', 'Minutes attended') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="rec in attendanceRecords" :key="rec.id">
								<td>{{ rec.markedAt ? rec.markedAt.substring(0, 10) : '—' }}</td>
								<td>{{ rec.status }}</td>
								<td>{{ rec.minutesAttended !== null && rec.minutesAttended !== undefined ? rec.minutesAttended : '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else>
					{{ t('scholiq', 'No attendance records for this learner.') }}
				</p>
			</section>

			<!-- Action buttons (only when pending-parent-review) -->
			<div v-if="job.lifecycle === 'pending-parent-review'"
				class="oso-dossier-review__actions">
				<div v-if="actionError" class="oso-dossier-review__action-error" role="alert">
					{{ actionError }}
				</div>

				<label for="reject-reason">{{ t('scholiq', 'Rejection reason (required to reject)') }}</label>
				<input id="reject-reason"
					v-model="rejectReason"
					type="text"
					:placeholder="t('scholiq', 'Why are you rejecting this dossier?')">

				<div class="oso-dossier-review__buttons">
					<button class="button-vue primary"
						:disabled="actioning"
						@click="approveDossier">
						{{ actioning === 'approve'
							? t('scholiq', 'Approving…')
							: t('scholiq', 'Approve and send') }}
					</button>
					<button class="button-vue"
						:disabled="actioning || !rejectReason"
						@click="rejectDossier">
						{{ actioning === 'reject'
							? t('scholiq', 'Rejecting…')
							: t('scholiq', 'Reject dossier') }}
					</button>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'OsoDossierReviewView',

	data() {
		return {
			loading: false,
			error: null,
			actionError: null,
			actioning: null,
			job: null,
			learnerProfile: null,
			gradeEntries: [],
			attendanceRecords: [],
			rejectReason: '',
		}
	},

	computed: {
		jobId() {
			return this.$route?.params?.id || null
		},

		learnerId() {
			if (!this.job) {
				return null
			}

			const scope = this.job.scope || {}
			const filters = scope.filters || {}
			return filters.learnerId || filters.ncUserId || null
		},
	},

	mounted() {
		this.loadJob()
	},

	methods: {
		async loadJob() {
			if (!this.jobId) {
				this.error = t('scholiq', 'No job ID in URL.')
				return
			}

			this.loading = true
			this.error = null

			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/DataExchangeJob/${this.jobId}`,
					{ headers: { 'X-Requested-With': 'XMLHttpRequest' } },
				)

				if (!resp.ok) {
					this.error = t('scholiq', 'Failed to load job. Please try again.')
					return
				}

				this.job = await resp.json()

				if (this.learnerId) {
					await Promise.all([
						this.loadLearnerProfile(),
						this.loadGradeEntries(),
						this.loadAttendanceRecords(),
					])
				}
			} catch {
				this.error = t('scholiq', 'Failed to load job. Please try again.')
			} finally {
				this.loading = false
			}
		},

		async loadLearnerProfile() {
			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/LearnerProfile?ncUserId=${encodeURIComponent(this.learnerId)}&limit=1`,
					{ headers: { 'X-Requested-With': 'XMLHttpRequest' } },
				)

				if (resp.ok) {
					const body = await resp.json()
					const results = body.results || body.items || []
					this.learnerProfile = results[0] || null
				}
			} catch {
				// Non-fatal — continue without learner profile
			}
		},

		async loadGradeEntries() {
			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/GradeEntry?learnerId=${encodeURIComponent(this.learnerId)}&limit=200`,
					{ headers: { 'X-Requested-With': 'XMLHttpRequest' } },
				)

				if (resp.ok) {
					const body = await resp.json()
					this.gradeEntries = body.results || body.items || []
				}
			} catch {
				this.gradeEntries = []
			}
		},

		async loadAttendanceRecords() {
			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/AttendanceRecord?learnerId=${encodeURIComponent(this.learnerId)}&limit=500`,
					{ headers: { 'X-Requested-With': 'XMLHttpRequest' } },
				)

				if (resp.ok) {
					const body = await resp.json()
					this.attendanceRecords = body.results || body.items || []
				}
			} catch {
				this.attendanceRecords = []
			}
		},

		async approveDossier() {
			this.actioning = 'approve'
			this.actionError = null

			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/DataExchangeJob/${this.jobId}/transition/approveDossier`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-Requested-With': 'XMLHttpRequest',
						},
						body: JSON.stringify({}),
					},
				)

				if (!resp.ok) {
					const err = await resp.json().catch(() => ({}))
					this.actionError = err.message || t('scholiq', 'Approval failed. Are you listed as a parent for this learner?')
					return
				}

				const updated = await resp.json()
				this.job = updated
			} catch {
				this.actionError = t('scholiq', 'Approval failed. Please try again.')
			} finally {
				this.actioning = null
			}
		},

		async rejectDossier() {
			if (!this.rejectReason) {
				return
			}

			this.actioning = 'reject'
			this.actionError = null

			try {
				const resp = await fetch(
					`/index.php/apps/openregister/api/objects/scholiq/DataExchangeJob/${this.jobId}/transition/fail`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-Requested-With': 'XMLHttpRequest',
						},
						body: JSON.stringify({ reason: this.rejectReason }),
					},
				)

				if (!resp.ok) {
					const err = await resp.json().catch(() => ({}))
					this.actionError = err.message || t('scholiq', 'Rejection failed. Please try again.')
					return
				}

				const updated = await resp.json()
				this.job = updated
			} catch {
				this.actionError = t('scholiq', 'Rejection failed. Please try again.')
			} finally {
				this.actioning = null
			}
		},
	},
}
</script>

<style scoped>
.oso-dossier-review {
	padding: var(--app-navigation-padding, 16px);
	max-width: 800px;
}

.oso-dossier-review__header {
	margin-bottom: 16px;
}

.oso-dossier-review__job-meta {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.oso-dossier-review__pending-banner,
.oso-dossier-review__status-banner {
	background: var(--color-background-hover);
	border-left: 3px solid var(--color-primary);
	padding: 8px 12px;
	margin-bottom: 16px;
	border-radius: 4px;
}

.oso-dossier-review__pending-banner {
	border-color: var(--color-warning);
}

.oso-dossier-review__section {
	margin-bottom: 24px;
}

.oso-dossier-review__section h3 {
	margin-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.oso-dossier-review__data-grid dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 4px 12px;
}

.oso-dossier-review__data-grid dt {
	font-weight: bold;
	color: var(--color-text-lighter);
}

.oso-dossier-review__privacy-note {
	margin-top: 8px;
	font-style: italic;
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.oso-dossier-review__table-wrapper {
	overflow-x: auto;
}

.oso-dossier-review__table {
	width: 100%;
	border-collapse: collapse;
}

.oso-dossier-review__table th,
.oso-dossier-review__table td {
	padding: 6px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.oso-dossier-review__table th {
	font-weight: bold;
	background: var(--color-background-hover);
}

.oso-dossier-review__actions {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.oso-dossier-review__actions label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.oso-dossier-review__actions input[type='text'] {
	width: 100%;
	margin-bottom: 12px;
}

.oso-dossier-review__buttons {
	display: flex;
	gap: 12px;
}

.oso-dossier-review__action-error {
	color: var(--color-error);
	margin-bottom: 8px;
}

.oso-dossier-review__loading,
.oso-dossier-review__error,
.oso-dossier-review__not-found {
	text-align: center;
	padding: 32px;
}

.oso-dossier-review__error {
	color: var(--color-error);
}
</style>
