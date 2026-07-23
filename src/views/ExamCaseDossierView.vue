<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExamCaseDossierView — shared custom page component for the ExemptionCase
 and FraudCase manifest detail pages (type: custom), selected by
 `config.schema` ("exemption-case" | "fraud-case").

 The "custom view only where a manifest page can't render it" exception
 (design.md §1, §8): "who may see what" is genuine conditional-rendering
 logic a manifest detail page cannot express — within FraudCase's already
 object-level-restricted readable set (x-property-rbac: admin, examboard,
 the accused learner, the reporter), the accused and the reporter must NOT
 see `hearingRecords`/decision-internal fields; only an examboard member (or
 admin) does (design.md §8, "the accused and reporter should not see
 internal hearing deliberation notes, only the case outline and eventual
 outcome"). This is an APPLICATION-LEVEL UI CONVENTION, NOT a server-enforced
 field-level RBAC guarantee — this register has no field-level read/write
 RBAC primitive at HEAD (the same documented residual gap as
 `ProctoringSession.flags[].reviewDecision`, restated in design.md §8).
 Anyone holding object-level read access (including the accused/reporter)
 retains full field-level read access to the raw object via the generic
 OpenRegister object API — this view is a rendering convention, not a
 security boundary.

 KNOWN LIMITATION: there is no client-side signal for "is this NC user an
 `examboard` group member" today (unlike `primaryRole`, which
 `PageController`/`DashboardRoleService` already resolve and expose via
 `loadState`). Until an `examboard` membership flag is added to that
 resolver and exposed the same way, this view treats `isAdmin` as the
 examboard proxy for the withholding decision below — under-inclusive
 (a real board member who isn't also an NC admin sees the redacted view
 too) but never over-inclusive (never leaks internals to an unauthorised
 viewer). Flagged here rather than silently assumed complete.

 @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-ui-gated-within-that-set
 @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-frontend-is-declarative-with-one-shared-custom-detail-view
-->

