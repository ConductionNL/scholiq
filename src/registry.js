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
import RolloverWizard from './views/RolloverWizard.vue'
// nav-restructure-dashboards (supersedes ADR-044 cards-collapse): the Learning
// and People groups land on domain dashboards instead of tile-grid card pages.
import LearningDashboard from './views/LearningDashboard.vue'
import PeopleDashboard from './views/PeopleDashboard.vue'
import AuditTrailWidget from './components/widgets/AuditTrailWidget.vue'

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

/*
 * Grid metadata required for every kind:"widget" registry entry by the
 * ADR-036 registry validator in CnAppRoot. `allowedSlots` uses the v2 slot
 * literals; `audit-trail` is placed on both body and sidebar since detail
 * pages use it in either position depending on the page's layout.
 */
const PANEL_WIDGET_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 6 },
	allowedSlots: ['body', 'sidebar'],
	propsSchema: null,
}

export default {
	GradeImpactDetail: page(GradeImpactDetail),
	ItemAuthorView: page(ItemAuthorView),
	LearningDashboard: page(LearningDashboard),
	LessonPlayer: page(LessonPlayer),
	MarkSubmissionView: page(MarkSubmissionView),
	PeopleDashboard: page(PeopleDashboard),
	ProctoringReviewQueue: page(ProctoringReviewQueue),
	RolloverWizard: page(RolloverWizard),
	ScholiqCompliance: page(ScholiqCompliance),
	ScholiqDashboards: page(ScholiqDashboards),
	ScholiqLearnerHome: page(ScholiqLearnerHome),
	ScholiqSettings: page(ScholiqSettings),
	TakeAssessmentView: page(TakeAssessmentView),

	// --- Shared library widgets registered under manifest widget keys (ADR-036). ---
	'audit-trail': {
		kind: 'widget',
		component: AuditTrailWidget,
		...PANEL_WIDGET_META,
		_note: 'Object change-log card — self-fetches from the detail object context (register/schema/objectId).',
	},
}
