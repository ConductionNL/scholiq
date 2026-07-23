<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DataExchangeSettingsSection — admin-settings entry point for data exchange.

 The data-exchange subsystem (OSO/DUO-BRON/RIO aanleveringen via OpenConnector,
 ADR-009 §3/§4) keeps its full backend and its in-app management pages; only the
 in-app left-nav entry was removed. This section, rendered on the Nextcloud
 Admin Settings page (AdminRoot.vue), is now the discoverable entry point: it
 deep-links into the still-routable SPA pages. The Admin Settings mount has no
 in-app vue-router, so links use a full navigation to the app's hash routes
 (mirrors ScholiqSettings.vue's "Manage AI features" affordance).

 @spec openspec/changes/relocate-dataexchange-remove-assistant/specs/data-exchange/spec.md#requirement-data-exchange-management-is-reached-from-the-admin-settings-page
-->
<template>
	<NcSettingsSection
		:name="t('scholiq', 'Data exchange')"
		:description="t('scholiq', 'Manage the aanleveringen and imports scholiq exchanges with DUO/BRON, OSO, the municipality, and ELO systems. Jobs and field-mapping profiles are administered here.')">
		<div class="scholiq-dataexchange-settings__actions">
			<NcButton type="secondary" @click="open('/data-exchange/jobs')">
				<template #icon>
					<SwapHorizontal :size="20" />
				</template>
				{{ t('scholiq', 'Data-exchange jobs') }}
			</NcButton>
			<NcButton type="secondary" @click="open('/data-exchange/mapping-profiles')">
				<template #icon>
					<MapIcon :size="20" />
				</template>
				{{ t('scholiq', 'Mapping profiles') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSettingsSection } from '@nextcloud/vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import MapIcon from 'vue-material-design-icons/Map.vue'

export default {
	name: 'DataExchangeSettingsSection',

	components: {
		NcButton,
		NcSettingsSection,
		SwapHorizontal,
		MapIcon,
	},

	methods: {
		/**
		 * Open a data-exchange SPA page. The Admin Settings mount has no in-app
		 * router, so navigate the browser to the app's history-mode route path
		 * (mirrors ScholiqSettings.vue's "Manage AI features" affordance).
		 *
		 * @param {string} routePath The app route path, e.g. `/data-exchange/jobs`.
		 * @return {void}
		 */
		open(routePath) {
			window.location.href = generateUrl('/apps/scholiq') + routePath
		},
	},
}
</script>

<style scoped>
.scholiq-dataexchange-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
