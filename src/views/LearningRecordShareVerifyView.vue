<!--
  LearningRecordShareVerifyView.vue
  Custom page component for the LearningRecordShareVerifyView manifest page
  (type: custom).

  Public verification page for a LearningRecordShare's shared bundle —
  modeled directly on the existing CredentialVerify page's minimal,
  anonymous-verifier shape (a single-purpose proof page, no related-index,
  no comms, no audit sidebar). Calls the public
  GET /api/learning-record-shares/{id}/verify endpoint, never a
  session-authenticated OR object read.

  Uses Options API + direct fetch calls, same pattern as
  CoursePackageImportView.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
-->

<template>
	<div class="lrs-verify">
		<p v-if="loading" class="lrs-verify__loading">
			{{ t('scholiq', 'Verifying…') }}
		</p>

		<template v-else>
			<div v-if="valid" class="lrs-verify__result lrs-verify__result--valid" role="status">
				<h2 class="lrs-verify__heading">
					{{ t('scholiq', 'Verified learning record') }}
				</h2>
				<p class="lrs-verify__hint">
					{{ t('scholiq', 'This bundle was signed by the issuing school and has not been tampered with.') }}
				</p>
				<dl class="lrs-verify__bundle">
					<dt>{{ t('scholiq', 'Issuer') }}</dt>
					<dd>{{ bundle.issuerDid }}</dd>
					<dt>{{ t('scholiq', 'Generated at') }}</dt>
					<dd>{{ bundle.generatedAt }}</dd>
				</dl>
				<details class="lrs-verify__raw">
					<summary>{{ t('scholiq', 'Full record content') }}</summary>
					<pre>{{ prettyBundle }}</pre>
				</details>
			</div>

			<div v-else class="lrs-verify__result lrs-verify__result--denied" role="alert">
				<h2 class="lrs-verify__heading">
					{{ t('scholiq', 'This share could not be verified') }}
				</h2>
				<p>{{ deniedReasonLabel }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'LearningRecordShareVerifyView',

	data() {
		return {
			loading: true,
			valid: false,
			bundle: null,
			reason: null,
		}
	},

	computed: {
		/**
		 * The LearningRecordShare UUID from the route.
		 *
		 * @return {string}
		 */
		shareId() {
			return this.$route?.params?.id ?? ''
		},

		/**
		 * Pretty-printed bundle JSON for the disclosure panel.
		 *
		 * @return {string}
		 */
		prettyBundle() {
			return this.bundle ? JSON.stringify(this.bundle, null, 2) : ''
		},

		/**
		 * Human-readable label for the denial reason.
		 *
		 * @return {string}
		 */
		deniedReasonLabel() {
			const labels = {
				not_found: this.t('scholiq', 'This link does not exist.'),
				revoked: this.t('scholiq', 'This share has been revoked by the learner.'),
				expired: this.t('scholiq', 'This share has expired.'),
				export_not_found: this.t('scholiq', 'The underlying record could not be found.'),
				bundle_unreadable: this.t('scholiq', 'The underlying record could not be read.'),
				signature_invalid: this.t('scholiq', 'This record\'s signature could not be verified — it may have been altered.'),
			}
			return labels[this.reason] ?? this.t('scholiq', 'This link is no longer valid.')
		},
	},

	async mounted() {
		await this.verify()
	},

	methods: {
		/**
		 * Call the public verification endpoint and render its result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-an-expired-share-is-denied-even-though-its-lifecycle-is-still-active
		 */
		async verify() {
			this.loading = true

			try {
				const url = generateUrl(`/apps/scholiq/api/learning-record-shares/${this.shareId}/verify`)
				const resp = await fetch(url, {
					headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
				})

				const json = await resp.json()
				this.valid = json.valid === true
				this.bundle = json.bundle ?? null
				this.reason = json.reason ?? null
			} catch (err) {
				this.valid = false
				this.reason = null
				// eslint-disable-next-line no-console
				console.error('[LearningRecordShareVerifyView] verify error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lrs-verify {
	max-width: 720px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 8px) * 3) calc(var(--default-grid-baseline, 8px) * 2);
}

.lrs-verify__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.lrs-verify__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.lrs-verify__result {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
}

.lrs-verify__result--valid {
	border-left: 4px solid var(--color-success);
}

.lrs-verify__result--denied {
	border-left: 4px solid var(--color-error);
}

.lrs-verify__bundle {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.lrs-verify__bundle dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.lrs-verify__raw pre {
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 480px;
	overflow: auto;
	background: var(--color-background-dark, #f5f5f5);
	padding: var(--default-grid-baseline, 8px);
	border-radius: var(--border-radius, 4px);
}
</style>
