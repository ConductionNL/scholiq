<!--
  SignPlanModal.vue
  Custom page component for the SignPlanModal manifest page (type: custom).

  Co-sign flow for a LearningPlan version:
  1. Load the LearningPlan identified by route param :planId.
  2. Present plan version, coordinator, and signer-role selector.
  3. Offer two signing methods:
     - 'click-to-confirm' → records assurance 'basic' immediately.
     - 'digid'            → shows a DigiD placeholder (redirect is out of scope);
                            records assurance 'substantial'.
  4. On confirm, POST a Signature object (subjectId/subjectVersion/signerId/signerRole/
     signedAt/assuranceLevel/method) to OpenRegister.

  DigiD/eIDAS authentication handshake is OUT OF SCOPE (openconnector concern).
  This component only records the Signature object.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="sign-plan-modal">
		<!-- Loading -->
		<div v-if="loading" class="sign-plan-modal__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading learning plan...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="loadError" class="sign-plan-modal__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ loadError }}</p>
		</div>

		<!-- Signed confirmation -->
		<div v-else-if="signed" class="sign-plan-modal__success" role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Plan signed successfully') }}</h2>
			<p>{{ t('scholiq', 'Your signature has been recorded for version {v} of this learning plan.', { v: plan.version || 1 }) }}</p>
		</div>

		<template v-else>
			<!-- Plan summary -->
			<header class="sign-plan-modal__header">
				<h2>{{ t('scholiq', 'Sign learning plan') }}</h2>
				<dl class="sign-plan-modal__meta">
					<dt>{{ t('scholiq', 'Learner') }}</dt>
					<dd>{{ plan.learnerId || '—' }}</dd>
					<dt>{{ t('scholiq', 'Kind') }}</dt>
					<dd>{{ plan.kind || '—' }}</dd>
					<dt>{{ t('scholiq', 'Version') }}</dt>
					<dd>{{ plan.version || 1 }}</dd>
					<dt>{{ t('scholiq', 'Period') }}</dt>
					<dd>{{ plan.period || '—' }}</dd>
					<dt>{{ t('scholiq', 'Coordinator') }}</dt>
					<dd>{{ plan.coordinatorId || '—' }}</dd>
				</dl>
			</header>

			<!-- Signer details form -->
			<section class="sign-plan-modal__form">
				<div class="sign-plan-modal__field">
					<label for="sign-role">{{ t('scholiq', 'Your role') }}</label>
					<select id="sign-role" v-model="signerRole" class="sign-plan-modal__select">
						<option value="learner">
							{{ t('scholiq', 'Learner') }}
						</option>
						<option value="parent">
							{{ t('scholiq', 'Parent / guardian') }}
						</option>
						<option value="coordinator">
							{{ t('scholiq', 'Coordinator') }}
						</option>
						<option value="teacher">
							{{ t('scholiq', 'Teacher') }}
						</option>
						<option value="other">
							{{ t('scholiq', 'Other') }}
						</option>
					</select>
				</div>

				<div class="sign-plan-modal__field">
					<label for="sign-method">{{ t('scholiq', 'Signing method') }}</label>
					<select id="sign-method" v-model="signingMethod" class="sign-plan-modal__select">
						<option value="click-to-confirm">
							{{ t('scholiq', 'Click to confirm (basic assurance)') }}
						</option>
						<option value="digid">
							{{ t('scholiq', 'DigiD (substantial assurance)') }}
						</option>
					</select>
				</div>
			</section>

			<!-- DigiD placeholder -->
			<div v-if="signingMethod === 'digid'" class="sign-plan-modal__digid-notice" role="note">
				<span class="icon-info" aria-hidden="true" />
				<p>{{ t('scholiq', 'In production you would be redirected to DigiD here. This placeholder records substantial assurance without the actual DigiD handshake (out of scope — see openconnector data-exchange spec).') }}</p>
			</div>

			<!-- Confirm button -->
			<div class="sign-plan-modal__actions">
				<button
					class="button-vue button-vue--primary sign-plan-modal__confirm-btn"
					:disabled="saving"
					@click="confirmSign">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ signingMethod === 'digid' ? t('scholiq', 'Simulate DigiD sign') : t('scholiq', 'Confirm signature') }}
				</button>
			</div>

			<p v-if="saveError" class="sign-plan-modal__save-error" role="alert">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

const ASSURANCE_BY_METHOD = {
	'click-to-confirm': 'basic',
	digid: 'substantial',
}

export default {
	name: 'SignPlanModal',

	props: {
		/**
		 * LearningPlan UUID from route param :planId.
		 */
		planId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object} */
			plan: {},
			signerRole: 'learner',
			signingMethod: 'click-to-confirm',
			loading: false,
			saving: false,
			signed: false,
			loadError: null,
			saveError: null,
		}
	},

	created() {
		this.loadPlan()
	},

	methods: {
		/**
		 * Load the LearningPlan from OpenRegister.
		 *
		 * @return {Promise<void>}
		 */
		async loadPlan() {
			this.loading = true
			this.loadError = null

			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/LearningPlan/${this.planId}`)
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})

				if (!resp.ok) {
					throw new Error(`HTTP ${resp.status}`)
				}

				const json = await resp.json()
				this.plan = json.object ?? json ?? {}
			} catch (err) {
				this.loadError = this.t('scholiq', 'Failed to load learning plan. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SignPlanModal] loadPlan error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the current user ID from the Nextcloud global.
		 *
		 * @return {string}
		 */
		currentUserId() {
			return window.oc_current_user ?? ''
		},

		/**
		 * POST a Signature object to OpenRegister and mark as signed.
		 *
		 * @return {Promise<void>}
		 */
		async confirmSign() {
			this.saving = true
			this.saveError = null

			const payload = {
				subjectKind: 'learning-plan',
				subjectId: this.planId,
				subjectVersion: this.plan.version ?? 1,
				signerId: this.currentUserId(),
				signerRole: this.signerRole,
				signedAt: new Date().toISOString(),
				assuranceLevel: ASSURANCE_BY_METHOD[this.signingMethod] ?? 'basic',
				method: this.signingMethod,
				tenant_id: this.plan.tenant_id ?? '',
			}

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/Signature')
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(payload),
				})

				if (!resp.ok) {
					throw new Error(`HTTP ${resp.status}`)
				}

				this.signed = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to record signature. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SignPlanModal] confirmSign error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sign-plan-modal {
	max-width: 600px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.sign-plan-modal__loading,
.sign-plan-modal__error,
.sign-plan-modal__success {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.sign-plan-modal__success {
	flex-direction: column;
	color: var(--color-success);
}

.sign-plan-modal__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.sign-plan-modal__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	font-size: 0.9em;
	margin-top: 8px;
}

.sign-plan-modal__meta dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.sign-plan-modal__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.sign-plan-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sign-plan-modal__field label {
	font-weight: 600;
	font-size: 0.9em;
}

.sign-plan-modal__select {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9em;
}

.sign-plan-modal__digid-notice {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 1.5);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.sign-plan-modal__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
}

.sign-plan-modal__confirm-btn {
	display: flex;
	align-items: center;
	gap: 6px;
}

.sign-plan-modal__save-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
}
</style>
