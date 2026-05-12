<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Dashboard manage-programmes widget: top-N programmes with courseCount + lifecycle. -->
<template>
	<ManageListWidget
		schema="Programme"
		:schema-label="t('scholiq', 'programme')"
		:columns="['name', 'lifecycle', 'courseCount']"
		index-route="/curriculum/programmes"
		:limit="6" />
</template>

<script>
import ManageListWidget from './ManageListWidget.vue'

export default {
	name: 'ManageProgrammesWidget',
	components: { ManageListWidget },
}
</script>
