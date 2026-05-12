<!--
  RequestExportModal.vue
  Custom page component for the RequestExportModal manifest page (type: custom).

  Allows a school administrator or authorised user to queue a DataExchangeJob:
  1. Pick a target (bron-rod | oso | leerplicht | surfconext | hr | custom).
  2. Pick a DataMappingProfile (filtered to that target + direction).
  3. Define the scope (schema slug + optional cohortId + optional period).
  4. Confirm → POST a DataExchangeJob in `queued` state.
  5. After queuing, poll the job's lifecycle/result every 3 seconds until done.

  This view makes clear that Scholiq delegates all wire-protocol work to the
  named OpenConnector connection — the banner explicitly states this.

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/DataMappingProfile?target=:t&direction=:d
    - POST /api/objects/scholiq/DataExchangeJob
    - GET  /api/objects/scholiq/DataExchangeJob/:id  (polling)

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="request-export-modal">
		<!-- Success / done state -->
		<div v-if="job && isTerminalState(job.lifecycle)"
			class="request-export-modal__result"
			role="status"
			aria-live="polite">
			<span :class="job.lifecycle === 'succeeded' ? 'icon-checkmark' : 'icon-close'"
				aria-hidden="true" />
			<h2>{{ t('scholiq', 'Job {state}', { state: job.lifecycle }) }}</h2>
			<p v-if="job.result">
				{{ t('scholiq', '{accepted} of {total} records accepted', {
					accepted: job.result.recordsAccepted || 0,
					total: job.result.recordsProcessed || 0,
				}) }}
			</p>
			<p v-if="job.errorMessage" class="request-export-modal__error">
				{{ job.errorMessage }}
			</p>
			<button class="button-vue" @click="reset">
				{{ t('scholiq', 'Queue another job') }}
			</button>
		</div>

		<!-- Polling / running state -->
		<div v-else-if="job"
			class="request-export-modal__polling"
			role="status"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<p>{{ t('scholiq', 'Job {state} — checking status…', { state: job.lifecycle }) }}</p>
			<p class="request-export-modal__delegate-note">
				{{ t('scholiq', 'Scholiq has delegated this job to the OpenConnector \'{target}\' connection. No wire-protocol code runs in Scholiq.', { target: job.target }) }}
			</p>
		</div>

		<!-- Error display (before job is queued) -->
		<div v-else-if="error"
			class="request-export-modal__error"
			role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
			<button class="button-vue" @click="error = null">
				{{ t('scholiq', 'Try again') }}
			</button>
		</div>

		<!-- Form -->
		<form v-else
			class="request-export-modal__form"
			@submit.prevent="submitJob">
			<h2>{{ t('scholiq', 'Request data exchange') }}</h2>

			<!-- Delegation notice -->
			<div class="request-export-modal__notice" role="note">
				<span class="icon-info" aria-hidden="true" />
				{{ t('scholiq', 'Scholiq queues this job and delegates wire-protocol execution to the named OpenConnector connection. No Edukoppeling, StUF, OSO-XML, Digikoppeling, or SAML code runs inside Scholiq.') }}
			</div>

			<!-- Direction -->
			<label for="req-direction">{{ t('scholiq', 'Direction') }}</label>
			<select id="req-direction" v-model="form.direction" required>
				<option value="export">
					{{ t('scholiq', 'Export (Scholiq → external)') }}
				</option>
				<option value="import">
					{{ t('scholiq', 'Import (external → Scholiq)') }}
				</option>
				<option value="sync">
					{{ t('scholiq', 'Sync (bidirectional)') }}
				</option>
			</select>

			<!-- Target -->
			<label for="req-target">{{ t('scholiq', 'Target (OpenConnector connection)') }}</label>
			<select id="req-target"
				v-model="form.target"
				required
				@change="onTargetChange">
				<option value="">
					{{ t('scholiq', '— select a target —') }}
				</option>
				<option value="bron-rod">
					{{ t('scholiq', 'BRON/ROD (DUO)') }}
				</option>
				<option value="oso">
					{{ t('scholiq', 'OSO transfer (PO→VO)') }}
				</option>
				<option value="leerplicht">
					{{ t('scholiq', 'Leerplicht (Digikoppeling)') }}
				</option>
				<option value="surfconext">
					{{ t('scholiq', 'SURFconext attributes') }}
				</option>
				<option value="hr">
					{{ t('scholiq', 'HR system sync') }}
				</option>
				<option value="custom">
					{{ t('scholiq', 'Custom…') }}
				</option>
			</select>
			<input v-if="form.target === 'custom'"
				v-model="form.customTarget"
				type="text"
				:placeholder="t('scholiq', 'Custom connection name')"
				:aria-label="t('scholiq', 'Custom connection name')">

			<!-- OSO notice -->
			<div v-if="form.target === 'oso'"
				class="request-export-modal__oso-notice"
				role="note">
				<span class="icon-info" aria-hidden="true" />
				{{ t('scholiq', 'OSO jobs enter \'pending-parent-review\' before executing. A parent must approve the dossier before it is sent.') }}
			</div>

			<!-- Mapping profile -->
			<label for="req-profile">{{ t('scholiq', 'Mapping profile (optional)') }}</label>
			<select id="req-profile" v-model="form.mappingProfileId">
				<option :value="null">
					{{ t('scholiq', '— none (pass raw objects) —') }}
				</option>
				<option v-for="profile in filteredProfiles"
					:key="profile.id"
					:value="profile.id">
					{{ profile.name }}
				</option>
			</select>

			<!-- Scope: schema -->
			<label for="req-schema">{{ t('scholiq', 'Source schema') }}</label>
			<select id="req-schema" v-model="form.scope.schema" required>
				<option value="">
					{{ t('scholiq', '— select a schema —') }}
				</option>
				<option v-for="s in sourceSchemas" :key="s" :value="s">
					{{ s }}
				</option>
			</select>

			<!-- Scope: cohort -->
			<label for="req-cohort">{{ t('scholiq', 'Cohort (optional)') }}</label>
			<input id="req-cohort"
				v-model="form.scope.cohortId"
				type="text"
				:placeholder="t('scholiq', 'Cohort UUID or leave empty for all')">

			<!-- Scope: period -->
			<label for="req-period">{{ t('scholiq', 'Period (optional)') }}</label>
			<input id="req-period"
				v-model="form.scope.period"
				type="text"
				:placeholder="t('scholiq', 'e.g. 2025-2026/1 or leave empty')">

			<button class="button-vue primary"
				type="submit"
				:disabled="submitting || !form.target || !form.scope.schema">
				{{ submitting ? t('scholiq', 'Queuing…') : t('scholiq', 'Queue job') }}
			</button>
		</form>
	</div>
