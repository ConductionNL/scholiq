<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqAiProcessingDisclosure — the sovereign-ai-guarantee disclosure
 surface a school hands to its DPO (sovereign-ai-guarantee).

 A singleton disclosure page (no `:id` route, mirrors
 ScholiqAccessibilityStatement.vue's exact shape): the school's
 SovereigntyPolicy tier, editable inline via OpenRegister's existing generic
 object-create/update endpoint (useObjectStore.saveObject — no bespoke write
 controller, per ADR-022, mirrors CourseBuilder.vue/CourseTemplate's
 frontend-orchestration precedent), plus every Hermiq-governed AI feature's
 DPO/lifecycle state, its AVG Art. 30 processing-activity fields, and its
 locality verdict, composed server-side by AiProcessingDisclosureController
 (a cross-app read a single declarative OR query cannot express).

 THE ONE NON-NEGOTIABLE RULE THIS COMPONENT ENFORCES: no verdict badge ever
 renders green/"compliant" unless the underlying payload says
 `verified: true`. `unverified` renders amber ALWAYS, even when the school's
 policy tier is permissive enough that the feature is allowed to run — an
 unverifiable claim must never look like a green tick.

 @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy
 @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
-->

<template>
	<div class="ai-processing-disclosure">
		<NcLoadingIcon v-if="loading" :size="44" class="ai-processing-disclosure__loading" />

		<template v-else>
			<h2>{{ t('scholiq', 'AI processing disclosure') }}</h2>
			<p class="ai-processing-disclosure__intro">
				{{ t('scholiq', 'A school-verifiable record of where this school\'s AI-assisted processing runs, for your DPO.') }}
			</p>

			<!-- Sovereignty policy tier — editable. -->
			<section class="ai-processing-disclosure__policy">
				<h3>{{ t('scholiq', 'Locality policy') }}</h3>
				<NcSelect
					v-model="policyForm.policy"
					:options="policyOptions"
					:reduce="(opt) => opt.value"
					:clearable="false"
					:input-label="t('scholiq', 'Locality policy tier')"
					:aria-label-combobox="t('scholiq', 'Locality policy tier')" />

				<label class="ai-processing-disclosure__field-label" for="ai-disclosure-rationale">
					{{ t('scholiq', 'Rationale (optional)') }}
				</label>
				<textarea id="ai-disclosure-rationale"
					v-model="policyForm.rationale"
					class="ai-processing-disclosure__rationale"
					:placeholder="t('scholiq', 'Why this tier was chosen, for the DPO record.')" />

				<NcButton type="primary" :disabled="saving" @click="savePolicy">
					{{ saving ? t('scholiq', 'Saving…') : t('scholiq', 'Save policy') }}
				</NcButton>

				<p v-if="policy.setAt" class="ai-processing-disclosure__meta">
					{{ t('scholiq', 'Last set by {setBy} on {setAt}', { setBy: policy.setBy || t('scholiq', 'unknown'), setAt: policy.setAt }) }}
				</p>
			</section>

			<!-- Hermiq-governed AI features + locality verdicts. -->
			<section class="ai-processing-disclosure__features">
				<h3>{{ t('scholiq', 'AI features') }}</h3>

				<NcNoteCard v-if="!hermiqInstalled" type="warning">
					{{ t('scholiq', 'Install and enable the Hermiq app to see the EU AI Act high-risk AI-feature register here.') }}
				</NcNoteCard>

				<p v-else-if="features.length === 0" class="ai-processing-disclosure__no-features">
					{{ t('scholiq', 'No AI features are registered in Hermiq yet.') }}
				</p>

				<table v-else class="ai-processing-disclosure__table">
					<thead>
						<tr>
							<th>{{ t('scholiq', 'Feature') }}</th>
							<th>{{ t('scholiq', 'DPO state') }}</th>
							<th>{{ t('scholiq', 'Purpose (AVG)') }}</th>
							<th>{{ t('scholiq', 'Locality') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="feature in features" :key="feature.slug">
							<td>{{ feature.name || feature.slug }}</td>
							<td>{{ feature.lifecycle }}</td>
							<td>{{ (feature.aiProcessingActivity && feature.aiProcessingActivity.doelbinding) || '—' }}</td>
							<td>
								<span class="ai-processing-disclosure__badge" :class="badgeClass(feature)">
									{{ badgeLabel(feature) }}
								</span>
								<p class="ai-processing-disclosure__evidence">
									{{ feature.evidence }}
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

const REGISTER = 'scholiq'
const POLICY_SCHEMA = 'SovereigntyPolicy'
const POLICY_TYPE = `${REGISTER}-${POLICY_SCHEMA}`

export default {
	name: 'ScholiqAiProcessingDisclosure',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			saving: false,
			hermiqInstalled: false,
			features: [],
			policy: { id: null, policy: 'eu-hosted-allowed', rationale: null, setBy: null, setAt: null },
			policyForm: { policy: 'eu-hosted-allowed', rationale: '' },
		}
	},

	computed: {
		/**
		 * NcSelect options for the three-tier locality policy, strictest first.
		 *
		 * @return {Array<{value: string, label: string}>} Options.
		 */
		policyOptions() {
			return [
				{ value: 'on-premises-only', label: this.t('scholiq', 'On-premises only') },
				{ value: 'eu-hosted-allowed', label: this.t('scholiq', 'EU-hosted allowed') },
				{ value: 'third-country-allowed', label: this.t('scholiq', 'Third-country allowed') },
			]
		},
	},

	async mounted() {
		await Promise.all([this.loadPolicy(), this.loadDisclosure()])
		this.loading = false
	},

	methods: {
		/**
		 * Load the SovereigntyPolicy singleton (the first object, or the
		 * schema default) into both the display and edit-form state.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy
		 */
		async loadPolicy() {
			const store = useObjectStore()

			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(POLICY_TYPE, POLICY_SCHEMA, REGISTER)
			}

			const results = typeof store.fetchCollection === 'function'
				? await store.fetchCollection(POLICY_TYPE, { _limit: 1 }).catch(() => [])
				: []

			const existing = Array.isArray(results) && results.length > 0 ? results[0] : null

			if (existing) {
				this.policy = {
					id: existing.id ?? (existing['@self'] && existing['@self'].id) ?? null,
					policy: existing.policy || 'eu-hosted-allowed',
					rationale: existing.rationale || null,
					setBy: existing.setBy || null,
					setAt: existing.setAt || null,
				}
			}

			this.policyForm = { policy: this.policy.policy, rationale: this.policy.rationale || '' }
		},

		/**
		 * Load the server-composed disclosure payload (Hermiq features + AVG
		 * carrier + locality verdicts) from AiProcessingDisclosureController.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
		 */
		async loadDisclosure() {
			try {
				const url = generateUrl('/apps/scholiq/api/ai-processing-disclosure')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) {
					throw new Error(`disclosure fetch failed: ${resp.status}`)
				}
				const data = await resp.json()
				this.hermiqInstalled = !!data.hermiqInstalled
				this.features = Array.isArray(data.features) ? data.features : []
			} catch (err) {
				this.hermiqInstalled = false
				this.features = []
				// eslint-disable-next-line no-console
				console.error('[ScholiqAiProcessingDisclosure] loadDisclosure error', err)
			}
		},

		/**
		 * Persist the edited policy tier/rationale via OpenRegister's generic
		 * object-create/update endpoint — no bespoke write controller.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-a-school-sets-its-locality-policy
		 */
		async savePolicy() {
			this.saving = true
			try {
				const store = useObjectStore()
				const currentUser = getCurrentUser()

				const payload = {
					policy: this.policyForm.policy,
					rationale: this.policyForm.rationale || null,
					setBy: currentUser ? currentUser.uid : null,
					setAt: new Date().toISOString(),
				}
				if (this.policy.id) {
					payload.id = this.policy.id
				}

				const saved = typeof store.saveObject === 'function'
					? await store.saveObject(POLICY_TYPE, payload)
					: null

				if (saved) {
					this.policy = {
						id: saved.id ?? this.policy.id,
						policy: saved.policy || payload.policy,
						rationale: saved.rationale ?? payload.rationale,
						setBy: saved.setBy ?? payload.setBy,
						setAt: saved.setAt ?? payload.setAt,
					}
				}

				// Re-fetch the composed disclosure so every feature's
				// policyCompliant verdict reflects the newly saved tier.
				await this.loadDisclosure()
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[ScholiqAiProcessingDisclosure] savePolicy error', err)
			} finally {
				this.saving = false
			}
		},

		/**
		 * The CSS class for a feature's verdict badge. No badge ever renders
		 * the `compliant` (green) class unless `verified` is strictly true —
		 * the one rule this component exists to enforce.
		 *
		 * @param {object} feature A disclosure row.
		 * @return {string} One of `ai-processing-disclosure__badge--compliant`,
		 *                  `--violates`, or `--unverified`.
		 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-an-unverified-locality-never-renders-as-compliant
		 */
		badgeClass(feature) {
			if (feature.verified !== true) {
				return 'ai-processing-disclosure__badge--unverified'
			}
			return feature.policyCompliant === true
				? 'ai-processing-disclosure__badge--compliant'
				: 'ai-processing-disclosure__badge--violates'
		},

		/**
		 * The human-readable label matching badgeClass().
		 *
		 * @param {object} feature A disclosure row.
		 * @return {string} Translated label.
		 */
		badgeLabel(feature) {
			if (feature.verified !== true) {
				return this.t('scholiq', 'Unverified')
			}
			return feature.policyCompliant === true
				? this.t('scholiq', 'Compliant')
				: this.t('scholiq', 'Violates policy')
		},
	},
}
</script>

