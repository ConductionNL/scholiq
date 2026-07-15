<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ScholiqDashboards — the single role-aware dashboard (ADR-009 §6).

 One component, one CnDashboardPage, that re-renders for the active role:
   - admin   → KPI overview + manage lists
   - teacher → instructor management lists (courses, assignments, sessions, cohorts)
   - student → the learner's own mandatory-training obligations

 The default view comes from the user's resolved role (initial state
 `dashboardRole`); users who can access more than one view (initial state
 `dashboardRoles`) get an in-page switcher. Replaces the old
 ScholiqDashboard.vue wrapper that nested a second CnDashboardPage inside a
 dashboard widget (the dashboard-in-dashboard antipattern).

 @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
-->
<template>
	<div class="scholiq-dashboards">
		<CnDashboardPage
			:key="activeRole"
			:title="pageTitle"
			:widgets="widgets"
			:layout="layout">
			<!-- Admin view -->
			<template #widget-kpi-courses>
				<KpiCoursesWidget />
			</template>
			<template #widget-kpi-cohorts>
				<KpiCohortsWidget />
			</template>
			<template #widget-kpi-learners>
				<KpiLearnersWidget />
			</template>
			<template #widget-kpi-active-enrolments>
				<KpiActiveEnrolmentsWidget />
			</template>
			<template #widget-kpi-open-flags>
				<KpiOpenFlagsWidget />
			</template>
			<template #widget-manage-courses>
				<ManageCoursesWidget />
			</template>
			<template #widget-manage-cohorts>
				<ManageCohortsWidget />
			</template>
			<template #widget-manage-programmes>
				<ManageProgrammesWidget />
			</template>

			<!-- Teacher view -->
			<template #widget-teacher-courses>
				<ManageListWidget
					schema="Course"
					:schema-label="t('scholiq', 'course')"
					:columns="['name', 'lifecycle', 'lessonCount']"
					index-route="/courses"
					:limit="6" />
			</template>
			<template #widget-teacher-assignments>
				<ManageListWidget
					schema="Assignment"
					:schema-label="t('scholiq', 'assignment')"
					:columns="['name', 'dueDate', 'lifecycle']"
					index-route="/assignments"
					:limit="6" />
			</template>
			<template #widget-teacher-sessions>
				<ManageListWidget
					schema="Session"
					:schema-label="t('scholiq', 'session')"
					:columns="['name', 'startsAt', 'lifecycle']"
					index-route="/sessions"
					:limit="6" />
			</template>
			<template #widget-teacher-cohorts>
				<ManageListWidget
					schema="Cohort"
					:schema-label="t('scholiq', 'cohort')"
					:columns="['name', 'learnerCount', 'programmeId']"
					index-route="/cohorts"
					:limit="6" />
			</template>
			<template #widget-kpi-engagement-score>
				<KpiEngagementScoreWidget />
			</template>
			<template #widget-kpi-engagement-flags>
				<KpiEngagementRiskFlagsWidget />
			</template>

			<!-- Student view -->
			<template #widget-my-mandatory-training>
				<MyMandatoryTrainingWidget />
			</template>
			<template #widget-kpi-points-level>
				<KpiPointsLevelWidget />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiCoursesWidget from './widgets/KpiCoursesWidget.vue'
import KpiCohortsWidget from './widgets/KpiCohortsWidget.vue'
import KpiLearnersWidget from './widgets/KpiLearnersWidget.vue'
import KpiActiveEnrolmentsWidget from './widgets/KpiActiveEnrolmentsWidget.vue'
import KpiOpenFlagsWidget from './widgets/KpiOpenFlagsWidget.vue'
import KpiEngagementScoreWidget from './widgets/KpiEngagementScoreWidget.vue'
import KpiEngagementRiskFlagsWidget from './widgets/KpiEngagementRiskFlagsWidget.vue'
import ManageCoursesWidget from './widgets/ManageCoursesWidget.vue'
import ManageCohortsWidget from './widgets/ManageCohortsWidget.vue'
import ManageProgrammesWidget from './widgets/ManageProgrammesWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'
import MyMandatoryTrainingWidget from './widgets/MyMandatoryTrainingWidget.vue'
import KpiPointsLevelWidget from './widgets/KpiPointsLevelWidget.vue'

const VALID_ROLES = ['admin', 'teacher', 'student']

