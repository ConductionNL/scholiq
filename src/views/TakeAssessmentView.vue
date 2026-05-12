<!--
  TakeAssessmentView.vue
  Custom page component for the TakeAssessmentView manifest page (type: custom).

  Timed test-taking surface for a learner to attempt an Assessment:
  1. Fetch the Assessment (title, timeLimitMinutes, itemRefs, proctoring config).
  2. Create an AssessmentResult in `in-progress` state.
  3. If the Assessment is proctored, show a placeholder notice (no concrete adapter ships).
  4. Render each Item one at a time; record the learner's response.
  5. On submit: POST responses to the AssessmentResult and dispatch the `submit` transition
     (which triggers AssessmentScoringHandler auto-scoring on the OR side).

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="take-assessment">
		<!-- Loading -->
		<div v-if="loading"
			class="take-assessment__loading"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading assessment...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="take-assessment__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Confirmation -->
		<div v-else-if="submitted"
			class="take-assessment__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Assessment submitted!') }}</h2>
			<p>{{ t('scholiq', 'Your responses have been recorded. Auto-scored items are marked immediately. Essay items will be reviewed by your teacher.') }}</p>
		</div>

		<!-- Proctoring notice (when proctored but no adapter installed) -->
		<div v-else-if="showProctoringNotice" class="take-assessment__proctoring-notice" role="status">
			<span class="icon-warning" aria-hidden="true" />
			<h3>{{ t('scholiq', 'Proctoring configured') }}</h3>
			<p>{{ t('scholiq', 'This assessment is configured with proctoring (provider: {provider}). No proctoring adapter is installed — the assessment will proceed without live proctoring. Contact your administrator.', { provider: assessment.proctoring.provider }) }}</p>
			<button class="button-vue button-vue--primary" @click="dismissProctoringNotice">
				{{ t('scholiq', 'Continue without proctoring') }}
			</button>
		</div>

		<!-- Assessment header + item view -->
		<template v-else-if="assessment && resultId">
			<header class="take-assessment__header">
				<h2 class="take-assessment__title">
					{{ assessment.title }}
				</h2>
				<div class="take-assessment__meta">
					<span v-if="assessment.timeLimitMinutes" class="take-assessment__timer" :class="{ 'take-assessment__timer--warning': timeWarning }">
						{{ t('scholiq', 'Time remaining: {time}', { time: formattedTimeRemaining }) }}
					</span>
					<span class="take-assessment__progress">
						{{ t('scholiq', 'Item {current} of {total}', { current: currentItemIndex + 1, total: items.length }) }}
					</span>
				</div>
			</header>

			<!-- Item display -->
			<section v-if="currentItem" class="take-assessment__item" aria-live="polite">
				<h3 class="take-assessment__item-title">
					{{ currentItem.title }}
				</h3>

				<!-- Choice interaction -->
				<div v-if="currentItem.interactionType === 'choice'" class="take-assessment__choice">
					<p class="take-assessment__qti-body" v-html="extractPrompt(currentItem.qtiBody)" />
					<ul class="take-assessment__options" role="radiogroup">
						<li
							v-for="option in extractChoices(currentItem.qtiBody)"
							:key="option.id"
							class="take-assessment__option">
							<label>
								<input
									type="radio"
									:name="'item-' + currentItem.uuid"
									:value="option.id"
									:checked="currentResponse === option.id"
									@change="setResponse(option.id)">
								{{ option.label }}
							</label>
						</li>
					</ul>
				</div>

				<!-- Extended text (essay) interaction -->
				<div v-else-if="currentItem.interactionType === 'extendedText'" class="take-assessment__essay">
					<p class="take-assessment__qti-body" v-html="extractPrompt(currentItem.qtiBody)" />
					<textarea
						class="take-assessment__essay-input"
						rows="10"
						:placeholder="t('scholiq', 'Write your response here...')"
						:value="currentResponse || ''"
						@input="setResponse($event.target.value)" />
					<p class="take-assessment__essay-note">
						{{ t('scholiq', 'This item will be marked by your teacher.') }}
					</p>
				</div>

				<!-- Other interaction types — no in-browser editor yet -->
				<div v-else class="take-assessment__other-interaction">
					<p class="take-assessment__qti-body" v-html="extractPrompt(currentItem.qtiBody)" />
					<p class="take-assessment__interaction-note">
						{{ t('scholiq', 'Interaction type "{type}" — please provide your response:', { type: currentItem.interactionType }) }}
					</p>
					<textarea
						class="take-assessment__essay-input"
						rows="6"
						:placeholder="t('scholiq', 'Enter your response...')"
						:value="currentResponse || ''"
						@input="setResponse($event.target.value)" />
				</div>
			</section>

			<!-- Navigation -->
			<nav class="take-assessment__nav">
				<button
					class="button-vue"
					:disabled="currentItemIndex === 0"
					@click="prevItem">
					{{ t('scholiq', 'Previous') }}
				</button>
				<button
					v-if="currentItemIndex < items.length - 1"
					class="button-vue button-vue--primary"
					@click="nextItem">
					{{ t('scholiq', 'Next') }}
				</button>
				<button
					v-else
					class="button-vue button-vue--primary"
					:disabled="submitting"
					@click="submitAssessment">
					<span v-if="submitting" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Submit assessment') }}
				</button>
			</nav>
			<p v-if="submitError" role="alert" class="take-assessment__error-inline">
				{{ submitError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'TakeAssessmentView',

	props: {
		/**
		 * Assessment UUID from route :assessmentId param.
		 */
		assessmentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			assessment: null,
			/** @type {object[]} */
			items: [],
			/** @type {string|null} */
			resultId: null,
			/** @type {number} */
			currentItemIndex: 0,
			/** @type {Record<string, unknown>} */
			responses: {},
			loading: false,
			submitting: false,
			submitted: false,
			showProctoringNotice: false,
			error: null,
			submitError: null,
			/** @type {number|null} Seconds remaining; null = untimed */
			secondsRemaining: null,
			/** @type {number|null} Interval ID */
			timerInterval: null,
		}
	},

	computed: {
		/**
		 * Current item object.
		 * @return {object|null}
		 */
		currentItem() {
			return this.items[this.currentItemIndex] ?? null
		},

		/**
		 * Current response for the active item.
		 * @return {unknown}
		 */
		currentResponse() {
			const item = this.currentItem
			if (!item) return null
			return this.responses[item.uuid] ?? null
		},

		/**
		 * Formatted time remaining string (MM:SS).
		 * @return {string}
		 */
		formattedTimeRemaining() {
			if (this.secondsRemaining === null) return ''
			const m = Math.floor(this.secondsRemaining / 60)
			const s = this.secondsRemaining % 60
			return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
		},

		/**
		 * True when fewer than 5 minutes remain.
		 * @return {boolean}
		 */
		timeWarning() {
			return this.secondsRemaining !== null && this.secondsRemaining <= 300
		},
	},

	watch: {
		assessmentId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.init(newId)
				}
			},
		},
	},

	beforeDestroy() {
		this.clearTimer()
	},

	methods: {
		/**
		 * Initialise the view: load the assessment and create a result.
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 */
		async init(id) {
			this.loading = true
			this.error = null

			try {
				await this.loadAssessment(id)
				await this.loadItems()

				if (this.assessment?.proctoring?.provider) {
					this.showProctoringNotice = true
					return
				}

				await this.createResult(id)
				this.startTimer()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load assessment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] init error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Assessment object.
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 */
		async loadAssessment(id) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Assessment/${id}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Assessment fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.assessment = json.object ?? json ?? {}
		},

		/**
		 * Fetch Item objects for all itemRefs on the assessment.
		 *
		 * @return {Promise<void>}
		 */
		async loadItems() {
			const itemRefs = this.assessment?.itemRefs ?? []
			const itemIds = itemRefs.map((r) => r.itemId).filter(Boolean)
			if (itemIds.length === 0) {
				this.items = []
				return
			}

			// Fetch each item individually (OR REST supports single object fetch).
			const fetched = await Promise.all(
				itemIds.map(async (itemId) => {
					const url = generateUrl(`/apps/openregister/api/objects/scholiq/Item/${itemId}`)
					const resp = await fetch(url, {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					})
					if (!resp.ok) return null
					const json = await resp.json()
					return json.object ?? json ?? null
				}),
			)

			this.items = fetched.filter(Boolean)
		},

		/**
		 * Create a new AssessmentResult in `in-progress` state.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<void>}
		 */
		async createResult(assessmentId) {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'

			const url = generateUrl('/apps/openregister/api/objects/scholiq/AssessmentResult')
			const resp = await fetch(url, {
				method: 'POST',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					assessmentId,
					learnerId,
					attemptNumber: 1,
					responses: [],
					startedAt: new Date().toISOString(),
					lifecycle: 'in-progress',
					tenant_id: this.assessment?.tenant_id ?? '',
				}),
			})
			if (!resp.ok) {
				throw new Error(`AssessmentResult create failed: ${resp.status}`)
			}
			const json = await resp.json()
			const created = json.object ?? json ?? {}
			this.resultId = created.uuid ?? created.id
		},

		/**
		 * Start the countdown timer if the assessment has a time limit.
		 *
		 * @return {void}
		 */
		startTimer() {
			const minutes = this.assessment?.timeLimitMinutes ?? null
			if (!minutes) return

			this.secondsRemaining = minutes * 60
			this.timerInterval = setInterval(() => {
				if (this.secondsRemaining <= 0) {
					this.clearTimer()
					this.submitAssessment()
					return
				}

				this.secondsRemaining--
			}, 1000)
		},

		/**
		 * Clear the countdown timer.
		 *
		 * @return {void}
		 */
		clearTimer() {
			if (this.timerInterval !== null) {
				clearInterval(this.timerInterval)
				this.timerInterval = null
			}
		},

		/**
		 * Dismiss the proctoring notice and proceed with creating the result.
		 *
		 * @return {void}
		 */
		async dismissProctoringNotice() {
			this.showProctoringNotice = false
			this.loading = true
			try {
				await this.createResult(this.assessmentId)
				this.startTimer()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to start assessment. Please try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Set the response for the current item.
		 *
		 * @param {unknown} value Learner's response value
		 * @return {void}
		 */
		setResponse(value) {
			const item = this.currentItem
			if (!item) return
			this.responses = { ...this.responses, [item.uuid]: value }
		},

		/**
		 * Navigate to the previous item.
		 *
		 * @return {void}
		 */
		prevItem() {
			if (this.currentItemIndex > 0) {
				this.currentItemIndex--
			}
		},

		/**
		 * Navigate to the next item.
		 *
		 * @return {void}
		 */
		nextItem() {
			if (this.currentItemIndex < this.items.length - 1) {
				this.currentItemIndex++
			}
		},

		/**
		 * Submit the assessment: persist responses then dispatch the `submit` transition.
		 * The OR-side AssessmentScoringHandler auto-scores choice/textEntry items.
		 *
		 * @return {Promise<void>}
		 */
		async submitAssessment() {
			if (!this.resultId) return
			this.submitting = true
			this.submitError = null
			this.clearTimer()

			const responsesPayload = this.items.map((item) => ({
				itemId: item.uuid,
				response: { value: this.responses[item.uuid] ?? null },
				autoScore: null,
				manualScore: null,
			}))

			try {
				// Persist responses.
				const patchUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/AssessmentResult/${this.resultId}`,
				)
				const patchResp = await fetch(patchUrl, {
					method: 'PUT',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						responses: responsesPayload,
						submittedAt: new Date().toISOString(),
					}),
				})
				if (!patchResp.ok) {
					throw new Error(`Response save failed: ${patchResp.status}`)
				}

				// Dispatch submit transition (triggers AssessmentScoringHandler).
				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/AssessmentResult/${this.resultId}/transition/submit`,
				)
				const transitionResp = await fetch(transitionUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})
				if (!transitionResp.ok) {
					throw new Error(`Submit transition failed: ${transitionResp.status}`)
				}

				this.submitted = true
			} catch (err) {
				this.submitError = this.t('scholiq', 'Failed to submit assessment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] submitAssessment error', err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Extract a human-readable prompt from a QTI body string.
		 * Strips XML tags to extract plain text for display.
		 *
		 * @param {string} qtiBody Raw QTI XML/JSON body
		 * @return {string} Cleaned prompt text
		 */
		extractPrompt(qtiBody) {
			if (!qtiBody) return ''
			// Strip XML tags for minimal display — a proper QTI renderer would be richer.
			return qtiBody.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 500)
		},

		/**
		 * Extract choice options from a QTI choice interaction XML body.
		 * Returns an array of { id, label } objects.
		 *
		 * @param {string} qtiBody Raw QTI XML body
		 * @return {Array<{id: string, label: string}>}
		 */
		extractChoices(qtiBody) {
			if (!qtiBody) return []
			const parser = new DOMParser()
			const doc = parser.parseFromString(qtiBody, 'text/xml')
			const simpleChoices = doc.getElementsByTagName('simpleChoice')
			const choices = []
			for (const sc of simpleChoices) {
				choices.push({
					id: sc.getAttribute('identifier') ?? sc.textContent?.trim() ?? '',
					label: sc.textContent?.trim() ?? '',
				})
			}

			return choices.length > 0 ? choices : [{ id: 'a', label: 'Option A' }, { id: 'b', label: 'Option B' }]
		},
	},
}
</script>

<style scoped>
.take-assessment {
	max-width: 800px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__loading,
.take-assessment__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: var(--default-grid-baseline, 8px);
}

.take-assessment__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.take-assessment__meta {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-top: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.take-assessment__timer {
	font-weight: bold;
}

.take-assessment__timer--warning {
	color: var(--color-error);
}

.take-assessment__item {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__item-title {
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.take-assessment__options {
	list-style: none;
	padding: 0;
}

.take-assessment__option {
	padding: 4px 0;
}

.take-assessment__option label {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	cursor: pointer;
}

.take-assessment__essay-input {
	width: 100%;
	box-sizing: border-box;
	padding: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
	resize: vertical;
}

.take-assessment__essay-note,
.take-assessment__interaction-note {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: var(--default-grid-baseline, 8px);
	font-style: italic;
}

.take-assessment__nav {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	justify-content: space-between;
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}

.take-assessment__proctoring-notice {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__error-inline {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
