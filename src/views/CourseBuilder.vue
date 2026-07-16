<!--
  CourseBuilder.vue
  Custom page component for the CourseBuilder manifest page (type: custom).

  course-authoring-ux: the Course/Module/Lesson tree editor. A module is a
  child Course (parentCourseId = this course); lessons live under each
  module. Supports create/delete of modules and lessons, dual-path reorder
  (drag-and-drop via vuedraggable AND keyboard-operable Move up/down
  controls — WCAG 2.1 AA SC 2.1.1, design.md D4), "Save as template" (writes
  a CourseTemplate skeleton from the current tree) and "New course from
  template" (instantiates a CourseTemplate into a fresh, independent Course
  tree — the "Clone for next year" capability).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Course/:courseId
    - GET  /api/objects/scholiq/Course?filters[parentCourseId]=:courseId
    - GET  /api/objects/scholiq/Lesson?filters[courseId]=:moduleId
    - POST /api/objects/scholiq/Course | Lesson | CourseTemplate
    - PUT  /api/objects/scholiq/Course/:id | Lesson/:id  (order updates)
    - DELETE /api/objects/scholiq/Course/:id | Lesson/:id
    - GET  /api/objects/scholiq/CourseTemplate

  No new PHP controller: every write is a call against OpenRegister's
  existing object-create/update/delete endpoints (ADR-022). Template
  instantiation is a sequence of OR object-create calls issued from here
  (design.md D5) — no CourseTemplateController::instantiate() exists.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring PortfolioBuilder.vue's existing shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-declares-its-display-order-among-sibling-modules
  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
  @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
-->

