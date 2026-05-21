// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// The existing inline customComponents map in main.js is preserved
// alongside this file for backward-compat during the migration window.
// CnAppRoot accepts both props; the v2 renderer emits a one-shot
// deprecation warning when both are present and the manifest is v2.
//
// Naming convention: entries ending in "Modal" are kind:'modal'; views
// and pages are kind:'page'. Future refinement: split the standalone
// modal helpers out of the page-component flow once openspec deltas
// land for modal kinds.
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44)
//   - procest #512 / mydash #206

import ItemAuthorView from './views/ItemAuthorView.vue'
import GradeImpactDetail from './views/GradeImpactDetail.vue'
import LessonPlayer from './views/LessonPlayer.vue'
import MarkSubmissionView from './views/MarkSubmissionView.vue'
import ProctoringReviewQueue from './views/ProctoringReviewQueue.vue'
import ScholiqSettings from './views/ScholiqSettings.vue'
import TakeAssessmentView from './views/TakeAssessmentView.vue'
import ScholiqDashboard from './views/ScholiqDashboard.vue'
import ScholiqCompliance from './views/ScholiqCompliance.vue'
import ScholiqLearnerHome from './views/ScholiqLearnerHome.vue'
import ScholiqAdminHealth from './views/ScholiqAdminHealth.vue'

export default {
	// Pages (kind:'page' — full-screen custom views with no lib analogue)
	GradeImpactDetail: { kind: 'page', component: GradeImpactDetail },
	ItemAuthorView: { kind: 'page', component: ItemAuthorView },
	LessonPlayer: { kind: 'page', component: LessonPlayer },
	MarkSubmissionView: { kind: 'page', component: MarkSubmissionView },
	ProctoringReviewQueue: { kind: 'page', component: ProctoringReviewQueue },
	ScholiqAdminHealth: { kind: 'page', component: ScholiqAdminHealth },
	ScholiqCompliance: { kind: 'page', component: ScholiqCompliance },
	ScholiqDashboard: { kind: 'page', component: ScholiqDashboard },
	ScholiqLearnerHome: { kind: 'page', component: ScholiqLearnerHome },
	ScholiqSettings: { kind: 'page', component: ScholiqSettings },
	TakeAssessmentView: { kind: 'page', component: TakeAssessmentView },
}
