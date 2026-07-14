<!--
  PortfolioReviewView.vue
  Custom page component for the PortfolioReviewView manifest page (type: custom).

  eportfolio: the read-only review surface for a Portfolio's entries —
  resolving and displaying the referenced Submission / WerkprocesAssessment /
  ExternalTrainingRecord / Credential per entry (attachmentRef / reflection
  entries render inline, no further lookup needed) — plus, for the grading
  teacher of a `course-bound` Portfolio in `submitted` state only, a
  `gradeValue` entry field and the `grade` transition. Once graded, the
  resulting `concept` GradeEntry is consumed unchanged by the existing
  GradebookView / GradeRollupHandler roll-up (this view does not compute a
  final grade itself — PortfolioGradeEmitHandler does the emission
  server-side on the `graded` transition).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Portfolio/:id
    - GET  /api/objects/scholiq/PortfolioEntry?filters[portfolioId]=:id
    - GET  /api/objects/scholiq/Submission/:id                  (per submission-kind entry)
    - GET  /api/objects/scholiq/WerkprocesAssessment/:id        (per werkproces-assessment-kind entry)
    - GET  /api/objects/scholiq/ExternalTrainingRecord/:id      (per external-training-record-kind entry)
    - GET  /api/objects/scholiq/Credential/:id                  (per credential-kind entry)
    - PUT  /api/objects/scholiq/Portfolio/:id                   (gradeValue)
    - POST /api/objects/scholiq/Portfolio/:id/transition/grade

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView.vue's existing shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
  @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-graded-course-bound-portfolio-flows-through-the-existing-gradeentry-pipeline-not-a-parallel-one
-->

