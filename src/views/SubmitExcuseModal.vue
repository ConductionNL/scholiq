<!--
  SubmitExcuseModal.vue
  Custom page component for the SubmitExcuseModal manifest page (type: custom).

  Learner / parent absence-excuse submission flow:
  1. Pre-fill learnerId from the current Nextcloud user.
  2. Provide date-range picker (dateFrom / dateTo), reason text, reasonKind select.
  3. Auth-level toggle:
     - "Click to confirm" → records submittedAuthLevel: basic (default)
     - "DigiD" → shows placeholder banner ("DigiD authentication would occur here")
       and records submittedAuthLevel: substantial.
     The DigiD handshake itself is out of scope (openconnector concern);
     only the resulting assurance level is stored.
  4. On submit: POST an ExcuseRequest to OpenRegister.

  Talks only to OpenRegister's REST API:
    - POST /api/objects/scholiq/ExcuseRequest

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="submit-excuse-modal">
		<!-- Submitted confirmation -->
		<div v-if="submitted"
			class="submit-excuse-modal__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Excuse request submitted!') }}</h2>
			<p>{{ t('scholiq', 'Your request has been sent to the coordinator for review.') }}</p>
			<button class="button-vue" @click="resetForm">
				{{ t('scholiq', 'Submit another request') }}
			</button>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="submit-excuse-modal__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
			<button class="button-vue" @click="error = null">
				{{ t('scholiq', 'Try again') }}
			</button>
		</div>

		<!-- Form -->
		<template v-else>
			<header class="submit-excuse-modal__header">
				<h2>{{ t('scholiq', 'Submit absence excuse') }}</h2>
				<p class="submit-excuse-modal__intro">
					{{ t('scholiq', 'Submit an excuse for one or more days of absence. A coordinator will review your request.') }}
				</p>
			</header>

			<form class="submit-excuse-modal__form" @submit.prevent="submitExcuse">
				<!-- Auth method -->
				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label">
						{{ t('scholiq', 'Submission method') }}
					</label>
					<div class="submit-excuse-modal__auth-options">
						<label class="submit-excuse-modal__auth-option">
							<input v-model="authMethod"
								type="radio"
								value="click-to-confirm"
								name="authMethod">
							{{ t('scholiq', 'Click to confirm (standard)') }}
						</label>
						<label class="submit-excuse-modal__auth-option">
							<input v-model="authMethod"
								type="radio"
								value="digid"
								name="authMethod">
							{{ t('scholiq', 'DigiD (higher assurance)') }}
						</label>
					</div>

					<!-- DigiD placeholder -->
					<div v-if="authMethod === 'digid'"
						class="submit-excuse-modal__digid-banner"
						role="note"
						aria-label="DigiD placeholder">
						<span class="icon-info" aria-hidden="true" />
						<p>
							{{ t('scholiq', 'DigiD authentication would occur here in a live environment. Your assurance level will be recorded as "substantial" (eIDAS). The authentication handshake itself is handled by the openconnector module.') }}
						</p>
					</div>
				</div>

				<!-- Date range -->
				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label" for="excuse-date-from">
						{{ t('scholiq', 'Absence from') }}
					</label>
					<input id="excuse-date-from"
						v-model="form.dateFrom"
						type="date"
						required
						class="submit-excuse-modal__input"
						:aria-required="true">
				</div>

				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label" for="excuse-date-to">
						{{ t('scholiq', 'Absence until (inclusive)') }}
					</label>
					<input id="excuse-date-to"
						v-model="form.dateTo"
						type="date"
						required
						:min="form.dateFrom"
						class="submit-excuse-modal__input"
						:aria-required="true">
				</div>

				<!-- Reason kind -->
				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label" for="excuse-reason-kind">
						{{ t('scholiq', 'Reason category') }}
					</label>
					<select id="excuse-reason-kind"
						v-model="form.reasonKind"
						required
						class="submit-excuse-modal__select">
						<option value="illness">
							{{ t('scholiq', 'Illness') }}
						</option>
						<option value="medical-appointment">
							{{ t('scholiq', 'Medical appointment') }}
						</option>
						<option value="family-circumstance">
							{{ t('scholiq', 'Family circumstance') }}
						</option>
						<option value="religious-observance">
							{{ t('scholiq', 'Religious observance') }}
						</option>
						<option value="bereavement">
							{{ t('scholiq', 'Bereavement') }}
						</option>
						<option value="other">
							{{ t('scholiq', 'Other') }}
						</option>
					</select>
				</div>

				<!-- Free-text reason -->
				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label" for="excuse-reason">
						{{ t('scholiq', 'Reason (description)') }}
					</label>
					<textarea id="excuse-reason"
						v-model="form.reason"
						required
						rows="4"
						class="submit-excuse-modal__textarea"
						:placeholder="t('scholiq', 'Please describe the reason for absence...')"
						:aria-required="true" />
				</div>

				<!-- Optional attachment ref -->
				<div class="submit-excuse-modal__field">
					<label class="submit-excuse-modal__label" for="excuse-attachment">
						{{ t('scholiq', 'Attachment reference (optional)') }}
					</label>
					<input id="excuse-attachment"
						v-model="form.attachmentRef"
						type="text"
						class="submit-excuse-modal__input"
						:placeholder="t('scholiq', 'e.g. link to doctor\'s note...')">
					<span class="submit-excuse-modal__hint">
						{{ t('scholiq', 'If you have a supporting document (doctor\'s note, etc.) you can add a reference here.') }}
					</span>
				</div>

				<!-- Submit -->
				<div class="submit-excuse-modal__actions">
					<button type="submit"
						class="button-vue button-vue--primary"
						:disabled="submitting">
						<span v-if="submitting" class="icon-loading-small" aria-hidden="true" />
						{{ submitting
							? t('scholiq', 'Submitting...')
							: (authMethod === 'digid'
								? t('scholiq', 'Authenticate with DigiD and submit')
								: t('scholiq', 'Submit excuse request'))
						}}
					</button>
				</div>
			</form>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'