<template>
	<div class="exam-case-dossier">
		<div v-if="loading" class="exam-case-dossier__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading case...') }}</span>
		</div>

		<div v-else-if="error" class="exam-case-dossier__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<div v-else-if="!caseObject" class="exam-case-dossier__empty" role="status">
			<p>{{ t('scholiq', 'Case not found.') }}</p>
		</div>

		<template v-else>
			<header class="exam-case-dossier__header">
				<h2>{{ isExemption ? t('scholiq', 'Exemption request') : t('scholiq', 'Fraud case') }}</h2>
				<span class="exam-case-dossier__status" :class="'exam-case-dossier__status--' + caseObject.lifecycle">
					{{ caseObject.lifecycle }}
				</span>
			</header>

			<!-- ExemptionCase data -->
			<section v-if="isExemption" class="exam-case-dossier__section">
				<dl class="exam-case-dossier__fields">
					<dt>{{ t('scholiq', 'Learner') }}</dt>
					<dd>{{ caseObject.learnerId }}</dd>
					<dt>{{ t('scholiq', 'Component') }}</dt>
					<dd>{{ caseObject.componentId }}</dd>
					<dt>{{ t('scholiq', 'Grounds') }}</dt>
					<dd>{{ caseObject.groundsKind }}</dd>
					<dt>{{ t('scholiq', 'Description') }}</dt>
					<dd>{{ caseObject.groundsDescription }}</dd>
					<template v-if="caseObject.decisionRationale">
						<dt>{{ t('scholiq', 'Decision rationale') }}</dt>
						<dd>{{ caseObject.decisionRationale }}</dd>
						<dt>{{ t('scholiq', 'Policy reference') }}</dt>
						<dd>{{ caseObject.policyReference }}</dd>
					</template>
				</dl>

				<div v-if="caseObject.lifecycle === 'submitted'" class="exam-case-dossier__actions">
					<button class="button-vue" :disabled="saving" @click="startAssessment">
						{{ t('scholiq', 'Start assessment') }}
					</button>
				</div>

				<div v-if="caseObject.lifecycle === 'in-assessment'" class="exam-case-dossier__decision-form">
					<label for="exemption-rationale">{{ t('scholiq', 'Decision rationale') }}</label>
					<textarea id="exemption-rationale" v-model="decisionRationale" />
					<label for="exemption-policy">{{ t('scholiq', 'Policy reference') }}</label>
					<input id="exemption-policy" v-model="policyReference" type="text">
					<div class="exam-case-dossier__actions">
						<button class="button-vue" :disabled="saving" @click="grantExemption">
							{{ t('scholiq', 'Grant') }}
						</button>
						<button class="button-vue button-vue--error" :disabled="saving" @click="rejectExemption">
							{{ t('scholiq', 'Reject') }}
						</button>
					</div>
				</div>

				<div v-if="['submitted', 'in-assessment'].includes(caseObject.lifecycle)" class="exam-case-dossier__actions">
					<button class="button-vue" :disabled="saving" @click="withdrawExemption">
						{{ t('scholiq', 'Withdraw') }}
					</button>
				</div>
			</section>

			<!-- FraudCase data -->
			<section v-else class="exam-case-dossier__section">
				<dl class="exam-case-dossier__fields">
					<dt>{{ t('scholiq', 'Accused learner') }}</dt>
					<dd>{{ caseObject.accusedLearnerId }}</dd>
					<dt>{{ t('scholiq', 'Reported by') }}</dt>
					<dd>{{ caseObject.reporterId }}</dd>
					<dt>{{ t('scholiq', 'Allegation') }}</dt>
					<dd>{{ caseObject.allegation }}</dd>
					<template v-if="caseObject.verdict">
						<dt>{{ t('scholiq', 'Verdict') }}</dt>
						<dd>{{ caseObject.verdict }}</dd>
						<dt>{{ t('scholiq', 'Appeal deadline') }}</dt>
						<dd>{{ caseObject.appealDeadline }}</dd>
					</template>
				</dl>

				<!-- design.md §8: hearing/decision internals are withheld from anyone
				     who is not an examboard member (or admin) — a UI convention, not
				     server-enforced field RBAC. See file-header note. -->
				<div v-if="canSeeInternals" class="exam-case-dossier__internal">
					<h3>{{ t('scholiq', 'Hearing records') }}</h3>
					<ul v-if="(caseObject.hearingRecords || []).length > 0">
						<li v-for="(record, idx) in caseObject.hearingRecords" :key="idx">
							{{ record.heldAt }} — {{ record.notes }}
						</li>
					</ul>
					<p v-else>
						{{ t('scholiq', 'No hearing records yet.') }}
					</p>

					<template v-if="caseObject.decisionRationale">
						<h3>{{ t('scholiq', 'Decision rationale') }}</h3>
						<p>{{ caseObject.decisionRationale }}</p>
					</template>
					<template v-if="caseObject.sanctionType">
						<h3>{{ t('scholiq', 'Sanction') }}</h3>
						<p>{{ caseObject.sanctionType }} — {{ caseObject.sanctionDurationMonths }} {{ t('scholiq', 'month(s)') }} — {{ caseObject.sanctionScope }}</p>
					</template>
				</div>
				<p v-else class="exam-case-dossier__redacted-notice">
					{{ t('scholiq', 'Hearing details and decision internals are only visible to the exam board.') }}
				</p>

				<div v-if="caseObject.lifecycle === 'reported'" class="exam-case-dossier__decision-form">
					<label for="hearing-date">{{ t('scholiq', 'Hearing date') }}</label>
					<input id="hearing-date" v-model="hearingDate" type="datetime-local">
					<div class="exam-case-dossier__actions">
						<button class="button-vue" :disabled="saving" @click="scheduleHearing">
							{{ t('scholiq', 'Schedule hearing') }}
						</button>
					</div>
				</div>

				<div v-if="caseObject.lifecycle === 'hearing-scheduled'" class="exam-case-dossier__actions">
					<button class="button-vue" :disabled="saving" @click="holdHearing">
						{{ t('scholiq', 'Record hearing held') }}
					</button>
				</div>

				<div v-if="caseObject.lifecycle === 'heard'" class="exam-case-dossier__decision-form">
					<label for="fraud-verdict">{{ t('scholiq', 'Verdict') }}</label>
					<select id="fraud-verdict" v-model="verdict">
						<option value="" disabled>
							{{ t('scholiq', 'Select a verdict') }}
						</option>
						<option value="fraud-proven">
							{{ t('scholiq', 'Fraud proven') }}
						</option>
						<option value="unfounded">
							{{ t('scholiq', 'Unfounded') }}
						</option>
					</select>
					<label for="fraud-rationale">{{ t('scholiq', 'Decision rationale') }}</label>
					<textarea id="fraud-rationale" v-model="decisionRationale" />

					<template v-if="verdict === 'fraud-proven'">
						<label for="sanction-type">{{ t('scholiq', 'Sanction type') }}</label>
						<select id="sanction-type" v-model="sanctionType">
							<option value="" disabled>
								{{ t('scholiq', 'Select a sanction') }}
							</option>
							<option value="warning">
								{{ t('scholiq', 'Warning') }}
							</option>
							<option value="grade-annulment">
								{{ t('scholiq', 'Grade annulment') }}
							</option>
							<option value="resubmission-required">
								{{ t('scholiq', 'Resubmission required') }}
							</option>
							<option value="suspension">
								{{ t('scholiq', 'Suspension') }}
							</option>
							<option value="exclusion">
								{{ t('scholiq', 'Exclusion') }}
							</option>
						</select>
						<label for="sanction-duration">{{ t('scholiq', 'Sanction duration (months, max 12)') }}</label>
						<input id="sanction-duration"
							v-model.number="sanctionDurationMonths"
							type="number"
							min="1"
							max="12">
						<label for="sanction-scope">{{ t('scholiq', 'Sanction scope') }}</label>
						<select id="sanction-scope" v-model="sanctionScope">
							<option value="" disabled>
								{{ t('scholiq', 'Select a scope') }}
							</option>
							<option value="single-assessment">
								{{ t('scholiq', 'Single assessment') }}
							</option>
							<option value="course">
								{{ t('scholiq', 'Course') }}
							</option>
							<option value="programme">
								{{ t('scholiq', 'Programme') }}
							</option>
						</select>
					</template>

					<div class="exam-case-dossier__actions">
						<button class="button-vue" :disabled="saving" @click="decideFraudCase">
							{{ t('scholiq', 'Decide') }}
						</button>
					</div>
				</div>

				<div v-if="['reported', 'hearing-scheduled'].includes(caseObject.lifecycle)" class="exam-case-dossier__actions">
					<button class="button-vue button-vue--error" :disabled="saving" @click="dismissFraudCase">
						{{ t('scholiq', 'Dismiss') }}
					</button>
				</div>
			</section>

			<p v-if="saveError" role="alert" class="exam-case-dossier__error-inline">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

