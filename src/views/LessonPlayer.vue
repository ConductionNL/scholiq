<!--
  LessonPlayer.vue
  Custom page component for the LessonPlayer manifest page (type: custom).

  Bespoke lesson playback view. Renders the lesson content for a given
  course+lesson pair, with xAPI-instrumented progress tracking and
  next/previous navigation between lessons in the same course.

  course-authoring-ux: when contentType='text', renders lesson.blocks in
  order (richText/media/quiz/assignment/ltiTool), replacing the previously
  dead lesson.title/lesson.summary/lesson.content reads — those fields have
  never existed on the Lesson schema; the "text lesson" render path had no
  real data to display until this change gave contentType='text' an actual
  authoring surface (LessonComposer.vue) and a real rendering path.

  Talks only to OpenRegister's REST API:
    - GET /api/objects/scholiq/Course/:courseId
    - GET /api/objects/scholiq/Lesson/:lessonId
    - GET /api/objects/scholiq/Material|Assessment|Assignment/:id (block references)
    - POST /api/objects/scholiq/LessonProgress (record xAPI statements)

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
-->

<template>
	<div class="lesson-player">
		<div v-if="loading" class="lesson-player__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading lesson…') }}</span>
		</div>

		<div v-else-if="error" class="lesson-player__error" role="alert">
			<NcEmptyContent
				:name="t('scholiq', 'Lesson not found')"
				:description="error">
				<template #icon>
					<AlertCircleOutline />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else-if="isLocked" class="lesson-player__locked" role="alert">
			<NcEmptyContent
				:name="t('scholiq', 'This lesson is not available yet')"
				:description="lockedDescription">
				<template #icon>
					<LockOutline />
				</template>
				<template #action>
					<NcButton type="secondary" @click="goBack">
						{{ t('scholiq', 'Back to course') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<article v-else class="lesson-player__content">
			<header class="lesson-player__header">
				<p v-if="course && course.name" class="lesson-player__course">
					{{ course.name }}
				</p>
				<h1 v-if="lesson && lesson.name" class="lesson-player__title">
					{{ lesson.name }}
				</h1>
			</header>

			<section v-if="isLtiLesson" class="lesson-player__lti">
				<div v-if="ltiLaunching" class="lesson-player__loading" aria-live="polite">
					<span class="icon-loading" aria-hidden="true" />
					<span>{{ t('scholiq', 'Starting external tool…') }}</span>
				</div>

				<NcEmptyContent
					v-else-if="ltiError"
					:name="t('scholiq', 'Could not start the external tool')"
					:description="ltiError">
					<template #icon>
						<AlertCircleOutline />
					</template>
					<template #action>
						<NcButton type="secondary" @click="launchLti">
							{{ t('scholiq', 'Try again') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<div v-else-if="ltiLaunch && ltiLaunch.launchMode === 'deep-linking'" class="lesson-player__lti-frame-wrap">
					<iframe
						:name="ltiFrameName"
						class="lesson-player__lti-frame"
						:title="t('scholiq', 'External LTI tool')" />
				</div>

				<NcEmptyContent
					v-else-if="ltiLaunch"
					:name="t('scholiq', 'External tool opened in a new tab')"
					:description="t('scholiq', 'If nothing opened, use the button below.')">
					<template #icon>
						<ApplicationOutline />
					</template>
					<template #action>
						<NcButton type="secondary" @click="launchLti">
							{{ t('scholiq', 'Open tool again') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</section>

			<section v-else-if="isTextLesson" class="lesson-player__blocks">
				<div
					v-for="block in sortedBlocks"
					:key="block.blockId"
					class="lesson-player__block">
					<!-- richText -->
					<div
						v-if="block.type === 'richText'"
						class="lesson-player__block-richtext"
						v-html="renderBlockMarkdown(block.text)" />

					<!-- media -->
					<div v-else-if="block.type === 'media'" class="lesson-player__block-media">
						<template v-if="materialFor(block)">
							<p class="lesson-player__block-media-kind">
								{{ materialKindLabel(materialFor(block).kind) }}
							</p>
							<video
								v-if="materialFor(block).kind === 'video' && materialFor(block).url"
								class="lesson-player__block-video"
								controls
								:src="materialFor(block).url">
								{{ materialFor(block).title }}
							</video>
							<a
								v-else-if="materialFor(block).kind === 'link' && materialFor(block).url"
								:href="materialFor(block).url"
								target="_blank"
								rel="noopener noreferrer">
								{{ materialFor(block).title }}
							</a>
							<p v-else>
								{{ materialFor(block).title }}
							</p>
						</template>
						<p v-else class="lesson-player__block-media-missing">
							{{ t('scholiq', 'Referenced material is unavailable.') }}
						</p>
					</div>

					<!-- quiz -->
					<div v-else-if="block.type === 'quiz'" class="lesson-player__block-quiz">
						<p>{{ assessmentFor(block) ? assessmentFor(block).title : t('scholiq', 'Quiz') }}</p>
						<NcButton type="primary" @click="startQuiz(block)">
							{{ t('scholiq', 'Start quiz') }}
						</NcButton>
					</div>

					<!-- assignment -->
					<div v-else-if="block.type === 'assignment'" class="lesson-player__block-assignment">
						<p>{{ assignmentFor(block) ? assignmentFor(block).title : t('scholiq', 'Assignment') }}</p>
						<NcButton type="secondary" @click="openAssignment(block)">
							{{ t('scholiq', 'View assignment') }}
						</NcButton>
					</div>

					<!-- ltiTool -->
					<div v-else-if="block.type === 'ltiTool'" class="lesson-player__block-lti">
						<div v-if="blockLtiState[block.blockId] && blockLtiState[block.blockId].launching" aria-live="polite">
							<span class="icon-loading" aria-hidden="true" />
							<span>{{ t('scholiq', 'Starting external tool…') }}</span>
						</div>
						<p v-else-if="blockLtiState[block.blockId] && blockLtiState[block.blockId].error" role="alert">
							{{ blockLtiState[block.blockId].error }}
						</p>
						<NcButton v-else type="secondary" @click="launchLtiForBlock(block)">
							{{ t('scholiq', 'Open external tool') }}
						</NcButton>
					</div>
				</div>

				<p v-if="sortedBlocks.length === 0" class="lesson-player__block-empty">
					{{ t('scholiq', 'This lesson has no content blocks yet.') }}
				</p>
			</section>

			<section v-else class="lesson-player__placeholder">
				<NcEmptyContent
					:name="t('scholiq', 'Lesson content not available')"
					:description="t('scholiq', 'This lesson does not yet have playable content. Author-tooling is delivered by the ItemAuthor view.')">
					<template #icon>
						<BookOpenPageVariantOutline />
					</template>
				</NcEmptyContent>
			</section>

			<footer class="lesson-player__footer">
				<NcButton
					v-if="showManualCompleteAction"
					type="primary"
					:disabled="manualCompletion.completed || manualCompletion.saving"
					@click="markLessonComplete">
					{{ manualCompletion.completed ? t('scholiq', 'Completed') : t('scholiq', 'Mark lesson complete') }}
				</NcButton>
				<p v-if="manualCompletion.error" class="lesson-player__manual-complete-error" role="alert">
					{{ manualCompletion.error }}
				</p>
				<NcButton type="secondary" @click="goBack">
					{{ t('scholiq', 'Back to course') }}
				</NcButton>
			</footer>
		</article>
	</div>
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { NcEmptyContent, NcButton } from '@nextcloud/vue'
import { cnRenderMarkdown } from '@conduction/nextcloud-vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'

// learning-progress-and-analytics: contentTypes that do NOT emit xAPI
// statements and therefore need the learner self-serve manual-completion
// path (progress-tracking spec "Learners can self-report completion of
// non-xAPI content"). cmi5/scorm12/scorm2004 rely on the xAPI-sourced path
// (LessonProgressHandler); lti delegates to an external tool launch, which
// is a different render branch entirely (isLtiLesson) with no completion
// action of its own here.
const MANUAL_COMPLETION_CONTENT_TYPES = ['text', 'video', 'quiz']

export default {
	name: 'LessonPlayer',

	components: {
		NcEmptyContent,
		NcButton,
		AlertCircleOutline,
		ApplicationOutline,
		BookOpenPageVariantOutline,
		LockOutline,
	},

	props: {
		/** Course UUID injected by CnAppRoot from the route :courseId param. */
		courseId: {
			type: String,
			required: true,
		},
		/** Lesson UUID injected by CnAppRoot from the route :lessonId param. */
		lessonId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			course: null,
			lesson: null,
			// LTI launch delegation state (contentType === 'lti').
			ltiLaunching: false,
			ltiError: '',
			ltiLaunch: null,
			ltiFrameName: 'scholiq-lti-launch-frame',
			// learning-progress-and-analytics: manual (source: manual)
			// LessonCompletion self-report state for non-xAPI content types.
			manualCompletion: {
				checked: false,
				completed: false,
				saving: false,
				error: '',
			},
			// adaptive-release-and-prerequisites: per-learner release-gate
			// decision from LessonReleaseController::status(). `available`
			// defaults true so a fetch failure never fails CLOSED and hides
			// content that was always meant to be open — best-effort, mirrors
			// checkExistingManualCompletion()'s own fail-open posture.
			releaseStatus: {
				checked: false,
				available: true,
				reason: '',
				availableAt: null,
			},
			// course-authoring-ux: referenced-object caches for block
			// rendering (contentType === 'text'), keyed by UUID.
			materialsById: {},
			assessmentsById: {},
			assignmentsById: {},
			// Per-block LTI launch state, keyed by blockId — a lesson can
			// carry more than one ltiTool block (LtiToolPlacement.lessonId
			// is nullable, so multiple placements can target one Lesson),
			// so launch state cannot be a single shared object the way the
			// whole-lesson contentType='lti' branch uses.
			blockLtiState: {},
		}
	},

	computed: {
		/**
		 * True when this lesson's body is authored as blocks
		 * (contentType === 'text' — course-authoring-ux).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		isTextLesson() {
			return this.lesson?.contentType === 'text'
		},

		/**
		 * lesson.blocks sorted by order — the sequence LessonComposer.vue
		 * persists is trusted, but sort defensively so a manually-edited
		 * object still renders in the right sequence.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		sortedBlocks() {
			return (this.lesson?.blocks ?? []).slice().sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
		},

		/**
		 * True when this lesson's content is an LTI 1.3 tool placement.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/course-management/spec.md#requirement-place-an-lti-1-3-tool-inside-a-lesson-via-a-dedicated-placement-object
		 */
		isLtiLesson() {
			return this.lesson?.contentType === 'lti'
		},

		/**
		 * True when this lesson's contentType does not emit xAPI statements
		 * and the learner should see a self-serve "Mark lesson complete"
		 * action, mirroring AssessmentResult's unrestricted self-serve create
		 * posture (progress-tracking spec).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#requirement-learners-can-self-report-completion-of-non-xapi-content
		 */
		showManualCompleteAction() {
			return MANUAL_COMPLETION_CONTENT_TYPES.includes(this.lesson?.contentType)
		},

		/**
		 * True once the release-status check has run and reported this
		 * lesson as unavailable to the current learner — renders the locked
		 * state instead of any content type (adaptive-release-and-prerequisites).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
		 */
		isLocked() {
			return this.releaseStatus.checked && !this.releaseStatus.available
		},

		/**
		 * Human-readable locked-state description: the unmet-condition reason
		 * from the backend, plus a formatted unlock date when the gate is a
		 * drip delay.
		 *
		 * @return {string}
		 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learner-s-own-enrolment-date
		 */
		lockedDescription() {
			if (this.releaseStatus.reason) {
				return this.releaseStatus.reason
			}
			return this.t('scholiq', 'This lesson is not yet available to you.')
		},
	},

	/**
	 * Load the Course + Lesson pair on mount for xAPI-instrumented playback.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
	 */
	async mounted() {
		try {
			const [courseRes, lessonRes] = await Promise.all([
				fetch(generateUrl('/apps/openregister/api/objects/scholiq/Course/' + this.courseId)),
				fetch(generateUrl('/apps/openregister/api/objects/scholiq/Lesson/' + this.lessonId)),
			])
			if (!courseRes.ok) throw new Error(this.t('scholiq', 'Failed to load course (HTTP {status})', { status: courseRes.status }))
			if (!lessonRes.ok) throw new Error(this.t('scholiq', 'Failed to load lesson (HTTP {status})', { status: lessonRes.status }))
			this.course = await courseRes.json()
			this.lesson = await lessonRes.json()
		} catch (e) {
			this.error = e?.message ?? String(e)
		} finally {
			this.loading = false
		}

		if (this.lesson && !this.error) {
			// adaptive-release-and-prerequisites: MUST resolve before
			// rendering any contentType — checkReleaseStatus() itself never
			// throws (best-effort, see its own doc).
			await this.checkReleaseStatus()
		}

		if (this.isLocked) {
			// Locked: do not initiate the manual-completion check or the LTI
			// launch delegation call — the locked state renders instead of
			// any content-type renderer.
			return
		}

		if (this.showManualCompleteAction) {
			// Best-effort — checkExistingManualCompletion() catches its own
			// errors internally so a failed lookup never blocks the lesson
			// from rendering (the action simply defaults to "not completed").
			await this.checkExistingManualCompletion()
		}

		if (this.isLtiLesson) {
			await this.launchLti()
		}

		if (this.isTextLesson) {
			// Best-effort — a failed reference fetch degrades that one
			// block to its "unavailable" state rather than blocking the
			// whole lesson from rendering.
			await this.loadBlockReferences()
		}
	},

	methods: {
		/**
		 * Navigate back to the parent course detail view.
		 *
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'CourseDetail', params: { id: this.courseId } }).catch(() => {})
			}
		},

		/**
		 * Fetch every object referenced by this lesson's blocks (Material
		 * for media blocks, Assessment for quiz blocks, Assignment for
		 * assignment blocks) so the block renderers below have data to
		 * show. ltiTool blocks resolve their placement lazily on click
		 * (launchLtiForBlock), mirroring the whole-lesson isLtiLesson path.
		 * Best-effort per reference — one failed fetch degrades only that
		 * block, never the whole lesson.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		async loadBlockReferences() {
			const materialIds = new Set()
			const assessmentIds = new Set()
			const assignmentIds = new Set()
			for (const block of this.sortedBlocks) {
				if (block.type === 'media' && block.materialId) materialIds.add(block.materialId)
				if (block.type === 'quiz' && block.assessmentId) assessmentIds.add(block.assessmentId)
				if (block.type === 'assignment' && block.assignmentId) assignmentIds.add(block.assignmentId)
			}

			const fetchInto = async (schema, id, target) => {
				try {
					const resp = await fetch(
						generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${id}`),
						{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
					)
					if (!resp.ok) return
					const json = await resp.json()
					this.$set(target, id, json.object ?? json ?? {})
				} catch {
					// Best-effort — the block renders its "unavailable" state.
				}
			}

			await Promise.all([
				...[...materialIds].map((id) => fetchInto('Material', id, this.materialsById)),
				...[...assessmentIds].map((id) => fetchInto('Assessment', id, this.assessmentsById)),
				...[...assignmentIds].map((id) => fetchInto('Assignment', id, this.assignmentsById)),
			])
		},

		/**
		 * Render a richText block's markdown as sanitised HTML.
		 *
		 * @param {string|null|undefined} text Markdown source.
		 * @return {string}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		renderBlockMarkdown(text) {
			return cnRenderMarkdown(text || '')
		},

		/**
		 * Resolve a media block's referenced Material, or null if not
		 * (yet) loaded.
		 *
		 * @param {object} block A media-type block.
		 * @return {object|null}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		materialFor(block) {
			return this.materialsById[block.materialId] ?? null
		},

		/**
		 * Resolve a quiz block's referenced Assessment, or null.
		 *
		 * @param {object} block A quiz-type block.
		 * @return {object|null}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		assessmentFor(block) {
			return this.assessmentsById[block.assessmentId] ?? null
		},

		/**
		 * Resolve an assignment block's referenced Assignment, or null.
		 *
		 * @param {object} block An assignment-type block.
		 * @return {object|null}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		assignmentFor(block) {
			return this.assignmentsById[block.assignmentId] ?? null
		},

		/**
		 * Human-readable label for a Material.kind value.
		 *
		 * @param {string} kind Material.kind value.
		 * @return {string}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		materialKindLabel(kind) {
			const labels = {
				slides: this.t('scholiq', 'Slides'),
				reading: this.t('scholiq', 'Reading'),
				video: this.t('scholiq', 'Video'),
				scorm: this.t('scholiq', 'SCORM package'),
				cmi5: this.t('scholiq', 'cmi5 package'),
				lti: this.t('scholiq', 'External tool'),
				link: this.t('scholiq', 'Link'),
				document: this.t('scholiq', 'Document'),
				other: this.t('scholiq', 'File'),
			}
			return labels[kind] ?? kind
		},

		/**
		 * Navigate to TakeAssessmentView for a quiz block's referenced
		 * Assessment ("embeds the referenced Assessment's take-flow").
		 *
		 * @param {object} block A quiz-type block.
		 * @return {void}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		startQuiz(block) {
			if (this.$router && block.assessmentId) {
				this.$router.push({ name: 'TakeAssessmentView', params: { assessmentId: block.assessmentId } }).catch(() => {})
			}
		},

		/**
		 * Navigate to AssignmentDetail for an assignment block's referenced
		 * Assignment ("a summary card linking to the referenced Assignment").
		 *
		 * @param {object} block An assignment-type block.
		 * @return {void}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		openAssignment(block) {
			if (this.$router && block.assignmentId) {
				this.$router.push({ name: 'AssignmentDetail', params: { id: block.assignmentId } }).catch(() => {})
			}
		},

		/**
		 * Launch an ltiTool block's LtiToolPlacement — the same
		 * launch-delegation flow launchLti()/submitLtiLaunchForm() already
		 * use for the whole-lesson contentType='lti' branch
		 * (LessonPlayer.vue:238-298 at the time of writing), scoped to this
		 * one block's ltiToolPlacementId rather than lesson.contentRef, with
		 * PER-BLOCK state (blockLtiState) since a lesson may carry more than
		 * one ltiTool block.
		 *
		 * @param {object} block An ltiTool-type block.
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessonplayer-renders-a-lesson-s-authored-blocks
		 */
		async launchLtiForBlock(block) {
			const placementId = block.ltiToolPlacementId
			if (!placementId) {
				this.$set(this.blockLtiState, block.blockId, {
					launching: false,
					error: this.t('scholiq', 'This block has no LTI tool placement configured.'),
				})
				return
			}

			this.$set(this.blockLtiState, block.blockId, { launching: true, error: '' })

			try {
				const res = await fetch(
					generateUrl('/apps/scholiq/api/lti-placements/' + placementId + '/launch'),
					{ method: 'POST', headers: { requesttoken: window.OC?.requestToken ?? '' } },
				)
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(body?.error || this.t('scholiq', 'Failed to start the tool (HTTP {status})', { status: res.status }))
				}
				if (!body?.formActionUrl || !body?.idToken) {
					throw new Error(this.t('scholiq', 'OpenConnector returned an unexpected launch response.'))
				}

				this.$set(this.blockLtiState, block.blockId, { launching: false, error: '' })
				this.submitLtiLaunchForm(body)
			} catch (e) {
				this.$set(this.blockLtiState, block.blockId, { launching: false, error: e?.message ?? String(e) })
			}
		},

		/**
		 * Check whether the current learner already has a LessonCompletion
		 * for this lesson (fetch-all-then-filter convention, mirroring
		 * TakeAssessmentView.vue's checkExistingAttempt() — no field-filter
		 * query parameter is assumed to exist server-side). Best-effort: a
		 * failed lookup is swallowed so it never blocks the lesson from
		 * rendering; the action simply defaults to "not completed".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
		 */
		async checkExistingManualCompletion() {
			try {
				const currentUser = getCurrentUser()
				const learnerId = currentUser?.uid ?? ''
				if (!learnerId) return

				const url = generateUrl('/apps/openregister/api/objects/scholiq/LessonCompletion?limit=100')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) return

				const json = await resp.json()
				const results = json.results ?? json.objects ?? json ?? []
				const existing = results.find(
					(row) => row.learnerId === learnerId && row.lessonId === this.lessonId,
				)

				this.manualCompletion.completed = !!existing
			} catch {
				// Best-effort — swallow, action defaults to "not completed".
			} finally {
				this.manualCompletion.checked = true
			}
		},

		/**
		 * Resolve the current learner's release-gate decision for this
		 * lesson before rendering any content type (adaptive-release-and-
		 * prerequisites). Best-effort — a failed lookup is swallowed and
		 * defaults to `available: true` (fail-open, mirroring
		 * checkExistingManualCompletion()'s own posture) so a transient
		 * network error never hard-locks content that was always meant to
		 * be open.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
		 */
		async checkReleaseStatus() {
			try {
				const url = generateUrl('/apps/scholiq/api/lessons/' + this.lessonId + '/release-status')
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) return

				const body = await resp.json()
				this.releaseStatus.available = body?.available !== false
				this.releaseStatus.reason = body?.reason ?? ''
				this.releaseStatus.availableAt = body?.availableAt ?? null
			} catch {
				// Best-effort — swallow, defaults to available (fail-open).
			} finally {
				this.releaseStatus.checked = true
			}
		},

		/**
		 * Self-report completion of this (non-xAPI-instrumented) lesson —
		 * creates a LessonCompletion (source: manual) for the current
		 * learner, mirroring AssessmentResult's unrestricted self-serve
		 * create posture (progress-tracking spec).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
		 */
		async markLessonComplete() {
			if (this.manualCompletion.completed || this.manualCompletion.saving) return

			this.manualCompletion.saving = true
			this.manualCompletion.error = ''

			try {
				const currentUser = getCurrentUser()
				const learnerId = currentUser?.uid ?? ''

				const url = generateUrl('/apps/openregister/api/objects/scholiq/LessonCompletion')
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						learnerId,
						lessonId: this.lessonId,
						courseId: this.courseId,
						source: 'manual',
						completedAt: new Date().toISOString(),
						tenant_id: this.lesson?.tenant_id ?? this.course?.tenant_id ?? '',
					}),
				})

				if (!resp.ok) {
					throw new Error(this.t('scholiq', 'Failed to mark lesson complete (HTTP {status})', { status: resp.status }))
				}

				this.manualCompletion.completed = true
			} catch (e) {
				this.manualCompletion.error = e?.message ?? String(e)
			} finally {
				this.manualCompletion.saving = false
			}
		},

		/**
		 * Delegate the LTI launch to the backend, which delegates to the
		 * OpenConnector lti-13-platform adapter (opaque proxy — Scholiq
		 * never inspects the id_token). `lesson.contentRef` names the
		 * LtiToolPlacement UUID; the backend resolves it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-place-an-lti-1-3-tool-inside-a-lesson-via-a-dedicated-placement-object
		 * @spec openspec/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-oidc-launch-to-the-openconnector-adapter
		 */
		async launchLti() {
			const placementId = this.lesson?.contentRef
			if (!placementId) {
				this.ltiError = this.t('scholiq', 'This lesson has no LTI tool placement configured.')
				return
			}

			this.ltiLaunching = true
			this.ltiError = ''
			this.ltiLaunch = null

			try {
				const res = await fetch(
					generateUrl('/apps/scholiq/api/lti-placements/' + placementId + '/launch'),
					{ method: 'POST', headers: { requesttoken: window.OC?.requestToken ?? '' } },
				)
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(body?.error || this.t('scholiq', 'Failed to start the tool (HTTP {status})', { status: res.status }))
				}
				if (!body?.formActionUrl || !body?.idToken) {
					throw new Error(this.t('scholiq', 'OpenConnector returned an unexpected launch response.'))
				}

				this.ltiLaunch = body
				this.$nextTick(() => this.submitLtiLaunchForm(body))
			} catch (e) {
				this.ltiError = e?.message ?? String(e)
			} finally {
				this.ltiLaunching = false
			}
		},

		/**
		 * Auto-submit an opaque LTI launch response as a real POST — an
		 * id_token cannot be delivered via a GET navigation. New tab for
		 * launchMode='resource-link', the in-page frame for 'deep-linking'.
		 * Scholiq never reads or validates `idToken` — it is forwarded
		 * exactly as OpenConnector returned it (design.md D5).
		 *
		 * @param {object} launch The opaque {formActionUrl, idToken, launchMode} response.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-place-an-lti-1-3-tool-inside-a-lesson-via-a-dedicated-placement-object
		 */
		submitLtiLaunchForm(launch) {
			const form = document.createElement('form')
			form.method = 'POST'
			form.action = launch.formActionUrl
			form.target = launch.launchMode === 'deep-linking' ? this.ltiFrameName : '_blank'
			form.style.display = 'none'

			const input = document.createElement('input')
			input.type = 'hidden'
			input.name = 'id_token'
			input.value = launch.idToken
			form.appendChild(input)

			document.body.appendChild(form)
			form.submit()
			document.body.removeChild(form)
		},
	},
}
</script>