<style scoped lang="scss">
.ai-processing-disclosure {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 960px;

	&__loading {
		margin: calc(var(--default-grid-baseline, 4px) * 8) auto;
	}

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	}

	&__policy {
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 8);
	}

	&__field-label {
		display: block;
		font-weight: bold;
		margin: calc(var(--default-grid-baseline, 4px) * 3) 0 calc(var(--default-grid-baseline, 4px) * 1);
	}

	&__rationale {
		width: 100%;
		min-height: 4em;
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	}

	&__no-features {
		color: var(--color-text-maxcontrast);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th, td {
			text-align: left;
			padding: calc(var(--default-grid-baseline, 4px) * 2);
			border-bottom: 1px solid var(--color-border);
			vertical-align: top;
		}
	}

	&__badge {
		display: inline-block;
		padding: 2px calc(var(--default-grid-baseline, 4px) * 2);
		border-radius: var(--border-radius-pill, 16px);
		font-weight: bold;
		font-size: 0.85em;

		&--compliant {
			background-color: var(--color-success, #2b8a3e);
			color: var(--color-primary-text, #fff);
		}

		&--violates {
			background-color: var(--color-error, #c0392b);
			color: var(--color-primary-text, #fff);
		}

		&--unverified {
			background-color: var(--color-warning, #e0a100);
			color: var(--color-main-text, #000);
		}
	}

	&__evidence {
		margin: calc(var(--default-grid-baseline, 4px) * 1) 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