const SCHEMA_TITLES = {
	'exemption-case': 'ExemptionCase',
	'fraud-case': 'FraudCase',
}

export default {
	name: 'ExamCaseDossierView',

	props: {
		/** OpenRegister object UUID, injected by vue-router from the `:id` route param. */
		id: {
			type: String,
			required: true,
		},
		/** `config.register` forwarded by CnPageRenderer. */
		register: {
			type: String,
			default: 'scholiq',
		},
		/** `config.schema` forwarded by CnPageRenderer — "exemption-case" | "fraud-case". */
		schema: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			caseObject: null,
			loading: false,
			saving: false,
			error: null,
			saveError: null,
			decisionRationale: '',
			policyReference: '',
			hearingDate: '',
			verdict: '',
			sanctionType: '',
			sanctionDurationMonths: null,
			sanctionScope: '',
		}
	},

	computed: {
		isExemption() {
			return this.schema === 'exemption-case'
		},

		/**
		 * See file-header note: `isAdmin` is the best available client-side
		 * proxy for "examboard member" until DashboardRoleService exposes a
		 * real `examboard` membership flag via loadState.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-ui-gated-within-that-set
		 */
		canSeeInternals() {
			return !!getCurrentUser()?.isAdmin
		},
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.loadCase()
			},
		},
	},

	methods: {
		schemaTitle() {
			return SCHEMA_TITLES[this.schema] || this.schema
		},

		objectUrl(suffix = '') {
			return generateUrl(
				`/apps/openregister/api/objects/${this.register}/${this.schemaTitle()}/${this.id}${suffix}`,
			)
		},

		/**
		 * Load the case object from OpenRegister.
		 *
		 * @return {Promise<void>}
		 */
		async loadCase() {
			this.loading = true
			this.error = null
			try {
				const resp = await fetch(this.objectUrl(), {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) {
					throw new Error(`Case fetch failed: ${resp.status}`)
				}
				const json = await resp.json()
				this.caseObject = json.object ?? json ?? null
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load case. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[ExamCaseDossierView] loadCase error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Dispatch a lifecycle transition against this case, with an optional payload.
		 *
		 * @param {string} action Transition name (e.g. "grant", "decide").
		 * @param {object} payload Fields to submit alongside the transition (read by
		 *                         the server-side guard as part of the transitioning object).
		 * @return {Promise<void>}
		 */
		async transition(action, payload = {}) {
			this.saving = true
			this.saveError = null
			try {
				const resp = await fetch(this.objectUrl(`/transition/${action}`), {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(payload),
				})
				if (!resp.ok) {
					throw new Error(`Transition '${action}' failed: ${resp.status}`)
				}
				await this.loadCase()
			} catch (err) {
				this.saveError = this.t('scholiq', 'Action failed. Please check the required fields and try again.')
				// eslint-disable-next-line no-console
				console.error(`[ExamCaseDossierView] transition(${action}) error`, err)
			} finally {
				this.saving = false
			}
		},

		startAssessment() {
			return this.transition('startAssessment')
		},

		grantExemption() {
			return this.transition('grant', {
				decisionRationale: this.decisionRationale,
				policyReference: this.policyReference,
			})
		},

		rejectExemption() {
			return this.transition('reject', {
				decisionRationale: this.decisionRationale,
				policyReference: this.policyReference,
			})
		},

		withdrawExemption() {
			return this.transition('withdraw')
		},

		scheduleHearing() {
			return this.transition('scheduleHearing', { hearingDate: this.hearingDate })
		},

		holdHearing() {
			return this.transition('holdHearing')
		},

		decideFraudCase() {
			const payload = {
				verdict: this.verdict,
				decisionRationale: this.decisionRationale,
			}
			if (this.verdict === 'fraud-proven') {
				payload.sanctionType = this.sanctionType
				payload.sanctionDurationMonths = this.sanctionDurationMonths
				payload.sanctionScope = this.sanctionScope
			}
			return this.transition('decide', payload)
		},

		dismissFraudCase() {
			return this.transition('dismiss')
		},
	},
}
</script>

<style scoped lang="scss">
.exam-case-dossier {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);

	&__header {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline, 8px);
	}

	&__status {
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background-color: var(--color-background-dark);
		font-size: 0.85em;
		text-transform: capitalize;
	}

	&__fields {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 4px var(--default-grid-baseline, 8px);

		dt {
			font-weight: bold;
		}
	}

	&__decision-form {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 480px;

		textarea {
			min-height: 80px;
		}
	}

	&__actions {
		display: flex;
		gap: var(--default-grid-baseline, 8px);
	}

	&__redacted-notice {
		font-style: italic;
		color: var(--color-text-maxcontrast);
	}

	&__error,
	&__error-inline {
		color: var(--color-error);
	}
}
</style>
