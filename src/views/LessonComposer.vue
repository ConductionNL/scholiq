<!--
  LessonComposer.vue
  Custom page component for the LessonComposer manifest page (type: custom).

  course-authoring-ux: the per-lesson block editor. Composes a
  contentType='text' Lesson's body as an ordered list of typed blocks —
  richText (CnMarkdownEditor, inline markdown), media (pointer to an
  existing Material, or a freshly-created one via the NC file picker),
  quiz/assignment/ltiTool (NcSelect pickers against existing
  Assessment/Assignment/LtiToolPlacement objects). Dual-path reorder
  (drag-and-drop via vuedraggable AND keyboard-operable Move up/down —
  WCAG 2.1 AA SC 2.1.1, design.md D4), sharing the same reorder pattern as
  CourseBuilder.vue.

  Talks only to OpenRegister's REST API:
    - GET /api/objects/scholiq/Lesson/:lessonId
    - GET /api/objects/scholiq/Course/:courseId
    - GET /api/objects/scholiq/Material|Assessment|Assignment|LtiToolPlacement
      (scoped pickers)
    - POST /api/objects/scholiq/Material (new media block upload)
    - PUT  /api/objects/scholiq/Lesson/:lessonId (persists the full blocks array)

  No new PHP controller — every write is a call against OpenRegister's
  existing object-create/update endpoints (ADR-022). A media block never
  duplicates the referenced Material's fileRef/kind/license (design.md D3) —
  it stores only materialId.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring CourseBuilder.vue's shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
  @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-media-block-references-an-existing-material-rather-than-duplicating-file-metadata
-->

