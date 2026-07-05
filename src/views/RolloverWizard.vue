<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RolloverWizard — the jaarovergang mapping editor (school-year-rollover).

 A custom view (the "custom view only where a manifest page can't render it"
 exception): proposes a default per-cohort mapping by leerjaar increment, lets
 the admin adjust per cohort, runs the side-effect-free preview (the mandatory
 dry-run gate), and — once previewed and not blocked — triggers execution by
 transitioning the RolloverPlan to `executing` (the RolloverExecutionHandler
 then runs the chunked, idempotent rollover server-side).

 Storage/lifecycle/audit/notifications are OpenRegister's; this view only edits
 the plan's mappings and drives proposal/preview/execute through the
 Scholiq rollover API + OR object API.

 @spec openspec/specs/school-year-rollover/spec.md#requirement-mandatory-side-effect-free-preview-gate
-->
<template>
	<div class="rollover-wizard">
		<h2>{{ t('scholiq', 'School-year rollover') }}</h2>

		<div class="rollover-wizard__years">
			<label for="rollover-from">{{ t('scholiq', 'From academic year') }}</label>
			<input id="rollover-from"
				v-model="fromAcademicYear"
				type="text"
				placeholder="2025/2026">
			<label for="rollover-to">{{ t('scholiq', 'To academic year') }}</label>
			<input id="rollover-to"
				v-model="toAcademicYear"
				type="text"
				placeholder="2026/2027">
			<NcButton type="secondary" :disabled="!fromAcademicYear" @click="propose">
				{{ t('scholiq', 'Propose mapping') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<table v-else-if="mappings.length > 0" class="rollover-wizard__table">
			<thead>
				<tr>
					<th>{{ t('scholiq', 'From cohort') }}</th>
					<th>{{ t('scholiq', 'Action') }}</th>
					<th>{{ t('scholiq', 'To cohort') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(m, i) in mappings" :key="m.fromCohortId || i" :class="{ 'rollover-wizard__row--blocked': !m.action }">
					<td>{{ m.fromCohortId }}</td>
					<td>
						<NcSelect
							v-model="m.action"
							:options="actionOptions"
							:input-label="t('scholiq', 'Action')"
							:aria-label-combobox="t('scholiq', 'Action')" />
					</td>
					<td>
						<input v-model="m.toCohortName" type="text" :placeholder="t('scholiq', 'New cohort name')">
					</td>
				</tr>
			</tbody>
		</table>

		<NcEmptyContent
			v-else
			:name="t('scholiq', 'No cohorts to roll over')"
			:description="t('scholiq', 'Enter a from-year and propose a mapping to begin.')" />

		<div v-if="mappings.length > 0" class="rollover-wizard__actions">
			<NcButton type="secondary" @click="preview">
				{{ t('scholiq', 'Preview') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canExecute" @click="execute">
				{{ t('scholiq', 'Execute rollover') }}
			</NcButton>
		</div>

		<div v-if="report" class="rollover-wizard__report">
			<h3>{{ t('scholiq', 'Dry-run report') }}</h3>
			<p v-if="report.blocked" class="rollover-wizard__report--blocked">
				{{ t('scholiq', 'Preview blocked: resolve every cohort action before executing.') }}
			</p>
			<ul>
				<li>{{ t('scholiq', 'Promote') }}: {{ report.counts.promote }}</li>
				<li>{{ t('scholiq', 'Retain') }}: {{ report.counts.retain }}</li>
				<li>{{ t('scholiq', 'Graduate') }}: {{ report.counts.graduate }}</li>
				<li>{{ t('scholiq', 'Outflow') }}: {{ report.counts.outflow }}</li>
				<li>{{ t('scholiq', 'Enrolments to carry over') }}: {{ report.enrolmentsToCarry }}</li>
				<li>{{ t('scholiq', 'Cohorts to create') }}: {{ report.cohortsToCreate.length }}</li>
			</ul>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'

export default {
	name: 'RolloverWizard',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			fromAcademicYear: '',
			toAcademicYear: '',
			mappings: [],
			planId: '',
			report: null,
			loading: false,
			actionOptions: ['promote', 'graduate', 'dissolve'],
		}
	},

	computed: {
		/**
		 * Execution is only allowed once a non-blocked preview exists.
		 *
		 * @return {boolean} True when the plan may be executed.
		 * @spec openspec/specs/school-year-rollover/spec.md#requirement-preview-must-be-side-effect-free-and-produce-a-complete-dry-run-report
		 */
		canExecute() {
			return this.report !== null && this.report.blocked === false && this.planId !== ''
		},
	},

	methods: {
		/**
		 * Fetch the default leerjaar-increment mapping for the from-year.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-year-rollover/spec.md#requirement-the-wizard-must-be-declarative-with-a-single-custom-view-exception-and-completion-must-notify-via-the-verified-dialect
		 */
		async propose() {
			this.loading = true
			try {
				const url = generateUrl('/apps/scholiq/api/rollover/propose?fromAcademicYear={year}', { year: this.fromAcademicYear })
				const response = await axios.get(url)
				this.mappings = (response.data && response.data.mappings) || []
				this.report = null
			} catch (e) {
				console.error('[RolloverWizard] propose failed', e)
				this.mappings = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the plan (create or update) and run the side-effect-free preview.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-year-rollover/spec.md#requirement-preview-must-be-side-effect-free-and-produce-a-complete-dry-run-report
		 */
		async preview() {
			this.loading = true
			try {
				this.planId = await this.savePlan()
				if (this.planId === '') {
					return
				}
				const url = generateUrl('/apps/scholiq/api/rollover/{planId}/preview', { planId: this.planId })
				const response = await axios.post(url, {})
				this.report = (response.data && response.data.report) || null
			} catch (e) {
				console.error('[RolloverWizard] preview failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger execution by transitioning the previewed plan to `executing`
		 * via the OpenRegister object API. The server-side handler runs the
		 * idempotent rollover.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/school-year-rollover/spec.md#requirement-execution-must-promote-by-creating-new-cohorts-and-archiving-old-ones
		 */
		async execute() {
			if (!this.canExecute) {
				return
			}
			this.loading = true
			try {
				// OR lifecycle transition draft→… is driven by writing the lifecycle
				// field through OR's object API; the RolloverExecutionHandler reacts.
				const url = generateUrl('/apps/openregister/api/objects/scholiq/rollover-plan/{id}', { id: this.planId })
				await axios.put(url, { lifecycle: 'executing' })
			} catch (e) {
				console.error('[RolloverWizard] execute failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create or update the RolloverPlan object via the OR object API.
		 *
		 * @return {Promise<string>} The plan UUID, or '' on failure.
		 * @spec openspec/specs/school-year-rollover/spec.md#requirement-rolloverplan-must-be-an-openregister-object-with-a-preview-gated-lifecycle
		 */
		async savePlan() {
			try {
				const body = {
					fromAcademicYear: this.fromAcademicYear,
					toAcademicYear: this.toAcademicYear,
					mappings: this.mappings,
				}
				if (this.planId !== '') {
					const url = generateUrl('/apps/openregister/api/objects/scholiq/rollover-plan/{id}', { id: this.planId })
					const r = await axios.put(url, body)
					return (r.data && (r.data.id || r.data.uuid)) || this.planId
				}
				const url = generateUrl('/apps/openregister/api/objects/scholiq/rollover-plan')
				const r = await axios.post(url, body)
				return (r.data && (r.data.id || r.data.uuid)) || ''
			} catch (e) {
				console.error('[RolloverWizard] savePlan failed', e)
				return ''
			}
		},
	},
}
</script>

<style scoped>
.rollover-wizard {
	padding: 1rem;
}

.rollover-wizard__years {
	display: flex;
	gap: 0.5rem;
	align-items: flex-end;
	flex-wrap: wrap;
	margin-bottom: 1rem;
}

.rollover-wizard__table {
	width: 100%;
	border-collapse: collapse;
}

.rollover-wizard__row--blocked {
	background: var(--cn-color-warning-background, var(--color-warning, #fdf4e3));
}

.rollover-wizard__actions {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
}

.rollover-wizard__report--blocked {
	color: var(--cn-color-error, var(--color-error, #c0392b));
}
</style>
