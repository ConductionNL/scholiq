<!--
  LearningPlanEditor.vue
  Custom page component for the LearningPlanEditor manifest page (type: custom).

  Goals-and-support-measures editor for a LearningPlan:
  1. Load the LearningPlan (:planId) and its template (if any).
  2. Render goals grouped under the template's sections / goalDomains.
  3. Allow add / edit / remove for goals and supportMeasures.
  4. Save changes back to the LearningPlan via PUT.
  5. Read-only once the plan is 'active' — show a "Create new version" button
     that clones the plan with version+1 + supersedesId (lifecycle: draft).

  Uses Options API + direct fetch (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="lp-editor">
		<!-- Loading -->
		<div v-if="loading" class="lp-editor__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading learning plan editor...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="loadError" class="lp-editor__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ loadError }}</p>
		</div>

		<template v-else>
			<!-- Header -->
			<header class="lp-editor__header">
				<h2>
					{{ t('scholiq', 'Learning Plan Editor') }}
					<span class="lp-editor__version-badge">
						{{ t('scholiq', 'v{v}', { v: plan.version || 1 }) }}
					</span>
				</h2>
				<p class="lp-editor__meta">
					{{ t('scholiq', 'Learner: {id}', { id: plan.learnerId || '—' }) }}
					— {{ t('scholiq', 'Kind: {kind}', { kind: plan.kind || '—' }) }}
					— {{ t('scholiq', 'Status: {status}', { status: plan.lifecycle || 'draft' }) }}
				</p>
			</header>

			<!-- Read-only banner when active -->
			<div v-if="isReadOnly" class="lp-editor__readonly-banner" role="note">
				<span class="icon-info" aria-hidden="true" />
				<span>{{ t('scholiq', 'This plan is active and cannot be edited. To make changes, create a new version.') }}</span>
				<button
					class="button-vue lp-editor__new-version-btn"
					:disabled="creatingVersion"
					@click="createNewVersion">
					<span v-if="creatingVersion" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Create new version') }}
				</button>
			</div>

			<div v-if="newVersionError" class="lp-editor__save-error" role="alert">
				{{ newVersionError }}
			</div>

			<!-- Goals section -->
			<section class="lp-editor__section">
				<h3>{{ t('scholiq', 'Goals') }}</h3>

				<!-- Goals grouped by domain -->
				<div
					v-for="domain in goalDomains"
					:key="domain"
					class="lp-editor__domain-group">
					<h4 class="lp-editor__domain-label">
						{{ domain }}
					</h4>

					<div
						v-for="goal in goalsByDomain(domain)"
						:key="goal.goalId"
						class="lp-editor__goal-row">
						<div class="lp-editor__goal-fields">
							<div class="lp-editor__field">
								<label>{{ t('scholiq', 'Description') }}</label>
								<textarea
									v-model="goal.description"
									class="lp-editor__textarea"
									rows="2"
									:disabled="isReadOnly"
									:aria-label="t('scholiq', 'Goal description')" />
							</div>
							<div class="lp-editor__field-row">
								<div class="lp-editor__field lp-editor__field--half">
									<label>{{ t('scholiq', 'Baseline') }}</label>
									<input
										v-model="goal.baseline"
										type="text"
										class="lp-editor__input"
										:disabled="isReadOnly"
										:aria-label="t('scholiq', 'Baseline')">
								</div>
								<div class="lp-editor__field lp-editor__field--half">
									<label>{{ t('scholiq', 'Target') }}</label>
									<input
										v-model="goal.target"
										type="text"
										class="lp-editor__input"
										:disabled="isReadOnly"
										:aria-label="t('scholiq', 'Target')">
								</div>
								<div class="lp-editor__field lp-editor__field--half">
									<label>{{ t('scholiq', 'Target date') }}</label>
									<input
										v-model="goal.targetDate"
										type="date"
										class="lp-editor__input"
										:disabled="isReadOnly"
										:aria-label="t('scholiq', 'Target date')">
								</div>
								<div class="lp-editor__field lp-editor__field--half">
									<label>{{ t('scholiq', 'Status') }}</label>
									<select
										v-model="goal.status"
										class="lp-editor__select"
										:disabled="isReadOnly">
										<option value="open">
											{{ t('scholiq', 'Open') }}
										</option>
										<option value="met">
											{{ t('scholiq', 'Met') }}
										</option>
										<option value="adjusted">
											{{ t('scholiq', 'Adjusted') }}
										</option>
										<option value="dropped">
											{{ t('scholiq', 'Dropped') }}
										</option>
									</select>
								</div>
							</div>
						</div>
						<button
							v-if="!isReadOnly"
							class="button-vue button-vue--error lp-editor__remove-btn"
							:aria-label="t('scholiq', 'Remove goal')"
							@click="removeGoal(goal.goalId)">
							<span class="icon-delete" aria-hidden="true" />
						</button>
					</div>

					<button
						v-if="!isReadOnly"
						class="button-vue lp-editor__add-btn"
						@click="addGoal(domain)">
						+ {{ t('scholiq', 'Add goal in {domain}', { domain }) }}
					</button>
				</div>

				<!-- Goals with no domain / free-form -->
				<div v-if="goalsByDomain(null).length > 0 || goalDomains.length === 0" class="lp-editor__domain-group">
					<h4 class="lp-editor__domain-label">
						{{ t('scholiq', 'Other goals') }}
					</h4>
					<div
						v-for="goal in goalsByDomain(null)"
						:key="goal.goalId"
						class="lp-editor__goal-row">
						<div class="lp-editor__goal-fields">
							<div class="lp-editor__field">
								<label>{{ t('scholiq', 'Description') }}</label>
								<textarea
									v-model="goal.description"
									class="lp-editor__textarea"
									rows="2"
									:disabled="isReadOnly"
									:aria-label="t('scholiq', 'Goal description')" />
							</div>
						</div>
						<button
							v-if="!isReadOnly"
							class="button-vue button-vue--error lp-editor__remove-btn"
							:aria-label="t('scholiq', 'Remove goal')"
							@click="removeGoal(goal.goalId)">
							<span class="icon-delete" aria-hidden="true" />
						</button>
					</div>
					<button
						v-if="!isReadOnly"
						class="button-vue lp-editor__add-btn"
						@click="addGoal(null)">
						+ {{ t('scholiq', 'Add goal') }}
					</button>
				</div>
			</section>

			<!-- Support measures section -->
			<section class="lp-editor__section">
				<h3>{{ t('scholiq', 'Support measures') }}</h3>

				<div
					v-for="measure in measures"
					:key="measure.measureId"
					class="lp-editor__measure-row">
					<div class="lp-editor__measure-fields">
						<div class="lp-editor__field">
							<label>{{ t('scholiq', 'Description') }}</label>
							<input
								v-model="measure.description"
								type="text"
								class="lp-editor__input"
								:disabled="isReadOnly"
								:aria-label="t('scholiq', 'Measure description')">
						</div>
						<div class="lp-editor__field-row">
							<div class="lp-editor__field lp-editor__field--half">
								<label>{{ t('scholiq', 'Responsible') }}</label>
								<input
									v-model="measure.responsibleId"
									type="text"
									class="lp-editor__input"
									:disabled="isReadOnly"
									:placeholder="t('scholiq', 'User ID or name')"
									:aria-label="t('scholiq', 'Responsible person')">
							</div>
							<div class="lp-editor__field lp-editor__field--half">
								<label>{{ t('scholiq', 'Start date') }}</label>
								<input
									v-model="measure.startDate"
									type="date"
									class="lp-editor__input"
									:disabled="isReadOnly"
									:aria-label="t('scholiq', 'Start date')">
							</div>
							<div class="lp-editor__field lp-editor__field--half">
								<label>{{ t('scholiq', 'End date') }}</label>
								<input
									v-model="measure.endDate"
									type="date"
									class="lp-editor__input"
									:disabled="isReadOnly"
									:aria-label="t('scholiq', 'End date')">
							</div>
						</div>
					</div>
					<button
						v-if="!isReadOnly"
						class="button-vue button-vue--error lp-editor__remove-btn"
						:aria-label="t('scholiq', 'Remove measure')"
						@click="removeMeasure(measure.measureId)">
						<span class="icon-delete" aria-hidden="true" />
					</button>
				</div>

				<button
					v-if="!isReadOnly"
					class="button-vue lp-editor__add-btn"
					@click="addMeasure">
					+ {{ t('scholiq', 'Add support measure') }}
				</button>
			</section>

			<!-- Save actions -->
			<div v-if="!isReadOnly" class="lp-editor__save-bar">
				<button
					class="button-vue button-vue--primary lp-editor__save-btn"
					:disabled="saving"
					@click="save">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Save learning plan') }}
				</button>
				<span v-if="saved" class="lp-editor__saved-msg">{{ t('scholiq', 'Saved.') }}</span>
				<span v-if="saveError" class="lp-editor__save-error" role="alert">{{ saveError }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

let _uid = 0
function uid() {
	return `goal-${++_uid}-${Date.now()}`
}
function midUid() {
	return `measure-${++_uid}-${Date.now()}`
}

export default {
	name: 'LearningPlanEditor',

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
			/** @type {Array<object>} */
			goals: [],
			/** @type {Array<object>} */
			measures: [],
			/** @type {string[]} */
			templateDomains: [],
			loading: false,
			saving: false,
			saved: false,
			creatingVersion: false,
			loadError: null,
			saveError: null,
			newVersionError: null,
		}
	},

	computed: {
		/**
		 * Plan is read-only when lifecycle is active, closed, or superseded.
		 *
		 * @return {boolean}
		 */
		isReadOnly() {
			const lc = this.plan.lifecycle ?? 'draft'
			return lc === 'active' || lc === 'closed' || lc === 'superseded'
		},

		/**
		 * Goal domains to render: template domains, or derived from goals if no template.
		 *
		 * @return {string[]}
		 */
		goalDomains() {
			if (this.templateDomains.length > 0) {
				return this.templateDomains
			}
			// Derive unique non-null domains from current goals.
			const domains = [...new Set(this.goals.map((g) => g.domain).filter(Boolean))]
			return domains
		},
	},

	watch: {
		planId: {
			immediate: true,
			handler() {
				this.loadPlan()
			},
		},
	},

	methods: {
		/**
		 * Load the LearningPlan and its template.
		 *
		 * @return {Promise<void>}
		 */
		async loadPlan() {
			this.loading = true
			this.loadError = null
			this.saved = false

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
				this.goals = (this.plan.goals ?? []).map((g) => ({ ...g }))
				this.measures = (this.plan.supportMeasures ?? []).map((m) => ({ ...m }))

				if (this.plan.templateId) {
					await this.loadTemplate(this.plan.templateId)
				}
			} catch (err) {
				this.loadError = this.t('scholiq', 'Failed to load learning plan. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LearningPlanEditor] loadPlan error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the LearningPlanTemplate to get goalDomains.
		 *
		 * @param {string} templateId Template UUID.
		 * @return {Promise<void>}
		 */
		async loadTemplate(templateId) {
			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/LearningPlanTemplate/${templateId}`)
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) {
					return
				}

				const json = await resp.json()
				const tmpl = json.object ?? json ?? {}
				this.templateDomains = tmpl.goalDomains ?? []
			} catch {
				// Template load failure is non-fatal.
			}
		},

		/**
		 * Get goals for a given domain (null = goals with no domain).
		 *
		 * @param {string|null} domain The domain to filter by.
		 * @return {Array<object>}
		 */
		goalsByDomain(domain) {
			if (domain === null) {
				// Only return domain-less goals when no template domains defined.
				if (this.templateDomains.length > 0) {
					return []
				}
				return this.goals.filter((g) => !g.domain)
			}

			return this.goals.filter((g) => g.domain === domain)
		},

		/**
		 * Add a new blank goal in the given domain.
		 *
		 * @param {string|null} domain Goal domain.
		 * @return {void}
		 */
		addGoal(domain) {
			this.goals.push({
				goalId: uid(),
				description: '',
				domain: domain ?? null,
				baseline: null,
				target: null,
				targetDate: null,
				status: 'open',
				evidenceRefs: [],
			})
		},

		/**
		 * Remove a goal by goalId.
		 *
		 * @param {string} goalId The goal ID to remove.
		 * @return {void}
		 */
		removeGoal(goalId) {
			this.goals = this.goals.filter((g) => g.goalId !== goalId)
		},

		/**
		 * Add a new blank support measure.
		 *
		 * @return {void}
		 */
		addMeasure() {
			this.measures.push({
				measureId: midUid(),
				description: '',
				responsibleId: null,
				startDate: null,
				endDate: null,
			})
		},

		/**
		 * Remove a support measure by measureId.
		 *
		 * @param {string} measureId The measure ID to remove.
		 * @return {void}
		 */
		removeMeasure(measureId) {
			this.measures = this.measures.filter((m) => m.measureId !== measureId)
		},

		/**
		 * Save goals and support measures back to the LearningPlan.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.saveError = null
			this.saved = false

			const payload = {
				...this.plan,
				goals: this.goals,
				supportMeasures: this.measures,
			}

			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/LearningPlan/${this.planId}`)
				const resp = await fetch(url, {
					method: 'PUT',
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

				const json = await resp.json()
				this.plan = json.object ?? json ?? this.plan
				this.saved = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save learning plan. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LearningPlanEditor] save error', err)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Clone this plan as a new version with version+1, supersedesId, and draft lifecycle.
		 *
		 * @return {Promise<void>}
		 */
		async createNewVersion() {
			this.creatingVersion = true
			this.newVersionError = null

			const newPlan = {
				...this.plan,
				version: (this.plan.version ?? 1) + 1,
				supersedesId: this.planId,
				lifecycle: 'draft',
			}

			// Remove id/uuid so OR creates a new object.
			delete newPlan.id
			delete newPlan.uuid

			try {
				const url = generateUrl('/apps/openregister/api/objects/scholiq/LearningPlan')
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(newPlan),
				})

				if (!resp.ok) {
					throw new Error(`HTTP ${resp.status}`)
				}

				const json = await resp.json()
				const created = json.object ?? json ?? {}
				const newId = created.id ?? created.uuid

				if (newId) {
					// Navigate to editor for the new version.
					this.$router.push({ name: 'LearningPlanEditor', params: { planId: newId } })
				}
			} catch (err) {
				this.newVersionError = this.t('scholiq', 'Failed to create new version. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LearningPlanEditor] createNewVersion error', err)
			} finally {
				this.creatingVersion = false
			}
		},
	},
}
</script>