const OR_BASE = '/index.php/apps/openregister/api/objects/scholiq'

export default {
	name: 'SubmitExcuseModal',

	props: {
		pageContext: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		const currentUser = getCurrentUser()
		return {
			submitting: false,
			submitted: false,
			error: null,
			/** @type {'click-to-confirm'|'digid'} */
			authMethod: 'click-to-confirm',
			form: {
				dateFrom: '',
				dateTo: '',
				reasonKind: 'illness',
				reason: '',
				attachmentRef: '',
			},
			currentUserId: currentUser?.uid ?? '',
		}
	},

	computed: {
		submittedAuthLevel() {
			return this.authMethod === 'digid' ? 'substantial' : 'basic'
		},
	},

	methods: {
		async submitExcuse() {
			if (this.form.dateFrom > this.form.dateTo) {
				this.error = this.t('scholiq', 'The "until" date must be on or after the "from" date.')
				return
			}

			this.submitting = true
			this.error = null

			const payload = {
				learnerId: this.currentUserId,
				submittedBy: this.currentUserId,
				dateFrom: this.form.dateFrom,
				dateTo: this.form.dateTo,
				reason: this.form.reason,
				reasonKind: this.form.reasonKind,
				attachmentRef: this.form.attachmentRef || null,
				submittedAuthLevel: this.submittedAuthLevel,
			}

			try {
				const res = await fetch(`${OR_BASE}/ExcuseRequest`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(payload),
				})

				if (!res.ok) {
					const body = await res.text()
					throw new Error(`${res.status} ${res.statusText}: ${body}`)
				}

				this.submitted = true
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to submit excuse request. Please try again.')
				console.error('[SubmitExcuseModal] submitExcuse error:', err)
			} finally {
				this.submitting = false
			}
		},

		resetForm() {
			this.submitted = false
			this.error = null
			this.authMethod = 'click-to-confirm'
			this.form = {
				dateFrom: '',
				dateTo: '',
				reasonKind: 'illness',
				reason: '',
				attachmentRef: '',
			}
		},
	},
}
</script>

<style scoped>
.submit-excuse-modal {
	max-width: 600px;
	margin: 0 auto;
	padding: var(--body-container-margin, 20px);
}

.submit-excuse-modal__confirmation,
.submit-excuse-modal__error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 48px 0;
	text-align: center;
}

.submit-excuse-modal__error {
	color: var(--color-error);
}

.submit-excuse-modal__header {
	margin-bottom: 24px;
}

.submit-excuse-modal__header h2 {
	font-size: 1.4em;
	font-weight: 600;
	margin-bottom: 8px;
}

.submit-excuse-modal__intro {
	color: var(--color-text-maxcontrast);
}

.submit-excuse-modal__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.submit-excuse-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.submit-excuse-modal__label {
	font-weight: 600;
	font-size: 0.9em;
}

.submit-excuse-modal__input,
.submit-excuse-modal__select,
.submit-excuse-modal__textarea {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1em;
}

.submit-excuse-modal__textarea {
	resize: vertical;
}

.submit-excuse-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.submit-excuse-modal__auth-options {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.submit-excuse-modal__auth-option {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.submit-excuse-modal__digid-banner {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin-top: 10px;
	padding: 12px;
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary);
	border-radius: var(--border-radius);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.submit-excuse-modal__actions {
	margin-top: 8px;
	display: flex;
	justify-content: flex-end;
}
</style>
