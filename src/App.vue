<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Scholiq app shell. Mounts CnAppRoot with the bundled manifest and the
 customComponents registry. CnAppRoot reads manifest.dependencies and
 renders a dependency-missing empty state for absent apps automatically
 (per ADR-024) — no app-local OpenRegisterGuard is needed.

 The #user-settings slot feeds ScholiqSettings into CnAppRoot's hosted
 NcAppSettingsDialog, which CnAppNav opens when the user clicks the
 manifest menu entry with action: "user-settings".
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:registry="registry"
		:page-types="pageTypes"
		app-id="scholiq"
		:translate="translateForApp">
		<template #user-settings>
			<ScholiqSettings :in-dialog="true" />
		</template>
	</CnAppRoot>
</template>

<script>
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import ScholiqSettings from './views/ScholiqSettings.vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		ScholiqSettings,
	},

	props: {
		/**
		 * Bundled manifest — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by `type: "custom"` pages.
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * 5-kind component registry (v2 manifest pattern per hydra ADR-036).
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	methods: {
		/**
		 * Translate function passed to CnAppRoot. Closes over the Nextcloud
		 * `translate` import so the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('scholiq', key)
		},
	},
}
</script>
