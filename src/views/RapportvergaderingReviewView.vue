<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RapportvergaderingReviewView — report-card-composer.

 The cohort-wide grid used during the rapportvergadering (grade-deliberation
 meeting): one row per learner's ReportCard for a ReportPeriod, one column
 per in-scope subject showing periodAverage/passed, inline
 teacherComment/mentorComment editing, and the finalise/reopen/publishToParents
 lifecycle actions. No manifest page can express a cohort-wide grid — mirrors
 GradebookView's existing precedent for the same reason.

 For an ReportPeriod still `open`, this view shows the period summary and a
 "Compose" button opening ComposeReportPeriodModal (the confirmation dialog).
 Once `composed`, the review grid renders.

 Route param: :id (ReportPeriod UUID).

 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
 @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-publishtoparents-must-not-surface-a-grade-before-its-own-scheduled-visibility-window
-->
<template>
	<div class="rapportvergadering-review">
		<h2>{{ t('scholiq', 'Rapportvergadering review') }}</h2>

		<NcLoadingIcon v-if="loadingPeriod" :size="32" />

		<template v-else-if="period">
			<p class="rapportvergadering-review__meta">
				{{ period.name }} — {{ period.academicYear }} ({{ t('scholiq', 'period {code}', { code: period.periodCode }) }})
			</p>

			<div v-if="period.lifecycle === 'open'" class="rapportvergadering-review__compose">
				<NcNoteCard type="info">
					{{ t('scholiq', 'Report cards have not been composed yet for this period.') }}
				</NcNoteCard>
				<NcButton type="primary" @click="showComposeModal = true">
					{{ t('scholiq', 'Compose report cards…') }}
				</NcButton>
			</div>

			<template v-else>
				<NcLoadingIcon v-if="loadingCards" :size="32" />

				<NcEmptyContent v-else-if="cards.length === 0"
					:name="t('scholiq', 'No report cards')"
					:description="t('scholiq', 'This period is composed but no report cards were generated.')" />

				<div v-else class="rapportvergadering-review__table-wrap">
					<table class="rapportvergadering-review__table">
						<thead>
							<tr>
								<th>{{ t('scholiq', 'Learner') }}</th>
								<th v-for="plan in subjectColumns" :key="plan.id">
									{{ plan.label }}
								</th>
								<th>{{ t('scholiq', 'Mentor comment') }}</th>
								<th>{{ t('scholiq', 'Status') }}</th>
								<th>{{ t('scholiq', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="card in cards" :key="card.id || card.uuid">
								<td>{{ card.learnerId }}</td>
								<td v-for="plan in subjectColumns" :key="plan.id">
									<template v-if="subjectRow(card, plan.id)">
										<div class="rapportvergadering-review__cell">
											<strong>{{ formatAverage(subjectRow(card, plan.id).periodAverage) }}</strong>
											<span v-if="subjectRow(card, plan.id).passed === false" class="rapportvergadering-review__fail">
												{{ t('scholiq', 'fail') }}
											</span>
											<textarea
												:value="subjectRow(card, plan.id).teacherComment"
												:aria-label="t('scholiq', 'Teacher comment for {subject}', { subject: plan.label })"
												:disabled="!isEditable(card)"
												rows="2"
												@change="onTeacherCommentChange(card, plan.id, $event.target.value)" />
										</div>
									</template>
									<span v-else class="rapportvergadering-review__empty">—</span>
								</td>
								<td>
									<textarea
										:value="card.mentorComment"
										:aria-label="t('scholiq', 'Mentor comment for {learner}', { learner: card.learnerId })"
										:disabled="!isEditable(card)"
										rows="2"
										@change="onMentorCommentChange(card, $event.target.value)" />
								</td>
								<td>{{ card.lifecycle }}</td>
								<td>
									<div class="rapportvergadering-review__actions">
										<NcButton v-if="card.lifecycle === 'draft'"
											type="tertiary"
											:disabled="!!transitioning[cardId(card)]"
											@click="transition(card, 'rapportvergadering-review')">
											{{ t('scholiq', 'Pull into review') }}
										</NcButton>
										<NcButton v-if="card.lifecycle === 'rapportvergadering-review'"
											type="tertiary"
											:disabled="!!transitioning[cardId(card)]"
											@click="transition(card, 'finalised')">
											{{ t('scholiq', 'Finalise') }}
										</NcButton>
										<NcButton v-if="card.lifecycle === 'finalised'"
											type="tertiary"
											:disabled="!!transitioning[cardId(card)]"
											@click="transition(card, 'rapportvergadering-review')">
											{{ t('scholiq', 'Reopen') }}
										</NcButton>
										<NcButton v-if="card.lifecycle === 'finalised'"
											type="primary"
											:disabled="!!transitioning[cardId(card)]"
											@click="transition(card, 'published-to-parents')">
											{{ t('scholiq', 'Publish to parents') }}
										</NcButton>
									</div>
									<NcNoteCard v-if="cardErrors[cardId(card)]" type="error">
										{{ cardErrors[cardId(card)] }}
									</NcNoteCard>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</template>

		<NcNoteCard v-else type="error">
			{{ t('scholiq', 'Could not load this report period.') }}
		</NcNoteCard>

		<ComposeReportPeriodModal v-if="showComposeModal"
			:report-period-id="id"
			@close="showComposeModal = false"
			@composed="onComposed" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ComposeReportPeriodModal from '../dialogs/ComposeReportPeriodModal.vue'

export default {
	name: 'RapportvergaderingReviewView',

	components: {
		ComposeReportPeriodModal,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/**
		 * ReportPeriod UUID injected by vue-router from the :id route param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loadingPeriod: true,
			loadingCards: false,
			period: null,
			cards: [],
			curriculumPlans: {},
			showComposeModal: false,
			transitioning: {},
			cardErrors: {},
		}
	},

	computed: {
		/**
		 * One column per in-scope CurriculumPlan, in ReportPeriod.curriculumPlanIds order.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		subjectColumns() {
			if (!this.period) return []
			return (this.period.curriculumPlanIds || []).map((planId) => ({
				id: planId,
				label: (this.curriculumPlans[planId] && this.curriculumPlans[planId].name) || planId,
			}))
		},
	},

	async mounted() {
		await this.loadPeriod()
	},

	methods: {
		t,

		/**
		 * Load the governing ReportPeriod, then (once composed) its
		 * CurriculumPlan labels and ReportCards.
		 *
		 * @return {Promise<void>}
		 */
		async loadPeriod() {
			this.loadingPeriod = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/report-period/{id}', { id: this.id })
				const response = await axios.get(url)
				this.period = response.data || null
			} catch (e) {
				console.error('[RapportvergaderingReviewView] loadPeriod failed', e)
				this.period = null
			} finally {
				this.loadingPeriod = false
			}

			if (this.period && this.period.lifecycle !== 'open') {
				await Promise.all([this.loadCurriculumPlans(), this.loadCards()])
			}
		},

		/**
		 * Resolve display labels for the period's in-scope CurriculumPlans.
		 *
		 * @return {Promise<void>}
		 */
		async loadCurriculumPlans() {
			const planIds = (this.period && this.period.curriculumPlanIds) || []
			const plans = {}
			await Promise.all(
				planIds.map(async (planId) => {
					try {
						const url = generateUrl('/apps/openregister/api/objects/scholiq/curriculum-plan/{id}', { id: planId })
						const response = await axios.get(url)
						plans[planId] = response.data || null
					} catch (e) {
						console.error('[RapportvergaderingReviewView] loadCurriculumPlans failed for', planId, e)
					}
				}),
			)
			this.curriculumPlans = plans
		},

		/**
		 * Load every ReportCard composed for this ReportPeriod.
		 *
		 * @return {Promise<void>}
		 */
		async loadCards() {
			this.loadingCards = true
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/report-card?reportPeriodId={periodId}&limit=500',
					{ periodId: this.id },
				)
				const response = await axios.get(url)
				this.cards = (response.data && (response.data.results || response.data.objects)) || response.data || []
			} catch (e) {
				console.error('[RapportvergaderingReviewView] loadCards failed', e)
				this.cards = []
			} finally {
				this.loadingCards = false
			}
		},

		/**
		 * Called when ComposeReportPeriodModal successfully triggers `compose`.
		 *
		 * @return {Promise<void>}
		 */
		async onComposed() {
			await this.loadPeriod()
		},

		/**
		 * The subjectGrades[] row for a given curriculumPlanId, or undefined
		 * when that subject contributed no row (no matching period component).
		 *
		 * @param {object} card       A ReportCard.
		 * @param {string} planId CurriculumPlan UUID.
		 * @return {object|undefined}
		 */
		subjectRow(card, planId) {
			return (card.subjectGrades || []).find((row) => row.curriculumPlanId === planId)
		},

		/**
		 * A card's editable state — only while it has not yet been published
		 * to parents (draft, rapportvergadering-review, and finalised — a
		 * mentor may still tweak a finalised card by reopening it first, but
		 * this view allows direct edits up to finalised for correction speed;
		 * the field itself is not what gates parent visibility, publishToParents is).
		 *
		 * @param {object} card A ReportCard.
		 * @return {boolean}
		 */
		isEditable(card) {
			return card.lifecycle !== 'published-to-parents'
		},

		/**
		 * Stable string id for a card (id or uuid).
		 *
		 * @param {object} card A ReportCard.
		 * @return {string}
		 */
		cardId(card) {
			return card.id || card.uuid || ''
		},

		/**
		 * Format a nullable numeric average for display.
		 *
		 * @param {number|null} value The periodAverage.
		 * @return {string}
		 */
		formatAverage(value) {
			if (value === null || value === undefined) return '—'
			return Number(value).toFixed(1)
		},

		/**
		 * Persist a per-subject teacherComment edit.
		 *
		 * @param {object} card   The ReportCard being edited.
		 * @param {string} planId CurriculumPlan UUID of the edited row.
		 * @param {string} value  New comment text.
		 * @return {Promise<void>}
		 */
		async onTeacherCommentChange(card, planId, value) {
			const row = this.subjectRow(card, planId)
			if (!row) return
			row.teacherComment = value
			await this.saveCard(card)
		},

		/**
		 * Persist a mentorComment edit.
		 *
		 * @param {object} card  The ReportCard being edited.
		 * @param {string} value New comment text.
		 * @return {Promise<void>}
		 */
		async onMentorCommentChange(card, value) {
			card.mentorComment = value
			await this.saveCard(card)
		},

		/**
		 * Persist the current in-memory card state (field edits only — never
		 * changes `lifecycle`, see transition()).
		 *
		 * @param {object} card The ReportCard to save.
		 * @return {Promise<void>}
		 */
		async saveCard(card) {
			const id = this.cardId(card)
			if (!id) return
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/report-card/{id}', { id })
				await axios.put(url, card)
			} catch (e) {
				console.error('[RapportvergaderingReviewView] saveCard failed', e)
				this.$set(this.cardErrors, id, t('scholiq', 'Could not save changes. Please try again.'))
			}
		},

		/**
		 * Trigger a ReportCard lifecycle transition by writing the target
		 * `lifecycle` value through OR's object API.
		 *
		 * @param {object} card         The ReportCard.
		 * @param {string} toLifecycle  Target lifecycle state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
		 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
		 */
		async transition(card, toLifecycle) {
			const id = this.cardId(card)
			if (!id) return

			this.$set(this.transitioning, id, true)
			this.$set(this.cardErrors, id, '')
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/report-card/{id}', { id })
				await axios.put(url, { lifecycle: toLifecycle })
				await this.loadCards()
			} catch (e) {
				console.error('[RapportvergaderingReviewView] transition failed', e)
				this.$set(this.cardErrors, id, t('scholiq', 'This action was blocked. Please check the requirements and try again.'))
			} finally {
				this.$set(this.transitioning, id, false)
			}
		},
	},
}
</script>

<style scoped>
.rapportvergadering-review {
	padding: 1rem;
}

.rapportvergadering-review__meta {
	color: var(--color-text-maxcontrast);
	margin-bottom: 1rem;
}

.rapportvergadering-review__compose {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
	max-width: 480px;
}

.rapportvergadering-review__table-wrap {
	overflow-x: auto;
}

.rapportvergadering-review__table {
	width: 100%;
	border-collapse: collapse;
}

.rapportvergadering-review__table th,
.rapportvergadering-review__table td {
	border: 1px solid var(--color-border);
	padding: 8px;
	vertical-align: top;
	text-align: left;
}

.rapportvergadering-review__cell {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rapportvergadering-review__fail {
	color: var(--color-error);
	font-size: 12px;
	font-weight: 600;
}

.rapportvergadering-review__empty {
	color: var(--color-text-maxcontrast);
}

.rapportvergadering-review__actions {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