<template>
	<div class="course-builder">
		<div v-if="loading" class="course-builder__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading course…') }}</span>
		</div>

		<div v-else-if="error" class="course-builder__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<!-- Move/status announcements for assistive technology (design.md D4). -->
			<p class="course-builder__sr-live" aria-live="polite" role="status">
				{{ liveMessage }}
			</p>

			<header class="course-builder__header">
				<h2>{{ t('scholiq', 'Course builder: {name}', { name: course.name || '' }) }}</h2>
				<div class="course-builder__header-actions">
					<button class="button-vue" @click="goBack">
						{{ t('scholiq', 'Back to course') }}
					</button>
					<button class="button-vue" @click="showSaveTemplate = !showSaveTemplate">
						{{ t('scholiq', 'Save as template') }}
					</button>
					<button class="button-vue" @click="onOpenInstantiate">
						{{ t('scholiq', 'New course from template') }}
					</button>
				</div>
			</header>

			<!-- Save as template -->
			<section v-if="showSaveTemplate" class="course-builder__panel">
				<h3>{{ t('scholiq', 'Save this course as a template') }}</h3>
				<label class="course-builder__field-label" for="cb-template-name">
					{{ t('scholiq', 'Template name') }}
				</label>
				<input
					id="cb-template-name"
					v-model="saveTemplateForm.name"
					type="text"
					class="course-builder__input">
				<label class="course-builder__field-label" for="cb-template-desc">
					{{ t('scholiq', 'Description') }}
				</label>
				<textarea
					id="cb-template-desc"
					v-model="saveTemplateForm.description"
					class="course-builder__textarea"
					rows="2" />
				<div class="course-builder__panel-actions">
					<button
						class="button-vue button-vue--primary"
						:disabled="savingTemplate || !saveTemplateForm.name"
						@click="saveAsTemplate">
						<span v-if="savingTemplate" class="icon-loading" aria-hidden="true" />
						{{ t('scholiq', 'Save template') }}
					</button>
				</div>
				<p v-if="saveTemplateError" role="alert" class="course-builder__inline-error">
					{{ saveTemplateError }}
				</p>
				<p v-if="saveTemplateDone" role="status" class="course-builder__inline-success">
					{{ t('scholiq', 'Template saved.') }}
				</p>
			</section>

			<!-- New course from template -->
			<section v-if="showInstantiate" class="course-builder__panel">
				<h3>{{ t('scholiq', 'Create a new course from a template') }}</h3>
				<NcSelect
					v-model="instantiateForm.templateId"
					:options="templateOptions"
					:reduce="(opt) => opt.id"
					:input-label="t('scholiq', 'Template')"
					:aria-label-combobox="t('scholiq', 'Template')" />
				<label class="course-builder__field-label" for="cb-new-course-name">
					{{ t('scholiq', 'New course name') }}
				</label>
				<input
					id="cb-new-course-name"
					v-model="instantiateForm.name"
					type="text"
					class="course-builder__input">
				<div class="course-builder__panel-actions">
					<button
						class="button-vue button-vue--primary"
						:disabled="instantiating || !instantiateForm.templateId || !instantiateForm.name"
						@click="instantiateTemplate">
						<span v-if="instantiating" class="icon-loading" aria-hidden="true" />
						{{ t('scholiq', 'Create course') }}
					</button>
				</div>
				<p v-if="instantiateError" role="alert" class="course-builder__inline-error">
					{{ instantiateError }}
				</p>
			</section>

			<!-- Modules -->
			<section class="course-builder__modules">
				<h3>{{ t('scholiq', 'Modules') }}</h3>

				<draggable
					v-model="modules"
					tag="ul"
					class="course-builder__module-list"
					handle=".course-builder__handle"
					@end="onModulesDragEnd">
					<li
						v-for="(module, mIdx) in modules"
						:key="module.id"
						class="course-builder__module">
						<div class="course-builder__module-row">
							<span class="course-builder__handle icon-menu" aria-hidden="true" />
							<span class="course-builder__module-name">{{ module.name }}</span>
							<button
								type="button"
								class="course-builder__icon-btn"
								:disabled="mIdx === 0"
								:aria-label="t('scholiq', 'Move module \'{name}\' up', { name: module.name })"
								@click="moveModuleUp(mIdx)">
								<ChevronUp :size="18" />
							</button>
							<button
								type="button"
								class="course-builder__icon-btn"
								:disabled="mIdx === modules.length - 1"
								:aria-label="t('scholiq', 'Move module \'{name}\' down', { name: module.name })"
								@click="moveModuleDown(mIdx)">
								<ChevronDown :size="18" />
							</button>
							<button
								type="button"
								class="course-builder__icon-btn"
								:aria-label="t('scholiq', 'Delete module \'{name}\'', { name: module.name })"
								@click="deleteModule(module, mIdx)">
								<DeleteOutline :size="18" />
							</button>
						</div>

						<draggable
							v-model="module.lessons"
							tag="ul"
							class="course-builder__lesson-list"
							handle=".course-builder__handle"
							@end="onLessonsDragEnd(module)">
							<li
								v-for="(lesson, lIdx) in module.lessons"
								:key="lesson.id"
								class="course-builder__lesson">
								<span class="course-builder__handle icon-menu" aria-hidden="true" />
								<span class="course-builder__lesson-name">{{ lesson.name }}</span>
								<span class="course-builder__lesson-type">{{ lesson.contentType }}</span>
								<button
									type="button"
									class="course-builder__icon-btn"
									:disabled="lIdx === 0"
									:aria-label="t('scholiq', 'Move lesson \'{name}\' up', { name: lesson.name })"
									@click="moveLessonUp(module, lIdx)">
									<ChevronUp :size="16" />
								</button>
								<button
									type="button"
									class="course-builder__icon-btn"
									:disabled="lIdx === module.lessons.length - 1"
									:aria-label="t('scholiq', 'Move lesson \'{name}\' down', { name: lesson.name })"
									@click="moveLessonDown(module, lIdx)">
									<ChevronDown :size="16" />
								</button>
								<button
									type="button"
									class="button-vue"
									@click="openComposer(lesson)">
									{{ t('scholiq', 'Compose') }}
								</button>
								<button
									type="button"
									class="button-vue"
									@click="openPlayer(lesson)">
									{{ t('scholiq', 'Preview') }}
								</button>
								<button
									type="button"
									class="course-builder__icon-btn"
									:aria-label="t('scholiq', 'Delete lesson \'{name}\'', { name: lesson.name })"
									@click="deleteLesson(module, lIdx)">
									<DeleteOutline :size="16" />
								</button>
							</li>
						</draggable>

						<div class="course-builder__add-row">
							<input
								v-model="newLessonNames[module.id]"
								type="text"
								class="course-builder__input"
								:placeholder="t('scholiq', 'New lesson name')"
								:aria-label="t('scholiq', 'New lesson name for module {name}', { name: module.name })">
							<button
								type="button"
								class="button-vue"
								:disabled="!newLessonNames[module.id]"
								@click="addLesson(module)">
								<PlusIcon :size="16" />
								{{ t('scholiq', 'Add lesson') }}
							</button>
						</div>
					</li>
				</draggable>

				<div class="course-builder__add-row">
					<input
						v-model="newModuleName"
						type="text"
						class="course-builder__input"
						:placeholder="t('scholiq', 'New module name')"
						:aria-label="t('scholiq', 'New module name')">
					<button
						type="button"
						class="button-vue button-vue--secondary"
						:disabled="!newModuleName"
						@click="addModule">
						<PlusIcon :size="16" />
						{{ t('scholiq', 'Add module') }}
					</button>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { compareByOrder } from '../utils/courseOrder.js'

