<!--
  PeerReviewMarkingView.vue
  Custom page component for the PeerReviewMarkingView manifest page (type: custom).

  A reviewer's rubric-scoring surface for an assigned PeerReview
  (peer-and-self-assessment):
  1. Fetch the PeerReview + its parent Assignment + the Assignment's Rubric.
  2. For each Rubric criterion, let the reviewer pick a level.
  3. Sum up points into totalScore as each level is picked.
  4. On save: write rubricScores + totalScore + comments to the PeerReview,
     then dispatch the `submit` lifecycle transition (server-side enforced by
     RubricScoresCompletionGuard regardless of client-side validation here).

  Anonymity (design.md "Anonymity Enforcement"): when the governing
  Assignment's peerReviewAnonymity is `double-blind`, this view MUST NOT
  display the reviewed Submission's learner identity anywhere — a UI
  convention only; the reviewer's underlying object-level read access to the
  Submission is unchanged (documented, not a server-enforced guarantee, same
  as ExamCaseDossierView's own documented limit).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/PeerReview/:id
    - GET  /api/objects/scholiq/Assignment/:id
    - GET  /api/objects/scholiq/Rubric/:id
    - GET  /api/objects/scholiq/Submission/:id (only when anonymity is not double-blind)
    - PUT  /api/objects/scholiq/PeerReview/:id
    - POST /api/objects/scholiq/PeerReview/:id/transition/submit

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-double-blind-reviewee-identity-hiding-is-ui-level-only-and-this-is-documented
-->

<template>
	<div class="peer-review-marking-view">
		<!-- Loading -->
		<div v-if="loading" class="peer-review-marking-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading peer review...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="peer-review-marking-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Submitted confirmation -->
		<div v-else-if="submitted"
			class="peer-review-marking-view__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Peer review submitted') }}</h2>
			<p>{{ t('scholiq', 'Score: {score} / {max}', { score: computedScore, max: assignment.maxPoints || '?' }) }}</p>
		</div>

		<!-- Marking form -->
		<template v-else-if="peerReview">
			<header class="peer-review-marking-view__header">
				<h2>{{ t('scholiq', 'Complete peer review') }}</h2>
				<p class="peer-review-marking-view__meta">
					{{ t('scholiq', 'Assignment: {title}', { title: assignment.title || '' }) }}
				</p>
				<!--
					Anonymity Enforcement: the reviewed learner's identity is never
					rendered when peerReviewAnonymity is double-blind (UI convention).
				-->
				<p v-if="!isDoubleBlind && submissionLearnerIds.length > 0"
					class="peer-review-marking-view__learners">
					{{ t('scholiq', 'Reviewing work by: {ids}', { ids: submissionLearnerIds.join(', ') }) }}
				</p>
				<p v-else-if="isDoubleBlind" class="peer-review-marking-view__anonymity-note">
					{{ t('scholiq', 'Double-blind review — the author\'s identity is withheld.') }}
				</p>
			</header>

			<section v-if="rubric && rubric.criteria && rubric.criteria.length > 0" class="peer-review-marking-view__rubric">
				<h3>{{ t('scholiq', 'Rubric: {name}', { name: rubric.name || '' }) }}</h3>

				<div
					v-for="criterion in rubric.criteria"
					:key="criterion.criterionId"
					class="peer-review-marking-view__criterion">
					<h4 class="peer-review-marking-view__criterion-label">
						{{ criterion.label }}
						<span class="peer-review-marking-view__criterion-weight">
							{{ t('scholiq', '(weight: {w})', { w: criterion.weight }) }}
						</span>
					</h4>
					<div class="peer-review-marking-view__levels">
						<label
							v-for="level in criterion.levels"
							:key="level.levelId"
							class="peer-review-marking-view__level">
							<input
								type="radio"
								:name="criterion.criterionId"
								:value="level.levelId"
								:checked="getSelectedLevel(criterion.criterionId) === level.levelId"
								:disabled="saving"
								@change="selectLevel(criterion, level)">
							<span class="peer-review-marking-view__level-label">{{ level.label }}</span>
							<span class="peer-review-marking-view__level-points">
								{{ t('scholiq', '{pts} pts', { pts: level.points }) }}
							</span>
						</label>
					</div>
				</div>

				<div class="peer-review-marking-view__score-total">
					<strong>{{ t('scholiq', 'Score: {score} / {max}', { score: computedScore, max: assignment.maxPoints || '?' }) }}</strong>
				</div>
			</section>

			<section class="peer-review-marking-view__comments">
				<h3>{{ t('scholiq', 'Comments for the author') }}</h3>
				<textarea
					v-model="comments"
					class="peer-review-marking-view__comments-input"
					:placeholder="t('scholiq', 'Write feedback for the author...')"
					:disabled="saving"
					rows="5" />
			</section>

			<div class="peer-review-marking-view__actions">
				<button
					class="button-vue button-vue--primary peer-review-marking-view__submit-btn"
					:disabled="saving || !canSubmit"
					@click="saveAndSubmit">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Submit peer review') }}
				</button>
			</div>
			<p v-if="saveError" role="alert" class="peer-review-marking-view__save-error">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PeerReviewMarkingView',

	props: {
		/**
		 * Assignment UUID injected by vue-router from :assignmentId param.
		 */
		assignmentId: {
			type: String,
			required: true,
		},
		/**
		 * PeerReview UUID injected by vue-router from :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			peerReview: null,
			/** @type {object} */
			assignment: {},
			/** @type {object|null} */
			rubric: null,
			/** @type {Array<string>} */
			submissionLearnerIds: [],
			/**
			 * Map of criterionId → { levelId, points }
			 *
			 * @type {Record<string, { levelId: string, points: number }>}
			 */
			selectedLevels: {},
			/** @type {string} */
			comments: '',
			loading: false,
			saving: false,
			submitted: false,
			error: null,
			saveError: null,
		}
	},

	computed: {
		/**
		 * Sum of points for all selected criterion levels.
		 *
		 * @return {number}
		 */
		computedScore() {
			return Object.values(this.selectedLevels).reduce(
				(sum, sel) => sum + (sel.points || 0),
				0,
			)
		},

		/**
		 * Whether the governing Assignment's peerReviewAnonymity is double-blind.
		 *
		 * @return {boolean}
		 */
		isDoubleBlind() {
			return this.assignment.peerReviewAnonymity === 'double-blind'
		},

		/**
		 * Whether every Rubric criterion has been scored (client-side hint only —
		 * the server enforces this via RubricScoresCompletionGuard regardless).
		 *
		 * @return {boolean}
		 */
		canSubmit() {
			if (!this.rubric || !this.rubric.criteria || this.rubric.criteria.length === 0) {
				return true
			}
			return this.rubric.criteria.every((c) => this.selectedLevels[c.criterionId] != null)
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the PeerReview id prop changing by loading all data.
			 *
			 * @param {string} newId New PeerReview UUID
			 * @return {void}
			 */
			handler(newId) {
				if (newId) {
					this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the PeerReview, Assignment, Rubric, and (when not double-blind)
		 * the reviewed Submission's learnerIds.
		 *
		 * @param {string} peerReviewId PeerReview UUID
		 * @return {Promise<void>}
		 */
		async loadData(peerReviewId) {
			this.loading = true
			this.error = null

			try {
				await this.loadPeerReview(peerReviewId)
				await this.loadAssignment(this.peerReview.assignmentId ?? this.assignmentId)

				if (this.assignment.rubricId) {
					await this.loadRubric(this.assignment.rubricId)
				}

				if (!this.isDoubleBlind && this.peerReview.submissionId) {
					await this.loadSubmissionLearnerIds(this.peerReview.submissionId)
				}

				const existingScores = this.peerReview.rubricScores ?? []
				for (const score of existingScores) {
					this.selectedLevels[score.criterionId] = {
						levelId: score.levelId,
						points: score.points,
					}
				}
				this.comments = this.peerReview.comments ?? ''
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load peer review. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[PeerReviewMarkingView] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the PeerReview from OR.
		 *
		 * @param {string} peerReviewId PeerReview UUID
		 * @return {Promise<void>}
		 */
		async loadPeerReview(peerReviewId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/PeerReview/${peerReviewId}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`PeerReview fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.peerReview = json.object ?? json ?? {}
		},

		/**
		 * Fetch the Assignment from OR.
		 *
		 * @param {string} assignmentId Assignment UUID
		 * @return {Promise<void>}
		 */
		async loadAssignment(assignmentId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Assignment/${assignmentId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Assignment fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.assignment = json.object ?? json ?? {}
		},

		/**
		 * Fetch the Rubric from OR.
		 *
		 * @param {string} rubricId Rubric UUID
		 * @return {Promise<void>}
		 */
		async loadRubric(rubricId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Rubric/${rubricId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// Non-fatal — fall back to no rubric display.
				return
			}
			const json = await resp.json()
			this.rubric = json.object ?? json ?? null
		},

		/**
		 * Fetch the reviewed Submission's learnerIds — only called when anonymity
		 * is not double-blind (Anonymity Enforcement).
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 */
		async loadSubmissionLearnerIds(submissionId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Submission/${submissionId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				return
			}
			const json = await resp.json()
			const submission = json.object ?? json ?? {}
			this.submissionLearnerIds = submission.learnerIds ?? []
		},

		/**
		 * Get the currently selected levelId for a criterion.
		 *
		 * @param {string} criterionId Criterion identifier
		 * @return {string|undefined}
		 */
		getSelectedLevel(criterionId) {
			return this.selectedLevels[criterionId]?.levelId
		},

		/**
		 * Record the reviewer's level selection for a criterion.
		 *
		 * @param {object} criterion Rubric criterion object
		 * @param {object} level     Selected level object
		 * @return {void}
		 */
		selectLevel(criterion, level) {
			this.selectedLevels = {
				...this.selectedLevels,
				[criterion.criterionId]: { levelId: level.levelId, points: level.points },
			}
		},

		/**
		 * Build the rubricScores array from current selections.
		 *
		 * @return {Array<{criterionId: string, levelId: string, points: number}>}
		 */
		buildRubricScores() {
			return Object.entries(this.selectedLevels).map(([criterionId, sel]) => ({
				criterionId,
				levelId: sel.levelId,
				points: sel.points,
			}))
		},

		/**
		 * Save rubricScores + totalScore + comments to the PeerReview, then
		 * dispatch the `submit` lifecycle transition.
		 *
		 * @return {Promise<void>}
		 */
		async saveAndSubmit() {
			if (!this.peerReview) {
				return
			}
			this.saving = true
			this.saveError = null

			const rubricScores = this.buildRubricScores()
			const totalScore = this.computedScore

			try {
				const updateUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/PeerReview/${this.id}`,
				)
				const updateResp = await fetch(updateUrl, {
					method: 'PUT',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						rubricScores,
						totalScore,
						comments: this.comments,
					}),
				})
				if (!updateResp.ok) {
					throw new Error(`PeerReview update failed: ${updateResp.status}`)
				}

				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/PeerReview/${this.id}/transition/submit`,
				)
				const transResp = await fetch(transitionUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})
				if (!transResp.ok) {
					throw new Error(`Submit transition failed: ${transResp.status}`)
				}

				this.submitted = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save peer review. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[PeerReviewMarkingView] saveAndSubmit error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.peer-review-marking-view {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.peer-review-marking-view__loading,
.peer-review-marking-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.peer-review-marking-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.peer-review-marking-view__meta,
.peer-review-marking-view__learners,
.peer-review-marking-view__anonymity-note {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.peer-review-marking-view__rubric,
.peer-review-marking-view__comments {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.peer-review-marking-view__criterion {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.peer-review-marking-view__criterion-label {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.peer-review-marking-view__criterion-weight {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-left: 4px;
}

.peer-review-marking-view__levels {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
}

.peer-review-marking-view__level {
	display: flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
}

.peer-review-marking-view__level:has(input:checked) {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary);
}

.peer-review-marking-view__level-points {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.peer-review-marking-view__score-total {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-radius: 4px;
}

.peer-review-marking-view__comments-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
	font-family: inherit;
}

.peer-review-marking-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.peer-review-marking-view__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.peer-review-marking-view__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}
</style>
