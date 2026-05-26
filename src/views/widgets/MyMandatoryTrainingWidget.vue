<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MyMandatoryTrainingWidget — learner-home widget.
 Shows the current user's mandatory enrolments in lifecycle=pending or active,
 sorted by dueDate. Each row has a "Start" link to the course player.
-->
<template>
	<div class="my-training-widget">
		<div v-if="loading" class="my-training-widget__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="enrolments.length === 0" class="my-training-widget__empty">
			{{ t('scholiq', 'No mandatory training due') }}
		</div>

		<ul v-else class="my-training-widget__list">
			<li
				v-for="enrolment in enrolments"
				:key="enrolment.id || enrolment.uuid"
				class="my-training-widget__item">
				<div class="my-training-widget__item-info">
					<span class="my-training-widget__item-name">
						{{ enrolment.courseTitle || enrolment.courseId || t('scholiq', 'Course') }}
					</span>
					<span v-if="enrolment.dueDate" class="my-training-widget__item-due">
						{{ t('scholiq', 'Due') }}: {{ formatDate(enrolment.dueDate) }}
					</span>
				</div>
				<a
					class="my-training-widget__start-link"
					@click.prevent="startCourse(enrolment)">
					{{ t('scholiq', 'Start') }}
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'MyMandatoryTrainingWidget',

	components: {
		NcLoadingIcon,
	},

	data() {
		return {
			enrolments: [],
			loading: true,
		}
	},

	created() {
		this.fetchEnrolments()
	},

	methods: {
		/**
		 * Fetch the current user's pending/active mandatory enrolments from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		async fetchEnrolments() {
			this.loading = true
			try {
				const params = new URLSearchParams({
					_limit: '20',
					mandatory: 'true',
					lifecycle__in: 'pending,active',
					_order: 'dueDate',
				})
				const url = generateUrl(
					'/apps/openregister/api/objects/scholiq/Enrolment?' + params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				this.enrolments = data.results ?? (Array.isArray(data) ? data : [])
			} catch {
				this.enrolments = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format an ISO date string as a localised date.
		 *
		 * @param {string} dateStr ISO date string
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},

		/**
		 * Navigate to the lessons view for the enrolment's course.
		 *
		 * @param {object} enrolment Enrolment object
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		startCourse(enrolment) {
			const courseId = enrolment.courseId
			if (courseId) {
				this.$router.push('/courses/' + courseId + '/lessons').catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.my-training-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 4px 0;
}

.my-training-widget__loading,
.my-training-widget__empty {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.my-training-widget__list {
	flex: 1;
	list-style: none;
	margin: 0;
	padding: 0;
	overflow-y: auto;
}

.my-training-widget__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.my-training-widget__item:last-child {
	border-bottom: none;
}

.my-training-widget__item-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.my-training-widget__item-name {
	font-weight: 500;
	font-size: 13px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.my-training-widget__item-due {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.my-training-widget__start-link {
	font-size: 12px;
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: none;
	white-space: nowrap;
	margin-left: 8px;
	font-weight: 500;
}

.my-training-widget__start-link:hover {
	text-decoration: underline;
}
</style>
