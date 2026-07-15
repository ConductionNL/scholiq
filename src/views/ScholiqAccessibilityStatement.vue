<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqAccessibilityStatement — the toegankelijkheidsverklaring disclosure
 surface (accessibility-conformance-statement).

 A purpose-built read surface over cross-schema data (mirrors
 ScholiqCompliance.vue's role), NOT a generic detail page: it resolves the
 current `published` AccessibilityStatement itself (there is no `:id` route
 param — this is a singleton disclosure page reachable by every authenticated
 user, no visibleIf role gate, per design.md Decision 3) and its linked
 AccessibilityLimitation rows, renders every mandatory field from the Dutch
 government model in the invulassistent's field order, and offers a
 persistent "Report an accessibility problem" entry point that opens the
 generic AccessibilityFeedback create form (AccessibilityFeedbackCreate,
 route /accessibility/feedback/new) — no bespoke ticketing UI.

 @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
 @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
 @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
-->

<template>
	<div class="accessibility-statement">
		<NcLoadingIcon v-if="loading" :size="44" class="accessibility-statement__loading" />

		<NcEmptyContent v-else-if="!statement"
			:name="t('scholiq', 'No accessibility statement published yet')"
			:description="t('scholiq', 'The compliance officer has not published a toegankelijkheidsverklaring for this environment yet.')">
			<template #icon>
				<span class="icon-info" />
			</template>
			<template #action>
				<NcButton type="primary" @click="openFeedbackForm">
					{{ t('scholiq', 'Report an accessibility problem') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="accessibility-statement__header">
				<h2>{{ t('scholiq', 'Accessibility statement') }}</h2>
				<NcButton type="primary" @click="openFeedbackForm">
					{{ t('scholiq', 'Report an accessibility problem') }}
				</NcButton>
			</div>

			<dl class="accessibility-statement__fields">
				<dt>{{ t('scholiq', 'Channel') }}</dt>
				<dd>{{ statement.channelTitle }}</dd>

				<dt>{{ t('scholiq', 'Conformance status') }}</dt>
				<dd>{{ statusLabel }}</dd>

				<dt>{{ t('scholiq', 'Evaluation method') }}</dt>
				<dd>{{ statement.evaluationMethod }}</dd>

				<dt>{{ t('scholiq', 'Evaluation date') }}</dt>
				<dd>{{ statement.evaluationDate }}</dd>

				<dt>{{ t('scholiq', 'Standard applied') }}</dt>
				<dd>{{ statement.standardApplied }}</dd>

				<dt>{{ t('scholiq', 'Feedback contact') }}</dt>
				<dd>{{ statement.feedbackContact }}</dd>

				<dt>{{ t('scholiq', 'Escalation route') }}</dt>
				<dd>{{ statement.escalationRoute }}</dd>

				<dt v-if="statement.lastReviewedAt">
					{{ t('scholiq', 'Last reviewed') }}
				</dt>
				<dd v-if="statement.lastReviewedAt">
					{{ statement.lastReviewedAt }}
				</dd>
			</dl>

			<h3>{{ t('scholiq', 'Known limitations') }}</h3>
			<p v-if="limitations.length === 0" class="accessibility-statement__no-limitations">
				{{ t('scholiq', 'No known limitations are currently logged.') }}
			</p>
			<table v-else class="accessibility-statement__limitations">
				<thead>
					<tr>
						<th>{{ t('scholiq', 'WCAG criterion') }}</th>
						<th>{{ t('scholiq', 'Severity') }}</th>
						<th>{{ t('scholiq', 'Affected surface') }}</th>
						<th>{{ t('scholiq', 'Status') }}</th>
						<th>{{ t('scholiq', 'Planned fix date') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="limitation in limitations" :key="limitation.id">
						<td>{{ limitation.wcagCriterion }}</td>
						<td>{{ limitation.severity }}</td>
						<td>{{ limitation.affectedSurface }}</td>
						<td>{{ limitation.lifecycle }}</td>
						<td>{{ limitation.plannedFixDate || t('scholiq', 'Not yet determined') }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

const REGISTER = 'scholiq'
const STATEMENT_SCHEMA = 'AccessibilityStatement'
const LIMITATION_SCHEMA = 'AccessibilityLimitation'
const STATEMENT_TYPE = `${REGISTER}-${STATEMENT_SCHEMA}`
const LIMITATION_TYPE = `${REGISTER}-${LIMITATION_SCHEMA}`

const STATUS_LABELS = {
	'fully-compliant': 'Fully compliant',
	'partially-compliant': 'Partially compliant',
	'non-compliant': 'Not compliant',
}

export default {
	name: 'ScholiqAccessibilityStatement',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			statement: null,
			limitations: [],
		}
	},

	computed: {
		/**
		 * Human-readable label for the statement's 3-value status.
		 *
		 * @return {string} The translated status label.
		 */
		statusLabel() {
			if (!this.statement || !this.statement.status) {
				return ''
			}
			return this.t('scholiq', STATUS_LABELS[this.statement.status] ?? this.statement.status)
		},
	},

	async mounted() {
		await this.loadStatement()
	},

	methods: {
		/**
		 * Resolve the current `published` AccessibilityStatement and its
		 * linked AccessibilityLimitation rows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
		 */
		async loadStatement() {
			this.loading = true
			const store = useObjectStore()

			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(STATEMENT_TYPE, STATEMENT_SCHEMA, REGISTER)
				store.registerObjectType(LIMITATION_TYPE, LIMITATION_SCHEMA, REGISTER)
			}

			const results = typeof store.fetchCollection === 'function'
				? await store.fetchCollection(STATEMENT_TYPE, { lifecycle: 'published', _limit: 1 }).catch(() => [])
				: []
			this.statement = Array.isArray(results) && results.length > 0 ? results[0] : null

			if (this.statement) {
				const statementId = this.statement.id ?? (this.statement['@self'] && this.statement['@self'].id)
				const limitationResults = typeof store.fetchCollection === 'function'
					? await store.fetchCollection(LIMITATION_TYPE, { accessibilityStatementId: statementId }).catch(() => [])
					: []
				this.limitations = Array.isArray(limitationResults) ? limitationResults : []
			} else {
				this.limitations = []
			}

			this.loading = false
		},

		/**
		 * Navigate to the generic AccessibilityFeedback create form — no
		 * bespoke ticketing dialog, reuses the manifest's declarative
		 * no-id detail create-mode route (ADR-062), same pattern as
		 * course-evaluation's ImprovementActionCreate.
		 *
		 * @return {void}
		 * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
		 */
		openFeedbackForm() {
			this.$router.push('/accessibility/feedback/new')
		},
	},
}
</script>

<style scoped lang="scss">
.accessibility-statement {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 900px;

	&__loading {
		margin: calc(var(--default-grid-baseline, 4px) * 8) auto;
	}

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	}

	&__fields {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 4);
		margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);

		dt {
			font-weight: bold;
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
		}
	}

	&__limitations {
		width: 100%;
		border-collapse: collapse;

		th, td {
			text-align: left;
			padding: calc(var(--default-grid-baseline, 4px) * 2);
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__no-limitations {
		color: var(--color-text-maxcontrast);
	}
}
</style>
