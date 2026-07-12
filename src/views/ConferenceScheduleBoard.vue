<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ConferenceScheduleBoard — the coordinator's manual-override board
 (parent-conferences).

 A custom view (the "custom view only where a manifest page can't render it"
 exception, matching RolloverWizard): for a selected ConferenceRound, lists
 every `waitlisted` ConferenceSignup together with the unmet teacher-request
 recorded by ConferenceScheduleGenerator (the algorithm records this in
 `notes` — see design.md task 4.4), offers a manual ConferenceSlot creation
 action to hand-place a stuck signup, and a `regenerate` trigger to re-run the
 greedy solver after the coordinator adds availability or a parent cancels
 (design.md Step 4 — idempotent regenerate, confirmed slots are never
 disturbed).

 Storage/lifecycle/scheduling logic are OpenRegister's/ConferenceScheduleGenerator's;
 this view only reads waitlisted signups, writes a manual ConferenceSlot, and
 triggers the round's `regenerate` transition through the OR object API.

 @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
 @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
-->
<template>
	<div class="conference-schedule-board">
		<h2>{{ t('scholiq', 'Conference schedule board') }}</h2>

		<div class="conference-schedule-board__toolbar">
			<NcSelect id="csb-round"
				v-model="selectedRoundId"
				:options="roundOptions"
				:reduce="(o) => o.id"
				label="label"
				:loading="loadingRounds"
				:input-label="t('scholiq', 'Conference round')"
				:aria-label-combobox="t('scholiq', 'Conference round')"
				@input="loadWaitlisted" />

			<NcButton type="secondary"
				:disabled="!selectedRoundId || regenerating"
				@click="regenerate">
				{{ regenerating ? t('scholiq', 'Regenerating…') : t('scholiq', 'Regenerate schedule') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="regenerateError" type="error">
			{{ regenerateError }}
		</NcNoteCard>
		<NcNoteCard v-if="regenerateSuccess" type="success">
			{{ t('scholiq', 'Regeneration triggered. Refresh the waitlist below in a moment.') }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loadingSignups" :size="32" />

		<NcEmptyContent v-else-if="selectedRoundId && waitlisted.length === 0"
			:name="t('scholiq', 'Nothing waitlisted')"
			:description="t('scholiq', 'Every submitted signup for this round has a full schedule.')" />

		<table v-else-if="waitlisted.length > 0" class="conference-schedule-board__table">
			<thead>
				<tr>
					<th>{{ t('scholiq', 'Learner') }}</th>
					<th>{{ t('scholiq', 'Requested teachers') }}</th>
					<th>{{ t('scholiq', 'Unmet') }}</th>
					<th>{{ t('scholiq', 'Manual placement') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="signup in waitlisted" :key="signup.id || signup.uuid">
					<td>{{ signup.learnerId }}</td>
					<td>{{ (signup.requestedTeacherIds || []).join(', ') }}</td>
					<td>{{ signup.notes || '—' }}</td>
					<td>
						<div class="conference-schedule-board__manual">
							<NcSelect v-model="manualForm[signup.id].teacherId"
								:options="teacherOptions(signup)"
								:reduce="(o) => o.id"
								label="label"
								:input-label="t('scholiq', 'Teacher')"
								:aria-label-combobox="t('scholiq', 'Teacher')" />
							<input v-model="manualForm[signup.id].startsAt"
								type="datetime-local"
								:aria-label="t('scholiq', 'Start time')">
							<input v-model="manualForm[signup.id].endsAt"
								type="datetime-local"
								:aria-label="t('scholiq', 'End time')">
							<NcButton type="tertiary"
								:disabled="!manualForm[signup.id].teacherId || !manualForm[signup.id].startsAt || !manualForm[signup.id].endsAt"
								@click="createManualSlot(signup)">
								{{ t('scholiq', 'Create slot') }}
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ConferenceScheduleBoard',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loadingRounds: true,
			loadingSignups: false,
			rounds: [],
			selectedRoundId: '',
			waitlisted: [],
			manualForm: {},
			regenerating: false,
			regenerateError: '',
			regenerateSuccess: false,
		}
	},

	computed: {
		/**
		 * Options for the round picker — every scheduled round (post-generate,
		 * where waitlist resolution happens).
		 *
		 * @return {Array<object>}
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
	},

	async mounted() {
		await this.loadRounds()
	},

	methods: {
		t,
		/**
		 * Load ConferenceRounds in `scheduled` (a generate pass has already run).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadRounds() {
			this.loadingRounds = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/conference-round?lifecycle=scheduled&limit=100')
				const response = await axios.get(url)
				this.rounds = (response.data && (response.data.results || response.data.objects)) || response.data || []
			} catch (e) {
				console.error('[ConferenceScheduleBoard] loadRounds failed', e)
				this.rounds = []
			} finally {
				this.loadingRounds = false
			}
		},

		/**
		 * Teacher options for a manual placement — scoped to the round's teacherIds.
		 *
		 * @param {object} _signup Reserved for future per-signup narrowing (unused today).
		 * @return {Array<object>}
		 */
		teacherOptions(_signup) {
			if (!this.selectedRound) return []
			return (this.selectedRound.teacherIds || []).map((id) => ({ id, label: id }))
		},

		/**
		 * Load waitlisted ConferenceSignups for the selected round.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
		 */
		async loadWaitlisted() {
			this.waitlisted = []
			this.manualForm = {}
			if (!this.selectedRoundId) return

			this.loadingSignups = true
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/conference-signup?conferenceRoundId={roundId}&lifecycle=waitlisted&limit=200',
					{ roundId: this.selectedRoundId },
				)
				const response = await axios.get(url)
				this.waitlisted = (response.data && (response.data.results || response.data.objects)) || response.data || []
				const forms = {}
				this.waitlisted.forEach((s) => {
					forms[s.id || s.uuid] = { teacherId: '', startsAt: '', endsAt: '' }
				})
				this.manualForm = forms
			} catch (e) {
				console.error('[ConferenceScheduleBoard] loadWaitlisted failed', e)
				this.waitlisted = []
			} finally {
				this.loadingSignups = false
			}
		},

		/**
		 * Hand-create a ConferenceSlot for a stuck waitlisted signup, bypassing
		 * the generator for this one teacher-request.
		 *
		 * @param {object} signup The waitlisted ConferenceSignup being resolved.
		 * @return {Promise<void>}
		 */
		async createManualSlot(signup) {
			const signupId = signup.id || signup.uuid
			const form = this.manualForm[signupId]
			if (!form || !form.teacherId || !form.startsAt || !form.endsAt) return

			try {
				const body = {
					conferenceRoundId: this.selectedRoundId,
					teacherId: form.teacherId,
					learnerId: signup.learnerId,
					learnerRef: signup.learnerRef || null,
					signupId,
					startsAt: new Date(form.startsAt).toISOString(),
					endsAt: new Date(form.endsAt).toISOString(),
					location: null,
					tenant_id: this.selectedRound ? this.selectedRound.tenant_id : undefined,
				}
				const url = generateUrl('/apps/openregister/api/objects/scholiq/conference-slot')
				await axios.post(url, body)

				// Reflect the manual placement on the signup itself so the board
				// stops listing it as waitlisted once every request is met.
				await this.loadWaitlisted()
			} catch (e) {
				console.error('[ConferenceScheduleBoard] createManualSlot failed', e)
			}
		},

		/**
		 * Trigger the round's `regenerate` transition (scheduled → scheduled).
		 * ConferenceScheduleGenerator re-runs the greedy solver, re-filling only
		 * from availability freed by cancellations or newly submitted availability.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parent-evening-planner/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
		 */
		async regenerate() {
			if (!this.selectedRoundId) return

			this.regenerating = true
			this.regenerateError = ''
			this.regenerateSuccess = false

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/conference-round/{id}', { id: this.selectedRoundId })
				await axios.put(url, { lifecycle: 'scheduled' })
				this.regenerateSuccess = true
			} catch (e) {
				console.error('[ConferenceScheduleBoard] regenerate failed', e)
				this.regenerateError = t('scholiq', 'Could not trigger regeneration. Please try again.')
			} finally {
				this.regenerating = false
			}
		},
	},
}
</script>

<style scoped>
.conference-schedule-board {
	padding: 1rem;
}

.conference-schedule-board__toolbar {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1rem;
	max-width: 640px;
}

.conference-schedule-board__table {
	width: 100%;
	border-collapse: collapse;
}

.conference-schedule-board__table th,
.conference-schedule-board__table td {
	text-align: left;
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.conference-schedule-board__manual {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}
</style>
