<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubjectChoicePicker -- the interactive vakkenpakket (elective-course)
 picker (admissions-and-subject-choice / school-structure).

 A custom view (the "custom view only where a manifest page can't render it"
 exception, matching BookConferenceSlotsView): lets the caller pick which of
 their linked children (or themselves, for an 18+ learner) and which
 CurriculumPlan to build a vakkenpakket for, shows LIVE feedback against the
 plan's electiveRules (minElectives/maxElectives, mandatoryCombinations,
 mutuallyExclusive, capacityByCourseId) as electives are (de)selected, then
 creates a `draft` SubjectChoice and immediately triggers its `submit`
 transition. The transition is gated server-side by
 SubjectChoiceConsentGuard -- the caller's identity is resolved from the NC
 session, never trusted from the client -- and the submitted choice is then
 authoritatively re-validated server-side by SubjectChoiceValidator; the
 feedback shown here is advisory, not the enforcement mechanism.

 Storage/lifecycle/notifications are OpenRegister's; this view only creates
 the choice and drives its submit transition through the OR object API.

 @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception
 @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
-->
<template>
	<div class="subject-choice-picker">
		<h2>{{ t('scholiq', 'Pick electives (vakkenpakket)') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="plans.length === 0"
			:name="t('scholiq', 'No curriculum plans with electives found')"
			:description="t('scholiq', 'Check back once your school publishes a curriculum plan with electiveCourseIds.')" />

		<template v-else>
			<div class="subject-choice-picker__field">
				<label for="scp-plan">{{ t('scholiq', 'Curriculum plan') }}</label>
				<NcSelect id="scp-plan"
					v-model="selectedPlanId"
					:options="planOptions"
					:reduce="(o) => o.id"
					label="label"
					:input-label="t('scholiq', 'Curriculum plan')"
					:aria-label-combobox="t('scholiq', 'Curriculum plan')"
					@input="onPlanChange" />
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field">
				<label for="scp-learner">{{ t('scholiq', 'For which child (or yourself)') }}</label>
				<NcSelect id="scp-learner"
					v-model="selectedLearnerId"
					:options="learnerOptions"
					:reduce="(o) => o.id"
					label="label"
					:loading="loadingLearners"
					:input-label="t('scholiq', 'Learner')"
					:aria-label-combobox="t('scholiq', 'Learner')" />
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field">
				<label for="scp-year">{{ t('scholiq', 'Academic year') }}</label>
				<input id="scp-year"
					v-model="academicYear"
					type="text"
					placeholder="2026-2027">
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field">
				<label for="scp-electives">{{ t('scholiq', 'Electives') }}</label>
				<NcSelect id="scp-electives"
					v-model="selectedCourseIds"
					:options="electiveOptions"
					:reduce="(o) => o.id"
					label="label"
					multiple
					:input-label="t('scholiq', 'Electives')"
					:aria-label-combobox="t('scholiq', 'Electives')" />
			</div>

			<!-- Live rule feedback -->
			<div v-if="selectedPlan" class="subject-choice-picker__feedback">
				<NcNoteCard v-if="feedback.length === 0" type="success">
					{{ t('scholiq', 'Your selection satisfies every declared rule so far.') }}
				</NcNoteCard>
				<NcNoteCard v-for="(msg, idx) in feedback" :key="idx" type="warning">
					{{ msg }}
				</NcNoteCard>
			</div>

			<div v-if="selectedPlan" class="subject-choice-picker__field subject-choice-picker__consent">
				<input id="scp-consent"
					v-model="guardianConsentGiven"
					type="checkbox">
				<label for="scp-consent">{{ t('scholiq', 'A guardian (or I, if 18+) consents to this selection') }}</label>
			</div>

			<NcNoteCard v-if="submitError" type="error">
				{{ submitError }}
			</NcNoteCard>

			<NcNoteCard v-if="submitSuccess" type="success">
				{{ t('scholiq', 'Your subject choice has been submitted for review.') }}
			</NcNoteCard>

			<NcButton v-if="selectedPlan"
				type="primary"
				:disabled="!canSubmit || submitting"
				@click="submitChoice">
				{{ submitting ? t('scholiq', 'Submitting…') : t('scholiq', 'Submit subject choice') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'SubjectChoicePicker',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadingLearners: false,
			plans: [],
			courses: [],
			learners: [],
			selectedPlanId: '',
			selectedLearnerId: '',
			selectedCourseIds: [],
			academicYear: '',
			guardianConsentGiven: false,
			submitting: false,
			submitError: '',
			submitSuccess: false,
		}
	},

	computed: {
		/**
		 * Options for the curriculum-plan picker -- only plans that declare
		 * at least one elective course.
		 *
		 * @return {Array<object>}
		 */
		planOptions() {
			return this.plans
				.filter((p) => Array.isArray(p.electiveCourseIds) && p.electiveCourseIds.length > 0)
				.map((p) => ({ id: p.id || p.uuid, label: p.name || p.id || p.uuid }))
		},
		/**
		 * The currently selected CurriculumPlan object, or null.
		 *
		 * @return {object|null}
		 */
		selectedPlan() {
			return this.plans.find((p) => (p.id || p.uuid) === this.selectedPlanId) || null
		},
		/**
		 * The selected plan's declared electiveRules, or an empty object when unset.
		 *
		 * @return {object}
		 */
		electiveRules() {
			return (this.selectedPlan && this.selectedPlan.electiveRules) || {}
		},
		/**
		 * Elective options scoped to the selected plan's electiveCourseIds.
		 *
		 * @return {Array<object>}
		 */
		electiveOptions() {
			if (!this.selectedPlan) return []
			return (this.selectedPlan.electiveCourseIds || []).map((id) => {
				const course = this.courses.find((c) => (c.id || c.uuid) === id)
				return { id, label: (course && course.name) || id }
			})
		},
		/**
		 * Learner options: the caller's linked children plus themselves (18+ self-choice).
		 *
		 * @return {Array<object>}
		 */
		learnerOptions() {
			return this.learners.map((l) => ({ id: l.ncUserId, label: l.displayName || l.ncUserId }))
		},
		/**
		 * Live rule/capacity feedback against the current selection -- advisory
		 * only, the authoritative check is SubjectChoiceValidator server-side.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-learner-picks-electives-with-live-rule-feedback
		 */
		feedback() {
			const messages = []
			const rules = this.electiveRules
			const selected = this.selectedCourseIds

			if (typeof rules.minElectives === 'number' && selected.length < rules.minElectives) {
				messages.push(this.t('scholiq', 'Select at least {min} elective(s) (currently {count}).', { min: rules.minElectives, count: selected.length }))
			}

			if (typeof rules.maxElectives === 'number' && selected.length > rules.maxElectives) {
				messages.push(this.t('scholiq', 'Select at most {max} elective(s) (currently {count}).', { max: rules.maxElectives, count: selected.length }))
			}

			;(rules.mandatoryCombinations || []).forEach((combo) => {
				const missing = (combo || []).filter((id) => !selected.includes(id))
				if (missing.length > 0 && missing.length < combo.length) {
					messages.push(this.t('scholiq', 'This combination must be chosen together — missing selection(s).'))
				}
			})

			;(rules.mutuallyExclusive || []).forEach((group) => {
				const chosen = (group || []).filter((id) => selected.includes(id))
				if (chosen.length > 1) {
					messages.push(this.t('scholiq', 'These electives cannot be chosen together.'))
				}
			})

			return messages
		},
		/**
		 * Whether the form has enough input to submit.
		 *
		 * @return {boolean}
		 */
		canSubmit() {
			return this.selectedPlanId !== ''
				&& this.selectedLearnerId !== ''
				&& this.academicYear !== ''
				&& this.selectedCourseIds.length > 0
		},
	},

	async mounted() {
		await this.loadPlansAndCourses()
		await this.loadLearners()
	},

	methods: {
		/**
		 * Load CurriculumPlans and Courses (for elective display labels).
		 *
		 * @return {Promise<void>}
		 */
		async loadPlansAndCourses() {
			this.loading = true
			try {
				const [plansResp, coursesResp] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/curriculum-plan?limit=200')),
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/course?limit=500')),
				])
				this.plans = (plansResp.data && (plansResp.data.results || plansResp.data.objects)) || plansResp.data || []
				this.courses = (coursesResp.data && (coursesResp.data.results || coursesResp.data.objects)) || coursesResp.data || []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] loadPlansAndCourses failed', e)
				this.plans = []
				this.courses = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the caller's linked LearnerProfiles (children via parentIds, plus
		 * self via ncUserId for an 18+ self-choice).
		 *
		 * @return {Promise<void>}
		 */
		async loadLearners() {
			this.loadingLearners = true
			const uid = getCurrentUser()?.uid
			if (!uid) {
				this.loadingLearners = false
				return
			}

			try {
				const [childrenResp, selfResp] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/learner-profile?parentIds={uid}&limit=50', { uid })),
					axios.get(generateUrl('/apps/openregister/api/objects/scholiq/learner-profile?ncUserId={uid}&limit=1', { uid })),
				])
				const children = (childrenResp.data && (childrenResp.data.results || childrenResp.data.objects)) || []
				const self = (selfResp.data && (selfResp.data.results || selfResp.data.objects)) || []
				const byId = new Map()
				;[...children, ...self].forEach((l) => byId.set(l.ncUserId, l))
				this.learners = Array.from(byId.values())
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] loadLearners failed', e)
				this.learners = []
			} finally {
				this.loadingLearners = false
			}
		},

		/**
		 * Reset the elective selection when the plan changes.
		 *
		 * @return {void}
		 */
		onPlanChange() {
			this.selectedCourseIds = []
		},

		/**
		 * Create the SubjectChoice (draft) then trigger its submit transition.
		 * A guard rejection (HTTP 4xx from OR's lifecycle engine) surfaces as a
		 * user-facing error rather than a silent failure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-linked-guardian-can-submit-a-subject-choice-for-their-own-child
		 */
		async submitChoice() {
			if (!this.canSubmit) return

			this.submitting = true
			this.submitError = ''
			this.submitSuccess = false

			try {
				const body = {
					learnerId: this.selectedLearnerId,
					curriculumPlanId: this.selectedPlanId,
					academicYear: this.academicYear,
					selectedElectiveCourseIds: this.selectedCourseIds,
					guardianConsentGiven: this.guardianConsentGiven,
					guardianConsentBy: this.guardianConsentGiven ? (getCurrentUser()?.uid || null) : null,
					tenant_id: this.selectedPlan ? this.selectedPlan.tenant_id : undefined,
				}

				const createUrl = generateUrl('/apps/openregister/api/objects/scholiq/subject-choice')
				const created = await axios.post(createUrl, body)
				const choiceId = (created.data && (created.data.id || created.data.uuid)) || ''

				if (choiceId === '') {
					throw new Error('No subject-choice id returned')
				}

				const transitionUrl = generateUrl('/apps/openregister/api/objects/scholiq/subject-choice/{id}', { id: choiceId })
				await axios.put(transitionUrl, { lifecycle: 'submitted' })

				this.submitSuccess = true
				this.selectedCourseIds = []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[SubjectChoicePicker] submitChoice failed', e)
				this.submitError = this.t('scholiq', 'We could not submit your subject choice. You may not be a linked guardian for this child, or the choice may violate a plan rule.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.subject-choice-picker {
	padding: 1rem;
	max-width: 560px;
}

.subject-choice-picker__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 1rem;
}

.subject-choice-picker__consent {
	flex-direction: row;
	align-items: center;
	gap: 0.5rem;
}

.subject-choice-picker__feedback {
	margin-bottom: 1rem;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}
</style>