<template>
	<div class="lesson-composer">
		<div v-if="loading" class="lesson-composer__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading lesson…') }}</span>
		</div>

		<div v-else-if="error" class="lesson-composer__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<p class="lesson-composer__sr-live" aria-live="polite" role="status">
				{{ liveMessage }}
			</p>

			<header class="lesson-composer__header">
				<h2>{{ t('scholiq', 'Compose lesson: {name}', { name: lesson.name || '' }) }}</h2>
				<div class="lesson-composer__header-actions">
					<button class="button-vue" @click="goBack">
						{{ t('scholiq', 'Back to builder') }}
					</button>
					<button class="button-vue" @click="openPlayer">
						{{ t('scholiq', 'Preview') }}
					</button>
					<button
						class="button-vue button-vue--primary"
						:disabled="saving"
						@click="save">
						<span v-if="saving" class="icon-loading" aria-hidden="true" />
						{{ t('scholiq', 'Save lesson') }}
					</button>
				</div>
			</header>
			<p v-if="lesson.contentType !== 'text'" class="lesson-composer__notice" role="status">
				{{ t('scholiq', "This lesson's contentType is '{type}', not 'text' — blocks only render in LessonPlayer once contentType is set to 'text'.", { type: lesson.contentType }) }}
			</p>
			<p v-if="saveError" role="alert" class="lesson-composer__inline-error">
				{{ saveError }}
			</p>
			<p v-if="saveDone" role="status" class="lesson-composer__inline-success">
				{{ t('scholiq', 'Lesson saved.') }}
			</p>

			<draggable
				v-model="blocks"
				tag="ul"
				class="lesson-composer__block-list"
				handle=".lesson-composer__handle"
				@end="onBlocksDragEnd">
				<li
					v-for="(block, idx) in blocks"
					:key="block.blockId"
					class="lesson-composer__block">
					<div class="lesson-composer__block-row">
						<span class="lesson-composer__handle icon-menu" aria-hidden="true" />
						<span class="lesson-composer__block-type">{{ blockTypeLabel(block.type) }}</span>
						<button
							type="button"
							class="lesson-composer__icon-btn"
							:disabled="idx === 0"
							:aria-label="t('scholiq', 'Move {type} block up', { type: blockTypeLabel(block.type) })"
							@click="moveBlockUp(idx)">
							<ChevronUp :size="18" />
						</button>
						<button
							type="button"
							class="lesson-composer__icon-btn"
							:disabled="idx === blocks.length - 1"
							:aria-label="t('scholiq', 'Move {type} block down', { type: blockTypeLabel(block.type) })"
							@click="moveBlockDown(idx)">
							<ChevronDown :size="18" />
						</button>
						<button
							type="button"
							class="lesson-composer__icon-btn"
							:aria-label="t('scholiq', 'Remove {type} block', { type: blockTypeLabel(block.type) })"
							@click="removeBlock(idx)">
							<DeleteOutline :size="18" />
						</button>
					</div>

					<!-- richText -->
					<div v-if="block.type === 'richText'" class="lesson-composer__block-body">
						<CnMarkdownEditor
							:value="block.text || ''"
							:aria-label="t('scholiq', 'Rich text content')"
							:rows="6"
							@input="(v) => onBlockFieldInput(block, 'text', v)" />
					</div>

					<!-- media -->
					<div v-else-if="block.type === 'media'" class="lesson-composer__block-body">
						<NcSelect
							v-model="block.materialId"
							:options="materialOptions"
							:reduce="(opt) => opt.id"
							:input-label="t('scholiq', 'Material')"
							:aria-label-combobox="t('scholiq', 'Material')" />
						<button
							type="button"
							class="button-vue"
							:disabled="pickingFile"
							@click="pickAndCreateMaterial(block)">
							<span v-if="pickingFile" class="icon-loading" aria-hidden="true" />
							{{ t('scholiq', 'Upload a new file…') }}
						</button>
					</div>

					<!-- quiz -->
					<div v-else-if="block.type === 'quiz'" class="lesson-composer__block-body">
						<NcSelect
							v-model="block.assessmentId"
							:options="assessmentOptions"
							:reduce="(opt) => opt.id"
							:input-label="t('scholiq', 'Assessment')"
							:aria-label-combobox="t('scholiq', 'Assessment')" />
					</div>

					<!-- assignment -->
					<div v-else-if="block.type === 'assignment'" class="lesson-composer__block-body">
						<NcSelect
							v-model="block.assignmentId"
							:options="assignmentOptions"
							:reduce="(opt) => opt.id"
							:input-label="t('scholiq', 'Assignment')"
							:aria-label-combobox="t('scholiq', 'Assignment')" />
					</div>

					<!-- ltiTool -->
					<div v-else-if="block.type === 'ltiTool'" class="lesson-composer__block-body">
						<NcSelect
							v-model="block.ltiToolPlacementId"
							:options="ltiToolPlacementOptions"
							:reduce="(opt) => opt.id"
							:input-label="t('scholiq', 'LTI tool placement')"
							:aria-label-combobox="t('scholiq', 'LTI tool placement')" />
					</div>
				</li>
			</draggable>

			<section class="lesson-composer__add-block">
				<label class="lesson-composer__field-label" for="lc-add-block-type">
					{{ t('scholiq', 'Block type') }}
				</label>
				<select id="lc-add-block-type" v-model="addBlockType" class="lesson-composer__select">
					<option value="richText">
						{{ t('scholiq', 'Rich text') }}
					</option>
					<option value="media">
						{{ t('scholiq', 'Media (image / video / file / SCORM-cmi5 reference)') }}
					</option>
					<option value="quiz">
						{{ t('scholiq', 'Quiz') }}
					</option>
					<option value="assignment">
						{{ t('scholiq', 'Assignment') }}
					</option>
					<option value="ltiTool">
						{{ t('scholiq', 'External tool (LTI)') }}
					</option>
				</select>
				<button type="button" class="button-vue button-vue--secondary" @click="addBlock">
					<PlusIcon :size="16" />
					{{ t('scholiq', 'Add block') }}
				</button>
			</section>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import draggable from 'vuedraggable'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

/**
 * Material.kind inferred from a picked file's MIME type (design.md D3 task 4.4).
 *
 * @param {string|null|undefined} mime The picked file's MIME type.
 * @return {string} A Material.kind enum value.
 */
function inferMaterialKind(mime) {
	if (!mime) return 'other'
	if (mime.startsWith('video/')) return 'video'
	if (mime === 'application/pdf' || mime.startsWith('text/')) return 'reading'
	if (mime === 'application/zip' || mime === 'application/x-zip-compressed') return 'scorm'
	if (mime.startsWith('image/') || mime.includes('presentation')) return 'slides'
	return 'document'
}

