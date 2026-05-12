<!--
  SubmitWorkModal.vue
  Custom page component for the SubmitWorkModal manifest page (type: custom).

  Learner's upload + submit flow for an Assignment:
  1. Fetch the Assignment to show title, instructions, dueAt.
  2. Fetch or create a draft Submission for the current learner.
  3. Allow file attachment upload via OR's attachment API.
  4. Save draft (PATCH Submission with attachmentRefs).
  5. Submit (dispatch the `submit` lifecycle transition).

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/scholiq/Assignment/:assignmentId
    - GET  /api/objects/scholiq/Submission?assignmentId=:id&learnerIds=:userId
    - POST /api/objects/scholiq/Submission  (create draft)
    - PUT  /api/objects/scholiq/Submission/:id  (update draft)
    - POST /api/objects/scholiq/Submission/:id/transition/submit  (dispatch submit)
    - POST /api/objects/scholiq/Submission/:id/files  (attach files)

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="submit-work-modal">
		<!-- Loading -->
		<div v-if="loading" class="submit-work-modal__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading assignment...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="submit-work-modal__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Submitted confirmation -->
		<div v-else-if="submitted"
			class="submit-work-modal__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('scholiq', 'Work submitted!') }}</h2>
			<p v-if="isLate" class="submit-work-modal__late-notice">
				{{ t('scholiq', 'Your submission was received after the deadline and has been marked as late.') }}
			</p>
			<p v-else>
				{{ t('scholiq', 'Your submission has been received.') }}
			</p>
		</div>

		<!-- Main form -->
		<template v-else-if="assignment">
			<!-- Assignment info -->
			<header class="submit-work-modal__header">
				<h2 class="submit-work-modal__title">
					{{ assignment.title }}
				</h2>
				<p v-if="assignment.dueAt" class="submit-work-modal__due">
					{{ t('scholiq', 'Due: {date}', { date: formatDate(assignment.dueAt) }) }}
					<span v-if="isOverdue && assignment.allowLateSubmission" class="submit-work-modal__late-badge">
						{{ t('scholiq', 'Late submission — {penalty}% penalty applies', { penalty: assignment.latePenaltyPercent || 0 }) }}
					</span>
				</p>
				<!-- Instructions -->
				<div
					v-if="assignment.instructions"
					class="submit-work-modal__instructions"
					v-html="assignment.instructions" />
			</header>

			<!-- Attachment list -->
			<section class="submit-work-modal__files">
				<h3>{{ t('scholiq', 'Your files') }}</h3>

				<ul v-if="attachmentRefs.length > 0" class="submit-work-modal__file-list">
					<li
						v-for="(ref, idx) in attachmentRefs"
						:key="ref"
						class="submit-work-modal__file-item">
						<span class="icon-file" aria-hidden="true" />
						<span class="submit-work-modal__file-ref">{{ ref }}</span>
						<button
							class="submit-work-modal__file-remove"
							:disabled="submitting"
							:aria-label="t('scholiq', 'Remove file {ref}', { ref })"
							@click="removeAttachment(idx)">
							<span class="icon-close" aria-hidden="true" />
						</button>
					</li>
				</ul>
				<p v-else class="submit-work-modal__no-files">
					{{ t('scholiq', 'No files attached yet.') }}
				</p>

				<!-- File input -->
				<div class="submit-work-modal__upload">
					<label for="file-upload" class="submit-work-modal__upload-label">
						<span class="icon-upload" aria-hidden="true" />
						{{ t('scholiq', 'Attach file') }}
					</label>
					<input
						id="file-upload"
						ref="fileInput"
						type="file"
						multiple
						class="submit-work-modal__file-input"
						:disabled="submitting || uploadingFiles"
						@change="handleFileChange">
				</div>
				<div v-if="uploadingFiles" class="submit-work-modal__upload-progress" aria-live="polite">
					<span class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Uploading files...') }}
				</div>
				<div v-if="uploadError" role="alert" class="submit-work-modal__upload-error">
					{{ uploadError }}
				</div>
			</section>

			<!-- Action buttons -->
			<div class="submit-work-modal__actions">
				<button
					class="button-vue submit-work-modal__save-btn"
					:disabled="submitting || uploadingFiles"
					@click="saveDraft">
					<span v-if="savingDraft" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Save draft') }}
				</button>
				<button
					class="button-vue button-vue--primary submit-work-modal__submit-btn"
					:disabled="submitting || uploadingFiles || attachmentRefs.length === 0"
					@click="submitWork">
					<span v-if="submitting" class="icon-loading" aria-hidden="true" />
					{{ t('scholiq', 'Submit') }}
				</button>
			</div>
			<p v-if="submitError" role="alert" class="submit-work-modal__submit-error">
				{{ submitError }}
			</p>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'SubmitWorkModal',

	props: {
		/**
		 * Assignment UUID injected by vue-router from :assignmentId param.
		 */
		assignmentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			assignment: null,
			/** @type {string|null} */
			submissionId: null,
			/** @type {string[]} */
			attachmentRefs: [],
			loading: false,
			submitting: false,
			savingDraft: false,
			uploadingFiles: false,
			submitted: false,
			isLate: false,
			isOverdue: false,
			error: null,
			uploadError: null,
			submitError: null,
		}
	},

	watch: {
		assignmentId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load assignment and existing draft submission.
		 *
		 * @param {string} id Assignment UUID
		 * @return {Promise<void>}
		 */
		async loadData(id) {
			this.loading = true
			this.error = null

			try {
				await this.loadAssignment(id)
				await this.loadOrCreateDraft(id)
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load assignment. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SubmitWorkModal] loadData error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Assignment object from OR.
		 *
		 * @param {string} id Assignment UUID
		 * @return {Promise<void>}
		 */
		async loadAssignment(id) {
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/Assignment/${id}`)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`Assignment fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.assignment = json.object ?? json ?? {}

			if (this.assignment.dueAt) {
				this.isOverdue = new Date() > new Date(this.assignment.dueAt)
			}
		},

		/**
		 * Load existing draft Submission or create a new one.
		 *
		 * @param {string} assignmentId Assignment UUID
		 * @return {Promise<void>}
		 */
		async loadOrCreateDraft(assignmentId) {
			const currentUser = getCurrentUser()
			const userId = currentUser ? currentUser.uid : null

			if (!userId) {
				return
			}

			const listUrl = generateUrl(
				`/apps/openregister/api/objects/scholiq/Submission?assignmentId=${assignmentId}&lifecycle=draft`,
			)
			const listResp = await fetch(listUrl, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})

			if (listResp.ok) {
				const listJson = await listResp.json()
				const results = listJson.results ?? listJson.objects ?? []
				// Find a draft belonging to the current user
				const myDraft = results.find((s) => {
					const ids = s.learnerIds ?? []
					return ids.includes(userId)
				})

				if (myDraft) {
					this.submissionId = myDraft.uuid ?? myDraft.id
					this.attachmentRefs = myDraft.attachmentRefs ?? []
					return
				}
			}

			// Create a new draft
			const createUrl = generateUrl('/apps/openregister/api/objects/scholiq/Submission')
			const createResp = await fetch(createUrl, {
				method: 'POST',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					assignmentId,
					learnerIds: [userId],
					attachmentRefs: [],
					lifecycle: 'draft',
					tenant_id: this.assignment.tenant_id ?? '',
				}),
			})

			if (!createResp.ok) {
				throw new Error(`Draft create failed: ${createResp.status}`)
			}
			const createJson = await createResp.json()
			const created = createJson.object ?? createJson ?? {}
			this.submissionId = created.uuid ?? created.id
			this.attachmentRefs = []
		},

		/**
		 * Upload selected files to OR's attachment API and collect refs.
		 *
		 * @param {Event} event File input change event
		 * @return {Promise<void>}
		 */
		async handleFileChange(event) {
			const files = Array.from(event.target.files ?? [])
			if (files.length === 0) {
				return
			}

			this.uploadingFiles = true
			this.uploadError = null

			try {
				for (const file of files) {
					const formData = new FormData()
					formData.append('file', file)

					const url = generateUrl(
						`/apps/openregister/api/objects/scholiq/Submission/${this.submissionId}/files`,
					)
					const resp = await fetch(url, {
						method: 'POST',
						headers: { 'OCS-APIREQUEST': 'true' },
						body: formData,
					})

					if (!resp.ok) {
						throw new Error(`File upload failed: ${resp.status}`)
					}

					const json = await resp.json()
					const ref = json.ref ?? json.fileRef ?? json.path ?? file.name
					this.attachmentRefs.push(ref)
				}
				// Clear input to allow re-selection
				if (this.$refs.fileInput) {
					this.$refs.fileInput.value = ''
				}
			} catch (err) {
				this.uploadError = this.t('scholiq', 'File upload failed. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SubmitWorkModal] file upload error', err)
			} finally {
				this.uploadingFiles = false
			}
		},

		/**
		 * Remove an attachment ref by index (does not delete from OR storage).
		 *
		 * @param {number} idx Index in attachmentRefs array
		 * @return {void}
		 */
		removeAttachment(idx) {
			this.attachmentRefs.splice(idx, 1)
		},

		/**
		 * Save the current attachmentRefs to the draft Submission (PATCH).
		 *
		 * @return {Promise<void>}
		 */
		async saveDraft() {
			if (!this.submissionId) {
				return
			}
			this.savingDraft = true
			this.submitError = null

			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/scholiq/Submission/${this.submissionId}`,
				)
				const resp = await fetch(url, {
					method: 'PUT',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ attachmentRefs: this.attachmentRefs }),
				})
				if (!resp.ok) {
					throw new Error(`Draft save failed: ${resp.status}`)
				}
			} catch (err) {
				this.submitError = this.t('scholiq', 'Failed to save draft. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SubmitWorkModal] saveDraft error', err)
			} finally {
				this.savingDraft = false
			}
		},

		/**
		 * Dispatch the `submit` lifecycle transition on the Submission.
		 * SubmissionWindowGuard on the OR side handles late/block logic.
		 *
		 * @return {Promise<void>}
		 */
		async submitWork() {
			if (!this.submissionId) {
				return
			}
			this.submitting = true
			this.submitError = null

			try {
				// Persist latest attachment refs before submitting
				await this.saveDraft()

				const url = generateUrl(
					`/apps/openregister/api/objects/scholiq/Submission/${this.submissionId}/transition/submit`,
				)
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})

				if (resp.status === 422) {
					// Guard blocked — deadline passed, late not allowed
					this.submitError = this.t(
						'scholiq',
						'The submission deadline has passed and late submissions are not accepted.',
					)
					return
				}

				if (!resp.ok) {
					throw new Error(`Submit transition failed: ${resp.status}`)
				}

				const json = await resp.json()
				const result = json.object ?? json ?? {}
				this.isLate = result.lifecycle === 'late'
				this.submitted = true
			} catch (err) {
				this.submitError = this.t('scholiq', 'Submission failed. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SubmitWorkModal] submitWork error', err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Format a datetime string for display.
		 *
		 * @param {string} dt ISO datetime string
		 * @return {string} Localised date+time
		 */
		formatDate(dt) {
			if (!dt) {
				return ''
			}
			try {
				return new Intl.DateTimeFormat(navigator.language, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				}).format(new Date(dt))
			} catch {
				return dt
			}
		},
	},
}
</script>

<style scoped>
.submit-work-modal {
	max-width: 720px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.submit-work-modal__loading,
.submit-work-modal__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.submit-work-modal__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.submit-work-modal__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.submit-work-modal__due {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.submit-work-modal__late-badge {
	display: inline-block;
	margin-left: var(--default-grid-baseline, 8px);
	padding: 2px 6px;
	background-color: var(--color-warning);
	color: var(--color-main-background);
	border-radius: 3px;
	font-size: 0.85em;
}

.submit-work-modal__instructions {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-background-hover);
	border-left: 3px solid var(--color-primary);
}

.submit-work-modal__files {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.submit-work-modal__file-list {
	list-style: none;
	padding: 0;
	margin: var(--default-grid-baseline, 8px) 0;
}

.submit-work-modal__file-item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.submit-work-modal__file-ref {
	flex: 1;
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.submit-work-modal__file-remove {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 2px;
}

.submit-work-modal__file-remove:hover {
	color: var(--color-error);
}

.submit-work-modal__no-files {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.submit-work-modal__upload {
	margin-top: var(--default-grid-baseline, 8px);
}

.submit-work-modal__upload-label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
	color: var(--color-primary);
}

.submit-work-modal__file-input {
	position: absolute;
	width: 1px;
	height: 1px;
	opacity: 0;
	overflow: hidden;
}

.submit-work-modal__upload-progress,
.submit-work-modal__upload-error,
.submit-work-modal__submit-error {
	margin-top: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
}

.submit-work-modal__upload-error,
.submit-work-modal__submit-error {
	color: var(--color-error);
}

.submit-work-modal__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.submit-work-modal__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}

.submit-work-modal__late-notice {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-warning);
}
</style>
