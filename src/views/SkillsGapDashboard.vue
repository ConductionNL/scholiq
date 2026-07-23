<!--
  SkillsGapDashboard.vue
  Custom page component for the SkillsGapDashboard manifest page (type: custom).

  Compares a learner's required competencies — Programme.requiredCompetencyIds
  for their enrolled programme(s), union any Competency whose requiredForRoles
  intersects the learner's LearnerProfile.roles — against their
  CompetencyAttainment rows, and lists any required competency with no
  attainment row (or, per v1, no non-null proficiencyLevelId) as a gap.

  This is the ONE genuine custom-view exception for the competency capability
  (competency-framework) — every other competency object is a declarative
  manifest index/detail page. A manager may deep-link to another learner's
  dashboard via ?learnerId=<nc-uid> (x-property-rbac on CompetencyAttainment
  and LearnerProfile enforces who may actually read that data server-side).

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring BsaRiskDashboard.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-skills-gap-view-compares-required-competencies-by-programme-and-by-role-against-attained-ones
-->

<template>
	<div class="skills-gap-dashboard">
		<header class="skills-gap-dashboard__header">
			<h2 class="skills-gap-dashboard__title">
				{{ t('scholiq', 'Skills gap dashboard') }}
			</h2>
			<p class="skills-gap-dashboard__subtitle">
				{{ t('scholiq', 'Competencies required by your programme(s) and role, compared against what you have attained.') }}
			</p>
		</header>

		<!-- Loading -->
		<div v-if="loading" class="skills-gap-dashboard__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('scholiq', 'Loading skills gap...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="skills-gap-dashboard__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<section class="skills-gap-dashboard__section">
				<h3>{{ t('scholiq', 'Required by programme') }}</h3>
				<div v-if="programmeGaps.length === 0" class="skills-gap-dashboard__empty" role="status">
					<span class="icon-checkmark" aria-hidden="true" />
					<p>{{ t('scholiq', 'No programme-required gaps.') }}</p>
				</div>
				<ul v-else class="skills-gap-dashboard__list">
					<li
						v-for="item in programmeGaps"
						:key="'programme-' + item.id"
						class="skills-gap-dashboard__item">
						<span class="skills-gap-dashboard__item-code">{{ item.code }}</span>
						<span class="skills-gap-dashboard__item-title">{{ item.title }}</span>
						<span class="skills-gap-dashboard__item-status">
							{{ t('scholiq', 'Gap') }}
						</span>
					</li>
				</ul>
			</section>

			<section class="skills-gap-dashboard__section">
				<h3>{{ t('scholiq', 'Required by role') }}</h3>
				<div v-if="roleGaps.length === 0" class="skills-gap-dashboard__empty" role="status">
					<span class="icon-checkmark" aria-hidden="true" />
					<p>{{ t('scholiq', 'No role-required gaps.') }}</p>
				</div>
				<ul v-else class="skills-gap-dashboard__list">
					<li
						v-for="item in roleGaps"
						:key="'role-' + item.id"
						class="skills-gap-dashboard__item">
						<span class="skills-gap-dashboard__item-code">{{ item.code }}</span>
						<span class="skills-gap-dashboard__item-title">{{ item.title }}</span>
						<span class="skills-gap-dashboard__item-status">
							{{ t('scholiq', 'Gap') }}
						</span>
					</li>
				</ul>
			</section>

			<section class="skills-gap-dashboard__section">
				<h3>{{ t('scholiq', 'Attained') }}</h3>
				<div v-if="attainedList.length === 0" class="skills-gap-dashboard__empty" role="status">
					<p>{{ t('scholiq', 'No attained competencies yet.') }}</p>
				</div>
				<ul v-else class="skills-gap-dashboard__list">
					<li
						v-for="item in attainedList"
						:key="'attained-' + item.id"
						class="skills-gap-dashboard__item skills-gap-dashboard__item--attained">
						<span class="skills-gap-dashboard__item-code">{{ item.code }}</span>
						<span class="skills-gap-dashboard__item-title">{{ item.title }}</span>
						<span class="skills-gap-dashboard__item-status">
							{{ item.proficiencyLevelId }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'

export default {
	name: 'SkillsGapDashboard',

	data() {
		return {
			learnerId: null,
			roles: [],
			attainments: [],
			requiredProgrammeCompetencies: [],
			requiredRoleCompetencies: [],
			competencyIndex: {},
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Competency ids the learner has a non-null proficiencyLevelId for —
		 * v1's "attained" definition (design.md: a per-framework configurable
		 * target level is a documented follow-up, not built here).
		 *
		 * @return {Set<string>}
		 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-skills-gap-view-compares-required-competencies-by-programme-and-by-role-against-attained-ones
		 */
		attainedCompetencyIds() {
			return new Set(
				this.attainments
					.filter((a) => a.proficiencyLevelId !== null && a.proficiencyLevelId !== undefined)
					.map((a) => a.competencyId),
			)
		},

		/**
		 * Programme-required competencies with no attainment row at/above met status.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/competency-framework/specs/competency/spec.md#scenario-a-learner-sees-an-unmet-programme-required-competency-as-a-gap
		 */
		programmeGaps() {
			return this.requiredProgrammeCompetencies.filter((c) => !this.attainedCompetencyIds.has(c.id))
		},

		/**
		 * Role-required competencies with no attainment row at/above met status —
		 * surfaced independent of any Programme enrolment.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/competency-framework/specs/competency/spec.md#scenario-a-role-required-competency-surfaces-even-without-a-programme-link
		 */
		roleGaps() {
			return this.requiredRoleCompetencies.filter((c) => !this.attainedCompetencyIds.has(c.id))
		},

		/**
		 * Attained competencies (for display), resolved from the competency index.
		 *
		 * @return {object[]}
		 */
		attainedList() {
			return this.attainments
				.filter((a) => a.proficiencyLevelId !== null && a.proficiencyLevelId !== undefined)
				.map((a) => ({
					id: a.competencyId,
					code: this.competencyIndex[a.competencyId]?.code ?? a.competencyId,
					title: this.competencyIndex[a.competencyId]?.title ?? '',
					proficiencyLevelId: a.proficiencyLevelId,
				}))
		},
	},

	created() {
		this.loadSkillsGap()
	},

	methods: {
		/**
		 * Load every source needed to compute the skills-gap view for the
		 * target learner (self by default, or ?learnerId= for a manager
		 * deep link — server-side RBAC on CompetencyAttainment/LearnerProfile
		 * enforces who may actually read another learner's data).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-skills-gap-view-compares-required-competencies-by-programme-and-by-role-against-attained-ones
		 */
		async loadSkillsGap() {
			this.loading = true
			this.error = null

			try {
				const targetLearnerId = this.$route?.query?.learnerId || getCurrentUser()?.uid
				if (!targetLearnerId) {
					throw new Error('No learner id available')
				}

				this.learnerId = targetLearnerId

				const [profile, attainments, enrolments, competencies] = await Promise.all([
					this.fetchLearnerProfile(targetLearnerId),
					this.fetchCollection('CompetencyAttainment', { learnerId: targetLearnerId, limit: 200 }),
					this.fetchCollection('Enrolment', { learnerId: targetLearnerId, limit: 200 }),
					this.fetchCollection('Competency', { limit: 500 }),
				])

				this.attainments = attainments
				this.roles = profile?.roles ?? []
				this.competencyIndex = Object.fromEntries(
					competencies.map((c) => [c.id ?? c.uuid, c]),
				)

				const courseIds = [...new Set(enrolments.map((e) => e.courseId).filter(Boolean))]
				const courses = await Promise.all(courseIds.map((id) => this.fetchObject('Course', id)))
				const programmeIds = [
					...new Set(courses.flatMap((c) => c?.programmeIds ?? []).filter(Boolean)),
				]
				const programmes = await Promise.all(programmeIds.map((id) => this.fetchObject('Programme', id)))

				const requiredProgrammeIds = new Set(
					programmes.flatMap((p) => p?.requiredCompetencyIds ?? []).filter(Boolean),
				)

				this.requiredProgrammeCompetencies = competencies.filter((c) => requiredProgrammeIds.has(c.id ?? c.uuid))
				this.requiredRoleCompetencies = competencies.filter((c) => (c.requiredForRoles ?? []).some((r) => this.roles.includes(r)))
			} catch (err) {
				this.error = this.t('scholiq', 'Failed to load the skills gap dashboard. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[SkillsGapDashboard] loadSkillsGap error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the LearnerProfile for a Nextcloud user id.
		 *
		 * @param {string} ncUserId Nextcloud user id.
		 * @return {Promise<object|null>}
		 */
		async fetchLearnerProfile(ncUserId) {
			const results = await this.fetchCollection('LearnerProfile', { ncUserId, limit: 1 })
			return results[0] ?? null
		},

		/**
		 * Fetch a filtered collection of OpenRegister objects for the scholiq register.
		 *
		 * @param {string} schema Schema name/slug as used in the OR object API path.
		 * @param {object} params Query filter params.
		 * @return {Promise<object[]>}
		 */
		async fetchCollection(schema, params) {
			const query = new URLSearchParams(
				Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])),
			).toString()
			const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}?${query}`)
			const resp = await axios.get(url)
			const body = resp.data
			return body.results ?? body.objects ?? body ?? []
		},

		/**
		 * Fetch a single OpenRegister object by id.
		 *
		 * @param {string} schema Schema name/slug.
		 * @param {string} id     Object UUID.
		 * @return {Promise<object|null>}
		 */
		async fetchObject(schema, id) {
			if (!id) return null
			try {
				const url = generateUrl(`/apps/openregister/api/objects/scholiq/${schema}/${id}`)
				const resp = await axios.get(url)
				return resp.data
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error(`[SkillsGapDashboard] fetchObject(${schema}, ${id}) error`, err)
				return null
			}
		},
	},
}
</script>

<style scoped>
.skills-gap-dashboard {
	max-width: 900px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
}

.skills-gap-dashboard__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.skills-gap-dashboard__title {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
}

.skills-gap-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.skills-gap-dashboard__loading,
.skills-gap-dashboard__error,
.skills-gap-dashboard__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.skills-gap-dashboard__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.skills-gap-dashboard__list {
	list-style: none;
	padding: 0;
}

.skills-gap-dashboard__item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
	font-size: 0.9em;
}

.skills-gap-dashboard__item--attained {
	border-left-color: var(--color-success);
}

.skills-gap-dashboard__item-code {
	font-weight: bold;
}

.skills-gap-dashboard__item-title {
	color: var(--color-text-maxcontrast);
}

.skills-gap-dashboard__item-status {
	margin-left: auto;
	color: var(--color-warning);
	font-weight: 500;
}

.skills-gap-dashboard__item--attained .skills-gap-dashboard__item-status {
	color: var(--color-success);
}
</style>