export default {
	name: 'CourseBuilder',

	components: {
		NcSelect,
		draggable,
		ChevronUp,
		ChevronDown,
		DeleteOutline,
		PlusIcon,
	},

	props: {
		/** Course UUID injected by vue-router from the :courseId route param. */
		courseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			course: null,
			/** @type {Array<object>} Each entry: {...Course fields, lessons: Array<Lesson>}. */
			modules: [],
			liveMessage: '',
			newModuleName: '',
			/** @type {Record<string, string>} Pending new-lesson-name input keyed by module id. */
			newLessonNames: {},
			showSaveTemplate: false,
			savingTemplate: false,
			saveTemplateError: '',
			saveTemplateDone: false,
			saveTemplateForm: { name: '', description: '' },
			showInstantiate: false,
			instantiating: false,
			instantiateError: '',
			instantiateForm: { templateId: '', name: '' },
			/** @type {Array<object>} */
			templates: [],
		}
	},

	computed: {
		/**
		 * NcSelect options for the CourseTemplate picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		templateOptions() {
			return this.templates.map((tpl) => ({ id: tpl.id, label: tpl.name }))
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the Course, its child modules (Course rows with
		 * parentCourseId = this course), and each module's Lessons —
		 * sorted by order with null sorting last (course-authoring-ux
		 * "A designer sets module order in the course builder" /
		 * "A pre-existing module without an order value sorts last" scenarios).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-declares-its-display-order-among-sibling-modules
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.course = await this.fetchObject('Course', this.courseId)
				const modules = await this.fetchList('Course', `filters[parentCourseId]=${this.courseId}&limit=200`)
				modules.sort(compareByOrder)
				for (const module of modules) {
					const lessons = await this.fetchList('Lesson', `filters[courseId]=${module.id}&limit=200`)
					lessons.sort(compareByOrder)
					module.lessons = lessons
				}
				this.modules = modules
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load the course. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] load error', err)
			} finally {
				this.loading = false
			}
		},

		/** @return {void} */
		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'CourseDetail', params: { id: this.courseId } }).catch(() => {})
			}
		},

		/**
		 * Navigate to LessonComposer for the given lesson.
		 *
		 * @param {object} lesson The Lesson row.
		 * @return {void}
		 */
		openComposer(lesson) {
			if (this.$router) {
				this.$router.push({ name: 'LessonComposer', params: { courseId: this.courseId, lessonId: lesson.id } }).catch(() => {})
			}
		},

		/**
		 * Navigate to LessonPlayer for the given lesson (preview).
		 *
		 * @param {object} lesson The Lesson row.
		 * @return {void}
		 */
		openPlayer(lesson) {
			if (this.$router) {
				this.$router.push({ name: 'LessonPlayer', params: { courseId: this.courseId, lessonId: lesson.id } }).catch(() => {})
			}
		},

		/**
		 * Fetch a single OR object.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} objId Object UUID.
		 * @return {Promise<object>}
		 */
		async fetchObject(schema, objId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${objId}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) throw new Error(`${schema} fetch failed: ${resp.status}`)
			const json = await resp.json()
			return json.object ?? json ?? {}
		},

		/**
		 * Fetch a filtered list of OR objects.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} query Pre-built query string.
		 * @return {Promise<Array<object>>}
		 */
		async fetchList(schema, query) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}?${query}`)
			const resp = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) return []
			const json = await resp.json()
			return json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
		},

		/**
		 * Create an OR object.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {object} body Payload.
		 * @return {Promise<object>} The created object.
		 */
		async createObject(schema, body) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}`)
			const resp = await fetch(url, {
				method: 'POST',
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify(body),
			})
			if (!resp.ok) throw new Error(`${schema} create failed: ${resp.status}`)
			const json = await resp.json()
			return json.object ?? json ?? {}
		},

		/**
		 * Partially update an OR object (PUT with only the changed fields —
		 * precedented by ProctoringReviewQueue.vue's flags-only PUT body).
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} objId Object UUID.
		 * @param {object} patch Partial payload.
		 * @return {Promise<void>}
		 */
		async updateObject(schema, objId, patch) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${objId}`)
			const resp = await fetch(url, {
				method: 'PUT',
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify(patch),
			})
			if (!resp.ok) throw new Error(`${schema} update failed: ${resp.status}`)
		},

		/**
		 * Delete an OR object.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} objId Object UUID.
		 * @return {Promise<void>}
		 */
		async deleteObject(schema, objId) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${objId}`)
			const resp = await fetch(url, { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } })
			if (!resp.ok) throw new Error(`${schema} delete failed: ${resp.status}`)
		},

		/**
		 * Recompute contiguous 1-based `order` for every item in `list` and
		 * persist only the ones that changed. The single place both the
		 * drag-and-drop handlers and the keyboard move handlers converge on
		 * (design.md D4) — neither path re-implements order bookkeeping.
		 *
		 * @param {Array<object>} list Modules or lessons array, already in its final order.
		 * @param {string} schema 'Course' (modules) or 'Lesson' (lessons).
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		async persistOrder(list, schema) {
			const updates = []
			list.forEach((item, idx) => {
				const newOrder = idx + 1
				if (item.order !== newOrder) {
					item.order = newOrder
					updates.push(this.updateObject(schema, item.id, { order: newOrder }))
				}
			})
			if (updates.length) {
				await Promise.all(updates)
			}
		},

		/**
		 * Move `list[fromIndex]` to `toIndex`, persist the resulting order,
		 * and announce the result. Used by the keyboard move-up/move-down
		 * controls; the drag-and-drop `@end` handlers reuse `persistOrder`
		 * directly since vuedraggable's `v-model` has already spliced the
		 * array by the time `@end` fires.
		 *
		 * @param {Array<object>} list Modules or lessons array.
		 * @param {number} fromIndex Current index.
		 * @param {number} toIndex Target index.
		 * @param {string} schema 'Course' or 'Lesson'.
		 * @param {string} noun Human-readable noun for the announcement ('Module' or 'Lesson').
		 * @return {Promise<void>}
		 */
		async reorder(list, fromIndex, toIndex, schema, noun) {
			if (fromIndex === toIndex || toIndex < 0 || toIndex >= list.length) return
			const [moved] = list.splice(fromIndex, 1)
			list.splice(toIndex, 0, moved)
			await this.persistOrder(list, schema)
			this.liveMessage = this.t(
				'scholiq',
				'{noun} moved to position {pos} of {total}',
				{ noun, pos: toIndex + 1, total: list.length },
			)
		},

		/** @param {number} idx Module index. @return {Promise<void>} */
		moveModuleUp(idx) {
			return this.reorder(this.modules, idx, idx - 1, 'Course', this.t('scholiq', 'Module'))
		},

		/** @param {number} idx Module index. @return {Promise<void>} */
		moveModuleDown(idx) {
			return this.reorder(this.modules, idx, idx + 1, 'Course', this.t('scholiq', 'Module'))
		},

		/**
		 * @param {object} module Parent module.
		 * @param {number} idx Lesson index within module.lessons.
		 * @return {Promise<void>}
		 */
		moveLessonUp(module, idx) {
			return this.reorder(module.lessons, idx, idx - 1, 'Lesson', this.t('scholiq', 'Lesson'))
		},

		/**
		 * @param {object} module Parent module.
		 * @param {number} idx Lesson index within module.lessons.
		 * @return {Promise<void>}
		 */
		moveLessonDown(module, idx) {
			return this.reorder(module.lessons, idx, idx + 1, 'Lesson', this.t('scholiq', 'Lesson'))
		},

		/**
		 * vuedraggable `@end` handler for the modules list — the array is
		 * already reordered by `v-model`; just persist + announce.
		 *
		 * @return {Promise<void>}
		 */
		async onModulesDragEnd() {
			await this.persistOrder(this.modules, 'Course')
			this.liveMessage = this.t('scholiq', 'Module order updated.')
		},

		/**
		 * vuedraggable `@end` handler for one module's lesson list.
		 *
		 * @param {object} module The module whose lessons were reordered.
		 * @return {Promise<void>}
		 */
		async onLessonsDragEnd(module) {
			await this.persistOrder(module.lessons, 'Lesson')
			this.liveMessage = this.t('scholiq', 'Lesson order updated.')
		},

		/**
		 * Create a new module (child Course) at the end of the module list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async addModule() {
			if (!this.newModuleName || !this.course) return
			try {
				const created = await this.createObject('Course', {
					code: `${this.course.code || 'MOD'}-${this.modules.length + 1}`,
					name: this.newModuleName,
					level: this.course.level,
					language: this.course.language,
					parentCourseId: this.courseId,
					order: this.modules.length + 1,
					tenant_id: this.course.tenant_id,
				})
				created.lessons = []
				this.modules.push(created)
				this.newModuleName = ''
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to add module. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] addModule error', err)
			}
		},

		/**
		 * Delete a module (and, implicitly, its lessons stop being reachable
		 * from this tree — OpenRegister owns cascade/soft-delete behaviour).
		 *
		 * @param {object} module The module to delete.
		 * @param {number} idx Its index in `modules`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async deleteModule(module, idx) {
			try {
				await this.deleteObject('Course', module.id)
				this.modules.splice(idx, 1)
				await this.persistOrder(this.modules, 'Course')
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to delete module. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] deleteModule error', err)
			}
		},

		/**
		 * Create a new Lesson at the end of a module's lesson list.
		 * contentType defaults to 'text' (native, block-composed) so the
		 * lesson is immediately valid without a contentRef (design.md D2).
		 *
		 * @param {object} module The parent module.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async addLesson(module) {
			const name = this.newLessonNames[module.id]
			if (!name) return
			try {
				const created = await this.createObject('Lesson', {
					courseId: module.id,
					name,
					order: module.lessons.length + 1,
					contentType: 'text',
					blocks: [],
					tenant_id: module.tenant_id,
				})
				module.lessons.push(created)
				this.$set(this.newLessonNames, module.id, '')
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to add lesson. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] addLesson error', err)
			}
		},

		/**
		 * Delete a lesson from a module.
		 *
		 * @param {object} module The parent module.
		 * @param {number} idx The lesson's index in module.lessons.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async deleteLesson(module, idx) {
			const lesson = module.lessons[idx]
			try {
				await this.deleteObject('Lesson', lesson.id)
				module.lessons.splice(idx, 1)
				await this.persistOrder(module.lessons, 'Lesson')
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to delete lesson. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] deleteLesson error', err)
			}
		},

		/**
		 * Capture the current Course tree into a new CourseTemplate — module
		 * and lesson names/order/contentType, and lightweight richText block
		 * placeholders only (no live materialId/assessmentId/assignmentId/
		 * ltiToolPlacementId pointers, design.md D5). The source Course and
		 * its Lessons are left unchanged.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
		 */
		async saveAsTemplate() {
			if (!this.course || !this.saveTemplateForm.name) return
			this.savingTemplate = true
			this.saveTemplateError = ''
			this.saveTemplateDone = false

			const moduleStructure = this.modules.map((module, mIdx) => ({
				key: `module-${mIdx + 1}`,
				name: module.name,
				order: module.order ?? mIdx + 1,
				ectsCredits: module.ectsCredits ?? null,
				lessons: module.lessons.map((lesson, lIdx) => ({
					key: `lesson-${mIdx + 1}-${lIdx + 1}`,
					name: lesson.name,
					order: lesson.order ?? lIdx + 1,
					contentType: lesson.contentType,
					durationMinutes: lesson.durationMinutes ?? null,
					blocksSkeleton: (lesson.blocks || [])
						.filter((b) => b.type === 'richText')
						.map((b) => ({
							blockId: b.blockId,
							type: 'richText',
							order: b.order,
							text: this.t('scholiq', 'Introduction — replace with your own text'),
						})),
				})),
			}))

			try {
				const created = await this.createObject('CourseTemplate', {
					name: this.saveTemplateForm.name,
					description: this.saveTemplateForm.description || null,
					level: this.course.level,
					sourceCoursesId: this.courseId,
					moduleStructure,
					tenant_id: this.course.tenant_id,
				})
				this.saveTemplateDone = true
				this.saveTemplateForm = { name: '', description: '' }
				if (this.$router && created.id) {
					this.$router.push({ name: 'CourseTemplateDetail', params: { id: created.id } }).catch(() => {})
				}
			} catch (err) {
				this.saveTemplateError = this.t('scholiq', 'Failed to save the template. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] saveAsTemplate error', err)
			} finally {
				this.savingTemplate = false
			}
		},

		/**
		 * Load available CourseTemplates when the instantiate panel opens.
		 *
		 * @return {Promise<void>}
		 */
		async onOpenInstantiate() {
			this.showInstantiate = !this.showInstantiate
			if (this.showInstantiate && this.templates.length === 0) {
				this.templates = await this.fetchList('CourseTemplate', 'limit=200')
			}
		},

		/**
		 * Instantiate the selected CourseTemplate into a new, independent
		 * Course tree — one Course, one child Course per moduleStructure
		 * entry, one Lesson per nested lesson entry, and (if
		 * curriculumPlanSkeleton is set) one CurriculumPlan back-set onto
		 * the new Course. Frontend orchestration only (design.md D5) — no
		 * CourseTemplateController::instantiate() PHP endpoint exists. The
		 * new Course starts lifecycle draft with zero enrolments.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
		 */
		async instantiateTemplate() {
			if (!this.instantiateForm.templateId || !this.instantiateForm.name) return
			this.instantiating = true
			this.instantiateError = ''

			try {
				const template = await this.fetchObject('CourseTemplate', this.instantiateForm.templateId)
				const tenantId = template.tenant_id || this.course?.tenant_id || ''

				const newCourse = await this.createObject('Course', {
					code: `${(this.instantiateForm.name || 'COURSE').slice(0, 12).toUpperCase().replace(/\s+/g, '-')}-${Date.now()}`,
					name: this.instantiateForm.name,
					level: template.level,
					language: this.course?.language || 'nl',
					tenant_id: tenantId,
				})

				for (const moduleSkeleton of (template.moduleStructure || [])) {
					const newModule = await this.createObject('Course', {
						code: `${newCourse.code}-${moduleSkeleton.key}`,
						name: moduleSkeleton.name,
						level: template.level,
						language: this.course?.language || 'nl',
						parentCourseId: newCourse.id,
						order: moduleSkeleton.order,
						ectsCredits: moduleSkeleton.ectsCredits ?? null,
						tenant_id: tenantId,
					})

					for (const lessonSkeleton of (moduleSkeleton.lessons || [])) {
						await this.createObject('Lesson', {
							courseId: newModule.id,
							name: lessonSkeleton.name,
							order: lessonSkeleton.order,
							contentType: lessonSkeleton.contentType,
							durationMinutes: lessonSkeleton.durationMinutes ?? null,
							blocks: (lessonSkeleton.blocksSkeleton || []).map((b) => ({
								blockId: b.blockId,
								type: b.type,
								order: b.order,
								text: b.text ?? null,
							})),
							tenant_id: tenantId,
						})
					}
				}

				if (template.curriculumPlanSkeleton) {
					const cp = await this.createObject('CurriculumPlan', {
						name: this.t('scholiq', '{name} — curriculum plan', { name: this.instantiateForm.name }),
						kind: template.curriculumPlanSkeleton.kind,
						formula: template.curriculumPlanSkeleton.formula,
						components: template.curriculumPlanSkeleton.components || [],
						periods: template.curriculumPlanSkeleton.periods || [],
						passRules: template.curriculumPlanSkeleton.passRules || [],
						tenant_id: tenantId,
					})
					await this.updateObject('Course', newCourse.id, { curriculumPlanId: cp.id })
				}

				this.instantiateForm = { templateId: '', name: '' }
				this.showInstantiate = false
				if (this.$router) {
					this.$router.push({ name: 'CourseBuilder', params: { courseId: newCourse.id } }).catch(() => {})
				}
			} catch (err) {
				this.instantiateError = this.t('scholiq', 'Failed to create the course from this template. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] instantiateTemplate error', err)
			} finally {
				this.instantiating = false
			}
		},
	},
}
</script>

