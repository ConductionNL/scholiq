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
// modal helpers (BulkEnrolModal, ImportQtiModal, etc.) out of the
// page-component flow once openspec deltas land for modal kinds.
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44)
//   - procest #512 / mydash #206

import BulkEnrolModal from './views/BulkEnrolModal.vue'
import CohortTimetable from './views/CohortTimetable.vue'
import ImportQtiModal from './views/ImportQtiModal.vue'
import ItemAuthorView from './views/ItemAuthorView.vue'
import GradebookView from './views/GradebookView.vue'
import GradeImpactDetail from './views/GradeImpactDetail.vue'
import LearningPlanEditor from './views/LearningPlanEditor.vue'
import MarkAttendanceView from './views/MarkAttendanceView.vue'
import MarkSubmissionView from './views/MarkSubmissionView.vue'
import SignPlanModal from './views/SignPlanModal.vue'
import ProctoringReviewQueue from './views/ProctoringReviewQueue.vue'
import ScholiqSettings from './views/ScholiqSettings.vue'
import OsoDossierReviewView from './views/OsoDossierReviewView.vue'
import RequestExportModal from './views/RequestExportModal.vue'
import SubmitExcuseModal from './views/SubmitExcuseModal.vue'
import SubmitWorkModal from './views/SubmitWorkModal.vue'
import TakeAssessmentView from './views/TakeAssessmentView.vue'
import FeaturesRoadmapView from './views/FeaturesRoadmap.vue'
import ScholiqDashboard from './views/ScholiqDashboard.vue'
import ScholiqCompliance from './views/ScholiqCompliance.vue'
import ScholiqLearnerHome from './views/ScholiqLearnerHome.vue'
import ScholiqAdminHealth from './views/ScholiqAdminHealth.vue'

export default {
	// Modals (kind:'modal' — opened via action declarations)
	BulkEnrolModal: { kind: 'modal', component: BulkEnrolModal },
	ImportQtiModal: { kind: 'modal', component: ImportQtiModal },
	RequestExportModal: { kind: 'modal', component: RequestExportModal },
	SignPlanModal: { kind: 'modal', component: SignPlanModal },
	SubmitExcuseModal: { kind: 'modal', component: SubmitExcuseModal },
	SubmitWorkModal: { kind: 'modal', component: SubmitWorkModal },

	// Pages (kind:'page' — full-screen custom views)
	CohortTimetable: { kind: 'page', component: CohortTimetable },
	GradebookView: { kind: 'page', component: GradebookView },
	GradeImpactDetail: { kind: 'page', component: GradeImpactDetail },
	ItemAuthorView: { kind: 'page', component: ItemAuthorView },
	LearningPlanEditor: { kind: 'page', component: LearningPlanEditor },
	MarkAttendanceView: { kind: 'page', component: MarkAttendanceView },
	MarkSubmissionView: { kind: 'page', component: MarkSubmissionView },
	OsoDossierReviewView: { kind: 'page', component: OsoDossierReviewView },
	ProctoringReviewQueue: { kind: 'page', component: ProctoringReviewQueue },
	ScholiqAdminHealth: { kind: 'page', component: ScholiqAdminHealth },
	ScholiqCompliance: { kind: 'page', component: ScholiqCompliance },
	ScholiqDashboard: { kind: 'page', component: ScholiqDashboard },
	ScholiqLearnerHome: { kind: 'page', component: ScholiqLearnerHome },
	ScholiqSettings: { kind: 'page', component: ScholiqSettings },
	TakeAssessmentView: { kind: 'page', component: TakeAssessmentView },
	FeaturesRoadmap: { kind: 'page', component: FeaturesRoadmapView },
}
