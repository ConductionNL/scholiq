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

import AuditPackExportModal from './views/AuditPackExportModal.vue'
import BulkEnrolModal from './views/BulkEnrolModal.vue'
import CohortTimetable from './views/CohortTimetable.vue'
import CredentialVerify from './views/CredentialVerify.vue'
import ImportQtiModal from './views/ImportQtiModal.vue'
import ItemAuthorView from './views/ItemAuthorView.vue'
import GradebookView from './views/GradebookView.vue'
import GradeImpactDetail from './views/GradeImpactDetail.vue'
import LearningPlanEditor from './views/LearningPlanEditor.vue'
import LessonPlayer from './views/LessonPlayer.vue'
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
import ScholiqDashboard from './views/ScholiqDashboard.vue'
import ScholiqCompliance from './views/ScholiqCompliance.vue'
import ScholiqLearnerHome from './views/ScholiqLearnerHome.vue'
import ScholiqAdminHealth from './views/ScholiqAdminHealth.vue'

export default {
	AuditPackExportModal,
	BulkEnrolModal,
	CohortTimetable,
	CredentialVerify,
	GradebookView,
	GradeImpactDetail,
	ImportQtiModal,
	ItemAuthorView,
	LearningPlanEditor,
	LessonPlayer,
	MarkAttendanceView,
	MarkSubmissionView,
	OsoDossierReviewView,
	ProctoringReviewQueue,
	RequestExportModal,
	ScholiqAdminHealth,
	ScholiqCompliance,
	ScholiqDashboard,
	ScholiqLearnerHome,
	ScholiqSettings,
	SignPlanModal,
	SubmitExcuseModal,
	SubmitWorkModal,
	TakeAssessmentView,
}
