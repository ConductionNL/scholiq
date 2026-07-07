<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PeopleDashboard — the landing page for the People group (ADR-009 v2).

 The People group is both navigable (this dashboard) and collapsible (its four
 leaves render as nav sub-children). This component is the group's landing
 page: a single CnDashboardPage giving the people domain (learners,
 enrolments, attendance, credentials) real information value — KPI tiles plus
 a manage-list per sub-area — rather than the former tile-grid navigational
 aid (supersedes ADR-044 cards-collapse).

 One component, exactly one CnDashboardPage: never referenced as a widget slot
 on another dashboard (avoids the dashboard-in-dashboard antipattern).

 @spec openspec/changes/nav-restructure-dashboards/specs/dashboard/spec.md#requirement-people-domain-dashboard
-->
<template>
	<div class="scholiq-domain-dashboard">
		<CnDashboardPage
			:title="pageTitle"
			:widgets="widgets"
			:layout="layout">
			<template #widget-kpi-learners>
				<KpiLearnersWidget />
			</template>
			<template #widget-kpi-active-enrolments>
				<KpiActiveEnrolmentsWidget />
			</template>
			<template #widget-kpi-cohorts>
				<KpiCohortsWidget />
			</template>
			<template #widget-kpi-open-flags>
				<KpiOpenFlagsWidget />
			</template>
			<template #widget-manage-learners>
				<ManageListWidget
					schema="learner-profile"
					:schema-label="t('scholiq', 'learner')"
					:columns="['name']"
					:name-resolver="learnerName"
					index-route="/learner-profiles"
					:limit="6" />
			</template>
			<template #widget-manage-enrolments>
				<ManageListWidget
					schema="Enrolment"
					:schema-label="t('scholiq', 'enrolment')"
					:columns="['name']"
					:extend="['learnerId', 'courseId']"
					:name-resolver="enrolmentName"
					index-route="/enrolments"
					:limit="6" />
			</template>
			<template #widget-manage-attendance>
				<ManageListWidget
					schema="attendance-record"
					:schema-label="t('scholiq', 'attendance record')"
					:columns="['name', 'lifecycle']"
					index-route="/attendance/records"
					:limit="6" />
			</template>
			<template #widget-manage-credentials>
				<ManageListWidget
					schema="Credential"
					:schema-label="t('scholiq', 'credential')"
					:columns="['name', 'lifecycle']"
					index-route="/credentials"
					:limit="6" />
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import KpiLearnersWidget from './widgets/KpiLearnersWidget.vue'
import KpiActiveEnrolmentsWidget from './widgets/KpiActiveEnrolmentsWidget.vue'
import KpiCohortsWidget from './widgets/KpiCohortsWidget.vue'
import KpiOpenFlagsWidget from './widgets/KpiOpenFlagsWidget.vue'
import ManageListWidget from './widgets/ManageListWidget.vue'

export default {
	name: 'PeopleDashboard',

	components: {
		CnDashboardPage,
		KpiLearnersWidget,
		KpiActiveEnrolmentsWidget,
		KpiCohortsWidget,
		KpiOpenFlagsWidget,
		ManageListWidget,
	},

	computed: {
		/**
		 * The dashboard page title.
		 *
		 * @return {string}
		 */
		pageTitle() {
			return this.t('scholiq', 'People')
		},

		/**
		 * The CnDashboardPage `widgets` declaration.
		 *
		 * @return {Array<object>}
		 */
		widgets() {
			return [
				{ id: 'kpi-learners', title: this.t('scholiq', 'Learners'), type: 'custom' },
				{ id: 'kpi-active-enrolments', title: this.t('scholiq', 'Active enrolments'), type: 'custom' },
				{ id: 'kpi-cohorts', title: this.t('scholiq', 'Cohorts'), type: 'custom' },
				{ id: 'kpi-open-flags', title: this.t('scholiq', 'Open attendance flags'), type: 'custom' },
				{ id: 'manage-learners', title: this.t('scholiq', 'Learners'), type: 'custom' },
				{ id: 'manage-enrolments', title: this.t('scholiq', 'Enrolments'), type: 'custom' },
				{ id: 'manage-attendance', title: this.t('scholiq', 'Attendance'), type: 'custom' },
				{ id: 'manage-credentials', title: this.t('scholiq', 'Credentials'), type: 'custom' },
			]
		},

		/**
		 * The CnDashboardPage `layout` declaration (12-column grid).
		 *
		 * @return {Array<object>}
		 */
		layout() {
			return [
				{ id: 1, widgetId: 'kpi-learners', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'kpi-active-enrolments', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 3, widgetId: 'kpi-cohorts', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 4, widgetId: 'kpi-open-flags', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 5, widgetId: 'manage-learners', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 6, widgetId: 'manage-enrolments', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 7, widgetId: 'manage-attendance', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
				{ id: 8, widgetId: 'manage-credentials', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
			]
		},
	},

	methods: {
		/**
		 * Display label for a learner-profile row: the person's name when set,
		 * otherwise the linked Nextcloud user id — never the raw object UUID.
		 *
		 * @param {object} item A learner-profile object.
		 * @return {string}
		 */
		learnerName(item) {
			const full = [item.givenName, item.familyName].filter(Boolean).join(' ').trim()
			return full || item.ncUserId || item['@self']?.name || item.id
		},

		/**
		 * Display label for an enrolment row: "learner → course", resolved from
		 * the `_extend`-expanded learnerId/courseId relations (each may arrive as
		 * an object or a plain label string).
		 *
		 * @param {object} item An enrolment object with extended relations.
		 * @return {string}
		 */
		enrolmentName(item) {
			const resolve = (rel) => (rel && typeof rel === 'object'
				? (rel.name || rel['@self']?.name || rel.id)
				: rel)
			const learner = resolve(item.learnerId) || '?'
			const course = resolve(item.courseId) || '?'
			return `${learner} → ${course}`
		},
	},
}
</script>
