<!--
  ItemAuthorView.vue
  Custom page component for the ItemAuthorView manifest page (type: custom).

  In-app QTI item editor supporting `choice` and `extendedText` interaction types.
  Other interaction types show an "editor coming soon" notice and suggest importing
  via QTI package.

  Features:
  - Edit title, interactionType, maxScore.
  - For `choice`: add/remove answer options; mark correct answer.
  - For `extendedText`: configure prompt only (no correctResponse — teacher scores).
  - Writes `qtiBody` (simplified QTI 3.0 XML) and `correctResponse` to the Item.
  - Loads existing Item data if a UUID is in the route.

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
-->

<template>
	<div class="item-author">
		<!-- Loading -->
		<div v-if="loading"
			class="item-author__loading"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading item...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="item-author__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Saved -->
		<div v-else-if="saved"
			class="item-author__saved"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('scholiq', 'Item saved successfully.') }}</p>
		</div>

		<template v-else>
			<header class="item-author__header">
				<h2 class="item-author__heading">
					{{ id ? t('scholiq', 'Edit item') : t('scholiq', 'New item') }}
				</h2>
			</header>

			<!-- Title -->
			<div class="item-author__field">
				<label class="item-author__label" for="item-title">
					{{ t('scholiq', 'Item title') }}
				</label>
				<input
					id="item-title"
					v-model="form.title"
					class="item-author__input"
					type="text"
					:placeholder="t('scholiq', 'Enter item title...')">
			</div>

			<!-- Interaction type -->
			<div class="item-author__field">
				<label class="item-author__label" for="item-type">
					{{ t('scholiq', 'Interaction type') }}
				</label>
				<select id="item-type" v-model="form.interactionType" class="item-author__select">
					<option value="choice">
						{{ t('scholiq', 'Multiple choice') }}
					</option>
					<option value="extendedText">
						{{ t('scholiq', 'Essay (extended text)') }}
					</option>
					<option disabled value="textEntry">
						{{ t('scholiq', 'Text entry (editor coming soon — use QTI import)') }}
					</option>
					<option disabled value="hotspot">
						{{ t('scholiq', 'Hotspot (editor coming soon — use QTI import)') }}
					</option>
					<option disabled value="order">
						{{ t('scholiq', 'Order (editor coming soon — use QTI import)') }}
					</option>
					<option disabled value="match">
						{{ t('scholiq', 'Match (editor coming soon — use QTI import)') }}
					</option>
					<option disabled value="gapMatch">
						{{ t('scholiq', 'Gap match (editor coming soon — use QTI import)') }}
					</option>
					<option disabled value="inlineChoice">
						{{ t('scholiq', 'Inline choice (editor coming soon — use QTI import)') }}
					</option>
				</select>
			</div>

			<!-- Max score -->
			<div class="item-author__field">
				<label class="item-author__label" for="item-max-score">
					{{ t('scholiq', 'Max score') }}
				</label>
				<input
					id="item-max-score"
					v-model.number="form.maxScore"
					class="item-author__input item-author__input--narrow"
					type="number"
					min="0"
					step="0.5">
			</div>

			<!-- Prompt / stem -->
			<div class="item-author__field">
				<label class="item-author__label" for="item-prompt">
					{{ t('scholiq', 'Question prompt / stem') }}
				</label>
				<textarea
					id="item-prompt"
					v-model="form.prompt"
					class="item-author__textarea"
					rows="4"
					:placeholder="t('scholiq', 'Enter the question text...')" />
			</div>

			<!-- Choice-specific: answer options -->
			<div v-if="form.interactionType === 'choice'" class="item-author__choices">
				<h3 class="item-author__sub-heading">
					{{ t('scholiq', 'Answer options') }}
				</h3>
				<ul class="item-author__choice-list">
					<li
						v-for="(choice, idx) in form.choices"
						:key="idx"
						class="item-author__choice-item">
						<input
							type="radio"
							:name="'correct-choice'"
							:checked="form.correctChoiceIdx === idx"
							:aria-label="t('scholiq', 'Mark as correct answer')"
							@change="form.correctChoiceIdx = idx">
						<input
							v-model="choice.label"
							class="item-author__input"
							type="text"
							:placeholder="t('scholiq', 'Option {n}', { n: idx + 1 })">
						<button
							class="item-author__remove-btn"
							:disabled="form.choices.length <= 2"
							:aria-label="t('scholiq', 'Remove option')"
							@click="removeChoice(idx)">
							<span class="icon-close" aria-hidden="true" />
						</button>
					</li>
				</ul>
				<button class="button-vue" @click="addChoice">
					{{ t('scholiq', 'Add option') }}
				</button>
				<p class="item-author__hint">
					{{ t('scholiq', 'Click the radio button to mark the correct answer.') }}
				</p>
			</div>

			<!-- extendedText note -->
			<div v-else-if="form.interactionType === 'extendedText'" class="item-author__essay-note">
				<span class="icon-info" aria-hidden="true" />
				<p>{{ t('scholiq', 'Essay items have no automatic correct response — teachers score these manually. This item will require manual scoring before the AssessmentResult can be graded.') }}</p>
			</div>

			<!-- Save button -->
			<div class="item-author__actions">
				<button
					class="button-vue button-vue--primary"
					:disabled="saving || !form.title"
					@click="saveItem">
					<span v-if="saving" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Save item') }}
				</button>
			</div>
			<p v-if="saveError" role="alert" class="item-author__error-inline">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ItemAuthorView',

	props: {
		/**
		 * Item UUID from :id route param (null when creating a new item).
		 */
		id: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			saving: false,
			saved: false,
			error: null,
			saveError: null,
			/** @type {string|null} */
			itemBankId: null,
			form: {
				title: '',
				interactionType: 'choice',
				maxScore: 1,
				prompt: '',
				choices: [
					{ id: 'A', label: '' },
					{ id: 'B', label: '' },
					{ id: 'C', label: '' },
					{ id: 'D', label: '' },
				],
				correctChoiceIdx: 0,
			},
		}
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the route id prop changing by loading the item.
			 *
			 * @param {string} newId New item UUID
			 * @return {void}
			 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
			 */
			handler(newId) {
				if (newId) {
					this.loadItem(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load an existing Item from OR.
		 *
		 * @param {string} itemId Item UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async loadItem(itemId) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/Item/${itemId}`)
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})
				if (!resp.ok) throw new Error(`Item fetch failed: ${resp.status}`)

				const json = await resp.json()
				const item = json.object ?? json ?? {}

				this.form.title = item.title ?? ''
				this.form.interactionType = item.interactionType ?? 'choice'
				this.form.maxScore = item.maxScore ?? 1
				this.itemBankId = item.itemBankId ?? null

				// Extract prompt from qtiBody (strip XML).
				this.form.prompt = (item.qtiBody ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

				// Restore choices for choice interaction.
				if (item.interactionType === 'choice' && item.qtiBody) {
					const parser = new DOMParser()
					const doc = parser.parseFromString(item.qtiBody, 'text/xml')
					const simpleChoices = doc.getElementsByTagName('simpleChoice')
					if (simpleChoices.length > 0) {
						this.form.choices = Array.from(simpleChoices).map((sc, i) => ({
							id: sc.getAttribute('identifier') ?? String.fromCharCode(65 + i),
							label: sc.textContent?.trim() ?? '',
						}))
					}

					const cr = item.correctResponse
					if (typeof cr === 'string') {
						const idx = this.form.choices.findIndex((c) => c.id === cr)
						this.form.correctChoiceIdx = idx >= 0 ? idx : 0
					}
				}
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load item. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[ItemAuthorView] loadItem error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add a new blank choice option.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		addChoice() {
			const nextId = String.fromCharCode(65 + this.form.choices.length)
			this.form.choices.push({ id: nextId, label: '' })
		},

		/**
		 * Remove a choice option by index.
		 *
		 * @param {number} idx Index to remove
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		removeChoice(idx) {
			if (this.form.choices.length <= 2) return
			this.form.choices.splice(idx, 1)
			if (this.form.correctChoiceIdx >= this.form.choices.length) {
				this.form.correctChoiceIdx = 0
			}
		},

		/**
		 * Build the QTI 3.0 item XML body from the form values.
		 *
		 * @return {string} QTI 3.0 XML string
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		buildQtiBody() {
			const { interactionType, prompt, choices, correctChoiceIdx } = this.form
			const identifier = `item-${Date.now()}`

			if (interactionType === 'choice') {
				const optionXml = choices
					.map((c) => `<simpleChoice identifier="${c.id}">${this.escapeXml(c.label)}</simpleChoice>`)
					.join('\n      ')

				return `<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0"
    identifier="${identifier}"
    title="${this.escapeXml(this.form.title)}"
    adaptive="false"
    timeDependent="false">
  <responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier">
    <correctResponse>
      <value>${this.escapeXml(choices[correctChoiceIdx]?.id ?? 'A')}</value>
    </correctResponse>
  </responseDeclaration>
  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue><value>${this.form.maxScore}</value></defaultValue>
  </outcomeDeclaration>
  <itemBody>
    <p>${this.escapeXml(prompt)}</p>
    <choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1">
      ${optionXml}
    </choiceInteraction>
  </itemBody>
</assessmentItem>`
			}

			// extendedText and other types.
			return `<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0"
    identifier="${identifier}"
    title="${this.escapeXml(this.form.title)}"
    adaptive="false"
    timeDependent="false">
  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue><value>${this.form.maxScore}</value></defaultValue>
  </outcomeDeclaration>
  <itemBody>
    <p>${this.escapeXml(prompt)}</p>
    <extendedTextInteraction responseIdentifier="RESPONSE" expectedLength="500" />
  </itemBody>
</assessmentItem>`
		},

		/**
		 * Escape XML special characters.
		 *
		 * @param {string} str Raw string
		 * @return {string} XML-escaped string
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		escapeXml(str) {
			return (str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
		},

		/**
		 * Save the item to OR (POST for new, PUT for existing).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async saveItem() {
			this.saving = true
			this.saveError = null
			this.saved = false

			const qtiBody = this.buildQtiBody()
			const correctResponse = this.form.interactionType === 'choice'
				? (this.form.choices[this.form.correctChoiceIdx]?.id ?? null)
				: null

			const payload = {
				title: this.form.title,
				interactionType: this.form.interactionType,
				qtiBody,
				correctResponse,
				maxScore: this.form.maxScore,
				lifecycle: 'draft',
			}

			if (this.itemBankId) {
				payload.itemBankId = this.itemBankId
			}

			const isEdit = Boolean(this.id)
			const url = isEdit
				? generateUrl(`/apps/openregister/api/objects/scholiq/Item/${this.id}`)
				: generateUrl('/apps/openregister/api/objects/scholiq/Item')

			try {
				const resp = await fetch(url, {
					method: isEdit ? 'PUT' : 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(payload),
				})
				if (!resp.ok) throw new Error(`Save failed: ${resp.status}`)
				this.saved = true
			} catch (err) {
				this.saveError = this.t('scholiq', 'Failed to save item. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[ItemAuthorView] saveItem error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.item-author {
	max-width: 720px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.item-author__loading,
.item-author__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-author__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-author__field {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-author__label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.item-author__input,
.item-author__select,
.item-author__textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
}

.item-author__input--narrow {
	width: 120px;
}

.item-author__textarea {
	resize: vertical;
}

.item-author__sub-heading {
	font-weight: 600;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.item-author__choice-list {
	list-style: none;
	padding: 0;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.item-author__choice-item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: 4px;
}

.item-author__remove-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 2px;
}

.item-author__remove-btn:hover {
	color: var(--color-error);
}

.item-author__hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.item-author__essay-note {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.item-author__actions {
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.item-author__error-inline {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-error);
	font-size: 0.9em;
}

.item-author__saved {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	color: var(--color-success);
}
</style>
