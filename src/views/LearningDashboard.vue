<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearningDashboard — the landing page for the Learning group (ADR-009 v2).

 The Learning group is both navigable (this dashboard) and collapsible (its
 six leaves render as nav sub-children). This component is the group's landing
 page: a single CnDashboardPage giving the learning domain (courses,
 curriculum, learning plans, assignments, assessments, grades) real
 information value — a KPI tile plus a manage-list per sub-area — rather than
 the former tile-grid navigational aid (supersedes ADR-044 cards-collapse).

 One component, exactly one CnDashboardPage: never referenced as a widget slot
 on another dashboard (avoids the dashboard-in-dashboard antipattern).

 @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-learning-domain-dashboard
-->
<template>
	<div class="scholiq-domain-dashboard">
		<CnDashboardPage
			:title="pageTitle"
			:widgets="widgets"
			:layout="layout">
			<template #widget-kpi-courses>
				<KpiCoursesWidget />
			</template>
			<template #widget-manage-courses>
				<ManageListWidget
					schema="Course"
					:schema-label="t('scholiq', 'course')"
					:columns="['name', 'lifecycle', 'lessonCount']"
					index-route="/courses"
					:limit="6" />
			</template>
			<template #widget-manage-curriculum>
				<ManageListWidget
					schema="Programme"
					:schema-label="t('scholiq', 'programme')"
					:columns="['name', 'lifecycle']"
					index-route="/curriculum/programmes"
					:limit="6" />
			</template>
			<template #widget-manage-assignments>
				<ManageListWidget
					schema="Assignment"
					:schema-label="t('scholiq', 'assignment')"
					:columns="['name', 'dueDate', 'lifecycle']"
					index-route="/assignments"
					:limit="6" />
			</template>
			<template #widget-manage-assessments>
				<ManageListWidget
					schema="Assessment"
					:schema-label="t('scholiq', 'assessment')"
					:columns="['name', 'lifecycle']"
					index-route="/assessments"
					:limit="6" />
			</template>
			<template #widget-manage-learning-plans>
				<ManageListWidget
					schema="learning-plan"
					:schema-label="t('scholiq', 'learning plan')"
					:columns="['name', 'lifecycle']"
					index-route="/learning-plans"
					:limit="6" />
			</template>
			<template #widget-manage-grades>
				<ManageListWidget
					schema="grade-entry"
					:schema-label="t('scholiq', 'grade')"
					:columns="['name', 'lifecycle']"
					index-route="/grades/entries"
					:limit="6" />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiCoursesWidget from './widgets/KpiCoursesWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'

export default {
	name: 'LearningDashboard',

	components: {
		CnDashboardPage,
		KpiCoursesWidget,
		ManageListWidget,
	},

	computed: {
		/**
		 * The dashboard page title.
		 *
		 * @return {string}
		 */
		pageTitle() {
			return this.t('scholiq', 'Learning')
		},

		/**
		 * The CnDashboardPage `widgets` declaration.
		 *
		 * @return {Array<object>}
		 */
		widgets() {
			return [
				{ id: 'kpi-courses', title: this.t('scholiq', 'Courses'), type: 'custom' },
				{ id: 'manage-courses', title: this.t('scholiq', 'Courses'), type: 'custom' },
				{ id: 'manage-curriculum', title: this.t('scholiq', 'Curriculum'), type: 'custom' },
				{ id: 'manage-assignments', title: this.t('scholiq', 'Assignments'), type: 'custom' },
				{ id: 'manage-assessments', title: this.t('scholiq', 'Assessments'), type: 'custom' },
				{ id: 'manage-learning-plans', title: this.t('scholiq', 'Learning plans'), type: 'custom' },
				{ id: 'manage-grades', title: this.t('scholiq', 'Grades'), type: 'custom' },
			]
		},

		/**
		 * The CnDashboardPage `layout` declaration (12-column grid).
		 *
		 * @return {Array<object>}
		 */
		layout() {
			return [
				{ id: 1, widgetId: 'kpi-courses', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'manage-courses', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 3, widgetId: 'manage-curriculum', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 4, widgetId: 'manage-assignments', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
				{ id: 5, widgetId: 'manage-assessments', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
				{ id: 6, widgetId: 'manage-learning-plans', gridX: 0, gridY: 10, gridWidth: 6, gridHeight: 4 },
				{ id: 7, widgetId: 'manage-grades', gridX: 6, gridY: 10, gridWidth: 6, gridHeight: 4 },
			]
		},
	},
}
</script>