export default {
	name: 'LessonComposer',

	components: {
		NcSelect,
		CnMarkdownEditor,
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
		/** Lesson UUID injected by vue-router from the :lessonId route param. */
		lessonId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			lesson: null,
			/** @type {Array<object>} Local mirror of lesson.blocks, mutated in place until Save. */
			blocks: [],
			addBlockType: 'richText',
			saving: false,
			saveError: '',
			saveDone: false,
			pickingFile: false,
			liveMessage: '',
			/** @type {Array<object>} */
			materials: [],
			/** @type {Array<object>} */
			assessments: [],
			/** @type {Array<object>} */
			assignments: [],
			/** @type {Array<object>} */
			ltiToolPlacements: [],
		}
	},

	computed: {
		materialOptions() {
			return this.materials.map((m) => ({ id: m.id, label: m.title }))
		},
		assessmentOptions() {
			return this.assessments.map((a) => ({ id: a.id, label: a.title }))
		},
		assignmentOptions() {
			return this.assignments.map((a) => ({ id: a.id, label: a.title }))
		},
		ltiToolPlacementOptions() {
			return this.ltiToolPlacements.map((p) => ({ id: p.id, label: p.title || p.name || p.id }))
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the Lesson and every picker source scoped to its Course:
		 * existing Materials attached to this Lesson (media block picker),
		 * and Assessments/Assignments/LtiToolPlacements scoped to courseId
		 * (quiz/assignment/ltiTool block pickers).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.lesson = await this.fetchObject('Lesson', this.lessonId)
				this.blocks = (this.lesson.blocks || []).slice().sort((a, b) => (a.order ?? 0) - (b.order ?? 0))

				const [materials, assessments, assignments, ltiToolPlacements] = await Promise.all([
					this.fetchList('Material', `filters[lessonId]=${this.lessonId}&limit=200`),
					this.fetchList('Assessment', `filters[courseId]=${this.courseId}&limit=200`),
					this.fetchList('Assignment', `filters[courseId]=${this.courseId}&limit=200`),
					this.fetchList('LtiToolPlacement', `filters[courseId]=${this.courseId}&limit=200`),
				])
				this.materials = materials
				this.assessments = assessments
				this.assignments = assignments
				this.ltiToolPlacements = ltiToolPlacements
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load the lesson. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] load error', err)
			} finally {
				this.loading = false
			}
		},

		/** @return {void} */
		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'CourseBuilder', params: { courseId: this.courseId } }).catch(() => {})
			}
		},

		/** @return {void} */
		openPlayer() {
			if (this.$router) {
				this.$router.push({ name: 'LessonPlayer', params: { courseId: this.courseId, lessonId: this.lessonId } }).catch(() => {})
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
		 * Generate a client-side block identifier, preferring crypto.randomUUID
		 * where available (mirrors TakeAssessmentView.vue's generateId()).
		 *
		 * @return {string}
		 */
		generateBlockId() {
			if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
				return crypto.randomUUID()
			}
			return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
		},

		/**
		 * Human-readable label for a block type.
		 *
		 * @param {string} type Block type value.
		 * @return {string}
		 */
		blockTypeLabel(type) {
			const labels = {
				richText: this.t('scholiq', 'Rich text'),
				media: this.t('scholiq', 'Media'),
				quiz: this.t('scholiq', 'Quiz'),
				assignment: this.t('scholiq', 'Assignment'),
				ltiTool: this.t('scholiq', 'External tool'),
			}
			return labels[type] ?? type
		},

		/**
		 * Append a new block of the selected type at the end of the list.
		 *
		 * @return {void}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		addBlock() {
			this.blocks.push({
				blockId: this.generateBlockId(),
				type: this.addBlockType,
				order: this.blocks.length + 1,
				text: this.addBlockType === 'richText' ? '' : null,
				materialId: null,
				assessmentId: null,
				assignmentId: null,
				ltiToolPlacementId: null,
			})
		},

		/**
		 * Update a single field on a block in place (CnMarkdownEditor's
		 * `input` event, richText only).
		 *
		 * @param {object} block The block.
		 * @param {string} field Field name.
		 * @param {*} value New value.
		 * @return {void}
		 */
		onBlockFieldInput(block, field, value) {
			this.$set(block, field, value)
		},

		/**
		 * Remove a block from the list and renumber the remainder.
		 *
		 * @param {number} idx Index in `blocks`.
		 * @return {void}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		removeBlock(idx) {
			this.blocks.splice(idx, 1)
			this.renumberBlocks()
		},

		/**
		 * Recompute contiguous 1-based `order` for every block in the local
		 * `blocks` mirror — the single place both drag-and-drop and the
		 * keyboard move handlers converge on (design.md D4), mirroring
		 * CourseBuilder.vue's persistOrder(). Blocks are not individually
		 * persisted (unlike Course/Lesson rows) — the whole array is saved
		 * together via `save()` — so this only mutates local state.
		 *
		 * @return {void}
		 */
		renumberBlocks() {
			this.blocks.forEach((b, idx) => { b.order = idx + 1 })
		},

		/**
		 * Move `blocks[fromIndex]` to `toIndex`, renumber, and announce.
		 *
		 * @param {number} fromIndex Current index.
		 * @param {number} toIndex Target index.
		 * @return {void}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		reorderBlock(fromIndex, toIndex) {
			if (fromIndex === toIndex || toIndex < 0 || toIndex >= this.blocks.length) return
			const [moved] = this.blocks.splice(fromIndex, 1)
			this.blocks.splice(toIndex, 0, moved)
			this.renumberBlocks()
			this.liveMessage = this.t(
				'scholiq',
				'{type} block moved to position {pos} of {total}',
				{ type: this.blockTypeLabel(moved.type), pos: toIndex + 1, total: this.blocks.length },
			)
		},

		/** @param {number} idx Block index. @return {void} */
		moveBlockUp(idx) {
			this.reorderBlock(idx, idx - 1)
		},

		/** @param {number} idx Block index. @return {void} */
		moveBlockDown(idx) {
			this.reorderBlock(idx, idx + 1)
		},

		/**
		 * vuedraggable `@end` handler — the array is already reordered by
		 * `v-model`; just renumber + announce.
		 *
		 * @return {void}
		 */
		onBlocksDragEnd() {
			this.renumberBlocks()
			this.liveMessage = this.t('scholiq', 'Block order updated.')
		},

		/**
		 * Open the NC Files picker, then create a new Material for the
		 * picked file (fileRef = picked path, lessonId = current lesson,
		 * kind inferred from the file's MIME type) and store the resulting
		 * Material's UUID as this block's materialId. Never duplicates the
		 * Material's own fields onto the block (design.md D3).
		 *
		 * @param {object} block The media block being edited.
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-media-block-references-an-existing-material-rather-than-duplicating-file-metadata
		 */
		async pickAndCreateMaterial(block) {
			this.pickingFile = true
			try {
				const picker = getFilePickerBuilder(this.t('scholiq', 'Choose a file for this block'))
					.setMultiSelect(false)
					.build()
				const nodes = await picker.pickNodes()
				const node = Array.isArray(nodes) ? nodes[0] : nodes
				if (!node) return

				const created = await this.createObject('Material', {
					title: node.basename || node.path,
					kind: inferMaterialKind(node.mime),
					fileRef: node.path,
					lessonId: this.lessonId,
					tenant_id: this.lesson.tenant_id,
				})
				this.materials.push(created)
				block.materialId = created.id
			} catch (err) {
				// FilePickerClosed on cancel — not an error the user needs to see.
				if (err && err.constructor && err.constructor.name === 'FilePickerClosed') return
				this.error = this.t('scholiq', 'Failed to attach the picked file. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] pickAndCreateMaterial error', err)
			} finally {
				this.pickingFile = false
			}
		},

		/**
		 * Persist the full blocks array on the Lesson via OR's existing
		 * object-update endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async save() {
			this.saving = true
			this.saveError = ''
			this.saveDone = false
			this.renumberBlocks()

			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/Lesson/${this.lessonId}`)
				const resp = await fetch(url, {
					method: 'PUT',
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
					body: JSON.stringify({ blocks: this.blocks }),
				})
				if (!resp.ok) {
					throw new Error(`Lesson blocks save failed: ${resp.status}`)
				}
				this.lesson.blocks = this.blocks
				this.saveDone = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save the lesson. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] save error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.lesson-composer {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.lesson-composer__loading,
.lesson-composer__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.lesson-composer__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.lesson-composer__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: 8px;
}

.lesson-composer__header-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.lesson-composer__notice {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.lesson-composer__inline-error {
	color: var(--color-error);
	margin-bottom: 8px;
}

.lesson-composer__inline-success {
	color: var(--color-success);
	margin-bottom: 8px;
}

.lesson-composer__block-list {
	list-style: none;
	margin: 0 0 16px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.lesson-composer__block {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
}

.lesson-composer__block-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;
}

.lesson-composer__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.lesson-composer__block-type {
	font-weight: 600;
	flex: 1 1 auto;
}

.lesson-composer__icon-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 4px;
	display: inline-flex;
}

.lesson-composer__icon-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.lesson-composer__icon-btn:hover:not(:disabled) {
	color: var(--color-main-text);
}

.lesson-composer__block-body {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.lesson-composer__add-block {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.lesson-composer__field-label {
	font-weight: 500;
}

.lesson-composer__select {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
}
</style>
