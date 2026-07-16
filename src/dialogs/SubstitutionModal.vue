<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubstitutionModal — timetabling-and-substitution.

 Mark a Session cancelled, or assign a substitute teacher, always requiring a
 reason (SessionChangeGuard is the actual server-side enforcement; this
 dialog only pre-empts a doomed request with the same reasoning surfaced to
 the user, mirroring ComposeReportPeriodModal's posture).

 Both actions are triggered as a plain PUT that sets `lifecycle` to the
 target value alongside the substitution fields — the SAME calling
 convention this app already uses for other self-loop transitions (e.g.
 ConferenceScheduleBoard's `regenerate`, PUT {lifecycle: 'scheduled'}):
 `cancel` sets `lifecycle: 'cancelled'`; `substitute-teacher` re-sends the
 Session's CURRENT lifecycle value unchanged (a true self-loop) — the
 register resolves this to the `substitute-teacher` transition when the
 Session is `scheduled`, or `substitute-teacher-in-progress` when
 `in-progress`, per which `from` state matches.

 Opened from MyTimetable.vue for a Session the caller may manage.

 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-frontend-is-declarative-with-named-custom-views
 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
 @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
-->
<template>
	<NcDialog :open="true"
		:name="dialogTitle"
		@update:open="v => { if (!v) $emit('close') }">
		<div class="substitution-modal">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="substitution-modal__mode" role="group" :aria-label="t('scholiq', 'Action')">
				<NcButton :type="mode === 'cancel' ? 'primary' : 'secondary'"
					:disabled="saving"
					@click="mode = 'cancel'">
					{{ t('scholiq', 'Cancel session') }}
				</NcButton>
				<NcButton :type="mode === 'substitute' ? 'primary' : 'secondary'"
					:disabled="saving"
					@click="mode = 'substitute'">
					{{ t('scholiq', 'Assign substitute teacher') }}
				</NcButton>
			</div>

			<div class="substitution-modal__field">
				<label for="substitution-reason-kind">{{ t('scholiq', 'Reason') }}</label>
				<NcSelect id="substitution-reason-kind"
					v-model="changeReasonKind"
					:input-label="t('scholiq', 'Reason')"
					:options="reasonOptions"
					:reduce="opt => opt.value"
					:clearable="false" />
			</div>

			<div v-if="mode === 'substitute'" class="substitution-modal__field">
				<label for="substitution-teacher-id">{{ t('scholiq', 'Substitute teacher (Nextcloud user ID)') }}</label>
				<input id="substitution-teacher-id"
					v-model="substituteTeacherId"
					type="text"
					class="substitution-modal__input">
			</div>

			<div class="substitution-modal__field">
				<label for="substitution-note">{{ t('scholiq', 'Note (optional)') }}</label>
				<textarea id="substitution-note"
					v-model="changeReason"
					class="substitution-modal__textarea"
					rows="3" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('scholiq', 'Close') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit || saving"
				@click="submit">
				{{ saving ? t('scholiq', 'Saving…') : submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'SubstitutionModal',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		session: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			mode: 'cancel',
			changeReasonKind: 'teacher-absence',
			changeReason: '',
			substituteTeacherId: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Dialog title.
		 *
		 * @return {string}
		 */
		dialogTitle() {
			return t('scholiq', 'Manage "{title}"', { title: this.session.title || t('scholiq', 'Untitled session') })
		},

		/**
		 * Reason-kind options, matching Session.changeReasonKind's declared enum.
		 *
		 * @return {Array<{value:string,label:string}>}
		 */
		reasonOptions() {
			return [
				{ value: 'teacher-absence', label: t('scholiq', 'Teacher absence') },
				{ value: 'room-unavailable', label: t('scholiq', 'Room unavailable') },
				{ value: 'timetable-change', label: t('scholiq', 'Timetable change') },
				{ value: 'other', label: t('scholiq', 'Other') },
			]
		},

		/**
		 * Submit button label for the current mode.
		 *
		 * @return {string}
		 */
		submitLabel() {
			return this.mode === 'cancel' ? t('scholiq', 'Cancel session') : t('scholiq', 'Assign substitute')
		},

		/**
		 * Whether the form has the minimum required fields for the current mode.
		 *
		 * @return {boolean}
		 */
		canSubmit() {
			if (!this.changeReasonKind) return false
			if (this.mode === 'substitute' && !this.substituteTeacherId.trim()) return false
			return true
		},
	},

	methods: {
		t,

		/**
		 * Submit the cancel or substitute-teacher change.
		 *
		 * `lifecycle` is sent as `cancelled` for the cancel mode, or the
		 * Session's OWN current lifecycle value (a self-loop) for the
		 * substitute mode — never a client-chosen action name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
		 */
		async submit() {
			if (!this.canSubmit) return

			this.saving = true
			this.error = ''

			const body = {
				lifecycle: this.mode === 'cancel' ? 'cancelled' : this.session.lifecycle,
				changeReasonKind: this.changeReasonKind,
				changeReason: this.changeReason || null,
			}
			if (this.mode === 'substitute') {
				body.substituteTeacherId = this.substituteTeacherId.trim()
			}

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/session/{id}', { id: this.session.id })
				await axios.put(url, body)
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				console.error('[SubstitutionModal] submit failed', e)
				this.error = t('scholiq', 'Could not save this change. Please check the reason and try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.substitution-modal {
	min-width: 380px;
	padding: 8px 4px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.substitution-modal__mode {
	display: flex;
	gap: 8px;
}

.substitution-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.substitution-modal__field label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.substitution-modal__input,
.substitution-modal__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}
</style>