<style scoped>
.lesson-player {
	padding: 16px;
}

.lesson-player__loading,
.lesson-player__error,
.lesson-player__locked,
.lesson-player__placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 32px 16px;
}

.lesson-player__header {
	margin-bottom: 24px;
}

.lesson-player__course {
	color: var(--color-text-maxcontrast);
	margin: 0 0 4px;
}

.lesson-player__title {
	margin: 0 0 8px;
}

.lesson-player__summary {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.lesson-player__body {
	line-height: 1.6;
}

.lesson-player__lti-frame-wrap {
	width: 100%;
	min-height: 480px;
}

.lesson-player__lti-frame {
	width: 100%;
	min-height: 480px;
	border: none;
}

.lesson-player__footer {
	margin-top: 32px;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.lesson-player__manual-complete-error {
	color: var(--color-error);
	margin: 0;
	font-size: 0.9em;
}

.lesson-player__blocks {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.lesson-player__block {
	line-height: 1.6;
}

.lesson-player__block-richtext :deep(p:first-child) {
	margin-top: 0;
}

.lesson-player__block-media,
.lesson-player__block-quiz,
.lesson-player__block-assignment,
.lesson-player__block-lti {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.lesson-player__block-media-kind {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.lesson-player__block-media-missing {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.lesson-player__block-video {
	max-width: 100%;
}

.lesson-player__block-empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
