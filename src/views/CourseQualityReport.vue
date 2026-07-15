<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CourseQualityReport — course-evaluation's one genuine custom-view exception
 (mirrors GroupTrendHeatmap/LeaderboardView's precedent).

 A coordinator/opleidingscommissie view of a course's (optionally a specific
 teacher's) CourseQualityScore trend over time, response rate, and the raw
 free-text answers submitted for it, with a link to draft an
 ImprovementAction pre-filled with the resolved campaignId/courseId. No
 route :param is used (the manifest router only auto-binds :route segments,
 not ?query=, to props — reference_never-line-grep-a-minified-bundle-style
 gotcha verified against CnPageRenderer.resolvedProps at HEAD) — the course
 (and optional teacher) is picked in-page instead, so the page is reachable
 directly from the "Course evaluation" menu with no id required.

 Uses Options API + direct fetch calls (no custom Pinia store modules),
 mirroring GroupTrendHeatmap.vue/BsaRiskDashboard.vue.

 @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-frontend-is-declarative-with-one-named-custom-view-for-the-quality-report
 @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
-->
<template>
	<div class="course-quality-report">
		<header class="course-quality-report__header">
			<h2>{{ t('scholiq', 'Course quality report') }}</h2>
			<p class="course-quality-report__subtitle">
				{{ t('scholiq', 'Vakevaluatie results over time, per course and teacher.') }}
			</p>
		</header>

		<NcLoadingIcon v-if="loadingCourses" :size="32" />

		<template v-else>
			<div class="course-quality-report__field">
				<label for="cqr-course">{{ t('scholiq', 'Course') }}</label>
				<NcSelect id="cqr-course"
					v-model="selectedCourseId"
					:options="courseOptions"
					:reduce="(o) => o.id"
					label="label"
					:input-label="t('scholiq', 'Course')"
					:aria-label-combobox="t('scholiq', 'Course')"
					@input="onCourseChange" />
			</div>

			<div v-if="selectedCourseId" class="course-quality-report__field">
				<label for="cqr-teacher">{{ t('scholiq', 'Teacher (optional)') }}</label>
				<NcSelect id="cqr-teacher"
					v-model="selectedTeacherId"
					:options="teacherOptions"
					:reduce="(o) => o.id"
					label="label"
					:input-label="t('scholiq', 'Teacher')"
					:aria-label-combobox="t('scholiq', 'Teacher')"
					@input="onTeacherChange" />
			</div>

			<NcLoadingIcon v-if="loadingReport" :size="32" />

			<template v-else-if="selectedCourseId">
				<NcEmptyContent v-if="trendRows.length === 0"
					:name="t('scholiq', 'No evaluation results yet')"
					:description="t('scholiq', 'No CourseQualityScore rows exist yet for this course/teacher — results appear once responses are submitted.')" />

				<template v-else>
					<section class="course-quality-report__section">
						<h3>{{ t('scholiq', 'Score trend') }}</h3>
						<table class="course-quality-report__table">
							<thead>
								<tr>
									<th scope="col">
										{{ t('scholiq', 'Period') }}
									</th>
									<th scope="col">
										{{ t('scholiq', 'Average score') }}
									</th>
									<th scope="col">
										{{ t('scholiq', 'Responses') }}
									</th>
									<th scope="col">
										{{ t('scholiq', 'Invitations') }}
									</th>
									<th scope="col">
										{{ t('scholiq', 'Response rate') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in trendRows" :key="row.id ?? (row.academicYear + row.period)">
									<td>{{ row.academicYear }} {{ row.period }}</td>
									<td>{{ formatScore(row.averageOverallScore) }}</td>
									<td>{{ row.responseCount ?? 0 }}</td>
									<td>{{ row.invitationCount ?? 0 }}</td>
									<td>{{ formatRate(row.responseRate) }}</td>
								</tr>
							</tbody>
						</table>
					</section>

					<section class="course-quality-report__section">
						<h3>{{ t('scholiq', 'Free-text answers') }}</h3>
						<ul v-if="freeTextAnswers.length > 0" class="course-quality-report__answers">
							<li v-for="(answer, index) in freeTextAnswers" :key="index">
								{{ answer }}
							</li>
						</ul>
						<p v-else class="course-quality-report__empty-text">
							{{ t('scholiq', 'No free-text answers submitted yet.') }}
						</p>
					</section>

					<NcButton type="secondary" @click="draftImprovementAction">
						{{ t('scholiq', 'Draft improvement action') }}
					</NcButton>
				</template>
			</template>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'

export default {
	name: 'CourseQualityReport',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			loadingCourses: true,
			loadingReport: false,
			courses: [],
			campaigns: [],
			selectedCourseId: '',
			selectedTeacherId: '',
			scores: [],
			responses: [],
		}
	},

	computed: {
		/**
		 * Course picker options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		courseOptions() {
			return this.courses.map((c) => ({ id: c.id ?? c.uuid, label: c.name ?? c.title ?? c.id }))
		},

		/**
		 * Teacher picker options, derived from the distinct non-null teacherId
		 * values already present on this course's fetched CourseQualityScore
		 * rows — plus an "All teachers" (course-level, teacherId:null) option.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		teacherOptions() {
			const ids = new Set(this.scores.map((s) => s.teacherId).filter((id) => !!id))
			const options = [{ id: '', label: this.t('scholiq', 'All teachers (course-level)') }]
			for (const id of ids) {
				options.push({ id, label: id })
			}
			return options
		},

		/**
		 * CourseQualityScore rows for the current course + teacher selection,
		 * sorted oldest to newest by academicYear/period.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
		 */
		trendRows() {
			const teacherId = this.selectedTeacherId || null
			return this.scores
				.filter((s) => (s.teacherId ?? null) === teacherId)
				.slice()
				.sort((a, b) => `${a.academicYear}${a.period}`.localeCompare(`${b.academicYear}${b.period}`))
		},

		/**
		 * Every non-empty free-text answer from this course's submitted
		 * responses. Anonymous by construction — CourseEvaluationResponse
		 * carries no learner-identifying field to display alongside these.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
		 */
		freeTextAnswers() {
			const teacherId = this.selectedTeacherId || null
			const answers = []
			for (const response of this.responses) {
				if (teacherId !== null && (response.teacherId ?? null) !== teacherId) continue
				for (const answer of response.answers ?? []) {
					if (answer.textValue) answers.push(answer.textValue)
				}
			}
			return answers
		},

		/**
		 * The most recently-closing EvaluationCampaign that scopes the
		 * selected course, used as the campaignId prefill for the
		 * "draft improvement action" link — a best-effort client-side
		 * resolution (courseIds is an array field; no server-side
		 * array-contains filter is assumed), not a guarantee. The reviewer
		 * can always change the campaign in the create form itself.
		 *
		 * @return {object|null}
		 */
		latestCampaignForCourse() {
			const matches = this.campaigns
				.filter((c) => Array.isArray(c.courseIds) && c.courseIds.includes(this.selectedCourseId))
				.sort((a, b) => String(b.closesAt ?? '').localeCompare(String(a.closesAt ?? '')))
			return matches[0] ?? null
		},
	},

	created() {
		this.loadCourses()
	},

	methods: {
		/**
		 * Fetch every Course and every EvaluationCampaign (for the
		 * campaignId-prefill resolution), via OpenRegister's existing object
		 * API — no new schema.
		 *
		 * @return {Promise<void>}
		 */
		async loadCourses() {
			this.loadingCourses = true
			try {
				const [coursesResp, campaignsResp] = await Promise.all([
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/Course?limit=500'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
					fetch(generateUrl('/apps/openregister/api/objects/scholiq/EvaluationCampaign?limit=200'), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
				])

				const coursesJson = coursesResp.ok ? await coursesResp.json() : {}
				const campaignsJson = campaignsResp.ok ? await campaignsResp.json() : {}

				this.courses = coursesJson.results ?? coursesJson.objects ?? coursesJson ?? []
				this.campaigns = campaignsJson.results ?? campaignsJson.objects ?? campaignsJson ?? []
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[CourseQualityReport] loadCourses error', err)
			} finally {
				this.loadingCourses = false
			}
		},

		/**
		 * Handle a course selection: reset the teacher filter and (re)load
		 * this course's CourseQualityScore rows and CourseEvaluationResponses.
		 *
		 * @return {Promise<void>}
		 */
		async onCourseChange() {
			this.selectedTeacherId = ''
			await this.loadReport()
		},

		/**
		 * Handle a teacher filter change — purely client-side (trendRows/
		 * freeTextAnswers are already-fetched-and-filtered computed
		 * properties), no re-fetch needed.
		 *
		 * @return {void}
		 */
		onTeacherChange() {
			// No-op: filtering happens in the trendRows/freeTextAnswers computed properties.
		},

		/**
		 * Fetch CourseQualityScore and CourseEvaluationResponse rows for the
		 * selected course.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-coordinator-opens-the-course-quality-report-and-sees-the-score-trend
		 */
		async loadReport() {
			if (!this.selectedCourseId) {
				this.scores = []
				this.responses = []
				return
			}

			this.loadingReport = true
			try {
				const courseId = encodeURIComponent(this.selectedCourseId)
				const [scoresResp, responsesResp] = await Promise.all([
					fetch(generateUrl(`/apps/openregister/api/objects/scholiq/CourseQualityScore?courseId=${courseId}&limit=100`), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
					fetch(generateUrl(`/apps/openregister/api/objects/scholiq/CourseEvaluationResponse?courseId=${courseId}&limit=200`), {
						headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
					}),
				])

				const scoresJson = scoresResp.ok ? await scoresResp.json() : {}
				const responsesJson = responsesResp.ok ? await responsesResp.json() : {}

				this.scores = scoresJson.results ?? scoresJson.objects ?? scoresJson ?? []
				this.responses = responsesJson.results ?? responsesJson.objects ?? responsesJson ?? []
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[CourseQualityReport] loadReport error', err)
			} finally {
				this.loadingReport = false
			}
		},

		/**
		 * Navigate to the ImprovementAction create route, pre-filled with
		 * the selected course and (best-effort resolved) campaign via query
		 * params — CnDetailPage's isCreateMode reads these and filters them
		 * to the schema's own properties (ADR-062).
		 *
		 * @return {void}
		 */
		draftImprovementAction() {
			const query = { courseId: this.selectedCourseId }
			const campaign = this.latestCampaignForCourse
			if (campaign) {
				query.campaignId = campaign.id ?? campaign.uuid
			}
			this.$router.push({ name: 'ImprovementActionCreate', query })
		},

		/**
		 * Format an average score to one decimal place, or a dash when null.
		 *
		 * @param {number|null} value Average overall score.
		 * @return {string}
		 */
		formatScore(value) {
			return value === null || value === undefined ? '—' : Number(value).toFixed(1)
		},

		/**
		 * Format a response rate as a percentage, or a dash when null.
		 *
		 * @param {number|null} value Response rate (0-1).
		 * @return {string}
		 */
		formatRate(value) {
			return value === null || value === undefined ? '—' : `${Math.round(Number(value) * 100)}%`
		},
	},
}
</script>

<style scoped>
.course-quality-report {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.course-quality-report__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.course-quality-report__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.course-quality-report__field {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	max-width: 400px;
}

.course-quality-report__field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.course-quality-report__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.course-quality-report__table {
	border-collapse: collapse;
	width: 100%;
}

.course-quality-report__table th,
.course-quality-report__table td {
	border: 1px solid var(--color-border);
	padding: calc(var(--default-grid-baseline, 8px) / 2) var(--default-grid-baseline, 8px);
	text-align: left;
}

.course-quality-report__answers {
	list-style: disc;
	padding-left: 1.5em;
}

.course-quality-report__answers li {
	margin-bottom: 4px;
}

.course-quality-report__empty-text {
	color: var(--color-text-maxcontrast);
}
</style>
