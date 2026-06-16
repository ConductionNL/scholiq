/**
 * Scholiq v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which Scholiq no longer ships).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import ItemAuthorView from './views/ItemAuthorView.vue'
import GradeImpactDetail from './views/GradeImpactDetail.vue'
import LessonPlayer from './views/LessonPlayer.vue'
import MarkSubmissionView from './views/MarkSubmissionView.vue'
import ProctoringReviewQueue from './views/ProctoringReviewQueue.vue'
import ScholiqSettings from './views/ScholiqSettings.vue'
import TakeAssessmentView from './views/TakeAssessmentView.vue'
import ScholiqDashboards from './views/ScholiqDashboards.vue'
import ScholiqCompliance from './views/ScholiqCompliance.vue'
import ScholiqLearnerHome from './views/ScholiqLearnerHome.vue'
import ScholiqAdminHealth from './views/ScholiqAdminHealth.vue'
import RolloverWizard from './views/RolloverWizard.vue'

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off — `kind: "widget"`/`"modal"`/`"form-field"`/
 * `"cell-renderer"` entries with the same name are NOT used for page
 * dispatch).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

export default {
	GradeImpactDetail: page(GradeImpactDetail),
	ItemAuthorView: page(ItemAuthorView),
	LessonPlayer: page(LessonPlayer),
	MarkSubmissionView: page(MarkSubmissionView),
	ProctoringReviewQueue: page(ProctoringReviewQueue),
	RolloverWizard: page(RolloverWizard),
	ScholiqAdminHealth: page(ScholiqAdminHealth),
	ScholiqCompliance: page(ScholiqCompliance),
	ScholiqDashboards: page(ScholiqDashboards),
	ScholiqLearnerHome: page(ScholiqLearnerHome),
	ScholiqSettings: page(ScholiqSettings),
	TakeAssessmentView: page(TakeAssessmentView),
}
