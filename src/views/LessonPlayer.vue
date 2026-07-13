<!--
  LessonPlayer.vue
  Custom page component for the LessonPlayer manifest page (type: custom).

  Bespoke lesson playback view. Renders the lesson content for a given
  course+lesson pair, with xAPI-instrumented progress tracking and
  next/previous navigation between lessons in the same course.

  Talks only to OpenRegister's REST API:
    - GET /api/objects/scholiq/Course/:courseId
    - GET /api/objects/scholiq/Lesson/:lessonId
    - POST /api/objects/scholiq/LessonProgress (record xAPI statements)

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
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

		<article v-else class="lesson-player__content">
			<header class="lesson-player__header">
				<p v-if="course && course.title" class="lesson-player__course">
					{{ course.title }}
				</p>
				<h1 v-if="lesson && lesson.title" class="lesson-player__title">
					{{ lesson.title }}
				</h1>
				<p v-if="lesson && lesson.summary" class="lesson-player__summary">
					{{ lesson.summary }}
				</p>
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

			<section
				v-else-if="lesson && lesson.content"
				class="lesson-player__body"
				v-html="sanitisedContent" />

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
import { NcEmptyContent, NcButton } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'

export default {
	name: 'LessonPlayer',

	components: {
		NcEmptyContent,
		NcButton,
		AlertCircleOutline,
		ApplicationOutline,
		BookOpenPageVariantOutline,
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
		}
	},

	computed: {
		/**
		 * Pass-through of the lesson's HTML content. The server validates
		 * the content payload via OR's value-validator; this template renders
		 * it as a string. Author-tooling is responsible for sanitisation
		 * before persistence.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
		 */
		sanitisedContent() {
			return this.lesson?.content ?? ''
		},

		/**
		 * True when this lesson's content is an LTI 1.3 tool placement.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/lti-tool-placement/tasks.md#task-3.1
		 */
		isLtiLesson() {
			return this.lesson?.contentType === 'lti'
		},
	},

	/**
	 * Load the Course + Lesson pair on mount for xAPI-instrumented playback.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
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

		if (this.isLtiLesson) {
			await this.launchLti()
		}
	},

	methods: {
		/**
		 * Navigate back to the parent course detail view.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
		 */
		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'CourseDetail', params: { id: this.courseId } }).catch(() => {})
			}
		},

		/**
		 * Delegate the LTI launch to the backend, which delegates to the
		 * OpenConnector lti-13-platform adapter (opaque proxy — Scholiq
		 * never inspects the id_token). `lesson.contentRef` names the
		 * LtiToolPlacement UUID; the backend resolves it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lti-tool-placement/tasks.md#task-3.1
		 * @spec openspec/changes/lti-tool-placement/tasks.md#task-3.2
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
		 * @spec openspec/changes/lti-tool-placement/tasks.md#task-3.1
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
	gap: 8px;
}
</style>