<style scoped>
.course-builder {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.course-builder__loading,
.course-builder__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-builder__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.course-builder__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-builder__header-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.course-builder__panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-builder__panel-actions {
	margin-top: 8px;
}

.course-builder__field-label {
	display: block;
	font-weight: 500;
	margin: 8px 0 4px;
}

.course-builder__input,
.course-builder__textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
}

.course-builder__module-list,
.course-builder__lesson-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.course-builder__module {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
}

.course-builder__module-row,
.course-builder__lesson {
	display: flex;
	align-items: center;
	gap: 6px;
}

.course-builder__lesson-list {
	margin: 8px 0 8px 24px;
	padding-left: 8px;
	border-left: 2px solid var(--color-border);
}

.course-builder__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.course-builder__module-name {
	font-weight: 600;
	flex: 1 1 auto;
}

.course-builder__lesson-name {
	flex: 1 1 auto;
}

.course-builder__lesson-type {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.course-builder__icon-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 4px;
	display: inline-flex;
}

.course-builder__icon-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.course-builder__icon-btn:hover:not(:disabled) {
	color: var(--color-main-text);
}

.course-builder__add-row {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	align-items: center;
}

.course-builder__inline-error {
	color: var(--color-error);
	margin-top: 8px;
}

.course-builder__inline-success {
	color: var(--color-success);
	margin-top: 8px;
}
</style>
