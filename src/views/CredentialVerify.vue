<template>
	<div class="credential-verify">
		<div v-if="loading" class="credential-verify__loading">
			{{ t('scholiq', 'Verifying credential…') }}
		</div>

		<div v-else-if="error" class="credential-verify__error">
			<NcEmptyContent :name="t('scholiq', 'Credential not found')"
				:description="t('scholiq', 'The credential you are looking for does not exist or has been removed.')">
				<template #icon>
					<AlertCircleOutline />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="credential-verify__card">
			<div :class="['credential-verify__badge', result.valid ? 'credential-verify__badge--valid' : 'credential-verify__badge--invalid']">
				<CheckCircleOutline v-if="result.valid" class="credential-verify__badge-icon" />
				<CloseCircleOutline v-else class="credential-verify__badge-icon" />
				<span class="credential-verify__badge-label">
					{{ result.valid ? t('scholiq', 'Valid credential') : t('scholiq', 'Invalid credential') }}
				</span>
			</div>

			<dl class="credential-verify__meta">
				<template v-if="result.issuerName">
					<dt>{{ t('scholiq', 'Issued by') }}</dt>
					<dd>{{ result.issuerName }}</dd>
				</template>
				<template v-if="result.issuedAt">
					<dt>{{ t('scholiq', 'Issued on') }}</dt>
					<dd>{{ formatDate(result.issuedAt) }}</dd>
				</template>
				<template v-if="result.expiresAt">
					<dt>{{ t('scholiq', 'Expires on') }}</dt>
					<dd>{{ formatDate(result.expiresAt) }}</dd>
				</template>
				<template v-if="result.revocationReason">
					<dt>{{ t('scholiq', 'Revocation reason') }}</dt>
					<dd>{{ result.revocationReason }}</dd>
				</template>
			</dl>

			<div class="credential-verify__qr">
				<img :src="qrUrl" :alt="t('scholiq', 'QR code linking to this verification page')" />
				<p class="credential-verify__qr-url">{{ verificationUrl }}</p>
			</div>
		</div>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'

export default {
	name: 'CredentialVerify',
	components: { NcEmptyContent, AlertCircleOutline, CheckCircleOutline, CloseCircleOutline },
	props: {
		/** Credential UUID injected by CnAppRoot from the route :id param. */
		id: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			error: false,
			result: null,
		}
	},
	computed: {
		verificationUrl() {
			return window.location.href
		},
		qrUrl() {
			// Use a public QR code API; no credentials or personal data in the URL.
			return `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(this.verificationUrl)}`
		},
	},
	async mounted() {
		await this.verify()
	},
	methods: {
		async verify() {
			this.loading = true
			this.error = false
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/scholiq/api/credentials/${this.id}/verify`),
					{ method: 'GET', headers: { Accept: 'application/json' } },
				)
				if (response.status === 404) {
					this.error = true
					return
				}
				this.result = await response.json()
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},
		formatDate(isoString) {
			if (!isoString) return ''
			return new Date(isoString).toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.credential-verify {
	max-width: 480px;
	margin: 40px auto;
	padding: 0 16px;
}

.credential-verify__badge {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 24px;
}

.credential-verify__badge--valid {
	background-color: var(--color-success-bg, #d9f0dd);
	color: var(--color-success, #2d7d46);
}

.credential-verify__badge--invalid {
	background-color: var(--color-error-bg, #fde8e8);
	color: var(--color-error, #c0392b);
}

.credential-verify__badge-icon {
	width: 24px;
	height: 24px;
}

.credential-verify__badge-label {
	font-weight: 600;
	font-size: 1.1rem;
}

.credential-verify__meta {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 16px;
	margin-bottom: 24px;
}

.credential-verify__meta dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.credential-verify__qr {
	text-align: center;
}

.credential-verify__qr-url {
	font-size: 0.75rem;
	color: var(--color-text-lighter);
	word-break: break-all;
	margin-top: 8px;
}
</style>
