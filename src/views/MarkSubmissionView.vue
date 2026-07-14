<!--
  MarkSubmissionView.vue
  Custom page component for the MarkSubmissionView manifest page (type: custom).

  Teacher's rubric-marking surface for a Submission:
  1. Fetch the Submission + parent Assignment + attached Rubric.
  2. Show the submission's attachments (downloadable links).
  3. For each Rubric criterion, let the teacher pick a level.
  4. Sum up points into proposedGrade as each level is picked.
  5. On save: write rubricScores + proposedGrade to the Submission,
     then dispatch the `return` lifecycle transition.
  6. TODO(grading spec): emit GradeEntry once the grading spec is implemented.

  peer-and-self-assessment: also fetches (read-only) the Submission's
  PeerFeedbackSummary and the teacher's own SelfAssessment for context —
  displayed in a read-only panel, never editable here. When
  Assignment.peerReviewWeightPercent is set, a suggested blended score
  (teacherScore x (1 - w) + PeerFeedbackSummary.averageScore x w) is shown
  as a hint next to the teacher's own entry fields — display arithmetic
  only; it NEVER pre-fills or alters proposedGrade (grade authority stays
  with the teacher, per the assignments spec's "Marking a Submission emits
  a GradeEntry" requirement).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Submission/:id
    - GET  /api/objects/scholiq/Assignment/:id
    - GET  /api/objects/scholiq/Rubric/:id
    - GET  /api/objects/scholiq/PeerFeedbackSummary?filters[submissionId]=:id (read-only context)
    - GET  /api/objects/scholiq/SelfAssessment?filters[submissionId]=:id (read-only context)
    - PUT  /api/objects/scholiq/Submission/:id
    - POST /api/objects/scholiq/Submission/:id/transition/return

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-marksubmissionview-shows-peer-and-self-assessment-as-read-only-context
  @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-configured-peer-review-weight-only-suggests-never-writes-a-blended-score
-->

<template>
	<div class="mark-submission-view">
		<!-- Loading -->
		<div v-if="loading" class="mark-submission-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading submission...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="mark-submission-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Returned confirmation -->
		<div v-else-if="returned"
			class="mark-submission-view__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Submission returned to learner') }}</h2>
			<p>{{ t('scholiq', 'Grade: {grade} / {max}', { grade: savedGrade, max: assignment.maxPoints || '?' }) }}</p>
		</div>

		<!-- Marking form -->
		<template v-else-if="submission">
			<!-- Header -->
			<header class="mark-submission-view__header">
				<h2>{{ t('scholiq', 'Mark submission') }}</h2>
				<p class="mark-submission-view__meta">
					{{ t('scholiq', 'Assignment: {title}', { title: assignment.title || '' }) }}
					<span
						v-if="submission.lifecycle === 'late'"
						class="mark-submission-view__late-badge">
						{{ t('scholiq', 'Late — {penalty}% penalty', { penalty: assignment.latePenaltyPercent || 0 }) }}
					</span>
				</p>
				<p class="mark-submission-view__learners">
					{{ t('scholiq', 'Learner(s): {ids}', { ids: (submission.learnerIds || []).join(', ') }) }}
				</p>
			</header>

			<!-- Attachments -->
			<section class="mark-submission-view__attachments">
				<h3>{{ t('scholiq', 'Submitted files') }}</h3>
				<ul v-if="(submission.attachmentRefs || []).length > 0" class="mark-submission-view__file-list">
					<li
						v-for="ref in submission.attachmentRefs"
						:key="ref"
						class="mark-submission-view__file-item">
						<span class="icon-file" aria-hidden="true" />
						<span class="mark-submission-view__file-ref">{{ ref }}</span>
					</li>
				</ul>
				<p v-else class="mark-submission-view__no-files">
					{{ t('scholiq', 'No files attached.') }}
				</p>
			</section>

			<!-- Rubric marking (shown only when a Rubric is attached) -->
			<section v-if="rubric && rubric.criteria && rubric.criteria.length > 0" class="mark-submission-view__rubric">
				<h3>{{ t('scholiq', 'Rubric: {name}', { name: rubric.name || '' }) }}</h3>

				<div
					v-for="criterion in rubric.criteria"
					:key="criterion.criterionId"
					class="mark-submission-view__criterion">
					<h4 class="mark-submission-view__criterion-label">
						{{ criterion.label }}
						<span class="mark-submission-view__criterion-weight">
							{{ t('scholiq', '(weight: {w})', { w: criterion.weight }) }}
						</span>
					</h4>
					<div class="mark-submission-view__levels">
						<label
							v-for="level in criterion.levels"
							:key="level.levelId"
							class="mark-submission-view__level">
							<input
								type="radio"
								:name="criterion.criterionId"
								:value="level.levelId"
								:checked="getSelectedLevel(criterion.criterionId) === level.levelId"
								:disabled="saving"
								@change="selectLevel(criterion, level)">
							<span class="mark-submission-view__level-label">{{ level.label }}</span>
							<span class="mark-submission-view__level-points">
								{{ t('scholiq', '{pts} pts', { pts: level.points }) }}
							</span>
						</label>
					</div>
				</div>

				<!-- Running total -->
				<div class="mark-submission-view__score-total">
					<strong>{{ t('scholiq', 'Score: {score} / {max}', { score: computedScore, max: assignment.maxPoints || '?' }) }}</strong>
					<span v-if="submission.lifecycle === 'late'" class="mark-submission-view__effective-grade">
						{{ t('scholiq', 'Effective grade after late penalty: {grade}', { grade: effectiveGrade }) }}
					</span>
				</div>
			</section>

			<!-- No rubric — manual score entry -->
			<section v-else class="mark-submission-view__manual-score">
				<h3>{{ t('scholiq', 'Manual score') }}</h3>
				<label for="manual-grade" class="mark-submission-view__score-label">
					{{ t('scholiq', 'Proposed grade (0 – {max})', { max: assignment.maxPoints || '?' }) }}
				</label>
				<input
					id="manual-grade"
					v-model.number="manualGrade"
					type="number"
					min="0"
					:max="assignment.maxPoints || undefined"
					class="mark-submission-view__score-input"
					:disabled="saving">
			</section>

			<!-- Feedback text -->
			<section class="mark-submission-view__feedback">
				<h3>{{ t('scholiq', 'Teacher feedback') }}</h3>
				<textarea
					v-model="feedbackText"
					class="mark-submission-view__feedback-input"
					:placeholder="t('scholiq', 'Write feedback for the learner...')"
					:disabled="saving"
					rows="5" />
			</section>

			<!--
				Peer/self-assessment read-only context (peer-and-self-assessment).
				Never editable here — grade authority stays with the teacher.
			-->
			<section
				v-if="peerFeedbackSummary || selfAssessment"
				class="mark-submission-view__peer-context"
				aria-label="Peer and self-assessment context">
				<h3>{{ t('scholiq', 'Peer & self-assessment context') }}</h3>

				<div v-if="peerFeedbackSummary" class="mark-submission-view__peer-summary">
					<p class="mark-submission-view__peer-summary-line">
						{{ t('scholiq', '{count} peer review(s), average score {avg}', {
							count: peerFeedbackSummary.reviewCount || 0,
							avg: peerFeedbackSummary.averageScore != null ? peerFeedbackSummary.averageScore : t('scholiq', 'n/a'),
						}) }}
					</p>
					<ul v-if="(peerFeedbackSummary.feedbackItems || []).length > 0" class="mark-submission-view__peer-items">
						<li
							v-for="(item, index) in peerFeedbackSummary.feedbackItems"
							:key="index"
							class="mark-submission-view__peer-item">
							<span class="mark-submission-view__peer-item-reviewer">
								{{ item.reviewerId ? item.reviewerId : t('scholiq', 'Anonymous reviewer') }}
							</span>
							<span v-if="item.comments" class="mark-submission-view__peer-item-comment">{{ item.comments }}</span>
						</li>
					</ul>
				</div>

				<div v-if="selfAssessment" class="mark-submission-view__self-summary">
					<p class="mark-submission-view__self-summary-line">
						{{ t('scholiq', 'Learner self-assessment score: {score}', {
							score: selfAssessment.totalScore != null ? selfAssessment.totalScore : t('scholiq', 'n/a'),
						}) }}
					</p>
					<p v-if="selfAssessment.comments" class="mark-submission-view__self-summary-comment">
						{{ selfAssessment.comments }}
					</p>
				</div>

				<!--
					Advisory-only suggestion — display arithmetic, never written to
					proposedGrade. Only shown when Assignment.peerReviewWeightPercent
					is set AND a peer average score exists.
				-->
				<p v-if="blendedSuggestion != null" class="mark-submission-view__blended-suggestion">
					{{ t('scholiq', 'Suggested blended score ({weight}% peer weight): {value}', {
						weight: assignment.peerReviewWeightPercent,
						value: blendedSuggestion,
					}) }}
				</p>
			</section>

			<!-- Actions -->
			<div class="mark-submission-view__actions">
				<button
					class="button-vue button-vue--primary mark-submission-view__save-btn"
					:disabled="saving"
					@click="saveAndReturn">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Save & return to learner') }}
				</button>
			</div>
			<p v-if="saveError" role="alert" class="mark-submission-view__save-error">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'MarkSubmissionView',

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
			/**
			 * Map of criterionId → { levelId, points }
			 *
			 * @type {Record<string, { levelId: string, points: number }>}
			 */
			selectedLevels: {},
			/** @type {string} */
			feedbackText: '',
			/** @type {number|null} */
			manualGrade: null,
			/** @type {object|null} Read-only peer-and-self-assessment context. */
			peerFeedbackSummary: null,
			/** @type {object|null} Read-only peer-and-self-assessment context. */
			selfAssessment: null,
			loading: false,
			saving: false,
			returned: false,
			savedGrade: null,
			error: null,
			saveError: null,
		}
	},

	computed: {
		/**
		 * Sum of points for all selected criterion levels.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		computedScore() {
			return Object.values(this.selectedLevels).reduce(
				(sum, sel) => sum + (sel.points || 0),
				0,
			)
		},

		/**
		 * Grade after applying the late penalty (when submission is late).
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		effectiveGrade() {
			const penalty = this.assignment.latePenaltyPercent || 0
			const grade = this.rubric ? this.computedScore : (this.manualGrade || 0)
			return Math.round(grade * (1 - penalty / 100) * 100) / 100
		},

		/**
		 * peer-and-self-assessment: advisory-only blended score suggestion —
		 * `teacherScore x (1 - w) + PeerFeedbackSummary.averageScore x w` — shown
		 * as display-only arithmetic next to the teacher's own entry fields.
		 * Never written to `Submission.proposedGrade`; null (nothing shown) unless
		 * `Assignment.peerReviewWeightPercent` is set AND a peer average score
		 * exists.
		 *
		 * @return {number|null}
		 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-configured-peer-review-weight-only-suggests-never-writes-a-blended-score
		 */
		blendedSuggestion() {
			const weightPercent = this.assignment.peerReviewWeightPercent
			const averageScore = this.peerFeedbackSummary ? this.peerFeedbackSummary.averageScore : null

			if (weightPercent == null || averageScore == null) {
				return null
			}

			const teacherScore = this.rubric ? this.computedScore : (this.manualGrade || 0)
			const w = weightPercent / 100
			return Math.round((teacherScore * (1 - w) + averageScore * w) * 100) / 100
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the submission id prop changing by loading all data.
			 *
			 * @param {string} newId New submission UUID
			 * @return {void}
			 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
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
		 * Load the Submission, Assignment, and optional Rubric.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
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

				// peer-and-self-assessment: read-only context, best-effort (a
				// missing/unauthorized fetch just means the panel doesn't render).
				await this.loadPeerFeedbackSummary(submissionId)
				await this.loadSelfAssessment(submissionId)

				// Pre-fill existing rubric scores if already partially marked
				const existingScores = this.submission.rubricScores ?? []
				for (const score of existingScores) {
					this.selectedLevels[score.criterionId] = {
						levelId: score.levelId,
						points: score.points,
					}
				}
				this.feedbackText = this.submission.feedbackText ?? ''
				if (this.submission.proposedGrade != null) {
					this.manualGrade = this.submission.proposedGrade
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load submission. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[MarkSubmissionView] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Submission from OR.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		async loadSubmission(submissionId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/Submission/${submissionId}`,
			)
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
		 * @param {string} id Assignment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		async loadAssignment(id) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Assignment/${id}`)
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		async loadRubric(rubricId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/Rubric/${rubricId}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// Non-fatal — fall back to manual scoring
				return
			}
			const json = await resp.json()
			this.rubric = json.object ?? json ?? null
		},

		/**
		 * Fetch the Submission's PeerFeedbackSummary from OR (read-only context).
		 * feedbackItems[].reviewerId is already server-computed as null when
		 * blind/double-blind — this view renders whatever the server returns
		 * without any client-side redaction of its own.
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-marksubmissionview-shows-peer-and-self-assessment-as-read-only-context
		 */
		async loadPeerFeedbackSummary(submissionId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/PeerFeedbackSummary?filters[submissionId]=${submissionId}&limit=1`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// Non-fatal — the panel simply doesn't render this section.
				return
			}
			const json = await resp.json()
			const results = json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
			this.peerFeedbackSummary = results.length > 0 ? results[0] : null
		},

		/**
		 * Fetch the learner's SelfAssessment for this Submission from OR
		 * (read-only context).
		 *
		 * @param {string} submissionId Submission UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-marksubmissionview-shows-peer-and-self-assessment-as-read-only-context
		 */
		async loadSelfAssessment(submissionId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/SelfAssessment?filters[submissionId]=${submissionId}&limit=1`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// Non-fatal — the panel simply doesn't render this section.
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
		 * Record the teacher's level selection for a criterion.
		 *
		 * @param {object} criterion Rubric criterion object
		 * @param {object} level     Selected level object
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		buildRubricScores() {
			return Object.entries(this.selectedLevels).map(([criterionId, sel]) => ({
				criterionId,
				levelId: sel.levelId,
				points: sel.points,
			}))
		},

		/**
		 * Save rubricScores + proposedGrade + feedbackText to the Submission,
		 * create a concept GradeEntry (grading spec bridge), and dispatch
		 * the `return` lifecycle transition.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-28
		 */
		async saveAndReturn() {
			if (!this.submission) {
				return
			}
			this.saving = true
			this.saveError = null

			const proposedGrade = this.rubric ? this.computedScore : (this.manualGrade ?? null)
			const rubricScores = this.rubric ? this.buildRubricScores() : []

			try {
				// 1. Persist marking data to the Submission
				const updateUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/Submission/${this.id}`,
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
						proposedGrade,
						feedbackText: this.feedbackText,
					}),
				})
				if (!updateResp.ok) {
					throw new Error(`Submission update failed: ${updateResp.status}`)
				}

				// 2. Create a concept GradeEntry (grading spec — sourceKind: assignment-submission).
				// The teacher reviews and publishes it via the GradebookView; only then does
				// the notification fire and the FinalGrade recompute trigger.
				const componentId = this.assignment.curriculumPlanComponentId ?? null
				const planId = this.assignment.curriculumPlanId ?? null
				if (proposedGrade !== null && componentId && planId) {
					const gradeEntryUrl = generateUrl(
						'/apps/openregister/api/objects/scholiq/GradeEntry',
					)
					const gradeEntryResp = await fetch(gradeEntryUrl, {
						method: 'POST',
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							learnerId: (this.submission.learnerIds ?? [])[0] ?? '',
							curriculumPlanId: planId,
							componentId,
							sourceKind: 'assignment-submission',
							submissionId: this.id,
							value: proposedGrade,
							gradeScaleId: this.assignment.gradeScaleId ?? '',
							grader: '',
							gradedAt: new Date().toISOString(),
							lifecycle: 'concept',
							tenant_id: this.submission.tenant_id ?? '',
						}),
					})
					if (gradeEntryResp.ok) {
						const gradeEntryJson = await gradeEntryResp.json()
						const gradeEntryId = (gradeEntryJson.object ?? gradeEntryJson)?.id ?? null
						if (gradeEntryId) {
							// Back-link the GradeEntry to the Submission.
							const linkUrl = generateUrl(
								`/apps/openregister/api/objects/scholiq/Submission/${this.id}`,
							)
							await fetch(linkUrl, {
								method: 'PUT',
								headers: {
									'OCS-APIREQUEST': 'true',
									Accept: 'application/json',
									'Content-Type': 'application/json',
								},
								body: JSON.stringify({ gradeEntryId }),
							})
						}
					}
					// Non-fatal: GradeEntry creation failure does not block the return transition.
				}

				// 3. Dispatch `return` lifecycle transition
				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/Submission/${this.id}/transition/return`,
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
					throw new Error(`Return transition failed: ${transResp.status}`)
				}

				this.savedGrade = proposedGrade
				this.returned = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save marking. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[MarkSubmissionView] saveAndReturn error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.mark-submission-view {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.mark-submission-view__loading,
.mark-submission-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.mark-submission-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.mark-submission-view__meta,
.mark-submission-view__learners {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.mark-submission-view__late-badge {
	display: inline-block;
	margin-left: var(--default-grid-baseline, 8px);
	padding: 2px 6px;
	background-color: var(--color-warning);
	color: var(--color-main-background);
	border-radius: 3px;
	font-size: 0.85em;
}

.mark-submission-view__attachments,
.mark-submission-view__rubric,
.mark-submission-view__manual-score,
.mark-submission-view__feedback {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.mark-submission-view__file-list {
	list-style: none;
	padding: 0;
}

.mark-submission-view__file-item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.mark-submission-view__no-files {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.mark-submission-view__criterion {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.mark-submission-view__criterion-label {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.mark-submission-view__criterion-weight {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-left: 4px;
}

.mark-submission-view__levels {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
}

.mark-submission-view__level {
	display: flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
}

.mark-submission-view__level:has(input:checked) {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary);
}

.mark-submission-view__level-points {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.mark-submission-view__score-total {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-radius: 4px;
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
}

.mark-submission-view__effective-grade {
	color: var(--color-warning);
	font-size: 0.9em;
}

.mark-submission-view__score-label {
	display: block;
	margin-bottom: 4px;
}

.mark-submission-view__score-input {
	width: 120px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.mark-submission-view__feedback-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 8px;
	resize: vertical;
	font-family: inherit;
}

.mark-submission-view__peer-context {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	padding: var(--default-grid-baseline, 8px);
	border: 1px dashed var(--color-border);
	border-radius: 4px;
	background: var(--color-background-hover);
}

.mark-submission-view__peer-items {
	list-style: none;
	padding: 0;
	margin: var(--default-grid-baseline, 8px) 0 0;
}

.mark-submission-view__peer-item {
	display: flex;
	flex-direction: column;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.mark-submission-view__peer-item-reviewer {
	font-weight: bold;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.mark-submission-view__self-summary {
	margin-top: var(--default-grid-baseline, 8px);
}

.mark-submission-view__blended-suggestion {
	margin-top: var(--default-grid-baseline, 8px);
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.mark-submission-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.mark-submission-view__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.mark-submission-view__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}
</style>