</template>

<script>
export default {
	name: 'RequestExportModal',

	data() {
		return {
			form: {
				direction: 'export',
				target: '',
				customTarget: '',
				mappingProfileId: null,
				scope: {
					schema: '',
					cohortId: '',
					period: '',
					filters: {},
				},
			},
			profiles: [],
			submitting: false,
			job: null,
			error: null,
			pollTimer: null,
			sourceSchemas: [
				'learner-profile',
				'enrolment',
				'grade-entry',
				'final-grade',
				'attendance-record',
				'attendance-flag',
				'credential',
				'attestation',
				'cohort',
				'course',
				'programme',
			],
		}
	},

	computed: {
		filteredProfiles() {
			const target = this.form.target === 'custom' ? this.form.customTarget : this.form.target
			if (!target) {
				return this.profiles
			}

			return this.profiles.filter((p) => p.target === target && (!p.direction || p.direction === this.form.direction))
		},
	},

	mounted() {
		this.loadProfiles()
	},

	beforeDestroy() {
		this.stopPolling()
	},

	methods: {
		async loadProfiles() {
			try {
				const resp = await fetch('/index.php/apps/openregister/api/objects/scholiq/DataMappingProfile?limit=100', {
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				})

				if (!resp.ok) {
					return
				}

				const body = await resp.json()
				this.profiles = body.results || body.items || []
			} catch {
				// Non-fatal — profiles list just stays empty
			}
		},

		onTargetChange() {
			this.form.mappingProfileId = null
		},

		async submitJob() {
			this.submitting = true
			this.error = null

			const resolvedTarget = this.form.target === 'custom' ? this.form.customTarget : this.form.target

			const scope = {
				schema: this.form.scope.schema,
				filters: this.form.scope.filters || {},
				cohortId: this.form.scope.cohortId || null,
				period: this.form.scope.period || null,
			}

			const payload = {
				direction: this.form.direction,
				target: resolvedTarget,
				mappingProfileId: this.form.mappingProfileId || null,
				scope,
				requestedBy: OC?.currentUser || 'unknown',
				requestedAt: new Date().toISOString(),
				lifecycle: 'queued',
			}

			try {
				const resp = await fetch('/index.php/apps/openregister/api/objects/scholiq/DataExchangeJob', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify(payload),
				})

				if (!resp.ok) {
					const err = await resp.json().catch(() => ({}))
					this.error = err.message || t('scholiq', 'Failed to queue job. Please try again.')
					return
				}

				const created = await resp.json()
				this.job = created
				this.startPolling(created.id || created.uuid)
			} catch {
				this.error = t('scholiq', 'Failed to queue job. Please try again.')
			} finally {
				this.submitting = false
			}
		},

		startPolling(jobId) {
			this.pollTimer = setInterval(async () => {
				try {
					const resp = await fetch(
						`/index.php/apps/openregister/api/objects/scholiq/DataExchangeJob/${jobId}`,
						{ headers: { 'X-Requested-With': 'XMLHttpRequest' } },
					)

					if (resp.ok) {
						const updated = await resp.json()
						this.job = updated
						if (this.isTerminalState(updated.lifecycle)) {
							this.stopPolling()
						}
					}
				} catch {
					// Continue polling — transient error
				}
			}, 3000)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},

		isTerminalState(lifecycle) {
			return ['succeeded', 'failed', 'partial'].includes(lifecycle)
		},

		reset() {
			this.stopPolling()
			this.job = null
			this.error = null
			this.submitting = false
			this.form.target = ''
			this.form.mappingProfileId = null
			this.form.scope = { schema: '', cohortId: '', period: '', filters: {} }
		},
	},
}
</script>

<style scoped>
.request-export-modal {
	padding: var(--app-navigation-padding, 16px);
	max-width: 600px;
}

.request-export-modal h2 {
	margin-bottom: 16px;
}

.request-export-modal__notice,
.request-export-modal__oso-notice {
	background: var(--color-background-hover);
	border-left: 3px solid var(--color-primary);
	padding: 8px 12px;
	margin-bottom: 16px;
	border-radius: 4px;
	font-size: 0.9em;
}

.request-export-modal__oso-notice {
	border-color: var(--color-warning);
}

.request-export-modal__form label {
	display: block;
	margin-top: 12px;
	font-weight: bold;
}

.request-export-modal__form select,
.request-export-modal__form input[type='text'] {
	width: 100%;
	margin-top: 4px;
}

.request-export-modal__form button {
	margin-top: 20px;
}

.request-export-modal__polling,
.request-export-modal__result {
	text-align: center;
	padding: 32px;
}

.request-export-modal__delegate-note {
	font-style: italic;
	color: var(--color-text-lighter);
	margin-top: 8px;
}

.request-export-modal__error {
	color: var(--color-error);
}
</style>