<style scoped>
.lp-editor {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.lp-editor__loading,
.lp-editor__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.lp-editor__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.lp-editor__version-badge {
	display: inline-block;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	border-radius: 12px;
	padding: 2px 10px;
	font-size: 0.75em;
	vertical-align: middle;
	margin-left: 8px;
}

.lp-editor__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.lp-editor__readonly-banner {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 1.5);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	font-size: 0.9em;
}

.lp-editor__new-version-btn {
	margin-left: auto;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 6px;
}

.lp-editor__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 4);
}

.lp-editor__section h3 {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.lp-editor__domain-group {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.lp-editor__domain-label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-primary-element);
	margin-bottom: 8px;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.lp-editor__goal-row,
.lp-editor__measure-row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin-bottom: 12px;
	padding: 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.lp-editor__goal-fields,
.lp-editor__measure-fields {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.lp-editor__field {
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.lp-editor__field label {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.lp-editor__field-row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.lp-editor__field--half {
	flex: 1 1 160px;
	min-width: 120px;
}

.lp-editor__input,
.lp-editor__select {
	padding: 5px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9em;
	width: 100%;
}

.lp-editor__textarea {
	padding: 5px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9em;
	width: 100%;
	resize: vertical;
}

.lp-editor__remove-btn {
	flex-shrink: 0;
	align-self: flex-start;
	padding: 4px 8px;
}

.lp-editor__add-btn {
	font-size: 0.85em;
	padding: 4px 10px;
}

.lp-editor__save-bar {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding-top: calc(var(--default-grid-baseline, 8px) * 2);
	border-top: 1px solid var(--color-border);
}

.lp-editor__save-btn {
	display: flex;
	align-items: center;
	gap: 6px;
}

.lp-editor__saved-msg {
	color: var(--color-success);
	font-size: 0.9em;
}

.lp-editor__save-error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
