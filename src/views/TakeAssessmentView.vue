<!--
  TakeAssessmentView.vue
  Custom page component for the TakeAssessmentView manifest page (type: custom).

  Timed test-taking surface for a learner to attempt an Assessment:
  1. Fetch the Assessment (title, timeLimitMinutes, proctoring config).
  2. Create (or resume) an AssessmentResult in `in-progress` state, then re-fetch
     it by id — AssessmentDrawResolver (server-side) resolves and persists
     `drawnItemRefs`, the frozen item set/order/answer-option-order this
     attempt presents, regardless of fixed/random-draw or shuffle settings.
  3. If the Assessment is proctored, show a placeholder notice (no concrete adapter ships).
  4. Render each Item from drawnItemRefs one at a time (choice options in
     drawnItemRefs[].optionOrder when present); record the learner's response.
  5. On submit: POST responses to the AssessmentResult and dispatch the `submit` transition
     (which triggers AssessmentScoringHandler auto-scoring on the OR side).

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
  @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
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

		<!-- Native test-mode pre-start instructions (design §3.2) -->
		<div v-else-if="showTestModeIntro" class="take-assessment__test-mode-intro" role="status">
			<span class="icon-info" aria-hidden="true" />
			<h3>{{ t('scholiq', 'Secure test mode') }}</h3>
			<ul class="take-assessment__test-mode-intro-list">
				<li v-if="assessment.proctoring.lockdownBrowser">
					{{ t('scholiq', 'Fullscreen is required while this test is open.') }}
				</li>
				<li>
					{{ t('scholiq', 'Switching tabs, minimising the window, or losing focus is logged and reviewed by your teacher — nothing is acted on automatically.') }}
				</li>
				<li>
					{{ t('scholiq', 'No camera, microphone, or screen content outside this page is captured.') }}
				</li>
				<li>
					{{ t('scholiq', 'This test can only be open in one browser tab or window at a time.') }}
				</li>
			</ul>
			<button class="button-vue button-vue--primary" @click="startNativeTestMode">
				{{ t('scholiq', 'Start') }}
			</button>
		</div>

		<!-- Blocked: the same attempt is already open in another tab (design §3.3) -->
		<div v-else-if="showTabLockBlocked" class="take-assessment__tab-lock-blocked" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<h3>{{ t('scholiq', 'Already open in another tab') }}</h3>
			<p>{{ t('scholiq', 'This assessment is already open in another browser tab or window. Close this tab and continue there.') }}</p>
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
							v-for="option in extractChoices(currentItem.qtiBody, currentItemOptionOrder)"
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
			/**
			 * Full AssessmentResult object (re-fetched after create — never
			 * trusted from the POST response body, since AssessmentDrawResolver
			 * populates drawnItemRefs as a follow-up write).
			 * @type {object|null}
			 */
			result: null,
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
			showTestModeIntro: false,
			showTabLockBlocked: false,
			nativeTestModeActive: false,
			proctoringSession: null,
			tabId: null,
			tabLockKey: null,
			tabLockInterval: null,
			lastFlagAt: {},
			nativeHandlers: {},
		}
	},

	computed: {
		/**
		 * Current item object.
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		currentItem() {
			return this.items[this.currentItemIndex] ?? null
		},

		/**
		 * Current response for the active item.
		 * @return {unknown}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		currentResponse() {
			const item = this.currentItem
			if (!item) return null
			return this.responses[item.uuid] ?? null
		},

		/**
		 * Server-resolved answer-option order for the current item, from
		 * AssessmentResult.drawnItemRefs[].optionOrder (null when shuffle is
		 * disabled or the item has no discrete choice identifiers).
		 * @return {string[]|null}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
		 */
		currentItemOptionOrder() {
			const item = this.currentItem
			if (!item) return null
			const drawnItemRefs = this.result?.drawnItemRefs ?? []
			const ref = drawnItemRefs.find((r) => r.itemId === item.uuid)
			return ref?.optionOrder ?? null
		},

		/**
		 * Formatted time remaining string (MM:SS).
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		timeWarning() {
			return this.secondsRemaining !== null && this.secondsRemaining <= 300
		},
	},

	watch: {
		assessmentId: {
			immediate: true,
			/**
			 * React to assessmentId prop changes by (re)initialising the view.
			 *
			 * @param {string} newId New assessment UUID
			 * @return {void}
			 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
			 */
			handler(newId) {
				if (newId) {
					this.init(newId)
				}
			},
		},
	},

	/**
	 * Vue lifecycle hook: stop the countdown timer and, for a native test-mode
	 * attempt, best-effort teardown (design §3.5) — detach listeners, release
	 * the tab lock, and beacon an `end` transition if the browser is closing
	 * mid-attempt without a successful submit.
	 *
	 * @return {void}
	 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
	 */
	beforeDestroy() {
		this.clearTimer()

		if (!this.nativeTestModeActive) return

		this.detachNativeHardening()
		this.releaseTabLock()

		// Best-effort teardown for a browser closed/navigated-away mid-attempt: a
		// synchronous fetch cannot be guaranteed to complete during unload, so use
		// sendBeacon (POST-only, which matches the transition endpoint's method)
		// where supported. If it doesn't land, the session is simply left `active`
		// with no `end` transition recorded — itself informative to a reviewer.
		if (!this.submitted && this.proctoringSession?.uuid
			&& typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
			const url = generateUrl(
				`/apps/openregister/api/objects/scholiq/ProctoringSession/${this.proctoringSession.uuid}/transition/end`,
			)
			const blob = new Blob([JSON.stringify({})], { type: 'application/json' })
			navigator.sendBeacon(url, blob)
		}
	},

	methods: {
		/**
		 * Initialise the view: load the assessment then branch on proctoring shape
		 * (external provider notice / native test-mode intro / unproctored start).
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
		 */
		async init(id) {
			this.loading = true
			this.error = null

			try {
				await this.loadAssessment(id)

				const proctoring = this.assessment?.proctoring ?? null

				if (proctoring?.provider) {
					if (proctoring?.nativeTestMode) {
						// Config error: both an external provider and native test mode are set.
						// The external provider wins (design.md §3.1) — no schema-level
						// mutual-exclusion precedent exists in this register.
						// eslint-disable-next-line no-console
						console.warn('[TakeAssessmentView] Assessment.proctoring has both "provider" and "nativeTestMode" set; the external provider path wins.')
					}
					this.showProctoringNotice = true
					return
				}

				if (proctoring?.nativeTestMode) {
					this.nativeTestModeActive = true
					this.tabId = this.generateId()
					this.showTestModeIntro = true
					return
				}

				// Item pools and analysis: items are resolved from the server-side
				// drawnItemRefs snapshot, which only exists once the AssessmentResult
				// has been created — loadItems() MUST run after getOrCreateResult().
				await this.getOrCreateResult(id)
				await this.loadItems()
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * Fetch Item objects for every entry in AssessmentResult.drawnItemRefs —
		 * the frozen, server-resolved snapshot of exactly which items (and, when
		 * shuffleItemOrder is set, in which order) this attempt presents. Reads
		 * drawnItemRefs instead of Assessment.itemRefs directly, since the
		 * resolved set may differ from itemRefs (random-draw, or a shuffled
		 * fixed list) — AssessmentDrawResolver populates it for EVERY attempt.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
		 */
		async loadItems() {
			const drawnItemRefs = this.result?.drawnItemRefs ?? []
			const itemIds = drawnItemRefs.map((r) => r.itemId).filter(Boolean)
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * Re-fetch an AssessmentResult by id (GET). MUST run before loadItems()
		 * reads drawnItemRefs — whether OR's ObjectCreatedEvent dispatch (which
		 * AssessmentDrawResolver listens on to populate drawnItemRefs) completes
		 * before the original POST response is serialized is an implementation
		 * detail this view does not assume either way, so the create response
		 * body is never trusted for drawnItemRefs.
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
		 */
		async fetchResult(resultId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/AssessmentResult/${resultId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`AssessmentResult fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.result = json.object ?? json ?? {}
		},

		/**
		 * Look up an existing non-terminal (`in-progress`) AssessmentResult for the
		 * current learner and Assessment, mirroring the established fetch-all-then-
		 * filter convention (`ProctoringReviewQueue.vue:loadSessions()`) — no field-
		 * filter query parameter is assumed to exist server-side.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<object|null>} The existing in-progress result, or null.
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async checkExistingAttempt(assessmentId) {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'

			const url = generateUrl('/apps/openregister/api/objects/scholiq/AssessmentResult?limit=100')
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) return null
			const json = await resp.json()
			const results = json.results ?? json.objects ?? json ?? []
			return results.find((r) => r.assessmentId === assessmentId
				&& r.learnerId === learnerId
				&& r.lifecycle === 'in-progress') ?? null
		},

		/**
		 * Single-attempt window guard: resume an existing in-progress AssessmentResult
		 * for this learner+assessment instead of creating a duplicate one.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
		 */
		async getOrCreateResult(assessmentId) {
			const existing = await this.checkExistingAttempt(assessmentId)
			if (existing) {
				this.resultId = existing.uuid ?? existing.id
			} else {
				await this.createResult(assessmentId)
			}
			// Always re-fetch by id — never trust drawnItemRefs from the create
			// response body (design.md "Frontend consequence").
			await this.fetchResult(this.resultId)
		},

		/**
		 * Generate an identifier for a flag or tab-lock, preferring crypto.randomUUID
		 * where available.
		 *
		 * @return {string}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		generateId() {
			if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
				return crypto.randomUUID()
			}
			return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
		},

		/**
		 * Start the countdown timer if the assessment has a time limit.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async dismissProctoringNotice() {
			this.showProctoringNotice = false
			this.loading = true
			try {
				await this.getOrCreateResult(this.assessmentId)
				await this.loadItems()
				this.startTimer()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to start assessment. Please try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Learner clicked "Start" on the native test-mode pre-start screen (design
		 * §3.2): apply the single-attempt guard, acquire the same-browser tab lock,
		 * and — if not blocked — create the ProctoringSession and attach hardening.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async startNativeTestMode() {
			this.loading = true
			this.error = null
			try {
				await this.getOrCreateResult(this.assessmentId)

				const blocked = this.acquireTabLock(this.resultId)
				this.showTestModeIntro = false

				if (blocked) {
					this.showTabLockBlocked = true
					await this.flagConcurrentSessionForBlockedTab(this.resultId)
					return
				}

				await this.createProctoringSession()
				this.attachNativeHardening()
				await this.loadItems()
				this.startTimer()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to start assessment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] startNativeTestMode error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Same-browser tab-lock guard (design §3.3): a localStorage heartbeat keyed
		 * by resultId. Returns true when a live lock from a different tab already
		 * holds this attempt; otherwise claims the lock and starts the heartbeat.
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {boolean} True when this tab is blocked by a live lock elsewhere.
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		acquireTabLock(resultId) {
			const key = `scholiq-native-test-mode-lock-${resultId}`
			let existing = null
			try {
				const raw = window.localStorage.getItem(key)
				existing = raw ? JSON.parse(raw) : null
			} catch {
				existing = null
			}

			const now = Date.now()
			const isLive = !!existing && existing.tabId !== this.tabId && (now - (existing.updatedAt ?? 0)) < 15000
			if (isLive) {
				return true
			}

			this.tabLockKey = key
			this.writeTabLock(key)
			this.tabLockInterval = setInterval(() => this.writeTabLock(key), 5000)
			return false
		},

		/**
		 * Write this tab's heartbeat entry to the tab-lock key. Best-effort —
		 * localStorage may be unavailable (private browsing quota, disabled storage).
		 *
		 * @param {string} key localStorage key for the current attempt's lock
		 * @return {void}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		writeTabLock(key) {
			try {
				window.localStorage.setItem(key, JSON.stringify({ tabId: this.tabId, updatedAt: Date.now() }))
			} catch {
				// best-effort only
			}
		},

		/**
		 * Stop the tab-lock heartbeat and release the held lock, if any.
		 *
		 * @return {void}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		releaseTabLock() {
			if (this.tabLockInterval !== null) {
				clearInterval(this.tabLockInterval)
				this.tabLockInterval = null
			}
			if (this.tabLockKey) {
				try {
					window.localStorage.removeItem(this.tabLockKey)
				} catch {
					// best-effort only
				}
				this.tabLockKey = null
			}
		},

		/**
		 * Find the ProctoringSession for a blocked tab's resultId (fetch-all-then-
		 * filter, matching `ProctoringReviewQueue.vue`'s convention) and append a
		 * `concurrent-session-detected` flag when one exists (design §3.3).
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async flagConcurrentSessionForBlockedTab(resultId) {
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/ProctoringSession?limit=100')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) return
				const json = await resp.json()
				const sessions = json.results ?? json.objects ?? json ?? []
				const session = sessions.find((s) => s.assessmentResultId === resultId)
				if (!session) return

				this.proctoringSession = session
				await this.appendFlag('concurrent-session-detected', 'high')
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] flagConcurrentSessionForBlockedTab error', err)
			}
		},

		/**
		 * Create the native-mode ProctoringSession (design §3.4) and dispatch its
		 * existing `activate` transition.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async createProctoringSession() {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'

			const url = generateUrl('/apps/openregister/api/objects/scholiq/ProctoringSession')
			const resp = await fetch(url, {
				method: 'POST',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					assessmentResultId: this.resultId,
					learnerId,
					provider: 'native-test-mode',
					status: 'created',
					tenant_id: this.assessment?.tenant_id ?? '',
				}),
			})
			if (!resp.ok) {
				throw new Error(`ProctoringSession create failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.proctoringSession = json.object ?? json ?? {}

			const transitionUrl = generateUrl(
				`/apps/openregister/api/objects/scholiq/ProctoringSession/${this.proctoringSession.uuid}/transition/activate`,
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
				throw new Error(`ProctoringSession activate transition failed: ${transitionResp.status}`)
			}
		},

		/**
		 * Attach the native hardening listener set (design §3.4): fullscreen exit is
		 * gated by `lockdownBrowser`; tab-hidden and window-blur are always attached
		 * in native mode; popstate/beforeunload are gated by `navigationLock`.
		 *
		 * @return {void}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		attachNativeHardening() {
			const proctoring = this.assessment?.proctoring ?? {}
			const handlers = {}

			handlers.visibilitychange = () => {
				if (document.hidden) {
					this.appendFlag('tab-hidden', 'medium')
				}
			}
			document.addEventListener('visibilitychange', handlers.visibilitychange)

			handlers.blur = () => {
				this.appendFlag('window-blur', 'low')
			}
			window.addEventListener('blur', handlers.blur)

			if (proctoring.lockdownBrowser) {
				handlers.fullscreenchange = () => {
					if (!document.fullscreenElement) {
						this.appendFlag('fullscreen-exit', 'medium')
					}
				}
				document.addEventListener('fullscreenchange', handlers.fullscreenchange)

				if (document.documentElement.requestFullscreen) {
					document.documentElement.requestFullscreen().catch(() => {
						// Fullscreen request rejected (no user gesture, unsupported, etc.) —
						// deterrence only; the attempt still proceeds (design.md §1).
					})
				}
			}

			if (proctoring.navigationLock) {
				handlers.popstate = () => {
					this.appendFlag('blocked-navigation', 'low')
					// Re-assert a sentinel history entry: deters, does not prevent
					// (browsers do not let a page trap the back button, design.md §1).
					window.history.pushState(null, '', window.location.href)
				}
				handlers.beforeunload = (event) => {
					if (!this.submitted) {
						event.preventDefault()
						event.returnValue = ''
					}
				}
				window.history.pushState(null, '', window.location.href)
				window.addEventListener('popstate', handlers.popstate)
				window.addEventListener('beforeunload', handlers.beforeunload)
			}

			this.nativeHandlers = handlers
		},

		/**
		 * Remove every listener attached by `attachNativeHardening()`.
		 *
		 * @return {void}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		detachNativeHardening() {
			const handlers = this.nativeHandlers ?? {}
			if (handlers.visibilitychange) document.removeEventListener('visibilitychange', handlers.visibilitychange)
			if (handlers.blur) window.removeEventListener('blur', handlers.blur)
			if (handlers.fullscreenchange) document.removeEventListener('fullscreenchange', handlers.fullscreenchange)
			if (handlers.popstate) window.removeEventListener('popstate', handlers.popstate)
			if (handlers.beforeunload) window.removeEventListener('beforeunload', handlers.beforeunload)
			this.nativeHandlers = {}
		},

		/**
		 * Append a qualifying event as a flag on the active ProctoringSession, using
		 * the exact read-modify-write PUT pattern `ProctoringReviewQueue.vue`'s
		 * `recordDecision()` uses for the reciprocal write. Client-side throttled to
		 * one flag per `kind` per 5 seconds (design §3.4). Never alters the
		 * AssessmentResult (EU AI Act Art. 14 human oversight).
		 *
		 * @param {string} kind     Flag kind (see design §3.4 event table)
		 * @param {string} severity 'low' | 'medium' | 'high'
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async appendFlag(kind, severity) {
			if (!this.proctoringSession?.uuid) return

			const now = Date.now()
			const lastAt = this.lastFlagAt[kind] ?? 0
			if (now - lastAt < 5000) return
			this.lastFlagAt = { ...this.lastFlagAt, [kind]: now }

			const newFlag = {
				flagId: this.generateId(),
				kind,
				occurredAt: new Date().toISOString(),
				severity,
				reviewDecision: 'pending',
			}
			const updatedFlags = [...(this.proctoringSession.flags ?? []), newFlag]

			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/scholiq/ProctoringSession/${this.proctoringSession.uuid}`,
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
					throw new Error(`Flag append failed: ${resp.status}`)
				}
				this.proctoringSession = { ...this.proctoringSession, flags: updatedFlags }
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] appendFlag error', err)
			}
		},

		/**
		 * Native-mode teardown on a successful submit (design §3.5): dispatch the
		 * ProctoringSession's `end` transition, release the tab lock, exit
		 * fullscreen, and detach listeners.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
		 */
		async teardownNativeTestMode() {
			this.detachNativeHardening()
			this.releaseTabLock()

			if (document.fullscreenElement) {
				try {
					await document.exitFullscreen()
				} catch {
					// best-effort only
				}
			}

			if (this.proctoringSession?.uuid) {
				try {
					const transitionUrl = generateUrl(
						`/apps/openregister/api/objects/scholiq/ProctoringSession/${this.proctoringSession.uuid}/transition/end`,
					)
					const resp = await fetch(transitionUrl, {
						method: 'POST',
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({}),
					})
					if (!resp.ok) {
						throw new Error(`ProctoringSession end transition failed: ${resp.status}`)
					}
				} catch (err) {
					// eslint-disable-next-line no-console
					console.error('[TakeAssessmentView] teardownNativeTestMode error', err)
				}
			}
		},

		/**
		 * Set the response for the current item.
		 *
		 * @param {unknown} value Learner's response value
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
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

				if (this.nativeTestModeActive) {
					await this.teardownNativeTestMode()
				}
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
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		extractPrompt(qtiBody) {
			if (!qtiBody) return ''
			// Strip XML tags for minimal display — a proper QTI renderer would be richer.
			return qtiBody.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 500)
		},

		/**
		 * Extract choice options from a QTI choice interaction XML body.
		 * Returns an array of { id, label } objects, in `optionOrder` when given
		 * (the server-resolved AssessmentResult.drawnItemRefs[].optionOrder for
		 * this item — present when shuffleAnswerOptions is enabled), falling
		 * back to the qtiBody's declared order otherwise.
		 *
		 * @param {string} qtiBody Raw QTI XML body
		 * @param {string[]|null} [optionOrder] Server-resolved identifier order, or null.
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
		 */
		extractChoices(qtiBody, optionOrder = null) {
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

			if (choices.length === 0) {
				return [{ id: 'a', label: 'Option A' }, { id: 'b', label: 'Option B' }]
			}

			if (!optionOrder || optionOrder.length === 0) {
				return choices
			}

			const byId = new Map(choices.map((choice) => [choice.id, choice]))
			const ordered = optionOrder.map((id) => byId.get(id)).filter(Boolean)
			const remaining = choices.filter((choice) => !optionOrder.includes(choice.id))
			return ordered.length > 0 ? [...ordered, ...remaining] : choices
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

.take-assessment__test-mode-intro {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-info);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__test-mode-intro-list {
	margin: calc(var(--default-grid-baseline, 8px) * 2) 0;
	padding-left: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__test-mode-intro-list li {
	margin-bottom: var(--default-grid-baseline, 8px);
}

.take-assessment__tab-lock-blocked {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-error);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__error-inline {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
