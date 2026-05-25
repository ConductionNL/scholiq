<!--
  ProctoringReviewQueue.vue
  Custom page component for the ProctoringReviewQueue manifest page (type: custom).

  Invigilator flag-review queue. Lists ProctoringSession objects with at least one
  pending flag; allows the invigilator to mark each flag as `allowed` or `annulled`.

  EU AI Act Art. 14 compliance: this view is the ONLY place where a flag decision
  is recorded. The decision NEVER automatically alters the associated AssessmentResult.
  Invigilators must consciously choose to take action on the result separately.

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
-->

<template>
	<div class="proctoring-review-queue">
		<header class="proctoring-review-queue__header">
			<h2 class="proctoring-review-queue__title">
				{{ t('scholiq', 'Proctoring flag review queue') }}
			</h2>
			<p class="proctoring-review-queue__subtitle">
				{{ t('scholiq', 'Review flagged proctoring events. Decisions are recorded for compliance; no result is altered automatically (EU AI Act Art. 14).') }}
			</p>
		</header>

		<!-- Loading -->
		<div v-if="loading" class="proctoring-review-queue__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading sessions...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="proctoring-review-queue__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Empty -->
		<div v-else-if="sessionsWithPendingFlags.length === 0" class="proctoring-review-queue__empty" role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'No sessions with pending flags.') }}</p>
		</div>

		<!-- Session list -->
		<ul v-else class="proctoring-review-queue__sessions">
			<li
				v-for="session in sessionsWithPendingFlags"
				:key="session.uuid"
				class="proctoring-review-queue__session">
				<header class="proctoring-review-queue__session-header">
					<h3>{{ t('scholiq', 'Session {id}', { id: shortId(session.uuid) }) }}</h3>
					<span class="proctoring-review-queue__meta">
						{{ t('scholiq', 'Learner: {id}', { id: session.learnerId }) }}
						&mdash;
						{{ t('scholiq', 'Provider: {p}', { p: session.provider }) }}
					</span>
					<span class="proctoring-review-queue__pending-count">
						{{ t('scholiq', '{n} pending flag(s)', { n: pendingCount(session) }) }}
					</span>
				</header>

				<!-- Flags -->
				<ul class="proctoring-review-queue__flags">
					<li
						v-for="flag in session.flags"
						:key="flag.flagId"
						class="proctoring-review-queue__flag"
						:class="'proctoring-review-queue__flag--' + flag.severity">
						<div class="proctoring-review-queue__flag-info">
							<span class="proctoring-review-queue__flag-kind">{{ flag.kind }}</span>
							<span class="proctoring-review-queue__flag-severity">{{ flag.severity }}</span>
							<span class="proctoring-review-queue__flag-time">{{ formatDate(flag.occurredAt) }}</span>
						</div>

						<div class="proctoring-review-queue__flag-decision">
							<template v-if="flag.reviewDecision === 'pending'">
								<button
									class="button-vue"
									:disabled="savingFlagId === flag.flagId"
									@click="recordDecision(session, flag, 'allowed')">
									<span v-if="savingFlagId === flag.flagId" class="icon-loading" aria-hidden="true" />
									{{ t('scholiq', 'Allow') }}
								</button>
								<button
									class="button-vue button-vue--error"
									:disabled="savingFlagId === flag.flagId"
									@click="recordDecision(session, flag, 'annulled')">
									{{ t('scholiq', 'Annul') }}
								</button>
							</template>
							<template v-else>
								<span class="proctoring-review-queue__flag-decided" :class="'proctoring-review-queue__flag-decided--' + flag.reviewDecision">
									{{ flag.reviewDecision === 'allowed' ? t('scholiq', 'Allowed') : t('scholiq', 'Annulled') }}
								</span>
								<span class="proctoring-review-queue__flag-reviewer">
									{{ t('scholiq', 'by {who} at {when}', { who: flag.reviewedBy, when: formatDate(flag.reviewedAt) }) }}
								</span>
							</template>
						</div>
					</li>
				</ul>

				<p v-if="sessionError[session.uuid]" role="alert" class="proctoring-review-queue__error-inline">
					{{ sessionError[session.uuid] }}
				</p>

				<!-- Human-oversight reminder -->
				<p class="proctoring-review-queue__oversight-note">
					{{ t('scholiq', 'Annulling a flag does not automatically invalidate the result. Use the AssessmentResult view to take further action.') }}
				</p>
			</li>
		</ul>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'ProctoringReviewQueue',

	data() {
		return {
			/** @type {object[]} */
			sessions: [],
			loading: false,
			error: null,
			/** @type {string|null} */
			savingFlagId: null,
			/** @type {Record<string, string>} */
			sessionError: {},
		}
	},

	computed: {
		/**
		 * Sessions that have at least one pending flag.
		 * @return {object[]}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		sessionsWithPendingFlags() {
			return this.sessions.filter((s) => this.pendingCount(s) > 0)
		},
	},

	created() {
		this.loadSessions()
	},

	methods: {
		/**
		 * Fetch all ProctoringSession objects.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async loadSessions() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/ProctoringSession?limit=100')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) throw new Error(`Sessions fetch failed: ${resp.status}`)
				const json = await resp.json()
				this.sessions = json.results ?? json.objects ?? json ?? []
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load proctoring sessions. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[ProctoringReviewQueue] loadSessions error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Count pending flags on a session.
		 *
		 * @param {object} session ProctoringSession object
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		pendingCount(session) {
			return (session.flags ?? []).filter((f) => f.reviewDecision === 'pending').length
		},

		/**
		 * Record an invigilator decision for a flag and persist it to OR.
		 * NEVER touches the associated AssessmentResult — EU AI Act Art. 14 compliance.
		 *
		 * @param {object} session    ProctoringSession object
		 * @param {object} flag       The flag to decide on
		 * @param {string} decision   'allowed' or 'annulled'
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async recordDecision(session, flag, decision) {
			const currentUser = getCurrentUser()
			const reviewedBy = currentUser?.uid ?? 'unknown'

			this.savingFlagId = flag.flagId
			this.sessionError = { ...this.sessionError, [session.uuid]: null }

			try {
				// Update the flag in the session's flags array.
				const updatedFlags = (session.flags ?? []).map((f) => {
					if (f.flagId !== flag.flagId) return f
					return {
						...f,
						reviewDecision: decision,
						reviewedBy,
						reviewedAt: new Date().toISOString(),
					}
				})

				const url = generateUrl(
					`/apps/openregister/api/objects/scholiq/ProctoringSession/${session.uuid}`,
				)
				const resp = await fetch(url, {
					method: 'PUT',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ flags: updatedFlags }),
				})

				if (!resp.ok) {
					throw new Error(`Flag decision save failed: ${resp.status}`)
				}

				// Update local state.
				const idx = this.sessions.findIndex((s) => s.uuid === session.uuid)
				if (idx >= 0) {
					this.sessions[idx] = { ...this.sessions[idx], flags: updatedFlags }
					// Force Vue 2 reactivity.
					this.sessions = [...this.sessions]
				}
			} catch (err) {
				this.sessionError = {
					...this.sessionError,
					[session.uuid]: this.t('scholiq', 'Failed to save review decision. Please try again.'),
				}
				// eslint-disable-next-line no-console
				console.error('[ProctoringReviewQueue] recordDecision error', err)
			} finally {
				this.savingFlagId = null
			}
		},

		/**
		 * Format a datetime string for display.
		 *
		 * @param {string} dt ISO datetime string
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		formatDate(dt) {
			if (!dt) return ''
			try {
				return new Intl.DateTimeFormat(navigator.language, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				}).format(new Date(dt))
			} catch {
				return dt
			}
		},

		/**
		 * Return a short display identifier (first 8 chars of UUID).
		 *
		 * @param {string} uuid Full UUID
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		shortId(uuid) {
			return (uuid ?? '').slice(0, 8)
		},
	},
}
</script>

<style scoped>
.proctoring-review-queue {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.proctoring-review-queue__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.proctoring-review-queue__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.proctoring-review-queue__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.proctoring-review-queue__loading,
.proctoring-review-queue__error,
.proctoring-review-queue__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.proctoring-review-queue__sessions {
	list-style: none;
	padding: 0;
}

.proctoring-review-queue__session {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	overflow: hidden;
}

.proctoring-review-queue__session-header {
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-background-dark);
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
}

.proctoring-review-queue__session-header h3 {
	margin: 0;
	font-weight: bold;
}

.proctoring-review-queue__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.proctoring-review-queue__pending-count {
	margin-left: auto;
	font-weight: bold;
	color: var(--color-warning);
}

.proctoring-review-queue__flags {
	list-style: none;
	padding: 0;
}

.proctoring-review-queue__flag {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	border-top: 1px solid var(--color-border);
}

.proctoring-review-queue__flag--high {
	border-left: 4px solid var(--color-error);
}

.proctoring-review-queue__flag--medium {
	border-left: 4px solid var(--color-warning);
}

.proctoring-review-queue__flag--low {
	border-left: 4px solid var(--color-info);
}

.proctoring-review-queue__flag-info {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
}

.proctoring-review-queue__flag-kind {
	font-weight: 500;
}

.proctoring-review-queue__flag-severity {
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	font-size: 0.8em;
}

.proctoring-review-queue__flag-time {
	color: var(--color-text-maxcontrast);
}

.proctoring-review-queue__flag-decision {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
	flex-shrink: 0;
}

.proctoring-review-queue__flag-decided {
	font-weight: 500;
}

.proctoring-review-queue__flag-decided--allowed {
	color: var(--color-success);
}

.proctoring-review-queue__flag-decided--annulled {
	color: var(--color-error);
}

.proctoring-review-queue__flag-reviewer {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.proctoring-review-queue__oversight-note {
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	background: var(--color-background-hover);
}

.proctoring-review-queue__error-inline {
	margin: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
