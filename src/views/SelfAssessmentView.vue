<!--
  SelfAssessmentView.vue
  Custom page component for the SelfAssessmentView manifest page (type: custom).

  A learner's own rubric self-scoring surface for their own Submission
  (peer-and-self-assessment):
  1. Fetch (or create) the learner's own SelfAssessment for this Submission +
     the parent Assignment + the Assignment's Rubric.
  2. For each Rubric criterion, let the learner pick a level.
  3. Sum up points into totalScore as each level is picked.
  4. On save: create/update the SelfAssessment (rubricScores + totalScore +
     comments + timing), then dispatch the `submit` lifecycle transition
     (server-side enforced by RubricScoresCompletionGuard regardless).

  Grade authority (openspec/specs/assignments/spec.md "Marking a Submission
  emits a GradeEntry"): this view NEVER writes Submission.rubricScores,
  Submission.proposedGrade, or any GradeEntry — the SelfAssessment is a
  parallel signal only, read by the teacher as read-only context in
  MarkSubmissionView.

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Submission/:id
    - GET  /api/objects/scholiq/Assignment/:id
    - GET  /api/objects/scholiq/Rubric/:id
    - GET  /api/objects/scholiq/SelfAssessment?submissionId=:id&learnerId=:uid
    - POST/PUT /api/objects/scholiq/SelfAssessment
    - POST /api/objects/scholiq/SelfAssessment/:id/transition/submit

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView / PeerReviewMarkingView.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-before-submitting
  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-after-submitting
-->

<template>
	<div class="self-assessment-view">
		<!-- Loading -->
		<div v-if="loading" class="self-assessment-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading self-assessment...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="self-assessment-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Submitted confirmation -->
		<div v-else-if="submitted"
			class="self-assessment-view__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Self-assessment submitted') }}</h2>
			<p>{{ t('scholiq', 'Score: {score} / {max}', { score: computedScore, max: assignment.maxPoints || '?' }) }}</p>
		</div>

		<!-- Marking form -->
		<template v-else-if="submission">
			<header class="self-assessment-view__header">
				<h2>{{ t('scholiq', 'Self-assessment') }}</h2>
				<p class="self-assessment-view__meta">
					{{ t('scholiq', 'Assignment: {title}', { title: assignment.title || '' }) }}
				</p>
			</header>

			<section v-if="rubric && rubric.criteria && rubric.criteria.length > 0" class="self-assessment-view__rubric">
				<h3>{{ t('scholiq', 'Rubric: {name}', { name: rubric.name || '' }) }}</h3>

				<div
					v-for="criterion in rubric.criteria"
					:key="criterion.criterionId"
					class="self-assessment-view__criterion">
					<h4 class="self-assessment-view__criterion-label">
						{{ criterion.label }}
						<span class="self-assessment-view__criterion-weight">
							{{ t('scholiq', '(weight: {w})', { w: criterion.weight }) }}
						</span>
					</h4>
					<div class="self-assessment-view__levels">
						<label
							v-for="level in criterion.levels"
							:key="level.levelId"
							class="self-assessment-view__level">
							<input
								type="radio"
								:name="criterion.criterionId"
								:value="level.levelId"
								:checked="getSelectedLevel(criterion.criterionId) === level.levelId"
								:disabled="saving"
								@change="selectLevel(criterion, level)">
							<span class="self-assessment-view__level-label">{{ level.label }}</span>
							<span class="self-assessment-view__level-points">
								{{ t('scholiq', '{pts} pts', { pts: level.points }) }}
							</span>
						</label>
					</div>
				</div>

				<div class="self-assessment-view__score-total">
					<strong>{{ t('scholiq', 'Score: {score} / {max}', { score: computedScore, max: assignment.maxPoints || '?' }) }}</strong>
				</div>
			</section>

			<section class="self-assessment-view__comments">
				<h3>{{ t('scholiq', 'Your reflection') }}</h3>
				<textarea
					v-model="comments"
					class="self-assessment-view__comments-input"
					:placeholder="t('scholiq', 'Reflect on your own work...')"
					:disabled="saving"
					rows="5" />
			</section>

			<div class="self-assessment-view__actions">
				<button
					class="button-vue button-vue--primary self-assessment-view__submit-btn"
					:disabled="saving || !canSubmit"
					@click="saveAndSubmit">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Submit self-assessment') }}
				</button>
			</div>
			<p v-if="saveError" role="alert" class="self-assessment-view__save-error">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'SelfAssessmentView',

	props: {
		/**
		 * Assignment UUID injected by vue-router from :assignmentId param.
		 */
		assignmentId: {
			type: String,
			required: true,
		},
		/**
		 * Submission UUID injected by vue-router from :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			submission: null,
			/** @type {object} */
			assignment: {},
			/** @type {object|null} */
			rubric: null,
			/** @type {object|null} Existing SelfAssessment row, if any. */
			selfAssessment: null,
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

		/**
		 * Timing this self-assessment is completed at, per whether the linked
		 * Submission has already been submitted.
		 *
		 * @return {string} 'before-submission' or 'after-submission'
		 */
		timing() {
			return this.submission && this.submission.lifecycle === 'draft'
				? 'before-submission'
				: 'after-submission'
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the Submission id prop changing by loading all data.
			 *
			 * @param {string} newId New Submission UUID
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
		 * Load the Submission, Assignment, Rubric, and any existing SelfAssessment
		 * for the current learner.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 */
		async loadData(submissionId) {
			this.loading = true
			this.error = null

			try {
				await this.loadSubmission(submissionId)
				await this.loadAssignment(this.submission.assignmentId ?? this.assignmentId)

				if (this.assignment.rubricId) {
					await this.loadRubric(this.assignment.rubricId)
				}

				await this.loadExistingSelfAssessment(submissionId)

				if (this.selfAssessment) {
					const existingScores = this.selfAssessment.rubricScores ?? []
					for (const score of existingScores) {
						this.selectedLevels[score.criterionId] = {
							levelId: score.levelId,
							points: score.points,
						}
					}
					this.comments = this.selfAssessment.comments ?? ''
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load self-assessment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SelfAssessmentView] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Submission from OR.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 */
		async loadSubmission(submissionId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Submission/${submissionId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Submission fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.submission = json.object ?? json ?? {}
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
		 * Fetch the current learner's own SelfAssessment for this Submission, if
		 * one already exists (draft in progress).
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 */
		async loadExistingSelfAssessment(submissionId) {
			const uid = getCurrentUser()?.uid ?? ''
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/SelfAssessment?filters[submissionId]=${submissionId}&filters[learnerId]=${uid}&limit=1`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				return
			}
			const json = await resp.json()
			const results = json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
			this.selfAssessment = results.length > 0 ? results[0] : null
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
		 * Record the learner's level selection for a criterion.
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
		 * Create/update the SelfAssessment, then dispatch the `submit` lifecycle
		 * transition. Never writes Submission.rubricScores/proposedGrade or a
		 * GradeEntry — grade authority stays with the teacher.
		 *
		 * @return {Promise<void>}
		 */
		async saveAndSubmit() {
			if (!this.submission) {
				return
			}
			this.saving = true
			this.saveError = null

			const rubricScores = this.buildRubricScores()
			const totalScore = this.computedScore
			const uid = getCurrentUser()?.uid ?? ''

			try {
				const payload = {
					assignmentId: this.assignment.id ?? this.assignmentId,
					submissionId: this.id,
					learnerId: uid,
					timing: this.timing,
					rubricScores,
					totalScore,
					comments: this.comments,
					tenant_id: this.submission.tenant_id ?? '',
				}

				const isUpdate = this.selfAssessment != null
				const saveUrl = isUpdate
					? generateUrl(`/apps/openregister/api/objects/scholiq/SelfAssessment/${this.selfAssessment.id}`)
					: generateUrl('/apps/openregister/api/objects/scholiq/SelfAssessment')

				const saveResp = await fetch(saveUrl, {
					method: isUpdate ? 'PUT' : 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(isUpdate ? { ...payload, id: this.selfAssessment.id } : payload),
				})
				if (!saveResp.ok) {
					throw new Error(`SelfAssessment save failed: ${saveResp.status}`)
				}

				const savedJson = await saveResp.json()
				const saved = savedJson.object ?? savedJson ?? {}
				const selfAssessmentId = saved.id ?? this.selfAssessment?.id

				if (!selfAssessmentId) {
					throw new Error('SelfAssessment save did not return an id')
				}

				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/SelfAssessment/${selfAssessmentId}/transition/submit`,
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
				this.saveError = this.t('scholiq', 'Failed to save self-assessment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SelfAssessmentView] saveAndSubmit error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.self-assessment-view {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.self-assessment-view__loading,
.self-assessment-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.self-assessment-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.self-assessment-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.self-assessment-view__rubric,
.self-assessment-view__comments {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.self-assessment-view__criterion {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.self-assessment-view__criterion-label {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.self-assessment-view__criterion-weight {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-left: 4px;
}

.self-assessment-view__levels {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
}

.self-assessment-view__level {
	display: flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
}

.self-assessment-view__level:has(input:checked) {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary);
}

.self-assessment-view__level-points {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.self-assessment-view__score-total {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-radius: 4px;
}

.self-assessment-view__comments-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
	font-family: inherit;
}

.self-assessment-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.self-assessment-view__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.self-assessment-view__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}
</style>