export default {
	name: 'ScholiqDashboards',

	props: {
		/**
		 * Which dashboard view to render: 'admin' | 'teacher' | 'student'.
		 * Supplied by the thin per-role route wrapper (DashboardAdmin/Teacher/
		 * Student). Falls back to the user's resolved default view when empty.
		 */
		role: {
			type: String,
			default: '',
		},
	},

	components: {
		CnDashboardPage,
		KpiCoursesWidget,
		KpiCohortsWidget,
		KpiLearnersWidget,
		KpiActiveEnrolmentsWidget,
		KpiOpenFlagsWidget,
		KpiEngagementScoreWidget,
		KpiEngagementRiskFlagsWidget,
		ManageCoursesWidget,
		ManageCohortsWidget,
		ManageProgrammesWidget,
		ManageListWidget,
		MyMandatoryTrainingWidget,
		KpiPointsLevelWidget,
	},

	computed: {
		/**
		 * The active dashboard role — the `role` prop when valid, otherwise the
		 * user's resolved default view (initial state `dashboardRole`).
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		activeRole() {
			if (VALID_ROLES.includes(this.role)) {
				return this.role
			}
			const dflt = loadState('scholiq', 'dashboardRole', 'student')
			return VALID_ROLES.includes(dflt) ? dflt : 'student'
		},

		/**
		 * The dashboard page title for the active role view.
		 *
		 * @return {string}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		pageTitle() {
			return this.roleLabel(this.activeRole) + ' · ' + this.t('scholiq', 'Dashboard')
		},

		/**
		 * The CnDashboardPage `widgets` declaration for the active role.
		 *
		 * @return {Array<object>}
		 */
		widgets() {
			return this.viewConfig.widgets
		},

		/**
		 * The CnDashboardPage `layout` declaration for the active role.
		 *
		 * @return {Array<object>}
		 */
		layout() {
			return this.viewConfig.layout
		},

		/**
		 * Resolve the widgets + layout for the active role view.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/specs/dashboard/spec.md#requirement-per-role-group-gated-dashboard-menu-items
		 */
		viewConfig() {
			if (this.activeRole === 'admin') {
				return this.adminConfig
			}
			if (this.activeRole === 'teacher') {
				return this.teacherConfig
			}
			return this.studentConfig
		},

		/**
		 * Admin KPI + manage layout (the previous default dashboard).
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 */
		adminConfig() {
			return {
				widgets: [
					{ id: 'kpi-courses', title: this.t('scholiq', 'Courses'), type: 'custom' },
					{ id: 'kpi-cohorts', title: this.t('scholiq', 'Cohorts'), type: 'custom' },
					{ id: 'kpi-learners', title: this.t('scholiq', 'Learners'), type: 'custom' },
					{ id: 'kpi-active-enrolments', title: this.t('scholiq', 'Active enrolments'), type: 'custom' },
					{ id: 'kpi-open-flags', title: this.t('scholiq', 'Open attendance flags'), type: 'custom' },
					{ id: 'manage-courses', title: this.t('scholiq', 'Courses'), type: 'custom' },
					{ id: 'manage-cohorts', title: this.t('scholiq', 'Cohorts'), type: 'custom' },
					{ id: 'manage-programmes', title: this.t('scholiq', 'Programmes'), type: 'custom' },
				],
				layout: [
					{ id: 1, widgetId: 'kpi-courses', gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
					{ id: 2, widgetId: 'kpi-cohorts', gridX: 2, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
					{ id: 3, widgetId: 'kpi-learners', gridX: 4, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
					{ id: 4, widgetId: 'kpi-active-enrolments', gridX: 6, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
					{ id: 5, widgetId: 'kpi-open-flags', gridX: 8, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
					{ id: 6, widgetId: 'manage-courses', gridX: 0, gridY: 2, gridWidth: 4, gridHeight: 4 },
					{ id: 7, widgetId: 'manage-cohorts', gridX: 4, gridY: 2, gridWidth: 4, gridHeight: 4 },
					{ id: 8, widgetId: 'manage-programmes', gridX: 8, gridY: 2, gridWidth: 4, gridHeight: 4 },
				],
			}
		},

		/**
		 * Teacher management layout.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 */
		teacherConfig() {
			return {
				widgets: [
					// learning-progress-and-analytics: declarative KPI tiles
					// surfacing average EngagementScore.score and open
					// EngagementRiskFlag counts — no new chart component.
					{ id: 'kpi-engagement-score', title: this.t('scholiq', 'Avg. engagement score'), type: 'custom' },
					{ id: 'kpi-engagement-flags', title: this.t('scholiq', 'Open engagement flags'), type: 'custom' },
					{ id: 'teacher-courses', title: this.t('scholiq', 'My courses'), type: 'custom' },
					{ id: 'teacher-assignments', title: this.t('scholiq', 'Assignments to grade'), type: 'custom' },
					{ id: 'teacher-sessions', title: this.t('scholiq', 'Sessions to mark'), type: 'custom' },
					{ id: 'teacher-cohorts', title: this.t('scholiq', 'My cohorts'), type: 'custom' },
				],
				layout: [
					{ id: 1, widgetId: 'kpi-engagement-score', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2, showTitle: false },
					{ id: 2, widgetId: 'kpi-engagement-flags', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 2, showTitle: false },
					{ id: 3, widgetId: 'teacher-courses', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
					{ id: 4, widgetId: 'teacher-assignments', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
					{ id: 5, widgetId: 'teacher-sessions', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
					{ id: 6, widgetId: 'teacher-cohorts', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
				],
			}
		},

		/**
		 * Student layout — the learner's own mandatory-training obligations,
		 * plus (engagement-gamification) the learner's own points/level/streak
		 * KPI, visible unconditionally regardless of any Leaderboard/opt-out
		 * state. Deliberately limited to user-scoped widgets to avoid exposing
		 * other learners' records.
		 *
		 * @return {{widgets: Array<object>, layout: Array<object>}}
		 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
		 */
		studentConfig() {
			return {
				widgets: [
					{ id: 'my-mandatory-training', title: this.t('scholiq', 'My mandatory training'), type: 'custom' },
					// engagement-gamification: the learner's own points/level/streak KPI —
					// always visible regardless of any Leaderboard/opt-out state.
					{ id: 'kpi-points-level', title: this.t('scholiq', 'My points'), type: 'custom' },
				],
				layout: [
					{ id: 1, widgetId: 'my-mandatory-training', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 5 },
					{ id: 2, widgetId: 'kpi-points-level', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 2, showTitle: false },
				],
			}
		},
	},

	methods: {
		/**
		 * Localized human label for a dashboard role view.
		 *
		 * @param {string} role One of admin|teacher|student.
		 * @return {string}
		 */
		roleLabel(role) {
			if (role === 'admin') {
				return this.t('scholiq', 'Administrator')
			}
			if (role === 'teacher') {
				return this.t('scholiq', 'Teacher')
			}
			return this.t('scholiq', 'Student')
		},
	},
}
</script>

<style scoped>
.scholiq-dashboards__rolebar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px 0;
}

.scholiq-dashboards__rolebar-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.scholiq-dashboards__roleselect {
	min-width: 200px;
}
</style>
