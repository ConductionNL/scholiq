<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 People — cards landing page (learning-people-cards-collapse, ADR-044).

 The single landing page for the collapsed People group. Each former child
 leaf (Learners, Enrolments, Attendance, Credentials) is rendered as a
 clickable card. Clicking any card navigates to that leaf's index page.
 All former leaf pages remain routable via their original routes — this
 card grid is purely a navigational aid.

 Layout: responsive CSS grid, one card per former child leaf.

 @spec openspec/changes/learning-people-cards-collapse/specs/navigation/spec.md
-->
<template>
	<div class="people-cards" data-testid="people-cards">
		<header class="people-cards__header">
			<h2 data-testid="people-cards-title">
				{{ t('scholiq', 'People') }}
			</h2>
			<p class="people-cards__hint">
				{{ t('scholiq', 'Navigate to a people area below.') }}
			</p>
		</header>

		<div class="people-cards__grid" data-testid="people-cards-grid">
			<article
				v-for="card in cards"
				:key="card.id"
				class="people-cards__card"
				:data-testid="`people-card-${card.id}`"
				role="button"
				tabindex="0"
				@click="navigate(card.route)"
				@keydown.enter="navigate(card.route)"
				@keydown.space.prevent="navigate(card.route)">
				<span class="people-cards__card-icon" aria-hidden="true">
					{{ card.icon }}
				</span>
				<h3 class="people-cards__card-title">
					{{ t('scholiq', card.label) }}
				</h3>
			</article>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PeopleCards',
	data() {
		return {
			cards: [
				{ id: 'LearnerProfilesMenu', label: 'Learners', icon: '👤', route: 'LearnerProfiles' },
				{ id: 'Enrolments', label: 'Enrolments', icon: '📋', route: 'Enrolments' },
				{ id: 'Attendance', label: 'Attendance', icon: '📅', route: 'AttendanceRecords' },
				{ id: 'Credentials', label: 'Credentials', icon: '🎓', route: 'Credentials' },
			],
		}
	},
	methods: {
		t,
		/**
		 * Navigate to the given named route.
		 *
		 * @param {string} routeName The manifest page id (vue-router route name).
		 */
		navigate(routeName) {
			this.$router.push({ name: routeName })
		},
	},
}
</script>

<style scoped>
.people-cards {
	padding: 1.5rem;
}

.people-cards__header {
	margin-bottom: 1.5rem;
}

.people-cards__header h2 {
	margin: 0 0 0.25rem 0;
}

.people-cards__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.people-cards__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
	gap: 1rem;
}

.people-cards__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.75rem;
	padding: 2rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
	transition: background 0.15s, box-shadow 0.15s;
	user-select: none;
}

.people-cards__card:hover,
.people-cards__card:focus {
	background: var(--color-background-hover);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.people-cards__card-icon {
	font-size: 2.5rem;
	line-height: 1;
}

.people-cards__card-title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	text-align: center;
}
</style>
