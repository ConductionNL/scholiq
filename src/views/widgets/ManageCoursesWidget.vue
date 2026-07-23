<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Dashboard manage-courses widget: top-N courses with lifecycle status. -->
<template>
	<ManageListWidget
		schema="Course"
		:schema-label="t('scholiq', 'course')"
		:columns="['name', 'lifecycle']"
		index-route="/courses"
		:limit="6" />
</template>

<script>
import ManageListWidget from './ManageListWidget.vue'

export default {
	name: 'ManageCoursesWidget',
	components: { ManageListWidget },
}
</script>
