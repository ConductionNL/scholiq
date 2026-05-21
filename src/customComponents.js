// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry (v1 shape) for the manifest-driven app shell.
//
// Preserved alongside the v2 registry.js during the migration window so
// CnAppRoot can fall back to the v1 resolution path if needed. The v2
// renderer emits a one-shot deprecation warning when both are passed and
// the manifest is v2 — that warning is acceptable until the lib drops v1
// support entirely.

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
	GradeImpactDetail,
	ItemAuthorView,
	LessonPlayer,
	MarkSubmissionView,
	ProctoringReviewQueue,
	ScholiqAdminHealth,
	ScholiqCompliance,
	ScholiqDashboard,
	ScholiqLearnerHome,
	ScholiqSettings,
	TakeAssessmentView,
}
