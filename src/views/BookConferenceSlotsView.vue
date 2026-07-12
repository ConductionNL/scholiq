<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BookConferenceSlotsView — the guardian/self conversation-slot picker
 (parent-conferences).

 A custom view (the "custom view only where a manifest page can't render it"
 exception, matching RolloverWizard/MarkAttendanceView): lists ConferenceRounds
 currently in `booking-open`, lets the caller pick which of their linked
 children (or themselves, for an 18+ self-signup) and which of the round's
 teachers they want conversations with (in preference order), then creates a
 `draft` ConferenceSignup and immediately triggers its `submit` transition.
 The transition is gated server-side by ConferenceSignupGuardianGuard — the
 caller's identity is resolved from the NC session, never trusted from the
 client, so a rejected submit here always reflects a genuine authorization
 failure, not a UI bug.

 Storage/lifecycle/notifications are OpenRegister's; this view only creates
 the signup and drives its submit transition through the OR object API.

 @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
 @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
-->
<template>
	<div class="book-conference-slots">
		<h2>{{ t('scholiq', 'Book a parent-teacher conversation') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="rounds.length === 0"
			:name="t('scholiq', 'No conference rounds open for booking')"
			:description="t('scholiq', 'Check back once your school opens booking for the next oudergesprekken round.')" />

		<template v-else>
			<div class="book-conference-slots__field">
				<label for="bcs-round">{{ t('scholiq', 'Conference round') }}</label>
				<NcSelect id="bcs-round"
					v-model="selectedRoundId"
					:options="roundOptions"
					:reduce="(o) => o.id"
					label="label"
					:input-label="t('scholiq', 'Conference round')"
					:aria-label-combobox="t('scholiq', 'Conference round')"
					@input="onRoundChange" />
			</div>

			<div v-if="selectedRound" class="book-conference-slots__field">
				<label for="bcs-learner">{{ t('scholiq', 'For which child (or yourself)') }}</label>
				<NcSelect id="bcs-learner"
					v-model="selectedLearnerId"
					:options="learnerOptions"
					:reduce="(o) => o.id"
					label="label"
					:loading="loadingLearners"
					:input-label="t('scholiq', 'Learner')"
					:aria-label-combobox="t('scholiq', 'Learner')" />
			</div>

			<div v-if="selectedRound" class="book-conference-slots__field">
				<label for="bcs-teachers">{{ t('scholiq', 'Requested teachers (in order of preference)') }}</label>
				<NcSelect id="bcs-teachers"
					v-model="selectedTeacherIds"
					:options="teacherOptions"
					:reduce="(o) => o.id"
					label="label"
					multiple
					:input-label="t('scholiq', 'Requested teachers')"
					:aria-label-combobox="t('scholiq', 'Requested teachers')" />
			</div>

			<div v-if="selectedRound" class="book-conference-slots__field">
				<label for="bcs-notes">{{ t('scholiq', 'Notes (optional)') }}</label>
				<textarea id="bcs-notes"
					v-model="notes"
					rows="3" />
			</div>

			<NcNoteCard v-if="submitError" type="error">
				{{ submitError }}
			</NcNoteCard>

			<NcNoteCard v-if="submitSuccess" type="success">
				{{ t('scholiq', 'Your request has been submitted. You will be notified once the schedule is generated.') }}
			</NcNoteCard>

			<NcButton type="primary"
				:disabled="!canSubmit || submitting"
				@click="submitSignup">
				{{ submitting ? t('scholiq', 'Submitting…') : t('scholiq', 'Submit request') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'BookConferenceSlotsView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadingLearners: false,
			rounds: [],
			learners: [],
			selectedRoundId: '',
			selectedLearnerId: '',
			selectedTeacherIds: [],
			notes: '',
			submitting: false,
			submitError: '',
			submitSuccess: false,
		}
	},

	computed: {
		/**
		 * Options for the round picker.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		roundOptions() {
			return this.rounds.map((r) => ({ id: r.id || r.uuid, label: r.name || r.id || r.uuid }))
		},
		/**
		 * The currently selected ConferenceRound object, or null.
		 *
		 * @return {object|null}
		 */
		selectedRound() {
			return this.rounds.find((r) => (r.id || r.uuid) === this.selectedRoundId) || null
		},
		/**
		 * Teacher options scoped to the selected round's teacherIds.
		 *
		 * @return {Array<object>}
		 */
		teacherOptions() {
			if (!this.selectedRound) return []
			return (this.selectedRound.teacherIds || []).map((id) => ({ id, label: id }))
		},
		/**
		 * Learner options: the caller's linked children plus themselves (18+ self-signup).
		 *
		 * @return {Array<object>}
		 */
		learnerOptions() {
			return this.learners.map((l) => ({ id: l.ncUserId, label: l.displayName || l.ncUserId }))
		},
		/**
		 * Whether the form has enough input to submit.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
		 */
		canSubmit() {
			return this.selectedRoundId !== ''
				&& this.selectedLearnerId !== ''
				&& this.selectedTeacherIds.length > 0
		},
	},

	async mounted() {
		await this.loadRounds()
		await this.loadLearners()
	},

	methods: {
		t,
		/**
		 * Load ConferenceRounds currently open for booking.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadRounds() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/conference-round?lifecycle=booking-open&limit=100')
				const response = await axios.get(url)
				this.rounds = (response.data && (response.data.results || response.data.objects)) || response.data || []
			} catch (e) {
				console.error('[BookConferenceSlotsView] loadRounds failed', e)
				this.rounds = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the caller's linked LearnerProfiles (children via parentIds, plus
		 * self via ncUserId for an 18+ self-signup).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
		 */
		async loadLearners() {
			this.loadingLearners = true
			const uid = getCurrentUser()?.uid
			if (!uid) {
				this.loadingLearners = false
				return
			}

			try {
				const [childrenResp, selfResp] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/learner-profile?parentIds={uid}&limit=50', { uid })),
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/learner-profile?ncUserId={uid}&limit=1', { uid })),
				])
				const children = (childrenResp.data && (childrenResp.data.results || childrenResp.data.objects)) || []
				const self = (selfResp.data && (selfResp.data.results || selfResp.data.objects)) || []
				const byId = new Map()
				;[...children, ...self].forEach((l) => byId.set(l.ncUserId, l))
				this.learners = Array.from(byId.values())
			} catch (e) {
				console.error('[BookConferenceSlotsView] loadLearners failed', e)
				this.learners = []
			} finally {
				this.loadingLearners = false
			}
		},

		/**
		 * Reset the teacher/learner selection when the round changes.
		 *
		 * @return {void}
		 */
		onRoundChange() {
			this.selectedTeacherIds = []
		},

		/**
		 * Create the ConferenceSignup (draft) then trigger its submit transition.
		 * A guard rejection (HTTP 4xx from OR's lifecycle engine) surfaces as a
		 * user-facing error rather than a silent failure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-an-unrelated-user-cannot-submit-a-signup-for-someone-else-s-child
		 */
		async submitSignup() {
			if (!this.canSubmit) return

			this.submitting = true
			this.submitError = ''
			this.submitSuccess = false

			try {
				const body = {
					conferenceRoundId: this.selectedRoundId,
					learnerId: this.selectedLearnerId,
					requestedTeacherIds: this.selectedTeacherIds,
					notes: this.notes || null,
					tenant_id: this.selectedRound ? this.selectedRound.tenant_id : undefined,
				}

				const createUrl = generateUrl('/apps/openregister/api/objects/scholiq/conference-signup')
				const created = await axios.post(createUrl, body)
				const signupId = (created.data && (created.data.id || created.data.uuid)) || ''

				if (signupId === '') {
					throw new Error('No signup id returned')
				}

				const transitionUrl = generateUrl('/apps/openregister/api/objects/scholiq/conference-signup/{id}', { id: signupId })
				await axios.put(transitionUrl, { lifecycle: 'submitted' })

				this.submitSuccess = true
				this.selectedTeacherIds = []
				this.notes = ''
			} catch (e) {
				console.error('[BookConferenceSlotsView] submitSignup failed', e)
				this.submitError = t('scholiq', 'We could not submit your request. You may not be a linked guardian for this child, or the round may no longer be open for booking.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.book-conference-slots {
	padding: 1rem;
	max-width: 480px;
}

.book-conference-slots__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 1rem;
}

.book-conference-slots__field textarea {
	width: 100%;
	resize: vertical;
}
</style>