<template>
	<div class="portfolio-review-view">
		<!-- Loading -->
		<div v-if="loading" class="portfolio-review-view__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading portfolio...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="portfolio-review-view__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else-if="portfolio">
			<header class="portfolio-review-view__header">
				<h2>{{ t('scholiq', 'Review portfolio: {title}', { title: portfolio.title || '' }) }}</h2>
				<p class="portfolio-review-view__meta">
					{{ t('scholiq', 'Kind: {kind}', { kind: portfolio.kind || '' }) }}
					<span class="portfolio-review-view__lifecycle">
						{{ t('scholiq', 'Status: {status}', { status: portfolio.lifecycle || '' }) }}
					</span>
				</p>
			</header>

			<!-- Entries (read-only) -->
			<section class="portfolio-review-view__entries">
				<h3>{{ t('scholiq', 'Evidence entries') }}</h3>
				<ul v-if="entries.length > 0" class="portfolio-review-view__entry-list">
					<li v-for="entry in entries" :key="entry.id" class="portfolio-review-view__entry-item">
						<span class="portfolio-review-view__entry-kind">{{ evidenceKindLabel(entry.evidenceKind) }}</span>
						<span class="portfolio-review-view__entry-title">{{ entry.title }}</span>

						<p v-if="entry.evidenceKind === 'reflection'" class="portfolio-review-view__entry-reflection">
							{{ entry.reflectionText }}
						</p>
						<p v-else-if="entry.evidenceKind === 'file' && entry.attachmentRef"
							class="portfolio-review-view__entry-file">
							{{ entry.attachmentRef }}
						</p>
						<p v-else-if="resolvedReferences[entry.id]" class="portfolio-review-view__entry-resolved">
							{{ resolvedReferences[entry.id] }}
						</p>
					</li>
				</ul>
				<p v-else class="portfolio-review-view__no-entries">
					{{ t('scholiq', 'No evidence entries.') }}
				</p>
			</section>

			<!-- Teacher grading (course-bound + submitted only) -->
			<section v-if="canGrade" class="portfolio-review-view__grading">
				<h3>{{ t('scholiq', 'Grade this portfolio') }}</h3>

				<label for="pr-grade-value" class="portfolio-review-view__field-label">
					{{ t('scholiq', 'Grade value') }}
				</label>
				<input
					id="pr-grade-value"
					v-model.number="gradeValue"
					type="number"
					class="portfolio-review-view__grade-input"
					:disabled="grading">

				<div class="portfolio-review-view__actions">
					<button
						class="button-vue button-vue--primary portfolio-review-view__grade-btn"
						:disabled="grading || gradeValue === null || gradeValue === ''"
						@click="gradePortfolio">
						<span v-if="grading" class="icon-loading" aria-hidden="true" />
						{{ t('scholiq', 'Save grade & transition to graded') }}
					</button>
				</div>
				<p v-if="gradeError" role="alert" class="portfolio-review-view__grade-error">
					{{ gradeError }}
				</p>
			</section>

			<p v-if="portfolio.lifecycle === 'graded'" class="portfolio-review-view__graded-confirmation" role="status">
				{{ t('scholiq', 'This portfolio has been graded. Grade: {grade}', { grade: portfolio.gradeValue }) }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

/**
 * Map from PortfolioEntry.evidenceKind to the OR schema + id field to
 * resolve for display.
 *
 * @type {Record<string, { schema: string, idField: string }>}
 */
const REFERENCE_SCHEMAS = {
	submission: { schema: 'Submission', idField: 'submissionId' },
	'werkproces-assessment': { schema: 'WerkprocesAssessment', idField: 'werkprocesAssessmentId' },
	'external-training-record': { schema: 'ExternalTrainingRecord', idField: 'externalTrainingRecordId' },
	credential: { schema: 'Credential', idField: 'credentialId' },
}

export default {
	name: 'PortfolioReviewView',

	props: {
		/**
		 * Portfolio UUID injected by vue-router from the :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			portfolio: null,
			/** @type {Array<object>} */
			entries: [],
			/** @type {Record<string, string>} entry.id -> a display label for the resolved reference */
			resolvedReferences: {},
			/** @type {number|string|null} */
			gradeValue: null,
			loading: false,
			error: null,
			grading: false,
			gradeError: null,
		}
	},

	computed: {
		/**
		 * Grading is only offered for a course-bound portfolio in `submitted`
		 * state — mirrors PortfolioGradeEmitHandler's `to: graded` listener
		 * (`submitted -> graded` is the only transition it reacts to).
		 *
		 * @return {boolean}
		 */
		canGrade() {
			return this.portfolio?.kind === 'course-bound' && this.portfolio?.lifecycle === 'submitted'
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the portfolio id prop changing by loading all data.
			 *
			 * @param {string} newId New portfolio UUID
			 * @return {Promise<void>}
			 */
			async handler(newId) {
				if (newId) {
					await this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the Portfolio, its PortfolioEntry rows, and resolve each
		 * reference-kind entry's referenced object for display.
		 *
		 * @param {string} portfolioId Portfolio UUID
		 * @return {Promise<void>}
		 */
		async loadData(portfolioId) {
			this.loading = true
			this.error = null

			try {
				this.portfolio = await this.fetchObject('Portfolio', portfolioId)
				this.gradeValue = this.portfolio.gradeValue ?? null

				this.entries = await this.fetchList('PortfolioEntry', `filters[portfolioId]=${portfolioId}&limit=100`)
				await this.resolveEntryReferences()
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load portfolio. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[PortfolioReviewView] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single OpenRegister object.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} objId  Object UUID.
		 * @return {Promise<object>}
		 */
		async fetchObject(schema, objId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${objId}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`${schema} fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			return json.object ?? json ?? {}
		},

		/**
		 * Fetch a filtered list of OpenRegister objects.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} query  Pre-built query string (already URL-encoded).
		 * @return {Promise<Array<object>>}
		 */
		async fetchList(schema, query) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}?${query}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				return []
			}
			const json = await resp.json()
			return json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
		},

		/**
		 * For every entry whose evidenceKind resolves through one of
		 * REFERENCE_SCHEMAS, fetch the referenced object and build a display
		 * label. Best-effort: a failed lookup just leaves that entry's
		 * resolved reference unset.
		 *
		 * @return {Promise<void>}
		 */
		async resolveEntryReferences() {
			const resolved = {}

			for (const entry of this.entries) {
				const mapping = REFERENCE_SCHEMAS[entry.evidenceKind]
				if (!mapping) {
					continue
				}
				const refId = entry[mapping.idField]
				if (!refId) {
					continue
				}
				try {
					const referenced = await this.fetchObject(mapping.schema, refId)
					resolved[entry.id] = referenced.title
						?? referenced.name
						?? referenced.feedbackText
						?? refId
				} catch (err) {
					// Non-fatal — the entry simply shows no resolved reference.
					// eslint-disable-next-line no-console
					console.error('[PortfolioReviewView] resolveEntryReferences error', err)
				}
			}

			this.resolvedReferences = resolved
		},

		/**
		 * Human-readable label for an evidenceKind value.
		 *
		 * @param {string} kind evidenceKind value.
		 * @return {string}
		 */
		evidenceKindLabel(kind) {
			const labels = {
				file: this.t('scholiq', 'File'),
				submission: this.t('scholiq', 'Submission'),
				'werkproces-assessment': this.t('scholiq', 'Werkproces assessment'),
				'external-training-record': this.t('scholiq', 'External training record'),
				credential: this.t('scholiq', 'Credential'),
				reflection: this.t('scholiq', 'Reflection'),
			}
			return labels[kind] ?? kind
		},

		/**
		 * Save the teacher-entered gradeValue and dispatch the Portfolio
		 * `grade` lifecycle transition. PortfolioGradeEmitHandler emits the
		 * concept GradeEntry server-side once the transition lands — this
		 * view computes no grade itself.
		 *
		 * @return {Promise<void>}
		 */
		async gradePortfolio() {
			if (!this.portfolio) {
				return
			}
			this.grading = true
			this.gradeError = null

			try {
				const updateUrl = generateUrl(`/apps/openregister/api/objects/scholiq/Portfolio/${this.id}`)
				const updateResp = await fetch(updateUrl, {
					method: 'PUT',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ gradeValue: Number(this.gradeValue) }),
				})
				if (!updateResp.ok) {
					throw new Error(`Portfolio update failed: ${updateResp.status}`)
				}

				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/scholiq/Portfolio/${this.id}/transition/grade`,
				)
				const transResp = await fetch(transitionUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})
				if (!transResp.ok) {
					throw new Error(`Portfolio grade transition failed: ${transResp.status}`)
				}

				this.portfolio = await this.fetchObject('Portfolio', this.id)
			} catch (err) {
				this.gradeError = this.t('scholiq', 'Failed to save grade. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[PortfolioReviewView] gradePortfolio error', err)
			} finally {
				this.grading = false
			}
		},
	},
}
</script>

<style scoped>
.portfolio-review-view {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-review-view__loading,
.portfolio-review-view__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-review-view__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-review-view__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.portfolio-review-view__lifecycle {
	margin-left: var(--default-grid-baseline, 8px);
}

.portfolio-review-view__entries,
.portfolio-review-view__grading {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-review-view__entry-list {
	list-style: none;
	padding: 0;
}

.portfolio-review-view__entry-item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: calc(var(--default-grid-baseline, 8px)) 0;
	border-bottom: 1px solid var(--color-border);
}

.portfolio-review-view__entry-kind {
	font-weight: bold;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.portfolio-review-view__entry-reflection,
.portfolio-review-view__entry-file,
.portfolio-review-view__entry-resolved {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.portfolio-review-view__no-entries {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.portfolio-review-view__field-label {
	display: block;
	margin-bottom: 4px;
}

.portfolio-review-view__grade-input {
	width: 120px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.portfolio-review-view__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-review-view__grade-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
	text-align: right;
}

.portfolio-review-view__graded-confirmation {
	color: var(--color-success-text, var(--color-success));
	font-weight: bold;
}
</style>
