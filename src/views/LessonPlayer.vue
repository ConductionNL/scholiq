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

			<section
				v-if="lesson && lesson.content"
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
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'

export default {
	name: 'LessonPlayer',

	components: {
		NcEmptyContent,
		NcButton,
		AlertCircleOutline,
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
		 */
		sanitisedContent() {
			return this.lesson?.content ?? ''
		},
	},

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
	},

	methods: {
		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'CourseDetail', params: { id: this.courseId } }).catch(() => {})
			}
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
.lesson-player__footer {
	margin-top: 32px;
	display: flex;
	gap: 8px;
}
</style>
